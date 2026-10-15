<?php

namespace App\Jobs;

use App\Models\AbsenSyncState;
use App\Models\InternshipRegistration;
use App\Services\AbsenIntegration\AbsenOutboxRecorder;
use App\Services\AbsenIntegration\Transports\AbsenOutboundTransportInterface;
use App\Services\AbsenIntegration\Transports\DisabledAbsenOutboundTransport;
use App\Services\AbsenIntegration\Transports\FakeAbsenOutboundTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SyncRegistrationToAbsenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Jumlah percobaan Laravel Job dibatasi tepat 1.
     * Sumber kebenaran retry dikelola secara terpusat oleh kolom attempt_count
     * dan next_retry_at pada tabel absen_sync_states untuk mencegah penggandaan retry.
     */
    public int $tries = 1;

    /**
     * Timeout eksekusi job (detik).
     */
    public int $timeout = 30;

    public ?string $attemptToken = null;

    public function __construct(
        public int $registrationId,
        public ?string $workerId = null,
        ?string $attemptToken = null
    ) {
        $this->queue = 'absen_sync';
        $this->workerId = $workerId ?? ('worker_' . uniqid('', true));
        $this->attemptToken = $attemptToken ?? (string) Str::uuid();
    }

    /**
     * Eksekusi job pengiriman pendaftaran ke Absen Djuragan.
     */
    public function handle(?AbsenOutboundTransportInterface $transport = null): ?AbsenSyncState
    {
        // 1. Resolusi Transport (default ke DisabledAbsenOutboundTransport jika belum terikat)
        if ($transport === null) {
            $transport = app()->bound(AbsenOutboundTransportInterface::class)
                ? app(AbsenOutboundTransportInterface::class)
                : new DisabledAbsenOutboundTransport();
        }

        // 2. Feature Flag Safety: jika outbound_enabled false dan transport bukan fake/offline contract yang diinjeksi
        $isOutboundEnabled = (bool) config('services.absen_djuragan.outbound_enabled', false);
        if (!$isOutboundEnabled
            && !($transport instanceof FakeAbsenOutboundTransport)
            && !($transport instanceof \App\Services\AbsenIntegration\Transports\CrossAppOfflineContractTransport)
        ) {
            // Fail-closed secara hening: tidak ada request keluar
            return null;
        }

        // 3. Ambil baris outbox terkait
        $syncState = AbsenSyncState::where('registration_id', $this->registrationId)->first();
        if (!$syncState) {
            return null;
        }

        // 4. Klaim Lease Atomik dengan Fencing Token
        $fencingToken = $syncState->claimLeaseAtomic($this->workerId, 120, $this->attemptToken);
        if (!$fencingToken) {
            // Klaim ditolak (kalah race condition atau sedang tidak eligible)
            return null;
        }

        $syncState->refresh();

        // 5. Muat ulang entitas registrasi terbaru
        $registration = InternshipRegistration::find($this->registrationId);
        if (!$registration) {
            $syncState->update([
                'sync_status'        => AbsenSyncState::STATUS_CANCELLED,
                'fencing_token'      => null,
                'lease_owner'        => null,
                'lease_expires_at'   => null,
                'last_error_message' => 'Registrasi pendaftaran magang tidak ditemukan.',
            ]);
            return $syncState->fresh();
        }

        $recorder = app(AbsenOutboxRecorder::class);

        // 6. Pre-Flight Business Status Check (Pencegahan Pengiriman Non-Eligible)
        if (!$recorder->isEligibleStatus($registration->internship_status)) {
            if ($syncState->last_synced_at === null) {
                // Kasus 1: Belum pernah dikirim -> batalkan pengiriman secara permanen
                $syncState->update([
                    'sync_status'        => AbsenSyncState::STATUS_CANCELLED,
                    'fencing_token'      => null,
                    'lease_owner'        => null,
                    'lease_expires_at'   => null,
                    'last_error_message' => "Status pendaftaran telah berpindah ke non-eligible ('{$registration->internship_status}') sebelum HTTP dibuka. Pengiriman dibatalkan.",
                ]);
            } else {
                // Kasus 3: Sudah pernah disinkronkan -> tandai kebutuhan offboarding
                $syncState->update([
                    'sync_status'        => AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED,
                    'fencing_token'      => null,
                    'lease_owner'        => null,
                    'lease_expires_at'   => null,
                    'last_error_message' => "Registrasi telah tersinkronisasi sebelumnya, status lokal berpindah ke '{$registration->internship_status}'. Menunggu endpoint remote offboarding.",
                ]);
            }
            return $syncState->fresh();
        }

        // 7. Konsistensi Snapshot dan Checksum (Item E)
        $currentCanonical = $recorder->generateCanonicalPayload($registration, $registration->internship_status);
        $currentChecksum  = $recorder->calculateChecksum($currentCanonical);

        if ($currentChecksum !== $syncState->in_flight_payload_checksum) {
            // Checksum lokal aktual tidak cocok dengan in_flight_payload_checksum yang diklaim lease!
            // Cegah pengiriman data yang tidak sesuai versi lease.
            $syncState->update([
                'sync_status'        => AbsenSyncState::STATUS_PENDING,
                'fencing_token'      => null,
                'lease_owner'        => null,
                'lease_expires_at'   => null,
                'last_error_message' => 'Payload checksum mismatch antara data lokal aktual dan in-flight lease. Reset ke pending.',
            ]);
            return $syncState->fresh();
        }

        // 7a. Validasi Kelengkapan Field Wajib (Email Akun Pengguna yang Sah)
        if (empty($currentCanonical['email'])) {
            $syncState->update([
                'sync_status'        => AbsenSyncState::STATUS_FAILED,
                'fencing_token'      => null,
                'lease_owner'        => null,
                'lease_expires_at'   => null,
                'last_error_message' => 'Snapshot pendaftaran tidak memiliki email pengguna yang sah. Pengiriman ditahan.',
            ]);
            return $syncState->fresh();
        }

        // 7b. Validasi Ketersediaan Master Data Mapping
        $requireMapping = (bool) config('services.absen_djuragan.require_master_mapping', false)
            || ($transport instanceof \App\Services\AbsenIntegration\Transports\AbsenHttpOutboundTransport)
            || ($transport instanceof \App\Services\AbsenIntegration\Transports\CrossAppOfflineContractTransport);

        $instId = (int) ($currentCanonical['institution_id'] ?? 0);
        $divId  = (int) ($currentCanonical['division_id'] ?? 0);

        if ($requireMapping && ($instId > 0 || $divId > 0)) {
            $resolver = app(\App\Services\AbsenIntegration\AbsenMasterDataResolver::class);
            if (!$resolver->hasCompleteMapping($instId, $divId)) {
                $syncState->update([
                    'sync_status'        => AbsenSyncState::STATUS_FAILED,
                    'fencing_token'      => null,
                    'lease_owner'        => null,
                    'lease_expires_at'   => null,
                    'last_error_message' => "Master data mapping belum tersedia: institution_id [{$instId}] atau division_id [{$divId}] belum dipetakan ke target Absen Djuragan. Pengiriman ditahan.",
                ]);
                return $syncState->fresh();
            }
        }

        // 8. Bentuk Idempotency Key Deterministik
        $shortChecksum  = substr((string) $syncState->in_flight_payload_checksum, 0, 8);
        $idempotencyKey = "ims-reg-{$this->registrationId}-v{$syncState->in_flight_payload_version}-{$shortChecksum}";

        // 9. Eksekusi Transport
        $payloadToSend = ($transport instanceof \App\Services\AbsenIntegration\Transports\AbsenHttpOutboundTransport || $transport instanceof \App\Services\AbsenIntegration\Transports\CrossAppOfflineContractTransport)
            ? app(\App\Services\AbsenIntegration\AbsenCanonicalPayloadBuilder::class)->buildHttpRequestPayload($currentCanonical, (int) $syncState->in_flight_payload_version, $idempotencyKey)
            : $currentCanonical;

        $response = $transport->sendRegistration($payloadToSend, $idempotencyKey);

        // 10. Tangani Hasil Transport Sesuai Kategori
        $currentReg = InternshipRegistration::find($this->registrationId);
        $isCurrentEligible = $currentReg && $recorder->isEligibleStatus($currentReg->internship_status);

        if ($response->isSuccess()) {
            if (!$isCurrentEligible) {
                // Remote menerima payload, namun status bisnis lokal berpindah ke non-eligible (exited/rejected/completed)
                // saat request in-flight. Tandai remote_action_required, simpan bukti synced, jangan biarkan pending.
                AbsenSyncState::where('id', $syncState->id)
                    ->where('fencing_token', $fencingToken)
                    ->update([
                        'sync_status'                 => AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED,
                        'last_synced_payload_version' => $syncState->in_flight_payload_version,
                        'last_synced_checksum'        => $syncState->in_flight_payload_checksum,
                        'last_synced_at'              => now(),
                        'last_response_status'        => $response->getStatusCode(),
                        'last_error_message'          => "Pengiriman berhasil di remote ({$response->getStatusCode()}), namun status lokal berpindah ke '{$currentReg?->internship_status}'. Menunggu offboarding.",
                        'fencing_token'               => null,
                        'lease_owner'                 => null,
                        'lease_expires_at'            => null,
                        'next_retry_at'               => null,
                        'updated_at'                  => now(),
                    ]);
            } else {
                $remoteData = $response->getData() ?? [];
                $remoteAbsenUserId    = $remoteData['absen_user_id'] ?? null;
                $remoteInternId       = $remoteData['intern_id'] ?? null;
                $remotePayloadVersion = isset($remoteData['payload_version']) ? (int) $remoteData['payload_version'] : null;

                // Proteksi respons replay versi lama: jangan anggap versi in-flight selesai jika hanya versi lama diakui remote
                if ($remotePayloadVersion !== null && $remotePayloadVersion < (int) $syncState->in_flight_payload_version) {
                    AbsenSyncState::where('id', $syncState->id)
                        ->where('fencing_token', $fencingToken)
                        ->update([
                            'sync_status'                 => AbsenSyncState::STATUS_PENDING,
                            'confirmed_payload_version'   => $remotePayloadVersion,
                            'last_synced_payload_version' => $remotePayloadVersion,
                            'absen_user_id'               => $remoteAbsenUserId,
                            'intern_id'                   => $remoteInternId,
                            'last_synced_at'              => now(),
                            'last_response_status'        => $response->getStatusCode(),
                            'last_error_message'          => "Remote server mengembalikan replay konfirmasi untuk versi {$remotePayloadVersion}, bukan versi in-flight {$syncState->in_flight_payload_version}.",
                            'fencing_token'               => null,
                            'lease_owner'                 => null,
                            'lease_expires_at'            => null,
                            'updated_at'                  => now(),
                        ]);
                } else {
                    $syncState->recordSuccessAtomic(
                        $fencingToken,
                        $response->getStatusCode(),
                        $remoteAbsenUserId,
                        $remoteInternId,
                        $remotePayloadVersion ?? (int) $syncState->in_flight_payload_version
                    );
                }
            }
        } elseif ($response->isTimeout()) {
            if (!$isCurrentEligible) {
                // Timeout saat status lokal non-eligible: hasil remote tidak diketahui
                AbsenSyncState::where('id', $syncState->id)
                    ->where('fencing_token', $fencingToken)
                    ->update([
                        'sync_status'          => AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED,
                        'last_response_status' => 408,
                        'last_error_message'   => "HTTP Timeout pada status non-eligible ('{$currentReg?->internship_status}'). Status remote tidak diketahui, rekonsiliasi diperlukan.",
                        'fencing_token'        => null,
                        'lease_owner'          => null,
                        'lease_expires_at'     => null,
                        'next_retry_at'        => null,
                        'updated_at'           => now(),
                    ]);
            } else {
                // Network timeout: hasil remote tidak diketahui (retryable)
                $backoff = $this->calculateBackoff((int) $syncState->attempt_count);
                $syncState->recordFailureAtomic(
                    $fencingToken,
                    $response->getErrorMessage() ?? 'HTTP Timeout: status remote tidak diketahui.',
                    $response->getStatusCode(),
                    ['idempotency_key' => $idempotencyKey, 'transport_type' => $response->getType()],
                    $backoff
                );
            }
        } elseif ($response->isConflict()) {
            // HTTP 409 Conflict: resource conflict di remote
            $backoff = $this->calculateBackoff((int) $syncState->attempt_count);
            $syncState->recordFailureAtomic(
                $fencingToken,
                $response->getErrorMessage() ?? 'HTTP 409 Conflict dari remote server.',
                409,
                ['idempotency_key' => $idempotencyKey, 'remote_data' => $response->getData()],
                $backoff
            );
        } elseif ($response->isValidationError() || $response->isUnauthorized()) {
            // HTTP 422 / 401: Non-retryable permanent failure
            $syncState->update([
                'sync_status'          => AbsenSyncState::STATUS_DEAD_LETTER,
                'last_response_status' => $response->getStatusCode(),
                'last_error_message'   => $response->getErrorMessage() ?? 'Non-retryable error (validation/unauthorized)',
                'last_error_context'   => ['idempotency_key' => $idempotencyKey, 'response' => $response->getData()],
                'fencing_token'        => null,
                'lease_owner'          => null,
                'lease_expires_at'     => null,
                'next_retry_at'        => null,
            ]);
        } else {
            // Retryable Server Error (500, 502, 503)
            $backoff = $this->calculateBackoff((int) $syncState->attempt_count);
            $syncState->recordFailureAtomic(
                $fencingToken,
                $response->getErrorMessage() ?? 'HTTP Server Error',
                $response->getStatusCode(),
                ['idempotency_key' => $idempotencyKey],
                $backoff
            );
        }

        return $syncState->fresh();
    }

    /**
     * Callback penanganan kegagalan eksekusi antrean Laravel Queue.
     * Mengadopsi prinsip Audit Job Reconstruction (Phase C.3B.1.1):
     * 1. Jika attemptToken tidak tersedia (misal rekonstruksi tanpa token atau token null),
     *    JANGAN modifikasi state di database. Biarkan expired-lease recovery menanganinya.
     * 2. Jika attemptToken tersedia, lakukan conditional failure update HANYA jika fencing_token
     *    di database persis sama dengan attemptToken milik job ini.
     *    Jika worker lain telah mengambil alih lease (recovery) atau job gagal sebelum acquire lease,
     *    conditional query WHERE fencing_token = ? akan mengembalikan 0 affected rows.
     */
    public function failed(?\Throwable $exception = null): void
    {
        if (empty($this->attemptToken)) {
            return;
        }

        $syncState = AbsenSyncState::where('registration_id', $this->registrationId)->first();
        if ($syncState && $syncState->sync_status === AbsenSyncState::STATUS_SYNCING) {
            $errorMessage = $exception ? $exception->getMessage() : 'Queue job execution failed unexpectedly.';
            $syncState->recordFailureAtomic(
                $this->attemptToken,
                'Job failed in queue: ' . $errorMessage,
                500,
                ['failed_callback' => true, 'worker_id' => $this->workerId]
            );
        }
    }

    /**
     * Hitung interval backoff bertingkat (detik): 1m, 5m, 15m, 1h, 4h.
     */
    protected function calculateBackoff(int $attempts): int
    {
        $intervals = [60, 300, 900, 3600, 14400];
        $index = min(max(0, $attempts - 1), count($intervals) - 1);
        return $intervals[$index];
    }
}
