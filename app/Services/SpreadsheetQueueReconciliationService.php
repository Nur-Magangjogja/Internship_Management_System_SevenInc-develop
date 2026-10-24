<?php

namespace App\Services;

use App\Jobs\ProcessSidecarSyncJob;
use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSidecarSync;
use App\Models\User;
use App\Services\SpreadsheetTransport\NullSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SpreadsheetQueueReconciliationService
{
    protected SpreadsheetTransportInterface $transport;

    public function __construct(?SpreadsheetTransportInterface $transport = null)
    {
        $this->transport = $transport ?? app(SpreadsheetTransportInterface::class);
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
     * Bekukan seluruh pekerjaan sinkronisasi yang masih pending, retrying, atau sedang diproses
     * saat target Spreadsheet diganti oleh Admin.
     */
    public function freezePendingJobs(SpreadsheetConnection $connection, ?string $oldSpreadsheetId = null, ?User $actor = null): array
    {
        $oldId = $oldSpreadsheetId ?: ($connection->previous_spreadsheet_id ?: 'target lama');
        $reason = "Target spreadsheet dialihkan dari '{$oldId}' ke target baru ('{$connection->spreadsheet_id}'). Antrean dibekukan untuk rekonsiliasi admin.";

        // 1. Bekukan SpreadsheetRowSync yang aktif/antre
        $activeStatuses = [
            SpreadsheetRowSync::STATUS_PENDING,
            SpreadsheetRowSync::STATUS_PROCESSING,
            SpreadsheetRowSync::STATUS_RETRYING,
        ];

        $frozenRowCount = DB::table('spreadsheet_row_syncs')
            ->where('spreadsheet_connection_id', $connection->id)
            ->whereIn('sync_status', $activeStatuses)
            ->update([
                'sync_status'     => SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
                'error_message'   => $reason,
                'updated_at'      => now(),
            ]);

        // 2. Bekukan SpreadsheetSidecarSync yang aktif/antre
        $frozenSidecarCount = DB::table('spreadsheet_sidecar_syncs')
            ->where('spreadsheet_connection_id', $connection->id)
            ->whereIn('sync_status', $activeStatuses)
            ->update([
                'sync_status'     => SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND,
                'locked_at'       => null,
                'locked_by'       => null,
                'next_retry_at'   => null,
                'error_message'   => $reason,
                'updated_at'      => now(),
            ]);

        ActivityLogger::log(
            action: 'spreadsheet_jobs_frozen_target_rebind',
            description: "Membekukan {$frozenRowCount} row syncs dan {$frozenSidecarCount} sidecar syncs untuk koneksi '{$connection->display_name}' karena pergantian target spreadsheet.",
            subject: $connection,
            actor: $actor,
            metadata: [
                'connection_key'       => $connection->connection_key,
                'old_spreadsheet_id'   => $oldId,
                'new_spreadsheet_id'   => $connection->spreadsheet_id,
                'frozen_row_count'     => $frozenRowCount,
                'frozen_sidecar_count' => $frozenSidecarCount,
            ]
        );

        return [
            'frozen_row_count'     => $frozenRowCount,
            'frozen_sidecar_count' => $frozenSidecarCount,
            'total_frozen'         => $frozenRowCount + $frozenSidecarCount,
        ];
    }

    /**
     * Dapatkan ringkasan antrean yang dibekukan untuk sebuah koneksi.
     */
    public function getPausedQueuesSummary(SpreadsheetConnection $connection): array
    {
        $rowCount = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
            ->where('sync_status', SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND)
            ->count();

        $sidecarCount = SpreadsheetSidecarSync::where('spreadsheet_connection_id', $connection->id)
            ->where('sync_status', SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND)
            ->count();

        $byTab = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
            ->where('sync_status', SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND)
            ->select('target_sheet_tab', DB::raw('count(*) as count'))
            ->groupBy('target_sheet_tab')
            ->pluck('count', 'target_sheet_tab')
            ->toArray();

        $recentPausedRowSyncs = SpreadsheetRowSync::with('syncable')
            ->where('spreadsheet_connection_id', $connection->id)
            ->where('sync_status', SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return [
            'row_syncs_count'     => $rowCount,
            'sidecar_syncs_count' => $sidecarCount,
            'total_count'         => $rowCount + $sidecarCount,
            'by_tab'              => $byTab,
            'recent_rows'         => $recentPausedRowSyncs,
        ];
    }

    /**
     * Rekonsiliasi satu record SpreadsheetRowSync yang dibekukan.
     * 
     * @param string $decision 'rebind_new_target' | 'cancel_sync'
     */
    public function reconcileRowSync(SpreadsheetRowSync $rowSync, string $decision, ?User $actor = null): array
    {
        if (!$rowSync->isPausedForTargetRebind()) {
            throw new DomainException("Record sync #{$rowSync->id} tidak berstatus 'paused_target_rebind'.");
        }

        $connection = $rowSync->spreadsheetConnection;
        if (!$connection) {
            throw new DomainException("Koneksi spreadsheet untuk record sync #{$rowSync->id} tidak ditemukan.");
        }

        if ($decision === 'cancel_sync') {
            $rowSync->sync_status = SpreadsheetRowSync::STATUS_FAILED;
            $rowSync->error_message = 'Sinkronisasi antrean dibatalkan oleh Admin (' . ($actor?->name ?? 'Admin') . ') saat rekonsiliasi target spreadsheet.';
            $rowSync->next_retry_at = null;
            $rowSync->locked_at = null;
            $rowSync->locked_by = null;
            try {
                $rowSync->save();
            } catch (\Throwable) {}

            try {
                ActivityLogger::log(
                    action: 'spreadsheet_queue_cancelled',
                    description: "Admin membatalkan antrean sinkronisasi baris {$rowSync->external_sync_id} tanpa mengubah data pendaftar.",
                    subject: $rowSync,
                    actor: $actor
                );
            } catch (\Throwable) {}

            return [
                'success' => true,
                'action'  => 'cancelled',
                'message' => "Antrean {$rowSync->external_sync_id} berhasil dibatalkan. Data pendaftar tetap utuh.",
            ];
        }

        if ($decision === 'rebind_new_target') {
            if (empty($connection->spreadsheet_id)) {
                throw new DomainException("Koneksi spreadsheet belum memiliki Spreadsheet ID target aktif.");
            }

            // Remote Deduplication Check: periksa apakah sync_id sudah ada di target spreadsheet baru
            $tabName = $rowSync->target_sheet_tab;
            $syncId = $rowSync->external_sync_id;

            try {
                $lookupRes = $this->transport->lookupRow($connection, $tabName, $syncId);
            } catch (Throwable $e) {
                Log::warning("Remote lookupRow failed during reconciliation for syncId {$syncId}: " . $e->getMessage());
                $lookupRes = null;
            }

            if ($lookupRes && $lookupRes->isSuccess() && !empty($lookupRes->rowIndex)) {
                // Baris sudah ada di target baru! Tandai tersinkronisasi tanpa membuat baris duplikat
                $rowSync->sync_status = SpreadsheetRowSync::STATUS_SYNCED;
                $rowSync->row_index = $lookupRes->rowIndex;
                try {
                    $rowSync->last_synced_at = now();
                } catch (\Throwable) {
                    $rowSync->setRawAttributes(array_merge($rowSync->getAttributes(), ['last_synced_at' => date('Y-m-d H:i:s')]));
                }
                $rowSync->target_spreadsheet_id = $connection->spreadsheet_id;
                $rowSync->target_generation = $connection->target_generation ?? 1;
                $rowSync->error_message = null;
                $rowSync->locked_at = null;
                $rowSync->locked_by = null;
                try {
                    $rowSync->save();
                } catch (\Throwable) {}

                try {
                    ActivityLogger::log(
                        action: 'spreadsheet_queue_rebound_deduplicated',
                        description: "Antrean {$rowSync->external_sync_id} dialihkan ke target baru dan dideteksi sudah ada pada baris {$lookupRes->rowIndex} (deduplikasi remote).",
                        subject: $rowSync,
                        actor: $actor
                    );
                } catch (\Throwable) {}

                return [
                    'success'   => true,
                    'action'    => 'deduplicated_synced',
                    'row_index' => $lookupRes->rowIndex,
                    'message'   => "Baris {$rowSync->external_sync_id} sudah ditemukan di Google Sheets pada baris {$lookupRes->rowIndex}. Ditandai tersinkronisasi tanpa menduplikasi data.",
                ];
            }

            // Belum ada di target baru: perbarui target snapshot dan antrekan untuk ditulis
            $rowSync->target_spreadsheet_id = $connection->spreadsheet_id;
            $rowSync->target_generation = $connection->target_generation ?? 1;
            $rowSync->sync_status = SpreadsheetRowSync::STATUS_PENDING;
            $rowSync->attempt_count = 0;
            try {
                $rowSync->next_retry_at = now();
            } catch (\Throwable) {
                $rowSync->setRawAttributes(array_merge($rowSync->getAttributes(), ['next_retry_at' => date('Y-m-d H:i:s')]));
            }
            $rowSync->error_message = null;
            $rowSync->locked_at = null;
            $rowSync->locked_by = null;
            try {
                $rowSync->save();
            } catch (\Throwable) {}

            try {
                DB::afterCommit(function () use ($rowSync, $connection) {
                    ProcessSpreadsheetRowSyncJob::dispatch(
                        $rowSync->id,
                        null,
                        $connection->spreadsheet_id,
                        $rowSync->target_sheet_tab,
                        $rowSync->schema_version,
                        $connection->id
                    );
                });
            } catch (\Throwable) {}

            try {
                ActivityLogger::log(
                    action: 'spreadsheet_queue_rebound_queued',
                    description: "Antrean {$rowSync->external_sync_id} dialihkan ke target Spreadsheet baru ({$connection->spreadsheet_id}) dan dijadwalkan untuk sinkronisasi.",
                    subject: $rowSync,
                    actor: $actor
                );
            } catch (\Throwable) {}

            return [
                'success' => true,
                'action'  => 'rebound_queued',
                'message' => "Antrean {$rowSync->external_sync_id} dialihkan ke target baru ({$connection->spreadsheet_id}) dan dijadwalkan untuk sinkronisasi.",
            ];
        }

        throw new DomainException("Keputusan rekonsiliasi '{$decision}' tidak valid. Pilihan: 'rebind_new_target' atau 'cancel_sync'.");
    }

    /**
     * Rekonsiliasi satu record SpreadsheetSidecarSync yang dibekukan.
     */
    public function reconcileSidecarSync(SpreadsheetSidecarSync $sidecarSync, string $decision, ?User $actor = null): array
    {
        if (!$sidecarSync->isPausedForTargetRebind()) {
            throw new DomainException("Record sidecar sync #{$sidecarSync->id} tidak berstatus 'paused_target_rebind'.");
        }

        $connection = $sidecarSync->spreadsheetConnection;
        if (!$connection) {
            throw new DomainException("Koneksi spreadsheet untuk sidecar sync #{$sidecarSync->id} tidak ditemukan.");
        }

        if ($decision === 'cancel_sync') {
            $sidecarSync->sync_status = SpreadsheetSidecarSync::STATUS_FAILED;
            $sidecarSync->error_message = 'Sinkronisasi sidecar dibatalkan oleh Admin (' . ($actor?->name ?? 'Admin') . ') saat rekonsiliasi target spreadsheet.';
            $sidecarSync->next_retry_at = null;
            $sidecarSync->locked_at = null;
            $sidecarSync->locked_by = null;
            try {
                $sidecarSync->save();
            } catch (\Throwable) {}

            return [
                'success' => true,
                'action'  => 'cancelled',
                'message' => "Antrean sidecar {$sidecarSync->external_sync_id} berhasil dibatalkan.",
            ];
        }

        if ($decision === 'rebind_new_target') {
            if (empty($connection->spreadsheet_id)) {
                throw new DomainException("Koneksi spreadsheet belum memiliki Spreadsheet ID target aktif.");
            }

            $sidecarSync->target_spreadsheet_id = $connection->spreadsheet_id;
            $sidecarSync->target_generation = $connection->target_generation ?? 1;
            $sidecarSync->sync_status = SpreadsheetSidecarSync::STATUS_PENDING;
            $sidecarSync->attempt_count = 0;
            try {
                $sidecarSync->next_retry_at = now();
            } catch (\Throwable) {
                $sidecarSync->setRawAttributes(array_merge($sidecarSync->getAttributes(), ['next_retry_at' => date('Y-m-d H:i:s')]));
            }
            $sidecarSync->error_message = null;
            $sidecarSync->locked_at = null;
            $sidecarSync->locked_by = null;
            try {
                $sidecarSync->save();
            } catch (\Throwable) {}

            try {
                DB::afterCommit(function () use ($sidecarSync, $connection) {
                    ProcessSidecarSyncJob::dispatch(
                        $sidecarSync->id,
                        null,
                        $connection->spreadsheet_id,
                        $sidecarSync->extension_sheet_tab,
                        $sidecarSync->form_schema_version,
                        $connection->id
                    );
                });
            } catch (\Throwable) {}

            return [
                'success' => true,
                'action'  => 'rebound_queued',
                'message' => "Antrean sidecar {$sidecarSync->external_sync_id} dialihkan ke target baru dan dijadwalkan ulang.",
            ];
        }

        throw new DomainException("Keputusan rekonsiliasi '{$decision}' tidak valid.");
    }

    /**
     * Batch rekonsiliasi seluruh antrean yang dibekukan pada suatu koneksi.
     */
    public function reconcileBatch(
        SpreadsheetConnection $connection,
        string $decision,
        array $rowSyncIds = [],
        array $sidecarSyncIds = [],
        ?User $actor = null
    ): array {
        $rowQuery = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
            ->where('sync_status', SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND);

        if (!empty($rowSyncIds)) {
            $rowQuery->whereIn('id', $rowSyncIds);
        }

        $rowSyncs = $rowQuery->get();

        $sidecarQuery = SpreadsheetSidecarSync::where('spreadsheet_connection_id', $connection->id)
            ->where('sync_status', SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND);

        if (!empty($sidecarSyncIds)) {
            $sidecarQuery->whereIn('id', $sidecarSyncIds);
        }

        $sidecarSyncs = $sidecarQuery->get();

        $stats = [
            'total_processed'      => 0,
            'deduplicated_synced'  => 0,
            'rebound_queued'       => 0,
            'cancelled'            => 0,
            'failed'               => 0,
            'errors'               => [],
        ];

        foreach ($rowSyncs as $row) {
            try {
                $res = $this->reconcileRowSync($row, $decision, $actor);
                $stats['total_processed']++;
                $action = $res['action'] ?? 'unknown';
                if ($action === 'deduplicated_synced') {
                    $stats['deduplicated_synced']++;
                } elseif ($action === 'rebound_queued') {
                    $stats['rebound_queued']++;
                } elseif ($action === 'cancelled') {
                    $stats['cancelled']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "Row #{$row->id}: " . $e->getMessage();
            }
        }

        foreach ($sidecarSyncs as $sidecar) {
            try {
                $res = $this->reconcileSidecarSync($sidecar, $decision, $actor);
                $stats['total_processed']++;
                $action = $res['action'] ?? 'unknown';
                if ($action === 'rebound_queued') {
                    $stats['rebound_queued']++;
                } elseif ($action === 'cancelled') {
                    $stats['cancelled']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "Sidecar #{$sidecar->id}: " . $e->getMessage();
            }
        }

        ActivityLogger::log(
            action: 'spreadsheet_queue_batch_reconciled',
            description: "Rekonsiliasi batch antrean dibekukan untuk koneksi '{$connection->display_name}' selesai dengan keputusan '{$decision}'. Diproses: {$stats['total_processed']}.",
            subject: $connection,
            actor: $actor,
            metadata: $stats
        );

        return $stats;
    }
}
