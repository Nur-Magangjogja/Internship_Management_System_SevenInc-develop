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
        $oldFollowup = $registration->followup_status;

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

        \Illuminate\Support\Facades\DB::transaction(function () use ($registration, $newStatus, $oldStatus, $oldFollowup, $isAdmin, $isStandard, $reason) {
            // S17.18C: Sinkronisasi otomatis followup_status berdasarkan siklus hidup resmi
            if ($newStatus === IR::STATUS_ACCEPTED) {
                // Status #1 not_followed_up hanya untuk pendaftaran yang resmi diterima admin
                if ($registration->followup_status === null) {
                    $registration->followup_status = IR::FOLLOWUP_NOT_FOLLOWED_UP;
                }
            } elseif ($newStatus === IR::STATUS_ACTIVE) {
                // accepted -> active: sesuaikan followup_status menjadi #4
                $registration->followup_status = IR::FOLLOWUP_INTERNSHIP_STARTED;
            } elseif ($newStatus === IR::STATUS_COMPLETED) {
                // active -> completed: sesuaikan followup_status menjadi #7
                $registration->followup_status = IR::FOLLOWUP_INTERNSHIP_COMPLETED;
            } elseif ($newStatus === IR::STATUS_EXITED) {
                if ($oldStatus === IR::STATUS_COMPLETED || $this->hasVerifiedCompletionHistory($registration)) {
                    // Prioritas 1: Pernah menyelesaikan magang: pertahankan fakta status #7
                    $registration->followup_status = $registration->followup_status ?? IR::FOLLOWUP_INTERNSHIP_COMPLETED;
                } elseif ($oldStatus === IR::STATUS_ACTIVE || $this->hasVerifiedActiveHistory($registration)) {
                    // Prioritas 2: Pernah mulai magang: pertahankan fakta historis status #4
                    $registration->followup_status = $registration->followup_status ?? IR::FOLLOWUP_INTERNSHIP_STARTED;
                } elseif ($oldStatus === IR::STATUS_ACCEPTED) {
                    // Prioritas 3: Diterima lalu batal sebelum mulai: sesuaikan followup_status menjadi #5
                    $registration->followup_status = IR::FOLLOWUP_INTERNSHIP_CANCELLED;
                }
            } elseif ($newStatus === IR::STATUS_REJECTED) {
                if ($oldStatus === IR::STATUS_COMPLETED || $this->hasVerifiedCompletionHistory($registration)) {
                    // Prioritas 1: Pernah selesai: pertahankan fakta #7
                    $registration->followup_status = $registration->followup_status ?? IR::FOLLOWUP_INTERNSHIP_COMPLETED;
                } elseif ($oldStatus === IR::STATUS_ACTIVE || $this->hasVerifiedActiveHistory($registration)) {
                    // Prioritas 2: Pernah aktif: pertahankan fakta #4, jangan beri label tidak jadi magang
                    $registration->followup_status = $registration->followup_status ?? IR::FOLLOWUP_INTERNSHIP_STARTED;
                } elseif ($oldStatus === IR::STATUS_ACCEPTED) {
                    // Prioritas 3: Diterima lalu ditolak/batal sebelum mulai: #5
                    $registration->followup_status = IR::FOLLOWUP_INTERNSHIP_CANCELLED;
                } else {
                    // Prioritas 4: Penolakan sebelum penerimaan tetap memiliki followup_status = NULL
                    $registration->followup_status = null;
                }
            } elseif (in_array($newStatus, [IR::STATUS_WAITING, IR::STATUS_PENDING, IR::STATUS_NEW], true)) {
                // Kembali ke status pra-penerimaan
                $registration->followup_status = null;
            }

            $registration->internship_status = $newStatus;
            $registration->invalidateConditionCache();
            $registration->save();

            // Catat ke ActivityLogger bila dijalankan oleh admin sebelum evaluasi sinkronisasi
            if ($isAdmin) {
                if (!$isStandard) {
                    ActivityLogger::log(
                        action: 'intern_registration_status_corrected',
                        description: "Admin melakukan koreksi status pendaftaran #{$registration->id} dari '{$oldStatus}' ke '{$newStatus}'. Alasan: {$reason}",
                        subject: $registration,
                        before: [
                            'internship_status' => $oldStatus,
                            'followup_status'   => $oldFollowup,
                        ],
                        after: [
                            'internship_status' => $newStatus,
                            'followup_status'   => $registration->followup_status,
                        ],
                        metadata: [
                            'registration_id' => $registration->id,
                            'user_id'         => $registration->user_id,
                            'old_status'      => $oldStatus,
                            'new_status'      => $newStatus,
                            'old_followup'    => $oldFollowup,
                            'new_followup'    => $registration->followup_status,
                            'reason'          => $reason,
                            'is_override'     => true,
                        ]
                    );
                } else {
                    ActivityLogger::log(
                        action: 'intern_registration_status_updated',
                        description: "Status pendaftaran #{$registration->id} diperbarui dari '{$oldStatus}' ke '{$newStatus}'.",
                        subject: $registration,
                        before: [
                            'internship_status' => $oldStatus,
                            'followup_status'   => $oldFollowup,
                        ],
                        after: [
                            'internship_status' => $newStatus,
                            'followup_status'   => $registration->followup_status,
                        ],
                        metadata: [
                            'registration_id' => $registration->id,
                            'user_id'         => $registration->user_id,
                            'old_status'      => $oldStatus,
                            'new_status'      => $newStatus,
                            'old_followup'    => $oldFollowup,
                            'new_followup'    => $registration->followup_status,
                            'is_override'     => false,
                        ]
                    );
                }
            }

            // Phase C.2C: Pencatatan transactional outbox untuk sinkronisasi Absen Djuragan
            app(\App\Services\AbsenIntegration\AbsenOutboxRecorder::class)
                ->recordTransition($registration, $newStatus);
        });

        // Enqueue outbound spreadsheet sync for eligible registrations
        try {
            app(SpreadsheetSyncEngineService::class)->queueSyncForRegistration($registration);
        } catch (\Throwable) {
            // Guardrail 2: Outbound spreadsheet sync queue failure must never abort business operations
        }

        // Enqueue outbound spreadsheet sync for follow-up if accepted
        if (in_array($newStatus, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE, IR::STATUS_COMPLETED], true)) {
            try {
                \App\Services\ContactMatchingService::ensureContactAndFollowUpCycle($registration);
            } catch (\Throwable $followUpErr) {
                \Illuminate\Support\Facades\Log::warning("Follow-up queue sync error on lifecycle transition #{$registration->id}: " . $followUpErr->getMessage());
            }
        }
    }

    /**
     * Dapatkan logs aktivitas untuk registrasi (mendukung batch preloaded logs untuk mencegah N+1).
     */
    public function getLogsForRegistration(IR $registration, ?\Illuminate\Support\Collection $preloadedLogs = null): \Illuminate\Support\Collection
    {
        if ($preloadedLogs !== null) {
            return $preloadedLogs;
        }

        return \App\Models\ActivityLog::where(function ($q) use ($registration) {
            $q->where(function ($sq) use ($registration) {
                $sq->whereIn('subject_type', [
                    get_class($registration),
                    'InternshipRegistration',
                    $registration->getMorphClass(),
                ])->where('subject_id', $registration->id);
            })->orWhere(function ($sq) use ($registration) {
                $sq->where('metadata->registration_id', $registration->id);
            });
        })
        ->whereIn('action', [
            'intern_registration_status_updated',
            'intern_registration_status_corrected',
            'admin_intern_registration_updated',
        ])
        ->get();
    }

    /**
     * Verifikasi bukti penerimaan resmi oleh Admin SertIF secara tepercaya (S17.17 / S17.18C).
     */
    public function hasVerifiedAdminAcceptance(IR $registration, ?\Illuminate\Support\Collection $preloadedLogs = null): bool
    {
        if ($registration->internship_status === IR::STATUS_ACCEPTED) {
            return true;
        }

        // Pemagang aktif yang berasal dari integrasi Absen Djuragan atau berstatus pemagang resmi (active / completed)
        if (str_contains((string) $registration->internship_reason, 'Absen')
            || (isset($registration->user) && $registration->user->role === 'pemagang' && in_array($registration->internship_status, [IR::STATUS_ACTIVE, IR::STATUS_COMPLETED], true))) {
            return true;
        }

        $logs = $this->getLogsForRegistration($registration, $preloadedLogs);

        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : (json_decode((string) $log->metadata, true) ?? []);
            $after = is_array($log->after_data) ? $log->after_data : (json_decode((string) $log->after_data, true) ?? []);

            $metaNew = $metadata['new_status'] ?? null;
            $afterStatus = $after['internship_status'] ?? null;

            if ($metaNew === IR::STATUS_ACCEPTED || $afterStatus === IR::STATUS_ACCEPTED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifikasi bukti historis penyelesaian magang (Case C).
     * S17.18C: Tidak menggunakan followup_status #7 atau dokumen SKL tanpa transisi resmi sebagai bukti tunggal.
     */
    public function hasVerifiedCompletionHistory(IR $registration, ?\Illuminate\Support\Collection $preloadedLogs = null): bool
    {
        if ($registration->internship_status === IR::STATUS_COMPLETED) {
            return true;
        }

        $logs = $this->getLogsForRegistration($registration, $preloadedLogs);

        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : (json_decode((string) $log->metadata, true) ?? []);
            $after = is_array($log->after_data) ? $log->after_data : (json_decode((string) $log->after_data, true) ?? []);

            $metaNew = $metadata['new_status'] ?? null;
            $afterStatus = $after['internship_status'] ?? null;

            if ($metaNew === IR::STATUS_COMPLETED || $afterStatus === IR::STATUS_COMPLETED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifikasi bukti historis pernah mulai magang / aktif (Case B).
     * S17.18C: Tidak menggunakan followup_status #4 atau jadwal start_date saja sebagai bukti tunggal.
     */
    public function hasVerifiedActiveHistory(IR $registration, ?\Illuminate\Support\Collection $preloadedLogs = null): bool
    {
        if ($registration->internship_status === IR::STATUS_ACTIVE) {
            return true;
        }

        $logs = $this->getLogsForRegistration($registration, $preloadedLogs);

        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : (json_decode((string) $log->metadata, true) ?? []);
            $after = is_array($log->after_data) ? $log->after_data : (json_decode((string) $log->after_data, true) ?? []);

            $metaNew = $metadata['new_status'] ?? null;
            $afterStatus = $after['internship_status'] ?? null;

            if ($metaNew === IR::STATUS_ACTIVE || $afterStatus === IR::STATUS_ACTIVE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifikasi bukti pembatalan pra-magang sebelum mulai (Case A).
     * Syarat positif: Diterima admin, tidak pernah aktif/selesai, dan ada log aksi pembatalan resmi.
     */
    public function hasVerifiedPreStartCancellation(IR $registration, ?\Illuminate\Support\Collection $preloadedLogs = null): bool
    {
        $logs = $this->getLogsForRegistration($registration, $preloadedLogs);

        if (!$this->hasVerifiedAdminAcceptance($registration, $logs)) {
            return false;
        }

        if ($this->hasVerifiedActiveHistory($registration, $logs) || $this->hasVerifiedCompletionHistory($registration, $logs)) {
            return false;
        }

        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : (json_decode((string) $log->metadata, true) ?? []);
            $before = is_array($log->before_data) ? $log->before_data : (json_decode((string) $log->before_data, true) ?? []);
            $after = is_array($log->after_data) ? $log->after_data : (json_decode((string) $log->after_data, true) ?? []);

            $oldStatus = $metadata['old_status'] ?? $before['internship_status'] ?? null;
            $newStatus = $metadata['new_status'] ?? $after['internship_status'] ?? null;

            if ($oldStatus === IR::STATUS_ACCEPTED && in_array($newStatus, [IR::STATUS_EXITED, IR::STATUS_REJECTED], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Single Canonical Resolver untuk Status Bisnis & Kondisi Pelaksanaan (S17.18C).
     *
     * 4-Tier Strict Evaluation Priority Order:
     * 1. Priority 1: Verified completion history -> Case C ('Sudah selesai magang')
     * 2. Priority 2: Verified active/start history -> Case B ('Keluar setelah mulai magang (belum selesai)')
     * 3. Priority 3: Verified pre-start cancellation -> Case A ('Tidak jadi magang')
     * 4. Priority 4: Insufficient evidence -> Fail-closed / Verification required (Case D)
     */
    public function resolveCurrentCondition(IR $registration, ?\Illuminate\Support\Collection $preloadedLogs = null): array
    {
        $status = $registration->internship_status;
        $followup = $registration->followup_status;
        $logs = $this->getLogsForRegistration($registration, $preloadedLogs);

        // 1. Status saat ini Active
        if ($status === IR::STATUS_ACTIVE) {
            return [
                'case'                        => 'ACTIVE',
                'condition_label'             => 'Magang Aktif',
                'execution_condition_ui'      => 'Magang Aktif',
                'spreadsheet_column_y_label'  => 'Sudah masuk magang',
                'followup_status'             => $followup ?? IR::FOLLOWUP_INTERNSHIP_STARTED,
                'is_verified'                 => true,
            ];
        }

        // 2. Status saat ini Accepted
        if ($status === IR::STATUS_ACCEPTED) {
            $fuLabel = $registration->followup_status_label ?? $registration->status_label;
            return [
                'case'                        => 'ACCEPTED',
                'condition_label'             => 'Diterima',
                'execution_condition_ui'      => 'Diterima',
                'spreadsheet_column_y_label'  => $fuLabel,
                'followup_status'             => $followup,
                'is_verified'                 => true,
            ];
        }

        // 3. Status pra-penerimaan (Waiting / Pending / New)
        if (in_array($status, [IR::STATUS_WAITING, IR::STATUS_PENDING, IR::STATUS_NEW, null, ''], true)) {
            $label = ($status === IR::STATUS_PENDING) ? 'Pending' : 'Menunggu Review';
            return [
                'case'                        => 'PRE_ACCEPTANCE',
                'condition_label'             => $label,
                'execution_condition_ui'      => $label,
                'spreadsheet_column_y_label'  => 'Menunggu',
                'followup_status'             => null,
                'is_verified'                 => false,
            ];
        }

        // 4. Closed / Final statuses: Evaluation using Operator's Strict 4-Tier Priorities

        // Prioritas 1: Verified Completion History (Case C)
        // Evaluasi pertama mencegah record lulus salah diklasifikasikan sebagai belum selesai (B) atau batal (A).
        if ($this->hasVerifiedCompletionHistory($registration, $logs)) {
            return [
                'case'                        => 'C',
                'condition_label'             => 'Sudah selesai magang',
                'execution_condition_ui'      => 'Sudah selesai magang',
                'spreadsheet_column_y_label'  => 'Sudah selesai magang',
                'followup_status'             => IR::FOLLOWUP_INTERNSHIP_COMPLETED,
                'is_verified'                 => true,
            ];
        }

        // Prioritas 2: Verified Active / Start History (Case B)
        // Pernah aktif dan saat ini exited / rejected -> Keluar setelah mulai magang (belum selesai)
        if ($this->hasVerifiedActiveHistory($registration, $logs)) {
            return [
                'case'                        => 'B',
                'condition_label'             => 'Keluar setelah mulai magang (belum selesai)',
                'execution_condition_ui'      => 'Keluar setelah mulai magang (belum selesai)',
                'spreadsheet_column_y_label'  => 'Keluar setelah mulai magang (belum selesai)',
                'followup_status'             => $followup ?? IR::FOLLOWUP_INTERNSHIP_STARTED,
                'is_verified'                 => true,
            ];
        }

        // Prioritas 3: Verified Pre-Start Cancellation (Case A)
        // Diterima admin, lalu dibatalkan secara resmi sebelum mulai (tanpa pernah aktif/selesai)
        if ($this->hasVerifiedPreStartCancellation($registration, $logs)) {
            return [
                'case'                        => 'A',
                'condition_label'             => 'Tidak jadi magang',
                'execution_condition_ui'      => 'Tidak jadi magang',
                'spreadsheet_column_y_label'  => 'Tidak jadi magang',
                'followup_status'             => IR::FOLLOWUP_INTERNSHIP_CANCELLED,
                'is_verified'                 => true,
            ];
        }

        // Prioritas 4: Insufficient Evidence -> Fail-closed / Verification Required
        // A. Jika status saat ini adalah rejected murni (sebelum pernah diterima)
        if ($status === IR::STATUS_REJECTED && !$this->hasVerifiedAdminAcceptance($registration, $logs)) {
            return [
                'case'                        => 'REJECTED_PRE',
                'condition_label'             => 'Ditolak',
                'execution_condition_ui'      => 'Ditolak',
                'spreadsheet_column_y_label'  => 'Ditolak',
                'followup_status'             => null,
                'is_verified'                 => true,
            ];
        }

        // B. Exited legacy atau rejected post-acceptance tanpa bukti yang cukup -> Case D
        return [
            'case'                        => 'D',
            'condition_label'             => 'Keluar — riwayat mulai perlu diverifikasi',
            'execution_condition_ui'      => 'Keluar — riwayat mulai perlu diverifikasi',
            'spreadsheet_column_y_label'  => 'Keluar — riwayat mulai perlu diverifikasi',
            'followup_status'             => $followup,
            'is_verified'                 => false,
        ];
    }

    /**
     * Validasi konsistensi status follow-up terhadap lifecycle magang (S17.18C).
     */
    public function validateFollowupStatusConsistency(IR $registration, ?string $followupStatus, ?string $targetInternshipStatus = null): void
    {
        if ($followupStatus === null) {
            return;
        }

        if (!array_key_exists($followupStatus, IR::FOLLOWUP_STATUS_LABELS)) {
            throw new InvalidArgumentException("Status follow-up '{$followupStatus}' tidak valid.");
        }

        $effectiveStatus = $targetInternshipStatus ?? $registration->internship_status;

        // Status #4 (internship_started) wajib konsisten dengan lifecycle active
        if ($followupStatus === IR::FOLLOWUP_INTERNSHIP_STARTED) {
            if ($effectiveStatus !== IR::STATUS_ACTIVE && !$this->hasVerifiedActiveHistory($registration)) {
                throw new InvalidArgumentException(
                    "Status follow-up 'Sudah masuk magang' hanya dapat digunakan jika status pendaftaran adalah 'Aktif' atau memiliki riwayat aktif resmi. Silakan lakukan transisi status magang ke 'Aktif' terlebih dahulu."
                );
            }
            return;
        }

        // Status #7 (internship_completed) wajib konsisten dengan lifecycle completed
        if ($followupStatus === IR::FOLLOWUP_INTERNSHIP_COMPLETED) {
            if ($effectiveStatus !== IR::STATUS_COMPLETED && !$this->hasVerifiedCompletionHistory($registration)) {
                throw new InvalidArgumentException(
                    "Status follow-up 'Sudah selesai magang' hanya dapat digunakan jika status pendaftaran adalah 'Selesai'. Silakan lakukan transisi status magang ke 'Selesai' terlebih dahulu."
                );
            }
            return;
        }

        // Status #5 (internship_cancelled) hanya melalui prosedur pembatalan resmi setelah penerimaan admin
        if ($followupStatus === IR::FOLLOWUP_INTERNSHIP_CANCELLED) {
            if (!in_array($effectiveStatus, [IR::STATUS_EXITED, IR::STATUS_REJECTED], true)) {
                throw new InvalidArgumentException(
                    "Status follow-up 'Tidak jadi magang' hanya berlaku untuk pendaftaran yang dibatalkan / keluar. Silakan lakukan proses pembatalan resmi melalui transisi status 'Keluar'/'Ditolak'."
                );
            }
            if ($this->hasVerifiedActiveHistory($registration) || $this->hasVerifiedCompletionHistory($registration)) {
                throw new InvalidArgumentException(
                    "Status follow-up 'Tidak jadi magang' tidak dapat diberikan kepada peserta yang memiliki riwayat pernah aktif atau selesai magang."
                );
            }
            return;
        }

        // Status #1, #2, #3, #6, #8 berlaku untuk pendaftaran yang resmi diterima (accepted)
        $acceptedFollowupStatuses = [
            IR::FOLLOWUP_NOT_FOLLOWED_UP,
            IR::FOLLOWUP_AWAITING_AGREEMENT,
            IR::FOLLOWUP_MOVED_TO_PARTICIPANTS,
            IR::FOLLOWUP_NOT_YET_ACC,
            IR::FOLLOWUP_AWAITING_DATES,
        ];

        if (in_array($followupStatus, $acceptedFollowupStatuses, true)) {
            if ($effectiveStatus !== IR::STATUS_ACCEPTED) {
                throw new InvalidArgumentException(
                    "Status follow-up ini hanya dapat diberikan kepada peserta yang telah dinyatakan 'Diterima' (accepted)."
                );
            }
        }
    }

    /**
     * Memperbarui status follow-up peserta oleh admin (S17.18C).
     */
    public function updateFollowupStatus(IR $registration, ?string $newFollowupStatus, User $actor, ?string $reason = null): void
    {
        if ($actor->role !== 'admin') {
            throw new \Illuminate\Auth\Access\AuthorizationException('Hanya admin yang berwenang memperbarui status follow-up.');
        }

        $this->validateFollowupStatusConsistency($registration, $newFollowupStatus);

        $oldFollowup = $registration->followup_status;
        if ($oldFollowup === $newFollowupStatus) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($registration, $newFollowupStatus, $oldFollowup, $actor, $reason) {
            $registration->followup_status = $newFollowupStatus;
            $registration->save();

            ActivityLogger::log(
                action: 'intern_followup_status_updated',
                description: "Admin {$actor->name} memperbarui status follow-up pendaftaran #{$registration->id} dari '{$oldFollowup}' ke '{$newFollowupStatus}'.",
                subject: $registration,
                before: ['followup_status' => $oldFollowup],
                after: ['followup_status' => $newFollowupStatus],
                metadata: [
                    'registration_id' => $registration->id,
                    'old_followup'    => $oldFollowup,
                    'new_followup'    => $newFollowupStatus,
                    'actor_id'        => $actor->id,
                    'reason'          => $reason,
                ]
            );
        });

        // Enqueue outbound spreadsheet sync for eligible registrations
        try {
            app(SpreadsheetSyncEngineService::class)->queueSyncForRegistration($registration);
        } catch (\Throwable) {
            // Guardrail 2: Outbound spreadsheet sync queue failure must never abort business operations
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
