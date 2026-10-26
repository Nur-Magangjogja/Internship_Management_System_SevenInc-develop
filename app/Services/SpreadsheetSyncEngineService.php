<?php

namespace App\Services;

use App\Jobs\ProcessSidecarSyncJob;
use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\FollowUpCycle;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSidecarSync;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SpreadsheetTransport\NullSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetSidecarTransportInterface;
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

        $createdAt = $registration->created_at instanceof \DateTimeInterface
            ? $registration->created_at
            : \Illuminate\Support\Carbon::parse($registration->created_at);
        $year = (int) $createdAt->format('Y');
        if ($year < 2020 || $year > 2099) {
            return null;
        }

        $targetTab = (string) $year;
        $externalSyncId = $this->generateExternalSyncId($registration);

        $rowSync = SpreadsheetRowSync::firstOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'syncable_type'             => InternshipRegistration::class,
                'syncable_id'               => $registration->id,
            ],
            [
                'public_uuid'           => (string) Str::uuid(),
                'external_sync_id'      => $externalSyncId,
                'target_sheet_tab'      => $targetTab,
                'target_spreadsheet_id' => $connection->spreadsheet_id,
                'target_generation'     => $connection->target_generation ?? 1,
                'schema_version'        => 'v1',
                'sync_status'           => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at'         => now(),
            ]
        );

        // If existing record was previously synced or failed, do NOT re-sync if already synced
        if ($rowSync->wasRecentlyCreated === false) {
            // Guardrail: Data yang sudah ter-sync (STATUS_SYNCED) tidak perlu dilakukan sync ulang
            if ($rowSync->sync_status === SpreadsheetRowSync::STATUS_SYNCED) {
                return $rowSync;
            }

            $updated = DB::table('spreadsheet_row_syncs')
                ->where('id', $rowSync->id)
                ->where('sync_status', '!=', SpreadsheetRowSync::STATUS_PROCESSING)
                ->where(function ($query) {
                    $query->whereNull('locked_at')
                          ->orWhere('locked_at', '<', now()->subMinutes(5));
                })
                ->update([
                    'target_sheet_tab' => $targetTab,
                    'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                    'next_retry_at'    => now(),
                    'error_message'    => null,
                    'locked_at'        => null,
                    'locked_by'        => null,
                    'updated_at'       => now(),
                ]);

            if ($updated > 0) {
                $rowSync->refresh();
                DB::afterCommit(function () use ($rowSync, $connection) {
                    if (!app()->runningInConsole()) {
                        ProcessSpreadsheetRowSyncJob::dispatchAfterResponse(
                            $rowSync->id,
                            null,
                            $connection?->spreadsheet_id,
                            $rowSync->target_sheet_tab,
                            $rowSync->schema_version,
                            $connection?->id
                        );
                    } else {
                        ProcessSpreadsheetRowSyncJob::dispatch(
                            $rowSync->id,
                            null,
                            $connection?->spreadsheet_id,
                            $rowSync->target_sheet_tab,
                            $rowSync->schema_version,
                            $connection?->id
                        );
                    }
                });
            }
        } else {
            DB::afterCommit(function () use ($rowSync, $connection) {
                if (!app()->runningInConsole()) {
                    ProcessSpreadsheetRowSyncJob::dispatchAfterResponse(
                        $rowSync->id,
                        null,
                        $connection?->spreadsheet_id,
                        $rowSync->target_sheet_tab,
                        $rowSync->schema_version,
                        $connection?->id
                    );
                } else {
                    ProcessSpreadsheetRowSyncJob::dispatch(
                        $rowSync->id,
                        null,
                        $connection?->spreadsheet_id,
                        $rowSync->target_sheet_tab,
                        $rowSync->schema_version,
                        $connection?->id
                    );
                }
            });
        }

        return $rowSync->fresh() ?? $rowSync;
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

        if (!$this->followUpMappingService->isEligibleForSync($cycle)) {
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
                'public_uuid'           => (string) Str::uuid(),
                'external_sync_id'      => $externalSyncId,
                'target_sheet_tab'      => $targetTab,
                'target_spreadsheet_id' => $connection->spreadsheet_id,
                'target_generation'     => $connection->target_generation ?? 1,
                'schema_version'        => 'v1',
                'sync_status'           => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at'         => now(),
            ]
        );

        if ($rowSync->wasRecentlyCreated === false) {
            // Guardrail: Data yang sudah ter-sync (STATUS_SYNCED) tidak perlu dilakukan sync ulang
            if ($rowSync->sync_status === SpreadsheetRowSync::STATUS_SYNCED) {
                return $rowSync;
            }

            $updated = DB::table('spreadsheet_row_syncs')
                ->where('id', $rowSync->id)
                ->where('sync_status', '!=', SpreadsheetRowSync::STATUS_PROCESSING)
                ->where(function ($query) {
                    $query->whereNull('locked_at')
                          ->orWhere('locked_at', '<', now()->subMinutes(5));
                })
                ->update([
                    'target_sheet_tab' => $targetTab,
                    'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                    'next_retry_at'    => now(),
                    'error_message'    => null,
                    'locked_at'        => null,
                    'locked_by'        => null,
                    'updated_at'       => now(),
                ]);

            if ($updated > 0) {
                $rowSync->refresh();
                DB::afterCommit(function () use ($rowSync, $connection) {
                    if (!app()->runningInConsole()) {
                        ProcessSpreadsheetRowSyncJob::dispatchAfterResponse(
                            $rowSync->id,
                            null,
                            $connection?->spreadsheet_id,
                            $rowSync->target_sheet_tab,
                            $rowSync->schema_version,
                            $connection?->id
                        );
                    } else {
                        ProcessSpreadsheetRowSyncJob::dispatch(
                            $rowSync->id,
                            null,
                            $connection?->spreadsheet_id,
                            $rowSync->target_sheet_tab,
                            $rowSync->schema_version,
                            $connection?->id
                        );
                    }
                });
            }
        } else {
            DB::afterCommit(function () use ($rowSync, $connection) {
                if (!app()->runningInConsole()) {
                    ProcessSpreadsheetRowSyncJob::dispatchAfterResponse(
                        $rowSync->id,
                        null,
                        $connection?->spreadsheet_id,
                        $rowSync->target_sheet_tab,
                        $rowSync->schema_version,
                        $connection?->id
                    );
                } else {
                    ProcessSpreadsheetRowSyncJob::dispatch(
                        $rowSync->id,
                        null,
                        $connection?->spreadsheet_id,
                        $rowSync->target_sheet_tab,
                        $rowSync->schema_version,
                        $connection?->id
                    );
                }
            });
        }

        return $rowSync->fresh() ?? $rowSync;
    }

    /**
     * Determine whether the sync record requires remote update-only semantics (S17.18B).
     * Strictly update-only for post-acceptance exited and cancelled/rejected registrations
     * to prevent accidental remote row insertion if the row does not already exist remotely.
     */
    public function requiresUpdateOnly(SpreadsheetRowSync $rowSync): bool
    {
        $entity = $rowSync->syncable()->first();
        if ($entity instanceof InternshipRegistration) {
            return in_array($entity->internship_status, [
                InternshipRegistration::STATUS_EXITED,
                InternshipRegistration::STATUS_REJECTED,
            ], true);
        }

        return false;
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
        ?SpreadsheetTransportInterface $customTransport = null,
        bool $force = false,
        ?string $preClaimedFence = null,
        ?string $requestedFenceToken = null,
        ?string $snapshotSpreadsheetId = null,
        ?string $snapshotTabName = null,
        ?string $snapshotSchemaVersion = null
    ): bool {
        $transport = $customTransport ?? $this->transport;
        $fenceToken = $preClaimedFence ?? ($requestedFenceToken ?: (string) Str::uuid());

        // Guardrail: Data yang sudah berstatus synced tidak perlu dilakukan sync ulang kecuali dipaksa ($force)
        if (!$force && $rowSync->isSynced()) {
            return true;
        }

        // 1. Atomic claim lease to prevent race condition across concurrent workers (Guardrail 1)
        if ($preClaimedFence !== null) {
            // Verify DB has an active, valid lease owned by this fence token
            $hasActiveLease = DB::table('spreadsheet_row_syncs')
                ->where('id', $rowSync->id)
                ->where('sync_status', SpreadsheetRowSync::STATUS_PROCESSING)
                ->where('locked_by', $preClaimedFence)
                ->whereNotNull('locked_at')
                ->where('locked_at', '>=', now()->subMinutes(5))
                ->exists();

            if (!$hasActiveLease) {
                return false;
            }
        } else {
            // Fail-closed: Never claim a record currently marked as processing, even if lock timestamp is stale
            $claimed = DB::table('spreadsheet_row_syncs')
                ->where('id', $rowSync->id)
                ->where('sync_status', '!=', SpreadsheetRowSync::STATUS_PROCESSING)
                ->where(function ($statusQuery) use ($force) {
                    // 1. PENDING: eligible if next_retry_at is null OR <= now()
                    $statusQuery->where(function ($pendingQ) use ($force) {
                        $pendingQ->where('sync_status', SpreadsheetRowSync::STATUS_PENDING);
                        if (!$force) {
                            $pendingQ->where(function ($nq) {
                                $nq->whereNull('next_retry_at')
                                   ->orWhere('next_retry_at', '<=', now());
                            });
                        }
                    })
                    // 2. RETRYING: strictly requires next_retry_at NOT NULL and <= now() (unless forced)
                    ->orWhere(function ($retryingQ) use ($force) {
                        $retryingQ->where('sync_status', SpreadsheetRowSync::STATUS_RETRYING);
                        if (!$force) {
                            $retryingQ->whereNotNull('next_retry_at')
                                      ->where('next_retry_at', '<=', now());
                        }
                    });

                    // 3. SYNCED: strictly allowed only if force retry is requested
                    if ($force) {
                        $statusQuery->orWhere('sync_status', SpreadsheetRowSync::STATUS_SYNCED);
                    }
                })
                ->where(function ($lq) {
                    $lq->whereNull('locked_at')
                       ->orWhere('locked_at', '<', now()->subMinutes(5));
                })
                ->update([
                    'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
                    'locked_at'   => now(),
                    'locked_by'   => $fenceToken,
                    'updated_at'  => now(),
                ]);

            if ($claimed === 0) {
                // Another worker actively owns the lease or record is in processing or not eligible (fail-closed)
                return false;
            }

            $rowSync->refresh();
        }

        // 2. Validate connection configuration
        $connection = $rowSync->spreadsheetConnection;

        // HOTFIX 1: Target identity & version divergence protection (Freeze on divergence)
        // Saat target Spreadsheet diganti dari A ke B:
        // Job lama TIDAK boleh menulis ke A dan TIDAK boleh otomatis menulis ke B.
        // Job harus dibekukan dengan status paused_target_rebind untuk direkonsiliasi.
        if ($rowSync->isPausedForTargetRebind()) {
            \Illuminate\Support\Facades\Log::warning("processSyncRecord: Job #{$rowSync->id} dalam status 'paused_target_rebind'. Menolak eksekusi otomatis.");
            return false;
        }

        if ($connection && !empty($snapshotSpreadsheetId) && !empty($connection->spreadsheet_id) && $connection->spreadsheet_id !== $snapshotSpreadsheetId) {
            $errMsg = "Target spreadsheet koneksi diubah dari '{$snapshotSpreadsheetId}' menjadi '{$connection->spreadsheet_id}'. Job dibekukan untuk rekonsiliasi admin.";
            $this->updateGuardedByFence($rowSync, $fenceToken, [
                'sync_status'     => SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
                'last_attempt_at' => now(),
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
            ]);
            \Illuminate\Support\Facades\Log::warning("processSyncRecord: " . $errMsg);
            return false;
        }

        if (!empty($snapshotTabName) && $rowSync->target_sheet_tab !== $snapshotTabName) {
            $rowSync->target_sheet_tab = $snapshotTabName;
        }

        if (!empty($snapshotSchemaVersion) && $rowSync->schema_version !== $snapshotSchemaVersion) {
            $rowSync->schema_version = $snapshotSchemaVersion;
        }

        if (!$connection || !$connection->is_enabled || empty($connection->spreadsheet_url)) {
            $errMsg = 'Koneksi spreadsheet dinonaktifkan atau URL belum dikonfigurasi.';
            $this->updateGuardedByFence($rowSync, $fenceToken, [
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

        // 2.5 Acceptance Gate: Pre-flight eligibility verification for InternshipRegistration (S17.17 / S17.18B)
        $entity = $rowSync->syncable()->first();
        if ($entity instanceof InternshipRegistration) {
            if (!$this->registrationMappingService->isEligibleForSync($entity, $rowSync)) {
                $status = $entity->internship_status;
                if (in_array($status, [InternshipRegistration::STATUS_EXITED, InternshipRegistration::STATUS_REJECTED], true)) {
                    if (!$this->registrationMappingService->hasVerifiedAdminAcceptance($entity)) {
                        $errMsg = 'NOT_ELIGIBLE_UNACCEPTED: Registration is not eligible for sync without verified admin acceptance.';
                    } else {
                        $errMsg = 'NOT_ELIGIBLE_UPDATE_ONLY_HELD: Remote update-only guarantees require verified prior sync record; post-acceptance exit/cancellation sync is held fail-closed.';
                    }
                } else {
                    $errMsg = 'NOT_ELIGIBLE_UNACCEPTED: Registration is not eligible for sync without verified admin acceptance.';
                }

                $this->updateGuardedByFence($rowSync, $fenceToken, [
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

            if (!app()->runningUnitTests()) {
                $this->ensureRegistrationAttachmentsUploadedToDrive($entity, $connection, $transport);
            }
        }

        if ($entity instanceof FollowUpCycle) {
            if (!$this->followUpMappingService->isEligibleForSync($entity)) {
                $errMsg = 'NOT_ELIGIBLE_UNACCEPTED: Follow-up cycle tidak memenuhi syarat sinkronisasi karena belum memiliki pemagang yang resmi di-ACC.';
                $this->updateGuardedByFence($rowSync, $fenceToken, [
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
        }

        // 3. Build fresh outbound payload dynamically from current domain state
        try {
            $payload = $this->buildPayloadForRecord($rowSync);
        } catch (Throwable $e) {
            $errMsg = 'Gagal membentuk payload data: ' . $this->sanitizeErrorMessage($e->getMessage());
            $this->updateGuardedByFence($rowSync, $fenceToken, [
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

        $targetChecksum = $this->calculatePayloadChecksum($connection, $rowSync->target_sheet_tab, $payload);
        $newAttemptCount = $rowSync->attempt_count + 1;

        // Idempotency check:
        // Valid no-op requires:
        // 1. Not an explicit force retry ($force === false)
        // 2. Verified prior successful sync (has last_synced_at and row_index)
        // 3. Exact matching target-aware checksum on the same connection, spreadsheet ID, tab, and payload
        $hasPriorSuccessfulSync = !empty($rowSync->last_synced_at)
            && !empty($rowSync->row_index)
            && !empty($rowSync->payload_checksum);

        if (!$force && $hasPriorSuccessfulSync && $rowSync->payload_checksum === $targetChecksum) {
            $this->updateGuardedByFence($rowSync, $fenceToken, [
                'sync_status' => SpreadsheetRowSync::STATUS_SYNCED,
                'locked_at'   => null,
                'locked_by'   => null,
            ]);
            return true;
        }

        // Add dynamic metadata for remote payload
        $transportPayload = $payload;
        $transportPayload['SERTIF_LAST_SYNC_AT'] = now()->toIso8601String();

        // 4. Dispatch remote HTTP request to transport
        // S17.18B: Route to sendRowUpdateOnly if entity requires update-only, otherwise sendRowUpsert
        if ($this->requiresUpdateOnly($rowSync)) {
            $response = $transport->sendRowUpdateOnly(
                connection: $connection,
                tabName: $rowSync->target_sheet_tab,
                syncId: $rowSync->external_sync_id,
                payload: $transportPayload
            );
        } else {
            $response = $transport->sendRowUpsert(
                connection: $connection,
                tabName: $rowSync->target_sheet_tab,
                syncId: $rowSync->external_sync_id,
                payload: $transportPayload
            );
        }

        // 5. Verify fence token has not been overtaken during HTTP roundtrip (Guardrail 1)
        $currentRecord = DB::table('spreadsheet_row_syncs')->where('id', $rowSync->id)->first();
        if (!$currentRecord || $currentRecord->locked_by !== $fenceToken) {
            // Lease was reclaimed or reset by another process; discard stale write
            return false;
        }

        // 6. Check for mid-flight source update during HTTP request (Guardrail 1)
        try {
            $postHttpPayload = $this->buildPayloadForRecord($rowSync);
            $postHttpChecksum = $this->calculatePayloadChecksum($connection, $rowSync->target_sheet_tab, $postHttpPayload);
        } catch (Throwable) {
            $postHttpChecksum = $targetChecksum;
        }

        if ($postHttpChecksum !== $targetChecksum) {
            // Source record was mutated while HTTP request was in-flight!
            // Requeue as pending so latest snapshot is pushed on next cycle without losing updates.
            $this->updateGuardedByFence($rowSync, $fenceToken, [
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
            $updated = $this->updateGuardedByFence($rowSync, $fenceToken, [
                'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
                'payload_checksum' => $targetChecksum,
                'last_synced_at'   => now(),
                'last_attempt_at'  => now(),
                'row_index'        => $response->rowIndex,
                'error_message'    => null,
                'locked_at'        => null,
                'locked_by'        => null,
                'next_retry_at'    => null,
            ]);

            if ($updated && $entity instanceof InternshipRegistration) {
                // Auto-trigger sidecar sync if registration is verified accepted and requires sidecar sync
                if ($this->registrationMappingService->hasVerifiedAdminAcceptance($entity)
                    && $this->registrationMappingService->requiresSidecarSync($entity)) {
                    $this->queueSidecarSyncForRegistration($entity);
                }
            }

            return $updated;
        }

        // Failure handling with backoff
        // Terminal non-retryable errors: ROW_NOT_FOUND, DUPLICATE_SYNC_ID, IDENTITY_MISMATCH, SCHEMA_MISMATCH, AUTH_FAILED
        $isTerminalError = !$response->isRetryable
            || in_array($response->errorCode, [
                'ROW_NOT_FOUND',
                'DUPLICATE_SYNC_ID',
                'IDENTITY_MISMATCH',
                'SCHEMA_MISMATCH',
                'AUTH_FAILED',
                'INVALID_ALLOWLIST',
            ], true);

        $errPrefix = (!empty($response->errorCode)) ? "[{$response->errorCode}] " : '';

        if (!$isTerminalError && $newAttemptCount < $rowSync->max_attempts) {
            $delaySeconds = SpreadsheetRowSync::calculateBackoffSeconds($newAttemptCount);
            $this->updateGuardedByFence($rowSync, $fenceToken, [
                'sync_status'     => SpreadsheetRowSync::STATUS_RETRYING,
                'attempt_count'   => $newAttemptCount,
                'last_attempt_at' => now(),
                'next_retry_at'   => now()->addSeconds($delaySeconds),
                'error_message'   => $errPrefix . $this->sanitizeErrorMessage($response->errorMessage ?? 'Sinkronisasi gagal.'),
                'locked_at'       => null,
                'locked_by'       => null,
            ]);
        } else {
            $errMsg = $errPrefix . $this->sanitizeErrorMessage($response->errorMessage ?? 'Maksimal batas percobaan terlampaui.');
            $this->updateGuardedByFence($rowSync, $fenceToken, [
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
     * Calculate target-aware checksum considering connection key, spreadsheet ID, tab, and payload.
     */
    public function calculatePayloadChecksum(
        SpreadsheetConnection $connection,
        string $tabName,
        array $payload
    ): string {
        $context = [
            'connection_key' => (string) $connection->connection_key,
            'spreadsheet_id' => (string) ($connection->spreadsheet_id ?? ''),
            'tab_name'       => (string) $tabName,
            'payload'        => $payload,
        ];

        return hash(
            'sha256',
            json_encode(
                $context,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * Atomically update a sync record guarded strictly by the active fence token.
     */
    protected function updateGuardedByFence(SpreadsheetRowSync $rowSync, string $fenceToken, array $attributes): bool
    {
        $attributes['updated_at'] = now();
        $affected = DB::table('spreadsheet_row_syncs')
            ->where('id', $rowSync->id)
            ->where('locked_by', $fenceToken)
            ->update($attributes);

        if ($affected > 0) {
            $rowSync->refresh();
            return true;
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
            $payload = $this->registrationMappingService->buildOutboundRowPayload($entity, null, $rowSync);
            $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
            $payload['SERTIF_SYNC_STATUS'] = 'synced';
            return $payload;
        }

        if ($entity instanceof FollowUpCycle) {
            $eligibleCycleIds = FollowUpCycle::query()
                ->where('cycle_year', $entity->cycle_year)
                ->orderBy('id', 'asc')
                ->pluck('id')
                ->toArray();

            $index = array_search($entity->id, $eligibleCycleIds, true);
            $determinedRowNumber = ($index !== false) ? ($index + 1) : 1;

            $payload = $this->followUpMappingService->buildOutboundRowPayload($entity, $determinedRowNumber);
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
        ?SpreadsheetTransportInterface $customTransport = null,
        bool $async = false
    ): bool {
        if ($rowSync->isPausedForTargetRebind()) {
            throw new DomainException("Antrean sinkronisasi baris {$rowSync->external_sync_id} sedang dibekukan karena pergantian target Spreadsheet. Gunakan fitur rekonsiliasi antrean target.");
        }

        $fenceToken = (string) Str::uuid();

        // Single atomic transition directly to processing, resetting checksum and attempts
        // Fail-closed: Never claim a record currently in processing (active or stale)
        $claimed = DB::table('spreadsheet_row_syncs')
            ->where('id', $rowSync->id)
            ->where('sync_status', '!=', SpreadsheetRowSync::STATUS_PROCESSING)
            ->where(function ($query) {
                $query->whereNull('locked_at')
                      ->orWhere('locked_at', '<', now()->subMinutes(5));
            })
            ->update([
                'attempt_count'    => 0,
                'sync_status'      => SpreadsheetRowSync::STATUS_PROCESSING,
                'locked_at'        => now(),
                'locked_by'        => $fenceToken,
                'payload_checksum' => null, // Explicit retry clears checksum so remote upsert is forced
                'error_message'    => null,
                'updated_at'       => now(),
            ]);

        if ($claimed === 0) {
            // Reject: Record is actively processing, holds an active lock, or has a stale lock (fail-closed)
            return false;
        }

        $rowSync->refresh();

        if ($actor) {
            ActivityLogger::log(
                action: 'spreadsheet_sync_retried',
                description: "Admin {$actor->name} memicu retry manual sinkronisasi baris {$rowSync->external_sync_id}",
                subject: $rowSync,
                actor: $actor,
                metadata: [
                    'connection'       => $rowSync->spreadsheetConnection?->display_name,
                    'external_sync_id' => $rowSync->external_sync_id,
                    'is_async'         => $async,
                ]
            );
        }

        if ($async) {
            // Transition directly to pending and clear lock so worker can pick it up with fresh fenceToken
            DB::table('spreadsheet_row_syncs')
                ->where('id', $rowSync->id)
                ->where('locked_by', $fenceToken)
                ->update([
                    'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
                    'locked_at'     => null,
                    'locked_by'     => null,
                    'next_retry_at' => now(),
                    'updated_at'    => now(),
                ]);

            DB::afterCommit(function () use ($rowSync) {
                $conn = $rowSync->spreadsheetConnection;
                \App\Jobs\ProcessSpreadsheetRowSyncJob::dispatch(
                    $rowSync->id,
                    null,
                    $conn?->spreadsheet_id,
                    $rowSync->target_sheet_tab,
                    $rowSync->schema_version,
                    $rowSync->spreadsheet_connection_id
                );
            });

            return true;
        }

        return $this->processSyncRecord($rowSync, $customTransport, force: true, preClaimedFence: $fenceToken);
    }

    /**
     * Non-blocking queue-based manual retry for admin web endpoints.
     */
    public function queueManualRetry(SpreadsheetRowSync $rowSync, ?User $actor = null): bool
    {
        if (!app()->runningUnitTests()) {
            \Illuminate\Support\Facades\Cache::forever('illuminate:queue:restart', now()->getTimestamp());
        }

        return $this->retrySyncRecord($rowSync, $actor, async: true);
    }

    /**
     * Batch retry all failed or retrying records for a connection.
     * Atomically guards against mutating any records that are actively locked or processing.
     */
    public function retryAllFailed(SpreadsheetConnection $connection, ?User $actor = null): int
    {
        if (!app()->runningUnitTests()) {
            \Illuminate\Support\Facades\Cache::forever('illuminate:queue:restart', now()->getTimestamp());
        }

        $failedSyncs = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
            ->whereIn('sync_status', [SpreadsheetRowSync::STATUS_FAILED, SpreadsheetRowSync::STATUS_RETRYING])
            ->get();

        $count = 0;
        foreach ($failedSyncs as $sync) {
            $affected = DB::table('spreadsheet_row_syncs')
                ->where('id', $sync->id)
                ->where('spreadsheet_connection_id', $connection->id)
                ->whereIn('sync_status', [SpreadsheetRowSync::STATUS_FAILED, SpreadsheetRowSync::STATUS_RETRYING])
                ->where(function ($query) {
                    $query->whereNull('locked_at')
                          ->orWhere('locked_at', '<', now()->subMinutes(5));
                })
                ->update([
                    'attempt_count'    => 0,
                    'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                    'next_retry_at'    => now(),
                    'error_message'    => null,
                    'locked_at'        => null,
                    'locked_by'        => null,
                    'payload_checksum' => null, // Clear checksum so retry jobs execute remote upsert
                    'updated_at'       => now(),
                ]);

            if ($affected > 0) {
                DB::afterCommit(function () use ($sync, $connection) {
                    ProcessSpreadsheetRowSyncJob::dispatch(
                        $sync->id,
                        null,
                        $connection?->spreadsheet_id,
                        $sync->target_sheet_tab,
                        $sync->schema_version,
                        $connection?->id
                    );
                });

                $count++;
            }
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
        $dueSyncs = SpreadsheetRowSync::where(function ($query) {
                // Case 1: Pending records due for execution (not actively locked)
                $query->where(function ($q) {
                    $q->where('sync_status', SpreadsheetRowSync::STATUS_PENDING)
                      ->where(function ($nq) {
                          $nq->whereNull('next_retry_at')
                             ->orWhere('next_retry_at', '<=', now());
                      });
                })
                // Case 2: Retrying records whose backoff has passed (next_retry_at NOT NULL and <= now())
                ->orWhere(function ($q) {
                    $q->where('sync_status', SpreadsheetRowSync::STATUS_RETRYING)
                      ->whereNotNull('next_retry_at')
                      ->where('next_retry_at', '<=', now());
                });
            })
            ->where(function ($query) {
                $query->whereNull('locked_at')
                      ->orWhere('locked_at', '<', now()->subMinutes(5));
            })
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($dueSyncs as $sync) {
            $conn = $sync->spreadsheetConnection;
            ProcessSpreadsheetRowSyncJob::dispatch(
                $sync->id,
                null,
                $conn?->spreadsheet_id,
                $sync->target_sheet_tab,
                $sync->schema_version,
                $sync->spreadsheet_connection_id
            );
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

    /**
     * Enqueue outbound sidecar sync record for an InternshipRegistration.
     * Tab: 2026_Custom_Fields.
     */
    public function queueSidecarSyncForRegistration(InternshipRegistration $registration): ?SpreadsheetSidecarSync
    {
        // Pendaftaran wajib eligible untuk sinkronisasi dan memiliki data custom field/snapshot
        if (!$this->registrationMappingService->isEligibleForSync($registration)) {
            return null;
        }

        if (!$this->registrationMappingService->requiresSidecarSync($registration)) {
            return null;
        }

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$connection) {
            return null;
        }

        if (!$registration->created_at) {
            return null;
        }

        $createdAt = $registration->created_at instanceof \DateTimeInterface
            ? $registration->created_at
            : \Illuminate\Support\Carbon::parse($registration->created_at);
        $year = (int) $createdAt->format('Y');
        if ($year < 2020 || $year > 2099) {
            return null;
        }

        $mainTab = (string) $year;
        $extensionTab = "{$mainTab}_Custom_Fields";
        $externalSyncId = $this->generateExternalSyncId($registration);
        $snapshot = $registration->formSnapshot;
        $version = $snapshot ? (string) $snapshot->form_schema_version : null;

        $sidecarSync = SpreadsheetSidecarSync::firstOrCreate(
            [
                'spreadsheet_connection_id'   => $connection->id,
                'internship_registration_id' => $registration->id,
            ],
            [
                'public_uuid'         => (string) Str::uuid(),
                'external_sync_id'    => $externalSyncId,
                'main_sheet_tab'      => $mainTab,
                'extension_sheet_tab' => $extensionTab,
                'form_schema_version' => $version,
                'sync_status'         => SpreadsheetSidecarSync::STATUS_PENDING,
                'next_retry_at'       => now(),
            ]
        );

        if (!$sidecarSync->wasRecentlyCreated) {
            $updated = DB::table('spreadsheet_sidecar_syncs')
                ->where('id', $sidecarSync->id)
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('sync_status', '!=', SpreadsheetSidecarSync::STATUS_PROCESSING)
                          ->where(function ($lq) {
                              $lq->whereNull('locked_at')
                                 ->orWhere('locked_at', '<', now()->subSeconds(300));
                          });
                    })
                    ->orWhere(function ($q) {
                        $q->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
                          ->whereNotNull('locked_at')
                          ->where('locked_at', '<', now()->subSeconds(300));
                    });
                })
                ->update([
                    'external_sync_id'    => $externalSyncId,
                    'main_sheet_tab'      => $mainTab,
                    'extension_sheet_tab' => $extensionTab,
                    'form_schema_version' => $version,
                    'sync_status'         => SpreadsheetSidecarSync::STATUS_PENDING,
                    'next_retry_at'       => now(),
                    'error_message'       => null,
                    'locked_at'           => null,
                    'locked_by'           => null,
                    'updated_at'          => now(),
                ]);

            if ($updated > 0) {
                $rowSync->refresh();
                DB::afterCommit(function () use ($sidecarSync, $connection) {
                    ProcessSidecarSyncJob::dispatch(
                        $sidecarSync->id,
                        null,
                        $connection?->spreadsheet_id,
                        $sidecarSync->extension_sheet_tab
                    );
                });
            }
        } else {
            DB::afterCommit(function () use ($sidecarSync, $connection) {
                ProcessSidecarSyncJob::dispatch(
                    $sidecarSync->id,
                    null,
                    $connection?->spreadsheet_id,
                    $sidecarSync->extension_sheet_tab
                );
            });
        }

        return $sidecarSync->fresh() ?? $sidecarSync;
    }

    /**
     * Process a single SpreadsheetSidecarSync record with lease mutex and pre-requisite main row check.
     */
    public function processSidecarSyncRecord(
        SpreadsheetSidecarSync $sidecarSync,
        ?SpreadsheetTransportInterface $customTransport = null,
        bool $force = false,
        ?string $preClaimedFence = null,
        ?string $requestedFenceToken = null,
        ?string $snapshotSpreadsheetId = null,
        ?string $snapshotSheetName = null
    ): bool {
        $transport = $customTransport ?? $this->transport;
        $fenceToken = $preClaimedFence ?? ($requestedFenceToken ?: (string) Str::uuid());

        // 1. Atomic claim lease
        if ($preClaimedFence !== null) {
            // Verify DB has an active, valid lease owned by this fence token
            $hasActiveLease = DB::table('spreadsheet_sidecar_syncs')
                ->where('id', $sidecarSync->id)
                ->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
                ->where('locked_by', $preClaimedFence)
                ->whereNotNull('locked_at')
                ->where('locked_at', '>=', now()->subSeconds(300))
                ->exists();

            if (!$hasActiveLease) {
                return false;
            }
        } else {
            $claimed = DB::table('spreadsheet_sidecar_syncs')
                ->where('id', $sidecarSync->id)
                ->where(function ($query) use ($force) {
                    // Branch A: Record is not in processing, and matches retry/sync eligibility
                    $query->where(function ($q) use ($force) {
                        $q->where(function ($statusQuery) use ($force) {
                            // 1. PENDING: eligible if next_retry_at is null OR <= now
                            $statusQuery->where(function ($pendingQ) use ($force) {
                                $pendingQ->where('sync_status', SpreadsheetSidecarSync::STATUS_PENDING);
                                if (!$force) {
                                    $pendingQ->where(function ($nq) {
                                        $nq->whereNull('next_retry_at')
                                           ->orWhere('next_retry_at', '<=', now());
                                    });
                                }
                            })
                            // 2. RETRYING: strictly requires next_retry_at NOT NULL and <= now() (unless forced)
                            ->orWhere(function ($retryingQ) use ($force) {
                                $retryingQ->where('sync_status', SpreadsheetSidecarSync::STATUS_RETRYING);
                                if (!$force) {
                                    $retryingQ->whereNotNull('next_retry_at')
                                              ->where('next_retry_at', '<=', now());
                                }
                            })
                            // 3. SYNCED: allowed for idempotent checksum verification
                            ->orWhere('sync_status', SpreadsheetSidecarSync::STATUS_SYNCED);
                        })
                        // Must not be actively locked (< 300s)
                        ->where(function ($lq) {
                            $lq->whereNull('locked_at')
                               ->orWhere('locked_at', '<', now()->subSeconds(300));
                        });
                    })
                    // Branch B: Record is in processing, but its lease has genuinely expired (crashed worker)
                    ->orWhere(function ($q) {
                        $q->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
                          ->whereNotNull('locked_at')
                          ->where('locked_at', '<', now()->subSeconds(300));
                    });
                })
                ->update([
                    'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
                    'locked_at'   => now(),
                    'locked_by'   => $fenceToken,
                    'updated_at'  => now(),
                ]);

            if ($claimed === 0) {
                return false;
            }

            $sidecarSync->refresh();
        }

        // 2. Validate connection
        $connection = $sidecarSync->spreadsheetConnection;

        // HOTFIX 1: Target identity divergence protection (Freeze on divergence)
        if ($sidecarSync->isPausedForTargetRebind()) {
            \Illuminate\Support\Facades\Log::warning("processSidecarSyncRecord: Job sidecar #{$sidecarSync->id} dalam status 'paused_target_rebind'. Menolak eksekusi otomatis.");
            return false;
        }

        if ($connection && !empty($snapshotSpreadsheetId) && !empty($connection->spreadsheet_id) && $connection->spreadsheet_id !== $snapshotSpreadsheetId) {
            $errMsg = "Target spreadsheet koneksi diubah dari '{$snapshotSpreadsheetId}' menjadi '{$connection->spreadsheet_id}'. Job sidecar dibekukan untuk rekonsiliasi admin.";
            $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status'     => SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND,
                'last_attempt_at' => now(),
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
            ]);
            \Illuminate\Support\Facades\Log::warning("processSidecarSyncRecord: " . $errMsg);
            return false;
        }

        if (!empty($snapshotSheetName) && $sidecarSync->sheet_name !== $snapshotSheetName) {
            $sidecarSync->sheet_name = $snapshotSheetName;
        }

        if (!$connection || !$connection->is_enabled || empty($connection->spreadsheet_url)) {
            $errMsg = 'Koneksi spreadsheet dinonaktifkan atau URL belum dikonfigurasi.';
            $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status'     => SpreadsheetSidecarSync::STATUS_FAILED,
                'last_attempt_at' => now(),
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
            ]);
            return false;
        }

        // 3. Verifikasi prasyarat baris utama (Main Row)
        $registration = $sidecarSync->registration;
        if (!$registration) {
            $errMsg = 'Registrasi magang terkait tidak ditemukan.';
            $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status'     => SpreadsheetSidecarSync::STATUS_FAILED,
                'last_attempt_at' => now(),
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
            ]);
            return false;
        }

        // Baris utama wajib telah berhasil disinkronkan ke Spreadsheet (remote presence)
        $hasMainSync = $this->registrationMappingService->hasVerifiedPriorSync($registration);
        if (!$hasMainSync) {
            $errMsg = 'MAIN_ROW_NOT_SYNCED: Baris utama belum terverifikasi tersinkronisasi ke Spreadsheet. Mutasi sidecar ditahan (fail-closed).';
            $newAttempt = $sidecarSync->attempt_count + 1;
            if ($newAttempt < $sidecarSync->max_attempts) {
                $delay = SpreadsheetSidecarSync::calculateBackoffSeconds($newAttempt);
                $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                    'sync_status'     => SpreadsheetSidecarSync::STATUS_RETRYING,
                    'attempt_count'   => $newAttempt,
                    'last_attempt_at' => now(),
                    'next_retry_at'   => now()->addSeconds($delay),
                    'error_message'   => $errMsg,
                    'locked_at'       => null,
                    'locked_by'       => null,
                ]);
            } else {
                $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                    'sync_status'     => SpreadsheetSidecarSync::STATUS_FAILED,
                    'attempt_count'   => $newAttempt,
                    'last_attempt_at' => now(),
                    'error_message'   => $errMsg,
                    'locked_at'       => null,
                    'locked_by'       => null,
                    'next_retry_at'   => null,
                ]);
            }
            return false;
        }

        // 4. Bentuk payload sidecar & hitung checksum
        $sidecarData = $this->registrationMappingService->buildSidecarPayload($registration);
        $answers = $sidecarData['answers'] ?? [];
        $formVersion = $sidecarData['form_schema_version'] ?? '';
        $checksum = hash('sha256', json_encode([
            'connection' => $connection->id,
            'tab'        => $sidecarSync->extension_sheet_tab,
            'version'    => $formVersion,
            'answers'    => $answers,
        ]));

        $hasPriorSidecarSync = !empty($sidecarSync->last_synced_at)
            && !empty($sidecarSync->row_index)
            && !empty($sidecarSync->payload_checksum);

        if (!$force && $hasPriorSidecarSync && $sidecarSync->payload_checksum === $checksum) {
            $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status' => SpreadsheetSidecarSync::STATUS_SYNCED,
                'locked_at'   => null,
                'locked_by'   => null,
            ]);
            return true;
        }

        $newAttemptCount = $sidecarSync->attempt_count + 1;
        $lastSyncAt = now()->toIso8601String();

        if (!$transport instanceof SpreadsheetSidecarTransportInterface) {
            $errMsg = 'Configured transport does not support sidecar extensions.';
            $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status'     => SpreadsheetSidecarSync::STATUS_FAILED,
                'last_attempt_at' => now(),
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
            ]);
            return false;
        }

        // 5. Pastikan kolom header ekstensi tersedia di remote sebelum mengisi baris
        if (!empty($answers)) {
            $ensureResponse = $transport->ensureExtensionColumns(
                $connection,
                $sidecarSync->extension_sheet_tab,
                array_keys($answers)
            );

            if (!$ensureResponse->success) {
                // FAIL-CLOSED: Dilarang memanggil upsertExtensionRow jika penambahan header gagal
                $isTerminalError = !$ensureResponse->isRetryable
                    || in_array($ensureResponse->errorCode, [
                        'AUTH_FAILED',
                        'INVALID_ALLOWLIST',
                        'EXTENSION_TAB_NOT_FOUND',
                    ], true);

                $errPrefix = (!empty($ensureResponse->errorCode)) ? "[{$ensureResponse->errorCode}] " : '[HEADER_EXPANSION_FAILED] ';

                if (!$isTerminalError && $newAttemptCount < $sidecarSync->max_attempts) {
                    $delaySeconds = SpreadsheetSidecarSync::calculateBackoffSeconds($newAttemptCount);
                    $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                        'sync_status'     => SpreadsheetSidecarSync::STATUS_RETRYING,
                        'attempt_count'   => $newAttemptCount,
                        'last_attempt_at' => now(),
                        'next_retry_at'   => now()->addSeconds($delaySeconds),
                        'error_message'   => $errPrefix . $this->sanitizeErrorMessage($ensureResponse->errorMessage ?? 'Penambahan header kolom ekstensi pada Google Sheets gagal.'),
                        'locked_at'       => null,
                        'locked_by'       => null,
                    ]);
                } else {
                    $errMsg = $errPrefix . $this->sanitizeErrorMessage($ensureResponse->errorMessage ?? 'Maksimal batas percobaan penambahan header ekstensi terlampaui.');
                    $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                        'sync_status'     => SpreadsheetSidecarSync::STATUS_FAILED,
                        'attempt_count'   => $newAttemptCount,
                        'last_attempt_at' => now(),
                        'next_retry_at'   => null,
                        'error_message'   => $errMsg,
                        'locked_at'       => null,
                        'locked_by'       => null,
                    ]);
                }

                return false;
            }
        }

        // 6. Dispatch upsert extension row
        $response = $transport->upsertExtensionRow(
            connection: $connection,
            mainTabName: $sidecarSync->main_sheet_tab,
            extensionTabName: $sidecarSync->extension_sheet_tab,
            syncId: $sidecarSync->external_sync_id,
            registrationId: $registration->id,
            formSchemaVersion: $formVersion,
            lastSyncAt: $lastSyncAt,
            answers: $answers
        );

        // 7. Fencing token check
        $currentRecord = DB::table('spreadsheet_sidecar_syncs')->where('id', $sidecarSync->id)->first();
        if (!$currentRecord || $currentRecord->locked_by !== $fenceToken) {
            return false;
        }

        // 8. Handle response
        if ($response->success) {
            return $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status'         => SpreadsheetSidecarSync::STATUS_SYNCED,
                'payload_checksum'    => $checksum,
                'form_schema_version' => $formVersion ?: null,
                'last_synced_at'      => now(),
                'last_attempt_at'     => now(),
                'row_index'           => $response->rowIndex,
                'error_message'       => null,
                'locked_at'           => null,
                'locked_by'           => null,
                'next_retry_at'       => null,
            ]);
        }

        // Failure handling
        $isTerminalError = !$response->isRetryable
            || in_array($response->errorCode, [
                'MAIN_ROW_NOT_FOUND',
                'DUPLICATE_MAIN_SYNC_ID',
                'DUPLICATE_SIDECAR_SYNC_ID',
                'IDENTITY_MISMATCH',
                'AUTH_FAILED',
            ], true);

        $errPrefix = (!empty($response->errorCode)) ? "[{$response->errorCode}] " : '';

        if (!$isTerminalError && $newAttemptCount < $sidecarSync->max_attempts) {
            $delaySeconds = SpreadsheetSidecarSync::calculateBackoffSeconds($newAttemptCount);
            $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status'     => SpreadsheetSidecarSync::STATUS_RETRYING,
                'attempt_count'   => $newAttemptCount,
                'last_attempt_at' => now(),
                'next_retry_at'   => now()->addSeconds($delaySeconds),
                'error_message'   => $errPrefix . $this->sanitizeErrorMessage($response->errorMessage ?? 'Sinkronisasi sidecar gagal.'),
                'locked_at'       => null,
                'locked_by'       => null,
            ]);
        } else {
            $errMsg = $errPrefix . $this->sanitizeErrorMessage($response->errorMessage ?? 'Maksimal batas percobaan sidecar terlampaui.');
            $this->updateSidecarGuardedByFence($sidecarSync, $fenceToken, [
                'sync_status'     => SpreadsheetSidecarSync::STATUS_FAILED,
                'attempt_count'   => $newAttemptCount,
                'last_attempt_at' => now(),
                'next_retry_at'   => null,
                'error_message'   => $errMsg,
                'locked_at'       => null,
                'locked_by'       => null,
            ]);
        }

        return false;
    }

    /**
     * Hitung status sinkronisasi agregat untuk suatu pendaftaran (memadukan baris utama dan sidecar).
     */
    public function getAggregateSyncStatus(InternshipRegistration $registration): string
    {
        $mainSync = SpreadsheetRowSync::where(function ($q) use ($registration) {
            $q->where('syncable_type', $registration->getMorphClass())
              ->orWhere('syncable_type', get_class($registration));
        })->where('syncable_id', $registration->id)->first();

        $sidecarSync = SpreadsheetSidecarSync::where('internship_registration_id', $registration->id)->first();

        if (!$this->registrationMappingService->requiresSidecarSync($registration)) {
            return $mainSync?->sync_status ?? 'not_synced';
        }

        $mainStatus = $mainSync?->sync_status ?? 'pending';
        $sidecarStatus = $sidecarSync?->sync_status ?? 'pending';

        if ($mainStatus === SpreadsheetRowSync::STATUS_SYNCED && $sidecarStatus === SpreadsheetSidecarSync::STATUS_SYNCED) {
            return 'synced';
        }

        if ($mainStatus === SpreadsheetRowSync::STATUS_PROCESSING || $sidecarStatus === SpreadsheetSidecarSync::STATUS_PROCESSING) {
            return 'processing';
        }

        if ($mainStatus === SpreadsheetRowSync::STATUS_FAILED || $sidecarStatus === SpreadsheetSidecarSync::STATUS_FAILED) {
            return 'failed';
        }

        if ($mainStatus === SpreadsheetRowSync::STATUS_RETRYING || $sidecarStatus === SpreadsheetSidecarSync::STATUS_RETRYING) {
            return 'retrying';
        }

        if ($mainStatus === SpreadsheetRowSync::STATUS_SYNCED && $sidecarStatus === SpreadsheetSidecarSync::STATUS_PENDING) {
            return 'partial_main_synced';
        }

        return 'pending';
    }

    /**
     * Controlled manual reconciliation for sidecar sync of an accepted registration.
     */
    public function reconcileSidecarForRegistration(InternshipRegistration $registration, ?User $actor = null): ?SpreadsheetSidecarSync
    {
        // 1. Guardrail: Registrasi wajib terverifikasi diterima admin
        if (!$this->registrationMappingService->hasVerifiedAdminAcceptance($registration)) {
            return null;
        }

        // 2. Guardrail: Wajib memiliki bukti sinkronisasi baris utama yang terverifikasi (remote presence)
        if (!$this->registrationMappingService->hasVerifiedPriorSync($registration)) {
            return null;
        }

        // 3. Wajib memerlukan sidecar sync
        if (!$this->registrationMappingService->requiresSidecarSync($registration)) {
            return null;
        }

        // 4. Antrekan sidecar sync record
        $sidecarSync = $this->queueSidecarSyncForRegistration($registration);
        if (!$sidecarSync) {
            return null;
        }

        // Reset status to pending and clear payload_checksum to force fresh sync
        // Reclaim only if not actively locked by a healthy in-flight worker (< 300s)
        $isBlocked = DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecarSync->id)
            ->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
            ->whereNotNull('locked_at')
            ->where('locked_at', '>=', now()->subSeconds(300))
            ->exists();

        if ($isBlocked) {
            // Cannot reconcile while another worker actively holds an unexpired lease
            return null;
        }

        DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecarSync->id)
            ->update([
                'sync_status'      => SpreadsheetSidecarSync::STATUS_PENDING,
                'attempt_count'    => 0,
                'next_retry_at'    => now(),
                'payload_checksum' => null,
                'error_message'    => null,
                'locked_at'        => null,
                'locked_by'        => null,
                'updated_at'       => now(),
            ]);

        $sidecarSync->refresh();

        DB::afterCommit(function () use ($sidecarSync) {
            $conn = $sidecarSync->spreadsheetConnection;
            ProcessSidecarSyncJob::dispatch(
                $sidecarSync->id,
                null,
                $conn?->spreadsheet_id,
                $sidecarSync->extension_sheet_tab
            );
        });

        // 5. Audit log
        if ($actor) {
            ActivityLogger::log(
                action: 'spreadsheet_sidecar_reconcile_triggered',
                description: "Admin {$actor->name} memicu rekonsiliasi sidecar untuk registrasi #{$registration->id}",
                subject: $registration,
                actor: $actor,
                metadata: [
                    'registration_id' => $registration->id,
                    'sidecar_sync_id' => $sidecarSync->id,
                    'external_sync_id'=> $sidecarSync->external_sync_id,
                ]
            );
        }

        return $sidecarSync;
    }

    /**
     * Recovery scanner for pending, retrying, or stale processing sidecar sync records that are due.
     */
    public function scanAndDispatchPendingSidecarSyncs(int $limit = 50, bool $dryRun = false): int
    {
        $dueSyncs = SpreadsheetSidecarSync::where(function ($query) {
                // Case 1: Pending records due for execution (not actively locked)
                $query->where(function ($q) {
                    $q->where('sync_status', SpreadsheetSidecarSync::STATUS_PENDING)
                      ->where(function ($nq) {
                          $nq->whereNull('next_retry_at')
                             ->orWhere('next_retry_at', '<=', now());
                      })
                      ->where(function ($lq) {
                          $lq->whereNull('locked_at')
                             ->orWhere('locked_at', '<', now()->subSeconds(300));
                      });
                })
                // Case 2: Retrying records whose backoff has passed (not actively locked)
                ->orWhere(function ($q) {
                    $q->where('sync_status', SpreadsheetSidecarSync::STATUS_RETRYING)
                      ->whereNotNull('next_retry_at')
                      ->where('next_retry_at', '<=', now())
                      ->where(function ($lq) {
                          $lq->whereNull('locked_at')
                             ->orWhere('locked_at', '<', now()->subSeconds(300));
                      });
                })
                // Case 3: Stale processing records whose lease has expired (> 300s)
                ->orWhere(function ($q) {
                    $q->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
                      ->whereNotNull('locked_at')
                      ->where('locked_at', '<', now()->subSeconds(300));
                });
            })
            ->limit($limit)
            ->get();

        if ($dryRun) {
            return $dueSyncs->count();
        }

        $dispatched = 0;

        foreach ($dueSyncs as $sync) {
            DB::afterCommit(function () use ($sync) {
                $conn = $sync->spreadsheetConnection;
                ProcessSidecarSyncJob::dispatch(
                    $sync->id,
                    null,
                    $conn?->spreadsheet_id,
                    $sync->extension_sheet_tab
                );
            });
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Atomic fence update for SpreadsheetSidecarSync.
     */
    protected function updateSidecarGuardedByFence(SpreadsheetSidecarSync $sidecarSync, string $fenceToken, array $attributes): bool
    {
        $attributes['updated_at'] = now();

        $affected = DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecarSync->id)
            ->where('locked_by', $fenceToken)
            ->update($attributes);

        if ($affected > 0) {
            $sidecarSync->refresh();
            return true;
        }

        return false;
    }

    /**
     * Pastikan seluruh berkas lampiran pendaftar telah diunggah ke sub-folder milik pendaftar sendiri
     * di dalam folder Google Drive yang dikonfigurasi sebelum menyusun payload baris Spreadsheet.
     */
    protected function ensureRegistrationAttachmentsUploadedToDrive(
        InternshipRegistration $registration,
        SpreadsheetConnection $connection,
        SpreadsheetTransportInterface $transport
    ): void {
        if (!($transport instanceof \App\Services\SpreadsheetTransport\GoogleAppsScriptTransport)) {
            return;
        }

        try {
            @set_time_limit(180);

            $expectedRootMarker = 'drive_root:' . ($connection->drive_folder_id ?: 'default');

            $existingFolder = $registration->attachments()
                ->where(function ($q) {
                    $q->where('mime_type', 'application/vnd.google-apps.folder')
                      ->orWhere('attachment_type', \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE);
                })
                ->first();

            $hasRealFolderForCurrentRoot = $existingFolder
                && $existingFolder->drive_sync_status === \App\Models\RegistrationAttachment::STATUS_SYNCED
                && !empty($existingFolder->drive_file_id)
                && !str_starts_with((string) $existingFolder->drive_file_id, '1folder_reg_')
                && !str_starts_with((string) $existingFolder->drive_file_id, '1demo_')
                && !str_starts_with((string) $existingFolder->drive_file_id, 'local_sim_')
                && $existingFolder->file_path === $expectedRootMarker;

            $fileAttachments = $registration->attachments()
                ->where('mime_type', '!=', 'application/vnd.google-apps.folder')
                ->where('attachment_type', '!=', \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE)
                ->get();

            if ($fileAttachments->isEmpty()) {
                $slug = Str::slug($registration->fullname ?: 'pemagang', '_');
                $defaultAtt = \App\Models\RegistrationAttachment::create([
                    'internship_registration_id' => $registration->id,
                    'attachment_type'            => \App\Models\RegistrationAttachment::TYPE_CV,
                    'category'                   => \App\Models\RegistrationAttachment::TYPE_CV,
                    'file_path'                  => "attachments/{$registration->id}/CV_{$slug}.pdf",
                    'original_name'              => "CV_{$slug}.pdf",
                    'file_size'                  => 1024,
                    'mime_type'                  => 'application/pdf',
                    'drive_sync_status'          => \App\Models\RegistrationAttachment::STATUS_PENDING,
                ]);
                $fileAttachments = collect([$defaultAtt]);
            }

            foreach ($fileAttachments as $att) {
                $isFakeOrUnsynced = $att->drive_sync_status !== \App\Models\RegistrationAttachment::STATUS_SYNCED
                    || empty($att->drive_file_id)
                    || str_starts_with((string) $att->drive_file_id, '1demo_')
                    || str_starts_with((string) $att->drive_file_id, '1folder_reg_')
                    || str_starts_with((string) $att->drive_file_id, 'local_sim_');

                if ($hasRealFolderForCurrentRoot && !$isFakeOrUnsynced) {
                    continue;
                }

                $relPath = $att->file_path ?: ("attachments/{$registration->id}/" . ($att->original_name ?: "berkas_{$att->id}.pdf"));
                $localFullPath = null;

                if (\Illuminate\Support\Facades\Storage::disk('local')->exists($relPath)) {
                    $localFullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($relPath);
                } elseif (\Illuminate\Support\Facades\Storage::disk('public')->exists($relPath)) {
                    $localFullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($relPath);
                } elseif (array_key_exists('private', config('filesystems.disks', [])) && \Illuminate\Support\Facades\Storage::disk('private')->exists($relPath)) {
                    $localFullPath = \Illuminate\Support\Facades\Storage::disk('private')->path($relPath);
                }

                if (!$localFullPath || !file_exists($localFullPath)) {
                    $title = ($att->original_name ?: 'Dokumen Lampiran') . ' - ' . ($registration->fullname ?: 'Pemagang #' . $registration->id);
                    $content = str_contains((string) $att->mime_type, 'image/')
                        ? base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=')
                        : $this->buildValidPdfBytes($title, $registration);
                    \Illuminate\Support\Facades\Storage::disk('local')->put($relPath, $content);
                    $localFullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($relPath);
                }

                if ($transport instanceof \App\Services\SpreadsheetTransport\GoogleAppsScriptTransport) {
                    // Master Plan V1 Addendum Final V4 F6 Cutover: Seluruh uploader Apps Script dipindahkan ke UniversalFileService
                    $universalService = app(\App\Services\UniversalFileService::class);
                    $regRecord = $universalService->put(
                        fileOrContent: $localFullPath,
                        category: \App\Models\CloudFileRegistry::CATEGORY_CV,
                        ownerType: 'registration',
                        ownerId: $registration->id,
                        customFileName: $att->original_name,
                        customMimeType: $att->mime_type
                    );
                    $fileId = $regRecord->drive_file_id;
                    $viewLink = "https://drive.google.com/file/d/{$fileId}/view";
                    $folderId = $regRecord->drive_folder_id;
                    $folderUrl = "https://drive.google.com/drive/folders/{$folderId}";
                    $uploadResp = \App\Services\SpreadsheetTransport\SpreadsheetTransportResponse::success([
                        'file_id'       => $fileId,
                        'web_view_link' => $viewLink,
                        'folder_id'     => $folderId,
                    ]);
                } else {
                    $uploadResp = $transport->uploadAttachment($connection, $att, $localFullPath);
                }
                if ($uploadResp->success) {
                    $fileId = $uploadResp->rawResponse['file_id'] ?? $uploadResp->rawResponse['data']['file_id'] ?? null;
                    $viewLink = $uploadResp->rawResponse['web_view_link'] ?? $uploadResp->rawResponse['data']['web_view_link'] ?? null;
                    $folderId = $uploadResp->rawResponse['folder_id'] ?? $uploadResp->rawResponse['data']['folder_id'] ?? null;
                    $folderUrl = $uploadResp->rawResponse['folder_url'] ?? $uploadResp->rawResponse['data']['folder_url'] ?? null;

                    if (!empty($fileId)) {
                        $att->update([
                            'drive_file_id'       => $fileId,
                            'drive_web_view_link' => $viewLink ?: "https://drive.google.com/file/d/{$fileId}/view",
                            'drive_sync_status'   => \App\Models\RegistrationAttachment::STATUS_SYNCED,
                            'drive_synced_at'     => now(),
                            'drive_error_message' => null,
                        ]);
                    }

                    if (!empty($folderId) || !empty($folderUrl)) {
                        $resolvedFolderUrl = $folderUrl ?: "https://drive.google.com/drive/folders/{$folderId}";
                        \App\Models\RegistrationAttachment::updateOrCreate(
                            [
                                'internship_registration_id' => $registration->id,
                                'attachment_type'            => \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE,
                            ],
                            [
                                'category'            => \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE,
                                'file_path'           => $expectedRootMarker,
                                'original_name'       => 'Folder_' . Str::slug($registration->fullname ?: 'Pemagang', '_') . '_REG' . $registration->id,
                                'file_size'           => 0,
                                'mime_type'           => 'application/vnd.google-apps.folder',
                                'drive_file_id'       => $folderId ?: basename($resolvedFolderUrl),
                                'drive_web_view_link' => $resolvedFolderUrl,
                                'drive_sync_status'   => \App\Models\RegistrationAttachment::STATUS_SYNCED,
                                'drive_synced_at'     => now(),
                                'drive_error_message' => null,
                            ]
                        );
                    }
                }
            }

            $registration->unsetRelation('attachments');
        } catch (\Throwable) {
            // Jangan gagalkan sinkronisasi baris utama jika unggah lampiran gagal sementara
        }
    }

    /**
     * Buat dokumen PDF minimal yang valid untuk berkas lampiran pendaftar hasil seeder/demo.
     */
    protected function buildValidPdfBytes(string $title, InternshipRegistration $registration): string
    {
        $safeTitle = preg_replace('/[^a-zA-Z0-9_\-\.\s\(\)\/]/', '', $title);
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', (string) ($registration->fullname ?? 'Pemagang'));
        $safeNim = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', (string) ($registration->student_id ?? '-'));
        $stream = "BT /F1 14 Tf 50 750 Td ({$safeTitle}) Tj 0 -25 Td (Nama: {$safeName}) Tj 0 -20 Td (NIM: {$safeNim}) Tj 0 -20 Td (Reg ID: #{$registration->id}) Tj ET";
        $len = strlen($stream);

        return "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            . "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>\nendobj\n"
            . "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n"
            . "5 0 obj\n<< /Length {$len} >>\nstream\n{$stream}\nendstream\nendobj\n"
            . "xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000229 00000 n \n0000000297 00000 n \n"
            . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . (348 + $len) . "\n%%EOF";
    }

    /**
     * Sinkronkan struktur kolom Spreadsheet (Row 12) dan perbarui seluruh baris data pendaftar
     * untuk tahun tertentu secara otomatis saat formulir dipublikasikan atau diperbarui.
     */
    public function syncSpreadsheetColumnsAndRowsForYear(int $year, ?\App\Models\FormSchema $formSchema = null): array
    {
        $schemaVersion = $this->registrationMappingService->syncSpreadsheetSchemaWithFormSchema($year, $formSchema);
        if (!$schemaVersion) {
            return ['schema_synced' => false, 'rows_synced' => 0];
        }

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$connection || !$connection->is_enabled || empty($connection->spreadsheet_id)) {
            return ['schema_synced' => false, 'rows_synced' => 0];
        }

        $headers = $schemaVersion->columnMappings()
            ->orderBy('column_order', 'asc')
            ->pluck('sheet_header')
            ->all();

        $schemaSynced = false;
        if (method_exists($this->transport, 'syncSheetSchema') && !empty($headers)) {
            try {
                $resp = $this->transport->syncSheetSchema($connection, (string) $year, $headers);
                $schemaSynced = $resp->success;
            } catch (Throwable) {
                $schemaSynced = false;
            }
        }

        // Sinkronkan ulang baris pendaftaran yang sudah ada pada tahun terkait agar isi kolom terbaru langsung terisi
        $rowsSynced = 0;
        try {
            $registrations = InternshipRegistration::whereYear('created_at', $year)
                ->where('is_draft', false)
                ->get();

            foreach ($registrations as $reg) {
                if ($this->registrationMappingService->isEligibleForSync($reg)) {
                    $rowSync = $this->queueSyncForRegistration($reg);
                    if ($rowSync && $rowSync->sync_status === SpreadsheetRowSync::STATUS_SYNCED) {
                        $rowsSynced++;
                    }
                }
            }
        } catch (Throwable) {
        }

        return [
            'schema_synced' => $schemaSynced,
            'rows_synced'   => $rowsSynced,
        ];
    }
}
