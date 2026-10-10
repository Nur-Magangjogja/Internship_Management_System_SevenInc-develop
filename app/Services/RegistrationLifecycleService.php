<?php

namespace App\Services;

use App\Models\InternshipRegistration as IR;
use App\Models\User;
use App\Services\ActivityLogger;
use InvalidArgumentException;

class RegistrationLifecycleService
{
    public const CLOSED_STATUSES = [
        IR::STATUS_COMPLETED,
        IR::STATUS_EXITED,
        IR::STATUS_REJECTED,
    ];

    public const OPEN_STATUSES = [
        IR::STATUS_WAITING,
        IR::STATUS_PENDING,
        IR::STATUS_ACCEPTED,
        IR::STATUS_ACTIVE,
    ];

    /**
     * Peta transisi status yang valid secara standar / normal.
     */
    public const ALLOWED_TRANSITIONS = [
        IR::STATUS_NEW       => [IR::STATUS_WAITING, IR::STATUS_REJECTED],
        IR::STATUS_WAITING   => [IR::STATUS_ACCEPTED, IR::STATUS_REJECTED, IR::STATUS_PENDING],
        IR::STATUS_PENDING   => [IR::STATUS_WAITING, IR::STATUS_ACCEPTED, IR::STATUS_REJECTED],
        IR::STATUS_ACCEPTED  => [IR::STATUS_ACTIVE, IR::STATUS_REJECTED, IR::STATUS_EXITED, IR::STATUS_WAITING],
        IR::STATUS_ACTIVE    => [IR::STATUS_COMPLETED, IR::STATUS_EXITED, IR::STATUS_ACCEPTED],
        IR::STATUS_COMPLETED => [], // Immutable secara normal
        IR::STATUS_EXITED    => [IR::STATUS_ACTIVE], // Re-open hanya melalui aksi khusus
        IR::STATUS_REJECTED  => [IR::STATUS_WAITING], // Appeal / re-review
    ];

    /**
     * Cek apakah transisi status merupakan alur standar (normal).
     */
    public function isStandardTransition(?string $fromStatus, string $toStatus): bool
    {
        if (empty($fromStatus) || $fromStatus === IR::STATUS_NEW) {
            return in_array($toStatus, [IR::STATUS_WAITING, IR::STATUS_PENDING], true);
        }

        if ($fromStatus === $toStatus) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
        return in_array($toStatus, $allowed, true);
    }

    /**
     * Periksa apakah transisi status diizinkan.
     */
    public function canTransition(?string $fromStatus, string $toStatus, bool $isAdmin = false, ?string $reason = null): bool
    {
        if ($this->isStandardTransition($fromStatus, $toStatus)) {
            return true;
        }

        // Jika bukan transisi standar atau menyentuh periode tertutup (completed/exited/rejected non-appeal)
        if ($isAdmin) {
            // Admin memerlukan alasan eksplisit untuk melakukan override / koreksi status
            return !empty(trim((string) $reason));
        }

        return false;
    }

    /**
     * Jalankan transisi status pada registrasi.
     */
    public function transition(IR $registration, string $newStatus, bool $isAdmin = false, ?string $reason = null): void
    {
        $oldStatus = $registration->internship_status;

        if ($oldStatus === $newStatus) {
            return;
        }

        $isStandard = $this->isStandardTransition($oldStatus, $newStatus);

        if (!$isStandard) {
            if (!$isAdmin) {
                throw new InvalidArgumentException("Transisi status dari '{$oldStatus}' ke '{$newStatus}' tidak diizinkan.");
            }

            if (empty(trim((string) $reason))) {
                throw new InvalidArgumentException("Koreksi status administratif untuk transisi tidak standar atau periode tertutup memerlukan alasan eksplisit.");
            }
        }

        $registration->internship_status = $newStatus;
        $registration->save();

        // Enqueue outbound spreadsheet sync for eligible registrations
        try {
            app(SpreadsheetSyncEngineService::class)->queueSyncForRegistration($registration);
        } catch (\Throwable) {
            // Guardrail 2: Outbound spreadsheet sync queue failure must never abort business operations
        }

        // Catat ke ActivityLogger bila dijalankan oleh admin
        if ($isAdmin) {
            if (!$isStandard) {
                ActivityLogger::log(
                    action: 'intern_registration_status_corrected',
                    description: "Admin melakukan koreksi status pendaftaran #{$registration->id} dari '{$oldStatus}' ke '{$newStatus}'. Alasan: {$reason}",
                    subject: $registration,
                    before: ['internship_status' => $oldStatus],
                    after: ['internship_status' => $newStatus],
                    metadata: [
                        'registration_id' => $registration->id,
                        'user_id'         => $registration->user_id,
                        'old_status'      => $oldStatus,
                        'new_status'      => $newStatus,
                        'reason'          => $reason,
                        'is_override'     => true,
                    ]
                );
            } else {
                ActivityLogger::log(
                    action: 'intern_registration_status_updated',
                    description: "Status pendaftaran #{$registration->id} diperbarui dari '{$oldStatus}' ke '{$newStatus}'.",
                    subject: $registration,
                    before: ['internship_status' => $oldStatus],
                    after: ['internship_status' => $newStatus],
                    metadata: [
                        'registration_id' => $registration->id,
                        'user_id'         => $registration->user_id,
                        'old_status'      => $oldStatus,
                        'new_status'      => $newStatus,
                        'is_override'     => false,
                    ]
                );
            }
        }
    }

