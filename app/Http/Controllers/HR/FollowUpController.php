<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Services\FollowUpService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FollowUpController extends Controller
{
    protected FollowUpService $followUpService;

    public function __construct(FollowUpService $followUpService)
    {
        $this->followUpService = $followUpService;
    }

    /**
     * Daftar siklus follow-up aktif dan riwayat.
     */
    public function index(Request $request)
    {
        $year   = $request->input('year', (int) date('Y'));
        $status = $request->input('status'); // 'all', 'open', 'completed', 'closed'
        $search = $request->input('search');

        $query = FollowUpCycle::with(['institution', 'contact', 'attempts.performer', 'confirmer', 'lockedByUser']);

        if (!empty($year) && $year !== 'all') {
            $query->where('cycle_year', (int) $year);
        }

        if ($status === 'open') {
            $query->open();
        } elseif ($status === 'completed') {
            $query->where('status', FollowUpCycle::STATUS_COMPLETED);
        } elseif ($status === 'closed') {
            $query->whereIn('status', [
                FollowUpCycle::STATUS_COMPLETED,
                FollowUpCycle::STATUS_CLOSED_NO_RESPONSE,
                FollowUpCycle::STATUS_CANCELLED,
            ]);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('institution', fn($sub) => $sub->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('contact', fn($sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        $cycles = $query->latest('last_activity_at')->latest('id')->paginate(15)->withQueryString();

        $statsYear = (!empty($year) && $year !== 'all') ? (int) $year : (int) date('Y');
        $statsRow = FollowUpCycle::where('cycle_year', $statsYear)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('open', 'waiting_response') THEN 1 ELSE 0 END) as `open`,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as `completed`,
                SUM(CASE WHEN status = 'closed_no_response' THEN 1 ELSE 0 END) as `no_response`
            ")
            ->first();

        $stats = [
            'total'       => (int) ($statsRow->total ?? 0),
            'open'        => (int) ($statsRow->open ?? 0),
            'completed'   => (int) ($statsRow->completed ?? 0),
            'no_response' => (int) ($statsRow->no_response ?? 0),
        ];

        return view('hr.follow-up.index', compact('cycles', 'stats', 'year', 'status', 'search'));
    }

    /**
     * Form buka siklus follow-up baru.
     */
    public function create()
    {
        $institutions = Institution::active()
            ->with(['contacts' => fn($q) => $q->active()->verified()])
            ->orderBy('name')
            ->get();

        return view('hr.follow-up.create', compact('institutions'));
    }

    /**
     * Simpan siklus follow-up baru.
     */
    public function store(Request $request)
    {
        $request->validate([
            'institution_contact_id' => 'required|exists:institution_contacts,id',
            'cycle_year'             => 'nullable|integer|min:2020|max:2050',
        ]);

        $contact = InstitutionContact::with('institution')->findOrFail($request->institution_contact_id);

        try {
            $cycle = $this->followUpService->createCycle(
                contact: $contact,
                year: $request->filled('cycle_year') ? (int) $request->cycle_year : null,
                creator: auth()->user()
            );

            $showRoute = request()->routeIs('hr.*')
                ? route('hr.follow-up.show', $cycle)
                : route('admin.follow-up.show', $cycle);

            return redirect($showRoute)
                ->with('success', 'Siklus follow-up berhasil dibuka.');
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }
    }

    /**
     * Detail siklus, riwayat attempt, dan form pencatatan attempt berikutnya.
     */
    public function show(FollowUpCycle $cycle)
    {
        $cycle->load(['institution', 'contact', 'attempts.performer', 'confirmer', 'lockedByUser']);

        // Prioritaskan pemagang pada tahun siklus berjalan, tanpa merusak riwayat tahun lain
        $interns = $cycle->contact->internshipRegistrations()
            ->with(['user' => fn($q) => $q->withTrashed(), 'division'])
            ->wherePivot('relation_year', $cycle->cycle_year)
            ->latest('internship_registrations.id')
            ->get();

        // Fallback jika belum ada pemagang pada tahun berjalan, tampilkan riwayat pendaftar lain di kontak ini
        if ($interns->isEmpty()) {
            $interns = $cycle->contact->internshipRegistrations()
                ->with(['user' => fn($q) => $q->withTrashed(), 'division'])
                ->latest('internship_registrations.id')
                ->take(10)
                ->get();
        }

        return view('hr.follow-up.show', compact('cycle', 'interns'));
    }

    /**
     * Catat attempt follow-up baru (Attempt 1, 2, atau 3).
     */
    public function recordAttempt(Request $request, FollowUpCycle $cycle)
    {
        $request->validate([
            'followed_up_at'   => 'required|date',
            'channel'          => 'required|in:whatsapp,phone,email,onsite,other',
            'result'           => 'required|in:responded,call_back,no_answer,busy,wrong_number,declined',
            'response_text'    => 'nullable|string',
            'notes'            => 'nullable|string',
            'next_followup_at' => 'nullable|date',
        ]);

        try {
            $this->followUpService->recordAttempt(
                cycle: $cycle,
                data: $request->all(),
                performer: auth()->user()
            );

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Percobaan follow-up berhasil dicatat.'], 200);
            }

            return back()->with('success', 'Percobaan follow-up berhasil dicatat.');
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                $isLockConflict = str_contains($e->getMessage(), 'kunci') || str_contains($e->getMessage(), 'dikerjakan oleh');
                return response()->json(['message' => $e->getMessage()], $isLockConflict ? 409 : 422);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Selesaikan siklus follow-up dengan keputusan final.
     */
    public function complete(Request $request, FollowUpCycle $cycle)
    {
        $request->validate([
            'final_result' => 'required|in:willing,no_response,declined,cooperation_stopped',
            'final_notes'  => 'nullable|string|max:1000',
        ]);

        try {
            $this->followUpService->completeCycle(
                cycle: $cycle,
                finalResult: $request->final_result,
                finalNotes: $request->final_notes,
                confirmer: auth()->user()
            );

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Siklus follow-up berhasil diselesaikan.'], 200);
            }

            return back()->with('success', 'Siklus follow-up berhasil diselesaikan.');
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                $isLockConflict = str_contains($e->getMessage(), 'kunci') || str_contains($e->getMessage(), 'dikerjakan oleh');
                return response()->json(['message' => $e->getMessage()], $isLockConflict ? 409 : 422);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Klaim kunci tugas follow-up (Task Lock).
     */
    public function claimLock(Request $request, FollowUpCycle $cycle)
    {
        $request->validate([
            'duration' => 'nullable|integer|min:5|max:30',
        ]);

        try {
            $duration = (int) $request->input('duration', 15);
            $lockedCycle = $this->followUpService->claimLock($cycle, auth()->user(), $duration);

            if ($request->expectsJson()) {
                return response()->json([
                    'status'            => 'success',
                    'message'           => "Tugas berhasil diklaim dan dikunci selama {$duration} menit.",
                    'expires_at'        => $lockedCycle->lock_expires_at->toIso8601String(),
                    'remaining_minutes' => $lockedCycle->lockExpiresInMinutes(),
                ], 200);
            }

            return back()->with('success', "Tugas berhasil diklaim dan dikunci selama {$duration} menit.");
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 409);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Perpanjang masa aktif kunci tugas follow-up (Heartbeat API).
     */
    public function heartbeat(Request $request, FollowUpCycle $cycle)
    {
        $request->validate([
            'duration' => 'nullable|integer|min:5|max:30',
        ]);

        try {
            $duration = (int) $request->input('duration', 15);
            $renewedCycle = $this->followUpService->renewLock($cycle, auth()->user(), $duration);

            return response()->json([
                'status'            => 'success',
                'message'           => 'Kunci tugas berhasil diperpanjang.',
                'expires_at'        => $renewedCycle->lock_expires_at->toIso8601String(),
                'remaining_minutes' => $renewedCycle->lockExpiresInMinutes(),
            ], 200);
        } catch (DomainException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Lepaskan kunci tugas follow-up.
     */
    public function releaseLock(Request $request, FollowUpCycle $cycle)
    {
        try {
            $this->followUpService->releaseLock($cycle, auth()->user());

            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'success',
                    'message' => 'Kunci tugas berhasil dilepaskan.',
                ], 200);
            }

            return back()->with('success', 'Kunci tugas berhasil dilepaskan.');
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 409);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Lepaskan paksa kunci tugas follow-up (Admin only).
     */
    public function forceRelease(Request $request, FollowUpCycle $cycle)
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Hanya Administrator yang berwenang melepaskan paksa kunci tugas.');
        }

        try {
            $this->followUpService->releaseLock($cycle, auth()->user(), force: true);

            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'success',
                    'message' => 'Kunci tugas berhasil dilepaskan secara paksa.',
                ], 200);
            }

            return back()->with('success', 'Kunci tugas berhasil dilepaskan secara paksa.');
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 409);
            }
            return back()->with('error', $e->getMessage());
        }
    }
}
