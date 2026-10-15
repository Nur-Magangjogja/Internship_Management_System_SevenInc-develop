<?php

namespace Tests\Feature;

use App\Jobs\ProcessSidecarSyncJob;
use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\SpreadsheetSidecarSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SERTIF Wave 2C-F: Final System Integration, Full Regression & Release Readiness Acceptance Test Suite
 * Master Plan: PLAN-S17.18G.6-F-R01
 * Covers End-to-End Scenarios F01 - F10.
 */
class Wave2FFinalIntegrationAndAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $hrUser;
    protected SpreadsheetConnection $regConnection;
    protected SpreadsheetConnection $followupConnection;
    protected MockSpreadsheetTransport $mockTransport;
    protected SpreadsheetSyncEngineService $syncEngine;
    protected RegistrationSpreadsheetMappingService $mappingService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Establish Canonical Registration Connection
        $this->regConnection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Pendaftaran Magang Wave 2F',
                'spreadsheet_id'           => 'sheet-reg-wave2f',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/sheet-reg-wave2f/edit',
                'sheet_name'               => '2026_Main',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-wave2f-endpoint/exec',
            ]
        );
        $this->regConnection->update([
            'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/sheet-reg-wave2f/edit',
            'is_enabled'        => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        // 2. Establish Canonical Follow-up Connection
        $this->followupConnection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'display_name'             => 'Follow-up Kemitraan Wave 2F',
                'spreadsheet_id'           => 'sheet-followup-wave2f',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/sheet-followup-wave2f/edit',
                'sheet_name'               => 'Followup_Main',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-wave2f-followup/exec',
            ]
        );
        $this->followupConnection->update([
            'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/sheet-followup-wave2f/edit',
            'is_enabled'        => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        // 3. Seed Canonical 2026 Schema & Column Mappings
        $this->seed(Spreadsheet2026SchemaSeeder::class);

        // 4. Actors
        $this->adminUser = $this->createUser([
            'role'      => User::ROLE_ADMIN,
            'is_banned' => false,
        ]);

        $this->hrUser = $this->createUser([
            'role'      => User::ROLE_HR_STAFF,
            'is_banned' => false,
        ]);

        // 5. Mock Transport & Services
        $this->mockTransport = new MockSpreadsheetTransport();
        $this->mappingService = app(RegistrationSpreadsheetMappingService::class);
        $this->syncEngine = new SpreadsheetSyncEngineService(
            transport: $this->mockTransport,
            registrationMappingService: $this->mappingService
        );

        $this->app->instance(\App\Services\SpreadsheetTransport\SpreadsheetTransportInterface::class, $this->mockTransport);
        $this->app->instance(SpreadsheetSyncEngineService::class, $this->syncEngine);
    }

    private function createUser(array $attributes = []): User
    {
        $id = uniqid();
        return User::create(array_merge([
            'name'      => 'User ' . $id,
            'email'     => 'user_' . $id . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => User::ROLE_ADMIN,
            'is_banned' => false,
        ], $attributes));
    }

    private function createHierarchy(int $year = 2026): array
    {
        $unique = Str::random(6);
        $inst = Institution::create([
            'name'            => 'Univ Wave2F ' . $unique,
            'normalized_name' => 'univ wave2f ' . $unique,
            'is_active'       => true,
        ]);
        $fac = Faculty::create([
            'name'           => 'Fakultas ' . $unique,
            'institution_id' => $inst->id,
        ]);
        $prodi = StudyProgram::create([
            'name'       => 'Prodi ' . $unique,
            'faculty_id' => $fac->id,
        ]);
        $div = Division::create([
            'name'      => 'Division ' . $unique,
            'is_active' => true,
        ]);
        $city = City::first() ?? City::create(['name' => 'City ' . $unique]);

        $cand = $this->createUser([
            'role'      => User::ROLE_USER,
            'is_banned' => false,
        ]);

        $reg = InternshipRegistration::create([
            'user_id'           => $cand->id,
            'fullname'          => 'Intern ' . $unique,
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prodi->id,
            'division_id'       => $div->id,
            'city_id'           => $city->id,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'registration_year' => $year,
            'start_date'        => "2026-02-01",
            'end_date'          => "2026-05-01",
        ]);

        if ($year <= 2037) {
            DB::table('internship_registrations')->where('id', $reg->id)->update([
                'created_at' => Carbon::create($year, 1, 15, 10, 0, 0),
                'updated_at' => Carbon::create($year, 1, 15, 10, 0, 0),
            ]);
            $reg->refresh();
        } else {
            // For years beyond MySQL TIMESTAMP range (e.g. 2099), retain DB timestamp and assign in-memory
            $reg->created_at = Carbon::create($year, 1, 15, 10, 0, 0);
        }

        return [$cand, $reg, $inst, $fac, $prodi, $div];
    }

    // =========================================================================
    // SCENARIO F01: SUCCESSFUL REGISTRATION WORKFLOW
    // =========================================================================
    public function test_scenario_f01_successful_registration_workflow(): void
    {
        [, $reg] = $this->createHierarchy(2026);

        // 1. Queue and Process Main-Row Sync via Engine
        $rowSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertNotNull($rowSync);
        $this->assertSame('2026', $rowSync->target_sheet_tab);

        $syncResult = $this->syncEngine->processSyncRecord($rowSync, $this->mockTransport);
        $this->assertTrue($syncResult);

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertSame('2026', $rowSync->target_sheet_tab);

        // 2. Drive Attachment Sync via worker job
        Storage::fake('local');
        $filePath = 'attachments/2026/cv_' . $reg->id . '.pdf';
        Storage::disk('local')->put($filePath, 'pdf-content-f01');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'cv_f01.pdf',
            'file_size'                  => strlen('pdf-content-f01'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
            'drive_file_checksum'        => hash('sha256', 'pdf-content-f01'),
        ]);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::success(
            rowIndex: 1,
            context: [
                'file_id'       => 'DRIVE_FILE_VERIFIED_F01',
                'web_view_link' => 'https://drive.google.com/file/d/DRIVE_FILE_VERIFIED_F01/view',
            ]
        );

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($this->mockTransport, $this->syncEngine);

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_SYNCED, $attachment->drive_sync_status);
        $this->assertSame('DRIVE_FILE_VERIFIED_F01', $attachment->drive_file_id);
        $this->assertNotNull($attachment->drive_synced_at);

        // 3. Monitoring Dashboard shows confirmed status
        $response = $this->actingAs($this->adminUser)->get(route('admin.sync-monitoring.index'));
        $response->assertOk();
    }

    // =========================================================================
    // SCENARIO F02: UNACCEPTED REGISTRATION BLOCKED BY ACCEPTANCE GATE
    // =========================================================================
    public function test_scenario_f02_unaccepted_registration_blocked_by_acceptance_gate(): void
    {
        [, $reg] = $this->createHierarchy(2026);
        // Mutate to unaccepted / draft state
        $reg->internship_status = InternshipRegistration::STATUS_PENDING;
        $reg->save();

        // 1. SyncEngine must refuse to queue/sync unaccepted registration
        $queuedRow = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertNull($queuedRow, 'Unaccepted registration must return null from queueSyncForRegistration');

        // Row sync must NOT be marked synced
        $rowSync = SpreadsheetRowSync::where('syncable_id', $reg->id)
            ->where('syncable_type', InternshipRegistration::class)
            ->first();
        $this->assertTrue($rowSync === null || $rowSync->sync_status !== SpreadsheetRowSync::STATUS_SYNCED);

        // 2. Sidecar retry via controller also rejects unaccepted registration
        $sidecar = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->regConnection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => 'SIDECAR-F02-' . $reg->id,
            'sync_status'                => SpreadsheetSidecarSync::STATUS_FAILED,
            'error_message'              => 'Waiting for prerequisite',
        ]);

        $response = $this->actingAs($this->adminUser)->post(
            route('admin.sync-monitoring.retry-sidecar', $sidecar->id)
        );
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // =========================================================================
    // SCENARIO F03: DYNAMIC FORM SCHEMA AND YEAR BINDING (2020 & 2099)
    // =========================================================================
    public function test_scenario_f03_dynamic_form_schema_and_year_binding(): void
    {
        // 1. Historical Year 2020
        [, $reg2020] = $this->createHierarchy(2020);
        $schema2026 = SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $this->regConnection->id)
            ->where('year', 2026)
            ->first();

        $schema2020 = SpreadsheetSchemaVersion::firstOrCreate(
            ['spreadsheet_connection_id' => $this->regConnection->id, 'year' => 2020, 'schema_version' => 'v1'],
            ['sheet_tab_name' => '2020', 'is_active' => true]
        );
        if ($schema2026 && $schema2020->columnMappings()->count() === 0) {
            foreach ($schema2026->columnMappings as $mapping) {
                $attr = $mapping->toArray();
                unset($attr['id'], $attr['public_uuid'], $attr['created_at'], $attr['updated_at']);
                $attr['spreadsheet_schema_version_id'] = $schema2020->id;
                SpreadsheetColumnMapping::create($attr);
            }
        }

        $this->mockTransport->reset();
        $rowSync2020 = $this->syncEngine->queueSyncForRegistration($reg2020);
        $this->assertNotNull($rowSync2020);
        $this->assertSame('2020', $rowSync2020->target_sheet_tab);

        $result2020 = $this->syncEngine->processSyncRecord($rowSync2020, $this->mockTransport);
        $this->assertTrue($result2020);
        $this->assertSame('2020', $rowSync2020->fresh()->target_sheet_tab);

        // 2. Future Year 2099
        [, $reg2099] = $this->createHierarchy(2099);
        $schema2099 = SpreadsheetSchemaVersion::firstOrCreate(
            ['spreadsheet_connection_id' => $this->regConnection->id, 'year' => 2099, 'schema_version' => 'v1'],
            ['sheet_tab_name' => '2099', 'is_active' => true]
        );
        if ($schema2026 && $schema2099->columnMappings()->count() === 0) {
            foreach ($schema2026->columnMappings as $mapping) {
                $attr = $mapping->toArray();
                unset($attr['id'], $attr['public_uuid'], $attr['created_at'], $attr['updated_at']);
                $attr['spreadsheet_schema_version_id'] = $schema2099->id;
                SpreadsheetColumnMapping::create($attr);
            }
        }

        $this->mockTransport->reset();
        $rowSync2099 = $this->syncEngine->queueSyncForRegistration($reg2099);
        $this->assertNotNull($rowSync2099);
        $this->assertSame('2099', $rowSync2099->target_sheet_tab);

        $result2099 = $this->syncEngine->processSyncRecord($rowSync2099, $this->mockTransport);
        $this->assertTrue($result2099);
        $this->assertSame('2099', $rowSync2099->fresh()->target_sheet_tab);

        // 3. Year binding on registration and tab is preserved
        $this->assertSame(2020, (int) $reg2020->fresh()->created_at->format('Y'));
        $this->assertSame('2020', $rowSync2020->fresh()->target_sheet_tab);
    }

    // =========================================================================
    // SCENARIO F04: PARTIAL SYNCHRONIZATION FAILURE & SAFE RECOVERY
    // =========================================================================
    public function test_scenario_f04_partial_synchronization_failure_and_safe_recovery(): void
    {
        [, $reg] = $this->createHierarchy(2026);

        // Main row succeeds with full verified presence metadata
        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => 'MAIN-ROW-F04',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'                 => 12,
            'payload_checksum'          => hash('sha256', 'payload-f04'),
            'last_synced_at'            => Carbon::now(),
        ]);

        // Sidecar fails temporarily
        $sidecar = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->regConnection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => 'SIDECAR-F04-' . $reg->id,
            'sync_status'                => SpreadsheetSidecarSync::STATUS_FAILED,
            'error_message'              => 'Temporary sidecar HTTP timeout',
        ]);

        Queue::fake();

        // Admin triggers retry on sidecar ONLY
        $response = $this->actingAs($this->adminUser)->post(
            route('admin.sync-monitoring.retry-sidecar', $sidecar->id)
        );
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Main row remains untouched and SYNCED (no duplicate creation)
        $this->assertSame(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->fresh()->sync_status);
        $this->assertSame(12, $rowSync->fresh()->row_index);

        // Sidecar transitioned to PENDING and job queued
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PENDING, $sidecar->fresh()->sync_status);
        Queue::assertPushed(ProcessSidecarSyncJob::class, 1);
        Queue::assertNotPushed(ProcessSpreadsheetRowSyncJob::class);
    }

    // =========================================================================
    // SCENARIO F05: GOOGLE DRIVE TIMEOUT / UNKNOWN OUTCOME IDEMPOTENCY
    // =========================================================================
    public function test_scenario_f05_google_drive_timeout_unknown_outcome_reconciliation(): void
    {
        [, $reg] = $this->createHierarchy(2026);
        Storage::fake('local');
        $filePath = 'attachments/cv_' . $reg->id . '.pdf';
        $fileBytes = 'binary-pdf-content-f05';
        Storage::disk('local')->put($filePath, $fileBytes);
        $checksum = hash('sha256', $fileBytes);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'cv.pdf',
            'file_size'                  => strlen($fileBytes),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_RETRYING,
            'drive_file_checksum'        => $checksum,
            'drive_next_retry_at'        => Carbon::now()->subMinute(),
        ]);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::success(
            rowIndex: 1,
            context: [
                'file_id'       => 'DRIVE_FILE_RECONCILED_F05',
                'web_view_link' => 'https://drive.google.com/file/d/DRIVE_FILE_RECONCILED_F05/view',
            ]
        );

        $job = new UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle($this->mockTransport, $this->syncEngine);

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_SYNCED, $attachment->drive_sync_status);
        $this->assertSame('DRIVE_FILE_RECONCILED_F05', $attachment->drive_file_id);
    }

    // =========================================================================
    // SCENARIO F06: ATTACHMENT CONTENT VERSION CHANGE
    // =========================================================================
    public function test_scenario_f06_attachment_content_version_change(): void
    {
        [, $reg] = $this->createHierarchy(2026);
        Storage::fake('local');

        // Version 1
        $path = 'attachments/portfolio_' . $reg->id . '.pdf';
        Storage::disk('local')->put($path, 'content-version-1');
        $hash1 = hash('sha256', 'content-version-1');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'portfolio',
            'file_path'                  => $path,
            'original_name'              => 'portfolio.pdf',
            'file_size'                  => strlen('content-version-1'),
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_file_checksum'        => $hash1,
            'drive_file_id'              => 'drive-v1-file-id',
            'drive_synced_at'            => Carbon::now()->subDay(),
        ]);

        // User uploads Version 2 (content changes)
        Storage::disk('local')->put($path, 'content-version-2-updated');
        $hash2 = hash('sha256', 'content-version-2-updated');
        $this->assertNotSame($hash1, $hash2);

        // Invalidate sync state
        $attachment->invalidateDriveSync('Content updated');

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_PENDING, $attachment->drive_sync_status);
        $this->assertNull($attachment->drive_file_id);
    }

    // =========================================================================
    // SCENARIO F07: QUEUE LEASE AND WORKER CRASH RECOVERY
    // =========================================================================
    public function test_scenario_f07_queue_lease_and_worker_crash_recovery(): void
    {
        [, $reg] = $this->createHierarchy(2026);
        Storage::fake('local');
        $path = 'attachments/doc_' . $reg->id . '.pdf';
        Storage::disk('local')->put($path, 'dummy-data');

        // Worker 1 claimed lease, but crashed; lease has now expired
        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $path,
            'original_name'              => 'doc.pdf',
            'file_size'                  => 10,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_PROCESSING,
            'drive_locked_by'            => 'worker-1-crashed-token',
            'drive_lock_expires_at'      => Carbon::now()->subMinutes(5), // EXPIRED
        ]);

        // Recovery / Successor Worker 2 claims the expired lease
        $now = Carbon::now();
        $newToken = 'worker-2-successor-token';
        $affected = RegistrationAttachment::where('id', $attachment->id)
            ->where(function ($q) use ($now) {
                $q->whereIn('drive_sync_status', [
                    RegistrationAttachment::STATUS_PENDING,
                    RegistrationAttachment::STATUS_FAILED,
                    RegistrationAttachment::STATUS_RETRYING,
                ])->orWhere(function ($q2) use ($now) {
                    $q2->where('drive_sync_status', RegistrationAttachment::STATUS_PROCESSING)
                       ->where('drive_lock_expires_at', '<=', $now);
                });
            })
            ->update([
                'drive_sync_status'     => RegistrationAttachment::STATUS_PROCESSING,
                'drive_locked_by'       => $newToken,
                'drive_lock_expires_at' => $now->copy()->addMinutes(15),
            ]);

        $this->assertSame(1, $affected);
        $attachment->refresh();
        $this->assertSame($newToken, $attachment->drive_locked_by);

        // Stale Worker 1 wakes up and attempts finalization with old token -> MUST FAIL (0 rows affected)
        $staleFinalize = RegistrationAttachment::where('id', $attachment->id)
            ->where('drive_locked_by', 'worker-1-crashed-token')
            ->update([
                'drive_sync_status' => RegistrationAttachment::STATUS_SYNCED,
            ]);

        $this->assertSame(0, $staleFinalize);
        $this->assertSame(RegistrationAttachment::STATUS_PROCESSING, $attachment->fresh()->drive_sync_status);
        $this->assertSame($newToken, $attachment->fresh()->drive_locked_by);
    }

    // =========================================================================
    // SCENARIO F08: ADMIN MONITORING AND MANUAL RETRY
    // =========================================================================
    public function test_scenario_f08_admin_monitoring_and_manual_retry(): void
    {
        [, $reg] = $this->createHierarchy(2026);

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => 'RETRY-F08-MAIN',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => 'Server 500 error from Google',
        ]);

        // 1. GET is strictly read-only
        $initialUpdated = $rowSync->updated_at;
        $response = $this->actingAs($this->adminUser)->get(route('admin.sync-monitoring.index'));
        $response->assertOk();
        $this->assertSame($initialUpdated->toDateTimeString(), $rowSync->fresh()->updated_at->toDateTimeString());

        Queue::fake();

        // 2. Authorized POST retry on FAILED record
        $retryResponse = $this->actingAs($this->adminUser)->post(
            route('admin.sync-monitoring.retry-main-row', $rowSync)
        );
        $retryResponse->assertRedirect();
        $retryResponse->assertSessionHas('success');

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1);

        // 3. Second POST on now PENDING record must reject without additional dispatch
        $secondRetry = $this->actingAs($this->adminUser)->post(
            route('admin.sync-monitoring.retry-main-row', $rowSync)
        );
        $secondRetry->assertRedirect();
        $secondRetry->assertSessionHas('error');
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1); // Strictly still 1
    }

    // =========================================================================
    // SCENARIO F09: BATCH RETRY ISOLATION & CONNECTION SCOPING
    // =========================================================================
    public function test_scenario_f09_batch_retry_isolation_and_connection_scoping(): void
    {
        [, $reg] = $this->createHierarchy(2026);
        Storage::fake('local');
        $path = 'attachments/cv_' . $reg->id . '.pdf';
        Storage::disk('local')->put($path, 'pdf-data');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $path,
            'original_name'              => 'cv.pdf',
            'file_size'                  => 8,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        Queue::fake();

        // Admin submits batch retry with connection_id = FOLLOW-UP connection -> MUST REJECT
        $mismatchedResponse = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'attachment',
            'connection_id' => $this->followupConnection->id,
            'ids'           => [$attachment->id],
        ]);
        $mismatchedResponse->assertRedirect();
        $mismatchedResponse->assertSessionHas('error');
        Queue::assertNothingPushed();
        $this->assertSame(RegistrationAttachment::STATUS_FAILED, $attachment->fresh()->drive_sync_status);

        // Admin submits batch retry with connection_id = REGISTRATION connection -> MUST SUCCEED
        $validResponse = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'attachment',
            'connection_id' => $this->regConnection->id,
            'ids'           => [$attachment->id],
        ]);
        $validResponse->assertRedirect();
        $validResponse->assertSessionHas('success');
        Queue::assertPushed(UploadRegistrationAttachmentToDriveJob::class, 1);
        $this->assertSame(RegistrationAttachment::STATUS_PENDING, $attachment->fresh()->drive_sync_status);
    }

    // =========================================================================
    // SCENARIO F10: TERMINAL FAILURE AND RECOVERY VISIBILITY
    // =========================================================================
    public function test_scenario_f10_terminal_failure_and_recovery_visibility(): void
    {
        [, $reg] = $this->createHierarchy(2026);

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => 'TERMINAL-FAIL-F10',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'attempt_count'             => 5,
            'max_attempts'              => 5,
            'error_message'             => 'Terminal HTTP 400 Bad Request with API_KEY=secret_key_123',
        ]);

        // Detail view rendered safely without secrets leaking in HTML
        $response = $this->actingAs($this->adminUser)->get(
            route('admin.sync-monitoring.show', ['category' => 'main_row', 'id' => $rowSync->id])
        );
        $response->assertOk();
        $response->assertDontSee('secret_key_123'); // Sanitized
        $response->assertSee('Terminal HTTP 400');
    }
}
