<?php

namespace App\Jobs;

use App\Models\SpreadsheetSidecarSync;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessSidecarSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Retry logic is managed explicitly in SpreadsheetSyncEngineService
    public int $timeout = 60;
    public string $fenceToken;
    public ?string $snapshotSpreadsheetId = null;
    public ?string $snapshotSheetName = null;

    public function __construct(
        public int $sidecarSyncId,
        ?string $fenceToken = null,
        ?string $snapshotSpreadsheetId = null,
        ?string $snapshotSheetName = null
    ) {
        $this->fenceToken = $fenceToken ?: (string) Str::uuid();
        $this->snapshotSpreadsheetId = $snapshotSpreadsheetId;
        $this->snapshotSheetName = $snapshotSheetName;
    }

    /**
     * Execute the job.
     */
    public function handle(SpreadsheetSyncEngineService $engine): void
    {
        $sidecarSync = SpreadsheetSidecarSync::find($this->sidecarSyncId);

        if (!$sidecarSync) {
            return;
        }

        $engine->processSidecarSyncRecord(
            $sidecarSync,
            requestedFenceToken: $this->fenceToken,
            snapshotSpreadsheetId: $this->snapshotSpreadsheetId,
            snapshotSheetName: $this->snapshotSheetName
        );
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $errMsg = $exception ? 'Job worker failed: ' . $exception->getMessage() : 'Job worker failed unexpectedly.';

        // Fail-closed atomic conditional update strictly guarded by THIS job's durable fenceToken:
        // If lease was taken over by another worker, locked_by will not match, affecting 0 rows.
        DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $this->sidecarSyncId)
            ->where('locked_by', $this->fenceToken)
            ->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
            ->update([
                'sync_status'   => SpreadsheetSidecarSync::STATUS_FAILED,
                'error_message' => $errMsg,
                'locked_at'     => null,
                'locked_by'     => null,
                'updated_at'    => now(),
            ]);
    }
}
