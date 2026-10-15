<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\InternshipRegistration as IR;
use Carbon\Carbon;

class UpdateInternshipStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-internship-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Hanya pendaftaran yang TELAH DITERIMA resmi oleh Admin (STATUS_ACCEPTED) yang dapat menjadi aktif (S17.17)
        $registrations = IR::where('internship_status', IR::STATUS_ACCEPTED)
            ->whereNotNull('start_date')
            ->get();

        // Periksa setiap magang
        foreach ($registrations as $registration) {
            // Cek jika tanggal mulai sudah tercapai
            if (Carbon::parse($registration->start_date)->isToday() || Carbon::parse($registration->start_date)->isPast()) {
                // Perbarui status menjadi "active" melalui alur lifecycle resmi
                app(\App\Services\RegistrationLifecycleService::class)->transition(
                    $registration,
                    IR::STATUS_ACTIVE
                );

                // Tampilkan info bahwa status telah diperbarui
                $this->info("Status magang untuk {$registration->fullname} diperbarui ke 'aktif'");
            }
        }

        // Beri info bahwa command sudah selesai dijalankan
        $this->info('Proses update status magang selesai.');
    }

}
