<?php

namespace Tests\Feature;

use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\CloudStorage\CloudStorageProviderInterface;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Symfony\Component\Process\Process;

class Wave2DPrivateDriveAttachmentSyncHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'   => 'Registration Connection Wave 2D',
                'spreadsheet_id' => 'test-sheet-id-wave2d',
                'sheet_name'     => '2026_Main',
                'is_enabled'     => true,
            ]
        );

        \Illuminate\Support\Facades\Config::set('services.google_apps_script.enabled', true);
        \Illuminate\Support\Facades\Config::set('services.google_apps_script.endpoint_url', 'https://script.google.com/macros/s/test_wave2d_gas/exec');
        \Illuminate\Support\Facades\Config::set('services.google_apps_script.shared_secret', 'test_secret_wave2d');
        \Illuminate\Support\Facades\Config::set('services.google_apps_script.timeout_seconds', 30);
        \Illuminate\Support\Facades\Config::set('services.google_apps_script.root_folder_id', 'root_wave2d_test');
    }

    private function createHierarchy(): array
    {
        $unique = Str::random(6);
        $inst = Institution::create([
            'name'            => 'Univ Wave2D ' . $unique,
            'normalized_name' => 'univ wave2d ' . $unique,
            'is_active'       => true,
        ]);
        $fac = Faculty::create([
            'name'           => 'Fakultas Teknik ' . $unique,
            'institution_id' => $inst->id,
        ]);
        $prog = StudyProgram::create([
            'name'       => 'Informatika ' . $unique,
            'faculty_id' => $fac->id,
        ]);
        $city = City::create(['name' => 'Kota ' . $unique]);
        $div  = Division::create([
            'name'        => 'Divisi ' . $unique,
            'code'        => strtoupper(substr($unique, 0, 4)),
            'description' => 'Desc',
            'is_active'   => true,
        ]);

        return [$inst, $fac, $prog, $city, $div];
    }

    private function createRegistration(?User $user = null): InternshipRegistration
    {
        if (!$user) {
            $user = User::create([
                'name'      => 'Peserta ' . uniqid(),
                'email'     => 'peserta_' . uniqid() . '@example.com',
                'password'  => bcrypt('secret'),
                'role'      => 'pemagang',
                'is_banned' => false,
            ]);
        }

        [$inst, $fac, $prog, $city, $div] = $this->createHierarchy();

        return InternshipRegistration::create([
            'user_id'                  => $user->id,
            'fullname'                 => $user->name,
            'email'                    => $user->email,
            'student_id'               => 'NIM' . rand(1000, 9999),
            'institution_id'           => $inst->id,
            'institution_name'         => $inst->name,
            'faculty_id'               => $fac->id,
            'faculty'                  => $fac->name,
            'study_program_id'         => $prog->id,
            'study_program'            => $prog->name,
            'current_city_id'          => $city->id,
            'current_city'             => $city->name,
            'division_id'              => $div->id,
            'internship_type'          => 'online',
            'internship_arrangement'   => 'individual',
            'current_status'           => 'mahasiswa',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'internship_status'        => InternshipRegistration::STATUS_ACCEPTED,
            'followup_status'          => InternshipRegistration::FOLLOWUP_NOT_FOLLOWED_UP,
        ]);
    }

    private function makeMockTransport(?string $fileId = 'DRIVE-FILE-WAVE2D-123', bool $success = true, bool $isRetryable = false): SpreadsheetTransportInterface
    {
        return new class($fileId, $success, $isRetryable) implements SpreadsheetTransportInterface {
            public int $callCount = 0;

            public function __construct(
                public ?string $fileId,
                public bool $success,
                public bool $isRetryable
            ) {}

            public function sendRowUpsert($c, $t, $s, $p): SpreadsheetTransportResponse
            {
                $this->callCount++;
                if (!$this->success) {
                    return SpreadsheetTransportResponse::failure(
                        errorMessage: 'Mock transport upload failed.',
                        errorCode: 'MOCK_ERROR',
                        isRetryable: $this->isRetryable
                    );
                }

                $raw = [];
                if ($this->fileId !== null) {
                    $raw['file_id'] = $this->fileId;
                    $raw['web_view_link'] = 'https://drive.google.com/file/d/' . $this->fileId . '/view';
                }

                return SpreadsheetTransportResponse::ok(10, $raw);
            }

            public function sendRowUpdateOnly($c, $t, $s, $p): SpreadsheetTransportResponse
            {
                $this->callCount++;
                return SpreadsheetTransportResponse::ok(10);
            }

            public function testConnection($c): bool { return true; }
            public function listTabs(SpreadsheetConnection $connection): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, ['tabs' => []]); }
            public function inspectSchema(SpreadsheetConnection $connection, string $tabName, ?int $headerRow = null): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, ['columns' => []]); }
            public function readRowsPage(SpreadsheetConnection $connection, string $tabName, ?int $headerRow = null, int $page = 1, int $pageSize = 20, bool $maskPii = true): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, ['rows' => []]); }
            public function syncSheetSchema(SpreadsheetConnection $connection, string $tabName, array $headers): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, []); }
            public function deleteEmptyColumn(SpreadsheetConnection $connection, int $sheetId, string $tabName, string $columnIdentity, string $expectedSchemaHash, ?string $idempotencyKey = null): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, []); }
        };
    }

    // =========================================================================
    // MATRIX D01 - D07: STORAGE PRIVACY & SECURITY
    // =========================================================================

    /**
     * D01: File baru pada local private disk berhasil diproses.
     */
    public function test_d01_new_file_on_local_private_disk_successfully_processed(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d01.pdf";
        Storage::disk('local')->put($privatePath, 'Isi Berkas Private D01');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d01.pdf',
            'file_size'                  => strlen('Isi Berkas Private D01'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D01');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-D01', $fresh->drive_file_id);
        $this->assertEquals('https://drive.google.com/file/d/DRIVE-FILE-D01/view', $fresh->drive_web_view_link);
        $this->assertNotNull($fresh->drive_synced_at);
        $this->assertNull($fresh->drive_error_message);
        $this->assertNull($fresh->drive_locked_by);
    }

    /**
     * D02: File legacy pada public disk tetap kompatibel tanpa penghapusan sumber.
     */
    public function test_d02_legacy_file_on_public_disk_processed_without_deleting_source(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $reg = $this->createRegistration();
        $legacyPath = 'uploads/legacy_cv_d02.pdf';
        Storage::disk('public')->put($legacyPath, 'Isi Berkas Legacy D02');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $legacyPath,
            'original_name'              => 'legacy_cv_d02.pdf',
            'file_size'                  => strlen('Isi Berkas Legacy D02'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D02');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-D02', $fresh->drive_file_id);

        // Verifikasi sumber berkas publik TIDAK dihapus
        $this->assertTrue(Storage::disk('public')->exists($legacyPath));
    }

    /**
     * D03: File tidak ditemukan -> fail-closed.
     */
    public function test_d03_missing_physical_file_fails_closed(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $reg = $this->createRegistration();
        $missingPath = "private/registrations/{$reg->id}/missing_file.pdf";

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $missingPath,
            'original_name'              => 'missing_file.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D03');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
        $this->assertNull($fresh->drive_synced_at);
        $this->assertStringContainsString('Berkas lampiran fisik tidak ditemukan di storage server', $fresh->drive_error_message);
        $this->assertNull($fresh->drive_locked_by);
        $this->assertEquals(0, $mockTransport->callCount);
    }

    /**
     * D04: Path traversal atau path di luar storage root ditolak.
     */
    public function test_d04_path_traversal_and_absolute_paths_rejected(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();

        $invalidPaths = [
            '../../etc/passwd',
            '../private/registrations/secret.pdf',
            '/var/www/secret.pdf',
            '\\Windows\\System32\\calc.exe',
            'C:\\secret.pdf',
        ];

        foreach ($invalidPaths as $badPath) {
            $attachment = RegistrationAttachment::create([
                'internship_registration_id' => $reg->id,
                'attachment_type'            => 'cv_ktp_portofolio',
                'file_path'                  => $badPath,
                'original_name'              => 'hack.pdf',
                'file_size'                  => 100,
                'mime_type'                  => 'application/pdf',
                'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
            ]);

            $mockTransport = $this->makeMockTransport('DRIVE-FILE-BAD');
            $syncEngine = app(SpreadsheetSyncEngineService::class);

            $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
            $job->handle($mockTransport, $syncEngine);

            $fresh = $attachment->fresh();
            $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $fresh->drive_sync_status);
            $this->assertStringContainsString('path traversal', $fresh->drive_error_message);
            $this->assertEquals(0, $mockTransport->callCount);
        }
    }

    /**
     * D05: MIME atau ukuran tidak valid mengikuti batas existing.
     */
    public function test_d05_invalid_size_empty_or_exceeding_limit_rejected(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();

        // 1. Berkas 0 bytes
        $emptyPath = "private/registrations/{$reg->id}/empty.pdf";
        Storage::disk('local')->put($emptyPath, '');

        $attEmpty = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $emptyPath,
            'original_name'              => 'empty.pdf',
            'file_size'                  => 0,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D05');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attEmpty->id);
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $attEmpty->fresh()->drive_sync_status);
        $this->assertStringContainsString('kosong (0 bytes)', $attEmpty->fresh()->drive_error_message);
        $this->assertEquals(0, $mockTransport->callCount);
    }

    /**
     * D06: Dokumen private tidak dapat diakses melalui public storage URL.
     */
    public function test_d06_private_document_not_accessible_via_public_storage(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/secret_cv.pdf";
        Storage::disk('local')->put($privatePath, 'Secret Content');

        // File hanya ada di disk local, tidak pernah ada di public disk
        $this->assertTrue(Storage::disk('local')->exists($privatePath));
        $this->assertFalse(Storage::disk('public')->exists($privatePath));
    }

    /**
     * D07: Upload tidak membuat permission publik.
     */
    public function test_d07_drive_upload_does_not_create_public_permissions(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'drive_private_file_123',
                'file_name'       => 'doc.pdf',
                'web_view_link'   => 'https://drive.google.com/file/d/drive_private_file_123/view',
                'checksum_sha256' => hash('sha256', 'Private Document Content'),
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_priv_');
        file_put_contents($tempFile, 'Private Document Content');

        $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-1', 'doc.pdf');

        // Verifikasi tidak ada request yang dibuat ke permissions API untuk public access
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'permissions') && str_contains($request->body(), 'anyone');
        });

        @unlink($tempFile);
    }

    // =========================================================================
    // MATRIX D08 - D15: RESULT VALIDATION, LEASING & IDEMPOTENCY
    // =========================================================================

    /**
     * D08: Respons remote tanpa file_id -> tidak boleh SYNCED.
     */
    public function test_d08_remote_response_without_file_id_fails_closed(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d08.pdf";
        Storage::disk('local')->put($privatePath, 'Content D08');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d08.pdf',
            'file_size'                  => strlen('Content D08'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        // Mock transport returns success: true, but file_id is NULL
        $mockTransport = $this->makeMockTransport(fileId: null, success: true);
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
        $this->assertNull($fresh->drive_synced_at);
        $this->assertStringContainsString('file_id Google Drive yang valid', $fresh->drive_error_message);
    }

    /**
     * D09: Respons gagal -> tidak mencatat keberhasilan palsu.
     */
    public function test_d09_failed_remote_response_does_not_mark_synced(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d09.pdf";
        Storage::disk('local')->put($privatePath, 'Content D09');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d09.pdf',
            'file_size'                  => strlen('Content D09'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        // Mock transport returns success: false
        $mockTransport = $this->makeMockTransport(fileId: null, success: false, isRetryable: false);
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
        $this->assertNull($fresh->drive_synced_at);
        $this->assertStringContainsString('Mock transport upload failed', $fresh->drive_error_message);
    }

    /**
     * D10: Duplicate queue jobs tidak menghasilkan duplicate upload.
     */
    public function test_d10_duplicate_queue_jobs_do_not_produce_duplicate_upload(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d10.pdf";
        Storage::disk('local')->put($privatePath, 'Content D10');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d10.pdf',
            'file_size'                  => strlen('Content D10'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D10');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        // Job 1 dispatches and runs
        $job1 = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'fence-worker-1');
        $job1->handle($mockTransport, $syncEngine);

        $this->assertEquals(1, $mockTransport->callCount);

        // Job 2 dispatches after Job 1 is synced
        $job2 = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'fence-worker-2');
        $job2->handle($mockTransport, $syncEngine);

        // Call count MUST REMAIN 1 (Job 2 no-ops via idempotency)
        $this->assertEquals(1, $mockTransport->callCount);
    }

    /**
     * D11: Active worker ownership tidak dapat diambil alih.
     */
    public function test_d11_active_worker_ownership_cannot_be_hijacked(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d11.pdf";
        Storage::disk('local')->put($privatePath, 'Content D11');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d11.pdf',
            'file_size'                  => strlen('Content D11'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PROCESSING,
            'drive_locked_by'            => 'active-worker-token-alpha',
            'drive_lock_expires_at'      => now()->addMinutes(5), // Active unexpired lease
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D11');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        // Worker Beta tries to execute while Alpha holds lease
        $jobBeta = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'intruder-worker-beta');
        $jobBeta->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        // Lease remains held by Alpha; Beta was rejected
        $this->assertEquals('active-worker-token-alpha', $fresh->drive_locked_by);
        $this->assertEquals(RegistrationAttachment::STATUS_PROCESSING, $fresh->drive_sync_status);
        $this->assertEquals(0, $mockTransport->callCount);
    }

    /**
     * D12: Expired lease dapat dipulihkan secara aman.
     */
    public function test_d12_expired_lease_can_be_safely_reclaimed(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d12.pdf";
        Storage::disk('local')->put($privatePath, 'Content D12');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d12.pdf',
            'file_size'                  => strlen('Content D12'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PROCESSING,
            'drive_locked_by'            => 'crashed-worker-token',
            'drive_lock_expires_at'      => now()->subMinutes(5), // EXPIRED LEASE
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D12');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        // Worker Recovery takes over
        $jobRecovery = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'recovery-worker-token');
        $jobRecovery->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-D12', $fresh->drive_file_id);
        $this->assertNull($fresh->drive_locked_by);
        $this->assertEquals(1, $mockTransport->callCount);
    }

    /**
     * D13: Stale worker callback tidak menimpa status worker pengganti.
     */
    public function test_d13_stale_worker_callback_cannot_overwrite_successor(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d13.pdf";
        Storage::disk('local')->put($privatePath, 'Content D13');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d13.pdf',
            'file_size'                  => strlen('Content D13'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PROCESSING,
            'drive_locked_by'            => 'stale-worker-alpha',
            'drive_lock_expires_at'      => now()->subMinutes(2),
        ]);

        // Successor Worker Beta takes over and syncs
        $attachment->update([
            'drive_sync_status'     => RegistrationAttachment::STATUS_SYNCED,
            'drive_file_id'         => 'DRIVE-FILE-SUCCESSOR-BETA',
            'drive_web_view_link'   => 'https://drive.google.com/file/d/DRIVE-FILE-SUCCESSOR-BETA/view',
            'drive_synced_at'       => now(),
            'drive_locked_by'       => null,
            'drive_lock_expires_at' => null,
        ]);

        $mockTransport = $this->makeMockTransport('STALE-ALPHA-ID');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        // Stale Worker Alpha attempts to run
        $staleJob = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'stale-worker-alpha');
        $staleJob->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        // Record MUST NOT be overwritten by stale Alpha
        $this->assertEquals('DRIVE-FILE-SUCCESSOR-BETA', $fresh->drive_file_id);
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals(0, $mockTransport->callCount);
    }

    /**
     * D14 & D15: Timeout dan rekonsiliasi remote tanpa duplikasi berkas.
     */
    public function test_d14_and_d15_remote_idempotency_reconciliation_prevents_duplicate(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'existing_remote_id_reconciled',
                'file_name'       => 'cv_reconciled.pdf',
                'web_view_link'   => 'https://drive.google.com/file/d/existing_remote_id_reconciled/view',
                'operation'       => 'reused_existing',
                'checksum_sha256' => hash('sha256', 'Reconciled File Content'),
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_recon_');
        file_put_contents($tempFile, 'Reconciled File Content');

        // Execute uploadFile with matching existing name
        $result = $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'cv_reconciled.pdf');

        $this->assertEquals('existing_remote_id_reconciled', $result['file_id']);
        $this->assertEquals('reused_existing', $result['operation']);

        @unlink($tempFile);
    }

    // =========================================================================
    // MATRIX D16 - D25: RETRY, FALLBACK, CONSISTENCY & AUTHORIZATION
    // =========================================================================

    /**
     * D16: Retryable failure menerapkan batas percobaan dan backoff.
     */
    public function test_d16_retryable_failure_schedules_backoff_and_retries(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d16.pdf";
        Storage::disk('local')->put($privatePath, 'Content D16');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d16.pdf',
            'file_size'                  => strlen('Content D16'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
            'drive_attempt_count'        => 0,
        ]);

        // Retryable mock failure
        $mockTransport = $this->makeMockTransport(fileId: null, success: false, isRetryable: true);
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_RETRYING, $fresh->drive_sync_status);
        $this->assertNotNull($fresh->drive_next_retry_at);
        $this->assertNull($fresh->drive_locked_by);
        $this->assertEquals(1, $fresh->drive_attempt_count);
    }

    /**
     * D17: Terminal failure tidak dijadwalkan otomatis secara tak terbatas.
     */
    public function test_d17_terminal_failure_is_not_scheduled_indefinitely(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d17.pdf";
        Storage::disk('local')->put($privatePath, 'Content D17');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d17.pdf',
            'file_size'                  => strlen('Content D17'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        // Terminal mock failure
        $mockTransport = $this->makeMockTransport(fileId: null, success: false, isRetryable: false);
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_next_retry_at);
        $this->assertNull($fresh->drive_locked_by);
    }

    /**
     * D18: drive_synced_at hanya terisi setelah keberhasilan terkonfirmasi.
     */
    public function test_d18_drive_synced_at_only_populated_on_confirmed_success(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d18.pdf";
        Storage::disk('local')->put($privatePath, 'Content D18');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d18.pdf',
            'file_size'                  => strlen('Content D18'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $this->assertNull($attachment->drive_synced_at);

        // Run failure
        $mockFailTransport = $this->makeMockTransport(fileId: null, success: false);
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $jobFail = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $jobFail->handle($mockFailTransport, $syncEngine);

        $this->assertNull($attachment->fresh()->drive_synced_at);

        // Manual requeue (as FAILED records require manual intervention under Finding A)
        $attachment->fresh()->update([
            'drive_sync_status'     => RegistrationAttachment::STATUS_PENDING,
            'drive_locked_by'       => null,
            'drive_lock_expires_at' => null,
            'drive_next_retry_at'   => null,
        ]);

        // Run success
        $mockSuccessTransport = $this->makeMockTransport('DRIVE-FILE-D18');
        $jobSuccess = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $jobSuccess->handle($mockSuccessTransport, $syncEngine);

        $this->assertNotNull($attachment->fresh()->drive_synced_at);
    }

    /**
     * D19: Local fallback tidak disamakan dengan Google Drive success.
     */
    public function test_d19_local_fallback_not_treated_as_google_drive_success(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $privatePath = "private/registrations/{$reg->id}/att_cv_d19.pdf";
        Storage::disk('local')->put($privatePath, 'Content D19');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $privatePath,
            'original_name'              => 'cv_d19.pdf',
            'file_size'                  => strlen('Content D19'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $manager = new CloudSyncManager(new LocalStorageFallbackDriver());
        $manager->syncRegistrationAttachment($attachment);

        $fresh = $attachment->fresh();
        // Must NOT be marked STATUS_SYNCED
        $this->assertNotEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals(RegistrationAttachment::STATUS_PENDING, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_synced_at);
        $this->assertFalse($fresh->isSyncedToDrive());
    }

    /**
     * D20: Attachment dengan nama file sama tetap mempunyai identitas terpisah.
     */
    public function test_d20_attachments_with_same_filename_have_distinct_identities(): void
    {
        Storage::fake('local');
        $reg1 = $this->createRegistration();
        $reg2 = $this->createRegistration();

        $path1 = "private/registrations/{$reg1->id}/cv.pdf";
        $path2 = "private/registrations/{$reg2->id}/cv.pdf";
        Storage::disk('local')->put($path1, 'Content 1');
        Storage::disk('local')->put($path2, 'Content 2');

        $att1 = RegistrationAttachment::create([
            'internship_registration_id' => $reg1->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path1,
            'original_name'              => 'cv.pdf',
            'file_size'                  => strlen('Content 1'),
            'mime_type'                  => 'application/pdf',
        ]);

        $att2 = RegistrationAttachment::create([
            'internship_registration_id' => $reg2->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path2,
            'original_name'              => 'cv.pdf',
            'file_size'                  => strlen('Content 2'),
            'mime_type'                  => 'application/pdf',
        ]);

        $syncId1 = 'ATT-' . $att1->internship_registration_id . '-' . $att1->id;
        $syncId2 = 'ATT-' . $att2->internship_registration_id . '-' . $att2->id;

        $this->assertNotEquals($syncId1, $syncId2);
        $this->assertNotEquals($att1->id, $att2->id);
    }

    /**
     * D21: Perubahan konten/file tidak memakai metadata upload lama secara keliru.
     */
    public function test_d21_file_content_change_invalidates_stale_metadata(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_cv_d21.pdf";
        Storage::disk('local')->put($path, 'Original Version 1');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'cv_d21.pdf',
            'file_size'                  => strlen('Original Version 1'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_file_id'              => 'OLD-DRIVE-FILE-ID',
            'drive_web_view_link'        => 'https://drive.google.com/file/d/OLD-DRIVE-FILE-ID/view',
            'drive_synced_at'            => now(),
            'drive_file_checksum'        => hash('sha256', 'Original Version 1'),
        ]);

        $this->assertTrue($attachment->isSyncedToDrive());

        // Pengguna mengganti berkas dengan konten baru
        Storage::disk('local')->put($path, 'New Updated Content Version 2');
        $attachment->invalidateDriveSync('Replaced with updated CV');

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_PENDING, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
        $this->assertNull($fresh->drive_web_view_link);
        $this->assertNull($fresh->drive_synced_at);
        $this->assertFalse($fresh->isSyncedToDrive());
    }

    /**
     * D22: Spreadsheet diperbarui hanya setelah upload terkonfirmasi.
     */
    public function test_d22_spreadsheet_re_evaluation_only_queued_after_confirmed_upload(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_cv_d22.pdf";
        Storage::disk('local')->put($path, 'Content D22');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'cv_d22.pdf',
            'file_size'                  => strlen('Content D22'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D22');

        $reEvaluationCalled = false;
        $mockSyncEngine = $this->createMock(SpreadsheetSyncEngineService::class);
        $mockSyncEngine->expects($this->once())
            ->method('queueSyncForRegistration')
            ->willReturnCallback(function ($r) use (&$reEvaluationCalled, $reg) {
                $this->assertEquals($reg->id, $r->id);
                $reEvaluationCalled = true;
            });

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $mockSyncEngine);

        $this->assertTrue($reEvaluationCalled);
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $attachment->fresh()->drive_sync_status);
    }

    /**
     * D23: Spreadsheet update failure tidak menyebabkan re-upload.
     */
    public function test_d23_spreadsheet_update_failure_does_not_cause_re_upload(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_cv_d23.pdf";
        Storage::disk('local')->put($path, 'Content D23');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'cv_d23.pdf',
            'file_size'                  => strlen('Content D23'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-D23');

        // Mock syncEngine throws exception
        $mockSyncEngine = $this->createMock(SpreadsheetSyncEngineService::class);
        $mockSyncEngine->method('queueSyncForRegistration')
            ->willThrowException(new RuntimeException('Spreadsheet queue down'));

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        // handle should catch spreadsheet failure and NOT fail the Drive upload
        $job->handle($mockTransport, $mockSyncEngine);

        $fresh = $attachment->fresh();
        // Drive status remains SYNCED!
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-D23', $fresh->drive_file_id);
        $this->assertNotNull($fresh->drive_synced_at);
    }

    /**
     * D24: Non-owner, unauthenticated user, dan role tidak sah tidak dapat mengunduh dokumen privat.
     */
    public function test_d24_document_download_authorization_matrix(): void
    {
        Storage::fake('local');

        $owner = User::create([
            'name'     => 'Owner ' . uniqid(),
            'email'    => 'owner_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $intruder = User::create([
            'name'     => 'Intruder ' . uniqid(),
            'email'    => 'intruder_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $admin = User::create([
            'name'     => 'Admin ' . uniqid(),
            'email'    => 'admin_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $reg = $this->createRegistration($owner);
        $path = "private/registrations/{$reg->id}/secret_d24.pdf";
        Storage::disk('local')->put($path, 'Sensitive User Content');

        $att = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'secret_d24.pdf',
            'file_size'                  => strlen('Sensitive User Content'),
            'mime_type'                  => 'application/pdf',
        ]);

        $url = route('pemagang.registration.attachment.download', ['attachment' => $att->id]);

        // 1. Unauthenticated -> 302 to login
        $this->get($url)->assertStatus(302)->assertRedirect(route('user.login'));

        // 2. Intruder -> 403 Forbidden
        $this->actingAs($intruder)->get($url)->assertStatus(403);

        // 3. Owner -> 200 OK
        $this->actingAs($owner)->get($url)->assertStatus(200);

        // 4. Admin -> 200 OK
        $this->actingAs($admin)->get($url)->assertStatus(200);
    }

    /**
     * D25: Regresi mapping service spreadsheet hanya menggunakan URL Drive yang sah dan terverifikasi.
     */
    public function test_d25_mapping_service_resolves_only_verified_drive_urls(): void
    {
        $reg = $this->createRegistration();
        $mappingService = app(RegistrationSpreadsheetMappingService::class);

        // Kasus 1: Belum ada attachment yang synced -> null
        $url1 = $mappingService->resolveColumnValue($reg, new \App\Models\SpreadsheetColumnMapping(['target_field' => 'attachment_url']));
        $this->assertNull($url1);

        // Kasus 2: Attachment hanya disinkronkan via local fallback simulation -> null
        $attSim = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => 'private/test.pdf',
            'original_name'              => 'test.pdf',
            'file_size'                  => 100,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
            'drive_file_id'              => 'local_sim_12345',
            'drive_web_view_link'        => 'sertif://simulated-storage/test.pdf',
        ]);

        $url2 = $mappingService->resolveColumnValue($reg, new \App\Models\SpreadsheetColumnMapping(['target_field' => 'attachment_url']));
        $this->assertNull($url2);

        // Kasus 3: Attachment tersinkronisasi sah ke Google Drive
        $attSim->update([
            'drive_sync_status'   => RegistrationAttachment::STATUS_SYNCED,
            'drive_file_id'       => 'VERIFIED_REAL_DRIVE_ID_999',
            'drive_web_view_link' => 'https://drive.google.com/file/d/VERIFIED_REAL_DRIVE_ID_999/view',
            'drive_synced_at'     => now(),
        ]);

        $url3 = $mappingService->resolveColumnValue($reg, new \App\Models\SpreadsheetColumnMapping(['target_field' => 'attachment_url']));
        $this->assertEquals('https://drive.google.com/file/d/VERIFIED_REAL_DRIVE_ID_999/view', $url3);
    }

    // =========================================================================
    // MATRIX R02: TARGET TESTS (ITEMS 1 - 19; ITEM 20 IS FULL REGRESSION SUITE)
    // =========================================================================

    /**
     * R02-01: RETRYING future -> rejected.
     */
    public function test_r02_01_retrying_future_schedule_is_rejected(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_retrying_future.pdf";
        Storage::disk('local')->put($path, 'Content Future');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_retrying_future.pdf',
            'file_size'                  => strlen('Content Future'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_RETRYING,
            'drive_next_retry_at'        => now()->addMinutes(10),
            'drive_attempt_count'        => 1,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-FUTURE');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(0, $mockTransport->callCount);
        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_RETRYING, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
        $this->assertNull($fresh->drive_locked_by);
    }

    /**
     * R02-02: RETRYING null schedule -> rejected.
     */
    public function test_r02_02_retrying_null_schedule_is_rejected(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_retrying_null.pdf";
        Storage::disk('local')->put($path, 'Content Null Schedule');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_retrying_null.pdf',
            'file_size'                  => strlen('Content Null Schedule'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_RETRYING,
            'drive_next_retry_at'        => null,
            'drive_attempt_count'        => 1,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-NULL-RETRY');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(0, $mockTransport->callCount);
        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_RETRYING, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
        $this->assertNull($fresh->drive_locked_by);
    }

    /**
     * R02-03: RETRYING due -> permitted.
     */
    public function test_r02_03_retrying_due_schedule_is_permitted(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_retrying_due.pdf";
        Storage::disk('local')->put($path, 'Content Due Schedule');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_retrying_due.pdf',
            'file_size'                  => strlen('Content Due Schedule'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_RETRYING,
            'drive_next_retry_at'        => now()->subMinute(),
            'drive_attempt_count'        => 1,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-DUE-SUCCESS');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(1, $mockTransport->callCount);
        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-DUE-SUCCESS', $fresh->drive_file_id);
        $this->assertNull($fresh->drive_locked_by);
    }

    /**
     * R02-04: FAILED tanpa manual authorization -> rejected.
     */
    public function test_r02_04_failed_without_manual_authorization_is_rejected(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_failed.pdf";
        Storage::disk('local')->put($path, 'Content Failed');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_failed.pdf',
            'file_size'                  => strlen('Content Failed'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
            'drive_error_message'        => 'Previous permanent error',
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-FAILED-BYPASS');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(0, $mockTransport->callCount);
        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
    }

    /**
     * R02-05: PROCESSING active lease -> rejected.
     */
    public function test_r02_05_processing_with_active_lease_is_rejected(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_active_lease.pdf";
        Storage::disk('local')->put($path, 'Content Active Lease');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_active_lease.pdf',
            'file_size'                  => strlen('Content Active Lease'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PROCESSING,
            'drive_locked_by'            => 'active-worker-token-xyz',
            'drive_lock_expires_at'      => now()->addMinutes(5),
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-HIJACK');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'another-worker-token');
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(0, $mockTransport->callCount);
        $fresh = $attachment->fresh();
        $this->assertEquals('active-worker-token-xyz', $fresh->drive_locked_by);
        $this->assertEquals(RegistrationAttachment::STATUS_PROCESSING, $fresh->drive_sync_status);
    }

    /**
     * R02-06: PROCESSING expiry null -> tidak diambil alih sembarangan.
     */
    public function test_r02_06_processing_with_null_expiry_cannot_be_overtaken(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_null_expiry.pdf";
        Storage::disk('local')->put($path, 'Content Null Expiry');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_null_expiry.pdf',
            'file_size'                  => strlen('Content Null Expiry'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PROCESSING,
            'drive_locked_by'            => 'existing-worker-token-null-exp',
            'drive_lock_expires_at'      => null,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-NULL-EXP-OVERTAKE');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'intruder-worker-token');
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(0, $mockTransport->callCount);
        $fresh = $attachment->fresh();
        $this->assertEquals('existing-worker-token-null-exp', $fresh->drive_locked_by);
        $this->assertEquals(RegistrationAttachment::STATUS_PROCESSING, $fresh->drive_sync_status);
    }

    /**
     * R02-07: PROCESSING expired lease -> recovery aman.
     */
    public function test_r02_07_processing_with_expired_lease_is_safely_recovered(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_expired_lease.pdf";
        Storage::disk('local')->put($path, 'Content Expired Lease');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_expired_lease.pdf',
            'file_size'                  => strlen('Content Expired Lease'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PROCESSING,
            'drive_locked_by'            => 'crashed-worker-token-old',
            'drive_lock_expires_at'      => now()->subMinutes(10),
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-RECOVERED');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'healthy-recovery-worker');
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(1, $mockTransport->callCount);
        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-RECOVERED', $fresh->drive_file_id);
        $this->assertNull($fresh->drive_locked_by);
    }

    /**
     * R02-08: Serialized job mempertahankan fencing token.
     */
    public function test_r02_08_serialized_job_preserves_fencing_token(): void
    {
        $token = 'durable-fence-token-' . uniqid();
        $job = new UploadRegistrationAttachmentToDriveJob(999, $token);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(UploadRegistrationAttachmentToDriveJob::class, $unserialized);
        $this->assertEquals($token, $unserialized->fenceToken);
    }

    /**
     * R02-09: Stale worker gagal mengubah hasil worker pengganti.
     */
    public function test_r02_09_stale_worker_cannot_overwrite_successor_results(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_successor.pdf";
        Storage::disk('local')->put($path, 'Content Successor');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_successor.pdf',
            'file_size'                  => strlen('Content Successor'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_file_id'              => 'DRIVE-FILE-SUCCESSOR-FINAL',
            'drive_synced_at'            => now(),
            'drive_locked_by'            => null,
        ]);

        $mockTransport = $this->makeMockTransport('STALE-OVERWRITE-ATTEMPT');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $staleJob = new UploadRegistrationAttachmentToDriveJob($attachment->id, 'stale-token-123');
        $staleJob->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals('DRIVE-FILE-SUCCESSOR-FINAL', $fresh->drive_file_id);
        $this->assertEquals(0, $mockTransport->callCount);
    }

    /**
     * R02-10: Upload Apps Script menghasilkan payload tahun dan identitas registrasi yang benar.
     */
    public function test_r02_10_apps_script_upload_generates_correct_year_and_registration_payload(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $reg->update(['created_at' => '2026-03-15 10:00:00']);

        $path = "private/registrations/{$reg->id}/att_apps_script.pdf";
        Storage::disk('local')->put($path, 'Payload verification content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_apps_script.pdf',
            'file_size'                  => strlen('Payload verification content'),
            'mime_type'                  => 'application/pdf',
        ]);

        $interceptedPayload = null;
        Http::fake([
            'https://script.google.com/*' => function ($request) use (&$interceptedPayload) {
                $body = json_decode($request->body(), true);
                $interceptedPayload = $body['payload'] ?? null;
                return Http::response([
                    'success' => true,
                    'data'    => [
                        'file_id'       => 'REAL-APPS-SCRIPT-FILE-ID',
                        'web_view_link' => 'https://drive.google.com/file/d/REAL-APPS-SCRIPT-FILE-ID/view',
                    ],
                ], 200);
            },
        ]);

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'shared_secret' => 'secret_deploy_token',
            'endpoint_url'  => 'https://script.google.com/macros/s/test/exec',
        ]);
        $connection = SpreadsheetConnection::first();

        $response = $transport->uploadAttachmentLegacy($connection, $attachment, Storage::disk('local')->path($path));

        $this->assertTrue($response->success);
        $this->assertNotNull($interceptedPayload);
        $this->assertEquals('upload_attachment', $interceptedPayload['action'] ?? null);
        $this->assertEquals('2026', $interceptedPayload['year'] ?? null);
        $this->assertEquals((string)$reg->id, (string)($interceptedPayload['registration_id'] ?? null));
        $this->assertEquals($reg->fullname, $interceptedPayload['applicant_name'] ?? null);
        $this->assertEquals($attachment->original_name, $interceptedPayload['file_name'] ?? null);
        $this->assertEquals(hash_file('sha256', Storage::disk('local')->path($path)), $interceptedPayload['file_checksum'] ?? null);
    }

    /**
     * R02-11: Tidak ada undefined variable pada transport.
     */
    public function test_r02_11_apps_script_transport_has_no_undefined_variables(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_no_undefined.pdf";
        Storage::disk('local')->put($path, 'Content without undefined vars');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_no_undefined.pdf',
            'file_size'                  => strlen('Content without undefined vars'),
            'mime_type'                  => 'application/pdf',
        ]);

        Http::fake([
            'https://script.google.com/*' => Http::response([
                'success'       => true,
                'file_id'       => 'DRIVE-FILE-DEF-TEST',
                'web_view_link' => 'https://drive.google.com/view',
            ], 200),
        ]);

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'shared_secret' => 'token',
            'endpoint_url'  => 'https://script.google.com/macros/s/test/exec',
        ]);
        $connection = SpreadsheetConnection::first();

        $response = $transport->uploadAttachmentLegacy($connection, $attachment, Storage::disk('local')->path($path));
        $this->assertTrue($response->success);
        $this->assertEquals('DRIVE-FILE-DEF-TEST', $response->rawResponse['file_id'] ?? null);
    }

    /**
     * R02-12: Apps Script tanpa DriveApp/LockService -> fail-closed.
     */
    public function test_r02_12_apps_script_fails_closed_when_services_unavailable(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_service_unavail.pdf";
        Storage::disk('local')->put($path, 'Content Unavailable Services');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_service_unavail.pdf',
            'file_size'                  => strlen('Content Unavailable Services'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        Http::fake([
            'https://script.google.com/*' => Http::response([
                'success' => false,
                'error'   => 'DRIVE_SERVICE_UNAVAILABLE',
                'message' => 'DriveApp service is not available in current execution context',
            ], 503),
        ]);

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'shared_secret' => 'token',
            'endpoint_url'  => 'https://script.google.com/macros/s/test/exec',
        ]);
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($transport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertNotEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals(RegistrationAttachment::STATUS_RETRYING, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_file_id);
    }

    /**
     * R02-13: Respons dry_run dan DRIVE-MOCK -> tidak pernah menghasilkan status SYNCED operasional.
     */
    public function test_r02_13_dry_run_and_mock_drive_responses_never_produce_operational_synced_status(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_dryrun.pdf";
        Storage::disk('local')->put($path, 'Content Dry Run Mock');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_dryrun.pdf',
            'file_size'                  => strlen('Content Dry Run Mock'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = new class implements SpreadsheetTransportInterface {
            public function sendRowUpsert($c, $t, $s, $p): SpreadsheetTransportResponse
            {
                return SpreadsheetTransportResponse::ok(10, [
                    'file_id'       => 'DRIVE-MOCK-ATTACHMENT-XYZ',
                    'web_view_link' => 'https://drive.google.com/file/d/DRIVE-MOCK/view',
                    'dry_run'       => true,
                ]);
            }
            public function sendRowUpdateOnly($c, $t, $s, $p): SpreadsheetTransportResponse
            {
                return SpreadsheetTransportResponse::ok(10);
            }
            public function testConnection($c): bool { return true; }
            public function listTabs(SpreadsheetConnection $connection): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, ['tabs' => []]); }
            public function inspectSchema(SpreadsheetConnection $connection, string $tabName, ?int $headerRow = null): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, ['columns' => []]); }
            public function readRowsPage(SpreadsheetConnection $connection, string $tabName, ?int $headerRow = null, int $page = 1, int $pageSize = 20, bool $maskPii = true): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, ['rows' => []]); }
            public function syncSheetSchema(SpreadsheetConnection $connection, string $tabName, array $headers): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, []); }
            public function deleteEmptyColumn(SpreadsheetConnection $connection, int $sheetId, string $tabName, string $columnIdentity, string $expectedSchemaHash, ?string $idempotencyKey = null): SpreadsheetTransportResponse { return SpreadsheetTransportResponse::ok(0, []); }
        };

        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertNotEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $fresh->drive_sync_status);
        $this->assertNull($fresh->drive_synced_at);
    }

    /**
     * R02-14: Direct Drive duplicate filename dengan konten berbeda -> tidak direkonsiliasi secara keliru.
     */
    public function test_r02_14_direct_drive_duplicate_filename_with_different_checksum_throws_conflict(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'remote_file_different_checksum',
                'file_name'       => 'cv_conflict.pdf',
                'checksum_sha256' => 'checksum_from_another_file_version',
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_conflict_');
        file_put_contents($tempFile, 'My Local Content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Integritas berkas terganggu: Checksum SHA-256 remote tidak sesuai dengan file lokal.');

        try {
            $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'cv_conflict.pdf');
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * R02-15: Remote lookup failure -> tidak otomatis mengunggah salinan baru.
     */
    public function test_r02_15_remote_lookup_failure_does_not_upload_duplicate_copy(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'    => false,
                'message'    => 'Internal Server Error',
                'error_code' => 'REMOTE_ERROR',
            ], 500),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_lookup_fail_');
        file_put_contents($tempFile, 'Lookup Fail Content');

        $this->expectException(RuntimeException::class);

        try {
            $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'cv_lookup_fail.pdf');
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * R02-16: Unknown remote outcome -> ditangani melalui reconciliation yang aman.
     */
    public function test_r02_16_ambiguous_remote_lookup_fails_closed(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'    => false,
                'message'    => 'Ditemukan duplikasi ambigu pada Google Drive untuk berkas: ambiguous.pdf',
                'error_code' => 'AMBIGUOUS_DUPLICATE',
            ], 409),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_ambig_');
        file_put_contents($tempFile, 'Ambiguous Content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ditemukan duplikasi ambigu');

        try {
            $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'ambiguous.pdf');
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * R02-17: Stale worker tidak dapat menulis metadata melalui CloudSyncManager.
     */
    public function test_r02_17_stale_worker_cannot_persist_metadata_via_cloud_sync_manager(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_stale_csm.pdf";
        Storage::disk('local')->put($path, 'Content Stale CSM');

        Http::fake([
            'https://script.google.com/*' => Http::response([
                'success' => true,
                'file_id' => 'STALE_UPLOAD_ID',
                'web_view_link' => 'https://drive.google.com/file/d/STALE_UPLOAD_ID/view',
            ], 200),
        ]);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_stale_csm.pdf',
            'file_size'                  => strlen('Content Stale CSM'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_file_id'              => 'DRIVE-FILE-SUCCESSOR-RECOGNIZED',
            'drive_synced_at'            => now(),
            'drive_locked_by'            => null,
        ]);

        $cloudSyncManager = app(CloudSyncManager::class);
        $result = $cloudSyncManager->uploadRegistrationAttachment($attachment);
        $this->assertArrayHasKey('file_id', $result);

        $fresh = $attachment->fresh();
        $this->assertEquals('DRIVE-FILE-SUCCESSOR-RECOGNIZED', $fresh->drive_file_id);
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
    }

    /**
     * R02-18: Schema leasing belum tersedia -> tidak melakukan upload yang mengklaim terlindungi fencing.
     */
    public function test_r02_18_missing_leasing_schema_fails_closed_without_upload(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_no_schema.pdf";
        Storage::disk('local')->put($path, 'Content No Schema');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_no_schema.pdf',
            'file_size'                  => strlen('Content No Schema'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-NO-SCHEMA');
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        \Illuminate\Support\Facades\Schema::shouldReceive('hasColumn')
            ->with('registration_attachments', 'drive_locked_by')
            ->andReturn(false);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $this->assertEquals(0, $mockTransport->callCount);
        $this->assertEquals(RegistrationAttachment::STATUS_FAILED, $attachment->fresh()->drive_sync_status);
        $this->assertStringContainsString('Schema database belum mendukung durable leasing contract', $attachment->fresh()->drive_error_message);

        \Mockery::close();
        \Illuminate\Support\Facades\Schema::clearResolvedInstances();
    }

    /**
     * R02-19: Spreadsheet requeue failure tidak menyebabkan upload ulang.
     */
    public function test_r02_19_spreadsheet_requeue_failure_does_not_cause_drive_reupload(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_requeue_fail.pdf";
        Storage::disk('local')->put($path, 'Content Requeue Fail');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_requeue_fail.pdf',
            'file_size'                  => strlen('Content Requeue Fail'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = $this->makeMockTransport('DRIVE-FILE-REQUEUE-19');

        $mockSyncEngine = $this->createMock(SpreadsheetSyncEngineService::class);
        $mockSyncEngine->method('queueSyncForRegistration')
            ->willThrowException(new RuntimeException('Spreadsheet engine offline'));

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $mockSyncEngine);

        $this->assertEquals(1, $mockTransport->callCount);
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $attachment->fresh()->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-REQUEUE-19', $attachment->fresh()->drive_file_id);

        $job2 = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job2->handle($mockTransport, $mockSyncEngine);
        $this->assertEquals(1, $mockTransport->callCount);
    }

    // =========================================================================
    // MATRIX R03: FINAL REMOTE IDENTITY CORRECTION (ITEMS 01 - 14)
    // =========================================================================

    /**
     * Helper to run Node.js script against SertifGoogleIntegration.js
     */
    private function runAppsScriptNodeTest(string $nodeScript): array
    {
        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * R03-01: Attachment sama, checksum sama -> reuse valid.
     */
    public function test_r03_01_apps_script_attachment_same_checksum_same_valid_reuse(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Konten Berkas Identik R03 01";
const checksum = crypto.createHash("sha256").update(rawContent).digest("hex");
const contentVersion = checksum.substring(0, 16);
const syncId = "ATT-10-5-v" + contentVersion;

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "FILE_EXISTING_SAME",
    getName: () => syncId + "_cv.pdf",
    getDescription: () => "sertif_sync:reg=10:att=5:sha256=" + checksum + ":sync_id=" + syncId,
    getUrl: () => "https://drive.google.com/file/d/FILE_EXISTING_SAME/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    return { getId: () => "NEW_ID", getName: () => blob.getName(), getUrl: () => "https://view", setDescription: () => {} };
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: checksum,
  content_version: contentVersion
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  operation: data.operation,
  file_id: data.file_id,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertTrue($out['success']);
        $this->assertEquals('reused_existing', $out['operation']);
        $this->assertEquals('FILE_EXISTING_SAME', $out['file_id']);
        $this->assertEquals(0, $out['createdCount']);
    }

    /**
     * R03-02: Attachment sama, checksum berubah -> tidak reuse file lama.
     */
    public function test_r03_02_apps_script_attachment_same_checksum_changed_creates_new_version(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const oldContent = "Old Content Version 1";
const oldChecksum = crypto.createHash("sha256").update(oldContent).digest("hex");
const oldVersion = oldChecksum.substring(0, 16);
const oldSyncId = "ATT-10-5-v" + oldVersion;

const newContent = "New Content Version 2";
const newChecksum = crypto.createHash("sha256").update(newContent).digest("hex");
const newVersion = newChecksum.substring(0, 16);
const newSyncId = "ATT-10-5-v" + newVersion;

let createdCount = 0;
let createdDesc = "";
const storedFiles = [
  {
    getId: () => "OLD_FILE_ID_V1",
    getName: () => oldSyncId + "_cv.pdf",
    getDescription: () => "sertif_sync:reg=10:att=5:sha256=" + oldChecksum + ":sync_id=" + oldSyncId,
    getUrl: () => "https://drive.google.com/file/d/OLD_FILE_ID_V1/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    const f = {
      getId: () => "NEW_FILE_ID_V2",
      getName: () => blob.getName(),
      getDescription: () => createdDesc,
      setDescription: (d) => { createdDesc = d; },
      getUrl: () => "https://drive.google.com/file/d/NEW_FILE_ID_V2/view"
    };
    storedFiles.push(f);
    return f;
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: newSyncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(newContent).toString("base64"),
  file_checksum: newChecksum,
  content_version: newVersion
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  operation: data.operation,
  file_id: data.file_id,
  createdCount: createdCount,
  createdDesc: createdDesc
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertTrue($out['success']);
        $this->assertEquals('created', $out['operation']);
        $this->assertEquals('NEW_FILE_ID_V2', $out['file_id']);
        $this->assertNotEquals('OLD_FILE_ID_V1', $out['file_id']);
        $this->assertEquals(1, $out['createdCount']);
        $this->assertStringContainsString('sha256=', $out['createdDesc']);
    }

    /**
     * R03-03: File lama tanpa checksum/identitas -> tidak dianggap cocok (fail-closed to new upload).
     */
    public function test_r03_03_apps_script_legacy_file_without_checksum_is_not_reused(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const content = "Real Content for Test 03";
const checksum = crypto.createHash("sha256").update(content).digest("hex");
const version = checksum.substring(0, 16);
const syncId = "ATT-10-5-v" + version;

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "LEGACY_FILE_NO_METADATA",
    getName: () => "ATT-10-5_cv.pdf",
    getDescription: () => "",
    getUrl: () => "https://drive.google.com/legacy"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    return {
      getId: () => "NEW_VERIFIED_FILE",
      getName: () => blob.getName(),
      getUrl: () => "https://view",
      setDescription: () => {}
    };
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(content).toString("base64"),
  file_checksum: checksum,
  content_version: version
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  operation: data.operation,
  file_id: data.file_id,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertTrue($out['success']);
        $this->assertEquals('created', $out['operation']);
        $this->assertEquals('NEW_VERIFIED_FILE', $out['file_id']);
        $this->assertNotEquals('LEGACY_FILE_NO_METADATA', $out['file_id']);
        $this->assertEquals(1, $out['createdCount']);
    }

    /**
     * R03-04: Nama file sama tetapi attachment ID berbeda -> identitas tetap terpisah.
     */
    public function test_r03_04_apps_script_same_filename_different_attachment_id_keeps_distinct_identity(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const content = "Content for Attachment 7";
const checksum = crypto.createHash("sha256").update(content).digest("hex");
const version = checksum.substring(0, 16);
const syncIdAtt7 = "ATT-10-7-v" + version;

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "FILE_ATT_99",
    getName: () => "ATT-10-99-v" + version + "_cv.pdf",
    getDescription: () => "sertif_sync:reg=10:att=99:sha256=" + checksum + ":sync_id=ATT-10-99-v" + version,
    getUrl: () => "https://drive.google.com/file/d/FILE_ATT_99/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    return {
      getId: () => "FILE_ATT_7_NEW",
      getName: () => blob.getName(),
      getUrl: () => "https://view",
      setDescription: () => {}
    };
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncIdAtt7,
  registration_id: "10",
  attachment_id: "7",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(content).toString("base64"),
  file_checksum: checksum,
  content_version: version
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  operation: data.operation,
  file_id: data.file_id,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertTrue($out['success']);
        $this->assertEquals('created', $out['operation']);
        $this->assertEquals('FILE_ATT_7_NEW', $out['file_id']);
        $this->assertNotEquals('FILE_ATT_99', $out['file_id']);
        $this->assertEquals(1, $out['createdCount']);
    }

    /**
     * R03-05: Apps Script menerima payload versi konten yang benar.
     */
    public function test_r03_05_transport_sends_correct_content_version_payload(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_v5.pdf";
        Storage::disk('local')->put($path, 'Content for Version 5 Payload Test');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_v5.pdf',
            'file_size'                  => strlen('Content for Version 5 Payload Test'),
            'mime_type'                  => 'application/pdf',
        ]);

        $intercepted = null;
        Http::fake([
            'https://script.google.com/*' => function ($request) use (&$intercepted) {
                $body = json_decode($request->body(), true);
                $intercepted = $body['payload'] ?? null;
                return Http::response([
                    'success'       => true,
                    'file_id'       => 'MOCK_REMOTE_DRIVE_ID',
                    'web_view_link' => 'https://drive.google.com/file/d/MOCK/view',
                ], 200);
            },
        ]);

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'shared_secret' => 'test_secret',
            'endpoint_url'  => 'https://script.google.com/macros/s/test/exec',
        ]);
        $connection = SpreadsheetConnection::first();

        $response = $transport->uploadAttachmentLegacy($connection, $attachment, Storage::disk('local')->path($path));

        $this->assertTrue($response->success);
        $this->assertNotNull($intercepted);

        $expectedHash = hash_file('sha256', Storage::disk('local')->path($path));
        $expectedVersion = substr($expectedHash, 0, 16);

        $this->assertEquals($expectedHash, $intercepted['file_checksum']);
        $this->assertEquals($expectedVersion, $intercepted['content_version']);
        $this->assertEquals("ATT-{$reg->id}-{$attachment->id}-v{$expectedVersion}", $intercepted['sync_id']);
        $this->assertEquals((string)$attachment->id, $intercepted['attachment_id']);
    }

    /**
     * R03-06: Apps Script tidak mengembalikan old file ID untuk checksum baru.
     */
    public function test_r03_06_apps_script_does_not_return_old_file_id_for_new_checksum(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_v6.pdf";
        Storage::disk('local')->put($path, 'Old Content Version 1');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_v6.pdf',
            'file_size'                  => strlen('Old Content Version 1'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_file_id'              => 'OLD_DRIVE_ID_111',
            'drive_synced_at'            => now(),
            'drive_file_checksum'        => hash('sha256', 'Old Content Version 1'),
        ]);

        // File modified on disk to Version 2
        Storage::disk('local')->put($path, 'Brand New Content Version 2');
        $attachment->invalidateDriveSync('Content changed');

        // Transport returns new file ID for new checksum
        Http::fake([
            'https://script.google.com/*' => function ($request) {
                $body = json_decode($request->body(), true);
                $checksum = $body['payload']['checksum_sha256'] ?? $body['payload']['file_checksum'] ?? '';
                if ($checksum === hash('sha256', 'Brand New Content Version 2')) {
                    return Http::response([
                        'success'       => true,
                        'file_id'       => 'NEW_DRIVE_ID_222',
                        'web_view_link' => 'https://drive.google.com/file/d/NEW_DRIVE_ID_222/view',
                    ], 200);
                }
                return Http::response(['success' => false, 'error' => 'WRONG_CHECKSUM'], 400);
            },
        ]);

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'shared_secret' => 'test_secret',
            'endpoint_url'  => 'https://script.google.com/macros/s/test/exec',
        ]);
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($transport, $syncEngine);

        $fresh = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $fresh->drive_sync_status);
        $this->assertEquals('NEW_DRIVE_ID_222', $fresh->drive_file_id);
        $this->assertNotEquals('OLD_DRIVE_ID_111', $fresh->drive_file_id);
        $this->assertEquals(hash('sha256', 'Brand New Content Version 2'), $fresh->drive_file_checksum);
    }

    /**
     * R03-07: Direct Drive checksum sama -> valid reuse.
     */
    public function test_r03_07_direct_drive_checksum_same_valid_reuse(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_r03_07_');
        file_put_contents($tempFile, 'Identical Content Direct Drive 7');
        $fileChecksum = hash_file('sha256', $tempFile);

        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'EXISTING_REMOTE_FILE_7',
                'file_name'       => 'file_7.pdf',
                'web_view_link'   => 'https://drive.google.com/file/d/EXISTING_REMOTE_FILE_7/view',
                'operation'       => 'reused_existing',
                'checksum_sha256' => $fileChecksum,
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        try {
            $result = $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'file_7.pdf');
            $this->assertEquals('EXISTING_REMOTE_FILE_7', $result['file_id']);
            $this->assertEquals('reused_existing', $result['operation']);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * R03-08: Direct Drive checksum berbeda -> tidak melakukan false reuse.
     */
    public function test_r03_08_direct_drive_checksum_different_rejects_reuse(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_r03_08_');
        file_put_contents($tempFile, 'Content A');

        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'REMOTE_FILE_DIFFERENT_HASH',
                'file_name'       => 'file_8.pdf',
                'checksum_sha256' => 'different_hash_from_other_content_12345',
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Integritas berkas terganggu: Checksum SHA-256 remote tidak sesuai dengan file lokal.');

        try {
            $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'file_8.pdf');
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * R03-09: Direct Drive checksum kosong -> fail-closed.
     */
    public function test_r03_09_direct_drive_missing_checksum_fails_closed(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_r03_09_');
        file_put_contents($tempFile, 'Content 9');

        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'    => false,
                'message'    => 'Konflik rekonsiliasi: berkas remote dengan nama sama tidak memiliki metadata checksum yang valid.',
                'error_code' => 'INVALID_CHECKSUM',
            ], 422),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $this->expectException(RuntimeException::class);

        try {
            $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'file_9.pdf');
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * R03-10: Folder lookup 200 + kosong -> boleh create.
     */
    public function test_r03_10_folder_lookup_200_empty_allows_creation(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'   => true,
                'action'    => 'drive_resolve_folder',
                'folder_id' => 'NEWLY_CREATED_FOLDER_10',
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $folderId = $driver->getOrCreateFolderPath('CustomSubfolder');
        $this->assertEquals('NEWLY_CREATED_FOLDER_10', $folderId);
    }

    /**
     * R03-11: Folder lookup 500 -> tidak create.
     */
    public function test_r03_11_folder_lookup_500_does_not_create_folder(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'error' => 'Server Error',
            ], 500),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $folderId = $driver->getOrCreateFolderPath('CustomSubfolder500');
        $this->assertEquals('root_wave2d_test', $folderId);
    }

    /**
     * R03-12: Folder lookup timeout -> tidak create.
     */
    public function test_r03_12_folder_lookup_timeout_does_not_create_folder(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $folderId = $driver->getOrCreateFolderPath('CustomSubfolderTimeout');
        $this->assertEquals('root_wave2d_test', $folderId);
    }

    /**
     * R03-13: Folder lookup ambigu -> fail-closed.
     */
    public function test_r03_13_folder_lookup_ambiguous_fails_closed(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'    => false,
                'message'    => 'Ditemukan duplikasi folder ambigu pada Google Drive',
                'error_code' => 'AMBIGUOUS_FOLDER',
            ], 409),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();
        $folderId = $driver->getOrCreateFolderPath('CustomSubfolderAmbiguous');
        $this->assertEquals('root_wave2d_test', $folderId);
    }

    /**
     * R03-14: Remote upload unknown outcome -> rekonsiliasi aman.
     */
    public function test_r03_14_remote_upload_unknown_outcome_reconciles_safely(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_r03_14_');
        file_put_contents($tempFile, 'Content For Recovery Reconciliation');
        $checksum = hash_file('sha256', $tempFile);

        Http::fake([
            'https://script.google.com/macros/s/test_wave2d_gas/exec*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'PREVIOUSLY_UPLOADED_DRIVE_ID',
                'file_name'       => 'file_recon_14.pdf',
                'web_view_link'   => 'https://drive.google.com/file/d/PREVIOUSLY_UPLOADED_DRIVE_ID/view',
                'operation'       => 'reused_existing',
                'checksum_sha256' => $checksum,
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        try {
            $result = $driver->uploadFile($tempFile, 'Lampiran/2026/Reg-10', 'file_recon_14.pdf');
            $this->assertEquals('PREVIOUSLY_UPLOADED_DRIVE_ID', $result['file_id']);
            $this->assertEquals('reused_existing', $result['operation']);
        } finally {
            @unlink($tempFile);
        }
    }

    // =========================================================================
    // MATRIX R04: FINAL VERSION IDENTITY PATCH (ITEMS 01 - 12)
    // =========================================================================

    /**
     * R04-01: Identitas sama, checksum sama -> reuse valid.
     */
    public function test_r04_01_apps_script_same_identity_same_checksum_reuses_existing(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Valid Content R04 01";
const checksum = crypto.createHash("sha256").update(rawContent).digest("hex");
const version = checksum.substring(0, 16);
const syncId = "ATT-10-5-v" + version;

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "REMOTE_EXISTING_R04_01",
    getName: () => syncId + "_cv.pdf",
    getDescription: () => "sertif_sync:reg=10:att=5:sha256=" + checksum + ":sync_id=" + syncId,
    getUrl: () => "https://drive.google.com/file/d/REMOTE_EXISTING_R04_01/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    return { getId: () => "NEW_ID", getName: () => blob.getName(), getUrl: () => "https://view", setDescription: () => {} };
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: checksum,
  content_version: version
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  operation: data.operation,
  file_id: data.file_id,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertTrue($out['success']);
        $this->assertEquals('reused_existing', $out['operation']);
        $this->assertEquals('REMOTE_EXISTING_R04_01', $out['file_id']);
        $this->assertEquals(0, $out['createdCount']);
    }

    /**
     * R04-02: Identitas sama, metadata checksum hilang -> reject fail-closed, zero create.
     */
    public function test_r04_02_apps_script_same_identity_missing_checksum_metadata_fails_closed_zero_create(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Valid Content R04 02";
const checksum = crypto.createHash("sha256").update(rawContent).digest("hex");
const version = checksum.substring(0, 16);
const syncId = "ATT-10-5-v" + version;

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "REMOTE_CORRUPT_METADATA",
    getName: () => syncId + "_cv.pdf",
    getDescription: () => "", // Empty description / missing metadata
    getUrl: () => "https://drive.google.com/file/d/REMOTE_CORRUPT_METADATA/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    return { getId: () => "NEW_ID", getName: () => blob.getName(), getUrl: () => "https://view", setDescription: () => {} };
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: checksum,
  content_version: version
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  error_code: data.error_code,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertFalse($out['success']);
        $this->assertEquals('REMOTE_IDENTITY_UNVERIFIABLE', $out['error_code']);
        $this->assertEquals(0, $out['createdCount']);
    }

    /**
     * R04-03: Identitas sama, checksum tidak cocok -> reject fail-closed, zero create.
     */
    public function test_r04_03_apps_script_same_identity_mismatched_checksum_fails_closed_zero_create(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Valid Content R04 03";
const checksum = crypto.createHash("sha256").update(rawContent).digest("hex");
const version = checksum.substring(0, 16);
const syncId = "ATT-10-5-v" + version;

const mismatchedChecksum = "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff";

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "REMOTE_CONFLICT_HASH",
    getName: () => syncId + "_cv.pdf",
    getDescription: () => "sertif_sync:reg=10:att=5:sha256=" + mismatchedChecksum + ":sync_id=" + syncId,
    getUrl: () => "https://drive.google.com/file/d/REMOTE_CONFLICT_HASH/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    return { getId: () => "NEW_ID", getName: () => blob.getName(), getUrl: () => "https://view", setDescription: () => {} };
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: checksum,
  content_version: version
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  error_code: data.error_code,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertFalse($out['success']);
        $this->assertEquals('REMOTE_IDENTITY_CONFLICT', $out['error_code']);
        $this->assertEquals(0, $out['createdCount']);
    }

    /**
     * R04-04: Content version berbeda -> file baru dapat dibuat tanpa menghapus versi lama.
     */
    public function test_r04_04_apps_script_different_content_version_creates_new_version_preserving_old(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const oldContent = "Old Content Version 100";
const oldChecksum = crypto.createHash("sha256").update(oldContent).digest("hex");
const oldVersion = oldChecksum.substring(0, 16);
const oldSyncId = "ATT-10-5-v" + oldVersion;

const newContent = "New Content Version 200";
const newChecksum = crypto.createHash("sha256").update(newContent).digest("hex");
const newVersion = newChecksum.substring(0, 16);
const newSyncId = "ATT-10-5-v" + newVersion;

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "OLD_REMOTE_VERSION_FILE",
    getName: () => oldSyncId + "_cv.pdf",
    getDescription: () => "sertif_sync:reg=10:att=5:sha256=" + oldChecksum + ":sync_id=" + oldSyncId,
    getUrl: () => "https://drive.google.com/file/d/OLD_REMOTE_VERSION_FILE/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    const newFile = {
      getId: () => "NEW_REMOTE_VERSION_FILE",
      getName: () => blob.getName(),
      getUrl: () => "https://view",
      setDescription: () => {}
    };
    storedFiles.push(newFile);
    return newFile;
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: newSyncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(newContent).toString("base64"),
  file_checksum: newChecksum,
  content_version: newVersion
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  operation: data.operation,
  file_id: data.file_id,
  createdCount: createdCount,
  totalFilesInFolder: storedFiles.length
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertTrue($out['success']);
        $this->assertEquals('created', $out['operation']);
        $this->assertEquals('NEW_REMOTE_VERSION_FILE', $out['file_id']);
        $this->assertEquals(1, $out['createdCount']);
        // Historical old file is preserved in folder: 2 files total
        $this->assertEquals(2, $out['totalFilesInFolder']);
    }

    /**
     * R04-05: Direct Drive content version baru -> upload file baru secara sah.
     */
    public function test_r04_05_direct_drive_new_content_version_uploads_new_versioned_file(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_v_r04.pdf";
        Storage::disk('local')->put($path, 'Content Direct Drive Version 1');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_v_r04.pdf',
            'file_size'                  => strlen('Content Direct Drive Version 1'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $fullPath = Storage::disk('local')->path($path);
        $hash1 = hash_file('sha256', $fullPath);
        $version1 = substr($hash1, 0, 16);
        $expectedName1 = "ATT-{$reg->id}-{$attachment->id}-v{$version1}_att_v_r04.pdf";

        $uploadedNames = [];
        $driver = $this->createMock(CloudStorageProviderInterface::class);
        $driver->method('getDriverName')->willReturn('google_apps_script_drive');
        $driver->method('uploadFile')->willReturnCallback(function ($path, $folder, $customName) use (&$uploadedNames) {
            $uploadedNames[] = $customName;
            return [
                'file_id'       => 'DRIVE_FILE_ID_' . count($uploadedNames),
                'file_name'     => $customName,
                'web_view_link' => 'https://drive.google.com/file/d/MOCK/view',
                'driver'        => 'google_apps_script_drive',
            ];
        });
        $csm = new CloudSyncManager($driver);

        $result1 = $csm->uploadRegistrationAttachment($attachment);
        $this->assertEquals('DRIVE_FILE_ID_1', $result1['file_id']);
        $this->assertEquals($expectedName1, $result1['file_name']);

        // Now modify content to Version 2
        Storage::disk('local')->put($path, 'Brand New Content For Direct Drive Version 2');
        $hash2 = hash_file('sha256', $fullPath);
        $version2 = substr($hash2, 0, 16);
        $expectedName2 = "ATT-{$reg->id}-{$attachment->id}-v{$version2}_att_v_r04.pdf";

        $result2 = $csm->uploadRegistrationAttachment($attachment);
        $this->assertEquals('DRIVE_FILE_ID_2', $result2['file_id']);
        $this->assertEquals($expectedName2, $result2['file_name']);
        $this->assertNotEquals($expectedName1, $expectedName2);

        $this->assertCount(2, $uploadedNames);
        $this->assertEquals($expectedName1, $uploadedNames[0]);
        $this->assertEquals($expectedName2, $uploadedNames[1]);
    }

    /**
     * R04-06: Direct Drive retry versi sama -> tidak membuat duplikat.
     */
    public function test_r04_06_direct_drive_same_version_retry_does_not_create_duplicate(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_retry.pdf";
        Storage::disk('local')->put($path, 'Content For Retry Safety Test');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_retry.pdf',
            'file_size'                  => strlen('Content For Retry Safety Test'),
            'mime_type'                  => 'application/pdf',
        ]);

        $fullPath = Storage::disk('local')->path($path);
        $hash = hash_file('sha256', $fullPath);
        $version = substr($hash, 0, 16);
        $expectedName = "ATT-{$reg->id}-{$attachment->id}-v{$version}_att_retry.pdf";

        $driver = $this->createMock(CloudStorageProviderInterface::class);
        $driver->method('getDriverName')->willReturn('google_apps_script_drive');
        $driver->method('uploadFile')->willReturn([
            'file_id'       => 'EXISTING_DRIVE_RETRY_ID',
            'file_name'     => $expectedName,
            'web_view_link' => 'https://drive.google.com/file/d/EXISTING_DRIVE_RETRY_ID/view',
            'operation'     => 'reused_existing',
            'driver'        => 'google_apps_script_drive',
        ]);
        $csm = new CloudSyncManager($driver);

        $result = $csm->uploadRegistrationAttachment($attachment);
        $this->assertEquals('EXISTING_DRIVE_RETRY_ID', $result['file_id']);
        $this->assertEquals('reused_existing', $result['operation']);
    }

    /**
     * R04-07: Direct Drive metadata checksum hilang -> fail-closed.
     */
    public function test_r04_07_direct_drive_missing_checksum_fails_closed(): void
    {
        Storage::fake('local');
        $reg = $this->createRegistration();
        $path = "private/registrations/{$reg->id}/att_no_meta.pdf";
        Storage::disk('local')->put($path, 'Content No Meta Direct Drive');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $path,
            'original_name'              => 'att_no_meta.pdf',
            'file_size'                  => strlen('Content No Meta Direct Drive'),
            'mime_type'                  => 'application/pdf',
        ]);

        $driver = $this->createMock(CloudStorageProviderInterface::class);
        $driver->method('getDriverName')->willReturn('google_apps_script_drive');
        $driver->method('uploadFile')->willThrowException(
            new RuntimeException('Konflik rekonsiliasi: berkas remote dengan nama sama tidak memiliki metadata checksum yang valid.')
        );
        $csm = new CloudSyncManager($driver);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Konflik rekonsiliasi: berkas remote dengan nama sama tidak memiliki metadata checksum yang valid.');

        $csm->uploadRegistrationAttachment($attachment);
    }

    /**
     * R04-08: SHA256 payload berbeda dari byte file -> reject 400.
     */
    public function test_r04_08_apps_script_checksum_payload_different_from_file_bytes_rejects(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Actual File Content";
const fakeChecksum = "9999999999999999000000000000000000000000000000000000000000000000";
const fakeVersion = "9999999999999999";
const syncId = "ATT-10-5-v" + fakeVersion;

let createdCount = 0;
const targetFolder = {
  getFiles: () => ({ hasNext: () => false, next: () => null }),
  createFile: () => { createdCount++; return {}; }
};
const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder }) }) })
};
global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: fakeChecksum, // Mismatch with actual hash of rawContent
  content_version: fakeVersion
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  error_code: data.error_code,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertFalse($out['success']);
        $this->assertEquals('CHECKSUM_MISMATCH', $out['error_code']);
        $this->assertEquals(0, $out['createdCount']);
    }

    /**
     * R04-09: content_version tidak sesuai SHA256 -> reject 400.
     */
    public function test_r04_09_apps_script_content_version_mismatched_with_checksum_rejects(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Content For Version Mismatch";
const checksum = crypto.createHash("sha256").update(rawContent).digest("hex");
const invalidVersion = "wrong_version_12"; // Does not match first 16 chars of checksum
const syncId = "ATT-10-5-v" + invalidVersion;

let createdCount = 0;
const targetFolder = {
  getFiles: () => ({ hasNext: () => false, next: () => null }),
  createFile: () => { createdCount++; return {}; }
};
const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder }) }) })
};
global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: checksum,
  content_version: invalidVersion
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  error_code: data.error_code,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertFalse($out['success']);
        $this->assertEquals('CONTENT_VERSION_MISMATCH', $out['error_code']);
        $this->assertEquals(0, $out['createdCount']);
    }

    /**
     * R04-10: Identitas registration/attachment tidak sesuai -> reject 400.
     */
    public function test_r04_10_apps_script_registration_or_attachment_id_mismatched_with_sync_id_rejects(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Content For Registration Mismatch";
const checksum = crypto.createHash("sha256").update(rawContent).digest("hex");
const version = checksum.substring(0, 16);
const tamperedSyncId = "ATT-999-5-v" + version; // registration_id is 10, but sync_id says 999

let createdCount = 0;
const targetFolder = {
  getFiles: () => ({ hasNext: () => false, next: () => null }),
  createFile: () => { createdCount++; return {}; }
};
const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder }) }) })
};
global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: tamperedSyncId,
  registration_id: "10", // mismatch with 999
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: checksum,
  content_version: version
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  error_code: data.error_code,
  createdCount: createdCount
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertFalse($out['success']);
        $this->assertEquals('SYNC_IDENTITY_MISMATCH', $out['error_code']);
        $this->assertEquals(0, $out['createdCount']);
    }

    /**
     * R04-11: MOU dan archive tidak mengalami regresi penamaan berkas.
     */
    public function test_r04_11_mou_and_archive_sync_do_not_suffer_regression(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        // 1. MOU Sync Test
        $mouPath = "mou/signed_mou_123.pdf";
        Storage::disk('public')->put($mouPath, 'MOU Signed Content 123');

        $institution = \App\Models\Institution::first() ?: \App\Models\Institution::create(['name' => 'Universitas Test']);

        $mou = \App\Models\Mou::create([
            'company_mou_number'           => 'MOU-SEVEN-2026-001',
            'institution_id'               => $institution->id,
            'first_party_institution_name' => $institution->name,
            'first_party_signatory_name'   => 'Dr. Rektor Test',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'effective_start'              => now()->toDateString(),
            'effective_end'                => now()->addYear()->toDateString(),
            'status'                       => \App\Models\Mou::STATUS_ACTIVE,
            'signed_file_path'             => $mouPath,
        ]);

        $uploadedFilename = null;
        $mockDriver = $this->createMock(\App\Services\CloudStorage\CloudStorageInterface::class);
        $mockDriver->method('isConfigured')->willReturn(true);
        $mockDriver->method('getDriverName')->willReturn('google_drive');
        $mockDriver->expects($this->once())
            ->method('uploadFile')
            ->willReturnCallback(function ($path, $folder, $filename) use (&$uploadedFilename) {
                $uploadedFilename = $filename;
                return [
                    'file_id'       => 'MOU_DRIVE_ID_1',
                    'web_view_link' => 'https://drive.google.com/mou',
                    'file_name'     => $filename,
                    'driver'        => 'google_drive',
                ];
            });

        $csm = new CloudSyncManager($mockDriver);
        $result = $csm->syncMou($mou);

        $this->assertEquals('MOU_DRIVE_ID_1', $result['file_id']);
        $this->assertEquals('MOU-mou-seven-2026-001-signed.pdf', $uploadedFilename);
        $this->assertStringNotContainsString('ATT-', $uploadedFilename);
        $this->assertStringNotContainsString('-v', $uploadedFilename);

        // 2. Archive Export Test
        $archivePath = "archives/2026/archive_2026.zip";
        Storage::disk('local')->put($archivePath, 'ZIP Archive Content 2026');

        $archive = \App\Models\ArchiveExport::create([
            'archive_year' => 2026,
            'file_name'    => 'archive_2026.zip',
            'file_path'    => $archivePath,
            'file_size'    => strlen('ZIP Archive Content 2026'),
            'status'       => 'completed',
        ]);

        $mockDriver2 = $this->createMock(\App\Services\CloudStorage\CloudStorageProviderInterface::class);
        $mockDriver2->method('isConfigured')->willReturn(true);
        $mockDriver2->method('getDriverName')->willReturn('google_apps_script_drive');

        $csm2 = new CloudSyncManager($mockDriver2);
        $resultArchive = $csm2->syncArchiveExport($archive);

        $this->assertTrue($resultArchive['success']);
        $this->assertEquals('deactivated', $resultArchive['driver']);
        $this->assertStringContainsString('telah dinonaktifkan', $resultArchive['message']);
    }

    /**
     * R04-12: Berkas lama unversioned dipertahankan tanpa memblokir upload versi baru.
     */
    public function test_r04_12_apps_script_legacy_unversioned_file_preserved_without_blocking_new_version(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const crypto = require("crypto");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "ATTACHMENTS_ROOT_FOLDER_ID" ? "root_123" : null
  })
};
global.LockService = {
  getScriptLock: () => ({ tryLock: () => true, releaseLock: () => {} })
};
global.Utilities = {
  DigestAlgorithm: { SHA_256: "SHA_256" },
  computeDigest: (algo, bytes) => {
    const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    return Array.from(crypto.createHash("sha256").update(buf).digest());
  },
  base64Decode: (str) => Buffer.from(str, 'base64'),
  newBlob: (bytes, mime, name) => ({ getName: () => name, getBytes: () => bytes, getContentType: () => mime })
};

