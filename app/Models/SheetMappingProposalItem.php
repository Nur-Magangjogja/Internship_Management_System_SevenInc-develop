<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetMappingProposalItem extends Model
{
    use HasFactory;

    public const ACTION_LINK_EXISTING     = 'link_existing';
    public const ACTION_CREATE_DRAFT      = 'create_draft_field';
    public const ACTION_EXTERNAL_ONLY     = 'external_only';
    public const ACTION_IGNORE            = 'ignore';
    public const ACTION_SYSTEM_PROTECTED  = 'system_protected';
    public const ACTION_SYSTEM_DERIVED    = 'system_derived';
    public const ACTION_ATTACHMENT_ADAPTER = 'attachment_adapter';

    public const COLLISION_DUPLICATE_HEADER = 'duplicate_header';
    public const COLLISION_TARGET_FIELD    = 'target_field_collision';
    public const COLLISION_PROTOCOL        = 'protocol_collision';

    protected $table = 'sheet_mapping_proposal_items';

    protected $fillable = [
        'proposal_id',
        'discovered_column_id',
        'column_identity',
        'column_fingerprint',
        'col_letter',
        'physical_index',
        'observed_label',
        'action',
        'field_key',
        'suggested_label',
        'suggested_field_type',
        'confidence_score',
        'confidence_level',
        'match_reason',
        'has_collision',
        'collision_type',
        'collision_detail',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'physical_index'   => 'integer',
        'confidence_score' => 'float',
        'has_collision'    => 'boolean',
        'resolved_at'      => 'datetime',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(SheetMappingProposal::class, 'proposal_id');
    }

    public function discoveredColumn(): BelongsTo
    {
        return $this->belongsTo(SheetDiscoveredColumn::class, 'discovered_column_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
