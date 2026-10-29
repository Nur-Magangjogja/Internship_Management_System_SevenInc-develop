<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\SpreadsheetConnectionService;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DedicatedBrandSpreadsheetConnectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DivisionSeeder::class);
        $this->seed(\Database\Seeders\OfficialBrandsDatasetSeeder::class);
    }

    protected function createAdmin(): User
    {
        static $counter = 1;
        $counter++;

        return User::create([
            'name'     => "Admin {$counter}",
            'email'    => "admin_{$counter}_" . uniqid() . "@example.com",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role'     => 'admin',
        ]);
    }

    protected function createPemagang(array $attributes = []): User
    {
        static $counter = 1;
        $counter++;

        return User::create(array_merge([
            'name'     => "Pemagang {$counter}",
            'email'    => "pemagang_{$counter}_" . uniqid() . "@example.com",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role'     => 'pemagang',
        ], $attributes));
    }

    /**
     * Test getAllConnections otomatis memuat seluruh koneksi: 1 Follow-Up dan 11 Brand Pendaftaran (tanpa generic null global).
     */
    public function test_get_all_connections_ensures_all_11_brands_and_globals_exist(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $connections = $service->getAllConnections();

        // 1 Follow-up + 11 Brands = 12 koneksi
        $this->assertGreaterThanOrEqual(12, $connections->count());

        $followUp = $connections->firstWhere('connection_key', SpreadsheetConnection::KEY_FOLLOWUP);
        $this->assertNotNull($followUp, 'Koneksi Follow-Up harus ada.');
        $this->assertNull($followUp->brand_id);

        $expectedCodes = ['SI', 'RW', 'AKM', 'MJ', 'MK', 'TT', 'MO', 'BC', 'AK', 'TL', 'TS'];
        foreach ($expectedCodes as $code) {
            $brand = Brand::where('code', $code)->first();
            $this->assertNotNull($brand, "Brand {$code} harus ada.");

            $brandConn = $connections->first(fn($c) => $c->connection_key === SpreadsheetConnection::KEY_REGISTRATION && $c->brand_id === $brand->id);
            $this->assertNotNull($brandConn, "Koneksi Spreadsheet untuk brand {$brand->name} ({$code}) harus ada.");
            $this->assertStringContainsString($brand->name, $brandConn->display_name);
        }
    }

    /**
     * Test halaman index Spreadsheet Connection menampilkan kartu dedicated untuk setiap brand.
     */
    public function test_admin_index_displays_dedicated_cards_for_each_brand(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->get(route('admin.spreadsheet-connections.index'));

        $response->assertOk();
        $response->assertSee('Integrasi Google Spreadsheet');

        // Memastikan nama-nama brand utama dan hostnya muncul di kartu
        $response->assertSee('Ada Kamar');
        $response->assertSee('daftar.adakamar.id');
        $response->assertSee('SEVEN INC');
        $response->assertSee('daftar.seveninc.web.id');
        $response->assertSee('Magang Jogja');
        $response->assertSee('daftar.magangjogja.com');
        $response->assertSee('Republik Web');
        $response->assertSee('daftar.republikweb.net');
    }

    /**
     * Test admin dapat mengedit link spreadsheet brand 1 per 1 secara manual tanpa saling tumpang tindih.
     */
    public function test_admin_can_edit_brand_spreadsheet_links_independently(): void
    {
        $admin = $this->createAdmin();
        $service = app(SpreadsheetConnectionService::class);
        $service->ensureBrandConnectionsExist();

        $brandAKM = Brand::where('code', 'AKM')->firstOrFail();
        $brandMJ = Brand::where('code', 'MJ')->firstOrFail();

        $connAKM = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $brandAKM->id)
            ->firstOrFail();

        $connMJ = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $brandMJ->id)
            ->firstOrFail();

        // 1. Edit form Ada Kamar
        $editResponseAKM = $this->actingAs($admin)
            ->get(route('admin.spreadsheet-connections.edit', $connAKM));

        $editResponseAKM->assertOk();
        $editResponseAKM->assertSee('Ada Kamar (AKM)');
        $editResponseAKM->assertSee('daftar.adakamar.id');
        $editResponseAKM->assertDontSee('<select name="brand_id"', false); // Dropdown telah diganti badge terkunci

        // 2. Simpan URL khusus Ada Kamar
        $updateAKM = $this->actingAs($admin)
            ->put(route('admin.spreadsheet-connections.update', $connAKM), [
                'display_name'     => 'Spreadsheet Pendaftaran (Ada Kamar)',
                'brand_id'         => $brandAKM->id,
                'spreadsheet_url'  => 'https://docs.google.com/spreadsheets/d/1SpreadsheetAdaKamarManualTest111/edit',
                'drive_folder_url' => 'https://drive.google.com/drive/folders/1DriveFolderAdaKamarManualTest111',
            ]);

        $updateAKM->assertRedirect(route('admin.spreadsheet-connections.index'));

        // 3. Simpan URL khusus Magang Jogja (link berbeda)
        $updateMJ = $this->actingAs($admin)
            ->put(route('admin.spreadsheet-connections.update', $connMJ), [
                'display_name'     => 'Spreadsheet Pendaftaran (Magang Jogja)',
                'brand_id'         => $brandMJ->id,
                'spreadsheet_url'  => 'https://docs.google.com/spreadsheets/d/1SpreadsheetMagangJogjaManualTest222/edit',
                'drive_folder_url' => 'https://drive.google.com/drive/folders/1DriveFolderMagangJogjaManualTest222',
            ]);

        $updateMJ->assertRedirect(route('admin.spreadsheet-connections.index'));

        // 4. Verifikasi bahwa kedua koneksi tersimpan terpisah dan independen
        $connAKM->refresh();
        $connMJ->refresh();

        $this->assertEquals('1SpreadsheetAdaKamarManualTest111', $connAKM->spreadsheet_id);
        $this->assertEquals('1DriveFolderAdaKamarManualTest111', $connAKM->drive_folder_id);
        $this->assertEquals($brandAKM->id, $connAKM->brand_id);

        $this->assertEquals('1SpreadsheetMagangJogjaManualTest222', $connMJ->spreadsheet_id);
        $this->assertEquals('1DriveFolderMagangJogjaManualTest222', $connMJ->drive_folder_id);
        $this->assertEquals($brandMJ->id, $connMJ->brand_id);
    }

    /**
     * Test admin dapat menetapkan brand tertentu sebagai Brand Default sistem.
     */
    public function test_admin_can_set_brand_as_default(): void
    {
        $admin = $this->createAdmin();
        $brandRW = Brand::where('code', 'RW')->firstOrFail();
        $brandSI = Brand::where('code', 'SI')->firstOrFail();

        $response = $this->actingAs($admin)
            ->post(route('admin.brands.set-default', $brandRW));

        $response->assertSessionHas('success');

        $brandRW->refresh();
        $brandSI->refresh();

        $this->assertTrue($brandRW->is_default, 'Republik Web harus menjadi default brand.');
        $this->assertFalse($brandSI->is_default, 'Seven Inc tidak lagi menjadi default brand.');
        $this->assertEquals($brandRW->id, Brand::getDefaultBrand()->id);
    }

    /**
     * Test registrasi akun calon pemagang langsung mengunci brand pada tabel users.
     */
    public function test_user_registration_locks_brand_on_account(): void
    {
        $brandTL = Brand::where('code', 'TL')->firstOrFail();

        $response = $this->post('http://daftar.tukanglas.org' . route('user.register.submit', [], false), [
            'username'              => 'calon_las_' . uniqid(),
            'email'                 => 'calon_las_' . uniqid() . '@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('pemagang.registration.form'));

        $user = User::latest('id')->first();
        $this->assertEquals($brandTL->id, $user->brand_id, 'User brand_id harus terkunci ke Tukang Las.');
        $this->assertEquals('daftar.tukanglas.org', $user->registration_host);
    }

    /**
     * Test calon pemagang yang beralih domain tidak akan pernah tertukar brand-nya.
     */
    public function test_candidate_switching_domain_does_not_change_brand(): void
    {
        $brandTL = Brand::where('code', 'TL')->firstOrFail();
        $brandRW = Brand::where('code', 'RW')->firstOrFail();

        // 1. Akun dibuat dan terkunci di Tukang Las
        $user = $this->createPemagang([
            'brand_id'          => $brandTL->id,
            'registration_host' => 'daftar.tukanglas.org',
        ]);

        // Aktifkan koneksi spreadsheet Tukang Las agar tidak maintenance
        $connTL = SpreadsheetConnection::firstOrCreate(
            [
                'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
                'brand_id'       => $brandTL->id,
            ],
            [
                'display_name'      => 'Spreadsheet Pendaftaran (Tukang Las)',
                'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummyTukangLasSheet/edit',
                'spreadsheet_id'    => '1DummyTukangLasSheet',
                'is_enabled'        => true,
                'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
            ]
        );
        $connTL->update([
            'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummyTukangLasSheet/edit',
            'spreadsheet_id'    => '1DummyTukangLasSheet',
            'is_enabled'        => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        // 2. Pemagang membuka form pendaftaran dari domain Republik Web
        $response = $this->actingAs($user)
            ->get('http://daftar.republikweb.net' . route('pemagang.registration.form', [], false));

        $response->assertOk();
        $response->assertViewIs('pemagang.registration.form');
        $divisions = $response->viewData('divisions');

        // Form harus tetap menyajikan divisi milik Tukang Las, bukan Republik Web
        $this->assertContains('Las', $divisions);
        $this->assertNotContains('Programmer', $divisions);

        // 3. Simpan draft pendaftaran via domain Republik Web
        $postResponse = $this->actingAs($user)
            ->post('http://daftar.republikweb.net' . route('pemagang.registration.draft', [], false), [
                'fullname' => 'Calon Tukang Las Handal',
                'email'    => $user->email,
            ]);

        $registration = InternshipRegistration::where('user_id', $user->id)->first();
        $this->assertNotNull($registration);
        $this->assertEquals($brandTL->id, $registration->brand_id, 'Registrasi harus tetap terkunci pada Tukang Las.');
        $this->assertNotEquals($brandRW->id, $registration->brand_id, 'Registrasi tidak boleh berubah ke Republik Web.');
    }

    /**
     * Test admin atau HR staff dapat mengubah brand pemagang secara manual melalui halaman edit admin.
     */
    public function test_admin_or_hr_can_update_candidate_brand(): void
    {
        $admin = $this->createAdmin();
        $brandTL = Brand::where('code', 'TL')->firstOrFail();
        $brandRW = Brand::where('code', 'RW')->firstOrFail();

        $user = $this->createPemagang(['brand_id' => $brandTL->id]);
        $registration = InternshipRegistration::create([
            'user_id'           => $user->id,
            'fullname'          => 'Calon Mutasi Brand',
            'brand_id'          => $brandTL->id,
            'registration_host' => 'daftar.tukanglas.org',
            'is_draft'          => 1,
        ]);

        $response = $this->actingAs($admin)
            ->patch(route('admin.interns.update', $registration->id), [
                'fullname' => 'Calon Mutasi Brand',
                'brand_id' => $brandRW->id,
            ]);

        $response->assertOk();

        $registration->refresh();
        $user->refresh();

        $this->assertEquals($brandRW->id, $registration->brand_id, 'Brand registrasi harus terupdate ke Republik Web oleh Admin.');
        $this->assertEquals($brandRW->id, $user->brand_id, 'Brand user harus terupdate ke Republik Web oleh Admin.');
    }

    /**
     * Test proteksi maintenance: form pendaftaran menampilkan maintenance jika koneksi belum aktif atau belum terhubung.
     */
    public function test_maintenance_protection_when_brand_spreadsheet_is_not_connected_or_disabled(): void
    {
        $brandMO = Brand::where('code', 'MO')->firstOrFail();

        // Pastikan koneksi Makeover nonaktif
        $connMO = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $brandMO->id)
            ->first();
        if ($connMO) {
            $connMO->update([
                'is_enabled'        => false,
                'connection_status' => SpreadsheetConnection::STATUS_NOT_CONFIGURED,
            ]);
        }

        $user = $this->createPemagang(['brand_id' => $brandMO->id]);

        // 1. Kunjungi form pendaftaran Makeover saat nonaktif
        $response = $this->actingAs($user)
            ->get('http://daftar.makeover.id' . route('pemagang.registration.form', [], false));

        $response->assertOk();
        $response->assertViewIs('pemagang.registration.maintenance');
        $response->assertSeeText('Sistem Pendaftaran Sedang Dalam Pemeliharaan');
        $response->assertSeeText($brandMO->name);

        // 2. Submit form pendaftaran Makeover saat nonaktif harus ditolak
        $postResponse = $this->actingAs($user)
            ->post('http://daftar.makeover.id' . route('pemagang.registration.draft', [], false), [
                'fullname' => 'Calon Makeover Kreatif',
            ]);

        $postResponse->assertSessionHas('error');
        $this->assertNull(InternshipRegistration::where('user_id', $user->id)->first(), 'Data tidak boleh masuk saat maintenance.');
    }
}
