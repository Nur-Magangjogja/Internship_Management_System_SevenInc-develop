<?php

namespace Tests\Feature;

use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\City;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateOnlyRemoteSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pemagang;
    protected SpreadsheetConnection $regConnection;
    protected MockSpreadsheetTransport $mockTransport;
    protected RegistrationSpreadsheetMappingService $mappingService;
    protected SpreadsheetSyncEngineService $syncEngine;
    protected RegistrationLifecycleService $lifecycleService;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $this->seed(Spreadsheet2026SchemaSeeder::class);

        $this->regConnection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Spreadsheet Pendaftaran Magang',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test-reg-sheet/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
                'spreadsheet_id'           => 'test-reg-sheet',
            ]
        );
        $this->regConnection->update([
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test-reg-sheet/edit',
            'is_enabled'               => true,
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
            'spreadsheet_id'           => 'test-reg-sheet',
        ]);

        $this->admin = User::create([
            'name'      => 'Admin SertIF',
            'email'     => 'admin_' . uniqid() . '@sertif.test',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Calon Pemagang',
            'email'     => 'pemagang_' . uniqid() . '@sertif.test',
            'password'  => bcrypt('password123'),
            'role'      => 'user',
            'is_banned' => false,
        ]);

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->mappingService = app(RegistrationSpreadsheetMappingService::class);
        $this->syncEngine = app(SpreadsheetSyncEngineService::class);
        $this->lifecycleService = app(RegistrationLifecycleService::class);
    }

    protected function createRegistration(array $attributes = []): InternshipRegistration
    {
        $institution = Institution::firstOrCreate(
            ['name' => 'Universitas Gadjah Mada'],
            ['type' => 'universitas']
        );
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $institution->id, 'name' => 'Fakultas MIPA']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Informatika']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        $reg = InternshipRegistration::create(array_merge([
            'user_id'                  => $this->pemagang->id,
            'public_uuid'              => (string) Str::uuid(),
            'fullname'                 => 'Budi Test ' . uniqid(),
            'gender'                   => 'L',
            'phone_number'             => '08123456789',
            'email'                    => 'budi_' . uniqid() . '@test.com',
            'student_id'               => '12345678',
            'institution_id'           => $institution->id,
            'faculty_id'               => $faculty->id,
            'study_program_id'         => $prodi->id,
            'institution_name'         => $institution->name,
            'faculty_name'             => $faculty->name,
            'study_program'            => $prodi->name,
            'current_city'             => $city->name,
            'internship_type'          => 'Mandiri',
            'current_status'           => 'Mahasiswa',
            'wfo_readiness'            => 'Siap WFO',
            'schedule_shift_readiness' => 'Siap',
            'has_laptop'               => true,
            'start_date'               => Carbon::now()->addDays(7)->toDateString(),
            'planned_duration'         => '3 bulan',
            'internship_reason'        => 'Belajar',
            'internship_interest'      => 'Web Development',
            'internship_status'        => InternshipRegistration::STATUS_NEW,
            'is_draft'                 => false,
            'created_at'               => Carbon::create(2026, 2, 1, 10, 0, 0),
        ], $attributes));

        return $reg;
    }

    /**
     * 1. GoogleAppsScriptTransport::sendRowUpdateOnly sends action update_row with HMAC signature.
     */
    public function test_transport_sends_action_update_row_with_hmac_signature(): void
    {
        $secret = 'test-update-secret-xyz';
        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
            'shared_secret' => $secret,
        ]);

        Http::fake([
            'https://script.google.com/*' => function ($request) use ($secret) {
                $body = json_decode($request->body(), true);

                $this->assertIsArray($body);
                $this->assertArrayHasKey('payload', $body);
                $this->assertArrayHasKey('timestamp', $body);
                $this->assertArrayHasKey('nonce', $body);
                $this->assertArrayHasKey('signature', $body);

                // Verify inner payload
                $payload = $body['payload'];
                $this->assertEquals('update_row', $payload['action']);
                $this->assertEquals('registration', $payload['connection_key']);
                $this->assertEquals('2026', $payload['tab_name']);
                $this->assertEquals('REG-100-test', $payload['sync_id']);

                // Verify HMAC calculation
                $canonicalData = $body['timestamp'] . '.' . $body['nonce'] . '.' . json_encode($body['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $expectedSig = hash_hmac('sha256', $canonicalData, $secret);
                $this->assertEquals($expectedSig, $body['signature']);

                return Http::response([
                    'success'   => true,
                    'action'    => 'update_row',
                    'sync_id'   => 'REG-100-test',
                    'row_index' => 17,
                    'operation' => 'updated',
                ], 200);
            },
        ]);

        $response = $transport->sendRowUpdateOnly(
            connection: $this->regConnection,
            tabName: '2026',
            syncId: 'REG-100-test',
            payload: ['Nama Lengkap' => 'Budi Terupdate', 'SERTIF_SYNC_ID' => 'REG-100-test']
        );

        $this->assertTrue($response->success);
        $this->assertEquals(17, $response->rowIndex);
    }

    /**
     * 2. Exact 1 Sync ID on remote sheet -> update-only succeeds, rowIndex recorded, status SYNCED.
     */
    public function test_update_only_success_when_exact_one_row_exists_on_remote_sheet(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACTIVE, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 22,
            'last_synced_at'   => now()->subDays(2),
            'payload_checksum' => 'old_checksum_val',
        ]);

        // Pre-populate mock remote sheet with existing row for this sync ID
        $this->mockTransport->setRemoteRow('2026', $syncRecord->external_sync_id, [
            'Nama Lengkap' => $reg->fullname,
            'SERTIF_SYNC_ID' => $syncRecord->external_sync_id,
        ]);
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026'));

        // Transition to exited
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Selesai lebih awal');

        $this->assertTrue($this->syncEngine->requiresUpdateOnly($syncRecord));

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertTrue($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $syncRecord->sync_status);
        $this->assertNotNull($syncRecord->last_synced_at);
        $this->assertNotNull($syncRecord->payload_checksum);
        $this->assertNull($syncRecord->error_message);

        // Verify remote sheet count was NOT increased (zero insert)
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026'));
        $this->assertCount(1, $this->mockTransport->callHistory);
        $this->assertEquals('update_row', $this->mockTransport->callHistory[0]['action']);
    }

    /**
     * 3. Sync ID not found on remote sheet -> ROW_NOT_FOUND (404), zero insert, terminal fail (no retry).
     */
    public function test_update_only_row_not_found_causes_zero_insert_and_terminal_failure(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACTIVE, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 18,
            'last_synced_at'   => now()->subDays(1),
            'payload_checksum' => 'initial_checksum',
        ]);

        // DO NOT add row to mock remote sheet (simulate row missing / deleted remotely)
        $this->assertEquals(0, $this->mockTransport->getRemoteRowCount('2026'));

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Mundur');

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        // Terminal non-retryable failure
        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertNull($syncRecord->next_retry_at);
        $this->assertStringContainsString('ROW_NOT_FOUND', (string) $syncRecord->error_message);

        // Zero insert: Remote sheet remains empty!
        $this->assertEquals(0, $this->mockTransport->getRemoteRowCount('2026'));
        $this->assertCount(1, $this->mockTransport->callHistory);
        $this->assertEquals('update_row', $this->mockTransport->callHistory[0]['action']);
    }

    /**
     * 4. Duplicate Sync ID detected on remote sheet -> DUPLICATE_SYNC_ID (409) fail-closed terminal failure.
     */
    public function test_update_only_duplicate_sync_id_causes_fail_closed_terminal_failure(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 15,
            'last_synced_at'   => now()->subHours(5),
            'payload_checksum' => 'checksum_dup_test',
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true);

        // Force transport response to simulate remote detecting multiple rows with same Sync ID
        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: "Terdeteksi lebih dari satu baris (2) dengan SERTIF_SYNC_ID '{$syncRecord->external_sync_id}'. Operasi update-only dihentikan fail-closed.",
            errorCode: 'DUPLICATE_SYNC_ID',
            isRetryable: false
        );

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertNull($syncRecord->next_retry_at); // Not scheduled for retry
        $this->assertStringContainsString('DUPLICATE_SYNC_ID', (string) $syncRecord->error_message);
    }

    /**
     * 5. Identity mismatch (remote registration ID differs from payload) -> IDENTITY_MISMATCH (409).
     */
    public function test_update_only_identity_mismatch_causes_fail_closed_terminal_failure(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 20,
            'last_synced_at'   => now()->subHours(2),
            'payload_checksum' => 'checksum_id_mismatch',
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: "Ketidakcocokan identitas registrasi: baris remote memiliki SERTIF_REGISTRATION_ID '999', tetapi payload mengirimkan '{$reg->id}'.",
            errorCode: 'IDENTITY_MISMATCH',
            isRetryable: false
        );

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertNull($syncRecord->next_retry_at);
        $this->assertStringContainsString('IDENTITY_MISMATCH', (string) $syncRecord->error_message);
    }

    /**
     * 6. Schema mismatch (e.g. column count / header order invalid) -> SCHEMA_MISMATCH (400) terminal failure.
     */
    public function test_update_only_schema_mismatch_causes_terminal_failure(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 25,
            'last_synced_at'   => now()->subDays(1),
            'payload_checksum' => 'checksum_schema',
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Jumlah kolom spreadsheet tidak sesuai kontrak: ditemukan 28 kolom (harus tepat 29 kolom).',
            errorCode: 'SCHEMA_MISMATCH',
            isRetryable: false
        );

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertNull($syncRecord->next_retry_at);
        $this->assertStringContainsString('SCHEMA_MISMATCH', (string) $syncRecord->error_message);
    }

    /**
     * 7. Invalid auth / HMAC signature -> AUTH_FAILED (401) terminal failure.
     */
    public function test_update_only_auth_failure_causes_terminal_failure(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 26,
            'last_synced_at'   => now()->subDays(1),
            'payload_checksum' => 'checksum_auth',
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Autentikasi Apps Script ditolak (401/403). Periksa shared secret.',
            errorCode: 'AUTH_FAILED',
            isRetryable: false
        );

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertNull($syncRecord->next_retry_at);
        $this->assertStringContainsString('AUTH_FAILED', (string) $syncRecord->error_message);
    }

    /**
     * 8. Pre-acceptance rejected registration is strictly ineligible (zero HTTP request).
     */
    public function test_pre_acceptance_rejected_registration_is_strictly_ineligible_zero_http(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);

        // Direct rejection by admin BEFORE any acceptance
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_REJECTED, isAdmin: true, reason: 'Kualifikasi belum sesuai');

        $this->assertFalse($this->mappingService->hasVerifiedAdminAcceptance($reg));
        $this->assertFalse($this->mappingService->isEligibleForSync($reg));

        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-stray-rej',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at'             => now(),
        ]);

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertStringContainsString('NOT_ELIGIBLE_UNACCEPTED', (string) $syncRecord->error_message);
        $this->assertEmpty($this->mockTransport->callHistory);
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * 9. Post-acceptance exited without verified prior sync fails closed (zero HTTP request).
     */
    public function test_post_acceptance_exited_without_prior_sync_fails_closed_zero_http(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Mengundurkan diri');

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);
        $this->assertNull($syncRecord->last_synced_at);

        $this->assertTrue($this->mappingService->hasVerifiedAdminAcceptance($reg));
        $this->assertFalse($this->mappingService->hasVerifiedPriorSync($reg, $syncRecord));
        $this->assertFalse($this->mappingService->isEligibleForSync($reg, $syncRecord));

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertStringContainsString('NOT_ELIGIBLE_UPDATE_ONLY_HELD', (string) $syncRecord->error_message);
        $this->assertEmpty($this->mockTransport->callHistory);
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * 10. Post-acceptance exited with verified prior sync dispatches update-only successfully.
     */
    public function test_post_acceptance_exited_with_prior_sync_dispatches_update_only_successfully(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 30,
            'last_synced_at'   => now()->subDays(3),
            'payload_checksum' => 'checksum_prior_30',
        ]);

        $this->mockTransport->setRemoteRow('2026', $syncRecord->external_sync_id, [
            'Nama Lengkap'   => $reg->fullname,
            'SERTIF_SYNC_ID' => $syncRecord->external_sync_id,
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Selesai');

        $this->assertTrue($this->mappingService->isEligibleForSync($reg, $syncRecord));
        $this->assertTrue($this->syncEngine->requiresUpdateOnly($syncRecord));

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertTrue($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $syncRecord->sync_status);
        $this->assertCount(1, $this->mockTransport->callHistory);
        $this->assertEquals('update_row', $this->mockTransport->callHistory[0]['action']);
        $this->assertEquals($syncRecord->external_sync_id, $this->mockTransport->callHistory[0]['sync_id']);
    }

    /**
     * 11. Post-acceptance cancelled (rejected) with verified prior sync dispatches update-only successfully.
     */
    public function test_post_acceptance_cancelled_with_prior_sync_dispatches_update_only_successfully(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 35,
            'last_synced_at'   => now()->subDay(),
            'payload_checksum' => 'checksum_prior_35',
        ]);

        $this->mockTransport->setRemoteRow('2026', $syncRecord->external_sync_id, [
            'Nama Lengkap'   => $reg->fullname,
            'SERTIF_SYNC_ID' => $syncRecord->external_sync_id,
        ]);

        // Post-acceptance cancellation (status transitions to rejected)
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_REJECTED, isAdmin: true, reason: 'Pembatalan kerjasama');

        $this->assertTrue($this->mappingService->isEligibleForSync($reg, $syncRecord));
        $this->assertTrue($this->syncEngine->requiresUpdateOnly($syncRecord));

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertTrue($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $syncRecord->sync_status);
        $this->assertCount(1, $this->mockTransport->callHistory);
        $this->assertEquals('update_row', $this->mockTransport->callHistory[0]['action']);
    }

    /**
     * 12. Transient HTTP timeout retries with backoff without duplicating rows.
     */
    public function test_transient_http_timeout_retries_with_backoff_without_duplicating_rows(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 40,
            'last_synced_at'   => now()->subDays(2),
            'payload_checksum' => 'checksum_timeout_test',
        ]);

        $this->mockTransport->setRemoteRow('2026', $syncRecord->external_sync_id, [
            'Nama Lengkap'   => $reg->fullname,
            'SERTIF_SYNC_ID' => $syncRecord->external_sync_id,
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true);

        // Simulate transient network timeout
        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'cURL error 28: Operation timed out',
            errorCode: 'NETWORK_TIMEOUT',
            isRetryable: true
        );

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        // Must be in RETRYING state with next_retry_at scheduled (not terminal FAILED)
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $syncRecord->sync_status);
        $this->assertEquals(1, $syncRecord->attempt_count);
        $this->assertNotNull($syncRecord->next_retry_at);
        $this->assertTrue($syncRecord->next_retry_at->isFuture());

        // Zero rows added remotely
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026'));
    }

    /**
     * 13. Concurrency fencing token protection: stale lease cannot overwrite sync record.
     */
    public function test_concurrency_fencing_token_protects_update_only_sync_record(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 45,
            'last_synced_at'   => now()->subDays(1),
            'payload_checksum' => 'checksum_fencing',
        ]);

        $this->mockTransport->setRemoteRow('2026', $syncRecord->external_sync_id, [
            'Nama Lengkap'   => $reg->fullname,
            'SERTIF_SYNC_ID' => $syncRecord->external_sync_id,
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true);

        // Simulate another worker overtaking the lease during mid-flight
        $staleFenceToken = (string) Str::uuid();
        $competingFenceToken = (string) Str::uuid();

        // Pre-claim with competing fence
        $syncRecord->update([
            'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $competingFenceToken,
        ]);

        // Attempt to process with stale preClaimedFence
        $result = $this->syncEngine->processSyncRecord(
            $syncRecord,
            $this->mockTransport,
            force: false,
            preClaimedFence: $staleFenceToken
        );

        $this->assertFalse($result);
        $syncRecord->refresh();

        // Lock remains held by competing worker
        $this->assertEquals($competingFenceToken, $syncRecord->locked_by);
    }

    /**
     * 14. Standard accepted registration dispatches sendRowUpsert (not update-only).
     */
    public function test_standard_accepted_registration_dispatches_upsert_row_not_update_only(): void
    {
        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);

        // Fresh accepted registration requires upsert (not update-only)
        $this->assertFalse($this->syncEngine->requiresUpdateOnly($syncRecord));

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::success(
            rowIndex: 55,
            context: ['action' => 'upsert_row', 'operation' => 'inserted']
        );

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertTrue($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $syncRecord->sync_status);
        $this->assertEquals(55, $syncRecord->row_index);

        // Verify transport dispatched upsert_row (NOT update_row)
        $this->assertCount(1, $this->mockTransport->callHistory);
        $this->assertEquals('upsert_row', $this->mockTransport->callHistory[0]['action']);
    }
}
