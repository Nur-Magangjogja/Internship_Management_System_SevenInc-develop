<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\BrandDivisionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BrandDivisionContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DivisionSeeder::class);
        $this->seed(\Database\Seeders\BrandSeeder::class);
        app(\App\Services\SpreadsheetConnectionService::class)->ensureBrandConnectionsExist();

        SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->update([
                'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummyTestSpreadsheetId/edit',
                'spreadsheet_id'    => '1DummyTestSpreadsheetId',
                'is_enabled'        => true,
                'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
            ]);
    }

    private function createPemagang(): User
    {
        return User::create([
            'name' => 'Division Contract Test',
            'email' => 'division_contract_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role' => 'pemagang',
        ]);
    }

    public function test_brand_specific_mode_never_falls_back_to_global_divisions_when_mapping_is_empty(): void
    {
        $brand = Brand::where('code', 'RW')->firstOrFail();
        $brand->update(['division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC]);
        $brand->divisions()->detach();

        $service = app(BrandDivisionService::class);

        $this->assertTrue($service->isRegistrationClosed($brand->fresh()));
        $this->assertTrue($service->registrationDivisionNames($brand->fresh())->isEmpty());
    }

    public function test_global_mode_uses_global_active_divisions_without_a_brand_mapping(): void
    {
        $brand = Brand::where('code', 'RW')->firstOrFail();
        $brand->update(['division_mode' => Brand::DIVISION_MODE_GLOBAL]);
        $brand->divisions()->detach();
        $division = Division::where('is_active', true)->firstOrFail();

        $service = app(BrandDivisionService::class);

        $this->assertFalse($service->isRegistrationClosed($brand->fresh()));
        $this->assertContains($division->name, $service->registrationDivisionNames($brand->fresh())->all());
        $this->assertTrue($service->isDivisionAllowed($brand->fresh(), (int) $division->id));
    }

    public function test_brand_specific_mode_rejects_an_active_division_owned_by_another_brand(): void
    {
        $brandTL = Brand::where('code', 'TL')->firstOrFail();
        $brandTL->update(['division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC]);
        $brandTL->divisions()->sync(Division::whereIn('name', ['Las', 'Administrasi'])->pluck('id')->all());

        $programmer = Division::where('name', 'Programmer')->firstOrFail();
        $service = app(BrandDivisionService::class);

        $this->assertFalse($service->isDivisionAllowed($brandTL->fresh(), (int) $programmer->id));
    }


    public function test_unknown_division_mode_fails_closed_instead_of_using_global_divisions(): void
    {
        $brand = Brand::where('code', 'RW')->firstOrFail();
        $brand->update(['division_mode' => 'unexpected_mode']);
        $division = Division::where('is_active', true)->firstOrFail();

        $service = app(BrandDivisionService::class);

        $this->assertTrue($service->isRegistrationClosed($brand->fresh()));
        $this->assertFalse($service->isDivisionAllowed($brand->fresh(), (int) $division->id));
    }

    public function test_registration_form_displays_closed_notice_for_specific_brand_with_no_active_mapping(): void
    {
        $brand = Brand::where('code', 'RW')->firstOrFail();
        $brand->update(['division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC]);
        $brand->divisions()->detach();

        $response = $this->actingAs($this->createPemagang())
            ->get('http://daftar.republikweb.net' . route('pemagang.registration.form', [], false));

        $response->assertOk();
        $response->assertViewIs('pemagang.registration.divisions-closed');
    }

    public function test_public_registration_rejects_an_active_division_not_mapped_to_the_brand(): void
    {
        $user = $this->createPemagang();
        $brandTL = Brand::where('code', 'TL')->firstOrFail();
        $programmer = Division::where('name', 'Programmer')->firstOrFail();
        $institution = \App\Models\Institution::firstOrCreate(['name' => 'Universitas Kontrak Divisi'], ['is_active' => true]);
        $city = \App\Models\City::firstOrCreate(['name' => 'Kota Kontrak Divisi']);

        $response = $this->actingAs($user)
            ->from('http://daftar.tukanglas.org/daftar')
            ->post('http://daftar.tukanglas.org' . route('pemagang.registration.store', [], false), [
                'fullname' => 'Peserta Uji Kontrak',
                'gender' => 'Laki-laki',
                'email' => 'peserta_uji_' . uniqid() . '@example.com',
                'phone_number' => '081234567890',
                'current_status' => 'Mahasiswa',
                'student_id' => 'NIM' . uniqid(),
                'institution_id' => $institution->id,
                'institution_name' => $institution->name,
                'faculty' => 'Fakultas Uji',
                'study_program' => 'Program Studi Uji',
                'city_id' => $city->id,
                'start_date' => now()->addDays(30)->format('Y-m-d'),
                'planned_duration' => '3 Bulan',
                'division_id' => $programmer->id,
                'internship_interest' => $programmer->name,
                'internship_type' => 'Magang Mandiri',
                'internship_reason' => 'Pengujian kontrak divisi brand',
                'wfo_readiness' => 'Siap',
                'schedule_shift_readiness' => 'Siap',
                'has_laptop' => 1,
                'form_schema_version' => '',
            ]);

        $response->assertSessionHasErrors('internship_interest');
        $this->assertDatabaseMissing('internship_registrations', ['user_id' => $user->id]);
    }

    public function test_admin_update_rejects_a_division_not_allowed_for_target_brand(): void
    {
        $admin = User::create([
            'name' => 'Division Contract Admin',
            'email' => 'division_contract_admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]);
        $pemagang = $this->createPemagang();
        $brandTL = Brand::where('code', 'TL')->firstOrFail();
        $programmer = Division::where('name', 'Programmer')->firstOrFail();
        $city = \App\Models\City::firstOrCreate(['name' => 'Kota Kontrak Admin']);
        $institution = \App\Models\Institution::firstOrCreate(['name' => 'Institusi Kontrak Admin'], ['is_active' => true]);
        $faculty = \App\Models\Faculty::firstOrCreate(['institution_id' => $institution->id, 'name' => 'Fakultas Kontrak Admin']);
        $studyProgram = \App\Models\StudyProgram::firstOrCreate(['faculty_id' => $faculty->id, 'name' => 'Program Studi Kontrak Admin']);
        $currentDivision = Division::where('name', 'Las')->firstOrFail();

        $intern = \App\Models\InternshipRegistration::create([
            'user_id' => $pemagang->id,
            'fullname' => $pemagang->name,
            'born_date' => '2001-01-01',
            'gender' => 'Laki-laki',
            'phone_number' => '081234567890',
            'city_id' => $city->id,
            'student_id' => 'NIM' . uniqid(),
            'institution_id' => $institution->id,
            'faculty_id' => $faculty->id,
            'study_program_id' => $studyProgram->id,
            'internship_reason' => 'Test contract',
            'internship_type' => 'Magang Mandiri',
            'internship_arrangement' => 'Onsite',
            'current_status' => 'Mahasiswa/Pelajar',
            'english_book_ability' => 'Saya bisa',
            'division_id' => $currentDivision->id,
            'brand_id' => $brandTL->id,
            'internship_status' => \App\Models\InternshipRegistration::STATUS_WAITING,
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.interns.update', $intern->id), [
            'brand_id' => $brandTL->id,
            'internship_interest' => $programmer->name,
        ]);

        $response->assertSessionHasErrors('division_id');
        $this->assertSame($currentDivision->id, $intern->fresh()->division_id);
    }
}
