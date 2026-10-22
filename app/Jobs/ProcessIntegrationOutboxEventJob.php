<?php

namespace App\Jobs;

use App\Models\FormSchema;
use App\Models\IntegrationOutboxEvent;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ProcessIntegrationOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Retry logic is driven by outbox retry loop and next_retry_at
    public int $timeout = 90;
    public string $fenceToken;

    public function __construct(
        public int $outboxEventId,
        ?string $fenceToken = null
    ) {
        $this->fenceToken = $fenceToken ?: (string) Str::uuid();
    }

    /**
     * Execute the job.
     */
    public function handle(
        SpreadsheetSyncEngineService $syncEngine,
        RegistrationSpreadsheetMappingService $mappingService,
        SpreadsheetTransportInterface $transport
    ): void {
        $event = IntegrationOutboxEvent::find($this->outboxEventId);

        if (!$event) {
            return;
        }

        // If event is already in terminal state, ignore
        if (in_array($event->status, [IntegrationOutboxEvent::STATUS_VERIFIED, IntegrationOutboxEvent::STATUS_SKIPPED], true)) {
            return;
        }

        // Only handle form_schema.publish_sync in this specialized worker
        if ($event->event_type !== 'form_schema.publish_sync') {
            return;
        }

        // CAS Atomic Claim: only claim if pending, failed (due for retry), or stale processing (> 5 mins)
        $hasFenceToken = Schema::hasColumn('integration_outbox_events', 'fence_token');

        $claimData = [
            'status'          => IntegrationOutboxEvent::STATUS_PROCESSING,
            'last_attempt_at' => now(),
            'attempt_count'   => DB::raw('attempt_count + 1'),
            'updated_at'      => now(),
        ];
        if ($hasFenceToken) {
            $claimData['fence_token'] = $this->fenceToken;
        }

        $claimed = DB::table('integration_outbox_events')
            ->where('id', $this->outboxEventId)
            ->where(function ($query) {
                $query->whereIn('status', [IntegrationOutboxEvent::STATUS_PENDING, IntegrationOutboxEvent::STATUS_FAILED])
                      ->orWhere(function ($sq) {
                          $sq->where('status', IntegrationOutboxEvent::STATUS_PROCESSING)
                             ->where('last_attempt_at', '<', now()->subMinutes(5));
                      });
            })
            ->update($claimData);

        if ($claimed === 0) {
            // Already claimed by an active worker or transitioned to terminal state
            return;
        }

        $event->refresh();

        $year = (int) ($event->payload['year'] ?? now()->year);
        $connId = $event->target_connection_id;

        // Shared advisory lock / distributed lock per target connection + year
        $lockKey = "schema_sync_{$connId}_{$year}";
        $lock = Cache::lock($lockKey, 60);

        try {
            $lock->block(15);
        } catch (\Throwable) {
            // Cannot acquire lock in time; reschedule retry with backoff
            $this->rescheduleWithBackoff($event, 'Koneksi sedang dikunci oleh worker schema lain.');
            return;
        }

        try {
            $connection = $event->targetConnection ?: SpreadsheetConnection::find($connId);

            if (!$connection || !$connection->is_enabled || empty($connection->spreadsheet_id)) {
                $updateQuery = DB::table('integration_outbox_events')->where('id', $event->id);
                if ($hasFenceToken) {
                    $updateQuery->where('fence_token', $this->fenceToken);
                }
                $updateQuery->update([
                    'status'           => IntegrationOutboxEvent::STATUS_SKIPPED,
                    'response_context' => json_encode(['reason' => 'Target connection not configured or disabled']),
                    'updated_at'       => now(),
                ]);
                return;
            }

            // Check if superseded: if there is a newer published schema for this year
            $publishedSchemaId = $event->payload['schema_id'] ?? null;
            $latestPublished = FormSchema::where('year', $year)
                ->where('status', FormSchema::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->first();

            if ($latestPublished && $publishedSchemaId && (int) $latestPublished->id !== (int) $publishedSchemaId) {
                $updateQuery = DB::table('integration_outbox_events')->where('id', $event->id);
                if ($hasFenceToken) {
                    $updateQuery->where('fence_token', $this->fenceToken);
                }
                $updateQuery->update([
                    'status'           => IntegrationOutboxEvent::STATUS_SKIPPED,
                    'response_context' => json_encode(['reason' => "Superseded by newer published version {$latestPublished->version_code}"]),
                    'updated_at'       => now(),
                ]);
                return;
            }

            // Reconcile schema and column mappings in database
            $targetSchema = $latestPublished ?: ($publishedSchemaId ? FormSchema::find($publishedSchemaId) : null);
            $schemaVersion = $mappingService->syncSpreadsheetSchemaWithFormSchema($year, $targetSchema);

            if (!$schemaVersion) {
                $this->rescheduleWithBackoff($event, 'Gagal menyelaraskan skema kolom lokal.');
                return;
            }

            $headers = $schemaVersion->columnMappings()
                ->where('mapping_status', 'active')
                ->orderBy('column_order', 'asc')
                ->pluck('sheet_header')
                ->all();

            if (empty($headers)) {
                $this->rescheduleWithBackoff($event, 'Header skema kolom kosong.');
                return;
            }

            // Execute remote schema write
            try {
                $resp = $transport->syncSheetSchema($connection, (string) $year, $headers);
            } catch (\Throwable $e) {
                $resp = SpreadsheetTransportResponse::failure(
                    errorMessage: 'Transport exception: ' . $e->getMessage(),
                    errorCode: 'TRANSPORT_EXCEPTION',
                    isRetryable: true
                );
            }

            if ($resp->success) {
                $updateQuery = DB::table('integration_outbox_events')->where('id', $event->id);
                if ($hasFenceToken) {
                    $updateQuery->where('fence_token', $this->fenceToken);
                }
                $updateQuery->update([
                    'status'           => IntegrationOutboxEvent::STATUS_VERIFIED,
                    'last_error'       => null,
                    'response_context' => json_encode([
                        'synced_at'     => now()->toIso8601String(),
                        'headers_count' => count($headers),
                        'tab_name'      => (string) $year,
                    ]),
                    'updated_at'       => now(),
                ]);

                // Asynchronously delegate row re-sync to separate row workers
                try {
                    $registrations = InternshipRegistration::whereYear('created_at', $year)
                        ->where('is_draft', false)
                        ->get();

                    foreach ($registrations as $reg) {
                        if ($mappingService->isEligibleForSync($reg)) {
                            $syncEngine->queueSyncForRegistration($reg);
                        }
                    }
                } catch (\Throwable) {
                    // Row sync enqueue failure does not invalidate verified schema status
                }
            } else {
                $this->rescheduleWithBackoff($event, $resp->errorMessage ?: 'Remote schema sync failed', $resp->isRetryable);
            }
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Reschedule event failure with backoff or mark permanent failure.
     */
    protected function rescheduleWithBackoff(IntegrationOutboxEvent $event, string $errorMessage, bool $isRetryable = true): void
    {
        $attempt = $event->attempt_count;
        $maxAttempts = $event->max_attempts ?: 5;
        $canRetry = $isRetryable && ($attempt < $maxAttempts);
        $nextRetry = $canRetry ? now()->addSeconds(min(3600, (int) pow(2, $attempt) * 10)) : null;

        $hasFenceToken = Schema::hasColumn('integration_outbox_events', 'fence_token');
        $hasRetryColumn = Schema::hasColumn('integration_outbox_events', 'next_retry_at');

        $updateData = [
            'status'     => IntegrationOutboxEvent::STATUS_FAILED,
            'last_error' => $errorMessage,
            'updated_at' => now(),
        ];
        if ($hasRetryColumn) {
            $updateData['next_retry_at'] = $nextRetry;
        }

        $query = DB::table('integration_outbox_events')->where('id', $event->id);
        if ($hasFenceToken) {
            $query->where('fence_token', $this->fenceToken);
        }
        $query->update($updateData);
    }

    /**
     * Handle job worker failure.
     */
    public function failed(?Throwable $exception): void
    {
        $errMsg = $exception ? 'Outbox worker failed: ' . $exception->getMessage() : 'Outbox worker failed unexpectedly.';
        $hasFenceToken = Schema::hasColumn('integration_outbox_events', 'fence_token');

        $query = DB::table('integration_outbox_events')
            ->where('id', $this->outboxEventId)
            ->where('status', IntegrationOutboxEvent::STATUS_PROCESSING);

        if ($hasFenceToken) {
            $query->where('fence_token', $this->fenceToken);
        }

        $query->update([
            'status'     => IntegrationOutboxEvent::STATUS_FAILED,
            'last_error' => $errMsg,
            'updated_at' => now(),
        ]);
    }
}
