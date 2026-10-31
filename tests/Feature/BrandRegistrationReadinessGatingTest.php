<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BrandRegistrationReadinessGatingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->withoutMiddleware(\App\Http\Middleware\TrustHosts::class);
        Division::firstOrCreate(['name' => 'Programmer'], ['is_active' => true]);
        app(\App\Services\BrandHostRegistryService::class)->forgetCache();
    }

    private function createApplicant(): User
    {
        return User::create([
            'name'     => 'Applicant Tester',
            'email'    => 'applicant_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role'     => 'user',
        ]);
    }

    /**
     * Test 1: Host domain yang tidak terdaftar ke brand aktif tidak boleh silent-fallback ke Brand Default
     */
    public function test_unknown_host_does_not_silently_fallback_to_default_brand(): void
    {
        $applicant = $this->createApplicant();

        // Buat brand tetapi domain_verified_at masih NULL (belum diverifikasi DNS)
        Brand::create([
            'code'               => 'UNV',
            'name'               => 'Brand Unverified',
            'registration_host'  => 'daftar.unverified.test',
            'primary_domain'     => 'unverified.test',
            'lifecycle_status'   => Brand::STATUS_ACTIVE,
            'domain_verified_at' => null, // Belum terverifikasi
            'is_default'         => false,
        ]);
        app(\App\Services\BrandHostRegistryService::class)->forgetCache();

        // Simulasikan request masuk dari host unverified via full URL
        $response = $this->actingAs($applicant)
            ->get('http://daftar.unverified.test' . route('pemagang.registration.form', [], false));

        // Harus ditolak fail-closed (divisions-closed) dan detectedBrand null, BUKAN fallback ke Seven Inc
        $response->assertOk();
        $response->assertViewIs('pemagang.registration.divisions-closed');
        $this->assertNull($response->viewData('detectedBrand'));

        // Pastikan submisi POST juga ditolak dengan pesan brand belum dapat ditentukan
        $postResponse = $this->actingAs($applicant)
            ->post('http://daftar.unverified.test' . route('pemagang.registration.store', [], false), [
                'fullname' => 'Test Applicant',
            ]);
        $postResponse->assertSessionHasErrors('internship_interest');
    }

    /**
     * Test 2: Brand yang link Google Drive-nya belum terhubung otomatis tertahan di halaman maintenance
     */
    public function test_brand_without_google_drive_folder_shows_maintenance(): void
    {
        $applicant = $this->createApplicant();

        $brand = Brand::create([
            'code'              => 'DRV',
            'name'              => 'Brand Tanpa Drive',
            'registration_host' => 'daftar.tanpadrive.test',
            'primary_domain'    => 'tanpadrive.test',
            'division_mode'     => Brand::DIVISION_MODE_GLOBAL,
            'lifecycle_status'  => Brand::STATUS_ACTIVE,
            'domain_verified_at'=> now(),
            'is_default'        => false,
        ]);

        // Buat koneksi spreadsheet tapi drive_folder_id dan drive_folder_url KOSONG
        SpreadsheetConnection::create([
            'display_name'      => 'Spreadsheet Pendaftaran DRV',
            'connection_key'    => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'          => $brand->id,
            'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummySheetForDriveTest/edit',
            'spreadsheet_id'    => '1DummySheetForDriveTest',
            'drive_folder_url'  => null,
            'drive_folder_id'   => null,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
            'is_enabled'        => true,
        ]);

        app(\App\Services\BrandHostRegistryService::class)->forgetCache();

        // Kunjungi via full URL host brand ini
        $response = $this->actingAs($applicant)
            ->get('http://daftar.tanpadrive.test' . route('pemagang.registration.form', [], false));

        $response->assertOk();
        $response->assertViewIs('pemagang.registration.maintenance');
        $response->assertSee('Pemeliharaan');

        // Pastikan submisi POST juga ditolak dengan pesan pemeliharaan
        $institution = \App\Models\Institution::firstOrCreate(['name' => 'Kampus Uji'], ['is_active' => true]);
        $city = \App\Models\City::firstOrCreate(['name' => 'Kota Uji']);
        $div = Division::where('is_active', true)->first();

        $postResponse = $this->actingAs($applicant)
            ->post('http://daftar.tanpadrive.test' . route('pemagang.registration.store', [], false), [
                'fullname' => 'Test Applicant',
                'gender' => 'Laki-laki',
                'email' => $applicant->email,
                'phone_number' => '081234567890',
                'current_status' => 'Mahasiswa',
                'student_id' => '123456',
                'institution_id' => $institution->id,
                'institution_name' => $institution->name,
                'faculty' => 'Fakultas Uji',
                'study_program' => 'Prodi Uji',
                'city_id' => $city->id,
                'start_date' => now()->addDays(30)->format('Y-m-d'),
                'planned_duration' => '3 Bulan',
                'division_id' => $div->id,
                'internship_interest' => $div->name,
                'internship_type' => 'Magang Mandiri',
                'internship_reason' => 'Alasan magang uji',
                'wfo_readiness' => 'Siap',
                'schedule_shift_readiness' => 'Siap',
                'has_laptop' => 1,
            ]);
        $postResponse->assertSessionHasErrors('maintenance');
        $this->assertStringContainsString('tahap pemeliharaan sistem', session('errors')->first('maintenance'));
    }

    /**
     * Test 3: Brand non-default yang belum disambungkan ke divisi manapun tidak boleh membuka divisi global (fail-closed)
     */
    public function test_non_default_brand_without_divisions_shows_divisions_closed(): void
    {
        $applicant = $this->createApplicant();

        $brand = Brand::create([
            'code'              => 'NOD',
            'name'              => 'Brand Tanpa Divisi',
            'registration_host' => 'daftar.tanpadivisi.test',
            'primary_domain'    => 'tanpadivisi.test',
            'division_mode'     => null, // mode null pada brand non-default
            'lifecycle_status'  => Brand::STATUS_ACTIVE,
            'domain_verified_at'=> now(),
            'is_default'        => false,
        ]);

        SpreadsheetConnection::create([
            'display_name'      => 'Spreadsheet Pendaftaran NOD',
            'connection_key'    => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'          => $brand->id,
            'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummySheetNoDiv/edit',
            'spreadsheet_id'    => '1DummySheetNoDiv',
            'drive_folder_url'  => 'https://drive.google.com/drive/folders/1DummyDriveNoDiv',
            'drive_folder_id'   => '1DummyDriveNoDiv',
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
            'is_enabled'        => true,
        ]);

        app(\App\Services\BrandHostRegistryService::class)->forgetCache();

        // Kunjungi via full URL host brand ini
        $response = $this->actingAs($applicant)
            ->get('http://daftar.tanpadivisi.test' . route('pemagang.registration.form', [], false));

        $response->assertOk();
        // Harus tertahan di divisions-closed karena belum ada divisi yang dipetakan ke brand non-default
        $response->assertViewIs('pemagang.registration.divisions-closed');
    }
}
