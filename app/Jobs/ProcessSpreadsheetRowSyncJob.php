<?php

namespace App\Jobs;

use App\Models\SpreadsheetRowSync;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSpreadsheetRowSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Retry logic is managed explicitly in SpreadsheetSyncEngineService
    public int $timeout = 60;

    public function __construct(
        public int $rowSyncId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SpreadsheetSyncEngineService $engine): void
    {
        $rowSync = SpreadsheetRowSync::find($this->rowSyncId);

        if (!$rowSync) {
            return;
        }

        $engine->processSyncRecord($rowSync);
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $rowSync = SpreadsheetRowSync::find($this->rowSyncId);

        if ($rowSync && $rowSync->sync_status === SpreadsheetRowSync::STATUS_PROCESSING) {
            $rowSync->update([
                'sync_status'   => SpreadsheetRowSync::STATUS_FAILED,
                'error_message' => $exception ? 'Job worker failed: ' . $exception->getMessage() : 'Job worker failed unexpectedly.',
                'locked_at'     => null,
                'locked_by'     => null,
            ]);
        }
    }
}
