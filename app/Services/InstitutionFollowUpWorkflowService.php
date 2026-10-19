<?php

namespace App\Services;

use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\Mou;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class InstitutionFollowUpWorkflowService
{
    // 5 Unified Workflow Stages
    public const STAGE_NOT_CONTACTED   = 'not_contacted';   // Belum Dihubungi
    public const STAGE_WAITING_RESPONSE = 'waiting_response'; // Menunggu Respons
    public const STAGE_NEEDS_FOLLOW_UP = 'needs_follow_up'; // Perlu Tindak Lanjut (termasuk Overdue)
    public const STAGE_WILLING         = 'willing';         // Bersedia Bekerja Sama (Siap MoU)
    public const STAGE_COMPLETED       = 'completed';       // Selesai / Ditutup

    /**
     * Resolusi status alur kerja (workflow stage) untuk sebuah siklus follow-up.
     *
     * @param FollowUpCycle $cycle
     * @return array{
     *     stage: string,
     *     label: string,
     *     badge_class: string,
     *     is_overdue: bool,
     *     is_due_today: bool,
     *     due_label: ?string,
     *     next_action_label: string,
     *     next_action_type: string,
     *     next_action_url: string
     * }
     */
    public function resolveCycleWorkflow(FollowUpCycle $cycle): array
    {
        $today = Carbon::today();
        $latestAttempt = $cycle->relationLoaded('attempts')
            ? $cycle->attempts->sortByDesc('id')->first()
            : $cycle->attempts()->latest('id')->first();

        $nextFollowupDate = $latestAttempt?->next_followup_at
            ? Carbon::parse($latestAttempt->next_followup_at)->startOfDay()
            : null;

        $isOverdue = false;
        $isDueToday = false;
        $dueLabel = null;

        if (!$cycle->isClosed() && $nextFollowupDate) {
            if ($nextFollowupDate->lt($today)) {
                $isOverdue = true;
                $diffDays = $today->diffInDays($nextFollowupDate);
                $dueLabel = $diffDays === 0 ? 'Jatuh Tempo Kemarin' : "Lewat Jadwal ({$diffDays} hari lalu)";
            } elseif ($nextFollowupDate->equalTo($today)) {
                $isDueToday = true;
                $dueLabel = 'Jadwal Hari Ini';
            } else {
                $dueLabel = 'Jadwal: ' . $nextFollowupDate->format('d/m/Y');
            }
        }

        // Tentukan tahap berdasarkan status & kondisi siklus
        if ($cycle->isClosed()) {
            if ($cycle->final_result === FollowUpCycle::RESULT_WILLING) {
                $stage = self::STAGE_WILLING;
                $label = 'Bersedia Bekerja Sama';
                $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
            } else {
                $stage = self::STAGE_COMPLETED;
                $label = $cycle->statusLabel();
                $badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
            }
        } elseif ($isOverdue) {
            $stage = self::STAGE_NEEDS_FOLLOW_UP;
            $label = 'Perlu Tindak Lanjut (Overdue)';
            $badgeClass = 'bg-rose-50 text-rose-700 border-rose-200';
        } elseif ($cycle->status === FollowUpCycle::STATUS_WAITING_RESPONSE || ($latestAttempt && in_array($latestAttempt->result, ['no_response', 'sent']) && !$nextFollowupDate)) {
            $stage = self::STAGE_WAITING_RESPONSE;
            $label = 'Menunggu Respons';
            $badgeClass = 'bg-indigo-50 text-indigo-700 border-indigo-200';
        } elseif ($cycle->current_attempt_count === 0 && $cycle->status === FollowUpCycle::STATUS_OPEN) {
            $stage = self::STAGE_NOT_CONTACTED;
            $label = 'Belum Dihubungi';
            $badgeClass = 'bg-blue-50 text-blue-700 border-blue-200';
        } else {
            $stage = self::STAGE_NEEDS_FOLLOW_UP;
            $label = 'Perlu Tindak Lanjut';
            $badgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
        }

        // Tentukan Tindakan Berikutnya (Next Action)
        $isHr = request()->routeIs('hr.*') || auth()->user()?->role === 'hr_staff';
        $cycleShowUrl = $isHr
            ? route('hr.follow-up.show', $cycle)
            : route('admin.follow-up.show', $cycle);

        if ($stage === self::STAGE_WILLING) {
            $hasActiveMou = $cycle->mous()->where('status', Mou::STATUS_ACTIVE)->exists();
            $hasPendingMou = $cycle->mous()->whereIn('status', [Mou::STATUS_GENERATED, Mou::STATUS_DELIVERED])->exists();

            if ($hasActiveMou) {
                $nextActionLabel = 'Lihat MOU Aktif';
                $nextActionType = 'view_mou';
                $nextActionUrl = $isHr ? route('hr.mous.index', ['institution_id' => $cycle->institution_id]) : route('admin.mous.index', ['institution_id' => $cycle->institution_id]);
            } elseif ($hasPendingMou) {
                $nextActionLabel = 'Proses Dokumen MOU';
                $nextActionType = 'process_mou';
                $nextActionUrl = $isHr ? route('hr.mous.index', ['institution_id' => $cycle->institution_id]) : route('admin.mous.index', ['institution_id' => $cycle->institution_id]);
            } else {
                $nextActionLabel = 'Buat Draft Dokumen MOU';
                $nextActionType = 'create_mou';
                $nextActionUrl = $isHr
                    ? route('hr.mous.create', ['institution_id' => $cycle->institution_id, 'cycle_id' => $cycle->id])
                    : route('admin.mous.create', ['institution_id' => $cycle->institution_id, 'cycle_id' => $cycle->id]);
            }
        } elseif ($stage === self::STAGE_NOT_CONTACTED) {
            $nextActionLabel = 'Hubungi (Attempt 1)';
            $nextActionType = 'record_attempt';
            $nextActionUrl = $cycleShowUrl;
        } elseif ($stage === self::STAGE_NEEDS_FOLLOW_UP) {
            $nextAttemptNum = min(3, $cycle->current_attempt_count + 1);
            $nextActionLabel = $isOverdue
                ? "Tindak Lanjut Segera (Attempt {$nextAttemptNum})"
                : "Lakukan Attempt ke-{$nextAttemptNum}";
            $nextActionType = 'record_attempt';
            $nextActionUrl = $cycleShowUrl;
        } elseif ($stage === self::STAGE_WAITING_RESPONSE) {
            $nextActionLabel = 'Cek Status Respons / Follow-up';
            $nextActionType = 'check_response';
            $nextActionUrl = $cycleShowUrl;
        } else {
            $nextActionLabel = 'Lihat Riwayat Siklus';
            $nextActionType = 'view_history';
            $nextActionUrl = $cycleShowUrl;
        }

        return [
            'stage'             => $stage,
            'label'             => $label,
            'badge_class'       => $badgeClass,
            'is_overdue'        => $isOverdue,
            'is_due_today'      => $isDueToday,
            'due_label'         => $dueLabel,
            'next_action'       => $nextActionType,
            'next_action_label' => $nextActionLabel,
            'next_action_type'  => $nextActionType,
            'next_action_url'   => $nextActionUrl,
        ];
    }

    /**
     * Ringkasan status kerja sama institusi untuk tampilan profil institusi.
     *
     * @param Institution $institution
     * @return array
     */
    public function getInstitutionCooperationSummary(Institution $institution): array
    {
        $currentYear = (int) date('Y');
        $activeMou = $institution->activeMou;
        $latestMou = $institution->mous()->latest('id')->first();
        $currentCycle = $institution->followUpCycles()
            ->where('cycle_year', $currentYear)
            ->latest('id')
            ->first();

        $canCreateMou = false;
        $readyCycle = null;

        // Cari apakah ada siklus berstatus willing yang belum memiliki MOU aktif
        if (!$activeMou) {
            $readyCycle = $institution->followUpCycles()
                ->where('status', FollowUpCycle::STATUS_COMPLETED)
                ->where('final_result', FollowUpCycle::RESULT_WILLING)
                ->whereDoesntHave('mous', fn($q) => $q->whereIn('status', [Mou::STATUS_ACTIVE, Mou::STATUS_GENERATED, Mou::STATUS_DELIVERED]))
                ->latest('id')
                ->first();

            $canCreateMou = $readyCycle !== null;
        }

        // Tentukan status kemitraan
        if ($activeMou) {
            $statusLabel = 'MOU Aktif Berlaku';
            $statusBadge = 'bg-emerald-50 text-emerald-700 border-emerald-200';
            $statusDetail = 'Nomor: ' . $activeMou->company_mou_number . ' (s/d ' . ($activeMou->effective_end ? $activeMou->effective_end->format('d M Y') : '-') . ')';
        } elseif ($latestMou && in_array($latestMou->status, [Mou::STATUS_GENERATED, Mou::STATUS_DELIVERED], true)) {
            $statusLabel = 'MOU Dalam Proses';
            $statusBadge = 'bg-blue-50 text-blue-700 border-blue-200';
            $statusDetail = 'Status: ' . $latestMou->statusLabel . ' (Nomor: ' . $latestMou->company_mou_number . ')';
        } elseif ($canCreateMou) {
            $statusLabel = 'Bersedia Kerjasama (Siap MOU)';
            $statusBadge = 'bg-purple-50 text-purple-700 border-purple-200';
            $statusDetail = 'Terkonfirmasi melalui follow-up tahun ' . $readyCycle->cycle_year . ' (PIC: ' . ($readyCycle->contact?->name ?? 'Institusi') . ')';
        } elseif ($currentCycle && !$currentCycle->isClosed()) {
            $workflow = $this->resolveCycleWorkflow($currentCycle);
            $statusLabel = 'Dalam Penjajakan (' . $workflow['label'] . ')';
            $statusBadge = $workflow['badge_class'];
            $statusDetail = 'Siklus ' . $currentCycle->cycle_year . ' (Percobaan ' . $currentCycle->current_attempt_count . '/3)';
        } elseif ($latestMou && $latestMou->status === Mou::STATUS_EXPIRED) {
            $statusLabel = 'MOU Kedaluwarsa';
            $statusBadge = 'bg-rose-50 text-rose-700 border-rose-200';
            $statusDetail = 'Masa berlaku berakhir pada ' . ($latestMou->effective_end ? $latestMou->effective_end->format('d M Y') : '-');
        } else {
            $statusLabel = 'Belum Ada Perjanjian';
            $statusBadge = 'bg-slate-100 text-slate-600 border-slate-200';
            $statusDetail = 'Belum memiliki dokumen kerja sama MOU resmi';
        }

        return [
            'status_label'       => $statusLabel,
            'status_badge'       => $statusBadge,
            'status_detail'      => $statusDetail,
            'active_mou'         => $activeMou,
            'latest_mou'         => $latestMou,
            'current_cycle'      => $currentCycle,
            'can_create_mou'     => $canCreateMou,
            'ready_cycle'        => $readyCycle,
            'has_active_mou'     => !is_null($activeMou),
            'is_willing_for_mou' => $canCreateMou,
            'eligible_cycle'     => $readyCycle,
            'pending_mous_count' => Mou::where('institution_id', $institution->id)->whereIn('status', ['draft', 'pending_signature'])->count(),
        ];
    }

    /**
     * Hitung statistik agregat 5-status follow-up untuk tahun tertentu.
     *
     * @param int $year
     * @return array{
     *     total: int,
     *     not_contacted: int,
     *     waiting_response: int,
     *     needs_follow_up: int,
     *     overdue: int,
     *     willing: int,
     *     completed: int
     * }
     */
    public function getAggregateWorkflowStats(int $year): array
    {
        $today = Carbon::today()->toDateString();

        $cycles = FollowUpCycle::where('cycle_year', $year)->get();

        $total           = $cycles->count();
        $notContacted    = 0;
        $waitingResponse = 0;
        $needsFollowUp   = 0;
        $overdueCount    = 0;
        $willingCount    = 0;
        $completedCount  = 0;

        foreach ($cycles as $cycle) {
            $workflow = $this->resolveCycleWorkflow($cycle);

            if ($workflow['is_overdue']) {
                $overdueCount++;
            }

            match ($workflow['stage']) {
                self::STAGE_NOT_CONTACTED    => $notContacted++,
                self::STAGE_WAITING_RESPONSE => $waitingResponse++,
                self::STAGE_NEEDS_FOLLOW_UP  => $needsFollowUp++,
                self::STAGE_WILLING          => $willingCount++,
                self::STAGE_COMPLETED        => $completedCount++,
                default                      => $completedCount++,
            };
        }

        return [
            'total'            => $total,
            'not_contacted'    => $notContacted,
            'waiting_response' => $waitingResponse,
            'needs_follow_up'  => $needsFollowUp,
            'overdue'          => $overdueCount,
            'willing'          => $willingCount,
            'completed'        => $completedCount,
            // Backwards-compatible aliases for legacy views and tests
            'open'             => $notContacted,
            'in_progress'      => $waitingResponse + $needsFollowUp,
            'needs_action'     => $needsFollowUp + $overdueCount,
        ];
    }

    /**
     * Deteksi apakah terdapat pengiriman attempt ganda identik dalam selang waktu singkat (Anti Double-Submit).
     *
     * @param FollowUpCycle $cycle
     * @param array $data
     * @param User $performer
     * @param int $thresholdSeconds
     * @return bool
     */
    public function isDuplicateAttempt(FollowUpCycle $cycle, array $data, User $performer, int $thresholdSeconds = 30): bool
    {
        $latest = $cycle->attempts()
            ->where('performed_by', $performer->id)
            ->latest('id')
            ->first();

        if (!$latest) {
            return false;
        }

        // Jika attempt dibuat dalam selang threshold detik dengan channel dan result yang sama
        $isRecent = $latest->created_at && $latest->created_at->diffInSeconds(now()) <= $thresholdSeconds;
        $sameChannel = $latest->channel === ($data['channel'] ?? 'whatsapp');
        $sameResult = $latest->result === ($data['result'] ?? '');

        return $isRecent && $sameChannel && $sameResult;
    }
}
