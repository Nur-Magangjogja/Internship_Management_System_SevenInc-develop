<?php

namespace Tests\Feature\CloudStorage;

use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\Brand;
use App\Models\City;
use App\Models\CloudFileRegistry;
use App\Models\CloudStorageSetting;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\GeneratedDocument;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\StudyProgram;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\CloudStorage\CloudStorageProviderInterface;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\CloudStorage\SystemStorageConfigService;
use App\Services\DocumentPublishingService;
use App\Services\SpreadsheetTransport\NullSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetSyncEngineService;
use App\Services\SystemAssetService;
use App\Services\UniversalFileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Gas04IntegrationAndHistoricalSafetyTest extends TestCase
{
    use DatabaseTransactions;

    protected string $endpointUrl = 'https://script.google.com/macros/s/AKfy_mock_gas04_gateway/exec';
    protected string $sharedSecret = 'secret_gas04_safety_key_12345';
    protected string $canonicalRootId = 'ROOT_CANONICAL_GAS04_FOLDER';

    protected User $adminUser;
    protected User $hrUser;
    protected User $pemagangUserA;
    protected User $pemagangUserB;
    protected InternshipRegistration $regA;
    protected InternshipRegistration $regB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');

        Config::set('services.cloud_storage.provider', 'apps_script_drive');
        Config::set('services.google_apps_script.enabled', true);
        Config::set('services.google_apps_script.endpoint_url', $this->endpointUrl);
        Config::set('services.google_apps_script.shared_secret', $this->sharedSecret);
        Config::set('services.google_apps_script.timeout_seconds', 60);

        CloudStorageSetting::firstOrCreate([], [
            'name'                        => 'Penyimpanan Google Drive Sistem',
            'canonical_parent_folder_id'  => $this->canonicalRootId,
            'canonical_parent_folder_url' => "https://drive.google.com/drive/folders/{$this->canonicalRootId}",
            'is_enabled'                  => true,
            'is_verified'                 => true,
            'connection_status'           => 'connected',
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

        $this->adminUser = User::create([
            'name'     => 'Admin GAS04',
            'email'    => 'admin_gas04_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $this->hrUser = User::create([
            'name'     => 'HR Staff GAS04',
            'email'    => 'hr_gas04_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagangUserA = User::create([
            'name'     => 'Pemagang Alice',
            'email'    => 'alice_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $this->pemagangUserB = User::create([
            'name'     => 'Pemagang Bob',
            'email'    => 'bob_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $unique = substr(md5(uniqid()), 0, 6);
        $institution = Institution::create([
            'name'            => 'Univ Test ' . $unique,
            'normalized_name' => 'univ test ' . $unique,
            'is_active'       => true,
        ]);
        $faculty = Faculty::create([
            'name'           => 'Fakultas Teknik ' . $unique,
            'institution_id' => $institution->id,
        ]);
        $program = StudyProgram::create([
            'name'       => 'Informatika ' . $unique,
            'faculty_id' => $faculty->id,
        ]);
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);
        $division = Division::firstOrCreate(
            ['name' => 'Software Engineer'],
            ['code' => 'SE', 'description' => 'Software Engineering']
        );

        $this->regA = InternshipRegistration::create([
            'user_id'                  => $this->pemagangUserA->id,
            'fullname'                 => 'Alice Pemagang',
            'email'                    => $this->pemagangUserA->email,
            'phone'                    => '081234567890',
            'student_id'               => 'NIM' . rand(1000, 9999),
            'institution_id'           => $institution->id,
            'institution_name'         => $institution->name,
            'faculty_id'               => $faculty->id,
            'faculty'                  => $faculty->name,
            'study_program_id'         => $program->id,
            'study_program'            => $program->name,
            'current_city_id'          => $city->id,
            'current_city'             => $city->name,
            'division_id'              => $division->id,
            'internship_type'          => 'online',
            'internship_arrangement'   => 'individual',
            'current_status'           => 'mahasiswa',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'internship_status'        => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $this->regB = InternshipRegistration::create([
            'user_id'                  => $this->pemagangUserB->id,
            'fullname'                 => 'Bob Pemagang',
            'email'                    => $this->pemagangUserB->email,
            'phone'                    => '081234567891',
            'student_id'               => 'NIM' . rand(1000, 9999),
            'institution_id'           => $institution->id,
            'institution_name'         => $institution->name,
            'faculty_id'               => $faculty->id,
            'faculty'                  => $faculty->name,
            'study_program_id'         => $program->id,
            'study_program'            => $program->name,
            'current_city_id'          => $city->id,
            'current_city'             => $city->name,
            'division_id'              => $division->id,
            'internship_type'          => 'online',
            'internship_arrangement'   => 'individual',
            'current_status'           => 'mahasiswa',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'internship_status'        => InternshipRegistration::STATUS_ACCEPTED,
        ]);
    }

    /**
     * Test 1: UploadRegistrationAttachmentToDriveJob mengunggah berkas via Apps Script Gateway.
     */
    public function test_upload_registration_attachment_job_uses_apps_script_drive(): void
    {
        $fileName = 'cv_alice.pdf';
        $filePath = "attachments/{$this->regA->id}/{$fileName}";
        $content = 'Konten Dokumen CV Alice untuk Uji Integrasi GAS-04';
        Storage::disk('local')->put($filePath, $content);
        $checksum = hash('sha256', $content);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $this->regA->id,
            'attachment_type'            => 'cv',
            'file_name'                  => $fileName,
            'original_name'              => $fileName,
            'file_path'                  => $filePath,
            'mime_type'                  => 'application/pdf',
            'file_size'                  => strlen($content),
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'DRIVE_FILE_ALICE_CV_123',
                'file_name'       => $fileName,
                'web_view_link'   => 'https://drive.google.com/file/d/DRIVE_FILE_ALICE_CV_123/view',
                'folder_id'       => 'FOLDER_CV_ALICE',
                'size_bytes'      => strlen($content),
                'checksum_sha256' => $checksum,
                'operation'       => 'created',
            ], 200),
        ]);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle(
            transport: new NullSpreadsheetTransport(),
            syncEngine: app(\App\Services\SpreadsheetSyncEngineService::class)
        );

        $attachment->refresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $attachment->drive_sync_status);
        $this->assertEquals('DRIVE_FILE_ALICE_CV_123', $attachment->drive_file_id);
        $this->assertNotNull($attachment->drive_synced_at);

        // Pastikan berkas fisik lokal tetap utuh
        $this->assertTrue(Storage::disk('local')->exists($filePath));
    }

    /**
     * Test 2: Kegagalan upload Apps Script mempertahankan file lokal dan mencatat status gagal tanpa klaim sukses.
     */
    public function test_upload_failure_preserves_local_file_and_marks_failed(): void
    {
        $fileName = 'ktm_alice.pdf';
        $filePath = "attachments/{$this->regA->id}/{$fileName}";
        $content = 'Konten KTM Alice';
        Storage::disk('local')->put($filePath, $content);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $this->regA->id,
            'attachment_type'            => 'identity',
            'file_name'                  => $fileName,
            'original_name'              => $fileName,
            'file_path'                  => $filePath,
            'mime_type'                  => 'application/pdf',
            'file_size'                  => strlen($content),
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'    => false,
                'error_code' => 'DRIVE_STORAGE_FULL',
                'message'    => 'Kapasitas penyimpanan Google Drive habis.',
            ], 500),
        ]);

        try {
            $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
            $job->handle(
                transport: new NullSpreadsheetTransport(),
                syncEngine: app(\App\Services\SpreadsheetSyncEngineService::class)
            );
        } catch (\Throwable $e) {
            // Expected exception rethrown by job handle
        }

        $attachment->refresh();
        $this->assertNotEquals(RegistrationAttachment::STATUS_SYNCED, $attachment->drive_sync_status);
        $this->assertTrue(in_array($attachment->drive_sync_status, [RegistrationAttachment::STATUS_FAILED, RegistrationAttachment::STATUS_RETRYING], true));
        $this->assertNull($attachment->drive_file_id);

        // Berkas lokal TIDAK hilang
        $this->assertTrue(Storage::disk('local')->exists($filePath));
    }

    /**
     * Test 3: Sinkronisasi MOU melalui CloudSyncManager menggunakan Apps Script Gateway.
     */
    public function test_cloud_sync_manager_syncs_mou_document(): void
    {
        $institution = Institution::create([
            'name'                 => 'Universitas Mitra ' . uniqid(),
            'address'              => 'Jl. Kerjasama No. 1',
            'phone'                => '08123456789',
            'email'                => 'mitra_' . uniqid() . '@test.com',
            'correspondence_unit'  => 'Kerjasama',
            'is_active'            => true,
        ]);

        $mouPath = 'mou_signed/mou_test_' . uniqid() . '.pdf';
        $mouContent = 'Konten Dokumen MOU PDF Ditandatangani';
        Storage::disk('public')->put($mouPath, $mouContent);
        $mouChecksum = hash('sha256', $mouContent);

        $mou = Mou::create([
            'institution_id'               => $institution->id,
            'company_mou_number'           => 'MOU-TEST-' . uniqid(),
            'first_party_institution_name' => $institution->name,
            'first_party_signatory_name'   => 'Rektor Mitra',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Direktur Utama',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'effective_start'              => now()->toDateString(),
            'effective_end'                => now()->addYear()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_file_path'             => $mouPath,
        ]);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'DRIVE_FILE_MOU_999',
                'file_name'       => basename($mouPath),
                'web_view_link'   => 'https://drive.google.com/file/d/DRIVE_FILE_MOU_999/view',
                'size_bytes'      => strlen($mouContent),
                'checksum_sha256' => $mouChecksum,
                'operation'       => 'created',
            ], 200),
        ]);

        $manager = app(CloudSyncManager::class);
        $result = $manager->syncMou($mou, $this->adminUser);

        $mou->refresh();
        $this->assertEquals('DRIVE_FILE_MOU_999', $mou->drive_file_id);
        $this->assertNotNull($mou->drive_synced_at);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_drive_synced',
            'actor_user_id' => $this->adminUser->id,
        ]);
    }

    /**
     * Test 4: Penerbitan dokumen (LOA, SKL, Sertifikat) melalui DocumentPublishingService.
     */
    public function test_document_publishing_service_integrates_with_provider(): void
    {
        $mockDriver = $this->createMock(CloudStorageProviderInterface::class);
        $mockDriver->method('getDriverName')->willReturn('google_apps_script_drive');
        $mockDriver->method('isConfigured')->willReturn(true);
        $mockDriver->method('setRootFolderId')->willReturnSelf();
        $mockDriver->method('uploadFile')->willReturn([
            'file_id'       => 'DRIVE_DOC_LOA_555',
            'web_view_link' => 'https://drive.google.com/file/d/DRIVE_DOC_LOA_555/view',
            'file_name'     => 'LOA_Alice.pdf',
            'operation'     => 'created',
            'driver'        => 'google_apps_script_drive',
        ]);

        $docService = app(DocumentPublishingService::class, ['cloudDriver' => $mockDriver]);

        $doc = $docService->publishInternDocument(
            intern: $this->regA,
            documentType: GeneratedDocument::TYPE_LOA,
            htmlContent: '<html><body><h1>Surat Penerimaan Magang</h1></body></html>',
            actor: $this->adminUser
        );

        $this->assertEquals(GeneratedDocument::STATUS_SYNCED, $doc->status);
        $this->assertEquals('DRIVE_DOC_LOA_555', $doc->drive_file_id);
        $this->assertNotNull($doc->synced_at);
    }

    /**
     * Test 5: Pengelolaan Aset Sistem (Logo Brand & Institusi) via SystemAssetService.
     */
    public function test_system_asset_service_syncs_to_cloud_provider(): void
    {
        $brand = Brand::create([
            'name'       => 'Brand Seven ' . uniqid(),
            'code'       => 'SVN' . rand(10, 99),
            'is_default' => false,
        ]);

        $mockDriver = $this->createMock(CloudStorageProviderInterface::class);
        $mockDriver->method('getDriverName')->willReturn('google_apps_script_drive');
        $mockDriver->method('isConfigured')->willReturn(true);
        $mockDriver->method('setRootFolderId')->willReturnSelf();
        $mockDriver->method('uploadFile')->willReturn([
            'file_id'       => 'DRIVE_ASSET_BRAND_777',
            'web_view_link' => 'https://drive.google.com/file/d/DRIVE_ASSET_BRAND_777/view',
            'file_name'     => 'logo.png',
            'operation'     => 'created',
            'driver'        => 'google_apps_script_drive',
        ]);

        $assetService = app(SystemAssetService::class, ['cloudDriver' => $mockDriver]);

        $file = UploadedFile::fake()->image('logo.png', 100, 100);
        $asset = $assetService->storeAsset(
            file: $file,
            attributes: [
                'name'     => 'Logo Brand Utama',
                'category' => SystemAsset::CATEGORY_LOGO,
                'scope'    => SystemAsset::SCOPE_BRAND,
                'brand_id' => $brand->id,
            ],
            actor: $this->adminUser
        );

        $assetService->syncAssetToDrive($asset, $this->adminUser);
        $asset->refresh();

        $this->assertEquals(SystemAsset::SYNC_STATUS_SYNCED, $asset->sync_status);
        $this->assertEquals('DRIVE_ASSET_BRAND_777', $asset->drive_file_id);
    }

    /**
     * Test 6: UniversalFileController mengamankan hak akses RBAC (Pemagang A tidak bisa akses berkas Pemagang B).
     */
    public function test_rbac_privilege_enforced_across_universal_file_controller(): void
    {
        $fileBob = CloudFileRegistry::create([
            'public_uuid'      => (string) \Illuminate\Support\Str::uuid(),
            'owner_type'       => 'registration',
            'owner_id'         => $this->regB->id,
            'category'         => 'cv',
            'logical_filename' => 'cv_bob.pdf',
            'provider'         => 'google_apps_script_drive',
            'drive_file_id'    => 'DRIVE_FILE_BOB_CONFIDENTIAL',
            'checksum_sha256'  => hash('sha256', 'Confidential Data of Bob'),
            'byte_size'        => 30,
            'mime_type'        => 'application/pdf',
            'sync_status'      => 'verified',
            'is_current'       => true,
        ]);

        // 1. Pemagang Alice mencoba melihat/mengunduh berkas milik Bob -> Harus 403 Forbidden
        $this->actingAs($this->pemagangUserA);

        $resPreview = $this->get(route('cloud-files.preview', $fileBob->public_uuid));
        $resPreview->assertStatus(403);

        $resDownload = $this->get(route('cloud-files.download', $fileBob->public_uuid));
        $resDownload->assertStatus(403);

        $resMeta = $this->get(route('cloud-files.metadata', $fileBob->public_uuid));
        $resMeta->assertStatus(403);

        // 2. HR Staff berwenang dapat melihat metadata berkas
        $this->actingAs($this->hrUser);

        $resHrMeta = $this->get(route('cloud-files.metadata', $fileBob->public_uuid));
        $resHrMeta->assertStatus(200);
        $this->assertEquals($fileBob->public_uuid, $resHrMeta->json('public_uuid'));
    }

    /**
     * Test 7: Audit kompatibilitas 472 berkas historis tanpa merusak atau mengubah metadata database.
     */
    public function test_historical_attachments_compatibility_audit_readonly(): void
    {
        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $this->regA->id,
            'attachment_type'            => 'cv',
            'file_name'                  => 'cv_historis.pdf',
            'original_name'              => 'cv_historis.pdf',
            'file_path'                  => 'attachments/legacy/cv_historis.pdf',
            'mime_type'                  => 'application/pdf',
            'file_size'                  => 1024,
            'drive_file_id'              => 'DRIVE_HISTORICAL_FILE_12345',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
        ]);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'  => true,
                'action'   => 'drive_get_metadata',
                'metadata' => [
                    'id'         => 'DRIVE_HISTORICAL_FILE_12345',
                    'name'       => 'cv_historis.pdf',
                    'size_bytes' => 1024,
                    'mime_type'  => 'application/pdf',
                    'trashed'    => false,
                ],
            ], 200),
        ]);

        $configService = app(SystemStorageConfigService::class);
        $audit = $configService->auditHistoricalAttachment($attachment);

        $this->assertTrue($audit['accessible']);
        $this->assertEquals('ACCESSIBLE', $audit['status']);
        $this->assertEquals('DRIVE_HISTORICAL_FILE_12345', $audit['file_id']);

        // Pastikan tidak ada mutasi pada database lampiran
        $attachment->refresh();
        $this->assertEquals('DRIVE_HISTORICAL_FILE_12345', $attachment->drive_file_id);
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $attachment->drive_sync_status);
    }

    /**
     * Test 8: Fallback pengunduhan dokumen pemagang mengambil bytes dari Google Drive saat lokal tidak ada.
     */
    public function test_pemagang_download_fallback_to_cloud_storage_when_local_pruned(): void
    {
        $remoteBytes = 'Remote PDF Binary Payload Streamed from Drive';
        $remoteChecksum = hash('sha256', $remoteBytes);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $this->regA->id,
            'attachment_type'            => 'cv',
            'file_name'                  => 'cv_pruned_locally.pdf',
            'original_name'              => 'cv_pruned_locally.pdf',
            'file_path'                  => 'attachments/pruned/cv_pruned_locally.pdf', // Tidak ada di disk lokal
            'mime_type'                  => 'application/pdf',
            'file_size'                  => strlen($remoteBytes),
            'drive_file_id'              => 'DRIVE_FILE_PRUNED_999',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
        ]);

        // Verifikasi file memang tidak ada di disk lokal
        $this->assertFalse(Storage::disk('local')->exists($attachment->file_path));

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'         => true,
                'action'          => 'drive_download_chunk',
                'file_id'         => 'DRIVE_FILE_PRUNED_999',
                'file_content'    => base64_encode($remoteBytes),
                'checksum_sha256' => $remoteChecksum,
            ], 200),
        ]);

        $this->actingAs($this->pemagangUserA);

        $response = $this->get(route('pemagang.registration.attachment.download', $attachment->id));
        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename="cv_pruned_locally.pdf"');
        $this->assertEquals($remoteBytes, $response->getContent());

        // Verifikasi berkas disimpan ke private cache lokal
        $this->assertTrue(Storage::disk('local')->exists($attachment->file_path));
    }
}
