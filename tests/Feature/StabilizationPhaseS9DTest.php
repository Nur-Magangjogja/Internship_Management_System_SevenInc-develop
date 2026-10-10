<?php

namespace Tests\Feature;

use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\City;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FollowUpService;
use App\Services\RegistrationLifecycleService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\NullSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StabilizationPhaseS9DTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $hrStaff;
    protected User $pemagang;
    protected Institution $institution;
    protected InstitutionContact $contact;
    protected SpreadsheetConnection $regConnection;
    protected SpreadsheetConnection $followupConnection;
    protected MockSpreadsheetTransport $mockTransport;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $this->seed(Spreadsheet2026SchemaSeeder::class);
        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);

        $this->unique = uniqid('s9d_', true);

        $this->admin = User::create([
            'name'      => 'Admin S9D ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR S9D ' . substr($this->unique, 0, 6),
            'email'     => 'hr_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang S9D ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas S9D ' . substr($this->unique, 0, 6),
            'is_active' => true,
        ]);

        $this->contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Dosen Pembimbing S9D',
            'phone'          => '081234567890',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $this->regConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        $this->regConnection->update([
            'is_enabled'       => true,
            'spreadsheet_url'  => 'https://docs.google.com/spreadsheets/d/test-reg-id/edit',
            'spreadsheet_id'   => 'test-reg-id',
        ]);

        $this->followupConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();
        $this->followupConnection->update([
            'is_enabled'       => true,
            'spreadsheet_url'  => 'https://docs.google.com/spreadsheets/d/test-fwu-id/edit',
            'spreadsheet_id'   => 'test-fwu-id',
        ]);

        $this->mockTransport = new MockSpreadsheetTransport();
    }

    private function createValidRegistration(array $overrides = []): InternshipRegistration
    {
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $this->institution->id, 'name' => 'Fakultas MIPA S9D']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Informatika S9D']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        $reg = InternshipRegistration::create(array_merge([
            'user_id'           => $this->pemagang->id,
            'fullname'          => 'Budi Santoso S9D',
            'student_id'        => '22/999999/PA/11111',
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
     * Test 1: spreadsheet_row_syncs table enforces unique constraint per entity and connection.
     */
    public function test_spreadsheet_row_syncs_table_has_correct_constraints(): void
    {
        $reg = $this->createValidRegistration();

        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => 'REG-' . $reg->id . '-uniq1',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Duplicate syncable record on same connection must be rejected by unique index
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => 'REG-' . $reg->id . '-uniq2',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
        ]);
    }

    /**
     * Test 2: queueSyncForRegistration creates stable sync ID and sets target year tab.
     */
    public function test_queue_sync_creates_stable_sync_id_for_registration(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();

        $sync = $engine->queueSyncForRegistration($reg);

        $this->assertNotNull($sync);
        $this->assertStringStartsWith("REG-{$reg->id}-", $sync->external_sync_id);
        $this->assertEquals('2026', $sync->target_sheet_tab);
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $sync->sync_status);
        $this->assertEquals($this->regConnection->id, $sync->spreadsheet_connection_id);
    }

    /**
     * Test 3: queueSyncForFollowUpCycle creates stable sync ID for follow-up cycle on single tab.
     */
    public function test_queue_sync_creates_stable_sync_id_for_followup_cycle(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);

        $sync = $engine->queueSyncForFollowUpCycle($cycle);

        $this->assertNotNull($sync);
        $this->assertStringStartsWith("FWU-{$cycle->id}-", $sync->external_sync_id);
        $this->assertEquals('Follow Up Guru Dosen', $sync->target_sheet_tab);
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $sync->sync_status);
    }

    /**
     * Test 4: Stable sync ID remains identical across updates, re-queues, and retries.
     */
    public function test_stable_sync_id_remains_identical_across_updates_and_retries(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();

        $sync1 = $engine->queueSyncForRegistration($reg);
        $initialSyncId = $sync1->external_sync_id;

        // Simulate status update on registration
        $reg->internship_status = InternshipRegistration::STATUS_ACCEPTED;
        $reg->save();

        $sync2 = $engine->queueSyncForRegistration($reg);

        $this->assertEquals($sync1->id, $sync2->id, 'Must reuse the exact same database row sync record');
        $this->assertEquals($initialSyncId, $sync2->external_sync_id, 'SERTIF_SYNC_ID must be permanently stable');
    }

    /**
     * Test 5: Draft registration is strictly rejected from sync queue.
     */
    public function test_draft_registration_is_strictly_rejected_from_sync_queue(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $faculty = Faculty::firstOrCreate(['institution_id' => $this->institution->id, 'name' => 'Fakultas Draft']);
        $prodi = StudyProgram::firstOrCreate(['faculty_id' => $faculty->id, 'name' => 'Prodi Draft']);

        $draftReg = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'fullname'          => 'Draft Applicant',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $prodi->id,
            'internship_status' => InternshipRegistration::STATUS_NEW,
        ]);

        $sync = $engine->queueSyncForRegistration($draftReg);
        $this->assertNull($sync, 'Draft registrations must return null and not create row syncs');
    }

    /**
     * Test 6: Successful sync transitions status to synced and records checksum & row index.
     */
    public function test_successful_sync_transitions_to_synced_and_records_checksum(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        $success = $engine->processSyncRecord($sync, $this->mockTransport);

        $this->assertTrue($success);
        $sync->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $sync->sync_status);
        $this->assertNotNull($sync->payload_checksum);
        $this->assertNotNull($sync->last_synced_at);
        $this->assertNull($sync->locked_at);
        $this->assertNull($sync->locked_by);
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026'));

        $remoteRow = $this->mockTransport->getRemoteRow('2026', $sync->external_sync_id);
        $this->assertEquals($sync->external_sync_id, $remoteRow['SERTIF_SYNC_ID']);
        $this->assertEquals('synced', $remoteRow['SERTIF_SYNC_STATUS']);
    }

    /**
     * Test 7: Sync with unchanged checksum is an idempotent no-op without extra remote calls.
     */
    public function test_sync_with_unchanged_checksum_is_idempotent_no_op(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        // First execution -> success
        $engine->processSyncRecord($sync, $this->mockTransport);
        $initialCallCount = $this->mockTransport->callCount;
        $this->assertEquals(1, $initialCallCount);

        // Second execution without domain changes -> idempotent no-op skip
        $secondSuccess = $engine->processSyncRecord($sync, $this->mockTransport);
        $this->assertTrue($secondSuccess);
        $this->assertEquals($initialCallCount, $this->mockTransport->callCount, 'Should not dispatch remote call when checksum is identical');
    }

    /**
     * Test 8: Transient failure increments attempt count and transitions to retrying with backoff.
     */
    public function test_transient_failure_increments_attempt_and_transitions_to_retrying(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Google API rate limit exceeded (503)',
            errorCode: 'RATE_LIMIT',
            isRetryable: true
        );

        $result = $engine->processSyncRecord($sync, $this->mockTransport);

        $this->assertFalse($result);
        $sync->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $sync->sync_status);
        $this->assertEquals(1, $sync->attempt_count);
        $this->assertNotNull($sync->next_retry_at);
        $this->assertStringContainsString('503', $sync->error_message);
        $this->assertNull($sync->locked_at);
    }

    /**
     * Test 9: Max attempts exceeded transitions status to failed.
     */
    public function test_max_attempts_exceeded_transitions_to_failed(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);
        $sync->update(['attempt_count' => 4, 'max_attempts' => 5]);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Internal Google Error (500)',
            errorCode: 'SERVER_ERROR',
            isRetryable: true
        );

        $result = $engine->processSyncRecord($sync, $this->mockTransport);

        $this->assertFalse($result);
        $sync->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $sync->sync_status);
        $this->assertEquals(5, $sync->attempt_count);
        $this->assertNull($sync->next_retry_at);
    }

    /**
     * Test 10: Disabled connection fails gracefully without fake success.
     */
    public function test_disabled_connection_fails_gracefully_without_fake_success(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        // Disable connection
        $this->regConnection->update(['is_enabled' => false]);

        $result = $engine->processSyncRecord($sync, $this->mockTransport);

        $this->assertFalse($result);
        $sync->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $sync->sync_status);
        $this->assertStringContainsString('dinonaktifkan', $sync->error_message);
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * Test 11: Mandatory Guardrail 3 — Simulated network timeout after remote success is idempotent on retry.
     */
    public function test_simulated_network_timeout_after_google_success_is_idempotent_on_retry(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        // 1. Simulate Google remote wrote successfully, but HTTP connection timed out returning to SertIF
        $this->mockTransport->simulateTimeoutAfterRemoteSuccess = true;

        $firstAttempt = $engine->processSyncRecord($sync, $this->mockTransport);
        $this->assertFalse($firstAttempt);

        $sync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $sync->sync_status);
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026'), 'Remote has the row written');

        // 2. Retry with same transport (network restored)
        $this->mockTransport->simulateTimeoutAfterRemoteSuccess = false;
        $secondAttempt = $engine->processSyncRecord($sync, $this->mockTransport);

        $this->assertTrue($secondAttempt);
        $sync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $sync->sync_status);
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026'), 'Idempotent upsert by sync_id must NOT create duplicate remote row');
    }

    /**
     * Test 12: Mandatory Guardrail 1 — Concurrent worker lease lock prevents duplicate processing.
     */
    public function test_concurrent_worker_lock_contention_prevents_duplicate_processing(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        // Manually lock row as actively processing by worker A
        $sync->update([
            'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => 'worker-token-alpha',
        ]);

        // Worker B attempts to process
        $resultWorkerB = $engine->processSyncRecord($sync, $this->mockTransport);

        $this->assertFalse($resultWorkerB, 'Worker B must be rejected while Worker A holds active lease');
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * Test 13: Mandatory Guardrail 1 — Mid-flight source mutation aborts stale sync and requeues latest snapshot as pending.
     */
    public function test_mid_flight_source_mutation_aborts_stale_sync_and_requeues_as_pending(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        // Create a custom transport that simulates mutation happening on database in parallel during HTTP transport
        $mutatingTransport = new class($reg) extends MockSpreadsheetTransport {
            public function __construct(private InternshipRegistration $registration) {}

            public function sendRowUpsert($connection, $tabName, $syncId, $payload): SpreadsheetTransportResponse {
                // Mutate the registration in database during in-flight transport
                $this->registration->fullname = 'Budi Santoso (Updated Mid Flight)';
                $this->registration->internship_status = InternshipRegistration::STATUS_ACCEPTED;
                $this->registration->save();

                return SpreadsheetTransportResponse::success(rowIndex: 5);
            }
        };

        $result = $engine->processSyncRecord($sync, $mutatingTransport);

        $this->assertFalse($result, 'Must detect mid-flight mutation and abort stale synced status');
        $sync->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $sync->sync_status, 'Must reset to pending to dispatch new snapshot');
        $this->assertStringContainsString('pembaruan saat sinkronisasi', $sync->error_message);
    }

    /**
     * Test 14: Mandatory Guardrail 2 — Recovery scanner dispatches due pending syncs.
     */
    public function test_recovery_scanner_dispatches_due_pending_syncs(): void
    {
        Queue::fake();

        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        $sync->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at' => now()->subMinute(),
            'locked_at'     => null,
        ]);

        $dispatchedCount = $engine->scanAndDispatchPendingSyncs();

        $this->assertGreaterThanOrEqual(1, $dispatchedCount);
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class);
    }

    /**
     * Test 15: Admin can trigger manual retry and non-admin is forbidden.
     */
    public function test_admin_can_trigger_manual_retry_and_non_admin_forbidden(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        $sync->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_FAILED,
            'error_message' => 'Simulated Failure',
        ]);

        // 1. Admin triggers retry -> 302 redirect with success
        $response = $this->actingAs($this->admin)->post(
            route('admin.spreadsheet-connections.retry-row', [$this->regConnection, $sync])
        );
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 2. HR Staff -> 403 Forbidden
        $this->actingAs($this->hrStaff)->postJson(
            route('admin.spreadsheet-connections.retry-row', [$this->regConnection, $sync])
        )->assertStatus(403);

        // 3. Pemagang -> 403 Forbidden
        $this->actingAs($this->pemagang)->postJson(
            route('admin.spreadsheet-connections.retry-row', [$this->regConnection, $sync])
        )->assertStatus(403);
    }

    /**
     * Test 16: Admin can trigger retry-all-failed batch on connection.
     */
    public function test_admin_can_trigger_retry_all_failed_batch(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $reg = $this->createValidRegistration();
        $sync = $engine->queueSyncForRegistration($reg);

        $sync->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_FAILED,
            'error_message' => 'Failed record 1',
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.spreadsheet-connections.retry-failed', $this->regConnection)
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $sync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $sync->sync_status);
        $this->assertEquals(0, $sync->attempt_count);
    }

    /**
     * Test 17: Mandatory Guardrail 2 — Domain operations succeed even when spreadsheet sync fails or is disabled.
     */
    public function test_domain_operations_succeed_even_when_spreadsheet_sync_fails(): void
    {
        $lifecycleService = app(RegistrationLifecycleService::class);
        $followUpService = app(FollowUpService::class);

        // 1. Registration status transition succeeds regardless of sync
        $reg = $this->createValidRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, true, 'Kualifikasi terpenuhi');

        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $reg->fresh()->internship_status);

        // 2. Follow-up cycle and attempt succeed regardless of sync
        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $cycle = $followUpService->claimLock($cycle, $this->hrStaff);
        $attempt = $followUpService->recordAttempt($cycle, [
            'channel' => 'whatsapp',
            'result'  => 'responded',
            'notes'   => 'Respons baik',
        ], $this->hrStaff);

        $this->assertNotNull($attempt);
        $this->assertEquals(1, $attempt->attempt_number);
    }
}
