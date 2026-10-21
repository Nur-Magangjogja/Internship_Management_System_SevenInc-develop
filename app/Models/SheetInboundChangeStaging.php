<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SheetInboundChangeStaging extends Model
{
    use HasFactory;

    // Field Classifications
    public const CLASSIFICATION_SYSTEM_PROTECTED   = 'system_protected';
    public const CLASSIFICATION_CORE_ADMINISTRATIVE = 'core_administrative';
    public const CLASSIFICATION_CUSTOM_ANSWER       = 'custom_answer';
    public const CLASSIFICATION_EXTERNAL_ONLY       = 'external_only';
    public const CLASSIFICATION_UNMAPPED            = 'unmapped';

    // Staging Lifecycle Statuses
    public const STATUS_STAGED   = 'staged';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED  = 'applied';
    public const STATUS_IGNORED  = 'ignored';

    // Conflict Statuses
    public const CONFLICT_NONE                    = 'none';
    public const CONFLICT_CLEAN_INBOUND           = 'clean_inbound';
    public const CONFLICT_DIVERGENT               = 'conflict_divergent';
    public const CONFLICT_CORE_REQUIRES_APPROVAL  = 'conflict_core_field_requires_approval';
    public const CONFLICT_REJECTED_PROTECTED      = 'rejected_protected_field';
    public const CONFLICT_REJECTED_VALIDATION     = 'rejected_validation';
    public const CONFLICT_DUPLICATE_ROW_ID        = 'conflict_duplicate_row_id';

    // Resolution Policies
    public const POLICY_PENDING        = 'pending';
    public const POLICY_ACCEPT_REMOTE  = 'accept_remote';
    public const POLICY_KEEP_LOCAL     = 'keep_local';
    public const POLICY_ADMIN_OVERRIDE = 'admin_override';

    protected $table = 'sheet_inbound_change_staging';

    protected $fillable = [
        'public_uuid',
        'spreadsheet_connection_id',
        'tab_name',
        'sheet_id',
        'row_index',
        'sync_id',
        'registration_id',
        'column_identity',
        'header_name',
        'field_key',
        'field_classification',
        'raw_remote_value',
        'normalized_remote_value',
        'current_db_value',
        'old_checksum',
        'new_checksum',
        'source_revision',
        'conflict_status',
        'conflict_reason',
        'staging_status',
        'resolution_policy',
        'is_formula',
        'reviewed_by',
        'reviewed_at',
        'applied_at',
        'error_message',
    ];

    protected $casts = [
        'sheet_id'    => 'integer',
        'row_index'   => 'integer',
        'is_formula'  => 'boolean',
        'reviewed_at' => 'datetime',
        'applied_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->public_uuid)) {
                $model->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'registration_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isProtected(): bool
    {
        return $this->field_classification === self::CLASSIFICATION_SYSTEM_PROTECTED;
    }

    public function isCleanInbound(): bool
    {
        return $this->conflict_status === self::CONFLICT_CLEAN_INBOUND;
    }

    public function isPendingReview(): bool
    {
        return $this->staging_status === self::STATUS_STAGED;
    }
}
