<?php

namespace Tests\Feature;

use App\Models\CloudStorageSetting;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudStorageSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->unique = uniqid('cs_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);
    }

    public function test_admin_can_view_cloud_storage_settings_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.cloud-storage.index'));

        $response->assertStatus(200);
        $response->assertSee('Pusat Pengaturan Penyimpanan Cloud');
        $response->assertSee('Pemetaan Folder Google Drive');
    }

    public function test_admin_can_update_folder_urls_and_ids_are_extracted(): void
    {
        $assetsUrl = 'https://drive.google.com/drive/folders/1ABCDEF1234567890xyz_test_asset';
        $templatesUrl = 'https://drive.google.com/drive/folders/2BCDEF2345678901xyz_test_template';
        $generatedDocsUrl = 'https://drive.google.com/drive/folders/3CDEFG3456789012xyz_test_gen';
        $archivesId = '4DEFGH4567890123xyz_test_arch'; // Input direct folder ID

        $response = $this->actingAs($this->admin)->put(route('admin.cloud-storage.update'), [
            'name'                          => 'Penyimpanan Utama Google Drive SertIF',
            'system_assets_folder_url'      => $assetsUrl,
            'document_templates_folder_url' => $templatesUrl,
            'generated_docs_folder_url'     => $generatedDocsUrl,
            'archives_folder_url'           => $archivesId,
            'is_enabled'                    => '1',
        ]);

        $response->assertRedirect(route('admin.cloud-storage.index'));
        $response->assertSessionHas('success');

        $setting = CloudStorageSetting::first();
        $this->assertNotNull($setting);
        $this->assertEquals('1ABCDEF1234567890xyz_test_asset', $setting->system_assets_folder_id);
        $this->assertEquals('2BCDEF2345678901xyz_test_template', $setting->document_templates_folder_id);
        $this->assertEquals('3CDEFG3456789012xyz_test_gen', $setting->generated_docs_folder_id);
        $this->assertEquals('4DEFGH4567890123xyz_test_arch', $setting->archives_folder_id);
    }

    public function test_registration_folder_reference_remains_separate(): void
    {
        SpreadsheetConnection::ensureDriveFolderColumns();
        SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'     => 'Registration Connection',
                'spreadsheet_id'   => 'mock_spreadsheet_id',
                'drive_folder_id'  => 'REG_EXISTING_FOLDER_ID_999',
                'drive_folder_url' => 'https://drive.google.com/drive/folders/REG_EXISTING_FOLDER_ID_999',
                'is_enabled'       => true,
            ]
        );

        $response = $this->actingAs($this->admin)->get(route('admin.cloud-storage.index'));

        $response->assertStatus(200);
        $response->assertSee('REG_EXISTING_FOLDER_ID_999');
    }

    public function test_connection_test_updates_status(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock_access_token_abc',
                'expires_in'   => 3600,
            ], 200),
            'https://www.googleapis.com/drive/v3/files/*' => Http::response([
                'id'           => 'mock_folder_123',
                'name'         => 'System_Assets',
                'mimeType'     => 'application/vnd.google-apps.folder',
                'trashed'      => false,
                'capabilities' => ['canAddChildren' => true],
            ], 200),
        ]);

        // Pastikan config testing mengizinkan Google Drive test
        config([
            'services.google_drive.enabled'       => true,
            'services.google_drive.client_id'     => 'test_client_id',
            'services.google_drive.client_secret' => 'test_client_secret',
            'services.google_drive.refresh_token' => 'test_refresh_token',
        ]);

        $setting = CloudStorageSetting::getEffectiveSettings();
        $setting->update([
            'system_assets_folder_id' => 'mock_folder_123',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.cloud-storage.test'));

        $response->assertRedirect(route('admin.cloud-storage.index'));
        $setting->refresh();
        $this->assertEquals(CloudStorageSetting::STATUS_CONNECTED, $setting->connection_status);
        $this->assertNotNull($setting->last_successful_test_at);
    }

    public function test_unauthorized_roles_cannot_access_cloud_storage_settings(): void
    {
        $response = $this->actingAs($this->pemagang)->get(route('admin.cloud-storage.index'));
        $response->assertRedirect(route('user.dashboard'));

        $responseJson = $this->actingAs($this->pemagang)->getJson(route('admin.cloud-storage.index'));
        $responseJson->assertStatus(403);
    }
}
