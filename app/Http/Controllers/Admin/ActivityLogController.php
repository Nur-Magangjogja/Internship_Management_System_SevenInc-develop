<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Tampilkan daftar audit activity logs dengan filter dinamis.
     */
    public function index(Request $request)
    {
        $action    = $request->input('action');
        $actorRole = $request->input('actor_role');
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');
        $search    = $request->input('search');

        $query = ActivityLog::with('actor')->latest('id');

        if (!empty($action) && $action !== 'all') {
            $query->where('action', $action);
        }

        if (!empty($actorRole) && $actorRole !== 'all') {
            $query->where('actor_role_snapshot', $actorRole);
        }

        if (!empty($dateFrom)) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if (!empty($dateTo)) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('actor_name_snapshot', 'like', "%{$search}%")
                  ->orWhere('actor_email_snapshot', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%")
                  ->orWhere('subject_public_ref', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(20)->withQueryString();

        // Opsi filter aksi unik dari database
        $distinctActions = ActivityLog::select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        // Statistik ringkas log
        $stats = [
            'total_today' => ActivityLog::whereDate('created_at', today())->count(),
            'total_week'  => ActivityLog::where('created_at', '>=', now()->startOfWeek())->count(),
            'total_all'   => ActivityLog::count(),
        ];

        return view('admin.activity-logs.index', compact(
            'logs',
            'distinctActions',
            'stats',
            'action',
            'actorRole',
            'dateFrom',
            'dateTo',
            'search'
        ));
    }

    /**
     * Detail log untuk inspeksi snapshot JSON via AJAX / modal.
     */
    public function show(ActivityLog $activityLog)
    {
        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'public_uuid'         => $activityLog->public_uuid,
                    'action'              => $activityLog->action,
                    'actor_name'          => $activityLog->actor_name_snapshot,
                    'actor_email'         => $activityLog->actor_email_snapshot,
                    'actor_role'          => $activityLog->actor_role_snapshot,
                    'subject_type'        => $activityLog->subject_type ? class_basename($activityLog->subject_type) : '-',
                    'subject_ref'         => $activityLog->subject_public_ref ?? '-',
                    'description'         => $activityLog->description,
                    'before_data'         => $activityLog->before_data,
                    'after_data'          => $activityLog->after_data,
                    'metadata'            => $activityLog->metadata,
                    'ip_address'          => $activityLog->ip_address,
                    'user_agent'          => $activityLog->user_agent,
                    'created_at'          => $activityLog->created_at?->format('d M Y H:i:s'),
                ],
            ]);
        }

        return redirect()->route('admin.activity-logs.index');
    }
}
