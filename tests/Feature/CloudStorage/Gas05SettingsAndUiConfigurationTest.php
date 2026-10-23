<?php

namespace Tests\Feature\CloudStorage;

use App\Models\CloudStorageSetting;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\CloudStorage\CloudStorageProviderInterface;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Gas05SettingsAndUiConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    protected string $endpointUrl = 'https://script.google.com/macros/s/AKfy_mock_gas05_settings/exec';
    protected string $sharedSecret = 'secret_gas05_hmac_key_98765';
    protected string $canonicalRootId = 'ROOT_CANONICAL_GAS05_FOLDER';

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.cloud_storage.provider', 'apps_script_drive');
        Config::set('services.google_apps_script.enabled', true);
        Config::set('services.google_apps_script.endpoint_url', $this->endpointUrl);
        Config::set('services.google_apps_script.shared_secret', $this->sharedSecret);

        $this->adminUser = User::create([
            'name'     => 'Admin GAS05 Settings',
            'email'    => 'admin_gas05_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Pendaftaran Pemagang 2026',
                'spreadsheet_id'           => '1lY3UhhViRCOstvZZeJtEEW2Yol8eFIIFgkCqt3haQSU',
                'drive_folder_id'          => $this->canonicalRootId,
                'apps_script_endpoint_url' => $this->endpointUrl,
                'is_enabled'               => true,
            ]
        );
    }

    /**
     * Test 1: Halaman Cloud Storage Settings memuat Apps Script Drive sebagai pilihan utama
     * tanpa menuntut OAuth2 Client ID/Secret.
     */
    public function test_cloud_storage_settings_page_loads_with_apps_script_drive_as_primary(): void
    {
        $setting = CloudStorageSetting::getEffectiveSettings();
        $setting->update([
            'provider'                   => 'apps_script_drive',
            'canonical_parent_folder_id' => $this->canonicalRootId,
            'is_enabled'                 => true,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('admin.cloud-storage.index'));

        $response->assertStatus(200);
        $response->assertSee('Apps Script Drive');
        $response->assertSee('Google Apps Script Drive (Utama');
        $response->assertSee('Antrean Dokumen');

        // Pastikan tidak ada peringatan yang menuntut OAuth2 keys saat menggunakan Apps Script Drive
        $response->assertDontSee('GOOGLE_DRIVE_CLIENT_ID');
        $response->assertDontSee('GOOGLE_DRIVE_CLIENT_SECRET');
        $response->assertDontSee('GOOGLE_DRIVE_REFRESH_TOKEN');
    }

    /**
     * Test 2: Memperbarui pengaturan penyimpanan menyimpan provider dan me-reset status verifikasi jika root berubah.
     */
    public function test_update_cloud_storage_settings_saves_provider_and_canonical_parent(): void
    {
        $newFolderId = '1New_Canonical_Folder_Id_GAS05_XYZ';
        $newFolderUrl = "https://drive.google.com/drive/folders/{$newFolderId}";

        $response = $this->actingAs($this->adminUser)->put(route('admin.cloud-storage.update'), [
            'name'                        => 'Penyimpanan Google Drive Sistem Terverifikasi',
            'provider'                    => 'apps_script_drive',
            'canonical_parent_folder_url' => $newFolderUrl,
            'is_enabled'                  => '1',
        ]);

        $response->assertRedirect(route('admin.cloud-storage.index'));
        $response->assertSessionHas('success');

        $setting = CloudStorageSetting::getEffectiveSettings();
        $this->assertEquals('apps_script_drive', $setting->provider);
        $this->assertEquals($newFolderId, $setting->canonical_parent_folder_id);
        $this->assertFalse($setting->is_verified);
        $this->assertEquals(CloudStorageSetting::STATUS_UNTESTED, $setting->connection_status);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'cloud_storage_settings_updated',
            'actor_user_id' => $this->adminUser->id,
        ]);
    }

    /**
     * Test 3: Tombol uji koneksi memicu drive_ping secara read-only tanpa mutasi file/folder.
     */
    public function test_test_connection_endpoint_calls_drive_ping_without_mutations(): void
    {
        $setting = CloudStorageSetting::getEffectiveSettings();
        $setting->update([
            'provider'                   => 'apps_script_drive',
            'canonical_parent_folder_id' => $this->canonicalRootId,
            'connection_status'          => CloudStorageSetting::STATUS_UNTESTED,
            'is_verified'                => false,
        ]);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'          => true,
                'action'           => 'drive_ping',
                'root_folder_id'   => $this->canonicalRootId,
                'root_folder_name' => 'SERTIF_MAIN_ROOT',
                'accessible'       => true,
                'storage'          => [
                    'used_gb'  => '2.45',
                    'limit_gb' => '15.00',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson(route('admin.cloud-storage.test'));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'details' => [
                'gateway'      => 'google_apps_script',
                'drive_folder' => true,
            ],
        ]);

        $setting->refresh();
        $this->assertEquals(CloudStorageSetting::STATUS_CONNECTED, $setting->connection_status);
        $this->assertTrue($setting->is_verified);
        $this->assertNotNull($setting->verified_at);
        $this->assertNotNull($setting->last_successful_test_at);
        $this->assertNull($setting->last_error_summary);

        // Verifikasi request yang dikirim adalah drive_ping (read-only)
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true) ?: $request->data();
            $payload = $body['payload'] ?? $body;
            return ($payload['action'] ?? null) === 'drive_ping'
                && ($payload['drive_folder_id'] ?? null) === $this->canonicalRootId;
        });
    }

    /**
     * Test 4: Uji koneksi melaporkan status gagal yang jujur ketika Apps Script mengembalikan error.
     */
    public function test_test_connection_reports_honest_failure_when_folder_not_found(): void
    {
        $setting = CloudStorageSetting::getEffectiveSettings();
        $setting->update([
            'provider'                   => 'apps_script_drive',
            'canonical_parent_folder_id' => $this->canonicalRootId,
            'connection_status'          => CloudStorageSetting::STATUS_CONNECTED,
            'is_verified'                => true,
        ]);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'    => false,
                'error_code' => 'CANONICAL_ROOT_NOT_FOUND',
                'message'    => 'Folder induk kanonikal Google Drive tidak ditemukan.',
            ], 400),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson(route('admin.cloud-storage.test'));

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);

        $setting->refresh();
        $this->assertEquals(CloudStorageSetting::STATUS_ERROR, $setting->connection_status);
        $this->assertFalse($setting->is_verified);
        $this->assertNotNull($setting->last_error_summary);
        $this->assertStringContainsString('Folder induk kanonikal Google Drive tidak ditemukan', $setting->last_error_summary);
    }

    /**
     * Test 5: Hierarki konfigurasi memprioritaskan database setting di atas konfigurasi .env.
     */
    public function test_config_hierarchy_prioritizes_database_setting_over_env(): void
    {
        // Kasus A: DB setting = local_fallback (walau .env = apps_script_drive)
        $setting = CloudStorageSetting::getEffectiveSettings();
        $setting->update(['provider' => 'local_fallback']);

        $resolvedA = app(CloudStorageProviderInterface::class);
        $this->assertInstanceOf(LocalStorageFallbackDriver::class, $resolvedA);

        // Kasus B: DB setting = apps_script_drive
        $setting->update(['provider' => 'apps_script_drive']);

        $resolvedB = app(CloudStorageProviderInterface::class);
        $this->assertInstanceOf(GoogleAppsScriptDriveDriver::class, $resolvedB);
    }

    /**
     * Test 6: Membuka atau me-refresh halaman pengaturan tidak pernah memicu request jaringan ke Google Drive.
     */
    public function test_page_refresh_does_not_mutate_drive_or_dispatch_http_requests(): void
    {
        Http::fake();

        $response = $this->actingAs($this->adminUser)->get(route('admin.cloud-storage.index'));
        $response->assertStatus(200);

        // Pastikan TIDAK ADA request HTTP keluar yang dipicu hanya oleh GET index
        Http::assertNothingSent();
    }

    /**
     * Test 7: Kredensial rahasia (shared secret / OAuth2 secrets) tidak bocor pada response HTML.
     */
    public function test_secret_credentials_are_not_exposed_in_html(): void
    {
        $superSecretKey = 'VERY_SECRET_HMAC_GAS05_KEY_XYZ_99999';
        Config::set('services.google_apps_script.shared_secret', $superSecretKey);

        $response = $this->actingAs($this->adminUser)->get(route('admin.cloud-storage.index'));

        $response->assertStatus(200);
        $response->assertDontSee($superSecretKey);
        $response->assertSee('Terpasang di Server (HMAC-SHA256 Aktif)');
    }
}
