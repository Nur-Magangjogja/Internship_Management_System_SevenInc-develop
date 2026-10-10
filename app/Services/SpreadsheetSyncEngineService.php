<?php

namespace App\Services;

use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\FollowUpCycle;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SpreadsheetTransport\NullSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SpreadsheetSyncEngineService
{
    protected SpreadsheetTransportInterface $transport;
    protected RegistrationSpreadsheetMappingService $registrationMappingService;
    protected FollowUpSpreadsheetMappingService $followUpMappingService;

    public function __construct(
        ?SpreadsheetTransportInterface $transport = null,
        ?RegistrationSpreadsheetMappingService $registrationMappingService = null,
        ?FollowUpSpreadsheetMappingService $followUpMappingService = null
    ) {
        $this->transport = $transport ?? new NullSpreadsheetTransport();
        $this->registrationMappingService = $registrationMappingService ?? app(RegistrationSpreadsheetMappingService::class);
        $this->followUpMappingService = $followUpMappingService ?? app(FollowUpSpreadsheetMappingService::class);
    }

    public function setTransport(SpreadsheetTransportInterface $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    public function getTransport(): SpreadsheetTransportInterface
    {
        return $this->transport;
    }

    /**
     * Generate permanent, immutable canonical sync ID for a domain model.
     */
    public function generateExternalSyncId(InternshipRegistration|FollowUpCycle $entity): string
    {
        if ($entity instanceof InternshipRegistration) {
            $uuidFragment = substr(str_replace('-', '', (string) ($entity->public_uuid ?? md5((string) $entity->id))), 0, 8);
            return "REG-{$entity->id}-{$uuidFragment}";
        }

        if ($entity instanceof FollowUpCycle) {
            $uuidFragment = substr(str_replace('-', '', (string) ($entity->public_uuid ?? md5((string) $entity->id))), 0, 8);
            return "FWU-{$entity->id}-{$uuidFragment}";
        }

        throw new DomainException('Tipe entitas tidak didukung untuk sinkronisasi spreadsheet.');
    }

    /**
     * Enqueue outbound sync record for an InternshipRegistration.
     * Nonblocking and strictly transactional.
     */
    public function queueSyncForRegistration(InternshipRegistration $registration): ?SpreadsheetRowSync
    {
        // Guardrail: Draft registrations are strictly excluded from spreadsheet sync
        if (!$this->registrationMappingService->isEligibleForSync($registration)) {
            return null;
        }

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$connection) {
            return null;
        }

        if (!$registration->created_at) {
            return null;
        }

        $targetTab = (string) $registration->created_at->year;
        $externalSyncId = $this->generateExternalSyncId($registration);

        $rowSync = SpreadsheetRowSync::firstOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'syncable_type'             => InternshipRegistration::class,
                'syncable_id'               => $registration->id,
            ],
            [
                'public_uuid'      => (string) Str::uuid(),
                'external_sync_id' => $externalSyncId,
                'target_sheet_tab' => $targetTab,
                'schema_version'   => 'v1',
                'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at'    => now(),
            ]
        );

        // If existing record was previously synced or failed, reset to pending for re-sync
        if ($rowSync->wasRecentlyCreated === false) {
            $rowSync->update([
                'target_sheet_tab' => $targetTab,
                'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at'    => now(),
                'error_message'    => null,
                'locked_at'        => null,
                'locked_by'        => null,
            ]);
        }

        // Dispatch background job strictly after current DB transaction commits (Guardrail 2)
        DB::afterCommit(function () use ($rowSync) {
            ProcessSpreadsheetRowSyncJob::dispatch($rowSync->id);
        });

        return $rowSync;
    }

    /**
     * Enqueue outbound sync record for a FollowUpCycle.
     * Nonblocking and strictly transactional.
     */
    public function queueSyncForFollowUpCycle(FollowUpCycle $cycle): ?SpreadsheetRowSync
    {
        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();
        if (!$connection) {
            return null;
        }

        $targetTab = 'Follow Up Guru Dosen';
        $externalSyncId = $this->generateExternalSyncId($cycle);

        $rowSync = SpreadsheetRowSync::firstOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'syncable_type'             => FollowUpCycle::class,
                'syncable_id'               => $cycle->id,
            ],
            [
                'public_uuid'      => (string) Str::uuid(),
                'external_sync_id' => $externalSyncId,
                'target_sheet_tab' => $targetTab,
                'schema_version'   => 'v1',
                'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at'    => now(),
            ]
        );

        if ($rowSync->wasRecentlyCreated === false) {
            $rowSync->update([
                'target_sheet_tab' => $targetTab,
                'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at'    => now(),
                'error_message'    => null,
                'locked_at'        => null,
                'locked_by'        => null,
            ]);
        }

        DB::afterCommit(function () use ($rowSync) {
            ProcessSpreadsheetRowSyncJob::dispatch($rowSync->id);
        });

        return $rowSync;
    }

    /**
     * Process a single SpreadsheetRowSync record with lease mutex and no-lost-updates protection.
     *
     * @param SpreadsheetRowSync $rowSync
     * @param SpreadsheetTransportInterface|null $customTransport
     * @return bool
     */
    public function processSyncRecord(
        SpreadsheetRowSync $rowSync,
        ?SpreadsheetTransportInterface $customTransport = null
    ): bool {
        $transport = $customTransport ?? $this->transport;
        $fenceToken = (string) Str::uuid();

        // 1. Atomic claim lease to prevent race condition across concurrent workers (Guardrail 1)
        $claimed = DB::table('spreadsheet_row_syncs')
            ->where('id', $rowSync->id)
            ->where(function ($query) {
                $query->where('sync_status', '!=', SpreadsheetRowSync::STATUS_PROCESSING)
                      ->orWhere('locked_at', '<', now()->subMinutes(5))
                      ->orWhereNull('locked_at');
            })
            ->update([
                'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
                'locked_at'   => now(),
                'locked_by'   => $fenceToken,
                'updated_at'  => now(),
            ]);

        if ($claimed === 0) {
            // Another worker actively owns the lease
            return false;
        }

        $rowSync->refresh();

        // 2. Validate connection configuration
        $connection = $rowSync->spreadsheetConnection;
        if (!$connection || !$connection->is_enabled || empty($connection->spreadsheet_url)) {
            $errMsg = 'Koneksi spreadsheet dinonaktifkan atau URL belum dikonfigurasi.';
            $rowSync->update([
                'sync_status'     => SpreadsheetRowSync::STATUS_FAILED,
                'last_attempt_at' => now(),
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
            ]);
            $this->logTerminalSyncFailure($rowSync, $errMsg);
            return false;
        }

        // 3. Build fresh outbound payload dynamically from current domain state
        try {
            $payload = $this->buildPayloadForRecord($rowSync);
        } catch (Throwable $e) {
            $errMsg = 'Gagal membentuk payload data: ' . $this->sanitizeErrorMessage($e->getMessage());
            $rowSync->update([
                'sync_status'     => SpreadsheetRowSync::STATUS_FAILED,
                'last_attempt_at' => now(),
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
            ]);
            $this->logTerminalSyncFailure($rowSync, $errMsg);
            return false;
        }

        $initialChecksum = hash('sha256', json_encode($payload));
        $newAttemptCount = $rowSync->attempt_count + 1;

        // Idempotency check: if payload checksum was previously recorded and is unchanged, restore synced status and no-op skip
        if (!empty($rowSync->payload_checksum) && $rowSync->payload_checksum === $initialChecksum) {
            $rowSync->update([
                'sync_status' => SpreadsheetRowSync::STATUS_SYNCED,
                'locked_at'   => null,
                'locked_by'   => null,
            ]);
            return true;
        }

        // Add dynamic metadata for remote payload
        $transportPayload = $payload;
        $transportPayload['SERTIF_LAST_SYNC_AT'] = now()->toIso8601String();

        // 4. Dispatch remote HTTP upsert to transport
        $response = $transport->sendRowUpsert(
            connection: $connection,
            tabName: $rowSync->target_sheet_tab,
            syncId: $rowSync->external_sync_id,
            payload: $transportPayload
        );

        // 5. Verify fence token has not been overtaken during HTTP roundtrip (Guardrail 1)
        $currentRecord = DB::table('spreadsheet_row_syncs')->where('id', $rowSync->id)->first();
        if (!$currentRecord || $currentRecord->locked_by !== $fenceToken) {
            // Lease was reclaimed or reset by another process; discard stale write
            return false;
        }

        // 6. Check for mid-flight source update during HTTP request (Guardrail 1)
        try {
            $postHttpPayload = $this->buildPayloadForRecord($rowSync);
            $postHttpChecksum = hash('sha256', json_encode($postHttpPayload));
        } catch (Throwable) {
            $postHttpChecksum = $initialChecksum;
        }

        if ($postHttpChecksum !== $initialChecksum) {
            // Source record was mutated while HTTP request was in-flight!
            // Requeue as pending so latest snapshot is pushed on next cycle without losing updates.
            $rowSync->update([
                'sync_status'     => SpreadsheetRowSync::STATUS_PENDING,
                'last_attempt_at' => now(),
                'next_retry_at'   => now(),
                'error_message'   => 'Data sumber mengalami pembaruan saat sinkronisasi berlangsung. Menjadwalkan ulang snapshot terbaru.',
                'locked_at'       => null,
                'locked_by'       => null,
            ]);
            return false;
        }

        // 7. Handle Transport Outcome
        if ($response->success) {
            $rowSync->update([
                'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
                'payload_checksum' => $initialChecksum,
                'last_synced_at'   => now(),
                'last_attempt_at'  => now(),
                'row_index'        => $response->rowIndex,
                'error_message'    => null,
                'locked_at'        => null,
                'locked_by'        => null,
                'next_retry_at'    => null,
            ]);
            return true;
        }

        // Failure handling with backoff
        if ($response->isRetryable && $newAttemptCount < $rowSync->max_attempts) {
            $delaySeconds = SpreadsheetRowSync::calculateBackoffSeconds($newAttemptCount);
            $rowSync->update([
                'sync_status'     => SpreadsheetRowSync::STATUS_RETRYING,
                'attempt_count'   => $newAttemptCount,
                'last_attempt_at' => now(),
                'next_retry_at'   => now()->addSeconds($delaySeconds),
                'error_message'   => $this->sanitizeErrorMessage($response->errorMessage ?? 'Sinkronisasi gagal.'),
                'locked_at'       => null,
                'locked_by'       => null,
            ]);
        } else {
            $errMsg = $this->sanitizeErrorMessage($response->errorMessage ?? 'Maksimal batas percobaan terlampaui.');
            $rowSync->update([
                'sync_status'     => SpreadsheetRowSync::STATUS_FAILED,
                'attempt_count'   => $newAttemptCount,
                'last_attempt_at' => now(),
                'next_retry_at'   => null,
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
            ]);
            $this->logTerminalSyncFailure($rowSync, $errMsg);
        }

        return false;
    }

    /**
     * Build outbound payload for a SpreadsheetRowSync record.
     */
    protected function buildPayloadForRecord(SpreadsheetRowSync $rowSync): array
    {
        $entity = $rowSync->syncable()->first();

        if (!$entity) {
            throw new DomainException('Entitas sumber data tidak ditemukan.');
        }

        if ($entity instanceof InternshipRegistration) {
            $payload = $this->registrationMappingService->buildOutboundRowPayload($entity);
            $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
            $payload['SERTIF_SYNC_STATUS'] = 'synced';
            return $payload;
        }

        if ($entity instanceof FollowUpCycle) {
            $payload = $this->followUpMappingService->buildOutboundRowPayload($entity, $rowSync->row_index ?? 1);
            $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
            $payload['SERTIF_SYNC_STATUS'] = 'synced';
            return $payload;
        }

        throw new DomainException('Tipe entitas tidak dikenali.');
    }

    /**
     * Trigger manual retry for a specific sync row by Admin.
     */
    public function retrySyncRecord(
        SpreadsheetRowSync $rowSync,
        ?User $actor = null,
        ?SpreadsheetTransportInterface $customTransport = null
    ): bool {
        $rowSync->update([
            'attempt_count' => 0,
            'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at' => now(),
            'error_message' => null,
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        if ($actor) {
            ActivityLogger::log(
                action: 'spreadsheet_sync_retried',
                description: "Admin {$actor->name} memicu retry manual sinkronisasi baris {$rowSync->external_sync_id}",
                subject: $rowSync,
                actor: $actor,
                metadata: [
                    'connection'       => $rowSync->spreadsheetConnection?->display_name,
                    'external_sync_id' => $rowSync->external_sync_id,
                ]
            );
        }

        return $this->processSyncRecord($rowSync, $customTransport);
    }

    /**
     * Batch retry all failed or retrying records for a connection.
     */
    public function retryAllFailed(SpreadsheetConnection $connection, ?User $actor = null): int
    {
        $failedSyncs = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
            ->whereIn('sync_status', [SpreadsheetRowSync::STATUS_FAILED, SpreadsheetRowSync::STATUS_RETRYING])
            ->get();

        $count = 0;
        foreach ($failedSyncs as $sync) {
            $sync->update([
                'attempt_count' => 0,
                'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at' => now(),
                'error_message' => null,
                'locked_at'     => null,
                'locked_by'     => null,
            ]);

            DB::afterCommit(function () use ($sync) {
                ProcessSpreadsheetRowSyncJob::dispatch($sync->id);
            });

            $count++;
        }

        if ($actor && $count > 0) {
            ActivityLogger::log(
                action: 'spreadsheet_batch_retry_triggered',
                description: "Admin {$actor->name} memicu batch retry untuk {$count} baris gagal pada {$connection->display_name}",
                subject: $connection,
                actor: $actor,
                metadata: [
                    'connection_key' => $connection->connection_key,
                    'retried_count'  => $count,
                ]
            );
        }

        return $count;
    }

    /**
     * Recovery scanner for pending or retrying sync records that are due (Guardrail 2).
     */
    public function scanAndDispatchPendingSyncs(int $limit = 50): int
    {
        $dueSyncs = SpreadsheetRowSync::whereIn('sync_status', [SpreadsheetRowSync::STATUS_PENDING, SpreadsheetRowSync::STATUS_RETRYING])
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                      ->orWhere('next_retry_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('locked_at')
                      ->orWhere('locked_at', '<', now()->subMinutes(5));
            })
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($dueSyncs as $sync) {
            ProcessSpreadsheetRowSyncJob::dispatch($sync->id);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Sanitize error message to ensure zero secret/credential leakage.
     */
    protected function sanitizeErrorMessage(?string $message): ?string
    {
        if (empty($message)) {
            return null;
        }

        // Strip query params and auth tokens
        $clean = preg_replace('/(\?|&)(key|token|auth|secret|password|bearer)=[^&]+/i', '$1$2=REDACTED', $message);
        $clean = preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]+/i', '$1REDACTED', $clean);

        return mb_substr($clean, 0, 1000);
    }

    /**
     * Safely log terminal failure of a spreadsheet sync record.
     */
    protected function logTerminalSyncFailure(SpreadsheetRowSync $rowSync, string $errorMessage): void
    {
        try {
            $connectionKey = $rowSync->spreadsheetConnection?->connection_key ?? 'unknown';
            \App\Services\ActivityLogger::log(
                action: 'spreadsheet_sync_failed_terminal',
                description: "Sinkronisasi spreadsheet '{$connectionKey}' untuk tab '{$rowSync->target_sheet_tab}' mengalami kegagalan permanen.",
                subject: $rowSync,
                metadata: [
                    'sync_id'          => $rowSync->id,
                    'external_sync_id' => $rowSync->external_sync_id,
                    'connection_key'   => $connectionKey,
                    'target_sheet_tab' => $rowSync->target_sheet_tab,
                    'attempt_count'    => $rowSync->attempt_count,
                    'max_attempts'     => $rowSync->max_attempts,
                    'error_summary'    => $this->sanitizeErrorMessage($errorMessage),
                ]
            );
        } catch (\Throwable) {
            // Logging failure must never crash the sync engine
        }
    }
}
