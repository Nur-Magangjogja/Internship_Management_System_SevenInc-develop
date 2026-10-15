<?php

namespace App\Console\Commands;

use App\Models\AbsenSyncState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AbsenSyncStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'absen:sync-status
                            {--registration-id= : Filter berdasarkan registration_id tertentu}
                            {--failed-only : Hanya tampilkan rekaman yang berstatus failed}
                            {--limit=25 : Jumlah rekaman terbaru yang ditampilkan}';

    /**
     * The console command description.
     */
    protected $description = 'Menampilkan ringkasan status sinkronisasi transactional outbox Absen Djuragan';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== MONITORING STATUS SINKRONISASI ABSEN DJURAGAN ===');

        if (!Schema::hasTable('absen_sync_states')) {
            $this->error("Tabel 'absen_sync_states' belum tersedia pada database.");
            return 1;
        }

        $totalCount    = AbsenSyncState::count();
        $pendingCount  = AbsenSyncState::where('sync_status', AbsenSyncState::STATUS_PENDING)->count();
        $syncedCount   = AbsenSyncState::where('sync_status', AbsenSyncState::STATUS_SYNCED)->count();
        $failedCount   = AbsenSyncState::where('sync_status', AbsenSyncState::STATUS_FAILED)->count();
        $staleCount    = AbsenSyncState::where('sync_status', AbsenSyncState::STATUS_STALE_PAYLOAD)->count();
        $cancelledCount= AbsenSyncState::where('sync_status', AbsenSyncState::STATUS_CANCELLED)->count();

        $this->table(
            ['Total Outbox', 'Pending', 'Synced', 'Failed', 'Stale Payload', 'Cancelled'],
            [[$totalCount, $pendingCount, $syncedCount, $failedCount, $staleCount, $cancelledCount]]
        );

        $limit = max(1, (int) $this->option('limit'));
        $query = AbsenSyncState::with('registration.user')->orderBy('updated_at', 'desc')->limit($limit);

        if ($this->option('registration-id')) {
            $query->where('registration_id', (int) $this->option('registration-id'));
        }

        if ($this->option('failed-only')) {
            $query->where('sync_status', AbsenSyncState::STATUS_FAILED);
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info('Tidak ada baris rekaman outbox yang cocok dengan kriteria filter.');
            return 0;
        }

        $rows = $records->map(function ($r) {
            $candidateName = $r->registration?->fullname ?? $r->registration?->user?->name ?? '-';
            return [
                'Reg ID'     => $r->registration_id,
                'Nama'       => mb_strimwidth($candidateName, 0, 20, '...'),
                'Status'     => $r->sync_status,
                'Attempts'   => "{$r->attempt_count}/{$r->max_attempts}",
                'User/Intern'=> ($r->absen_user_id ? "U:{$r->absen_user_id} " : '') . ($r->absen_intern_id ? "I:{$r->absen_intern_id}" : '-'),
                'Last Synced'=> $r->last_synced_at ? $r->last_synced_at->toDateTimeString() : '-',
                'Last Error' => $r->last_error_message ? mb_strimwidth($r->last_error_message, 0, 35, '...') : '-',
            ];
        });

        $this->table(['Reg ID', 'Nama', 'Status', 'Attempts', 'User/Intern', 'Last Synced', 'Last Error'], $rows);

        return 0;
    }
}
