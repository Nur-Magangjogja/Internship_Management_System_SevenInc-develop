<?php

namespace App\Console\Commands;

use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetRowSync;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Console\Command;

class SpreadsheetSyncRecoveryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sertif:spreadsheet-sync:recover
                            {--connection= : Optional connection key (registration|followup)}
                            {--limit=50 : Maximum records to process}
                            {--include-sidecar : Juga pindai antrean sidecar custom fields}
                            {--dry-run : Mode simulasi tanpa melakukan dispatch atau mutasi database}';

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
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $includeSidecar = (bool) $this->option('include-sidecar');
        $connectionKey = $this->option('connection') ? trim((string) $this->option('connection')) : null;

        if ($dryRun) {
            $this->warn('[DRY-RUN] Mode simulasi aktif: Tidak ada mutasi database atau job dispatch yang dijalankan.');
        }

        $this->info('Memulai pemindaian recovery antrean sinkronisasi...');

        // 1. Scan and dispatch spreadsheet row syncs
        $dispatchedRows = $syncEngine->scanAndDispatchPendingSyncs($limit, $dryRun, $connectionKey);
        if ($dryRun) {
            $this->line("[DRY-RUN] Ditemukan {$dispatchedRows} baris spreadsheet utama yang memenuhi syarat pemulihan.");
        } else {
            $this->line("Ditemukan {$dispatchedRows} baris spreadsheet sinkronisasi yang siap diproses ulang.");
        }

        // 2. Scan pending/failed attachment uploads
        $pendingAttachmentsQuery = RegistrationAttachment::whereIn('drive_sync_status', [
                RegistrationAttachment::STATUS_PENDING,
                RegistrationAttachment::STATUS_FAILED,
            ])
            ->whereNull('drive_file_id')
            ->limit($limit);

        if ($dryRun) {
            $dispatchedAttachments = $pendingAttachmentsQuery->count();
            $this->line("[DRY-RUN] Ditemukan {$dispatchedAttachments} berkas lampiran yang memenuhi syarat sinkronisasi Drive.");
        } else {
            $pendingAttachments = $pendingAttachmentsQuery->get();
            $dispatchedAttachments = 0;
            foreach ($pendingAttachments as $attachment) {
                UploadRegistrationAttachmentToDriveJob::dispatch($attachment->id);
                $dispatchedAttachments++;
            }
            $this->line("Ditemukan {$dispatchedAttachments} berkas lampiran yang dijadwalkan untuk sinkronisasi Drive.");
        }

        // 3. Optional Sidecar Recovery Scanner
        $dispatchedSidecars = 0;
        if ($includeSidecar) {
            $dispatchedSidecars = $syncEngine->scanAndDispatchPendingSidecarSyncs($limit, $dryRun, $connectionKey);
            if ($dryRun) {
                $this->line("[DRY-RUN] Ditemukan {$dispatchedSidecars} baris sidecar custom fields yang memenuhi syarat pemulihan.");
            } else {
                $this->line("Ditemukan {$dispatchedSidecars} baris sidecar custom fields yang dijadwalkan untuk diproses.");
            }
        }

        $summaryPrefix = $dryRun ? '[DRY-RUN] Simulasi' : 'Recovery';
        $sidecarSummary = $includeSidecar ? ", Total sidecar: {$dispatchedSidecars}" : '';
        $this->info("{$summaryPrefix} selesai. Total baris: {$dispatchedRows}, Total lampiran: {$dispatchedAttachments}{$sidecarSummary}.");

        return Command::SUCCESS;
    }
}
