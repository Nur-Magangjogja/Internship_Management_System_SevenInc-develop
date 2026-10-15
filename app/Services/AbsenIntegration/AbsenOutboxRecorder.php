<?php

namespace App\Services\AbsenIntegration;

use App\Models\AbsenSyncState;
use App\Models\InternshipRegistration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AbsenOutboxRecorder
{
    /**
     * Status registrasi magang yang memenuhi syarat pencatatan outbox.
     */
    public const ELIGIBLE_STATUSES = [
        InternshipRegistration::STATUS_ACCEPTED,
        InternshipRegistration::STATUS_ACTIVE,
    ];

    /**
     * Memeriksa apakah status baru memenuhi syarat untuk dicatat ke outbox.
     */
    public function isEligibleStatus(string $status): bool
    {
        return in_array($status, self::ELIGIBLE_STATUSES, true);
    }

    /**
     * Mencatat atau memperbarui outbox record secara atomik di dalam transaksi database.
     * Mengembalikan model AbsenSyncState jika dicatat, atau null jika flag nonaktif atau status tidak eligible.
     *
     * @throws RuntimeException jika outbox_enabled aktif tetapi skema tabel absen_sync_states belum siap.
     */
    public function recordTransition(InternshipRegistration $registration, string $newStatus): ?AbsenSyncState
    {
        // 1. Feature Flag: Jika nonaktif, keluar seketika tanpa query apapun
        if (!config('services.absen_djuragan.outbox_enabled', false)) {
            return null;
        }

        // 2. Schema Dependency Gate (Fail-Closed jika flag aktif tetapi skema belum dimigrasikan)
        if (!Schema::hasTable('absen_sync_states')) {
            throw new RuntimeException("Dependensi skema outbox 'absen_sync_states' belum tersedia pada database.");
        }

        // 3. Evaluasi kelayakan status bisnis
        if (!$this->isEligibleStatus($newStatus)) {
            return $this->handleNonEligibleTransition($registration, $newStatus);
        }

        // 4. Bangkitkan proyeksi kanonikal dan checksum SHA-256 deterministik
        $payload  = $this->generateCanonicalPayload($registration, $newStatus);
        $checksum = $this->calculateChecksum($payload);

        // 5. Atomic Upsert dengan row-level lock & duplicate-key race protection
        $state = AbsenSyncState::where('registration_id', $registration->id)
            ->lockForUpdate()
            ->first();

        if ($state === null) {
            try {
                $state = AbsenSyncState::create([
                    'registration_id'          => $registration->id,
                    'sync_status'              => AbsenSyncState::STATUS_PENDING,
                    'current_payload_version'  => 1,
                    'current_payload_checksum' => $checksum,
                    'payload_snapshot'         => $payload,
                    'attempt_count'            => 0,
                    'max_attempts'             => (int) config('services.absen_djuragan.max_retries', 5),
                ]);
            } catch (QueryException $e) {
                // Penanganan duplicate-key race: jika dua transaksi bersamaan mencoba INSERT,
                // transaksi kedua menangkap exception unique key lalu mengunci baris yang sudah ada.
                $state = AbsenSyncState::where('registration_id', $registration->id)
                    ->lockForUpdate()
                    ->first();

                if (!$state) {
                    throw $e;
                }
            }
        }

        // Jika baris baru saja dibuat pada percobaan ini, return state awal
        if ($state->wasRecentlyCreated) {
            return $state;
        }

        // 6. Latest-State Versioning: Hanya naikkan versi jika checksum data berubah
        if ($state->current_payload_checksum !== $checksum) {
            $state->bumpPayloadVersion($checksum, $payload);
        }

        return $state;
    }

    /**
     * Membangun representasi data kanonikal internal yang stabil dan ter-whitelist.
     * Tidak menyertakan kata sandi plaintext/hash, religion, token, maupun timestamp dinamis.
     * Menyertakan email akun pengguna yang sah sebagai identitas pengiriman terverifikasi.
     */
    public function generateCanonicalPayload(InternshipRegistration $registration, string $status): array
    {
        $user = $registration->relationLoaded('user')
            ? $registration->user
            : \App\Models\User::find($registration->user_id);

        $email = strtolower(trim((string) ($user?->email ?? '')));

        return [
            'birth_date'        => $this->formatDate($registration->born_date),
            'birth_place'       => trim((string) ($registration->birth_place ?? '')),
            'brand_id'          => (int) ($registration->brand_id ?? 0),
            'division_id'       => (int) ($registration->division_id ?? 0),
            'email'             => $email,
            'end_date'          => $this->formatDate($registration->end_date),
            'fullname'          => trim((string) $registration->fullname),
            'gender'            => trim((string) ($registration->gender ?? '')),
            'institution_id'    => (int) ($registration->institution_id ?? 0),
            'internship_status' => trim($status),
            'phone_number'      => trim((string) ($registration->phone_number ?? '')),
            'registration_id'   => (int) $registration->id,
            'start_date'        => $this->formatDate($registration->start_date),
            'student_id'        => trim((string) ($registration->student_id ?? '')),
            'user_id'           => (int) $registration->user_id,
        ];
    }

    /**
     * Alias kanonikal untuk generateCanonicalPayload.
     */
    public function buildCanonicalPayload(InternshipRegistration $registration, string $status): array
    {
        return $this->generateCanonicalPayload($registration, $status);
    }

    /**
     * Helper pemformat tanggal yang aman untuk instance DateTimeInterface maupun string.
     */
    protected function formatDate(mixed $date): string
    {
        if (empty($date)) {
            return '';
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }

    /**
     * Menghasilkan checksum SHA-256 heksadesimal deterministik dari payload kanonikal.
     */
    public function calculateChecksum(array $payload): string
    {
        ksort($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Menangani transisi status registrasi ke status non-eligible (rejected, waiting, exited, completed).
     * Membedakan 3 kondisi sesuai tata kelola C.3B.1:
     * 1. Belum pernah dikirim & not in-flight: batalkan secara atomik (sync_status = cancelled).
     * 2. Sedang in-flight: naikkan payload version agar saat worker kembali tidak menandai synced.
     * 3. Sudah pernah disinkronkan: tandai remote_action_required untuk offboarding.
     */
    public function handleNonEligibleTransition(InternshipRegistration $registration, string $newStatus): ?AbsenSyncState
    {
        $state = AbsenSyncState::where('registration_id', $registration->id)
            ->lockForUpdate()
            ->first();

        if (!$state) {
            return null;
        }

        // Kondisi 1: Belum pernah dikirim ke remote (last_synced_at is null)
        if ($state->last_synced_at === null) {
            if ($state->sync_status !== AbsenSyncState::STATUS_SYNCING) {
                // Not in-flight: batalkan outbox secara permanen
                $state->update([
                    'sync_status'        => AbsenSyncState::STATUS_CANCELLED,
                    'next_retry_at'      => null,
                    'fencing_token'      => null,
                    'lease_owner'        => null,
                    'lease_expires_at'   => null,
                    'last_error_message' => "Transisi bisnis ke status non-eligible: '{$newStatus}'. Pengiriman dibatalkan.",
                ]);
            } else {
                // In-flight: naikkan version agar pengembalian worker tidak menandai versi lama sebagai synced
                $state->update([
                    'current_payload_version' => $state->current_payload_version + 1,
                    'last_error_message'      => "Transisi bisnis ke '{$newStatus}' terjadi saat request in-flight.",
                ]);
            }
            return $state->fresh();
        }

        // Kondisi 2: Sudah pernah tersinkronisasi di remote sebelumnya
        $state->update([
            'sync_status'        => AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED,
            'next_retry_at'      => null,
            'last_error_message' => "Registrasi telah tersinkronisasi, status lokal berubah ke '{$newStatus}'. Menunggu spesifikasi endpoint remote offboarding.",
        ]);

        return $state->fresh();
    }
}