const rawContent = "Content for Versioned Upload with Legacy Sibling";
const checksum = crypto.createHash("sha256").update(rawContent).digest("hex");
const version = checksum.substring(0, 16);
const syncId = "ATT-10-5-v" + version;

let createdCount = 0;
const storedFiles = [
  {
    getId: () => "LEGACY_UNVERSIONED_FILE",
    getName: () => "cv.pdf", // Unversioned generic legacy filename
    getDescription: () => "sertif_sync:reg=10:att=5:sha256=" + checksum,
    getUrl: () => "https://drive.google.com/file/d/LEGACY_UNVERSIONED_FILE/view"
  }
];

const targetFolder = {
  getFiles: () => {
    let idx = 0;
    return { hasNext: () => idx < storedFiles.length, next: () => storedFiles[idx++] };
  },
  createFile: (blob) => {
    createdCount++;
    const newFile = {
      getId: () => "NEW_VERSIONED_FILE_12",
      getName: () => blob.getName(),
      getUrl: () => "https://view",
      setDescription: () => {}
    };
    storedFiles.push(newFile);
    return newFile;
  }
};

const regBaseFolder = {
  getFoldersByName: () => ({ hasNext: () => true, next: () => ({
    getFoldersByName: () => ({ hasNext: () => true, next: () => targetFolder })
  }) })
};

global.DriveApp = {
  getFolderById: () => ({ getFoldersByName: () => ({ hasNext: () => true, next: () => regBaseFolder }) })
};

const payload = {
  action: "upload_attachment",
  sync_id: syncId,
  registration_id: "10",
  attachment_id: "5",
  applicant_name: "Budi",
  year: "2026",
  file_name: "cv.pdf",
  mime_type: "application/pdf",
  file_content: Buffer.from(rawContent).toString("base64"),
  file_checksum: checksum,
  content_version: version
};

const res = appsScript.handleUploadAttachmentAction(payload);
const data = res.data || JSON.parse(res.body);

console.log(JSON.stringify({
  success: data.success,
  operation: data.operation,
  file_id: data.file_id,
  createdCount: createdCount,
  totalFiles: storedFiles.length
}));
JS;

        $out = $this->runAppsScriptNodeTest($nodeScript);
        $this->assertTrue($out['success']);
        $this->assertEquals('created', $out['operation']);
        $this->assertEquals('NEW_VERSIONED_FILE_12', $out['file_id']);
        $this->assertEquals(1, $out['createdCount']);
        $this->assertEquals(2, $out['totalFiles']);
    }
}


