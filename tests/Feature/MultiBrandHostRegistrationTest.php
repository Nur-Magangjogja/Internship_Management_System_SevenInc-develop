<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\City;
use App\Models\Division;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\BrandDomainResolverService;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MultiBrandHostRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->seed(\Database\Seeders\DivisionSeeder::class);
        $this->seed(\Database\Seeders\BrandSeeder::class);

        app(\App\Services\SpreadsheetConnectionService::class)->ensureBrandConnectionsExist();
        \App\Models\SpreadsheetConnection::where('connection_key', \App\Models\SpreadsheetConnection::KEY_REGISTRATION)
            ->update([
                'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummyMultiBrandTestSpreadsheet/edit',
                'spreadsheet_id'    => '1DummyMultiBrandTestSpreadsheet',
                'is_enabled'        => true,
                'connection_status' => \App\Models\SpreadsheetConnection::STATUS_CONNECTED,
            ]);
    }

    protected function createUser(array $attributes = []): User
    {
        static $counter = 1;
        $counter++;

        return User::create(array_merge([
            'name'     => "User {$counter}",
            'email'    => "user_{$counter}_" . uniqid() . "@example.com",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role'     => 'pemagang',
        ], $attributes));
    }

    /**
     * Test BrandDomainResolverService resolves correct brands from configured hosts.
     */
    public function test_brand_domain_resolver_resolves_all_domain_hosts(): void
    {
        $resolver = app(BrandDomainResolverService::class);

        $testCases = [
            'daftar.magangjogja.com' => 'MJ',
            'daftar.areakerja.com'   => 'AK',
            'daftar.republikweb.net' => 'RW',
            'daftar.adakamar.id'     => 'AKM',
            'daftar.magangku.com'    => 'MK',
            'daftar.tuantanah.com'   => 'TT',
            'daftar.makeover.id'     => 'MO',
            'daftar.bimbelcerdas.com'=> 'BC',
            'daftar.tukanglas.org'   => 'TL',
            'daftar.titipsini.com'   => 'TS',
            'daftar.seveninc.web.id' => 'SI',
            'seveninc.web.id'        => 'SI',
            'localhost'              => 'SI', // fallback default
        ];

        foreach ($testCases as $host => $expectedCode) {
            $brand = $resolver->resolveFromHost($host);
            $this->assertNotNull($brand, "Host {$host} harus berhasil di-resolve ke Brand.");
            $this->assertEquals($expectedCode, $brand->code, "Host {$host} harus menghasilkan brand code {$expectedCode}");
        }
    }

    /**
     * Test registrasi pemagang dari host spesifik otomatis menyimpan brand_id dan registration_host.
     */
    public function test_intern_registration_from_host_assigns_brand_and_host(): void
    {
        $user = $this->createUser(['role' => 'pemagang']);
        $division = Division::firstOrCreate(['name' => 'Web Developer'], ['slug' => 'web-developer', 'code' => 'WEB']);
        $institution = Institution::firstOrCreate(['name' => 'Universitas Gadjah Mada'], ['is_active' => true]);
        $city = City::firstOrCreate(['name' => 'Sleman']);

        $brandMJ = Brand::where('code', 'MJ')->firstOrFail();

        $response = $this->actingAs($user)
            ->post('http://daftar.magangjogja.com' . route('pemagang.registration.store', [], false), [
                'fullname'                 => 'Budi Santoso',
                'gender'                   => 'Laki-laki',
                'email'                    => 'budi_santoso@example.com',
                'phone_number'             => '081234567890',
                'line_id'                  => 'budi_santoso',
                'current_status'           => 'Mahasiswa',
                'student_id'               => '12345678',
                'institution_id'           => $institution->id,
                'institution_name'         => $institution->name,
                'faculty'                  => 'Teknik Informatika',
                'major'                    => 'Sistem Informasi',
                'study_program'            => 'Sistem Informasi',
                'current_address'          => 'Sleman, DI Yogyakarta',
                'city_id'                  => $city->id,
                'start_date'               => date('Y') . '-08-01',
                'planned_duration'         => '3 Bulan',
                'internship_duration'      => '3 Bulan',
                'division_id'              => $division->id,
                'internship_interest'      => $division->name,
                'internship_type'          => 'Mandiri',
                'internship_reason'        => 'Ingin mengasah kemampuan pemrograman',
                'wfo_readiness'            => 'Siap',
                'schedule_shift_readiness' => 'Siap',
                'has_laptop'               => 1,
                'form_schema_version'      => '',
            ]);

        $intern = InternshipRegistration::where('user_id', $user->id)->first();
        $this->assertNotNull($intern, 'Data pendaftaran harus tersimpan.');
        $this->assertEquals($brandMJ->id, $intern->brand_id, 'Brand ID harus terhubung ke Magang Jogja (MJ).');
        $this->assertEquals('daftar.magangjogja.com', $intern->registration_host, 'Registration host harus tersimpan.');
        $this->assertEquals('Magang Jogja', $intern->brand, 'Accessor brand harus mengembalikan nama Magang Jogja.');
    }

    /**
     * Test struktur folder Google Drive berjenjang: REGISTRASI / {TAHUN} / {BRAND} / {PEMAGANG}
     */
    public function test_drive_folder_hierarchy_includes_brand_subfolder(): void
    {
        $brandAK = Brand::where('code', 'AK')->firstOrFail();

        $intern = InternshipRegistration::create([
            'user_id'           => $this->createUser()->id,
            'fullname'          => 'Siti Rahma',
            'brand_id'          => $brandAK->id,
            'registration_host' => 'daftar.areakerja.com',
            'created_at'        => '2026-05-10 10:00:00',
        ]);

        $folderPath = $intern->getDriveApplicantFolderPath();
        $expectedFolderName = $intern->getDriveApplicantFolderName();

        $this->assertEquals("REGISTRASI/2026/Area Kerja/{$expectedFolderName}", $folderPath);

        $cvPath = $intern->getDriveAttachmentFolderPath('cv');
        $this->assertEquals("REGISTRASI/2026/Area Kerja/{$expectedFolderName}/Curriculum Vitae (CV)", $cvPath);
    }

    /**
     * Test SpreadsheetSyncEngineService meresolusi koneksi registrasi brand secara dinamis
     * dan Follow-Up tetap menggunakan 1 koneksi terpusat.
     */
    public function test_spreadsheet_sync_resolves_brand_connection_and_single_followup(): void
    {
        $brandMJ = Brand::where('code', 'MJ')->firstOrFail();
        $brandRW = Brand::where('code', 'RW')->firstOrFail();

        // 1. Koneksi registrasi khusus MJ
        $connMJ = SpreadsheetConnection::updateOrCreate(
            [
                'connection_key'  => SpreadsheetConnection::KEY_REGISTRATION,
                'brand_id'        => $brandMJ->id,
            ],
            [
                'display_name'    => 'Spreadsheet Pendaftaran (Magang Jogja)',
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit',
                'spreadsheet_id'  => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
                'is_enabled'      => true,
            ]
        );

        // 2. Koneksi registrasi default brand sistem (SEVEN INC)
        $defaultBrand = Brand::getDefaultBrand();
        $connDefault = SpreadsheetConnection::firstOrCreate(
            [
                'connection_key'  => SpreadsheetConnection::KEY_REGISTRATION,
                'brand_id'        => $defaultBrand->id,
            ],
            [
                'display_name'    => "Spreadsheet Pendaftaran ({$defaultBrand->name})",
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1DefaultSpreadsheetId9999999999999999999999999/edit',
                'spreadsheet_id'  => '1DefaultSpreadsheetId9999999999999999999999999',
                'is_enabled'      => true,
            ]
        );
        $connDefault->update([
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1DefaultSpreadsheetId9999999999999999999999999/edit',
            'spreadsheet_id'  => '1DefaultSpreadsheetId9999999999999999999999999',
            'is_enabled'      => true,
        ]);

        // 3. Koneksi Follow-Up tunggal
        $connFollowUp = SpreadsheetConnection::firstOrCreate(
            [
                'connection_key'  => SpreadsheetConnection::KEY_FOLLOWUP,
                'brand_id'        => null,
            ],
            [
                'display_name'    => 'Spreadsheet Follow-Up CRM Guru/Dosen',
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1FollowUpCRMSpreadsheetId111111111111111111/edit',
                'spreadsheet_id'  => '1FollowUpCRMSpreadsheetId111111111111111111',
                'is_enabled'      => true,
            ]
        );

        $engine = app(SpreadsheetSyncEngineService::class);

        // Resolusi pendaftar MJ -> harus mengambil connMJ
        $resolvedConnMJ = $engine->getRegistrationConnection($brandMJ->id);
        $this->assertNotNull($resolvedConnMJ);
        $this->assertEquals($connMJ->id, $resolvedConnMJ->id);

        // Resolusi pendaftar dengan brand yang belum punya koneksi khusus -> harus fallback ke connDefault
        $resolvedConnFallback = $engine->getRegistrationConnection(999999);
        $this->assertNotNull($resolvedConnFallback);
        $this->assertEquals($connDefault->id, $resolvedConnFallback->id);

        // Resolusi tanpa brandId -> harus fallback ke connDefault
        $resolvedConnNull = $engine->getRegistrationConnection(null);
        $this->assertNotNull($resolvedConnNull);
        $this->assertEquals($connDefault->id, $resolvedConnNull->id);

        // Follow-Up selalu 1 koneksi terpusat
        $followUpConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();
        $this->assertNotNull($followUpConn);
        $this->assertEquals($connFollowUp->id, $followUpConn->id);
        $this->assertNull($followUpConn->brand_id, 'Koneksi Follow-Up tidak boleh terikat pada 1 brand saja.');
    }

    /**
     * Test admin intern API memuat data brand dan registration_host untuk label di tabel.
     */
    public function test_admin_intern_api_includes_brand_labels(): void
    {
        $admin = $this->createUser(['role' => 'admin']);
        $brandTS = Brand::where('code', 'TS')->firstOrFail();

        $intern = InternshipRegistration::create([
            'user_id'           => $this->createUser()->id,
            'fullname'          => 'Titip Sini Candidate',
            'brand_id'          => $brandTS->id,
            'registration_host' => 'daftar.titipsini.com',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'is_draft'          => false,
            'created_at'        => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.interns.api', ['status' => 'waiting']));

        $response->assertOk();
        $response->assertJsonFragment([
            'id'                => $intern->id,
            'brand_id'          => $brandTS->id,
            'brand'             => 'Titip Sini',
            'brand_name'        => 'Titip Sini',
            'registration_host' => 'daftar.titipsini.com',
        ]);
    }

    /**
     * Test admin dapat membuat koneksi spreadsheet khusus brand dan memperbaruinya.
     */
    public function test_admin_can_create_and_update_brand_specific_spreadsheet_connection(): void
    {
        $admin = $this->createUser(['role' => 'admin']);
        $brandMO = Brand::where('code', 'MO')->firstOrFail();

        // 1. Buat koneksi brand Makeover via admin
        $createResponse = $this->actingAs($admin)
            ->post(route('admin.spreadsheet-connections.create-brand'), [
                'brand_id' => $brandMO->id,
            ]);

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $brandMO->id)
            ->first();

        $this->assertNotNull($connection, 'Koneksi spreadsheet brand Makeover harus terbentuk.');
        $createResponse->assertRedirect(route('admin.spreadsheet-connections.edit', $connection));

        // 2. Update konfigurasi URL Spreadsheet dan Drive Folder
        $updateResponse = $this->actingAs($admin)
            ->put(route('admin.spreadsheet-connections.update', $connection), [
                'display_name'             => 'Spreadsheet Pendaftaran Makeover ID',
                'brand_id'                 => $brandMO->id,
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/1MakeOverSpreadsheetID1234567890/edit',
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AKfycbx_MakeOverEndpoint123/exec',
                'drive_folder_url'         => 'https://drive.google.com/drive/folders/1MakeOverDriveFolderID123',
            ]);

        $updateResponse->assertRedirect(route('admin.spreadsheet-connections.index'));

        $connection->refresh();
        $this->assertEquals('1MakeOverSpreadsheetID1234567890', $connection->spreadsheet_id);
        $this->assertEquals('1MakeOverDriveFolderID123', $connection->drive_folder_id);
        $this->assertEquals($brandMO->id, $connection->brand_id);
    }

    /**
     * Test registrasi akun baru pada domain brand mengikat brand ke sesi.
     */
    public function test_auth_register_stores_detected_brand_in_session(): void
    {
        $brandBC = Brand::where('code', 'BC')->firstOrFail();

        $response = $this->post('http://daftar.bimbelcerdas.com/register', [
            'name'                  => 'Siswa Bimbel',
            'email'                 => 'siswa_bimbel_' . uniqid() . '@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHas('registration_brand_id', $brandBC->id);
        $response->assertSessionHas('registration_brand_code', 'BC');
        $response->assertSessionHas('registration_host', 'daftar.bimbelcerdas.com');
    }

    /**
     * Test multi-brand registrasi mendistribusikan data ke link spreadsheet dan folder drive masing-masing secara terpisah.
     */
    public function test_multi_brand_candidates_distribute_to_their_respective_spreadsheets_and_drive(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);

        $brandMJ = Brand::where('code', 'MJ')->firstOrFail();
        $brandRW = Brand::where('code', 'RW')->firstOrFail();
        $brandAK = Brand::where('code', 'AK')->firstOrFail();

        // 1. Siapkan 3 link Spreadsheet berbeda untuk masing-masing Brand
        $connMJ = SpreadsheetConnection::updateOrCreate(
            [
                'connection_key'  => SpreadsheetConnection::KEY_REGISTRATION,
                'brand_id'        => $brandMJ->id,
            ],
            [
                'display_name'    => 'Spreadsheet Pendaftaran (Magang Jogja)',
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1SpreadsheetMJ1111111111111111111111111111/edit',
                'spreadsheet_id'  => '1SpreadsheetMJ1111111111111111111111111111',
                'drive_folder_id' => '1DriveFolderMJ111111111111111111111',
                'is_enabled'      => true,
            ]
        );

        $connRW = SpreadsheetConnection::updateOrCreate(
            [
                'connection_key'  => SpreadsheetConnection::KEY_REGISTRATION,
                'brand_id'        => $brandRW->id,
            ],
            [
                'display_name'    => 'Spreadsheet Pendaftaran (Republik Web)',
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1SpreadsheetRW2222222222222222222222222222/edit',
                'spreadsheet_id'  => '1SpreadsheetRW2222222222222222222222222222',
                'drive_folder_id' => '1DriveFolderRW222222222222222222222',
                'is_enabled'      => true,
            ]
        );

        $connAK = SpreadsheetConnection::updateOrCreate(
            [
                'connection_key'  => SpreadsheetConnection::KEY_REGISTRATION,
                'brand_id'        => $brandAK->id,
            ],
            [
                'display_name'    => 'Spreadsheet Pendaftaran (Area Kerja)',
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1SpreadsheetAK3333333333333333333333333333/edit',
                'spreadsheet_id'  => '1SpreadsheetAK3333333333333333333333333333',
                'drive_folder_id' => '1DriveFolderAK333333333333333333333',
                'is_enabled'      => true,
            ]
        );

        // 2. Buat 3 calon pemagang pada 3 brand berbeda
        $internMJ = InternshipRegistration::create([
            'user_id'           => $this->createUser()->id,
            'fullname'          => 'Calon Pemagang MJ',
            'brand_id'          => $brandMJ->id,
            'registration_host' => 'daftar.magangjogja.com',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
            'created_at'        => '2026-03-01 10:00:00',
        ]);

        $internRW = InternshipRegistration::create([
            'user_id'           => $this->createUser()->id,
            'fullname'          => 'Calon Pemagang RW',
            'brand_id'          => $brandRW->id,
            'registration_host' => 'daftar.republikweb.net',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
            'created_at'        => '2026-03-02 10:00:00',
        ]);

        $internAK = InternshipRegistration::create([
            'user_id'           => $this->createUser()->id,
            'fullname'          => 'Calon Pemagang AK',
            'brand_id'          => $brandAK->id,
            'registration_host' => 'daftar.areakerja.com',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
            'created_at'        => '2026-03-03 10:00:00',
        ]);

        // 3. Queue sinkronisasi spreadsheet untuk masing-masing pemagang
        $syncMJ = $engine->queueSyncForRegistration($internMJ, allowPendaftar: true);
        $syncRW = $engine->queueSyncForRegistration($internRW, allowPendaftar: true);
        $syncAK = $engine->queueSyncForRegistration($internAK, allowPendaftar: true);

        // 4. Verifikasi bahwa setiap pendaftar terisolasi ke spreadsheet brand masing-masing
        $this->assertNotNull($syncMJ);
        $this->assertEquals($connMJ->id, $syncMJ->spreadsheet_connection_id);
        $this->assertEquals('1SpreadsheetMJ1111111111111111111111111111', $syncMJ->target_spreadsheet_id);

        $this->assertNotNull($syncRW);
        $this->assertEquals($connRW->id, $syncRW->spreadsheet_connection_id);
        $this->assertEquals('1SpreadsheetRW2222222222222222222222222222', $syncRW->target_spreadsheet_id);

        $this->assertNotNull($syncAK);
        $this->assertEquals($connAK->id, $syncAK->spreadsheet_connection_id);
        $this->assertEquals('1SpreadsheetAK3333333333333333333333333333', $syncAK->target_spreadsheet_id);

        // 5. Verifikasi bahwa folder Drive pendaftar terisolasi ke subfolder brand masing-masing
        $this->assertStringStartsWith('REGISTRASI/2026/Magang Jogja/', $internMJ->getDriveApplicantFolderPath());
        $this->assertStringStartsWith('REGISTRASI/2026/Republik Web/', $internRW->getDriveApplicantFolderPath());
        $this->assertStringStartsWith('REGISTRASI/2026/Area Kerja/', $internAK->getDriveApplicantFolderPath());
    }
}
