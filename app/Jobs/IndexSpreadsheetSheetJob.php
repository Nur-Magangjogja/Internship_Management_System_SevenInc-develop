<?php

namespace App\Jobs;

use App\Models\SpreadsheetSearchIndexRun;
use App\Services\SpreadsheetSearch\SpreadsheetIndexingPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IndexSpreadsheetSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $indexRunId,
        public int $page = 1
    ) {}

    public function handle(SpreadsheetIndexingPipelineService $pipelineService): void
    {
        $run = SpreadsheetSearchIndexRun::find($this->indexRunId);
        if (!$run || in_array($run->status, [SpreadsheetSearchIndexRun::STATUS_CANCELLED, SpreadsheetSearchIndexRun::STATUS_FAILED], true)) {
            Log::info("IndexSpreadsheetSheetJob diabaikan karena run #{$this->indexRunId} tidak ditemukan atau status telah dibatalkan/gagal.");
            return;
        }

        try {
            $pipelineService->processPageBatch($run, $this->page);
        } catch (Throwable $e) {
            Log::error("IndexSpreadsheetSheetJob gagal pada run #{$this->indexRunId} page #{$this->page}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            $run->status = SpreadsheetSearchIndexRun::STATUS_FAILED;
            $run->last_error = $e->getMessage();
            $run->save();

            throw $e;
        }
    }
}
