<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AbsenSyncState extends Model
{
    use HasFactory;

    protected $table = 'absen_sync_states';

    public const STATUS_PENDING                = 'pending';
    public const STATUS_SYNCING                = 'syncing';
    public const STATUS_SYNCED                 = 'synced';
    public const STATUS_FAILED                 = 'failed';
    public const STATUS_DEAD_LETTER            = 'dead_letter';
    public const STATUS_CANCELLED              = 'cancelled';
    public const STATUS_REMOTE_ACTION_REQUIRED = 'remote_action_required';

    protected $fillable = [
        'registration_id',
        'sync_status',
        'current_payload_version',
        'current_payload_checksum',
        'in_flight_payload_version',
        'in_flight_payload_checksum',
        'lease_owner',
        'fencing_token',
        'lease_expires_at',
        'last_synced_payload_version',
        'last_synced_checksum',
        'last_synced_at',
        'attempt_count',
        'max_attempts',
        'last_attempted_at',
        'next_retry_at',
        'last_response_status',
        'last_error_message',
        'last_error_context',
        'payload_snapshot',
        'absen_user_id',
        'intern_id',
        'confirmed_payload_version',
    ];

    protected $attributes = [
        'sync_status'             => self::STATUS_PENDING,
        'current_payload_version' => 1,
        'attempt_count'           => 0,
        'max_attempts'            => 5,
    ];

    protected $casts = [
        'current_payload_version'     => 'integer',
        'in_flight_payload_version'   => 'integer',
        'last_synced_payload_version' => 'integer',
        'confirmed_payload_version'   => 'integer',
        'absen_user_id'               => 'integer',
        'intern_id'                   => 'integer',
        'payload_snapshot'            => 'array',
        'attempt_count'               => 'integer',
        'max_attempts'                => 'integer',
        'last_response_status'        => 'integer',
        'lease_expires_at'            => 'datetime',
        'last_synced_at'              => 'datetime',
        'last_attempted_at'           => 'datetime',
        'next_retry_at'               => 'datetime',
        'last_error_context'          => 'array',
    ];

    /**
     * Relasi ke pendaftaran magang IMS.
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'registration_id');
    }

    /**
     * Scope query untuk memilih outbox records yang eligible untuk diproses/diklaim worker:
     * - Memiliki payload checksum yang valid (tidak NULL).
     * - Batas attempt belum terlampaui (attempt_count < max_attempts).
     * - Status pending/failed yang jadwal retry-nya sudah tiba (atau baru dibuat / next_retry_at NULL).
     * - ATAU status syncing yang lease-nya sudah kedaluwarsa (crash recovery).
     */
    public function scopeEligibleForClaim($query)
    {
        $now = now();

        return $query->where(function ($q) use ($now) {
            $q->whereNotNull('current_payload_checksum')
              ->whereColumn('attempt_count', '<', 'max_attempts')
              ->where(function ($sub) use ($now) {
                  $sub->where(function ($pendingOrFailed) use ($now) {
                      $pendingOrFailed->whereIn('sync_status', [self::STATUS_PENDING, self::STATUS_FAILED])
                          ->where(function ($r) use ($now) {
                              $r->whereNull('next_retry_at')
                                ->orWhere('next_retry_at', '<=', $now);
                          });
                  })->orWhere(function ($expiredSyncing) use ($now) {
                      $expiredSyncing->where('sync_status', self::STATUS_SYNCING)
                          ->whereNotNull('lease_expires_at')
                          ->where('lease_expires_at', '<=', $now);
                  });
              });
        });
    }

    /**
     * Status checkers.
     */
    public function isPending(): bool
    {
        return $this->sync_status === self::STATUS_PENDING;
    }

    public function isSyncing(): bool
    {
        return $this->sync_status === self::STATUS_SYNCING;
    }

    public function isSynced(): bool
    {
        return $this->sync_status === self::STATUS_SYNCED;
    }

    public function isFailed(): bool
    {
        return $this->sync_status === self::STATUS_FAILED;
    }

    public function isDeadLetter(): bool
    {
        return $this->sync_status === self::STATUS_DEAD_LETTER;
    }

    public function isCancelled(): bool
    {
        return $this->sync_status === self::STATUS_CANCELLED;
    }

    public function isRemoteActionRequired(): bool
    {
        return $this->sync_status === self::STATUS_REMOTE_ACTION_REQUIRED;
    }

    public function isLeaseExpired(): bool
    {
        return $this->lease_expires_at !== null && $this->lease_expires_at->isPast();
    }

    public function hasExceededMaxAttempts(): bool
    {
        $max = $this->max_attempts ?? 5;
        return (int) $this->attempt_count >= $max;
    }

    /**
     * Evaluasi in-memory: apakah state ini eligible untuk diklaim.
     */
    public function canBeClaimed(): bool
    {
        if (empty($this->current_payload_checksum)) {
            return false;
        }

        if ($this->hasExceededMaxAttempts() || $this->isDeadLetter() || $this->isCancelled() || $this->isRemoteActionRequired()) {
            return false;
        }

        if ($this->isPending() || $this->isFailed()) {
            if ($this->next_retry_at !== null && $this->next_retry_at->isFuture()) {
                return false;
            }
            return true;
        }

        if ($this->isSyncing() && $this->isLeaseExpired()) {
            return true;
        }

        return false;
    }

    /**
     * Klaim lease in-memory (digunakan pada unit test POPO).
     */
    public function claimLease(string $workerId, int $leaseDurationSeconds = 60): ?string
    {
        if (!$this->canBeClaimed()) {
            return null;
        }

        $fencingToken = (string) Str::uuid();

        $this->sync_status                = self::STATUS_SYNCING;
        $this->lease_owner                = $workerId;
        $this->fencing_token              = $fencingToken;
        $this->lease_expires_at           = now()->addSeconds($leaseDurationSeconds);
        $this->in_flight_payload_version  = $this->current_payload_version;
        $this->in_flight_payload_checksum = $this->current_payload_checksum;
        $this->last_attempted_at          = now();
        $this->attempt_count              = $this->attempt_count + 1;

        return $fencingToken;
    }

    /**
     * Klaim lease atomik berbasis database (conditional SQL UPDATE).
     * Mencegah race condition antar-worker yang bersaing pada baris yang sama.
     * Mengembalikan fencing_token unik jika klaim berhasil, atau null jika klaim kalah/ditolak.
     */
    public function claimLeaseAtomic(string $workerId, int $leaseDurationSeconds = 60, ?string $fencingToken = null): ?string
    {
        $tokenToUse     = $fencingToken ?? (string) Str::uuid();
        $now            = now();
        $leaseExpiresAt = $now->copy()->addSeconds($leaseDurationSeconds);

        $affected = static::where('id', $this->id)
            ->whereNotNull('current_payload_checksum')
            ->whereColumn('attempt_count', '<', 'max_attempts')
            ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereIn('sync_status', [self::STATUS_PENDING, self::STATUS_FAILED])
                        ->where(function ($r) use ($now) {
                            $r->whereNull('next_retry_at')
                              ->orWhere('next_retry_at', '<=', $now);
                        });
                })->orWhere(function ($sub) use ($now) {
                    $sub->where('sync_status', self::STATUS_SYNCING)
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', $now);
                });
            })
            ->update([
                'sync_status'                => self::STATUS_SYNCING,
                'lease_owner'                => $workerId,
                'fencing_token'              => $tokenToUse,
                'lease_expires_at'           => $leaseExpiresAt,
                'in_flight_payload_version'  => DB::raw('current_payload_version'),
                'in_flight_payload_checksum' => DB::raw('current_payload_checksum'),
                'last_attempted_at'          => $now,
                'attempt_count'              => DB::raw('attempt_count + 1'),
                'updated_at'                 => $now,
            ]);

        if ($affected === 1) {
            $this->refresh();
            return $tokenToUse;
        }

        return null;
    }

    /**
     * Rekam sukses in-memory (digunakan pada unit test POPO).
     */
    public function recordSuccess(string $fencingToken, ?int $httpStatus = 200): bool
    {
        if ($this->fencing_token !== $fencingToken) {
            return false;
        }

        $syncedVersion  = $this->in_flight_payload_version ?? $this->current_payload_version;
        $syncedChecksum = $this->in_flight_payload_checksum ?? $this->current_payload_checksum;

        $this->last_synced_payload_version = $syncedVersion;
        $this->last_synced_checksum        = $syncedChecksum;
        $this->last_synced_at              = now();
        $this->last_response_status        = $httpStatus;
        $this->last_error_message          = null;
        $this->last_error_context          = null;

        $this->lease_owner      = null;
        $this->fencing_token    = null;
        $this->lease_expires_at = null;

        if ($this->current_payload_version > $syncedVersion) {
            $this->sync_status = self::STATUS_PENDING;
        } else {
            $this->sync_status = self::STATUS_SYNCED;
        }

        return true;
    }

    /**
     * Rekam sukses atomik berbasis database (conditional SQL UPDATE).
     * Jika data lokal bertambah versi selama worker mengirim data, status otomatis kembali ke 'pending'.
     */
    public function recordSuccessAtomic(
        string $fencingToken,
        ?int $httpStatus = 200,
        ?int $absenUserId = null,
        ?int $remoteInternId = null,
        ?int $confirmedPayloadVersion = null
    ): bool {
        $now = now();

        $updateData = [
            'sync_status'                 => DB::raw("CASE WHEN current_payload_version > in_flight_payload_version THEN '" . self::STATUS_PENDING . "' ELSE '" . self::STATUS_SYNCED . "' END"),
            'last_synced_payload_version' => DB::raw('in_flight_payload_version'),
            'last_synced_checksum'        => DB::raw('in_flight_payload_checksum'),
            'last_synced_at'              => $now,
            'last_response_status'        => $httpStatus,
            'last_error_message'          => null,
            'last_error_context'          => null,
            'fencing_token'               => null,
            'lease_owner'                 => null,
            'lease_expires_at'            => null,
            'updated_at'                  => $now,
        ];

        if ($absenUserId !== null) {
            $updateData['absen_user_id'] = $absenUserId;
        }

        if ($remoteInternId !== null) {
            $updateData['intern_id'] = $remoteInternId;
        }

        if ($confirmedPayloadVersion !== null) {
            $updateData['confirmed_payload_version'] = $confirmedPayloadVersion;
        }

        $affected = static::where('id', $this->id)
            ->where('sync_status', self::STATUS_SYNCING)
            ->where('fencing_token', $fencingToken)
            ->update($updateData);

        if ($affected === 1) {
            $this->refresh();
            return true;
        }

        return false;
    }

    /**
     * Rekam kegagalan in-memory (digunakan pada unit test POPO).
     */
    public function recordFailure(
        string $fencingToken,
        string $errorMessage,
        ?int $httpStatus = null,
        ?array $context = null,
        int $backoffSeconds = 60
    ): bool {
        if ($this->fencing_token !== $fencingToken) {
            return false;
        }

        $this->last_response_status = $httpStatus;
        $this->last_error_message   = Str::limit(strip_tags($errorMessage), 1000);
        $this->last_error_context   = $context;

        $this->lease_owner      = null;
        $this->fencing_token    = null;
        $this->lease_expires_at = null;

        if ($this->hasExceededMaxAttempts()) {
            $this->sync_status   = self::STATUS_DEAD_LETTER;
            $this->next_retry_at = null;
        } else {
            $this->sync_status   = self::STATUS_FAILED;
            $this->next_retry_at = now()->addSeconds($backoffSeconds);
        }

        return true;
    }

    /**
     * Rekam kegagalan atomik berbasis database (conditional SQL UPDATE).
     */
    public function recordFailureAtomic(
        string $fencingToken,
        string $errorMessage,
        ?int $httpStatus = null,
        ?array $context = null,
        int $backoffSeconds = 60
    ): bool {
        $now = now();
        $sanitizedMessage = Str::limit(strip_tags($errorMessage), 1000);
        $encodedContext   = $context !== null ? json_encode($context) : null;
        $retryAt          = $now->copy()->addSeconds($backoffSeconds)->toDateTimeString();

        $affected = static::where('id', $this->id)
            ->where('sync_status', self::STATUS_SYNCING)
            ->where('fencing_token', $fencingToken)
            ->update([
                'sync_status'          => DB::raw("CASE WHEN attempt_count >= max_attempts THEN '" . self::STATUS_DEAD_LETTER . "' ELSE '" . self::STATUS_FAILED . "' END"),
                'next_retry_at'        => DB::raw("CASE WHEN attempt_count >= max_attempts THEN NULL ELSE '{$retryAt}' END"),
                'last_response_status' => $httpStatus,
                'last_error_message'   => $sanitizedMessage,
                'last_error_context'   => $encodedContext,
                'fencing_token'        => null,
                'lease_owner'          => null,
                'lease_expires_at'     => null,
                'updated_at'           => $now,
            ]);

        if ($affected === 1) {
            $this->refresh();
            return true;
        }

        return false;
    }

    /**
     * Memperbarui versi payload lokal ketika registrasi magang mengalami modifikasi data.
     * Hanya menaikkan versi jika checksum data benar-benar berubah.
     */
    public function bumpPayloadVersion(string $newChecksum, ?array $newPayload = null): bool
    {
        if ($this->current_payload_checksum === $newChecksum) {
            return false;
        }

        $now = now();

        $updateData = [
            'current_payload_version'  => DB::raw('current_payload_version + 1'),
            'current_payload_checksum' => $newChecksum,
            'sync_status'              => DB::raw("CASE WHEN sync_status = '" . self::STATUS_SYNCING . "' AND (lease_expires_at IS NULL OR lease_expires_at > '" . $now->toDateTimeString() . "') THEN '" . self::STATUS_SYNCING . "' ELSE '" . self::STATUS_PENDING . "' END"),
            'updated_at'               => $now,
        ];

        if ($newPayload !== null) {
            $updateData['payload_snapshot'] = json_encode($newPayload);
        }

        if ($this->exists) {
            $affected = static::where('id', $this->id)->update($updateData);

            if ($affected === 1) {
                $this->refresh();
                return true;
            }
            return false;
        }

        // In-memory fallback
        $this->current_payload_version  = $this->current_payload_version + 1;
        $this->current_payload_checksum = $newChecksum;
        if ($newPayload !== null) {
            $this->payload_snapshot = $newPayload;
        }

        if (!$this->isSyncing() || $this->isLeaseExpired()) {
            $this->sync_status = self::STATUS_PENDING;
        }

        return true;
    }
}
