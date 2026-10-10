<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InternshipRegistration as IR;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use App\Models\ActivityLog;
use App\Models\DailyReport;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\LeaveRequest;
use App\Models\Mou;
use App\Models\PendingTask;
use App\Models\User;
use Carbon\Carbon;

class DashboardController extends Controller
{

    public function edit()
    {
        $config = [
            'company_name'    => config('app.company_name'),
            'company_address' => config('app.company_address'),
            'company_city'    => config('app.company_city'),
            'leader_name'     => config('app.company_leader_name'),
            'leader_title'    => config('app.company_leader_title'),
        ];

        return view('admin.skl_editor', compact('config'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'company_name'    => 'required|string|max:100',
            'company_address' => 'required|string|max:255',
            'company_city'    => 'required|string|max:100',
            'leader_name'     => 'required|string|max:100',
            'leader_title'    => 'required|string|max:100',
            'logo'            => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'stamp'           => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        // 🔹 Upload logo & stempel
        if ($request->hasFile('logo')) {
            $request->file('logo')->storeAs('public/images/logos', 'logo_seveninc.png');
        }

        if ($request->hasFile('stamp')) {
            $request->file('stamp')->storeAs('public/images/logos', 'stamp.png');
        }

        // 🔹 Backup .env sebelum menulis ulang
        $envPath = base_path('.env');
        $backupPath = storage_path('app/backups/env-backup-'.now()->format('Ymd_His').'.env');
        if (File::exists($envPath)) {
            File::copy($envPath, $backupPath);
        }

        // 🔹 Edit isi .env dengan regex aman
        $envContent = File::get($envPath);
        $replacements = [
            'APP_COMPANY_NAME'         => $request->company_name,
            'APP_COMPANY_ADDRESS'      => $request->company_address,
            'APP_COMPANY_CITY'         => $request->company_city,
            'APP_COMPANY_LEADER_NAME'  => $request->leader_name,
            'APP_COMPANY_LEADER_TITLE' => $request->leader_title,
        ];

        foreach ($replacements as $key => $value) {
            $pattern = "/^{$key}=.*/m";
            $replacement = "{$key}=\"{$value}\"";

            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n{$replacement}";
            }
        }

        File::put($envPath, $envContent);

        // 🔹 Bersihkan & reload cache konfigurasi
        Artisan::call('config:clear');
        Artisan::call('config:cache');

        return back()->with('success', '✅ Data SKL berhasil diperbarui & disimpan!');
    }


    /**
     * GET /admin/user/{user}/daily-reports
     * Tampilkan semua Daily Report milik user (dengan filter optional).
     */
    public function showReports(User $user, Request $request)
    {

        $q         = trim($request->get('q', ''));
        $from      = $request->get('from');   // format: Y-m-d
        $to        = $request->get('to');     // format: Y-m-d

        $reports = DailyReport::with('user')
            ->where('user_id', $user->id)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('activities', 'like', "%{$q}%")
                       ->orWhere('challenges', 'like', "%{$q}%");
                });
            })
            ->when($from, fn($query) => $query->whereDate('date', '>=', $from))
            ->when($to,   fn($query) => $query->whereDate('date', '<=', $to))
            ->orderByDesc('date')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.reports', [
            'user'    => $user,
            'reports' => $reports,
            'filters' => compact('q', 'from', 'to'),
        ]);
    }

    /**
     * GET /admin/user/{user}/leave-requests
     * Tampilkan semua Leave Request milik user (dengan filter optional).
     */
    public function showLeaves(User $user, Request $request)
    {

        $q         = trim($request->get('q', ''));
        $from      = $request->get('from');      // Y-m-d
        $to        = $request->get('to');        // Y-m-d
        $type      = $request->get('type');      // sick/personal/other (opsional)
        $status    = $request->get('status');    // kalau kamu punya kolom status (approved/pending/rejected)

        $leaves = LeaveRequest::with('user')
            ->where('user_id', $user->id)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('reason', 'like', "%{$q}%")
                       ->orWhere('leave_type', 'like', "%{$q}%");
                });
            })
            ->when($from,   fn($query) => $query->whereDate('leave_date', '>=', $from))
            ->when($to,     fn($query) => $query->whereDate('leave_date', '<=', $to))
            ->when($type,   fn($query) => $query->where('leave_type', $type))
            ->when($status, fn($query) => $query->where('status', $status)) // hapus jika tidak ada kolom status
            ->orderByDesc('leave_date')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.leaves', [
            'user'   => $user,
            'leaves' => $leaves,
            'filters'=> compact('q', 'from', 'to', 'type', 'status'),
        ]);
    }

    /**
     * GET /admin/user/{user}/pending-tasks
     * Tampilkan semua Pending Task milik user (dengan filter optional).
     */
    public function showTasks(User $user, Request $request)
    {

        $q      = trim($request->get('q', ''));
        $from   = $request->get('from');   // Y-m-d
        $to     = $request->get('to');     // Y-m-d

        /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $tasks */
        $tasks = PendingTask::with('user')
            ->where('user_id', $user->id)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('title', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->when($from, fn($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to,   fn($query) => $query->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(15);

        $tasks->appends($request->query());
        
        return view('admin.users.tasks', [
            'user'  => $user,
            'tasks' => $tasks,
            'filters' => compact('q', 'from', 'to'),
        ]);
    }

    public function index()
    {
        // Kompatibilitas view: inisialisasi collection kosong tanpa query database berat yang tidak terpakai
        $allReports = collect();
        $allLeaves  = collect();
        $allTasks   = collect();
        $users      = collect();

        // 1. Ringkasan hitungan status pemagang dalam 1 query agregasi terpadu
        $irCounts = IR::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN internship_status = ? THEN 1 ELSE 0 END) as waiting,
            SUM(CASE WHEN internship_status = ? THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN internship_status = ? THEN 1 ELSE 0 END) as accepted,
            SUM(CASE WHEN internship_status = ? THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN internship_status = ? THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN internship_status = ? THEN 1 ELSE 0 END) as exited,
            SUM(CASE WHEN internship_status = ? THEN 1 ELSE 0 END) as rejected
        ", [
            IR::STATUS_WAITING,
            IR::STATUS_PENDING,
            IR::STATUS_ACCEPTED,
            IR::STATUS_ACTIVE,
            IR::STATUS_COMPLETED,
            IR::STATUS_EXITED,
            IR::STATUS_REJECTED,
        ])->first();

        $counts = [
            'total'     => (int) ($irCounts->total ?? 0),
            'waiting'   => (int) ($irCounts->waiting ?? 0),
            'pending'   => (int) ($irCounts->pending ?? 0),
            'accepted'  => (int) ($irCounts->accepted ?? 0),
            'active'    => (int) ($irCounts->active ?? 0),
            'completed' => (int) ($irCounts->completed ?? 0),
            'exited'    => (int) ($irCounts->exited ?? 0),
            'rejected'  => (int) ($irCounts->rejected ?? 0),
        ];

        // 2. 5 Pendaftaran terbaru
        $recentInterns = IR::latest('created_at')->limit(5)->get();

        // 3. Line chart: total pendaftar per bulan (6 bulan terakhir)
        $end   = Carbon::now()->endOfMonth();
        $start = (clone $end)->subMonths(5)->startOfMonth();

        $months = collect();
        for ($d = $start->copy(); $d <= $end; $d->addMonth()) {
            $months->push($d->copy());
        }
        $labels = $months->map->format('M Y')->all();
        $keys   = $months->map->format('Y-m')->all();

        $grouped = IR::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as c")
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('ym')
            ->pluck('c', 'ym')
            ->all();

        $totals = array_map(fn ($k) => $grouped[$k] ?? 0, $keys);

        $chart = [
            'labels' => $labels,
            'total'  => $totals,
        ];

        // 4. Metrik Monitoring Kemitraan & Dokumen MOU dalam query terpadu
        $instCounts = Institution::selectRaw("
            COUNT(*) as total_institutions,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_institutions
        ")->first();

        $nowStr = now()->toDateTimeString();
        $fcCounts = FollowUpCycle::selectRaw("
            SUM(CASE WHEN status IN ('open', 'waiting_response') THEN 1 ELSE 0 END) as followup_open,
            SUM(CASE WHEN status IN ('open', 'waiting_response') AND locked_by IS NOT NULL AND lock_expires_at > ? THEN 1 ELSE 0 END) as followup_locked,
            SUM(CASE WHEN status = 'waiting_response' THEN 1 ELSE 0 END) as followup_waiting,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as followup_completed,
            SUM(CASE WHEN final_result = 'willing' THEN 1 ELSE 0 END) as outcome_willing,
            SUM(CASE WHEN final_result = 'declined' THEN 1 ELSE 0 END) as outcome_declined,
            SUM(CASE WHEN final_result = 'no_response' THEN 1 ELSE 0 END) as outcome_no_response
        ", [$nowStr])->first();

        $nowDate = now()->toDateString();
        $expiringDate = now()->addDays(60)->toDateString();
        $mouCounts = Mou::selectRaw("
            COUNT(*) as mou_total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as mou_active,
            SUM(CASE WHEN status = 'generated' THEN 1 ELSE 0 END) as mou_generated,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as mou_delivered,
            SUM(CASE WHEN status = 'active' AND effective_end IS NOT NULL AND effective_end BETWEEN ? AND ? THEN 1 ELSE 0 END) as mou_expiring_soon,
            SUM(CASE WHEN status = 'expired' OR (status = 'active' AND effective_end IS NOT NULL AND effective_end < ?) THEN 1 ELSE 0 END) as mou_expired
        ", [$nowDate, $expiringDate, $nowDate])->first();

        $hrStaffCount = User::where('role', 'hr_staff')->where('is_banned', 0)->count();

        $partnershipMetrics = [
            'total_institutions'  => (int) ($instCounts->total_institutions ?? 0),
            'active_institutions' => (int) ($instCounts->active_institutions ?? 0),
            'followup_open'       => (int) ($fcCounts->followup_open ?? 0),
            'followup_locked'     => (int) ($fcCounts->followup_locked ?? 0),
            'followup_waiting'    => (int) ($fcCounts->followup_waiting ?? 0),
            'followup_completed'  => (int) ($fcCounts->followup_completed ?? 0),
            'outcome_willing'     => (int) ($fcCounts->outcome_willing ?? 0),
            'outcome_declined'    => (int) ($fcCounts->outcome_declined ?? 0),
            'outcome_no_response' => (int) ($fcCounts->outcome_no_response ?? 0),
            'mou_total'           => (int) ($mouCounts->mou_total ?? 0),
            'mou_active'          => (int) ($mouCounts->mou_active ?? 0),
            'mou_generated'       => (int) ($mouCounts->mou_generated ?? 0),
            'mou_delivered'       => (int) ($mouCounts->mou_delivered ?? 0),
            'mou_expiring_soon'   => (int) ($mouCounts->mou_expiring_soon ?? 0),
            'mou_expired'         => (int) ($mouCounts->mou_expired ?? 0),
            'hr_staff_count'      => $hrStaffCount,
        ];

        // 5. Log kemitraan & Dokumen MOU akan kedaluwarsa
        $recentPartnershipLogs = ActivityLog::latest('id')->limit(5)->get();

        $expiringMous = Mou::with('institution')
            ->where('status', Mou::STATUS_ACTIVE)
            ->whereNotNull('effective_end')
            ->whereBetween('effective_end', [now(), now()->addDays(60)])
            ->orderBy('effective_end')
            ->limit(5)
            ->get();

        return view('dashboard.index', compact(
            'counts',
            'chart',
            'users',
            'allReports',
            'allLeaves',
            'allTasks',
            'recentInterns',
            'partnershipMetrics',
            'recentPartnershipLogs',
            'expiringMous'
        ));
    }
}