    /**
     * Ambil pendaftaran yang sedang aktif/berjalan atau berstatus draf.
     * Mengembalikan null jika seluruh pendaftaran sudah berstatus tertutup (completed/rejected/exited).
     */
    public function getOpenOrDraftRegistration(User $user): ?IR
    {
        $latest = IR::where('user_id', $user->id)->latest('id')->first();

        if (!$latest) {
            return null;
        }

        // Jika pendaftaran statusnya masih terbuka/berjalan atau berstatus draf
        if (in_array($latest->internship_status, self::OPEN_STATUSES, true) || $latest->internship_status === IR::STATUS_NEW || $latest->is_draft) {
            return $latest;
        }

        return null;
    }

    /**
     * Periksa apakah pengguna memiliki setidaknya satu riwayat magang yang selesai (Alumni).
     */
    public function hasCompletedInternship(User $user): bool
    {
        return IR::where('user_id', $user->id)
            ->where('internship_status', IR::STATUS_COMPLETED)
            ->exists();
    }

    /**
     * Evaluasi apakah pengguna berhak mempertahankan role 'pemagang'.
     * Pengguna berhak menyandang role 'pemagang' jika memiliki pendaftaran aktif/accepted atau merupakan alumni.
     */
    public function shouldRetainPemagangRole(User $user): bool
    {
        if ($this->hasCompletedInternship($user)) {
            return true;
        }

        return IR::where('user_id', $user->id)
            ->whereIn('internship_status', [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE])
            ->exists();
    }

    /**
     * Resolusi registrasi untuk keperluan dokumen sesuai hierarki multi-periode.
     *
     * 1. Jika $specificId diberikan:
     *    - Wajib diverifikasi bahwa registrasi tersebut milik $user (mencegah tampering).
     * 2. Jika $specificId tidak diberikan:
     *    - Cek registrasi terbaru ($latest). Jika registrasi terbaru memiliki status yang memenuhi hak
     *      dokumen tersebut, gunakan registrasi tersebut.
     *    - Jika registrasi terbaru belum/tidak memenuhi (misal: pendaftaran baru status 'waiting' / 'rejected'),
     *      lakukan fallback ke registrasi selesai terbaru ($latestCompleted) milik pengguna.
     *    - Fallback terakhir: registrasi terbaru apa pun.
     */
    public function resolveRegistrationForDocuments(User $user, ?int $specificId = null, ?string $documentType = null): ?IR
    {
        $baseQuery = IR::where('user_id', $user->id);

        if ($specificId) {
            // Mencegah tampering antar user
            $existsOther = IR::where('id', $specificId)->where('user_id', '!=', $user->id)->exists();
            if ($existsOther) {
                abort(403, 'Anda tidak berhak mengakses pendaftaran milik pengguna lain.');
            }
            return (clone $baseQuery)->where('id', $specificId)->first();
        }

        $latest = (clone $baseQuery)->latest('id')->first();
        if (!$latest) {
            return null;
        }

        // Tentukan apakah $latest memenuhi syarat dokumen
        $isCompletionDoc = in_array($documentType, ['skl', 'certificate', 'membercard', 'assessment', 'rekomendasi'], true);

        if ($isCompletionDoc) {
            if ($latest->internship_status === IR::STATUS_COMPLETED) {
                return $latest;
            }
            // Fallback ke latest completed
            $completed = (clone $baseQuery)->where('internship_status', IR::STATUS_COMPLETED)->latest('id')->first();
            return $completed ?? $latest;
        }

        if ($documentType === 'loa') {
            if (in_array($latest->internship_status, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE, IR::STATUS_COMPLETED], true)) {
                return $latest;
            }
            // Fallback ke accepted/active/completed terdahulu jika ada
            $acceptedOrCompleted = (clone $baseQuery)
                ->whereIn('internship_status', [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE, IR::STATUS_COMPLETED])
                ->latest('id')
                ->first();
            return $acceptedOrCompleted ?? $latest;
        }

        // Default jika tipe dokumen tidak spesifik:
        // Utamakan completed jika latest bukan active/completed/accepted
        if (in_array($latest->internship_status, [IR::STATUS_COMPLETED, IR::STATUS_ACTIVE, IR::STATUS_ACCEPTED], true)) {
            return $latest;
        }

        $completed = (clone $baseQuery)->where('internship_status', IR::STATUS_COMPLETED)->latest('id')->first();
        return $completed ?? $latest;
    }
}
