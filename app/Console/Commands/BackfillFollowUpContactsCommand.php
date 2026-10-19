<?php

namespace App\Console\Commands;

use App\Models\FollowUpCycle;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Services\ContactMatchingService;
use App\Services\FollowUpSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use Illuminate\Console\Command;

class BackfillFollowUpContactsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sertif:followup:backfill
                            {--sync : Langsung kirim dan sinkronkan baris ke Google Spreadsheet}
                            {--clear-remote : Bersihkan data lama pada tab sheet sebelum sinkronisasi ulang}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill kontak pembimbing & siklus follow-up dari pendaftaran pemagang yang RESMI DI-ACC (accepted/active/completed)';

    /**
     * Execute the console command.
     */
    public function handle(
        SpreadsheetSyncEngineService $syncEngine,
        FollowUpSpreadsheetMappingService $mappingService
    ): int {
        $this->info('Memulai pembersihan siklus lama yang belum di-ACC...');

        // 1. Bersihkan siklus lokal yang tidak memiliki pemagang yang di-ACC
        $unacceptedCycles = FollowUpCycle::all()->filter(fn($c) => !$mappingService->isEligibleForSync($c));
        $removedCyclesCount = 0;
        foreach ($unacceptedCycles as $uc) {
            SpreadsheetRowSync::where('syncable_type', FollowUpCycle::class)
                ->where('syncable_id', $uc->id)
                ->delete();
            $uc->delete();
            $removedCyclesCount++;
        }
        if ($removedCyclesCount > 0) {
            $this->warn("Dihapus {$removedCyclesCount} siklus follow-up lama yang pemagangnya belum di-ACC.");
        }

        // 2. Ambil pendaftaran yang berstatus resmi di-ACC (accepted, active, completed)
        $acceptedStatuses = [
            InternshipRegistration::STATUS_ACCEPTED,
            InternshipRegistration::STATUS_ACTIVE,
            InternshipRegistration::STATUS_COMPLETED,
        ];

        $registrations = InternshipRegistration::with('institution')
            ->whereNotNull('institution_id')
            ->whereIn('internship_status', $acceptedStatuses)
            ->orderBy('id', 'asc')
            ->get();

        $this->info("Menemukan {$registrations->count()} pendaftaran pemagang yang resmi di-ACC.");

        $processedCount = 0;
        $skippedCount = 0;
        $cycles = [];

        foreach ($registrations as $reg) {
            $namePlaceholder = ContactMatchingService::isPlaceholder($reg->supervisor_name);
            $phonePlaceholder = ContactMatchingService::isPlaceholder($reg->supervisor_contact);

            if ($namePlaceholder && $phonePlaceholder) {
                $skippedCount++;
                continue;
            }

            $cycle = ContactMatchingService::ensureContactAndFollowUpCycle($reg);
            if ($cycle) {
                $processedCount++;
                $cycles[$cycle->id] = $cycle;
            }
        }

        $this->info("Pendaftaran di-ACC diproses: {$processedCount}");
        $this->info("Pendaftaran dilewati (kontak kosong/placeholder): {$skippedCount}");
        $this->info("Total Siklus Follow-Up aktif (deduplikasi per institusi): " . count($cycles));
        $this->info("Total Kontak Pembimbing Resmi: " . InstitutionContact::count());

        $shouldClearRemote = (bool) $this->option('clear-remote');
        if ($shouldClearRemote) {
            $this->info('Mengirim instruksi pembersihan baris data lama pada sheet...');
            $conn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();
            if ($conn && $conn->apps_script_endpoint_url) {
                try {
                    $transport = app(GoogleAppsScriptTransport::class);
                    $res = $transport->dispatchSignedRequest(
                        $conn->apps_script_endpoint_url,
                        [
                            'action'         => 'clear_tab_data',
                            'connection_key' => 'followup',
                            'tab_name'       => 'Follow Up Guru Dosen',
                            'spreadsheet_id' => $conn->spreadsheet_id,
                        ]
                    );
                    if ($res->isSuccess()) {
                        $this->info('Berhasil mengosongkan baris data lama di sheet Follow Up Guru Dosen.');
                    } else {
                        $this->warn('Respon clear remote: ' . ($res->errorMessage ?? 'gagal/tidak didukung'));
                    }
                } catch (\Throwable $e) {
                    $this->warn('Gagal clear remote: ' . $e->getMessage());
                }
            }
        }

        $shouldSync = (bool) $this->option('sync');

        if ($shouldSync) {
            $this->info('Memulai pengiriman sinkronisasi ke Google Spreadsheet Follow-Up...');
            $rowSyncs = SpreadsheetRowSync::where('syncable_type', FollowUpCycle::class)
                ->whereIn('syncable_id', array_keys($cycles))
                ->orderBy('syncable_id', 'asc')
                ->get();

            $syncedCount = 0;
            $failedCount = 0;

            $bar = $this->output->createProgressBar($rowSyncs->count());
            $bar->start();

            $currentNo = 1;
            foreach ($rowSyncs as $rowSync) {
                try {
                    $success = $syncEngine->processSyncRecord($rowSync, force: true);
                    if ($success) {
                        $syncedCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Throwable $e) {
                    $failedCount++;
                }
                $bar->advance();
                $currentNo++;
            }

            $bar->finish();
            $this->newLine();

            $this->info("Hasil Sinkronisasi: {$syncedCount} Berhasil, {$failedCount} Gagal/Tertunda.");
        } else {
            $this->comment('Catatan: Jalankan dengan opsi --sync jika ingin langsung mengirim baris ke Google Spreadsheet secara instan.');
        }

        return self::SUCCESS;
    }
}
