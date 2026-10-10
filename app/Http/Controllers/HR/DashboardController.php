<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:hr_staff,admin']);
    }

    /**
     * Tampilkan halaman utama Dashboard HR Staff.
     */
    public function index()
    {
        $user = auth()->user();
        $currentYear = (int) date('Y');
        $nowStr = now()->toDateTimeString();

        // 1. Metrik Operasional Follow-Up dalam 1 query agregasi
        $fuStats = FollowUpCycle::selectRaw("
            SUM(CASE WHEN status NOT IN ('completed', 'closed_no_response', 'cancelled') THEN 1 ELSE 0 END) as `open`,
            SUM(CASE WHEN status NOT IN ('completed', 'closed_no_response', 'cancelled') AND locked_by = ? AND lock_expires_at > ? THEN 1 ELSE 0 END) as my_claimed,
            SUM(CASE WHEN status = 'waiting_response' THEN 1 ELSE 0 END) as `waiting`,
            SUM(CASE WHEN status = 'completed' AND cycle_year = ? THEN 1 ELSE 0 END) as `completed`
        ", [$user->id, $nowStr, $currentYear])->first();

        $followUpMetrics = [
            'open'       => (int) ($fuStats->open ?? 0),
            'my_claimed' => (int) ($fuStats->my_claimed ?? 0),
            'waiting'    => (int) ($fuStats->waiting ?? 0),
            'completed'  => (int) ($fuStats->completed ?? 0),
        ];

        // 2. Metrik Siklus Hidup MOU dalam 1 query agregasi
        $mouStats = Mou::selectRaw("
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as `generated`,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as `delivered`,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as `active`
        ", [Mou::STATUS_GENERATED, Mou::STATUS_DELIVERED, Mou::STATUS_ACTIVE])->first();

        $mouMetrics = [
            'ready_to_generate' => FollowUpCycle::where('final_result', FollowUpCycle::RESULT_WILLING)
                ->whereDoesntHave('mous', fn($q) => $q->whereIn('status', [
                    Mou::STATUS_GENERATED,
                    Mou::STATUS_DELIVERED,
                    Mou::STATUS_ACTIVE
                ]))->count(),
            'generated'         => (int) ($mouStats->generated ?? 0),
            'delivered'         => (int) ($mouStats->delivered ?? 0),
            'active'            => (int) ($mouStats->active ?? 0),
        ];

        // 3. Metrik Master Data
        $masterCounts = [
            'institutions' => Institution::count(),
            'contacts'     => InstitutionContact::count(),
            'interns'      => InternshipRegistration::count(),
        ];

        // 4. Daftar Antrean Tugas Aktif & Cepat
        $myActiveTasks = FollowUpCycle::with(['institution', 'contact'])
            ->where('locked_by', $user->id)
            ->where('lock_expires_at', '>', now())
            ->latest('locked_at')
            ->limit(5)
            ->get();

        $pendingFollowUps = FollowUpCycle::open()
            ->with(['institution', 'contact', 'lockedByUser'])
            ->latest('updated_at')
            ->limit(5)
            ->get();

        $readyMouPartners = FollowUpCycle::with(['institution', 'contact'])
            ->where('final_result', FollowUpCycle::RESULT_WILLING)
            ->whereDoesntHave('mous', fn($q) => $q->whereIn('status', [
                Mou::STATUS_GENERATED,
                Mou::STATUS_DELIVERED,
                Mou::STATUS_ACTIVE
            ]))
            ->latest('completed_at')
            ->limit(5)
            ->get();

        $recentMous = Mou::with('institution')
            ->latest('id')
            ->limit(5)
            ->get();

        return view('hr.dashboard', compact(
            'user',
            'followUpMetrics',
            'mouMetrics',
            'masterCounts',
            'myActiveTasks',
            'pendingFollowUps',
            'readyMouPartners',
            'recentMous'
        ));
    }
}
