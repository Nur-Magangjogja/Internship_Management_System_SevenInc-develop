<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Pemrosesan transactional outbox Absen Djuragan jika outbox aktif
        if (config('services.absen_djuragan.outbox_enabled', false)) {
            $schedule->command('absen:process-outbox --limit=50')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
