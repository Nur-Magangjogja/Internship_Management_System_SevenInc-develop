<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SheetMappingProposal extends Model
{
    use HasFactory;

    public const STATUS_DRAFT              = 'draft';
    public const STATUS_APPROVED_TO_DRAFT  = 'approved_to_draft';
    public const STATUS_APPLIED            = 'applied';
    public const STATUS_REJECTED           = 'rejected';
    public const STATUS_CONFLICT           = 'conflict';

    protected $table = 'sheet_mapping_proposals';

    protected $fillable = [
        'public_uuid',
        'spreadsheet_connection_id',
        'snapshot_id',
        'expected_schema_hash',
        'year',
        'form_schema_id',
        'version',
        'status',
        'conflict_reason',
        'proposed_by',
        'approved_by',
        'approved_at',
        'applied_at',
        'notes',
    ];

    protected $casts = [
        'year'        => 'integer',
        'version'     => 'integer',
        'approved_at' => 'datetime',
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

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SheetDiscoverySnapshot::class, 'snapshot_id');
    }

    public function formSchema(): BelongsTo
    {
        return $this->belongsTo(FormSchema::class, 'form_schema_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SheetMappingProposalItem::class, 'proposal_id')->orderBy('physical_index');
    }

    public function proposedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED_TO_DRAFT;
    }
}
