<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class SpreadsheetRowSync extends Model
{
    use HasFactory;

    protected $table = 'spreadsheet_row_syncs';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SYNCED     = 'synced';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_RETRYING   = 'retrying';
    public const STATUS_PAUSED_TARGET_REBIND = 'paused_target_rebind';

    protected $fillable = [
        'public_uuid',
        'spreadsheet_connection_id',
        'syncable_type',
        'syncable_id',
        'external_sync_id',
        'target_sheet_tab',
        'target_spreadsheet_id',
        'target_generation',
        'schema_version',
        'sync_status',
        'payload_checksum',
        'attempt_count',
        'max_attempts',
        'last_attempt_at',
        'last_synced_at',
        'next_retry_at',
        'locked_at',
        'locked_by',
        'error_message',
        'row_index',
    ];

    protected $casts = [
        'attempt_count'   => 'integer',
        'max_attempts'    => 'integer',
        'target_generation' => 'integer',
        'row_index'       => 'integer',
        'last_attempt_at' => 'datetime',
        'last_synced_at'  => 'datetime',
        'next_retry_at'   => 'datetime',
        'locked_at'       => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($sync) {
            if (empty($sync->public_uuid)) {
                $sync->public_uuid = (string) Str::uuid();
            }
            if (empty($sync->sync_status)) {
                $sync->sync_status = self::STATUS_PENDING;
            }
            if (empty($sync->max_attempts)) {
                $sync->max_attempts = 5;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function spreadsheetConnection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }

    public function connection(): BelongsTo
    {
        return $this->spreadsheetConnection();
    }

    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSynced(): bool
    {
        return $this->sync_status === self::STATUS_SYNCED;
    }

    public function isPending(): bool
    {
        return $this->sync_status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->sync_status === self::STATUS_PROCESSING;
    }

    public function isFailed(): bool
    {
        return $this->sync_status === self::STATUS_FAILED;
    }

    public function isRetrying(): bool
    {
        return $this->sync_status === self::STATUS_RETRYING;
    }

    public function isPausedForTargetRebind(): bool
    {
        return $this->sync_status === self::STATUS_PAUSED_TARGET_REBIND;
    }

    public function canRetry(): bool
    {
        return in_array($this->sync_status, [self::STATUS_FAILED, self::STATUS_RETRYING, self::STATUS_PENDING], true);
    }

    /**
     * Helper to compute exponential backoff delay in seconds with jitter.
     */
    public static function calculateBackoffSeconds(int $attempt): int
    {
        // Attempt 1: 60s (1 min), Attempt 2: 300s (5 min), Attempt 3: 900s (15 min), Attempt 4: 3600s (1 hr), Attempt 5: 14400s (4 hr)
        return match ($attempt) {
            1 => 60,
            2 => 300,
            3 => 900,
            4 => 3600,
            default => 14400,
        };
    }
}
