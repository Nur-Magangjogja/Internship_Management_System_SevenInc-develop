<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\InternshipRegistration as IR;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\SpreadsheetSyncEngineService;
use Tests\TestCase;

class MultiBrandTenantIsolationFase1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SpreadsheetConnection::ensureDriveFolderColumns();
        app(\App\Services\SpreadsheetConnectionService::class)->ensureBrandConnectionsExist();
    }

    protected function tearDown(): void
    {
        $testBrandIds = Brand::whereIn('code', ['NFD', 'UNC', 'DIS'])->pluck('id');
        if ($testBrandIds->isNotEmpty()) {
            SpreadsheetConnection::whereIn('brand_id', $testBrandIds)->delete();
            Brand::whereIn('id', $testBrandIds)->delete();
        }
        parent::tearDown();
    }

    /**
     * Test 1: Sinkronisasi users.brand_id pada alur update() saat status berubah (Cabang A)
     */
    public function test_user_brand_is_synced_when_intern_updated_with_status_change()
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test_iso@example.com'],
            ['name' => 'Admin Iso', 'username' => 'admin_iso', 'password' => bcrypt('password123'), 'role' => 'admin']
        );

        $brandA = Brand::where('code', 'SI')->first() ?? Brand::create(['code' => 'SI', 'name' => 'SEVEN INC', 'is_default' => true]);
        $brandB = Brand::where('code', 'TL')->first() ?? Brand::create(['code' => 'TL', 'name' => 'Tukang Las', 'is_default' => false]);

        $user = User::create([
            'name'              => 'Pemagang Sync A',
            'username'          => 'user_sync_a_' . uniqid(),
            'email'             => 'user_sync_a_' . uniqid() . '@example.com',
            'password'          => bcrypt('password123'),
            'role'              => 'user',
            'brand_id'          => $brandA->id,
            'registration_host' => 'daftar.seveninc.web.id',
        ]);

        $intern = IR::create([
            'user_id'           => $user->id,
            'email'             => $user->email,
            'fullname'          => 'Pemagang Sync A',
            'brand_id'          => $brandA->id,
            'registration_host' => 'daftar.seveninc.web.id',
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // Kirim update dengan perubahan status (dari waiting ke accepted) dan perubahan brand (dari SI ke TL)
        $response = $this->actingAs($admin)->patch(route('admin.interns.update', $intern->id), [
            'fullname'          => 'Pemagang Sync A Updated',
            'internship_status' => IR::STATUS_ACCEPTED,
            'brand_id'          => $brandB->id,
            'brand'             => $brandB->name,
        ]);

        $response->assertSessionHasNoErrors();

        $intern->refresh();
        $user->refresh();

        // Verifikasi pendaftaran dan akun user identik tersinkron ke Brand B
        $this->assertEquals($brandB->id, $intern->brand_id);
        $this->assertEquals($brandB->id, $user->brand_id);
        $this->assertEquals(IR::STATUS_ACCEPTED, $intern->internship_status);

        // Verifikasi registration_host asal tetap terjaga (jejak audit)
        $this->assertEquals('daftar.seveninc.web.id', $user->registration_host);
        $this->assertEquals('daftar.seveninc.web.id', $intern->registration_host);

        $intern->forceDelete();
        $user->forceDelete();
    }

    /**
     * Test 2: Sinkronisasi users.brand_id pada alur updateStatus() (Aksi Cepat Tabel)
     */
    public function test_user_brand_is_synced_when_intern_status_updated_via_quick_action()
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test_iso@example.com'],
            ['name' => 'Admin Iso', 'username' => 'admin_iso', 'password' => bcrypt('password123'), 'role' => 'admin']
        );

        $brandA = Brand::where('code', 'SI')->first() ?? Brand::create(['code' => 'SI', 'name' => 'SEVEN INC', 'is_default' => true]);
        $brandB = Brand::where('code', 'RW')->first() ?? Brand::create(['code' => 'RW', 'name' => 'Republik Web', 'is_default' => false]);

        $user = User::create([
            'name'              => 'Pemagang Quick Sync',
            'username'          => 'user_quick_' . uniqid(),
            'email'             => 'user_quick_' . uniqid() . '@example.com',
            'password'          => bcrypt('password123'),
            'role'              => 'user',
            'brand_id'          => $brandA->id,
            'registration_host' => 'daftar.seveninc.web.id',
        ]);

        $intern = IR::create([
            'user_id'           => $user->id,
            'email'             => $user->email,
            'fullname'          => 'Pemagang Quick Sync',
            'brand_id'          => $brandA->id,
            'registration_host' => 'daftar.seveninc.web.id',
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // Admin menerima via updateStatus modal dengan brand B (Republik Web)
        $response = $this->actingAs($admin)->patchJson(route('admin.interns.status.update', $intern->id), [
            'internship_status' => IR::STATUS_ACCEPTED,
            'brand'             => $brandB->name,
        ]);

        $response->assertStatus(200);

        $intern->refresh();
        $user->refresh();

        $this->assertEquals($brandB->id, $intern->brand_id);
        $this->assertEquals($brandB->id, $user->brand_id);

        $intern->attachments()->forceDelete();
        $intern->forceDelete();
        $user->forceDelete();
    }

    /**
     * Test 3: Isolasi Tenant Spreadsheet - Tidak Boleh Silent Fallback ke Brand Default
     */
    public function test_spreadsheet_connection_tenant_isolation_never_falls_back_when_brand_specified()
    {
        $engine = app(SpreadsheetSyncEngineService::class);

        $defaultBrand = Brand::getDefaultBrand();
        $this->assertNotNull($defaultBrand);

        // Kasus A: brandId tidak diketahui (ID fiktif) -> HARUS return null (Isolasi Tenant, DILARANG fallback ke Seven Inc)
        $unconfiguredBrandConn = $engine->getRegistrationConnection(999888);
        $this->assertNull($unconfiguredBrandConn, 'Brand yang belum memiliki koneksi tidak boleh mencampur data ke brand default.');

        // Kasus B: brandId null -> fallback ke default brand diperbolehkan untuk alur registrasi umum pra-brand
        $fallbackConn = $engine->getRegistrationConnection(null);
        $this->assertNotNull($fallbackConn);
        $this->assertEquals($defaultBrand->id, $fallbackConn->brand_id);
    }

    /**
     * Test 4: Isolasi Folder Google Drive pada CloudSyncManager
     */
    public function test_cloud_sync_manager_uses_brand_specific_drive_folder()
    {
        $brandTL = Brand::where('code', 'TL')->first() ?? Brand::create(['code' => 'TL', 'name' => 'Tukang Las']);

        // Set folder drive khusus untuk brand Tukang Las
        $connTL = SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION, 'brand_id' => $brandTL->id],
            ['display_name' => 'Spreadsheet Pendaftaran (Tukang Las)', 'drive_folder_id' => 'DRIVE_FOLDER_TL_999', 'is_enabled' => true]
        );

        $intern = IR::create([
            'email'             => 'intern_tl_' . uniqid() . '@example.com',
            'fullname'          => 'Pemagang Tukang Las',
            'brand_id'          => $brandTL->id,
            'internship_status' => IR::STATUS_WAITING,
        ]);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $intern->id,
            'category'                   => 'cv',
            'attachment_type'            => 'cv',
            'file_path'                  => 'attachments/test_cv_' . uniqid() . '.pdf',
            'original_name'              => 'test_cv.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
        ]);

        // Buat file fisik tiruan di disk local
        \Illuminate\Support\Facades\Storage::disk('local')->put($attachment->file_path, '%PDF-dummy-content%');

        $driver = new \App\Services\CloudStorage\LocalStorageFallbackDriver();
        $cloudSync = new CloudSyncManager($driver);

        // Unggah lampiran
        $res = $cloudSync->uploadRegistrationAttachment($attachment);

        // Driver getRootFolderId harus merefleksikan folder brand Tukang Las
        $this->assertEquals('DRIVE_FOLDER_TL_999', $driver->getRootFolderId());

        // Bersihkan
        \Illuminate\Support\Facades\Storage::disk('local')->delete($attachment->file_path);
        $attachment->forceDelete();
        $intern->forceDelete();
    }

    /**
     * Test 5: Upload Google Drive dihentikan dan dicatat sebagai gagal jika folder brand belum dikonfigurasi
     */
    public function test_upload_halts_and_marks_failure_when_brand_drive_folder_is_not_configured()
    {
        $brandNoFolder = Brand::firstOrCreate(
            ['code' => 'NFD'],
            ['name' => 'No Folder Brand', 'is_default' => false]
        );

        // Pastikan koneksi brand ada tapi drive_folder_id kosong
        SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION, 'brand_id' => $brandNoFolder->id],
            ['display_name' => 'Spreadsheet Pendaftaran (No Folder)', 'drive_folder_id' => null, 'is_enabled' => true]
        );

        $intern = IR::create([
            'email'             => 'intern_nofolder_' . uniqid() . '@example.com',
            'fullname'          => 'Pemagang No Folder',
            'brand_id'          => $brandNoFolder->id,
            'internship_status' => IR::STATUS_WAITING,
        ]);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $intern->id,
            'category'                   => 'cv',
            'attachment_type'            => 'cv',
            'file_path'                  => 'attachments/test_nofolder_' . uniqid() . '.pdf',
            'original_name'              => 'test_nofolder.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
        ]);

        \Illuminate\Support\Facades\Storage::disk('local')->put($attachment->file_path, '%PDF-nofolder-content%');

        $cloudSync = app(CloudSyncManager::class);

        $thrown = false;
        try {
            $cloudSync->syncRegistrationAttachment($attachment);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('Isolasi Tenant', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Upload Google Drive wajib menghentikan eksekusi dan melempar RuntimeException jika folder belum dikonfigurasi.');

        $attachment->refresh();
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $attachment->drive_sync_status);
        $this->assertNotNull($attachment->drive_error_message);
        $this->assertStringContainsString('Isolasi Tenant', $attachment->drive_error_message);

        // Driver root folder wajib di-reset ke null
        if (method_exists($cloudSync->getDriver(), 'getRootFolderId')) {
            $this->assertNull($cloudSync->getDriver()->getRootFolderId());
        }

        \Illuminate\Support\Facades\Storage::disk('local')->delete($attachment->file_path);
        $attachment->forceDelete();
        $intern->forceDelete();
    }

    /**
     * Test 6: Driver tidak boleh membawa root folder dari brand sebelumnya saat request brand berikutnya tidak memiliki konfigurasi
     */
    public function test_driver_does_not_retain_previous_brand_root_folder_across_calls()
    {
        $cloudSync = app(CloudSyncManager::class);
        $driver = $cloudSync->getDriver();

        if (!method_exists($driver, 'setRootFolderId') || !method_exists($driver, 'getRootFolderId')) {
            $this->markTestSkipped('Driver tidak memiliki antarmuka setRootFolderId.');
        }

        // Simulasikan state driver sebelumnya berisi folder Brand A
        $driver->setRootFolderId('PREVIOUS_BRAND_FOLDER_AAA');
        $this->assertEquals('PREVIOUS_BRAND_FOLDER_AAA', $driver->getRootFolderId());

        $brandUnconfigured = Brand::firstOrCreate(
            ['code' => 'UNC'],
            ['name' => 'Unconfigured Brand', 'is_default' => false]
        );

        $intern = IR::create([
            'email'             => 'intern_unc_' . uniqid() . '@example.com',
            'fullname'          => 'Pemagang Unconfigured',
            'brand_id'          => $brandUnconfigured->id,
            'internship_status' => IR::STATUS_WAITING,
        ]);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $intern->id,
            'category'                   => 'cv',
            'attachment_type'            => 'cv',
            'file_path'                  => 'attachments/test_unc_' . uniqid() . '.pdf',
            'original_name'              => 'test_unc.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
        ]);

        \Illuminate\Support\Facades\Storage::disk('local')->put($attachment->file_path, '%PDF-unc-content%');

        try {
            $cloudSync->uploadRegistrationAttachment($attachment);
        } catch (\Throwable) {
            // Expected
        }

        // Root folder driver WAJIB di-reset ke null dan TIDAK boleh bocor membawa folder Brand A
        $this->assertNull($driver->getRootFolderId(), 'Worker tidak boleh membawa root folder dari brand sebelumnya.');

        \Illuminate\Support\Facades\Storage::disk('local')->delete($attachment->file_path);
        $attachment->forceDelete();
        $intern->forceDelete();
    }

    /**
     * Test 7: Spreadsheet connection readiness memeriksa isRegistrationReady dan menolak koneksi nonaktif tanpa fallback
     */
    public function test_spreadsheet_connection_readiness_rejects_disabled_connection_without_fallback()
    {
        $engine = app(SpreadsheetSyncEngineService::class);

        $brandDis = Brand::firstOrCreate(
            ['code' => 'DIS'],
            ['name' => 'Disabled Brand', 'is_default' => false]
        );

        $connDis = SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION, 'brand_id' => $brandDis->id],
            [
                'display_name'      => 'Spreadsheet (Disabled)',
                'spreadsheet_id'    => 'SHEET_DIS_123',
                'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/SHEET_DIS_123/edit',
                'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
                'is_enabled'        => false, // Dinonaktifkan
            ]
        );

        $this->assertFalse($connDis->isRegistrationReady());

        // getRegistrationConnection dengan requireReady: true HARUS return null
        $resolved = $engine->getRegistrationConnection($brandDis->id, requireReady: true);
        $this->assertNull($resolved, 'Koneksi nonaktif tidak boleh dianggap valid untuk sinkronisasi.');

        // Pastikan TIDAK fallback ke brand default
        $defaultBrand = Brand::getDefaultBrand();
        if ($resolved) {
            $this->assertNotEquals($defaultBrand->id, $resolved->brand_id);
        }
    }

    /**
     * Test 8: Aksi Cepat updateStatus menolak kontradiksi antara brand (nama) dan brand_id dengan HTTP 422
     */
    public function test_quick_action_rejects_conflicting_brand_name_and_id_with_422()
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test_iso@example.com'],
            ['name' => 'Admin Iso', 'username' => 'admin_iso', 'password' => bcrypt('password123'), 'role' => 'admin']
        );

        $brandSI = Brand::where('code', 'SI')->first() ?? Brand::create(['code' => 'SI', 'name' => 'SEVEN INC', 'is_default' => true]);
        $brandTL = Brand::where('code', 'TL')->first() ?? Brand::create(['code' => 'TL', 'name' => 'Tukang Las', 'is_default' => false]);

        $intern = IR::create([
            'email'             => 'intern_conflict_' . uniqid() . '@example.com',
            'fullname'          => 'Pemagang Conflict',
            'brand_id'          => $brandSI->id,
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // Request mengirim brand 'SEVEN INC' tapi brand_id adalah milik 'Tukang Las'
        $response = $this->actingAs($admin)->patchJson(route('admin.interns.status.update', $intern->id), [
            'internship_status' => IR::STATUS_ACCEPTED,
            'brand'             => $brandSI->name,
            'brand_id'          => $brandTL->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Input brand tidak konsisten: nama brand dan ID brand tidak merujuk ke brand yang sama.']);

        $intern->forceDelete();
    }

    /**
     * Test 9: Aksi Cepat updateStatus menolak penerimaan pemagang jika belum ada brand yang ditentukan
     */
    public function test_quick_action_rejects_accepting_intern_without_brand_with_422()
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test_iso@example.com'],
            ['name' => 'Admin Iso', 'username' => 'admin_iso', 'password' => bcrypt('password123'), 'role' => 'admin']
        );

        // Buat pemagang tanpa brand_id
        $intern = IR::create([
            'email'             => 'intern_nobrand_' . uniqid() . '@example.com',
            'fullname'          => 'Pemagang Tanpa Brand',
            'brand_id'          => null,
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // Coba terima tanpa mengirimkan brand
        $response = $this->actingAs($admin)->patchJson(route('admin.interns.status.update', $intern->id), [
            'internship_status' => IR::STATUS_ACCEPTED,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Pemagang tidak dapat diterima tanpa brand yang ditentukan.']);

        $intern->forceDelete();
    }

    /**
     * Test 10: Perubahan brand bersifat transaksional (Rollback atomik saat terjadi kegagalan)
     */
    public function test_transactional_brand_update_rolls_back_atomically_on_failure()
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test_iso@example.com'],
            ['name' => 'Admin Iso', 'username' => 'admin_iso', 'password' => bcrypt('password123'), 'role' => 'admin']
        );

        $brandSI = Brand::where('code', 'SI')->first() ?? Brand::create(['code' => 'SI', 'name' => 'SEVEN INC', 'is_default' => true]);
        $brandTL = Brand::where('code', 'TL')->first() ?? Brand::create(['code' => 'TL', 'name' => 'Tukang Las', 'is_default' => false]);

        $user = User::create([
            'name'              => 'User Trans Rollback',
            'username'          => 'user_rollback_' . uniqid(),
            'email'             => 'user_rollback_' . uniqid() . '@example.com',
            'password'          => bcrypt('password123'),
            'role'              => 'user',
            'brand_id'          => $brandSI->id,
            'registration_host' => 'daftar.seveninc.web.id',
        ]);

        $intern = IR::create([
            'user_id'           => $user->id,
            'email'             => $user->email,
            'fullname'          => 'User Trans Rollback',
            'brand_id'          => $brandSI->id,
            'registration_host' => 'daftar.seveninc.web.id',
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // Mock LifecycleService agar melempar exception saat transisi
        $mockLifecycle = \Mockery::mock(\App\Services\RegistrationLifecycleService::class);
        $mockLifecycle->shouldReceive('transition')
            ->andThrow(new \InvalidArgumentException('Transisi status ditolak oleh lifecycle contract.'));
        $this->app->instance(\App\Services\RegistrationLifecycleService::class, $mockLifecycle);

        // Kirim request update
        $response = $this->actingAs($admin)->patch(route('admin.interns.update', $intern->id), [
            'fullname'          => 'User Trans Rollback Updated',
            'internship_status' => IR::STATUS_ACCEPTED,
            'brand_id'          => $brandTL->id,
            'brand'             => $brandTL->name,
        ]);

        $response->assertStatus(302); // Redirect back with error
        $response->assertSessionHas('error');

        // Verifikasi tidak ada perubahan parsial di database (atomisitas terjaga)
        $intern->refresh();
        $user->refresh();

        $this->assertEquals($brandSI->id, $intern->brand_id);
        $this->assertEquals($brandSI->id, $user->brand_id);
        $this->assertEquals(IR::STATUS_WAITING, $intern->internship_status);
        $this->assertEquals('daftar.seveninc.web.id', $intern->registration_host);

        $intern->forceDelete();
        $user->forceDelete();
    }
}
