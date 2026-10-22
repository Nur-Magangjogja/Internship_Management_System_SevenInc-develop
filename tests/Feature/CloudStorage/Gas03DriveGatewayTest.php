<?php

namespace Tests\Feature\CloudStorage;

use App\Models\CloudStorageSetting;
use App\Models\SpreadsheetConnection;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class Gas03DriveGatewayTest extends TestCase
{
    use DatabaseTransactions;

    protected string $endpointUrl = 'https://script.google.com/macros/s/AKfy_mock_drive_gateway/exec';
    protected string $sharedSecret = 'test_shared_secret_for_drive_gateway_12345';
    protected string $canonicalRootId = 'ROOT_CANONICAL_FOLDER_XYZ';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.google_apps_script.enabled', true);
        Config::set('services.google_apps_script.endpoint_url', $this->endpointUrl);
        Config::set('services.google_apps_script.shared_secret', $this->sharedSecret);
        Config::set('services.google_apps_script.timeout_seconds', 60);

        $setting = CloudStorageSetting::first();
        if ($setting) {
            $setting->update([
                'canonical_parent_folder_id'  => $this->canonicalRootId,
                'canonical_parent_folder_url' => "https://drive.google.com/drive/folders/{$this->canonicalRootId}",
                'is_enabled'                  => true,
            ]);
        } else {
            CloudStorageSetting::create([
                'name'                        => 'Penyimpanan Google Drive Sistem',
                'canonical_parent_folder_id'  => $this->canonicalRootId,
                'canonical_parent_folder_url' => "https://drive.google.com/drive/folders/{$this->canonicalRootId}",
                'is_enabled'                  => true,
                'is_verified'                 => true,
                'connection_status'           => 'connected',
            ]);
        }

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
     * Test 1: Driver terkonfigurasi dengan benar berdasarkan settings kanonikal dan env.
     */
    public function test_driver_is_configured_with_canonical_root(): void
    {
        $driver = new GoogleAppsScriptDriveDriver();

        $this->assertTrue($driver->isConfigured());
        $this->assertEquals('google_apps_script_drive', $driver->getDriverName());
        $this->assertEquals($this->canonicalRootId, $driver->getRootFolderId());
    }

    /**
     * Test 2: drive_resolve_folder membuat/mengembalikan ID folder di bawah root.
     */
    public function test_get_or_create_folder_path_dispatches_drive_resolve_folder(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'     => true,
                'action'      => 'drive_resolve_folder',
                'folder_id'   => 'RESOLVED_SUBFOLDER_999',
                'folder_name' => 'CV',
                'folder_path' => 'REGISTRASI/2026/REG-1/CV',
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $folderId = $driver->getOrCreateFolderPath('REGISTRASI/2026/REG-1/CV');

        $this->assertEquals('RESOLVED_SUBFOLDER_999', $folderId);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return ($body['payload']['action'] ?? '') === 'drive_resolve_folder'
                && ($body['payload']['drive_folder_id'] ?? '') === $this->canonicalRootId
                && ($body['payload']['folder_path'] ?? '') === 'REGISTRASI/2026/REG-1/CV';
        });
    }

    /**
     * Test 3: drive_upload mengunggah berkas secara privat dengan verifikasi SHA-256.
     */
    public function test_upload_file_verifies_checksum_and_returns_real_drive_file_id(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_cv_');
        $content = 'Sample CV Content for Testing Drive Gateway ' . uniqid();
        file_put_contents($tempPath, $content);
        $expectedChecksum = hash('sha256', $content);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'DRIVE_FILE_REAL_12345',
                'file_name'       => 'cv_pemagang.pdf',
                'web_view_link'   => 'https://drive.google.com/file/d/DRIVE_FILE_REAL_12345/view',
                'folder_id'       => 'FOLDER_CV_101',
                'size_bytes'      => strlen($content),
                'checksum_sha256' => $expectedChecksum,
                'operation'       => 'created',
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $result = $driver->uploadFile(
            localFullPath: $tempPath,
            remoteFolderPath: 'REGISTRASI/2026/REG-101/CV',
            customFileName: 'cv_pemagang.pdf'
        );

        $this->assertEquals('DRIVE_FILE_REAL_12345', $result['file_id']);
        $this->assertEquals('google_apps_script_drive', $result['driver']);
        $this->assertEquals('created', $result['operation']);
        $this->assertEquals($expectedChecksum, $result['checksum']);
        $this->assertEquals('https://drive.google.com/file/d/DRIVE_FILE_REAL_12345/view', $result['web_view_link']);

        unlink($tempPath);
    }

    /**
     * Test 4: drive_upload menolak file jika remote mengembalikan checksum mismatch.
     */
    public function test_upload_file_fails_if_remote_returns_mismatched_checksum(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_ktm_');
        file_put_contents($tempPath, 'KTM Content Data');

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'DRIVE_FILE_CORRUPT_777',
                'checksum_sha256' => 'tampered_sha256_hash_that_does_not_match',
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Integritas berkas terganggu');

        try {
            $driver->uploadFile($tempPath, 'REGISTRASI/2026/REG-1/IDENTITAS');
        } finally {
            unlink($tempPath);
        }
    }

    /**
     * Test 5: drive_upload menolak file melebihi 10 MB.
     */
    public function test_upload_file_fails_if_size_exceeds_10mb(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_oversize_');
        $fp = fopen($tempPath, 'w');
        // Tulis 10.5 MB
        ftruncate($fp, (int) (10.5 * 1024 * 1024));
        fclose($fp);

        $driver = new GoogleAppsScriptDriveDriver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi batas maksimum 10 MB');

        try {
            $driver->uploadFile($tempPath, 'REGISTRASI/2026/REG-1/PORTOFOLIO');
        } finally {
            unlink($tempPath);
        }
    }

    /**
     * Test 6: Berkas 5 MB dan 10 MB valid dapat ditransfer dengan checksum lengkap.
     */
    public function test_upload_5mb_and_10mb_files_supported(): void
    {
        // 1. Siapkan berkas 5 MB
        $path5mb = tempnam(sys_get_temp_dir(), 'test_5mb_');
        $content5mb = str_repeat('A', 5 * 1024 * 1024);
        file_put_contents($path5mb, $content5mb);
        $checksum5mb = hash('sha256', $content5mb);

        // 2. Siapkan berkas 10 MB (batas tepat)
        $path10mb = tempnam(sys_get_temp_dir(), 'test_10mb_');
        $content10mb = str_repeat('B', 10 * 1024 * 1024);
        file_put_contents($path10mb, $content10mb);
        $checksum10mb = hash('sha256', $content10mb);

        Http::fake([
            $this->endpointUrl . '*' => Http::sequence()
                ->push([
                    'success'         => true,
                    'action'          => 'drive_upload',
                    'file_id'         => 'DRIVE_FILE_5MB_VALID',
                    'file_name'       => 'portofolio_5mb.pdf',
                    'size_bytes'      => 5 * 1024 * 1024,
                    'checksum_sha256' => $checksum5mb,
                    'operation'       => 'created',
                ], 200)
                ->push([
                    'success'         => true,
                    'action'          => 'drive_upload',
                    'file_id'         => 'DRIVE_FILE_10MB_VALID',
                    'file_name'       => 'portofolio_10mb.pdf',
                    'size_bytes'      => 10 * 1024 * 1024,
                    'checksum_sha256' => $checksum10mb,
                    'operation'       => 'created',
                ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $res5mb = $driver->uploadFile($path5mb, 'REGISTRASI/2026/REG-1/PORTOFOLIO', 'portofolio_5mb.pdf');
        $this->assertEquals('DRIVE_FILE_5MB_VALID', $res5mb['file_id']);
        unlink($path5mb);

        $res10mb = $driver->uploadFile($path10mb, 'REGISTRASI/2026/REG-1/PORTOFOLIO', 'portofolio_10mb.pdf');
        $this->assertEquals('DRIVE_FILE_10MB_VALID', $res10mb['file_id']);
        unlink($path10mb);
    }

    /**
     * Test 7: drive_get_metadata mengembalikan metadata berkas dari Apps Script.
     */
    public function test_get_file_metadata_returns_remote_metadata(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'  => true,
                'action'   => 'drive_get_metadata',
                'metadata' => [
                    'id'          => 'DRIVE_FILE_EXISTING_456',
                    'name'        => 'mou_signed.pdf',
                    'size_bytes'  => 204800,
                    'mime_type'   => 'application/pdf',
                    'trashed'     => false,
                    'created_time'=> '2026-10-22T10:00:00Z',
                ],
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $meta = $driver->getFileMetadata('DRIVE_FILE_EXISTING_456');

        $this->assertEquals('DRIVE_FILE_EXISTING_456', $meta['id']);
        $this->assertEquals('mou_signed.pdf', $meta['name']);
        $this->assertEquals(204800, $meta['size_bytes']);
        $this->assertFalse($meta['trashed']);
    }

    /**
     * Test 8: drive_download_chunk mengunduh bytes dengan validasi checksum.
     */
    public function test_download_file_bytes_verifies_checksum(): void
    {
        $originalBytes = 'Simulated Document Binary Payload 12345';
        $checksum = hash('sha256', $originalBytes);

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'         => true,
                'action'          => 'drive_download_chunk',
                'file_id'         => 'DRIVE_FILE_DOWNLOAD_888',
                'file_name'       => 'document.pdf',
                'file_content'    => base64_encode($originalBytes),
                'checksum_sha256' => $checksum,
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $downloaded = $driver->downloadFileBytes('DRIVE_FILE_DOWNLOAD_888');

        $this->assertEquals($originalBytes, $downloaded);
    }

    /**
     * Test 9: drive_download_chunk menolak file jika checksum hash tidak sesuai.
     */
    public function test_download_file_bytes_fails_if_checksum_mismatch(): void
    {
        $originalBytes = 'Altered Document Binary Payload';

        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'         => true,
                'action'          => 'drive_download_chunk',
                'file_id'         => 'DRIVE_FILE_DOWNLOAD_TAMPERED',
                'file_content'    => base64_encode($originalBytes),
                'checksum_sha256' => 'expected_hash_that_will_not_match',
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Integritas berkas terganggu');

        $driver->downloadFileBytes('DRIVE_FILE_DOWNLOAD_TAMPERED');
    }

    /**
     * Test 10: drive_trash menandai berkas ke tempat sampah secara jujur.
     */
    public function test_delete_file_calls_drive_trash(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => Http::sequence()
                ->push([
                    'success' => true,
                    'action'  => 'drive_trash',
                    'file_id' => 'DRIVE_FILE_TRASH_1',
                    'trashed' => true,
                ], 200)
                ->push([
                    'success' => false,
                    'error_code' => 'ACCESS_DENIED_OUTSIDE_ROOT',
                    'message' => 'Berkas berada di luar root kanonikal.',
                ], 403),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $this->assertTrue($driver->deleteFile('DRIVE_FILE_TRASH_1'));
        $this->assertFalse($driver->deleteFile('DRIVE_FILE_TRASH_ILLEGAL'));
    }

    /**
     * Test 11: listChildren dan searchWithinCanonicalRoot meneruskan parameter dengan benar.
     */
    public function test_list_children_and_search_within_root(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => Http::sequence()
                ->push([
                    'success' => true,
                    'files'   => [
                        ['id' => 'F1', 'name' => 'child1.pdf', 'isFolder' => false],
                        ['id' => 'D1', 'name' => 'SUBDIR', 'isFolder' => true],
                    ],
                ], 200)
                ->push([
                    'success' => true,
                    'files'   => [
                        ['id' => 'F2', 'name' => 'matched.pdf'],
                    ],
                ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $list = $driver->listChildren('SOME_FOLDER_ID');
        $this->assertCount(2, $list['files']);

        $search = $driver->searchWithinCanonicalRoot($this->canonicalRootId, 'name contains "matched"');
        $this->assertCount(1, $search['files']);
    }
}
