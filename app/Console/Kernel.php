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
        // Recovery outbox sinkronisasi spreadsheet setiap 5 menit
        $schedule->command('spreadsheet:process-outbox')->everyFiveMinutes()->withoutOverlapping();

        // Recovery baris data dan berkas Drive setiap 10 menit
        $schedule->command('sertif:spreadsheet-sync:recover')->everyTenMinutes()->withoutOverlapping();
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
