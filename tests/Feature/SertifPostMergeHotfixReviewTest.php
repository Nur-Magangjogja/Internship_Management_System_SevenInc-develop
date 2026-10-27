<?php

namespace Tests\Feature;

use App\Jobs\ProcessIntegrationOutboxEventJob;
use App\Jobs\ProcessSidecarSyncJob;
use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\City;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\IntegrationOutboxEvent;
use App\Models\InternshipRegistration;
use App\Models\RegistrationContactLink;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSidecarSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetConnectionService;
use App\Services\SpreadsheetQueueReconciliationService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetSidecarTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SertifPostMergeHotfixReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $hrStaff;
    protected User $pemagang;
    protected SpreadsheetConnection $regConnection;
    protected SpreadsheetConnection $followUpConnection;
    protected MockSpreadsheetTransport $mockTransport;
    protected SpreadsheetSyncEngineService $syncEngine;
    protected RegistrationLifecycleService $lifecycleService;
    protected RegistrationSpreadsheetMappingService $mappingService;

    protected const TARGET_SHEET_A = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upmsA';
    protected const TARGET_SHEET_B = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upmsB';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([
            ProcessSpreadsheetRowSyncJob::class,
            ProcessSidecarSyncJob::class,
            ProcessIntegrationOutboxEventJob::class,
        ]);

        $this->seed(Spreadsheet2026SchemaSeeder::class);
        if (class_exists(SpreadsheetFollowUpSchemaSeeder::class)) {
            $this->seed(SpreadsheetFollowUpSchemaSeeder::class);
        }

        $this->regConnection = SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Spreadsheet Pendaftaran Magang',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/' . self::TARGET_SHEET_A . '/edit',
                'spreadsheet_id'           => self::TARGET_SHEET_A,
                'target_generation'        => 1,
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
            ]
        );

        $this->followUpConnection = SpreadsheetConnection::updateOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'display_name'             => 'Spreadsheet Follow Up Guru/Dosen',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/sheet-followup-A/edit',
                'spreadsheet_id'           => 'sheet-followup-A',
                'target_generation'        => 1,
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-followup-script-exec/exec',
            ]
        );

        $this->admin = User::create([
            'name'      => 'Admin SertIF Hotfix',
            'email'     => 'admin_hotfix_' . uniqid() . '@sertif.test',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR Staff Hotfix',
            'email'     => 'hr_hotfix_' . uniqid() . '@sertif.test',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_banned' => false,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang Hotfix',
            'email'     => 'pemagang_hotfix_' . uniqid() . '@sertif.test',
            'password'  => bcrypt('password123'),
            'role'      => 'user',
            'is_banned' => false,
        ]);

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);

        $this->mappingService = app(RegistrationSpreadsheetMappingService::class);
        $this->syncEngine = app(SpreadsheetSyncEngineService::class);
        $this->syncEngine->setTransport($this->mockTransport);
        $this->lifecycleService = app(RegistrationLifecycleService::class);
    }

    protected function createAcceptedRegistration(array $overrides = []): InternshipRegistration
    {
        $institution = Institution::firstOrCreate(
            ['name' => 'Universitas Gadjah Mada'],
            ['type' => 'universitas', 'is_active' => true]
        );
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $institution->id, 'name' => 'Fakultas Teknik']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknologi Informasi']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        $targetStatus = $overrides['internship_status'] ?? InternshipRegistration::STATUS_ACCEPTED;
        unset($overrides['internship_status']);

        $reg = InternshipRegistration::create(array_merge([
            'user_id'                  => $this->pemagang->id,
            'fullname'                 => 'Pemagang Uji ' . uniqid(),
            'gender'                   => 'L',
            'phone_number'             => '081234567890',
            'email'                    => 'intern_' . uniqid() . '@sertif.test',
            'student_id'               => '20260001',
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
            'division_interest'        => 'Backend Developer',
            'start_date'               => '2026-02-01',
            'end_date'                 => '2026-06-30',
            'internship_status'        => InternshipRegistration::STATUS_WAITING,
            'followup_status'          => null,
            'is_draft'                 => false,
            'Data_diri'                => 'Lengkap',
        ], $overrides));

        $reg->created_at = $overrides['created_at'] ?? Carbon::create(2026, 1, 15, 10, 0, 0);
        $reg->save();

        // Transition through official lifecycle service as admin so verified acceptance provenance exists
        $this->actingAs($this->admin);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, true, 'Diterima resmi oleh Admin');
        if ($targetStatus !== InternshipRegistration::STATUS_ACCEPTED) {
            $this->lifecycleService->transition($reg, $targetStatus, true, 'Transisi pengujian');
        }

        // Attach a custom answer so sidecar sync is also eligible whenever tested
        $reg->customAnswers()->create([
            'field_key'    => 'portfolio_url',
            'answer_value' => 'https://github.com/sertif-intern-' . $reg->id,
        ]);

        return $reg->fresh();
    }

    /**
     * Skenario 1: accepted -> active -> completed memperbarui baris Spreadsheet yang sama tanpa duplikat.
     */
    public function test_accepted_to_active_to_completed_updates_same_spreadsheet_row_without_duplicate(): void
    {
        $reg = $this->createAcceptedRegistration([
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        // 1. Initial sync on 'accepted'
        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertNotNull($rowSync);
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);

        $ok1 = $this->syncEngine->processSyncRecord($rowSync, $this->mockTransport);
        $this->assertTrue($ok1);

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $initialRowIndex = $rowSync->row_index;
        $this->assertNotNull($initialRowIndex);
        $initialChecksum = $rowSync->payload_checksum;
        $initialSyncId = $rowSync->external_sync_id;

        // 2. Transition accepted -> active
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACTIVE, true, 'Mulai aktif magang');
        $reg->refresh();
        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);

        // queueSyncForRegistration must detect the status payload change via checksum and re-queue to pending
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertSame($initialSyncId, $rowSync->external_sync_id);
        $this->assertSame(1, SpreadsheetRowSync::where('syncable_type', InternshipRegistration::class)->where('syncable_id', $reg->id)->count());

        $ok2 = $this->syncEngine->processSyncRecord($rowSync, $this->mockTransport);
        $this->assertTrue($ok2);

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertSame($initialRowIndex, $rowSync->row_index);
        $this->assertNotSame($initialChecksum, $rowSync->payload_checksum);
        $activeChecksum = $rowSync->payload_checksum;

        // 3. Transition active -> completed
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_COMPLETED, true, 'Lulus magang');
        $reg->refresh();
        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);

        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertSame($initialSyncId, $rowSync->external_sync_id);
        $this->assertSame(1, SpreadsheetRowSync::where('syncable_type', InternshipRegistration::class)->where('syncable_id', $reg->id)->count());

        $ok3 = $this->syncEngine->processSyncRecord($rowSync, $this->mockTransport);
        $this->assertTrue($ok3);

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertSame($initialRowIndex, $rowSync->row_index);
        $this->assertNotSame($activeChecksum, $rowSync->payload_checksum);

        // Verify remote tab has exactly 1 row (no duplicate row added)
        $this->assertCount(1, $this->mockTransport->remoteSheets['2026'] ?? []);
        $this->assertCount(3, $this->mockTransport->callHistory);
        foreach ($this->mockTransport->callHistory as $call) {
            $this->assertSame($initialSyncId, $call['sync_id']);
        }
    }

    /**
     * Skenario 2: Perubahan follow-up setelah status synced (baik pada registrasi maupun FollowUpCycle)
     * mendeteksi perubahan checksum dan memperbarui baris yang sama.
     */
    public function test_followup_changes_after_synced_status_resync_and_update_existing_row(): void
    {
        $reg = $this->createAcceptedRegistration([
            'followup_status' => 'not_started',
        ]);

        // Sync registration initially
        $regSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertTrue($this->syncEngine->processSyncRecord($regSync, $this->mockTransport));
        $regSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $regSync->sync_status);
        $regRowIndex = $regSync->row_index;
        $beforeRegChecksum = $regSync->payload_checksum;

        // Create verified contact, link to accepted registration, and create FollowUpCycle
        $contact = InstitutionContact::create([
            'institution_id' => $reg->institution_id,
            'name'           => 'Dosen Pembimbing Uji',
            'position_title' => 'Kaprodi TI',
            'phone_number'   => '081122334455',
            'email'          => 'kaprodi@ugm.test',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        RegistrationContactLink::create([
            'internship_registration_id' => $reg->id,
            'institution_contact_id'     => $contact->id,
            'relationship_role'          => 'pembimbing_akademik',
            'is_primary'                 => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $reg->institution_id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);

        $cycleSync = $this->syncEngine->queueSyncForFollowUpCycle($cycle);
        $this->assertNotNull($cycleSync);
        $this->assertTrue($this->syncEngine->processSyncRecord($cycleSync, $this->mockTransport));
        $cycleSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $cycleSync->sync_status);
        $cycleRowIndex = $cycleSync->row_index;
        $beforeCycleChecksum = $cycleSync->payload_checksum;

        // Mutate follow-up data after synced
        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff->id,
            'channel'           => 'whatsapp',
            'result'            => 'willing',
            'followed_up_at'    => now(),
            'notes'             => 'Dosen bersedia dihubungi untuk MoU',
        ]);

        $cycle->update([
            'status'                => FollowUpCycle::STATUS_COMPLETED,
            'final_result'          => FollowUpCycle::RESULT_WILLING,
            'final_notes'           => 'Siap MoU 2026',
            'current_attempt_count' => 1,
            'completed_at'          => now(),
        ]);

        $reg->update([
            'followup_status' => 'willing',
        ]);

        // Re-enqueue both after synced
        $updatedCycleSync = $this->syncEngine->queueSyncForFollowUpCycle($cycle->fresh());
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $updatedCycleSync->sync_status);

        $updatedRegSync = $this->syncEngine->queueSyncForRegistration($reg->fresh());
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $updatedRegSync->sync_status);

        // Process both updates and verify same row_index is updated
        $this->assertTrue($this->syncEngine->processSyncRecord($updatedCycleSync, $this->mockTransport));
        $updatedCycleSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $updatedCycleSync->sync_status);
        $this->assertSame($cycleRowIndex, $updatedCycleSync->row_index);
        $this->assertNotSame($beforeCycleChecksum, $updatedCycleSync->payload_checksum);

        $this->assertTrue($this->syncEngine->processSyncRecord($updatedRegSync, $this->mockTransport));
        $updatedRegSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $updatedRegSync->sync_status);
        $this->assertSame($regRowIndex, $updatedRegSync->row_index);
        $this->assertNotSame($beforeRegChecksum, $updatedRegSync->payload_checksum);
    }

    /**
     * Skenario 3: Payload identik tidak menyebabkan write ulang.
     */
    public function test_identical_payload_does_not_cause_remote_rewrite(): void
    {
        $reg = $this->createAcceptedRegistration();

        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertTrue($this->syncEngine->processSyncRecord($rowSync, $this->mockTransport));

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertCount(1, $this->mockTransport->callHistory);

        // Calling queueSyncForRegistration again with identical data must stay SYNCED and not dispatch job
        $requeued = $this->syncEngine->queueSyncForRegistration($reg->fresh());
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $requeued->sync_status);

        // Calling processSyncRecord directly without force when checksum is identical must perform 0 remote calls
        $result = $this->syncEngine->processSyncRecord($requeued, $this->mockTransport, false);
        $this->assertTrue($result);
        $this->assertCount(1, $this->mockTransport->callHistory, 'Payload identik tidak boleh memicu remote write ulang.');
    }

    /**
     * Skenario 4: Perubahan target saat job pending/processing membekukan antrean dan menolak worker in-flight.
     */
    public function test_target_change_while_job_is_pending_or_processing_freezes_jobs_and_protects_against_inflight_worker(): void
    {
        $regPending = $this->createAcceptedRegistration(['fullname' => 'Pending Intern']);
        $regProcessing = $this->createAcceptedRegistration(['fullname' => 'Processing Intern']);

        $syncPending = $this->syncEngine->queueSyncForRegistration($regPending);
        $syncProcessing = $this->syncEngine->queueSyncForRegistration($regProcessing);

        $inflightFence = (string) Str::uuid();
        $syncProcessing->update([
            'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_by'   => $inflightFence,
            'locked_at'   => now(),
        ]);

        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($regPending);
        $this->assertNotNull($sidecarSync);

        // Admin replaces Spreadsheet target from TARGET_SHEET_A to TARGET_SHEET_B
        $connectionService = app(SpreadsheetConnectionService::class);
        $connectionService->updateConnection(
            $this->regConnection,
            [
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/' . self::TARGET_SHEET_B . '/edit',
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
                'is_enabled'               => true,
            ],
            $this->admin
        );

        $this->regConnection->refresh();
        $this->assertSame(self::TARGET_SHEET_B, $this->regConnection->spreadsheet_id);
        $this->assertSame(2, (int) $this->regConnection->target_generation);

        $syncPending->refresh();
        $syncProcessing->refresh();
        $sidecarSync->refresh();

        // Both pending and processing jobs + sidecar must now be paused_target_rebind with cleared locks
        $this->assertSame(SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND, $syncPending->sync_status);
        $this->assertSame(SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND, $syncProcessing->sync_status);
        $this->assertNull($syncProcessing->locked_by);
        $this->assertNull($syncProcessing->locked_at);
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND, $sidecarSync->sync_status);

        // In-flight worker attempting to continue with preClaimedFence or stale snapshot must fail-closed with 0 writes
        $workerResult1 = $this->syncEngine->processSyncRecord(
            $syncProcessing,
            $this->mockTransport,
            false,
            $inflightFence,
            null,
            self::TARGET_SHEET_A
        );
        $this->assertFalse($workerResult1);

        $workerResult2 = $this->syncEngine->processSyncRecord(
            $syncPending,
            $this->mockTransport,
            false,
            null,
            null,
            self::TARGET_SHEET_A
        );
        $this->assertFalse($workerResult2);
        $this->assertCount(0, $this->mockTransport->callHistory);
    }

    /**
     * Skenario 5: Job paused_target_rebind tidak aktif kembali melalui enqueue biasa dan hanya lepas via rekonsiliasi Admin.
     */
    public function test_paused_target_rebind_job_does_not_reactivate_without_reconciliation_for_main_and_sidecar(): void
    {
        $reg = $this->createAcceptedRegistration();
        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);
        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);

        // Freeze via target switch
        app(SpreadsheetConnectionService::class)->updateConnection(
            $this->regConnection,
            [
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/' . self::TARGET_SHEET_B . '/edit',
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
                'is_enabled'               => true,
            ],
            $this->admin
        );

        $rowSync->refresh();
        $sidecarSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND, $rowSync->sync_status);
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND, $sidecarSync->sync_status);

        // Attempt normal enqueue & recovery functions while paused
        $reg->update(['division_interest' => 'Fullstack Developer']);
        $afterEnqueueRow = $this->syncEngine->queueSyncForRegistration($reg->fresh());
        $afterEnqueueSidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg->fresh());
        $afterReconcileSidecarHelper = $this->syncEngine->reconcileSidecarForRegistration($reg->fresh());

        $this->assertSame(SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND, $afterEnqueueRow->sync_status);
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND, $afterEnqueueSidecar->sync_status);
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND, ($afterReconcileSidecarHelper ?? $sidecarSync->fresh())->sync_status);

        $dispatchedRows = $this->syncEngine->scanAndDispatchPendingSyncs();
        $dispatchedSidecars = $this->syncEngine->scanAndDispatchPendingSidecarSyncs();
        $this->assertSame(0, $dispatchedRows);
        $this->assertSame(0, $dispatchedSidecars);

        // Now release via Admin reconciliation service
        $reconcileService = app(SpreadsheetQueueReconciliationService::class);
        $reconcileService->setTransport($this->mockTransport);

        $rowResult = $reconcileService->reconcileRowSync($rowSync->fresh(), 'rebind_new_target', $this->admin);
        $this->assertTrue($rowResult['success']);
        $this->assertSame('rebound_queued', $rowResult['action']);

        $sidecarResult = $reconcileService->reconcileSidecarSync($sidecarSync->fresh(), 'rebind_new_target', $this->admin);
        $this->assertTrue($sidecarResult['success']);
        $this->assertSame('rebound_queued', $sidecarResult['action']);

        $rowSync->refresh();
        $sidecarSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertSame(self::TARGET_SHEET_B, $rowSync->target_spreadsheet_id);
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PENDING, $sidecarSync->sync_status);
        $this->assertSame(self::TARGET_SHEET_B, $sidecarSync->target_spreadsheet_id);
    }

    /**
     * Skenario 6: Kegagalan freezePendingJobs membatalkan penggantian target Spreadsheet (fail-closed rollback).
     */
    public function test_freeze_failure_aborts_target_replacement_fail_closed(): void
    {
        $reg = $this->createAcceptedRegistration();
        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);

        $failingReconcileMock = $this->createMock(SpreadsheetQueueReconciliationService::class);
        $failingReconcileMock->expects($this->once())
            ->method('freezePendingJobs')
            ->willThrowException(new RuntimeException('Simulated database lock timeout during freezePendingJobs'));

        $this->app->instance(SpreadsheetQueueReconciliationService::class, $failingReconcileMock);

        $connectionService = app(SpreadsheetConnectionService::class);

        try {
            $connectionService->updateConnection(
                $this->regConnection,
                [
                    'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/' . self::TARGET_SHEET_B . '/edit',
                    'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
                    'is_enabled'               => true,
                ],
                $this->admin
            );
            $this->fail('updateConnection seharusnya melempar exception saat freezePendingJobs gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated database lock timeout', $e->getMessage());
        }

        // Verify fail-closed rollback: spreadsheet_id and target_generation must remain unchanged
        $this->regConnection->refresh();
        $this->assertSame(self::TARGET_SHEET_A, $this->regConnection->spreadsheet_id);
        $this->assertSame(1, (int) $this->regConnection->target_generation);

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
    }

    /**
     * Skenario 7: Endpoint monitoring GET (globalSyncTick dan syncStatus) tidak melakukan remote write atau mutasi DB.
     */
    public function test_monitoring_get_endpoints_global_sync_tick_and_sync_status_do_not_write_data(): void
    {
        $reg = $this->createAcceptedRegistration();
        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);

        $respTick = $this->actingAs($this->admin)
            ->getJson(route('admin.spreadsheet-connections.global-sync-tick'));

        $respTick->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('readonly', true)
            ->assertJsonPath('processed', 0);

        $respStatus = $this->actingAs($this->admin)
            ->getJson(route('admin.spreadsheet-connections.sync-status', $this->regConnection));

        $respStatus->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('readonly', true)
            ->assertJsonPath('stats.pending', 1);

        // Zero remote transport calls and rowSync remains pending untouched
        $this->assertCount(0, $this->mockTransport->callHistory);
        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertSame(0, $rowSync->attempt_count);
        $this->assertNull($rowSync->last_synced_at);
    }

    /**
     * Skenario 8: Admin dan HR memiliki akses sesuai RBAC, serta script monitoring dibatasi hanya pada halaman Admin yang memerlukannya.
     */
    public function test_admin_and_hr_staff_have_access_according_to_rbac_and_monitoring_script_scope(): void
    {
        // Admin has access to spreadsheet connections, show page (with visibility-aware monitor), and sync monitoring
        $adminConnShow = $this->actingAs($this->admin)
            ->get(route('admin.spreadsheet-connections.show', $this->regConnection));
        $adminConnShow->assertStatus(200);
        $adminConnShow->assertSee('pollSyncMonitoringStatus', false);
        $adminConnShow->assertSee('document.hidden', false);

        // Dashboard layout must NOT contain the removed global 5s runGlobalSyncTick polling
        $adminDashboard = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.index'));
        $adminDashboard->assertStatus(200);
        $adminDashboard->assertDontSee('runGlobalSyncTick', false);

        // HR Staff can access sync monitoring and intern list, but NOT spreadsheet connection config
        $this->actingAs($this->hrStaff)
            ->get(route('admin.sync-monitoring.index'))
            ->assertStatus(200);

        $this->actingAs($this->hrStaff)
            ->get(route('admin.interns.index'))
            ->assertStatus(200);

        $this->actingAs($this->hrStaff)
            ->getJson(route('admin.spreadsheet-connections.index'))
            ->assertStatus(403);

        // Pemagang (regular user) is forbidden from both
        $this->actingAs($this->pemagang)
            ->getJson(route('admin.spreadsheet-connections.global-sync-tick'))
            ->assertStatus(403);

        $this->actingAs($this->pemagang)
            ->getJson(route('admin.sync-monitoring.index'))
            ->assertStatus(403);
    }

    /**
     * Skenario 9: Queue worker / scheduler memproses pending dan retrying berdasarkan koneksi aktif & prioritas tanpa dashboard terbuka.
     */
    public function test_queue_worker_and_recovery_command_process_pending_and_retrying_by_connection_and_priority_without_dashboard_open(): void
    {
        $regRetrying = $this->createAcceptedRegistration(['fullname' => 'Retrying Intern']);
        $regPending = $this->createAcceptedRegistration(['fullname' => 'Pending Priority Intern']);
        $regPaused = $this->createAcceptedRegistration(['fullname' => 'Paused Intern']);

        $syncRetrying = $this->syncEngine->queueSyncForRegistration($regRetrying);
        $syncPending = $this->syncEngine->queueSyncForRegistration($regPending);
        $syncPaused = $this->syncEngine->queueSyncForRegistration($regPaused);

        $syncRetrying->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_RETRYING,
            'attempt_count' => 1,
            'next_retry_at' => now()->subMinute(),
        ]);

        $syncPending->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at' => now()->subSeconds(10),
        ]);

        $syncPaused->update([
            'sync_status' => SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
        ]);

        // Also create a record on a disabled connection
        $this->followUpConnection->update(['is_enabled' => false]);
        $disabledSync = SpreadsheetRowSync::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->followUpConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $regPending->id,
            'external_sync_id'          => 'FWU-DISABLED-TEST',
            'target_sheet_tab'          => 'Follow Up Guru Dosen',
            'schema_version'            => 'v1',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at'             => now()->subMinute(),
        ]);

        // Run recovery command (simulating background scheduler without any browser open)
        $exitCode = Artisan::call('sertif:spreadsheet-sync:recover', [
            '--connection'      => SpreadsheetConnection::KEY_REGISTRATION,
            '--include-sidecar' => true,
        ]);
        $this->assertSame(0, $exitCode);

        // Process the two eligible records via worker job execution
        (new ProcessSpreadsheetRowSyncJob($syncPending->id, null, $this->regConnection->spreadsheet_id, $syncPending->target_sheet_tab, $syncPending->schema_version, $this->regConnection->id))
            ->handle($this->syncEngine);
        (new ProcessSpreadsheetRowSyncJob($syncRetrying->id, null, $this->regConnection->spreadsheet_id, $syncRetrying->target_sheet_tab, $syncRetrying->schema_version, $this->regConnection->id))
            ->handle($this->syncEngine);

        $syncPending->refresh();
        $syncRetrying->refresh();
        $syncPaused->refresh();
        $disabledSync->refresh();

        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $syncPending->sync_status);
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $syncRetrying->sync_status);
        $this->assertSame(SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND, $syncPaused->sync_status);
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $disabledSync->sync_status);
    }

    /**
     * Skenario 10: Sidecar dan Outbox tetap berfungsi.
     */
    public function test_sidecar_and_outbox_continue_to_function(): void
    {
        $reg = $this->createAcceptedRegistration();

        // Main row sync must be verified first (prerequisite for sidecar sync)
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertTrue($this->syncEngine->processSyncRecord($mainSync, $this->mockTransport));

        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->assertNotNull($sidecarSync);
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PENDING, $sidecarSync->sync_status);

        $ok = $this->syncEngine->processSidecarSyncRecord($sidecarSync, $this->mockTransport);
        $this->assertTrue($ok);
        $sidecarSync->refresh();
        $this->assertSame(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecarSync->sync_status);

        // Re-queueing sidecar with identical payload must stay SYNCED
        $sameSidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg->fresh());
        $this->assertSame(SpreadsheetSidecarSync::STATUS_SYNCED, $sameSidecar->sync_status);

        // Outbox processing command dispatches ProcessIntegrationOutboxEventJob
        $outboxEvent = IntegrationOutboxEvent::create([
            'event_type'      => 'form_schema.publish_sync',
            'aggregate_type'  => 'form_schema',
            'aggregate_id'    => '2026',
            'idempotency_key' => 'form_schema_publish_2026_' . uniqid(),
            'payload'         => ['year' => 2026],
            'status'          => IntegrationOutboxEvent::STATUS_PENDING,
            'max_attempts'    => 5,
        ]);

        $exitCode = Artisan::call('spreadsheet:process-outbox');
        $this->assertSame(0, $exitCode);

        Queue::assertPushed(ProcessIntegrationOutboxEventJob::class);
    }

    /**
     * Skenario 11: Regresi dua grafik dashboard, proteksi LEGACY_SOURCE_READONLY, dan kejelasan filter tahun pemagang.
     */
    public function test_regression_two_dashboard_charts_legacy_readonly_and_clarified_intern_year_filter(): void
    {
        // Create cross-year registration: registered in Dec 2025, starting internship in Jan 2026
        $crossYearReg = $this->createAcceptedRegistration([
            'fullname'   => 'Cross Year Intern 2025-2026',
            'start_date' => '2026-01-10',
            'end_date'   => '2026-05-10',
            'created_at' => Carbon::create(2025, 12, 20, 9, 0, 0),
        ]);

        // 1. Filter by year_basis=created_at for 2025 -> includes crossYearReg; for 2026 -> excludes crossYearReg
        $respCreated2025 = $this->actingAs($this->admin)
            ->getJson(route('admin.interns.api', ['year' => 2025, 'year_basis' => 'created_at']));
        $respCreated2025->assertStatus(200)
            ->assertJsonPath('year_filter.year', 2025)
            ->assertJsonPath('year_filter.year_basis', 'created_at');
        $idsCreated2025 = collect($respCreated2025->json('data'))->pluck('id')->all();
        $this->assertContains($crossYearReg->id, $idsCreated2025);

        $respCreated2026 = $this->actingAs($this->admin)
            ->getJson(route('admin.interns.api', ['year' => 2026, 'year_basis' => 'created_at']));
        $respCreated2026->assertStatus(200);
        $idsCreated2026 = collect($respCreated2026->json('data'))->pluck('id')->all();
        $this->assertNotContains($crossYearReg->id, $idsCreated2026);

        // 2. Filter by year_basis=start_date for 2026 -> includes crossYearReg; for 2025 -> excludes crossYearReg
        $respStart2026 = $this->actingAs($this->admin)
            ->getJson(route('admin.interns.api', ['year' => 2026, 'year_basis' => 'start_date']));
        $respStart2026->assertStatus(200)
            ->assertJsonPath('year_filter.year', 2026)
            ->assertJsonPath('year_filter.year_basis', 'start_date');
        $idsStart2026 = collect($respStart2026->json('data'))->pluck('id')->all();
        $this->assertContains($crossYearReg->id, $idsStart2026);

        $respStart2025 = $this->actingAs($this->admin)
            ->getJson(route('admin.interns.api', ['year' => 2025, 'year_basis' => 'start_date']));
        $respStart2025->assertStatus(200);
        $idsStart2025 = collect($respStart2025->json('data'))->pluck('id')->all();
        $this->assertNotContains($crossYearReg->id, $idsStart2025);

        // 3. Dashboard renders both charts (Chart 1: 6-month trend, Chart 2: Accepted Interns chart)
        $dashboardResp = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $dashboardResp->assertStatus(200);
        $dashboardResp->assertViewHas('chart');
        $dashboardResp->assertViewHas('acceptedChart');

        $chartApiResp = $this->actingAs($this->admin)
            ->getJson(route('admin.dashboard.accepted-interns-chart', ['period' => 'monthly']));
        $chartApiResp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => ['period', 'labels', 'datasets', 'summary'],
            ]);

        // 4. LEGACY_SOURCE_READONLY protection remains active by default
        $this->assertTrue((bool) config('spreadsheet.legacy_source_readonly', true));
    }

    /**
     * Skenario 12: Pembuatan folder Drive pemagang menggunakan REGISTRASI/{TAHUN}/{NAMA_PEMAGANG},
     * tidak membuat folder dengan nama raw Folder ID, dan kolom Spreadsheet selalu diisi link folder per pemagang.
     */
    public function test_drive_folder_hierarchy_and_spreadsheet_applicant_folder_link_population(): void
    {
        $reg = $this->createAcceptedRegistration([
            'fullname'   => 'Theo Pratama',
            'created_at' => Carbon::create(2026, 10, 26, 10, 0, 0),
        ]);

        $expectedFolderPath = "REGISTRASI/2026/theo_pratama_REG{$reg->id}";
        $this->assertSame($expectedFolderPath, $reg->getDriveApplicantFolderPath());

        // 1. UniversalFileService::resolveFolderPath menghasilkan REGISTRASI/{TAHUN}/{NAMA_PEMAGANG} (bukan REG-{id}/CV)
        $capturedRemotePaths = [];
        $mockDriver = $this->createMock(\App\Services\CloudStorage\CloudStorageProviderInterface::class);
        $mockDriver->method('getDriverName')->willReturn('google_apps_script_drive');
        $mockDriver->method('getOrCreateFolderPath')->willReturn('1APPLICANT_FOLDER_THEO_123');
        $mockDriver->method('uploadFile')->willReturnCallback(function ($localPath, $remoteFolderPath, $customFileName) use (&$capturedRemotePaths) {
            $capturedRemotePaths[] = $remoteFolderPath;
            return [
                'file_id'       => '1FILE_CV_THEO_999',
                'web_view_link' => 'https://drive.google.com/file/d/1FILE_CV_THEO_999/view',
                'folder_id'     => '1APPLICANT_FOLDER_THEO_123',
                'folder_url'    => 'https://drive.google.com/drive/folders/1APPLICANT_FOLDER_THEO_123',
                'operation'     => 'created',
            ];
        });

        \App\Models\CloudStorageSetting::getEffectiveSettings()->update([
            'canonical_parent_folder_url' => 'https://drive.google.com/drive/folders/1ROOT_FOLDER_777',
            'canonical_parent_folder_id'  => '1ROOT_FOLDER_777',
            'is_verified'                 => true,
            'connection_status'           => \App\Models\CloudStorageSetting::STATUS_CONNECTED,
        ]);

        $universalService = new \App\Services\UniversalFileService($mockDriver);
        $this->assertSame(
            $expectedFolderPath,
            $universalService->resolveFolderPath(\App\Models\CloudFileRegistry::CATEGORY_CV, 'registration', $reg->id)
        );

        // 2. UniversalFileService::put meneruskan $folderPath (bukan raw drive_folder_id) ke uploadFile
        $fileRecord = $universalService->put(
            fileOrContent: 'PDF Content Theo',
            category: \App\Models\CloudFileRegistry::CATEGORY_CV,
            ownerType: 'registration',
            ownerId: $reg->id,
            customFileName: 'CV_Theo.pdf',
            customMimeType: 'application/pdf'
        );

        $expectedCvFolder = $reg->getDriveAttachmentFolderPath(\App\Models\CloudFileRegistry::CATEGORY_CV);
        $this->assertSame([$expectedCvFolder], $capturedRemotePaths);
        $this->assertSame('1APPLICANT_FOLDER_THEO_123', $fileRecord->drive_folder_id);

        // 3. Meski pemagang hanya memiliki berkas CV, kolom Spreadsheet wajib berisi link FOLDER pemagang (/drive/folders/...),
        // BUKAN link file tunggal (/file/d/.../view)
        \App\Models\RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => \App\Models\RegistrationAttachment::TYPE_CV,
            'category'                   => \App\Models\RegistrationAttachment::TYPE_CV,
            'file_path'                  => "private/registrations/2026/{$reg->id}/cv.pdf",
            'original_name'              => 'CV_Theo.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_file_id'              => '1FILE_CV_THEO_999',
            'drive_web_view_link'        => 'https://drive.google.com/file/d/1FILE_CV_THEO_999/view',
            'drive_sync_status'          => \App\Models\RegistrationAttachment::STATUS_SYNCED,
            'drive_synced_at'            => now(),
        ]);

        $mappingService = app(\App\Services\RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($reg->fresh());
        $uploadColHeader = 'Silakan Upload (berupa file Pdf) : CV, Scan KTM/KTP dan Portofolio max. 10 MB';
        $resolvedLink = $payload[$uploadColHeader] ?? ($payload['Upload PDF CV + scan KTM/KTP + portofolio max 10MB'] ?? null);

        $this->assertSame('https://drive.google.com/drive/folders/1APPLICANT_FOLDER_THEO_123', $resolvedLink);
    }
}
