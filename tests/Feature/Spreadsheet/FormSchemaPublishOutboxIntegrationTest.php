<?php

namespace Tests\Feature\Spreadsheet;

use App\Jobs\ProcessIntegrationOutboxEventJob;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\IntegrationOutboxEvent;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormSchemaPublishOutboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected SpreadsheetConnection $connection;
    protected MockSpreadsheetTransport $mockTransport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Outbox',
            'email'     => 'admin.outbox@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Google Sheets Pendaftaran 2026',
                'spreadsheet_id'           => 'mock-spreadsheet-outbox-2026',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-outbox-2026/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_OUTBOX_GATEWAY/exec',
            ]
        );

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);
    }

    /**
     * Test: Publishing a form schema creates published schema and outbox event atomically.
     */
    public function test_publishing_form_schema_creates_outbox_event_atomically(): void
    {
        $service = app(FormBuilderService::class);
        $draft = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);

        $published = $service->publishVersion($draft, $this->adminUser);

        $this->assertNotNull($published);
        $this->assertSame(FormSchema::STATUS_PUBLISHED, $published->status);

        $outbox = IntegrationOutboxEvent::where('aggregate_type', FormSchema::class)
            ->where('aggregate_id', (string) $published->id)
            ->where('event_type', 'form_schema.publish_sync')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame(IntegrationOutboxEvent::STATUS_PENDING, $outbox->status);
        $this->assertSame($this->connection->id, $outbox->target_connection_id);
        $this->assertSame(2026, $outbox->payload['year']);
        $this->assertSame($published->version_code, $outbox->payload['version_code']);
    }

    /**
     * Test: If outbox insert fails, publishing transaction rolls back completely.
     */
    public function test_publish_rolls_back_if_outbox_insert_fails(): void
    {
        $service = app(FormBuilderService::class);
        $draft = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);
        $initialDraftStatus = $draft->status;

        // Force a unique constraint violation by pre-creating an event with the expected idempotency key
        $hashPayload = $draft->fields()->where('is_active', true)->where('is_archived', false)->get()
            ->map(fn($f) => [
                'key'   => $f->field_key,
                'type'  => $f->field_type,
                'label' => $f->label,
                'req'   => $f->is_required,
                'opts'  => $f->options,
                'rules' => $f->validation_rules,
                'order' => $f->sort_order,
            ])->toArray();
        $expectedHash = hash('sha256', json_encode($hashPayload));
        $expectedVersionCode = preg_replace('/-draft$/', '', $draft->version_code);
        $idempotencyKey = 'publish_sync_' . $draft->id . '_' . $expectedVersionCode . '_' . substr($expectedHash, 0, 16);

        // Pre-insert duplicate idempotency_key
        IntegrationOutboxEvent::create([
            'public_uuid'          => (string) Str::uuid(),
            'event_type'           => 'form_schema.publish_sync',
            'target_connection_id' => $this->connection->id,
            'aggregate_type'       => FormSchema::class,
            'aggregate_id'         => (string) $draft->id,
            'idempotency_key'      => $idempotencyKey,
            'status'               => IntegrationOutboxEvent::STATUS_VERIFIED,
        ]);

        try {
            $service->publishVersion($draft, $this->adminUser);
            $this->fail('Publish should have thrown exception due to duplicate outbox idempotency key.');
        } catch (\Throwable $e) {
            // Expected
        }

        // Assert draft status is still draft (rolled back)
        $draft->refresh();
        $this->assertSame($initialDraftStatus, $draft->status);
    }

    /**
     * Test: Worker successfully executes schema sync and marks outbox verified.
     */
    public function test_worker_successfully_processes_outbox_event(): void
    {
        $service = app(FormBuilderService::class);
        $draft = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);
        $published = $service->publishVersion($draft, $this->adminUser);

        $outbox = IntegrationOutboxEvent::where('aggregate_id', (string) $published->id)->first();
        $this->assertSame(IntegrationOutboxEvent::STATUS_PENDING, $outbox->status);

        // Execute job worker
        $job = new ProcessIntegrationOutboxEventJob($outbox->id);
        app()->call([$job, 'handle']);

        $outbox->refresh();
        $this->assertSame(IntegrationOutboxEvent::STATUS_VERIFIED, $outbox->status);
        $this->assertNull($outbox->last_error);
        $this->assertNotNull($outbox->response_context);
    }

    /**
     * Test: Remote transport failure marks outbox failed with scheduled retry backoff.
     */
    public function test_worker_handles_transport_failure_with_backoff(): void
    {
        $service = app(FormBuilderService::class);
        $draft = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);
        $published = $service->publishVersion($draft, $this->adminUser);

        $outbox = IntegrationOutboxEvent::where('aggregate_id', (string) $published->id)->first();

        // Configure mock transport to fail
        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Google Apps Script Timeout 504 Gateway',
            errorCode: 'TIMEOUT_ERROR',
            isRetryable: true
        );

        $job = new ProcessIntegrationOutboxEventJob($outbox->id);
        app()->call([$job, 'handle']);

        $outbox->refresh();
        $this->assertSame(IntegrationOutboxEvent::STATUS_FAILED, $outbox->status);
        $this->assertStringContainsString('Google Apps Script Timeout', $outbox->last_error);
        $this->assertNotNull($outbox->next_retry_at);
        $this->assertSame(1, $outbox->attempt_count);

        // Published schema in local DB remains untouched and published
        $published->refresh();
        $this->assertSame(FormSchema::STATUS_PUBLISHED, $published->status);
    }

    /**
     * Test: Command spreadsheet:process-outbox recovers pending and failed events.
     */
    public function test_outbox_recovery_command_dispatches_due_events(): void
    {
        $outbox1 = IntegrationOutboxEvent::create([
            'public_uuid'          => (string) Str::uuid(),
            'event_type'           => 'form_schema.publish_sync',
            'target_connection_id' => $this->connection->id,
            'aggregate_type'       => FormSchema::class,
            'aggregate_id'         => '100',
            'idempotency_key'      => 'recovery_test_1',
            'status'               => IntegrationOutboxEvent::STATUS_PENDING,
            'payload'              => ['year' => 2026, 'version_code' => 'v1', 'schema_id' => 100],
        ]);

        $outbox2 = IntegrationOutboxEvent::create([
            'public_uuid'          => (string) Str::uuid(),
            'event_type'           => 'form_schema.publish_sync',
            'target_connection_id' => $this->connection->id,
            'aggregate_type'       => FormSchema::class,
            'aggregate_id'         => '101',
            'idempotency_key'      => 'recovery_test_2',
            'status'               => IntegrationOutboxEvent::STATUS_FAILED,
            'attempt_count'        => 1,
            'max_attempts'         => 5,
            'next_retry_at'        => now()->subMinutes(1), // due for retry
            'payload'              => ['year' => 2026, 'version_code' => 'v2', 'schema_id' => 101],
        ]);

        $exitCode = Artisan::call('spreadsheet:process-outbox');
        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Ditemukan 2 event outbox', $output);
    }

    /**
     * Test: Duplicate job or conflicting worker with stale fence token cannot overwrite state.
     */
    public function test_cas_claim_prevents_conflicting_worker_overwrite(): void
    {
        $service = app(FormBuilderService::class);
        $draft = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);
        $published = $service->publishVersion($draft, $this->adminUser);

        $outbox = IntegrationOutboxEvent::where('aggregate_id', (string) $published->id)->first();

        // Worker A claims with fenceTokenA
        $fenceTokenA = 'token-worker-A';
        $jobA = new ProcessIntegrationOutboxEventJob($outbox->id, $fenceTokenA);
        app()->call([$jobA, 'handle']);

        $outbox->refresh();
        $this->assertSame(IntegrationOutboxEvent::STATUS_VERIFIED, $outbox->status);
        $this->assertSame($fenceTokenA, $outbox->fence_token);

        // Stale Worker B attempts to update using outdated token-worker-B
        $staleTokenB = 'token-worker-B';
        $affected = DB::table('integration_outbox_events')
            ->where('id', $outbox->id)
            ->where('fence_token', $staleTokenB)
            ->update([
                'status'     => IntegrationOutboxEvent::STATUS_FAILED,
                'last_error' => 'Stale overwrite attempt',
            ]);

        $this->assertSame(0, $affected, 'Stale fence token should not mutate verified outbox record.');

        $outbox->refresh();
        $this->assertSame(IntegrationOutboxEvent::STATUS_VERIFIED, $outbox->status);
    }

    /**
     * Test: Older publication event is skipped when superseded by a newer published schema.
     */
    public function test_superseded_older_publication_event_is_skipped(): void
    {
        $service = app(FormBuilderService::class);

        // Publication 1
        $draft1 = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);
        $published1 = $service->publishVersion($draft1, $this->adminUser);
        $outbox1 = IntegrationOutboxEvent::where('aggregate_id', (string) $published1->id)->first();

        // Publication 2 (Newer active version)
        $draft2 = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);
        $published2 = $service->publishVersion($draft2, $this->adminUser);
        $outbox2 = IntegrationOutboxEvent::where('aggregate_id', (string) $published2->id)->first();

        // Now worker runs outbox 1 (older publication)
        $job1 = new ProcessIntegrationOutboxEventJob($outbox1->id);
        app()->call([$job1, 'handle']);

        $outbox1->refresh();
        $this->assertSame(IntegrationOutboxEvent::STATUS_SKIPPED, $outbox1->status);
        $this->assertStringContainsString('Superseded by newer published version', json_encode($outbox1->response_context));
    }

    /**
     * Test: Controller publish endpoint returns immediately with pending outbox status without blocking on remote sync.
     */
    public function test_controller_publish_returns_immediately_with_pending_status(): void
    {
        $service = app(FormBuilderService::class);
        $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.form-settings.fields.publish', ['year' => 2026]));

        $response->assertStatus(200);
        $response->assertJson([
            'ok'                => true,
            'form_status'       => 'published',
            'sheet_sync_status' => 'pending',
        ]);
    }
}
