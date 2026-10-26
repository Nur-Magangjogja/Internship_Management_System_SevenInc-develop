<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Pemrosesan outbox sinkronisasi spreadsheet terkontrol di background
        $schedule->command('spreadsheet:process-outbox')->everyMinute()->withoutOverlapping();

        // Pemrosesan dan pemulihan antrean baris utama, sidecar, dan lampiran Drive tanpa bergantung pada browser
        $schedule->command('sertif:spreadsheet-sync:recover --include-sidecar')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }
}
