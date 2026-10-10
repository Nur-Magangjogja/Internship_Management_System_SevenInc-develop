<?php

namespace App\Console\Commands;

use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\RegistrationAttachment;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Console\Command;

class SpreadsheetSyncRecoveryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sertif:spreadsheet-sync:recover {--connection= : Optional connection key (registration|followup)} {--limit=50 : Maximum records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pindai dan kirim ulang antrean sinkronisasi spreadsheet dan unggah lampiran Drive yang tertunda';

    /**
     * Execute the console command.
     */
    public function handle(SpreadsheetSyncEngineService $syncEngine): int
    {
        $limit = (int) $this->option('limit');
        $this->info('Memulai pemindaian recovery antrean sinkronisasi...');

        // 1. Scan and dispatch spreadsheet row syncs
        $dispatchedRows = $syncEngine->scanAndDispatchPendingSyncs($limit);
        $this->line("Ditemukan {$dispatchedRows} baris spreadsheet sinkronisasi yang siap diproses ulang.");

        // 2. Scan pending/failed attachment uploads
        $pendingAttachments = RegistrationAttachment::whereIn('drive_sync_status', [
                RegistrationAttachment::STATUS_PENDING,
                RegistrationAttachment::STATUS_FAILED,
            ])
            ->whereNull('drive_file_id')
            ->limit($limit)
            ->get();

        $dispatchedAttachments = 0;
        foreach ($pendingAttachments as $attachment) {
            UploadRegistrationAttachmentToDriveJob::dispatch($attachment->id);
            $dispatchedAttachments++;
        }

        $this->line("Ditemukan {$dispatchedAttachments} berkas lampiran yang dijadwalkan untuk sinkronisasi Drive.");
        $this->info("Recovery selesai. Total baris: {$dispatchedRows}, Total lampiran: {$dispatchedAttachments}.");

        return Command::SUCCESS;
    }
}
