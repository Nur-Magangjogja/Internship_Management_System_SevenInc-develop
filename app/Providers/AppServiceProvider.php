<?php

namespace App\Providers;

use App\Models\InternshipRegistration as IR;
use App\Models\Webinar;
use App\Models\DocumentDownload;
use App\Models\WebinarAttendance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Services\SpreadsheetTransport\SpreadsheetTransportInterface::class,
            function ($app) {
                $driver = new \App\Services\SpreadsheetTransport\GoogleAppsScriptTransport();
                if ($driver->isConfigured()) {
                    return $driver;
                }

                return new \App\Services\SpreadsheetTransport\NullSpreadsheetTransport();
            }
        );

        // S17.18G.4R Fix 3: Scoped binding — fresh per request/job cycle, avoiding cross-request state leakage
        $this->app->scoped(\App\Services\FormBuilderService::class);

        // Phase C.3B.1 & C.3B.3A: Absen Outbound Transport binding with fail-closed default
        $this->app->bind(
            \App\Services\AbsenIntegration\Transports\AbsenOutboundTransportInterface::class,
            function ($app) {
                if (config('services.absen_djuragan.outbound_enabled', false)) {
                    return $app->make(\App\Services\AbsenIntegration\Transports\AbsenHttpOutboundTransport::class);
                }
                return new \App\Services\AbsenIntegration\Transports\DisabledAbsenOutboundTransport();
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (request()->isSecure() || config('app.env') === 'production' || str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Standarisasi Pagination Global SERTIF (Modul 4)
        \Illuminate\Pagination\Paginator::defaultView('components.pagination');
        \Illuminate\Pagination\Paginator::defaultSimpleView('components.pagination');

        View::composer('pemagang.layouts.app', function ($view) {
            $user = Auth::user();
            $notifications = collect();

            if ($user) {
                $registration = IR::where('user_id', $user->id)->latest('id')->first();
                $status = $registration?->internship_status;

                // ── Notifikasi dokumen magang ──
                if (in_array($status, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE, IR::STATUS_COMPLETED], true)) {
                    $notifications->push([
                        'title'   => 'LOA telah tersedia',
                        'message' => 'Surat keputusan magang sudah dapat diunduh.',
                        'url'     => route('pemagang.documents'),
                    ]);
                }

                if ($status === IR::STATUS_COMPLETED) {
                    $notifications->push([
                        'title'   => 'SKL siap diunduh',
                        'message' => 'Surat Keterangan Lulus sudah tersedia untuk Anda.',
                        'url'     => route('pemagang.documents'),
                    ]);
                    $notifications->push([
                        'title'   => 'Surat Penilaian siap diunduh',
                        'message' => 'Nilai magang Anda sudah dapat diunduh.',
                        'url'     => route('pemagang.documents'),
                    ]);
                    $notifications->push([
                        'title'   => 'Sertifikat Magang siap',
                        'message' => 'Sertifikat magang Anda sudah dapat diunduh.',
                        'url'     => route('pemagang.documents'),
                    ]);
                    $notifications->push([
                        'title'   => 'Membercard Digital siap',
                        'message' => 'Membercard alumni magang sudah dapat dilihat.',
                        'url'     => route('pemagang.membercard'),
                    ]);
                }

                // ── Notifikasi webinar baru ──
                // Hanya tampilkan kalau sudah submit form (bukan draft)
                if ($registration && !$registration->is_draft) {
                    $newWebinarCount = Webinar::whereDoesntHave('attendances', fn($q) => $q->where('user_id', $user->id))
                        ->where('created_at', '>=', now()->subDays(7))
                        ->count();

                    if ($newWebinarCount > 0) {
                        $notifications->push([
                            'title'   => "🎥 {$newWebinarCount} Webinar Baru",
                            'message' => 'Ada webinar baru yang bisa kamu ikuti!',
                            'url'     => route('pemagang.webinar.index'),
                        ]);
                    }
                }

                // ── Notifikasi sertifikat webinar baru ──
                $newWebinarCerts = DB::table('webinar_certificates')
                    ->join('webinar_attendances', 'webinar_certificates.attendance_id', '=', 'webinar_attendances.id')
                    ->where('webinar_attendances.user_id', $user->id)
                    ->where('webinar_certificates.created_at', '>=', now()->subDays(7))
                    ->count();

                if ($newWebinarCerts > 0) {
                    $notifications->push([
                        'title'   => '🏆 Sertifikat Webinar Tersedia',
                        'message' => "{$newWebinarCerts} sertifikat webinar baru sudah bisa diunduh.",
                        'url'     => route('pemagang.documents'),
                    ]);
                }

                // ── Notifikasi bukti kehadiran ditolak ──
                $rejectedCount = WebinarAttendance::where('user_id', $user->id)
                    ->where('status', WebinarAttendance::STATUS_REJECTED)
                    ->where('updated_at', '>=', now()->subDays(7))
                    ->count();

                if ($rejectedCount > 0) {
                    $notifications->push([
                        'title'   => '❌ Bukti Kehadiran Ditolak',
                        'message' => "Ada {$rejectedCount} bukti kehadiran webinar yang ditolak. Silakan upload ulang.",
                        'url'     => route('pemagang.webinar.index'),
                    ]);
                }
            }

            $view->with([
                'notifications'          => $notifications,
                'notificationCount'      => $notifications->count(),
                'notificationWebinarCount' => $notifications->filter(fn($n) => str_contains($n['url'] ?? '', 'webinar'))->count(),
            ]);
        });
    }
}
