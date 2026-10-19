<?php

namespace App\Services;

use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\InstitutionContact;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowUpService
{
    /**
     * Inisialisasi siklus follow-up baru untuk kontak institusi pada tahun tertentu.
     */
    public function createCycle(InstitutionContact $contact, ?int $year = null, ?User $creator = null): FollowUpCycle
    {
        $year = $year ?? (int) date('Y');

        if (!$contact->institution->is_active) {
            throw ValidationException::withMessages([
                'institution_id' => 'Institusi ini sedang nonaktif dan tidak dapat membuka siklus follow-up baru.'
            ]);
        }

        if (!$contact->is_active) {
            throw ValidationException::withMessages([
                'institution_contact_id' => 'Kontak pembimbing ini sedang nonaktif dan tidak dapat membuka siklus follow-up baru.'
            ]);
        }

        if (!$contact->is_verified) {
            throw ValidationException::withMessages([
                'institution_contact_id' => 'Kontak pembimbing ini belum diverifikasi dan tidak dapat membuka siklus follow-up baru.'
            ]);
        }

        $exists = FollowUpCycle::where('institution_contact_id', $contact->id)
            ->where('cycle_year', $year)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'institution_contact_id' => "Siklus follow-up untuk kontak {$contact->name} pada tahun {$year} sudah terdaftar."
            ]);
        }

        $cycle = FollowUpCycle::create([
            'institution_id'         => $contact->institution_id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => $year,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
            'confirmed_by'           => $creator?->id,
            'confirmed_at'           => $creator ? now() : null,
        ]);

        ActivityLogger::log(
            action: 'followup_cycle_created',
            description: "Membuka siklus follow-up tahun {$year} untuk {$contact->name} ({$contact->institution->name})",
            subject: $cycle,
            after: ['cycle_year' => $year, 'contact_name' => $contact->name, 'institution_name' => $contact->institution->name],
            actor: $creator
        );

        // Enqueue outbound spreadsheet sync (Follow-Up Tab)
        try {
            app(\App\Services\SpreadsheetSyncEngineService::class)->queueSyncForFollowUpCycle($cycle);
        } catch (\Throwable) {
            // Outbound sync queue failure must never abort follow-up operations
        }

        return $cycle;
    }

    /**
     * Klaim kunci tugas follow-up (Task Lock) untuk mencegah tabrakan kerja antar-staf.
     */
    public function claimLock(FollowUpCycle $cycle, User $user, int $durationMinutes = 15): FollowUpCycle
    {
        if (!$user->isActive()) {
            throw new DomainException('Akun pengguna tidak aktif atau telah diblokir.');
        }

        $durationMinutes = max(5, min(30, $durationMinutes));

        return DB::transaction(function () use ($cycle, $user, $durationMinutes) {
            /** @var FollowUpCycle $lockedCycle */
            $lockedCycle = FollowUpCycle::where('id', $cycle->id)->lockForUpdate()->first();

            if (!$lockedCycle) {
                throw new DomainException('Siklus follow-up tidak ditemukan.');
            }

            if ($lockedCycle->isClosed()) {
                throw new DomainException('Siklus follow-up ini sudah ditutup dan tidak dapat dikunci.');
            }

            // Jika sedang dikunci aktif oleh staf lain
            if ($lockedCycle->isLockedByOther($user)) {
                $holder = $lockedCycle->lockedByUser; // withTrashed
                $holderName = $holder ? $holder->name : 'staf lain';
                $expiresAt = $lockedCycle->lock_expires_at ? $lockedCycle->lock_expires_at->format('H:i') : '-';
                throw new DomainException("Tugas follow-up ini sedang dikunci oleh {$holderName} hingga pukul {$expiresAt}.");
            }

            $expiresAt = now()->addMinutes($durationMinutes);

            // Jika user yang sama memperbarui kunci yang sedang dipegangnya (idempotent / perpanjangan)
            if ($lockedCycle->isLockedBy($user)) {
                $lockedCycle->update([
                    'lock_expires_at'  => $expiresAt,
                    'last_activity_at' => now(),
                ]);

                ActivityLogger::log(
                    action: 'followup_cycle_lock_renewed',
                    description: "Memperpanjang kunci tugas follow-up {$lockedCycle->institution->name} ({$lockedCycle->contact->name}) selama {$durationMinutes} menit",
                    subject: $lockedCycle,
                    after: [
                        'locked_by'       => $user->id,
                        'lock_expires_at' => $expiresAt->toIso8601String(),
                    ],
                    actor: $user
                );

                return $lockedCycle;
            }

            // Reclaim lock jika sebelumnya ada yang mengunci tapi sudah expired atau owner tidak valid (banned/deleted/missing)
            $isReclaim = $lockedCycle->locked_by !== null;
            $beforeSnapshot = null;
            $reclaimReason = null;

            if ($isReclaim) {
                $prevOwner = User::withTrashed()->find($lockedCycle->locked_by);
                if (!$prevOwner) {
                    $reclaimReason = 'owner_missing';
                } elseif ($prevOwner->trashed()) {
                    $reclaimReason = 'owner_deleted';
                } elseif ($prevOwner->is_banned || $prevOwner->banned_at !== null) {
                    $reclaimReason = 'owner_banned';
                } else {
                    $reclaimReason = 'lock_expired';
                }

                $beforeSnapshot = [
                    'previous_locked_by'       => $lockedCycle->locked_by,
                    'previous_locked_by_name'  => $prevOwner?->name ?? 'Unknown',
                    'previous_locked_at'       => $lockedCycle->locked_at?->toIso8601String(),
                    'previous_lock_expires_at' => $lockedCycle->lock_expires_at?->toIso8601String(),
                    'reclaim_reason'           => $reclaimReason,
                ];
            }

            $lockedCycle->update([
                'locked_by'        => $user->id,
                'locked_at'        => now(),
                'lock_expires_at'  => $expiresAt,
                'last_activity_at' => now(),
            ]);

            if ($isReclaim) {
                ActivityLogger::log(
                    action: 'followup_cycle_lock_reclaimed',
                    description: "Mengklaim ulang kunci tugas follow-up {$lockedCycle->institution->name} ({$lockedCycle->contact->name}) (alasan: {$reclaimReason})",
                    subject: $lockedCycle,
                    before: $beforeSnapshot,
                    after: [
                        'locked_by'       => $user->id,
                        'lock_expires_at' => $expiresAt->toIso8601String(),
                    ],
                    actor: $user
                );
            } else {
                ActivityLogger::log(
                    action: 'followup_cycle_locked',
                    description: "Mengunci tugas follow-up {$lockedCycle->institution->name} ({$lockedCycle->contact->name}) selama {$durationMinutes} menit",
                    subject: $lockedCycle,
                    after: [
                        'locked_by'       => $user->id,
                        'lock_expires_at' => $expiresAt->toIso8601String(),
                    ],
                    actor: $user
                );
            }

            return $lockedCycle;
        });
    }

    /**
     * Perpanjang masa aktif kunci tugas follow-up (Heartbeat).
     */
    public function renewLock(FollowUpCycle $cycle, User $user, int $durationMinutes = 15): FollowUpCycle
    {
        if (!$user->isActive()) {
            throw new DomainException('Akun pengguna tidak aktif atau telah diblokir.');
        }

        $durationMinutes = max(5, min(30, $durationMinutes));

        return DB::transaction(function () use ($cycle, $user, $durationMinutes) {
            /** @var FollowUpCycle $lockedCycle */
            $lockedCycle = FollowUpCycle::where('id', $cycle->id)->lockForUpdate()->first();

            if (!$lockedCycle) {
                throw new DomainException('Siklus follow-up tidak ditemukan.');
            }

            if ($lockedCycle->isClosed()) {
                throw new DomainException('Siklus follow-up ini sudah ditutup dan tidak dapat diperpanjang kuncinya.');
            }

            if (!$lockedCycle->isLockedBy($user)) {
                if ($lockedCycle->isLockedByOther($user)) {
                    $holder = $lockedCycle->lockedByUser; // withTrashed
                    $holderName = $holder ? $holder->name : 'staf lain';
                    $expiresAt = $lockedCycle->lock_expires_at ? $lockedCycle->lock_expires_at->format('H:i') : '-';
                    throw new DomainException("Tugas follow-up ini sedang dikunci oleh {$holderName} hingga pukul {$expiresAt}.");
                }
                throw new DomainException('Kunci tugas telah kedaluwarsa atau belum Anda klaim.');
            }

            $expiresAt = now()->addMinutes($durationMinutes);

            $lockedCycle->update([
                'lock_expires_at'  => $expiresAt,
                'last_activity_at' => now(),
            ]);

            ActivityLogger::log(
                action: 'followup_cycle_lock_renewed',
                description: "Memperpanjang kunci tugas follow-up {$lockedCycle->institution->name} ({$lockedCycle->contact->name}) selama {$durationMinutes} menit",
                subject: $lockedCycle,
                after: [
                    'locked_by'       => $user->id,
                    'lock_expires_at' => $expiresAt->toIso8601String(),
                ],
                actor: $user
            );

            return $lockedCycle;
        });
    }

    /**
     * Lepaskan kunci tugas follow-up.
     */
    public function releaseLock(FollowUpCycle $cycle, User $user, bool $force = false): FollowUpCycle
    {
        if (!$user->isActive()) {
            throw new DomainException('Akun pengguna tidak aktif atau telah diblokir.');
        }

        return DB::transaction(function () use ($cycle, $user, $force) {
            /** @var FollowUpCycle $lockedCycle */
            $lockedCycle = FollowUpCycle::where('id', $cycle->id)->lockForUpdate()->first();

            if (!$lockedCycle) {
                throw new DomainException('Siklus follow-up tidak ditemukan.');
            }

            // Jika tidak sedang terkunci atau sudah kedaluwarsa, bersihkan kolom jika perlu
            if (!$lockedCycle->isLocked()) {
                if ($lockedCycle->locked_by !== null) {
                    $lockedCycle->update([
                        'locked_by'       => null,
                        'locked_at'       => null,
                        'lock_expires_at' => null,
                    ]);
                }
                return $lockedCycle;
            }

            // Jika force = true, hanya admin yang diizinkan
            if ($force && $user->role !== 'admin') {
                throw new DomainException('Hanya Administrator yang berwenang melepaskan paksa kunci tugas.');
            }

            // Jika dikunci staf lain, hanya admin dengan opsi force yang diizinkan
            if ($lockedCycle->isLockedByOther($user)) {
                if (!$force && $user->role !== 'admin') {
                    throw new DomainException('Anda tidak memiliki wewenang untuk melepaskan kunci tugas milik staf lain.');
                }
                if (!$force && $user->role === 'admin') {
                    throw new DomainException('Melepaskan kunci tugas milik staf lain memerlukan konfirmasi force release.');
                }
            }

            // Capture before snapshot sebelum fields dinullkan
            $prevOwner = User::withTrashed()->find($lockedCycle->locked_by);
            $beforeSnapshot = [
                'previous_locked_by'       => $lockedCycle->locked_by,
                'previous_locked_by_name'  => $prevOwner?->name ?? 'Unknown',
                'previous_locked_at'       => $lockedCycle->locked_at?->toIso8601String(),
                'previous_lock_expires_at' => $lockedCycle->lock_expires_at?->toIso8601String(),
            ];

            $lockedCycle->update([
                'locked_by'       => null,
                'locked_at'       => null,
                'lock_expires_at' => null,
            ]);

            $isForce = $force || ((int) $beforeSnapshot['previous_locked_by'] !== (int) $user->id);
            $action = $isForce ? 'followup_cycle_force_unlocked' : 'followup_cycle_unlocked';
            $desc = $isForce
                ? "Admin {$user->name} melepaskan paksa kunci tugas follow-up {$lockedCycle->institution->name} milik {$beforeSnapshot['previous_locked_by_name']}"
                : "Melepaskan kunci tugas follow-up {$lockedCycle->institution->name}";

            ActivityLogger::log(
                action: $action,
                description: $desc,
                subject: $lockedCycle,
                before: $beforeSnapshot,
                after: [
                    'locked_by'       => null,
                    'locked_at'       => null,
                    'lock_expires_at' => null,
                ],
                actor: $user
            );

            return $lockedCycle;
        });
    }

    /**
     * Catat percobaan follow-up (Attempt 1, 2, atau 3) dalam transaksi aman.
     */
    public function recordAttempt(FollowUpCycle $cycle, array $data, User $performer): FollowUpAttempt
    {
        if (!$performer->isActive()) {
            throw new DomainException('Akun pengguna tidak aktif atau telah diblokir.');
        }

        return DB::transaction(function () use ($cycle, $data, $performer) {
            /** @var FollowUpCycle $lockedCycle */
            $lockedCycle = FollowUpCycle::where('id', $cycle->id)->lockForUpdate()->first();

            if (!$lockedCycle) {
                throw new DomainException('Siklus follow-up tidak ditemukan.');
            }

            if ($lockedCycle->isClosed()) {
                throw new DomainException('Siklus follow-up ini sudah ditutup dan tidak dapat menerima percobaan baru.');
            }

            // Inactive contact or institution check
            if (!$lockedCycle->contact->is_active) {
                throw new DomainException('Kontak pembimbing ini sedang nonaktif sehingga percobaan follow-up baru tidak dapat dicatat.');
            }

            if (!$lockedCycle->institution->is_active) {
                throw new DomainException('Institusi ini sedang nonaktif sehingga percobaan follow-up baru tidak dapat dicatat.');
            }

            // Invariant: NO VALID LOCK = NO MUTATION (Termasuk Admin)
            if (!$lockedCycle->isLockedBy($performer)) {
                if ($lockedCycle->isLockedByOther($performer)) {
                    $holder = $lockedCycle->lockedByUser; // withTrashed
                    $holderName = $holder ? $holder->name : 'staf lain';
                    $expiresAt = $lockedCycle->lock_expires_at ? $lockedCycle->lock_expires_at->format('H:i') : '-';
                    throw new DomainException("Tugas follow-up ini sedang dikerjakan oleh {$holderName} hingga pukul {$expiresAt}.");
                }
                throw new DomainException('Anda wajib mengklaim kunci tugas follow-up sebelum mencatat percobaan kontak.');
            }

            $existingAttemptCount = $lockedCycle->attempts()->count();
            if ($existingAttemptCount >= 3 || $lockedCycle->current_attempt_count >= 3) {
                throw new DomainException('Siklus follow-up telah mencapai batas maksimal 3 kali percobaan.');
            }

            $attemptNumber = $existingAttemptCount + 1;
            if ($attemptNumber > 3) {
                throw new DomainException('Siklus follow-up telah mencapai batas maksimal 3 kali percobaan.');
            }

            $attempt = $lockedCycle->attempts()->create([
                'attempt_number'   => $attemptNumber,
                'performed_by'     => $performer->id,
                'followed_up_at'   => $data['followed_up_at'] ?? now(),
                'channel'          => $data['channel'] ?? 'whatsapp',
                'result'           => $data['result'],
                'response_text'    => $data['response_text'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'next_followup_at' => $data['next_followup_at'] ?? null,
            ]);

            $newStatus = match ($data['result']) {
                'responded' => FollowUpCycle::STATUS_WAITING_RESPONSE,
                'call_back' => FollowUpCycle::STATUS_WAITING_RESPONSE,
                default     => FollowUpCycle::STATUS_IN_PROGRESS,
            };

            // Update siklus dan pertahankan lock untuk actor yang sama
            $lockedCycle->update([
                'current_attempt_count' => $attemptNumber,
                'last_activity_at'      => now(),
                'started_at'            => $lockedCycle->started_at ?? now(),
                'status'                => $newStatus,
            ]);

            ActivityLogger::log(
                action: 'followup_attempt_recorded',
                description: "Mencatat Attempt ke-{$attemptNumber} follow-up untuk {$lockedCycle->contact->name} ({$lockedCycle->institution->name})",
                subject: $attempt,
                after: [
                    'attempt_number' => $attemptNumber,
                    'result'         => $attempt->result,
                    'channel'        => $attempt->channel,
                ],
                actor: $performer
            );

            // Enqueue outbound spreadsheet sync (Guardrail 2)
            try {
                app(\App\Services\SpreadsheetSyncEngineService::class)->queueSyncForFollowUpCycle($lockedCycle);
            } catch (\Throwable) {
                // Outbound sync queue failure must never abort follow-up operations
            }

            return $attempt;
        });
    }

    /**
     * Selesaikan siklus follow-up dengan keputusan / hasil akhir.
     */
    public function completeCycle(FollowUpCycle $cycle, string $finalResult, ?string $finalNotes, User $confirmer): FollowUpCycle
    {
        if (!$confirmer->isActive()) {
            throw new DomainException('Akun pengguna tidak aktif atau telah diblokir.');
        }

        return DB::transaction(function () use ($cycle, $finalResult, $finalNotes, $confirmer) {
            /** @var FollowUpCycle $lockedCycle */
            $lockedCycle = FollowUpCycle::where('id', $cycle->id)->lockForUpdate()->first();

            if (!$lockedCycle) {
                throw new DomainException('Siklus follow-up tidak ditemukan.');
            }

            if ($lockedCycle->isClosed()) {
                throw new DomainException('Siklus follow-up ini sudah dalam status selesai/ditutup.');
            }

            // Invariant: NO VALID LOCK = NO MUTATION (Termasuk Admin)
            if (!$lockedCycle->isLockedBy($confirmer)) {
                if ($lockedCycle->isLockedByOther($confirmer)) {
                    $holder = $lockedCycle->lockedByUser; // withTrashed
                    $holderName = $holder ? $holder->name : 'staf lain';
                    $expiresAt = $lockedCycle->lock_expires_at ? $lockedCycle->lock_expires_at->format('H:i') : '-';
                    throw new DomainException("Tugas follow-up ini sedang dikerjakan oleh {$holderName} hingga pukul {$expiresAt}. Hanya pemegang kunci aktif yang dapat menyelesaikan siklus.");
                }
                throw new DomainException('Anda wajib mengklaim kunci tugas follow-up sebelum menyelesaikan siklus.');
            }

            // call_back adalah NON-TERMINAL, dilarang menutup siklus (bahkan admin override tidak boleh)
            if ($finalResult === FollowUpCycle::RESULT_CALL_BACK) {
                throw new DomainException("Status 'call_back' (minta dihubungi kembali) merupakan status berjalan (non-terminal) dan tidak dapat digunakan untuk menyelesaikan siklus.");
            }

            // Validasi final result vocabulary
            $allowedResults = [
                FollowUpCycle::RESULT_WILLING,
                FollowUpCycle::RESULT_DECLINED,
                FollowUpCycle::RESULT_COOPERATION_STOPPED,
                FollowUpCycle::RESULT_NO_RESPONSE,
            ];

            if (!in_array($finalResult, $allowedResults, true)) {
                throw new DomainException("Hasil akhir follow-up '{$finalResult}' tidak valid.");
            }

            $attemptCount = $lockedCycle->attempts()->count();

            // Zero-attempt guardrail
            $isAdminOverride = false;
            if ($attemptCount === 0) {
                if ($confirmer->role !== 'admin') {
                    throw new DomainException('Siklus follow-up belum memiliki riwayat percobaan kontak dan tidak dapat diselesaikan oleh staf.');
                }

                if (empty(trim($finalNotes ?? ''))) {
                    throw new DomainException('Penyelesaian siklus tanpa percobaan kontak (admin override) memerlukan catatan/alasan yang jelas.');
                }

                $isAdminOverride = true;
            }

            // no_response hanya boleh jika sudah mencapai minimal 3 attempt
            if ($finalResult === FollowUpCycle::RESULT_NO_RESPONSE && $attemptCount < 3) {
                throw new DomainException("Hasil akhir 'no_response' (tanpa respons) hanya dapat ditutup setelah minimal 3 kali percobaan kontak dilakukan.");
            }

            $status = match ($finalResult) {
                FollowUpCycle::RESULT_WILLING             => FollowUpCycle::STATUS_COMPLETED,
                FollowUpCycle::RESULT_NO_RESPONSE         => FollowUpCycle::STATUS_CLOSED_NO_RESPONSE,
                FollowUpCycle::RESULT_DECLINED            => FollowUpCycle::STATUS_COMPLETED,
                FollowUpCycle::RESULT_COOPERATION_STOPPED => FollowUpCycle::STATUS_COMPLETED,
            };

            // Update siklus dan otomatis lepaskan lock
            $lockedCycle->update([
                'status'          => $status,
                'final_result'    => $finalResult,
                'final_notes'     => $finalNotes,
                'completed_at'    => now(),
                'confirmed_by'    => $confirmer->id,
                'confirmed_at'    => now(),
                'locked_by'       => null,
                'locked_at'       => null,
                'lock_expires_at' => null,
            ]);

            if ($isAdminOverride) {
                ActivityLogger::log(
                    action: 'followup_cycle_completed_admin_override',
                    description: "Admin menyelesaikan siklus follow-up tahun {$lockedCycle->cycle_year} untuk {$lockedCycle->institution->name} tanpa percobaan kontak (override: {$finalNotes})",
                    subject: $lockedCycle,
                    after: [
                        'status'         => $lockedCycle->status,
                        'final_result'   => $lockedCycle->final_result,
                        'final_notes'    => $lockedCycle->final_notes,
                        'admin_override' => true,
                    ],
                    actor: $confirmer
                );
            } else {
                ActivityLogger::log(
                    action: 'followup_cycle_completed',
                    description: "Menyelesaikan siklus follow-up tahun {$lockedCycle->cycle_year} untuk {$lockedCycle->institution->name} dengan hasil: {$lockedCycle->finalResultLabel()}",
                    subject: $lockedCycle,
                    after: [
                        'status'       => $lockedCycle->status,
                        'final_result' => $lockedCycle->final_result,
                        'final_notes'  => $lockedCycle->final_notes,
                    ],
                    actor: $confirmer
                );
            }

            // Enqueue outbound spreadsheet sync on cycle completion (Guardrail 2)
            try {
                app(\App\Services\SpreadsheetSyncEngineService::class)->queueSyncForFollowUpCycle($lockedCycle);
            } catch (\Throwable) {
                // Outbound sync queue failure must never abort follow-up operations
            }

            return $lockedCycle;
        });
    }
}
