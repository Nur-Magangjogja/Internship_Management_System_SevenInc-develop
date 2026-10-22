<?php

namespace App\Console\Commands;

use App\Jobs\ProcessIntegrationOutboxEventJob;
use App\Models\IntegrationOutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessSpreadsheetOutboxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spreadsheet:process-outbox
                            {--limit=20 : Jumlah maksimal event outbox yang diproses}
                            {--dry-run : Mode simulasi tanpa melakukan dispatch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pindai dan proses event outbox sinkronisasi integrasi spreadsheet yang tertunda atau gagal';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Mode simulasi aktif: Tidak ada event outbox yang didispatch.');
        }

        $hasRetryColumn = Schema::hasColumn('integration_outbox_events', 'next_retry_at');

        $events = IntegrationOutboxEvent::where('event_type', 'form_schema.publish_sync')
            ->where(function ($query) use ($hasRetryColumn) {
                // 1. Pending events
                $query->where('status', IntegrationOutboxEvent::STATUS_PENDING)
                    // 2. Failed events ready for retry
                    ->orWhere(function ($q) use ($hasRetryColumn) {
                        $q->where('status', IntegrationOutboxEvent::STATUS_FAILED)
                          ->whereRaw('attempt_count < max_attempts');
                        if ($hasRetryColumn) {
                            $q->where(function ($sq) {
                                $sq->whereNull('next_retry_at')
                                   ->orWhere('next_retry_at', '<=', now());
                            });
                        } else {
                            $q->where(function ($sq) {
                                $sq->whereNull('last_attempt_at')
                                   ->orWhere('last_attempt_at', '<=', now()->subMinutes(5));
                            });
                        }
                    })
                    // 3. Stale processing events (> 5 minutes without completion)
                    ->orWhere(function ($q) {
                        $q->where('status', IntegrationOutboxEvent::STATUS_PROCESSING)
                          ->where('last_attempt_at', '<', now()->subMinutes(5));
                    });
            })
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        $count = $events->count();
        $this->info("Ditemukan {$count} event outbox yang memenuhi syarat pemrosesan.");

        if ($dryRun || $count === 0) {
            return 0;
        }

        $dispatched = 0;
        foreach ($events as $event) {
            ProcessIntegrationOutboxEventJob::dispatch($event->id);
            $dispatched++;
        }

        $this->line("Berhasil mendispatch {$dispatched} event outbox ke antrean antarmuka.");

        return 0;
    }
}
