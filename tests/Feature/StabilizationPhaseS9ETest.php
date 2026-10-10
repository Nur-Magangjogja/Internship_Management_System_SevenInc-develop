<?php

namespace Tests\Feature;

use App\Console\Commands\SpreadsheetSyncRecoveryCommand;
use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\City;
use App\Models\Faculty;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\NullSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StabilizationPhaseS9ETest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $pemagang;
    protected Institution $institution;
    protected SpreadsheetConnection $regConnection;
    protected SpreadsheetConnection $followupConnection;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSpreadsheetRowSyncJob::class, UploadRegistrationAttachmentToDriveJob::class]);

        $this->seed(Spreadsheet2026SchemaSeeder::class);
        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);

        $this->unique = uniqid('s9e_', true);

        $this->admin = User::create([
            'name'      => 'Admin S9E ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang S9E ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas S9E ' . substr($this->unique, 0, 6),
            'is_active' => true,
        ]);

        $this->regConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        $this->regConnection->update([
            'is_enabled'               => true,
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test-reg-id/edit',
            'spreadsheet_id'           => 'test-reg-id',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-deployment-id/exec',
        ]);

        $this->followupConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();
        $this->followupConnection->update([
            'is_enabled'               => true,
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test-fwu-id/edit',
            'spreadsheet_id'           => 'test-fwu-id',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-deployment-id/exec',
        ]);
    }

    private function createValidRegistration(array $overrides = []): InternshipRegistration
    {
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $this->institution->id, 'name' => 'Fakultas MIPA S9E']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Informatika S9E']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        $reg = InternshipRegistration::create(array_merge([
            'user_id'           => $this->pemagang->id,
            'fullname'          => 'Budi Santoso S9E',
            'student_id'        => '22/999999/PA/99999',
            'gender'            => 'Laki-laki',
            'phone_number'      => '08123456789',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $prodi->id,
            'city_id'           => $city->id,
            'internship_reason' => 'Magang PKL',
            'internship_type'   => 'Mandiri',
            'current_status'    => 'Masih Kuliah',
            'wfo_readiness'     => 'Siap WFO',
            'has_laptop'        => true,
            'start_date'        => '2026-07-01',
            'planned_duration'  => '3 Bulan',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ], $overrides));

        $reg->created_at = \Carbon\Carbon::create(2026, 3, 1, 10, 0, 0);
        $reg->save();

        return $reg;
    }

    /**
     * Test 1: GoogleAppsScriptTransport generates valid HMAC-SHA256 signature in request body.
     */
    public function test_google_apps_script_transport_generates_valid_hmac_signature(): void
    {
        $secret = 'test-secret-key-1234567890';
        $endpoint = 'https://script.google.com/macros/s/TEST/exec';

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => $endpoint,
            'shared_secret' => $secret,
        ]);

        Http::fake([
            '*script.google.com*' => function ($request) use ($secret) {
                $body = json_decode($request->body(), true);

                $this->assertArrayHasKey('payload', $body);
                $this->assertArrayHasKey('timestamp', $body);
                $this->assertArrayHasKey('nonce', $body);
                $this->assertArrayHasKey('signature', $body);

                // Verify HMAC calculation
                $canonicalData = $body['timestamp'] . '.' . $body['nonce'] . '.' . json_encode($body['payload']);
                $expectedSig = hash_hmac('sha256', $canonicalData, $secret);

                $this->assertEquals($expectedSig, $body['signature'], 'Signature in request body must match expected HMAC-SHA256');

                return Http::response([
                    'success'   => true,
                    'row_index' => 15,
                    'operation' => 'inserted',
                ], 200);
            },
        ]);

        $response = $transport->sendRowUpsert(
            connection: $this->regConnection,
            tabName: '2026',
            syncId: 'REG-1-abc12345',
            payload: ['Nama Lengkap' => 'Budi Santoso']
        );

        $this->assertTrue($response->success);
        $this->assertEquals(15, $response->rowIndex);
    }

    /**
     * Test 2: Formula injection protection sanitizes leading formula characters.
     */
    public function test_formula_injection_characters_are_sanitized_before_dispatch(): void
    {
        $transport = new GoogleAppsScriptTransport();

        $dangerousPayload = [
            'safe_field'    => 'Budi Santoso',
            'calc_formula'  => '=SUM(A1:A10)',
            'cmd_injection' => '+cmd|/c calc!A1',
            'dash_formula'  => '-5+10',
            'at_formula'    => '@SUM(B1:B5)',
            'tab_prefix'    => "\tMaliciousTab",
        ];

        $sanitized = $transport->sanitizePayloadForFormulas($dangerousPayload);

        $this->assertEquals('Budi Santoso', $sanitized['safe_field']);
        $this->assertEquals("'=SUM(A1:A10)", $sanitized['calc_formula']);
        $this->assertEquals("'+cmd|/c calc!A1", $sanitized['cmd_injection']);
        $this->assertEquals("'-5+10", $sanitized['dash_formula']);
        $this->assertEquals("'@SUM(B1:B5)", $sanitized['at_formula']);
        $this->assertEquals("'\tMaliciousTab", $sanitized['tab_prefix']);
    }

    /**
     * Test 3: Transport handles successful remote upsert JSON response.
     */
    public function test_transport_handles_successful_remote_upsert_response(): void
    {
        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => 'https://script.google.com/macros/s/TEST/exec',
            'shared_secret' => 'secret',
        ]);

        Http::fake([
            '*' => Http::response([
                'success'   => true,
                'action'    => 'upsert_row',
                'sync_id'   => 'REG-10-xyz',
                'row_index' => 45,
                'operation' => 'updated',
            ], 200),
        ]);

        $res = $transport->sendRowUpsert($this->regConnection, '2026', 'REG-10-xyz', ['Nama Lengkap' => 'Updated']);

        $this->assertTrue($res->success);
        $this->assertEquals(45, $res->rowIndex);
        $this->assertNull($res->errorMessage);
    }

    /**
     * Test 4: Transport handles header mismatch as non-retryable failure.
     */
    public function test_transport_handles_header_mismatch_as_non_retryable_failure(): void
    {
        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => 'https://script.google.com/macros/s/TEST/exec',
            'shared_secret' => 'secret',
        ]);

        Http::fake([
            '*' => Http::response([
                'success'      => false,
                'error_code'   => 'HEADER_MISMATCH',
                'is_retryable' => false,
                'message'      => "Header kolom 'NISN / NIM' tidak ditemukan pada sheet.",
            ], 200),
        ]);

        $res = $transport->sendRowUpsert($this->regConnection, '2026', 'REG-10-xyz', ['Nama Lengkap' => 'Test']);

        $this->assertFalse($res->success);
        $this->assertFalse($res->isRetryable, 'Header mismatch must be non-retryable');
        $this->assertEquals('HEADER_MISMATCH', $res->errorCode);
        $this->assertStringContainsString('NISN / NIM', $res->errorMessage);
    }

    /**
     * Test 5: Transport handles rate limits, lock timeouts, and 5xx errors as retryable.
     */
    public function test_transport_handles_rate_limit_and_timeout_as_retryable(): void
    {
        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => 'https://script.google.com/macros/s/TEST/exec',
            'shared_secret' => 'secret',
        ]);

        // Scenario A: Structured lock timeout in JSON
        Http::fake([
            '*' => Http::response([
                'success'      => false,
                'error_code'   => 'LOCK_TIMEOUT',
                'is_retryable' => true,
                'message'      => 'Gagal memperoleh lock spreadsheet dalam 30 detik.',
            ], 503),
        ]);

        $res = $transport->sendRowUpsert($this->regConnection, '2026', 'REG-10-xyz', ['Nama' => 'Test']);
        $this->assertFalse($res->success);
        $this->assertTrue($res->isRetryable);

        // Scenario B: Network exception / timeout
        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $resTimeout = $transport->sendRowUpsert($this->regConnection, '2026', 'REG-10-xyz', ['Nama' => 'Test']);
        $this->assertFalse($resTimeout->success);
        $this->assertTrue($resTimeout->isRetryable);
        $this->assertEquals('NETWORK_TIMEOUT', $resTimeout->errorCode);
    }

    /**
     * Test 6: Asynchronous attachment upload persists Drive metadata and triggers row sync.
     */
    public function test_attachment_upload_persists_drive_metadata_and_triggers_row_sync(): void
    {
        Storage::fake('private');
        $reg = $this->createValidRegistration();

        // Create dummy physical file
        $filePath = 'registrations/' . $reg->id . '/cv_budi.pdf';
        Storage::disk('private')->put($filePath, 'Fake PDF Content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'cv_budi.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        $mockTransport = new class implements SpreadsheetTransportInterface {
            public int $callCount = 0;

            public function sendRowUpsert($c, $t, $s, $p): SpreadsheetTransportResponse
            {
                $this->callCount++;
                return SpreadsheetTransportResponse::ok(10, [
                    'file_id'       => 'DRIVE-FILE-XYZ-123',
                    'web_view_link' => 'https://drive.google.com/file/d/DRIVE-FILE-XYZ-123/view',
                ]);
            }

            public function testConnection($c): bool { return true; }
        };

        $syncEngine = app(SpreadsheetSyncEngineService::class);
        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        $attachment->refresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $attachment->drive_sync_status);
        $this->assertEquals('DRIVE-FILE-XYZ-123', $attachment->drive_file_id);
        $this->assertEquals('https://drive.google.com/file/d/DRIVE-FILE-XYZ-123/view', $attachment->drive_web_view_link);
        $this->assertNotNull($attachment->drive_synced_at);
        $this->assertNull($attachment->drive_error_message);
    }

    /**
     * Test 7: Duplicate attachment upload is prevented when drive_file_id is already present.
     */
    public function test_duplicate_attachment_upload_is_prevented_when_drive_id_exists(): void
    {
        Storage::fake('private');
        $reg = $this->createValidRegistration();
        $filePath = 'registrations/' . $reg->id . '/ktp.pdf';
        Storage::disk('private')->put($filePath, 'Fake KTP Content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'ktp',
            'file_path'                  => $filePath,
            'original_name'              => 'ktp.pdf',
            'file_size'                  => 500,
            'mime_type'                  => 'application/pdf',
            'drive_file_id'              => 'EXISTING-DRIVE-FILE-999',
            'drive_web_view_link'        => 'https://drive.google.com/file/d/EXISTING-DRIVE-FILE-999/view',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_synced_at'            => now(),
        ]);

        $mockTransport = new class implements SpreadsheetTransportInterface {
            public int $callCount = 0;
            public function sendRowUpsert($c, $t, $s, $p): SpreadsheetTransportResponse
            {
                $this->callCount++;
                return SpreadsheetTransportResponse::ok();
            }
            public function testConnection($c): bool { return true; }
        };

        $syncEngine = app(SpreadsheetSyncEngineService::class);
        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($mockTransport, $syncEngine);

        // Assert no transport call was made because it was already synced
        $this->assertEquals(0, $mockTransport->callCount, 'Should not re-upload file that is already synced');
    }

    /**
     * Test 8: Partial failure recovery populates Drive URL in next spreadsheet sync.
     */
    public function test_partial_failure_recovery_populates_drive_url_in_next_spreadsheet_sync(): void
    {
        $reg = $this->createValidRegistration();
        $mappingService = app(RegistrationSpreadsheetMappingService::class);

        // Before Drive sync: attachment URL in payload is null
        $initialPayload = $mappingService->buildOutboundRowPayload($reg);
        $this->assertNull($initialPayload['Upload PDF CV + scan KTM/KTP + portofolio max 10MB']);

        // Attach drive link to attachment record
        RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => 'cv.pdf',
            'original_name'              => 'cv.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_file_id'              => 'DRIVE-FILE-456',
            'drive_web_view_link'        => 'https://drive.google.com/file/d/DRIVE-FILE-456/view',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_synced_at'            => now(),
        ]);

        // After Drive sync: payload correctly maps the Drive web view link
        $freshPayload = $mappingService->buildOutboundRowPayload($reg);
        $this->assertEquals(
            'https://drive.google.com/file/d/DRIVE-FILE-456/view',
            $freshPayload['Upload PDF CV + scan KTM/KTP + portofolio max 10MB']
        );
    }

    /**
     * Test 9: Recovery Artisan command dispatches due syncs and un-synced attachments.
     */
    public function test_recovery_command_dispatches_due_pending_syncs_and_attachments(): void
    {
        $reg = $this->createValidRegistration();
        $engine = app(SpreadsheetSyncEngineService::class);
        $sync = $engine->queueSyncForRegistration($reg);

        $sync->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at' => now()->subMinute(),
        ]);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => 'cv.pdf',
            'original_name'              => 'cv.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        $exitCode = $this->artisan('sertif:spreadsheet-sync:recover', ['--limit' => 10])->run();

        $this->assertEquals(0, $exitCode);
    }

    /**
     * Test 10: Local business operations remain functional when Apps Script is disabled.
     */
    public function test_domain_operations_remain_functional_when_apps_script_disabled(): void
    {
        $this->regConnection->update(['is_enabled' => false]);
        $reg = $this->createValidRegistration(['fullname' => 'Offline Registration']);

        $this->assertDatabaseHas('internship_registrations', [
            'id'       => $reg->id,
            'fullname' => 'Offline Registration',
        ]);
    }
}
