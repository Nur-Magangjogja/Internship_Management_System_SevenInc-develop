<?php

namespace Tests\Feature;

use App\Jobs\ProcessSidecarSyncJob;
use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSidecarSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SyncMonitoringQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Wave2ESyncMonitoringAndAdminRetryTest extends TestCase
{
    use DatabaseTransactions;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'   => 'Registration Connection Wave 2E',
                'spreadsheet_id' => 'test-sheet-id-wave2e',
                'sheet_name'     => '2026_Main',
                'is_enabled'     => true,
            ]
        );

        $this->adminUser = $this->createUser([
            'role'      => User::ROLE_ADMIN,
            'is_banned' => false,
        ]);
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

    private function createHierarchy(): array
    {
        $unique = Str::random(6);
        $inst = Institution::create([
            'name'            => 'Univ Wave2E ' . $unique,
            'normalized_name' => 'univ wave2e ' . $unique,
            'is_active'       => true,
        ]);
        $fac = Faculty::create([
            'name'           => 'Fakultas Teknik ' . $unique,
            'institution_id' => $inst->id,
        ]);
        $prodi = StudyProgram::create([
            'name'       => 'Informatika ' . $unique,
            'faculty_id' => $fac->id,
        ]);
        $div = Division::create([
            'name'      => 'IT ' . $unique,
            'is_active' => true,
        ]);
        $city = City::first() ?? City::create(['name' => 'Kota ' . $unique]);

        $cand = $this->createUser([
            'role'      => User::ROLE_USER,
            'is_banned' => false,
        ]);

        $reg = InternshipRegistration::create([
            'user_id'          => $cand->id,
            'fullname'         => 'Peserta ' . $unique,
            'institution_id'   => $inst->id,
            'faculty_id'       => $fac->id,
            'study_program_id' => $prodi->id,
            'division_id'      => $div->id,
            'city_id'          => $city->id,
            'status'            => 'accepted',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'admin_notes'       => 'VERIFIED_ACCEPTED',
            'start_date'       => now()->toDateString(),
            'end_date'         => now()->addMonths(3)->toDateString(),
        ]);

        return [$cand, $reg, $inst, $fac, $prodi, $div];
    }

    /**
     * E01. Monitoring dapat dibuka oleh admin berwenang.
     */
    public function test_e01_monitoring_dashboard_can_be_opened_by_authorized_admin(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('admin.sync-monitoring.index'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.sync-monitoring.index');
        $response->assertViewHas(['stats', 'records', 'filters', 'connections', 'years']);
        $response->assertSee('Monitoring & Pemulihan Sinkronisasi', false);
    }

    /**
     * E02. Pengguna tanpa login ditolak.
     */
    public function test_e02_unauthenticated_user_rejected(): void
    {
        $responseGet = $this->get(route('admin.sync-monitoring.index'));
        $responseGet->assertRedirect(route('user.login'));

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1,
            'external_sync_id'          => 'SERTIF-REG-E02-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $responsePost = $this->post(route('admin.sync-monitoring.retry-main-row', $rowSync));
        $responsePost->assertRedirect(route('user.login'));
    }

    /**
     * E03. Pengguna tanpa permission monitoring ditolak.
     */
    public function test_e03_user_without_monitoring_permission_rejected(): void
    {
        $pemagang = $this->createUser([
            'role'      => User::ROLE_PEMAGANG,
            'is_banned' => false,
        ]);

        $response = $this->actingAs($pemagang)
            ->get(route('admin.sync-monitoring.index'));

        $response->assertRedirect(route('user.dashboard'));
    }

    /**
     * E04. Permission membaca berbeda dari permission retry.
     */
    public function test_e04_read_permission_is_distinct_from_retry_permission(): void
    {
        $restrictedAdmin = $this->createUser([
            'role'      => User::ROLE_ADMIN,
            'is_banned' => false,
        ]);
        config(["services.spreadsheet_retry_permission.{$restrictedAdmin->id}" => false]);

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1,
            'external_sync_id'          => 'SERTIF-REG-E04-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        // 1. Reading monitoring is ALLOWED
        $resGet = $this->actingAs($restrictedAdmin)->get(route('admin.sync-monitoring.index'));
        $resGet->assertStatus(200);

        // 2. Mutating retry is FORBIDDEN (403)
        $resPost = $this->actingAs($restrictedAdmin)->post(route('admin.sync-monitoring.retry-main-row', $rowSync));
        $resPost->assertStatus(403);
    }

    /**
     * E05. Admin tidak dapat melihat record di luar scope kewenangannya.
     */
    public function test_e05_admin_cannot_view_record_outside_connection_scope(): void
    {
        $connB = SpreadsheetConnection::create([
            'connection_key' => 'custom_scope_conn',
            'display_name'   => 'Custom Scope Connection',
            'is_enabled'     => true,
        ]);

        $scopedAdmin = $this->createUser([
            'role'      => User::ROLE_ADMIN,
            'is_banned' => false,
        ]);
        config(["services.spreadsheet_scopes.{$scopedAdmin->id}" => [$this->connection->id]]);

        $res = $this->actingAs($scopedAdmin)
            ->get(route('admin.sync-monitoring.index', ['connection_id' => $connB->id]));

        $res->assertStatus(403);
    }

    /**
     * E06. Manipulasi record ID tidak melewati authorization (IDOR).
     */
    public function test_e06_record_id_manipulation_does_not_bypass_authorization(): void
    {
        $connB = SpreadsheetConnection::create([
            'connection_key' => 'scope_test_conn_b',
            'display_name'   => 'Connection B',
            'is_enabled'     => true,
        ]);

        $scopedAdmin = $this->createUser([
            'role'      => User::ROLE_ADMIN,
            'is_banned' => false,
        ]);
        config(["services.spreadsheet_scopes.{$scopedAdmin->id}" => [$this->connection->id]]);

        $rowSyncOnConnB = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $connB->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1,
            'external_sync_id'          => 'IDOR-PROBE-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $res = $this->actingAs($scopedAdmin)
            ->post(route('admin.sync-monitoring.retry-main-row', $rowSyncOnConnB));

        $res->assertStatus(403);
    }

    /**
     * E07. Dashboard GET tidak melakukan database write atau dispatch job.
     */
    public function test_e07_dashboard_get_does_not_write_database_or_dispatch_jobs(): void
    {
        Queue::fake();

        $initRowSyncs = SpreadsheetRowSync::count();
        $initLogs = ActivityLog::count();

        $this->actingAs($this->adminUser)->get(route('admin.sync-monitoring.index'));

        $this->assertSame($initRowSyncs, SpreadsheetRowSync::count());
        $this->assertSame($initLogs, ActivityLog::count());
        Queue::assertNothingPushed();
    }

    /**
     * E08. Statistik status main-row akurat.
     */
    public function test_e08_main_row_status_statistics_accurate(): void
    {
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 101,
            'external_sync_id'          => 'STAT-M-01',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
        ]);
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 102,
            'external_sync_id'          => 'STAT-M-02',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $service = app(SyncMonitoringQueryService::class);
        $stats = $service->getSummaryStatistics($this->connection->id, 2026);

        $this->assertGreaterThanOrEqual(1, $stats['main_row']['synced']);
        $this->assertGreaterThanOrEqual(1, $stats['main_row']['failed']);
    }

    /**
     * E09. Statistik status sidecar akurat.
     */
    public function test_e09_sidecar_status_statistics_accurate(): void
    {
        [$cand, $reg] = $this->createHierarchy();
        SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => 'STAT-S-01',
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_PENDING,
        ]);

        $service = app(SyncMonitoringQueryService::class);
        $stats = $service->getSummaryStatistics($this->connection->id, 2026);

        $this->assertGreaterThanOrEqual(1, $stats['sidecar']['pending']);
    }

    /**
     * E10. Statistik status Drive akurat.
     */
    public function test_e10_drive_status_statistics_accurate(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        [$cand, $reg] = $this->createHierarchy();
        RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => 'attachments/test.pdf',
            'original_name'              => 'cv.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        $service = app(SyncMonitoringQueryService::class);
        $stats = $service->getSummaryStatistics();

        $this->assertGreaterThanOrEqual(1, $stats['attachment']['failed']);
    }

    /**
     * E11. Filter status, tahun, dan connection menghasilkan record yang benar.
     */
    public function test_e11_filters_status_year_connection_return_correct_records(): void
    {
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 401,
            'external_sync_id'          => 'MATCH-FILTER-2026',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 402,
            'external_sync_id'          => 'DIFF-YEAR-2027',
            'target_sheet_tab'          => '2027',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $service = app(SyncMonitoringQueryService::class);
        $paginator = $service->getMonitoringRecords([
            'category'      => 'main_row',
            'status'        => 'failed',
            'connection_id' => $this->connection->id,
            'year'          => 2026,
            'search'        => 'MATCH-FILTER',
        ]);

        $this->assertSame(1, $paginator->total());
        $this->assertSame('MATCH-FILTER-2026', $paginator->items()[0]->sync_identifier);
    }

    /**
     * E12. Pagination tidak memuat seluruh dataset ke memori.
     */
    public function test_e12_pagination_does_not_load_full_dataset(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            SpreadsheetRowSync::create([
                'spreadsheet_connection_id' => $this->connection->id,
                'syncable_type'             => InternshipRegistration::class,
                'syncable_id'               => 500 + $i,
                'external_sync_id'          => "PAGINATE-{$i}",
                'target_sheet_tab'          => '2026',
                'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            ]);
        }

        $service = app(SyncMonitoringQueryService::class);
        $paginator = $service->getMonitoringRecords([
            'category' => 'main_row',
            'search'   => 'PAGINATE-',
        ], perPage: 10);

        $this->assertSame(20, $paginator->total());
        $this->assertCount(10, $paginator->items());
        $this->assertTrue($paginator->hasPages());
    }

    /**
     * E13. Error yang mengandung HTML/script ditampilkan aman (XSS Prevention).
     */
    public function test_e13_error_containing_html_script_rendered_safely(): void
    {
        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 601,
            'external_sync_id'          => 'XSS-TEST-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => "<script>alert('XSS-PAYLOAD')</script><b>SQL Syntax Error</b>",
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('admin.sync-monitoring.show', ['main_row', $rowSync->public_uuid]));

        $response->assertStatus(200);
        $response->assertDontSee("<script>alert('XSS-PAYLOAD')</script>", false);
        $response->assertSee("&lt;script&gt;alert(&#039;XSS-PAYLOAD&#039;)&lt;/script&gt;", false);
    }

    /**
     * E14. Error view tidak membocorkan secret atau data pribadi.
     */
    public function test_e14_error_view_does_not_leak_secrets_or_private_data(): void
    {
        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 701,
            'external_sync_id'          => 'LEAK-TEST-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => "Transport Failed with Bearer ya29.a0AfH6SMD_super_secret_access_token and key=AIzaSyA_super_secret_api_key",
        ]);

        $service = app(SyncMonitoringQueryService::class);
        $detail = $service->getRecordDetail('main_row', $rowSync->public_uuid);

        $this->assertStringNotContainsString('ya29.a0AfH6SMD_super_secret_access_token', $detail['sanitized_error']);
        $this->assertStringNotContainsString('AIzaSyA_super_secret_api_key', $detail['sanitized_error']);
        $this->assertStringContainsString('[REDACTED_TOKEN]', $detail['sanitized_error']);
        $this->assertStringContainsString('[REDACTED_KEY]', $detail['sanitized_error']);
    }

    /**
     * 1. Main-row FAILED -> one retry.
     */
    public function test_01_main_row_failed_triggers_one_retry(): void
    {
        Queue::fake();

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 801,
            'external_sync_id'          => 'REQ-01-FAILED-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'attempt_count'             => 3,
            'payload_checksum'          => 'old_checksum_value',
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-main-row', $rowSync));

        $res->assertRedirect();
        $res->assertSessionHas('success');

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertSame(0, $rowSync->attempt_count);
        $this->assertNull($rowSync->payload_checksum);

        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1);
    }

    /**
     * 2. Main-row RETRYING -> one manual retry.
     */
    public function test_02_main_row_retrying_triggers_one_manual_retry(): void
    {
        Queue::fake();

        $futureTime = now()->addHours(3);
        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 901,
            'external_sync_id'          => 'REQ-02-RETRYING-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at'             => $futureTime,
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-main-row', $rowSync));

        $res->assertRedirect();
        $rowSync->refresh();

        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertTrue($rowSync->next_retry_at->lte(now()));
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1);
    }

    /**
     * 3. Main-row PENDING -> zero dispatch.
     */
    public function test_03_main_row_pending_yields_zero_dispatch(): void
    {
        Queue::fake();

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 103,
            'external_sync_id'          => 'REQ-03-PENDING-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
            'payload_checksum'          => 'existing_checksum',
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-main-row', $rowSync));

        $res->assertRedirect();
        $res->assertSessionHas('error');

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertSame('existing_checksum', $rowSync->payload_checksum);

        Queue::assertNothingPushed();
    }

    /**
     * 4. Main-row PROCESSING with lock NULL -> zero dispatch.
     */
    public function test_04_main_row_processing_with_null_lock_yields_zero_dispatch(): void
    {
        Queue::fake();

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 104,
            'external_sync_id'          => 'REQ-04-PROC-NULL-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'                 => null,
            'locked_by'                 => null,
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-main-row', $rowSync));

        $res->assertRedirect();
        $res->assertSessionHas('error');

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PROCESSING, $rowSync->sync_status);

        Queue::assertNothingPushed();
    }

    /**
     * 5. Main-row PROCESSING with active lock -> zero dispatch.
     */
    public function test_05_main_row_processing_with_active_lock_yields_zero_dispatch(): void
    {
        Queue::fake();

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1001,
            'external_sync_id'          => 'REQ-05-PROC-ACTIVE-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'                 => now()->subMinute(),
            'locked_by'                 => (string) Str::uuid(),
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-main-row', $rowSync));

        $res->assertRedirect();
        $res->assertSessionHas('error');

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PROCESSING, $rowSync->sync_status);
        Queue::assertNothingPushed();
    }

    /**
     * 6. Double POST -> only one dispatch.
     */
    public function test_06_double_post_yields_only_one_dispatch_second_rejected(): void
    {
        Queue::fake();

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1201,
            'external_sync_id'          => 'REQ-06-DOUBLE-POST-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        // First POST
        $res1 = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.retry-main-row', $rowSync));
        $res1->assertSessionHas('success');

        // Immediately second POST: rowSync is now in PENDING, not eligible for failed/retrying retry
        $res2 = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.retry-main-row', $rowSync));
        $res2->assertSessionHas('error');

        // Total dispatched job should be exactly 1
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1);
    }

    /**
     * 7. Sidecar PENDING -> zero dispatch.
     */
    public function test_07_sidecar_pending_yields_zero_dispatch(): void
    {
        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => "SERTIF-REG-{$reg->id}",
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            'last_synced_at'            => now(),
        ]);

        $sidecar = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => "REQ-07-SIDECAR-{$reg->id}",
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_PENDING,
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-sidecar', $sidecar));

        $res->assertRedirect();
        $res->assertSessionHas('error');

        $sidecar->refresh();
        $this->assertSame(SpreadsheetSidecarSync::STATUS_PENDING, $sidecar->sync_status);

        Queue::assertNothingPushed();
    }

    /**
     * 8. Sidecar active worker -> zero dispatch.
     */
    public function test_08_sidecar_active_worker_yields_zero_dispatch(): void
    {
        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => "SERTIF-REG-{$reg->id}",
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            'last_synced_at'            => now(),
        ]);

        $sidecarActive = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => "REQ-08-SIDECAR-ACTIVE-{$reg->id}",
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'                  => now()->subSeconds(30),
            'locked_by'                  => 'active-worker-token',
        ]);

        $resActive = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-sidecar', $sidecarActive));

        $resActive->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    /**
     * 9. Sidecar not required -> zero dispatch.
     */
    public function test_09_sidecar_not_required_yields_zero_dispatch(): void
    {
        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        // Registration is pending -> requiresSidecarSync() is false
        $reg->update(['status' => 'pending', 'admin_notes' => null]);

        $sidecar = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => "REQ-09-SIDECAR-NOTREQ-{$reg->id}",
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_FAILED,
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-sidecar', $sidecar));

        $res->assertRedirect();
        $res->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /**
     * 10. Default monitoring without connection filter respects admin scope.
     */
    public function test_10_default_monitoring_without_connection_filter_respects_admin_scope(): void
    {
        $connB = SpreadsheetConnection::create([
            'connection_key' => 'scope_b_conn',
            'display_name'   => 'Scope B Connection',
            'is_enabled'     => true,
        ]);

        $rowA = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 110,
            'external_sync_id'          => 'SCOPE-VISIBLE-A-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $rowB = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $connB->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 111,
            'external_sync_id'          => 'SCOPE-HIDDEN-B-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $scopedAdmin = $this->createUser(['role' => User::ROLE_ADMIN]);
        config(["services.spreadsheet_scopes.{$scopedAdmin->id}" => [$this->connection->id]]);

        $res = $this->actingAs($scopedAdmin)
            ->get(route('admin.sync-monitoring.index', ['connection_id' => 'all', 'search' => 'SCOPE-']));

        $res->assertStatus(200);
        $res->assertSee('SCOPE-VISIBLE-A-001');
        $res->assertDontSee('SCOPE-HIDDEN-B-001');
    }

    /**
     * 11. Monitoring detail does not reveal record outside scope.
     */
    public function test_11_monitoring_detail_does_not_reveal_record_outside_scope(): void
    {
        $connB = SpreadsheetConnection::create([
            'connection_key' => 'secret_conn_b',
            'display_name'   => 'Secret Connection B',
            'is_enabled'     => true,
        ]);

        $rowB = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $connB->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 112,
            'external_sync_id'          => 'SECRET-RECORD-B-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $scopedAdmin = $this->createUser(['role' => User::ROLE_ADMIN]);
        config(["services.spreadsheet_scopes.{$scopedAdmin->id}" => [$this->connection->id]]);

        $res = $this->actingAs($scopedAdmin)
            ->get(route('admin.sync-monitoring.show', ['main_row', $rowB->public_uuid]));

        $res->assertStatus(404);
    }

    /**
     * 12. Role/connection restriction persists after user reload from DB.
     */
    public function test_12_role_connection_restriction_persists_after_user_reload_from_db(): void
    {
        $followupConn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'display_name'   => 'Followup Connection',
                'spreadsheet_id' => 'followup-sheet-id',
                'sheet_name'     => 'Followup',
                'is_enabled'     => true,
            ]
        );

        $hrStaff = $this->createUser(['role' => User::ROLE_HR_STAFF]);

        // Reload user fresh from DB
        $freshUser = $hrStaff->fresh();

        $allowedIds = $freshUser->getAllowedConnectionIds();
        $this->assertIsArray($allowedIds);
        $this->assertContains($followupConn->id, $allowedIds);
        $this->assertNotContains($this->connection->id, $allowedIds);

        $this->assertTrue($freshUser->canAccessConnection($followupConn));
        $this->assertFalse($freshUser->canAccessConnection($this->connection));
    }

    /**
     * 13. Admin with explicit global access operates according to policy.
     */
    public function test_13_admin_with_explicit_global_access_operates_according_to_policy(): void
    {
        $admin = $this->createUser(['role' => User::ROLE_ADMIN]);

        $this->assertNull($admin->getAllowedConnectionIds());
        $this->assertTrue($admin->canAccessConnection($this->connection));
        $this->assertTrue($admin->canRetrySync());

        $this->assertTrue(Gate::forUser($admin)->allows('view-sync-monitoring'));
        $this->assertTrue(Gate::forUser($admin)->allows('retry-sync-monitoring', $this->connection));
    }

    /**
     * 14. Attachment access and retry follow document/registration authorization.
     */
    public function test_14_attachment_access_and_retry_follow_document_registration_authorization(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        Storage::fake(config('filesystems.default', 'public'));
        $filePath = 'attachments/test_auth.pdf';
        Storage::disk(config('filesystems.default', 'public'))->put($filePath, 'sample-pdf-content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'test_auth.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        // 1. HR staff (scoped only to followup) -> forbidden 403
        $hrStaff = $this->createUser(['role' => User::ROLE_HR_STAFF]);
        $resHR = $this->actingAs($hrStaff)
            ->post(route('admin.sync-monitoring.retry-attachment', $attachment));
        $resHR->assertStatus(403);
        Queue::assertNothingPushed();

        // 2. Global Admin -> allowed 302 + dispatch
        $resAdmin = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-attachment', $attachment));
        $resAdmin->assertRedirect();
        $resAdmin->assertSessionHas('success');

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_PENDING, $attachment->drive_sync_status);
        Queue::assertPushed(UploadRegistrationAttachmentToDriveJob::class, 1);
    }

    /**
     * 15. HTML index and tooltip do not leak bearer token or API key.
     */
    public function test_15_html_index_and_tooltip_do_not_leak_bearer_token_or_api_key(): void
    {
        $sensitiveToken = 'ya29.a0AfH6SMD_sensitive_bearer_token_12345';
        $sensitiveKey   = 'AIzaSyA_sensitive_google_api_key_67890';
        $sensitivePath  = 'D:\\laragon\\www\\secret_app\\file.php';

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 115,
            'external_sync_id'          => 'LEAK-TEST-TOKEN-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => "Transport failed with Bearer {$sensitiveToken} and key={$sensitiveKey} at {$sensitivePath}",
        ]);

        $res = $this->actingAs($this->adminUser)
            ->get(route('admin.sync-monitoring.index', ['search' => 'LEAK-TEST-TOKEN']));

        $res->assertStatus(200);

        // Raw tokens MUST NOT be present in body or title attributes
        $res->assertDontSee($sensitiveToken, false);
        $res->assertDontSee($sensitiveKey, false);
        $res->assertDontSee($sensitivePath, false);

        // Redacted markers MUST be visible
        $res->assertSee('[REDACTED_TOKEN]', false);
        $res->assertSee('[REDACTED_KEY]', false);
        $res->assertSee('[REDACTED_PATH]', false);
    }

    /**
     * 16. Batch sidecar without custom field required -> rejected.
     */
    public function test_16_batch_sidecar_without_custom_field_required_rejected(): void
    {
        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        // Registration is pending -> requiresSidecarSync() is false
        $reg->update(['status' => 'pending', 'admin_notes' => null]);

        $sidecar = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => "BATCH-SIDECAR-NOTREQ-{$reg->id}",
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_FAILED,
        ]);

        $res = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'sidecar',
            'connection_id' => $this->connection->id,
            'ids'           => [$sidecar->id],
        ]);

        $res->assertRedirect();
        Queue::assertNothingPushed();
    }

    /**
     * 17. Batch attachment without physical file -> zero dispatch.
     */
    public function test_17_batch_attachment_without_physical_file_yields_zero_dispatch(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => 'non_existent_folder/missing_file.pdf',
            'original_name'              => 'missing_file.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        $res = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category' => 'attachment',
            'ids'      => [$attachment->id],
        ]);

        $res->assertRedirect();
        Queue::assertNothingPushed();
    }

    /**
     * 18. Batch without connection ID does not expand authorization.
     */
    public function test_18_batch_without_connection_id_does_not_expand_authorization(): void
    {
        Queue::fake();

        $connB = SpreadsheetConnection::create([
            'connection_key' => 'batch_scope_b',
            'display_name'   => 'Batch Scope B Connection',
            'is_enabled'     => true,
        ]);

        $rowA = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1181,
            'external_sync_id'          => 'BATCH-AUTH-A-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $rowB = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $connB->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1182,
            'external_sync_id'          => 'BATCH-AUTH-B-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $scopedAdmin = $this->createUser(['role' => User::ROLE_ADMIN]);
        config(["services.spreadsheet_scopes.{$scopedAdmin->id}" => [$this->connection->id]]);

        // Submit batch retry without connection_id
        $res = $this->actingAs($scopedAdmin)->post(route('admin.sync-monitoring.batch-retry'), [
            'category' => 'main_row',
            'ids'      => [$rowA->id, $rowB->id],
        ]);

        $res->assertRedirect();

        // Exactly 1 job pushed (only rowA), rowB must be rejected
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1);
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, function ($job) use ($rowA) {
            return $job->rowSyncId === $rowA->id;
        });
    }

    /**
     * 19. Year filter consistent across all 3 streams.
     */
    public function test_19_year_filter_consistent_across_all_three_streams(): void
    {
        [$cand1, $reg2026] = $this->createHierarchy();
        $reg2026->created_at = '2026-05-10 10:00:00';
        $reg2026->save();

        [$cand2, $reg2027] = $this->createHierarchy();
        $reg2027->created_at = '2027-05-10 10:00:00';
        $reg2027->save();

        // 1. Main rows
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg2026->id,
            'external_sync_id'          => 'YEAR-STREAM-MAIN-2026',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'created_at'                => '2026-05-10 10:00:00',
        ]);
        SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg2027->id,
            'external_sync_id'          => 'YEAR-STREAM-MAIN-2027',
            'target_sheet_tab'          => '2027',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'created_at'                => '2027-05-10 10:00:00',
        ]);

        // 2. Sidecars
        SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg2026->id,
            'external_sync_id'           => 'YEAR-STREAM-SIDE-2026',
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_FAILED,
            'created_at'                 => '2026-05-10 10:00:00',
        ]);
        SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg2027->id,
            'external_sync_id'           => 'YEAR-STREAM-SIDE-2027',
            'main_sheet_tab'             => '2027',
            'extension_sheet_tab'        => '2027_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_FAILED,
            'created_at'                 => '2027-05-10 10:00:00',
        ]);

        // 3. Attachments
        if (Schema::hasTable('registration_attachments')) {
            RegistrationAttachment::create([
                'internship_registration_id' => $reg2026->id,
                'attachment_type'            => 'cv',
                'file_path'                  => 'attachments/2026_cv.pdf',
                'original_name'              => '2026_cv.pdf',
                'file_size'                  => 1024,
                'mime_type'                  => 'application/pdf',
                'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
                'created_at'                 => '2026-05-10 10:00:00',
            ]);
            RegistrationAttachment::create([
                'internship_registration_id' => $reg2027->id,
                'attachment_type'            => 'cv',
                'file_path'                  => 'attachments/2027_cv.pdf',
                'original_name'              => '2027_cv.pdf',
                'file_size'                  => 1024,
                'mime_type'                  => 'application/pdf',
                'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
                'created_at'                 => '2027-05-10 10:00:00',
            ]);
        }

        $queryService = app(SyncMonitoringQueryService::class);
        $records2026 = $queryService->getMonitoringRecords([
            'category'      => 'all',
            'connection_id' => 'all',
            'year'          => 2026,
            'search'        => 'YEAR-STREAM-',
        ]);

        $this->assertGreaterThan(0, $records2026->total());
        foreach ($records2026->items() as $item) {
            $this->assertSame(2026, (int) $item->year);
            $this->assertStringNotContainsString('2027', $item->sync_identifier);
        }
    }

    /**
     * 20. Full regression across Wave 2C-D and Wave 2C-A/B/C remains PASS.
     */
    public function test_20_full_regression_across_wave_2cd_and_2cabc_remains_pass(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $this->assertInstanceOf(SpreadsheetSyncEngineService::class, $engine);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $this->assertInstanceOf(RegistrationSpreadsheetMappingService::class, $mappingService);

        $queryService = app(SyncMonitoringQueryService::class);
        $this->assertInstanceOf(SyncMonitoringQueryService::class, $queryService);

        // Async retry capability is non-blocking
        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 12001,
            'external_sync_id'          => 'ASYNC-REGRESSION-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        Queue::fake();
        $success = $engine->queueManualRetry($rowSync, $this->adminUser);
        $this->assertTrue($success);
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1);
    }

    /**
     * E20. Batch retry menghormati authorization, scope, dan batas jumlah.
     */
    public function test_e20_batch_retry_respects_authorization_scope_and_limits(): void
    {
        Queue::fake();

        for ($i = 1; $i <= 10; $i++) {
            SpreadsheetRowSync::create([
                'spreadsheet_connection_id' => $this->connection->id,
                'syncable_type'             => InternshipRegistration::class,
                'syncable_id'               => 1300 + $i,
                'external_sync_id'          => "BATCH-TEST-{$i}",
                'target_sheet_tab'          => '2026',
                'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            ]);
        }

        $res = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'main_row',
            'connection_id' => $this->connection->id,
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success');

        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 10);
    }

    /**
     * E21. Sidecar retry menolak registrasi yang belum memenuhi acceptance gate.
     */
    public function test_e21_sidecar_retry_rejects_registration_not_meeting_acceptance_gate(): void
    {
        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        $reg->update(['status' => 'pending', 'admin_notes' => null]);

        $sidecarSync = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->connection->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => "SIDECAR-NOT-ACCEPTED-{$reg->id}",
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_FAILED,
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-sidecar', $sidecarSync));

        $res->assertRedirect();
        $res->assertSessionHas('error');

        $sidecarSync->refresh();
        $this->assertSame(SpreadsheetSidecarSync::STATUS_FAILED, $sidecarSync->sync_status);
        Queue::assertNothingPushed();
    }

    /**
     * E23. Drive retry tanpa berkas ditolak sebelum remote call.
     */
    public function test_e23_drive_retry_without_file_rejected_before_remote_call(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => 'non_existent_folder/missing.pdf',
            'original_name'              => 'missing.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        $res = $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-attachment', $attachment));

        $res->assertRedirect();
        $res->assertSessionHas('error');

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_FAILED, $attachment->drive_sync_status);
        Queue::assertNothingPushed();
    }

    /**
     * E24. Audit log menyimpan actor, record, dan outcome dengan benar tanpa secrets.
     */
    public function test_e24_audit_log_stores_actor_record_outcome_without_secrets(): void
    {
        Queue::fake();

        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => 1501,
            'external_sync_id'          => 'AUDIT-LOG-RECORD-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
        ]);

        $this->actingAs($this->adminUser)
            ->post(route('admin.sync-monitoring.retry-main-row', $rowSync));

        $log = ActivityLog::where('subject_type', SpreadsheetRowSync::class)
            ->where('subject_id', $rowSync->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->adminUser->id, $log->actor_user_id);
        $this->assertSame('sync_monitoring_retry_main_row', $log->action);
        $this->assertSame('queued', $log->metadata['outcome']);
        $this->assertArrayNotHasKey('password', (array) $log->metadata);
        $this->assertArrayNotHasKey('secret', (array) $log->metadata);
    }

    /**
     * R03-1. Admin global + filter followup + batch attachment -> zero transition dan zero dispatch.
     */
    public function test_r03_01_global_admin_with_followup_filter_rejects_batch_attachment_with_zero_dispatch(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        $followupConn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'display_name'   => 'Followup Sheet R03',
                'spreadsheet_id' => 'followup-sheet-r03-1',
                'sheet_name'     => 'Followup',
                'is_enabled'     => true,
            ]
        );

        [$cand, $reg] = $this->createHierarchy();
        Storage::fake(config('filesystems.default', 'public'));
        $filePath = 'attachments/test_r03_01.pdf';
        Storage::disk(config('filesystems.default', 'public'))->put($filePath, 'pdf-content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'test_r03_01.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        $res = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'attachment',
            'connection_id' => $followupConn->id,
            'ids'           => [$attachment->id],
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('error');

        // Status in DB must NOT change
        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_FAILED, $attachment->drive_sync_status);

        // Queue must have ZERO jobs pushed
        Queue::assertNothingPushed();
    }

    /**
     * R03-2. Admin global + filter registration + batch attachment valid -> satu dispatch sah.
     */
    public function test_r03_02_global_admin_with_registration_filter_queues_valid_batch_attachment(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        Storage::fake(config('filesystems.default', 'public'));
        $filePath = 'attachments/test_r03_02.pdf';
        Storage::disk(config('filesystems.default', 'public'))->put($filePath, 'pdf-content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'test_r03_02.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        $res = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'attachment',
            'connection_id' => $this->connection->id,
            'ids'           => [$attachment->id],
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success');

        // Status transitioned to PENDING
        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_PENDING, $attachment->drive_sync_status);

        // Exactly 1 job pushed
        Queue::assertPushed(UploadRegistrationAttachmentToDriveJob::class, 1);
    }

    /**
     * R03-3. Admin global + connection_id kosong -> hanya attachment dalam scope registrasi yang sah.
     */
    public function test_r03_03_global_admin_without_connection_id_processes_attachment_under_registration_scope(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        Storage::fake(config('filesystems.default', 'public'));
        $filePath = 'attachments/test_r03_03.pdf';
        Storage::disk(config('filesystems.default', 'public'))->put($filePath, 'pdf-content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'test_r03_03.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_RETRYING,
        ]);

        $res = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category' => 'attachment',
            'ids'      => [$attachment->id],
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success');

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_PENDING, $attachment->drive_sync_status);

        Queue::assertPushed(UploadRegistrationAttachmentToDriveJob::class, 1);
    }

    /**
     * R03-4. HR Staff tanpa permission registration -> zero dispatch.
     */
    public function test_r03_04_hr_staff_without_registration_permission_yields_zero_dispatch(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'display_name'   => 'Followup Sheet R03-4',
                'spreadsheet_id' => 'followup-sheet-r03-4',
                'sheet_name'     => 'Followup',
                'is_enabled'     => true,
            ]
        );

        $hrStaff = $this->createUser(['role' => User::ROLE_HR_STAFF]);

        [$cand, $reg] = $this->createHierarchy();
        Storage::fake(config('filesystems.default', 'public'));
        $filePath = 'attachments/test_r03_04.pdf';
        Storage::disk(config('filesystems.default', 'public'))->put($filePath, 'pdf-content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'test_r03_04.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        // 1. Batch retry request from HR staff
        $resBatch = $this->actingAs($hrStaff)->post(route('admin.sync-monitoring.batch-retry'), [
            'category' => 'attachment',
            'ids'      => [$attachment->id],
        ]);

        $resBatch->assertRedirect();
        $resBatch->assertSessionHas('error');

        // 2. Single retry request from HR staff
        $resSingle = $this->actingAs($hrStaff)->post(route('admin.sync-monitoring.retry-attachment', $attachment));
        $resSingle->assertStatus(403);

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_FAILED, $attachment->drive_sync_status);

        Queue::assertNothingPushed();
    }

    /**
     * R03-5. Connection registration tidak ditemukan -> fail-closed.
     */
    public function test_r03_05_missing_registration_connection_fails_closed(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        Storage::fake(config('filesystems.default', 'public'));
        $filePath = 'attachments/test_r03_05.pdf';
        Storage::disk(config('filesystems.default', 'public'))->put($filePath, 'pdf-content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'test_r03_05.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        // Temporarily delete registration connection
        $deletedConnId = $this->connection->id;
        SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->delete();

        // 1. Test single retry
        $resSingle = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.retry-attachment', $attachment));
        $resSingle->assertRedirect();
        $resSingle->assertSessionHas('error');

        // 2. Test batch retry
        $resBatch = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category' => 'attachment',
            'ids'      => [$attachment->id],
        ]);
        $resBatch->assertRedirect();
        $resBatch->assertSessionHas('error');

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_FAILED, $attachment->drive_sync_status);
        Queue::assertNothingPushed();

        // Restore registration connection for transaction rollback cleanliness
        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['id' => $deletedConnId],
            [
                'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
                'display_name'   => 'Registration Connection Wave 2E',
                'spreadsheet_id' => 'test-sheet-id-wave2e',
                'sheet_name'     => '2026_Main',
                'is_enabled'     => true,
            ]
        );
    }

    /**
     * R03-6. Dua POST batch pada record sama -> hanya satu dispatch efektif.
     */
    public function test_r03_06_two_consecutive_batch_posts_produce_only_one_effective_dispatch(): void
    {
        if (!Schema::hasTable('registration_attachments')) {
            $this->markTestSkipped('Table registration_attachments not available');
        }

        Queue::fake();

        [$cand, $reg] = $this->createHierarchy();
        Storage::fake(config('filesystems.default', 'public'));
        $filePath = 'attachments/test_r03_06.pdf';
        Storage::disk(config('filesystems.default', 'public'))->put($filePath, 'pdf-content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_path'                  => $filePath,
            'original_name'              => 'test_r03_06.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
            'drive_sync_status'          => RegistrationAttachment::STATUS_FAILED,
        ]);

        // First POST: transitions FAILED -> PENDING, dispatches 1 job
        $res1 = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'attachment',
            'connection_id' => $this->connection->id,
            'ids'           => [$attachment->id],
        ]);
        $res1->assertRedirect();
        $res1->assertSessionHas('success');

        $attachment->refresh();
        $this->assertSame(RegistrationAttachment::STATUS_PENDING, $attachment->drive_sync_status);

        // Second POST on the exact same record: now PENDING in DB, must NOT dispatch another job
        $res2 = $this->actingAs($this->adminUser)->post(route('admin.sync-monitoring.batch-retry'), [
            'category'      => 'attachment',
            'connection_id' => $this->connection->id,
            'ids'           => [$attachment->id],
        ]);
        $res2->assertRedirect();

        // Total dispatched job must be strictly 1 across both POST requests
        Queue::assertPushed(UploadRegistrationAttachmentToDriveJob::class, 1);
    }

    /**
     * @test
     * Wave 2C-F: Superadmin role HTTP access to sync monitoring dashboard and retry actions.
     */
    public function test_f01_superadmin_can_access_sync_monitoring_and_retry(): void
    {
        $superAdmin = $this->createUser([
            'role'      => User::ROLE_ADMIN,
            'is_banned' => false,
        ]);
        $superAdmin->role = 'superadmin';

        // 1. Dashboard GET access
        $response = $this->actingAs($superAdmin)->get(route('admin.sync-monitoring.index'));
        $response->assertOk();
        $response->assertViewIs('admin.sync-monitoring.index');

        // 2. Unprivileged role rejected (e.g., pemagang / user)
        $pemagang = $this->createUser([
            'role'      => User::ROLE_PEMAGANG,
            'is_banned' => false,
        ]);
        $rejectedResponse = $this->actingAs($pemagang)->get(route('admin.sync-monitoring.index'));
        $rejectedResponse->assertRedirect();

        // 3. Superadmin can retry a failed main row
        Queue::fake();
        [, $reg] = $this->createHierarchy();
        $rowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => 'SUPERADMIN-RETRY-001',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => 'Temporary remote error',
        ]);

        $retryResponse = $this->actingAs($superAdmin)->post(
            route('admin.sync-monitoring.retry-main-row', $rowSync)
        );
        $retryResponse->assertRedirect();
        $retryResponse->assertSessionHas('success');

        $rowSync->refresh();
        $this->assertSame(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, 1);
    }
}

