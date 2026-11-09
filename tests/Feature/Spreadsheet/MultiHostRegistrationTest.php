<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\City;
use App\Models\Division;
use App\Models\FormSchema;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\RegistrationFormSnapshot;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\FormBuilderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MultiHostRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Brand $brandMJ;
    protected Brand $brandAK;
    protected Division $division;
    protected Institution $institution;
    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->seed(\Database\Seeders\DivisionSeeder::class);
        $this->seed(\Database\Seeders\OfficialBrandsDatasetSeeder::class);

        $this->brandMJ = Brand::where('code', 'MJ')->firstOrFail();
        $this->brandAK = Brand::where('code', 'AK')->firstOrFail();

        app(\App\Services\SpreadsheetConnectionService::class)->ensureBrandConnectionsExist();
        // Pastikan SpreadsheetConnection terhubung penuh (Spreadsheet + Drive) agar tidak masuk mode maintenance
        SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->update([
                'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummyMultiBrandTest/edit',
                'spreadsheet_id'    => '1DummyMultiBrandTest',
                'drive_folder_url'  => 'https://drive.google.com/drive/folders/1DummyFolder',
                'drive_folder_id'   => '1DummyFolder',
                'is_enabled'        => true,
                'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
            ]);

        $this->user = User::create([
            'name'      => 'Pemagang MultiHost Test',
            'email'     => 'pemagang_multihost_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        $this->division = Division::where('name', 'Administrasi')->firstOrFail();
        $this->institution = Institution::firstOrCreate(['name' => 'Universitas Gadjah Mada'], ['is_active' => true]);
        $this->city = City::firstOrCreate(['name' => 'Sleman']);
    }

    public function test_host_resolves_brand_and_renders_custom_brand_questions(): void
    {
        $formBuilder = app(FormBuilderService::class);
        $year = (int) date('Y');

        // 1. Buat dan terbitkan skema khusus brand MJ
        $draftMJ = $formBuilder->forkGlobalTemplateToBrandDraft($year, $this->brandMJ->id);
        $formBuilder->addFieldToDraft($draftMJ, [
            'field_key'   => 'custom_mj_portfolio_link',
            'field_type'  => 'text',
            'label'       => 'Link Portfolio Khusus Magang Jogja',
        ]);
        $formBuilder->publishVersion($draftMJ);

        // 2. Request melalui host MJ: daftar.magangjogja.com
        $response = $this->actingAs($this->user)
            ->get('http://' . $this->brandMJ->registration_host . route('pemagang.registration.form', [], false));

        $response->assertStatus(200);
        $response->assertSee('Link Portfolio Khusus Magang Jogja');
    }

    public function test_unregistered_host_fails_closed_or_shows_closed_state(): void
    {
        $response = $this->actingAs($this->user)
            ->get('http://unregistered-unknown-brand.example.com' . route('pemagang.registration.form', [], false));

        $this->assertTrue(
            str_contains($response->getContent(), 'Pemeliharaan') || str_contains($response->getContent(), 'ditutup')
        );
    }

    public function test_public_registration_cannot_be_overridden_by_query_brand_id(): void
    {
        $formBuilder = app(FormBuilderService::class);
        $year = (int) date('Y');

        // Terbitkan skema khusus brand MJ
        $draftMJ = $formBuilder->forkGlobalTemplateToBrandDraft($year, $this->brandMJ->id);
        $formBuilder->addFieldToDraft($draftMJ, [
            'field_key'   => 'custom_mj_verified_token',
            'field_type'  => 'text',
            'label'       => 'MJ Verification Token',
        ]);
        $formBuilder->publishVersion($draftMJ);

        // Submit form ke host MJ namun mengirimkan query ?brand_id={AK->id}
        $response = $this->actingAs($this->user)
            ->post('http://' . $this->brandMJ->registration_host . route('pemagang.registration.store', ['brand_id' => $this->brandAK->id], false), [
                'fullname'                 => 'Budi Test Host Integrity',
                'gender'                   => 'Laki-laki',
                'born_date'                => '2002-05-15',
                'religion'                 => 'Islam',
                'email'                    => 'budi_spoof_' . uniqid() . '@example.com',
                'phone_number'             => '081234567899',
                'line_id'                  => 'budi_spoof',
                'current_status'           => 'Mahasiswa',
                'student_id'               => '12345678',
                'institution_id'           => $this->institution->id,
                'institution_name'         => $this->institution->name,
                'faculty'                  => 'Teknik Informatika',
                'major'                    => 'Sistem Informasi',
                'study_program'            => 'Sistem Informasi',
                'current_address'          => 'Sleman, DI Yogyakarta',
                'city_id'                  => $this->city->id,
                'start_date'               => date('Y') . '-08-01',
                'planned_duration'         => '3 Bulan',
                'internship_duration'      => '3 Bulan',
                'division_id'              => $this->division->id,
                'internship_interest'      => $this->division->name,
                'internship_type'          => 'Mandiri',
                'internship_reason'        => 'Test host brand protection',
                'wfo_readiness'            => 'Siap',
                'schedule_shift_readiness' => 'Siap',
                'has_laptop'               => 1,
            ]);

        $intern = InternshipRegistration::where('user_id', $this->user->id)->first();
        $this->assertNotNull($intern);
        // Harus terikat secara absolut ke brand host MJ, bukan query spoof AK!
        $this->assertEquals($this->brandMJ->id, $intern->brand_id);
    }

    public function test_submitting_registration_freezes_snapshot_with_brand_and_schema_version(): void
    {
        $formBuilder = app(FormBuilderService::class);
        $year = (int) date('Y');

        $draftMJ = $formBuilder->forkGlobalTemplateToBrandDraft($year, $this->brandMJ->id);
        $publishedMJ = $formBuilder->publishVersion($draftMJ);

        $response = $this->actingAs($this->user)
            ->post('http://' . $this->brandMJ->registration_host . route('pemagang.registration.store', [], false), [
                'fullname'                 => 'Rina Snapshot Test',
                'gender'                   => 'Perempuan',
                'born_date'                => '2003-08-20',
                'religion'                 => 'Islam',
                'email'                    => 'rina_snapshot_' . uniqid() . '@example.com',
                'phone_number'             => '081234567891',
                'line_id'                  => 'rina_snap',
                'current_status'           => 'Mahasiswa',
                'student_id'               => '87654321',
                'institution_id'           => $this->institution->id,
                'institution_name'         => $this->institution->name,
                'faculty'                  => 'Teknik Informatika',
                'major'                    => 'Sistem Informasi',
                'study_program'            => 'Sistem Informasi',
                'current_address'          => 'Sleman, DI Yogyakarta',
                'city_id'                  => $this->city->id,
                'start_date'               => date('Y') . '-08-01',
                'planned_duration'         => '3 Bulan',
                'internship_duration'      => '3 Bulan',
                'division_id'              => $this->division->id,
                'internship_interest'      => $this->division->name,
                'internship_type'          => 'Mandiri',
                'internship_reason'        => 'Testing snapshot freeze',
                'wfo_readiness'            => 'Siap',
                'schedule_shift_readiness' => 'Siap',
                'has_laptop'               => 1,
            ]);

        $intern = InternshipRegistration::where('user_id', $this->user->id)->first();
        $this->assertNotNull($intern);

        $snapshot = RegistrationFormSnapshot::where('registration_id', $intern->id)->first();
        $this->assertNotNull($snapshot, 'Snapshot pendaftaran harus dibekukan.');
        $this->assertEquals($publishedMJ->id, $snapshot->form_schema_id);
        $this->assertEquals($publishedMJ->version_code, $snapshot->form_schema_version);
        $this->assertEquals($this->brandMJ->id, $snapshot->schema_snapshot['brand_id']);
    }
}
