<?php

namespace App\Console\Commands;

use App\Jobs\SyncRegistrationToAbsenJob;
use App\Models\AbsenSyncState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessAbsenOutboxCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'absen:process-outbox
                            {--limit=50 : Jumlah maksimum antrean outbox yang diproses}
                            {--worker-id= : ID unik worker pelaksana proses}
                            {--dry-run : Tampilkan daftar outbox yang memenuhi syarat tanpa mengeksekusi}';

    /**
     * The console command description.
     */
    protected $description = 'Memproses rekaman transactional outbox Absen Djuragan yang berstatus pending atau perlu retry';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== PEMROSESAN OUTBOX SINKRONISASI ABSEN DJURAGAN ===');

        if (!config('services.absen_djuragan.outbox_enabled', false)) {
            $this->warn('Fitur outbox Absen Djuragan dinonaktifkan (services.absen_djuragan.outbox_enabled = false).');
            return 0;
        }

        if (!Schema::hasTable('absen_sync_states')) {
            $this->error("Tabel 'absen_sync_states' belum tersedia pada skema database.");
            return 1;
        }

        $limit = max(1, (int) $this->option('limit'));
        $workerId = (string) ($this->option('worker-id') ?: ('cli_worker_' . getmypid() . '_' . uniqid()));
        $isDryRun = (bool) $this->option('dry-run');

        $query = AbsenSyncState::whereIn('sync_status', [
                AbsenSyncState::STATUS_PENDING,
                AbsenSyncState::STATUS_FAILED,
            ])
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('max_attempts')
                  ->orWhereRaw('attempt_count < max_attempts');
            })
            ->orderBy('id', 'asc')
            ->limit($limit);

        $pendingRecords = $query->get();

        if ($pendingRecords->isEmpty()) {
            $this->info('Tidak ada rekaman outbox pending atau retry yang memenuhi syarat.');
            return 0;
        }

        $this->info("Ditemukan {$pendingRecords->count()} rekaman outbox untuk diproses.");

        if ($isDryRun) {
            $rows = $pendingRecords->map(fn ($r) => [
                'ID'              => $r->id,
                'Registration ID' => $r->registration_id,
                'Status'          => $r->sync_status,
                'Attempt'         => "{$r->attempt_count}/{$r->max_attempts}",
                'Version'         => $r->current_payload_version,
                'Next Retry'      => $r->next_retry_at ?? 'IMMEDIATE',
            ]);
            $this->table(['ID', 'Registration ID', 'Status', 'Attempt', 'Version', 'Next Retry'], $rows);
            $this->info('Dry-run selesai. Tidak ada transaksi yang dikirim.');
            return 0;
        }

        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($pendingRecords as $record) {
            $processed++;
            $this->line("[{$processed}/{$pendingRecords->count()}] Memproses Registrasi #{$record->registration_id}...");

            try {
                $job = new SyncRegistrationToAbsenJob($record->registration_id, $workerId);
                $resultState = $job->handle();

                if ($resultState && $resultState->sync_status === AbsenSyncState::STATUS_SYNCED) {
                    $succeeded++;
                    $this->info("  -> SUKSES disinkronkan ke Absen (User ID: {$resultState->absen_user_id}, Intern ID: {$resultState->absen_intern_id})");
                } elseif ($resultState && $resultState->sync_status === AbsenSyncState::STATUS_FAILED) {
                    $failed++;
                    $this->warn("  -> GAGAL (Attempt {$resultState->attempt_count}/{$resultState->max_attempts}): {$resultState->last_error_message}");
                } else {
                    $status = $resultState ? $resultState->sync_status : 'SKIPPED/DISABLED';
                    $this->line("  -> STATUS: {$status}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  -> EXCEPTION: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Ringkasan Pemrosesan: Total {$processed}, Sukses {$succeeded}, Gagal {$failed}.");
        return 0;
    }
}
