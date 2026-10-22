<?php

namespace Tests\Feature\CloudStorage;

use App\Contracts\CloudFileReadInterface;
use App\Contracts\CloudFolderQueryInterface;
use App\Models\CloudFileRegistry;
use App\Models\CloudStorageSetting;
use App\Models\User;
use App\Services\CloudStorage\CloudStorageInterface;
use App\Services\CloudStorage\CloudStorageProviderInterface;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\CloudStorage\GoogleDriveStorageDriver;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use App\Services\DocumentPublishingService;
use App\Services\SystemAssetService;
use App\Services\UniversalFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Gas02ProviderContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CloudStorageSetting::create([
            'name'                        => 'Penyimpanan Google Drive Sistem',
            'canonical_parent_folder_id'  => 'ROOT_CANONICAL_TEST_123',
            'canonical_parent_folder_url' => 'https://drive.google.com/drive/folders/ROOT_CANONICAL_TEST_123',
            'is_enabled'                  => true,
            'is_verified'                 => true,
            'connection_status'           => 'connected',
        ]);
    }

    /**
     * Test 1: Kontrak CloudStorageProviderInterface menggabungkan Read, Query, Folder, dan Storage.
     */
    public function test_cloud_storage_provider_interface_hierarchy(): void
    {
        $this->assertTrue(is_subclass_of(CloudStorageProviderInterface::class, CloudStorageInterface::class));
        $this->assertTrue(is_subclass_of(CloudStorageProviderInterface::class, CloudFileReadInterface::class));
        $this->assertTrue(is_subclass_of(CloudStorageProviderInterface::class, CloudFolderQueryInterface::class));
    }

    /**
     * Test 2: Seluruh driver (GAS, OAuth2 Google Drive, Local Fallback) mengimplementasikan kontrak provider terpadu.
     */
    public function test_all_drivers_implement_cloud_storage_provider_interface(): void
    {
        $this->assertInstanceOf(CloudStorageProviderInterface::class, new LocalStorageFallbackDriver());
        $this->assertInstanceOf(CloudStorageProviderInterface::class, new GoogleDriveStorageDriver());
        $this->assertInstanceOf(CloudStorageProviderInterface::class, new GoogleAppsScriptDriveDriver());
    }

    /**
     * Test 3: Service Container me-resolve CloudStorageInterface ke driver yang tepat sesuai konfigurasi.
     */
    public function test_service_container_resolves_cloud_storage_interface(): void
    {
        $resolved = app(CloudStorageInterface::class);
        $this->assertInstanceOf(CloudStorageInterface::class, $resolved);
        $this->assertInstanceOf(CloudStorageProviderInterface::class, $resolved);
    }

    /**
     * Test 4: UniversalFileService beroperasi dengan mock generic CloudStorageProviderInterface tanpa ketergantungan OAuth2 konkret.
     */
    public function test_universal_file_service_accepts_generic_cloud_storage_provider(): void
    {
        $mockDriver = $this->createMock(CloudStorageProviderInterface::class);
        $mockDriver->method('getDriverName')->willReturn('generic_mock');
        $mockDriver->method('getOrCreateFolderPath')->willReturn('MOCK_FOLDER_ID_456');
        $mockDriver->method('uploadFile')->willReturn([
            'file_id'       => 'MOCK_REMOTE_FILE_789',
            'web_view_link' => 'https://mock.storage/view/789',
            'operation'     => 'created',
            'driver'        => 'generic_mock',
        ]);
        $mockDriver->method('downloadFileBytes')->willReturn('SAMPLE_BINARY_BYTES');

        $service = new UniversalFileService($mockDriver);
        $this->assertSame($mockDriver, $service->getDriver());

        // Test put
        $registry = $service->put(
            fileOrContent: 'SAMPLE_BINARY_BYTES',
            category: CloudFileRegistry::CATEGORY_CV,
            ownerType: 'registration',
            ownerId: 999,
            customFileName: 'cv_candidate.pdf'
        );

        $this->assertEquals('verified', $registry->sync_status);
        $this->assertEquals('MOCK_REMOTE_FILE_789', $registry->drive_file_id);
        $this->assertEquals('MOCK_FOLDER_ID_456', $registry->drive_folder_id);

        // Test download
        $admin = User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin_gas02_' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'role'     => 'admin',
        ]);
        $bytes = $service->downloadBytes($registry, $admin);
        $this->assertEquals('SAMPLE_BINARY_BYTES', $bytes);
    }

    /**
     * Test 5: CloudSyncManager, DocumentPublishingService, dan SystemAssetService dapat diinjeksi mock provider generic.
     */
    public function test_all_storage_consumers_accept_generic_cloud_storage_interface(): void
    {
        $mockDriver = $this->createMock(CloudStorageProviderInterface::class);
        $mockDriver->method('getDriverName')->willReturn('gas_drive_mock');
        $mockDriver->method('isConfigured')->willReturn(true);
        $mockDriver->method('setRootFolderId')->willReturnSelf();

        // 1. CloudSyncManager
        $syncManager = new CloudSyncManager($mockDriver);
        $this->assertSame($mockDriver, $syncManager->getDriver());

        // 2. DocumentPublishingService
        $docService = app(DocumentPublishingService::class, ['cloudDriver' => $mockDriver]);
        $this->assertInstanceOf(DocumentPublishingService::class, $docService);

        // 3. SystemAssetService
        $assetService = app(SystemAssetService::class, ['cloudDriver' => $mockDriver]);
        $this->assertInstanceOf(SystemAssetService::class, $assetService);
    }
}
