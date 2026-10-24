<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SpreadsheetSidecarSync extends Model
{
    use HasFactory;

    protected $table = 'spreadsheet_sidecar_syncs';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SYNCED     = 'synced';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_RETRYING   = 'retrying';
    public const STATUS_PAUSED_TARGET_REBIND = 'paused_target_rebind';

    protected $fillable = [
        'public_uuid',
        'spreadsheet_connection_id',
        'internship_registration_id',
        'external_sync_id',
        'main_sheet_tab',
        'extension_sheet_tab',
        'target_spreadsheet_id',
        'target_generation',
        'form_schema_version',
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
        });
    }

    public function spreadsheetConnection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id');
    }

    public function internshipRegistration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id');
    }

    public function isPending(): bool
    {
        return $this->sync_status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->sync_status === self::STATUS_PROCESSING;
    }

    public function isSynced(): bool
    {
        return $this->sync_status === self::STATUS_SYNCED;
    }

    public function isFailed(): bool
    {
        return $this->sync_status === self::STATUS_FAILED;
    }

    public function isPausedForTargetRebind(): bool
    {
        return $this->sync_status === self::STATUS_PAUSED_TARGET_REBIND;
    }

    public static function calculateBackoffSeconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 30,
            2 => 120,
            3 => 600,
            4 => 1800,
            default => 3600,
        };
    }
}
