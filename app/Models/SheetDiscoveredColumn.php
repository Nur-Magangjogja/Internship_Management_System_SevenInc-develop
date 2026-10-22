<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetDiscoveredColumn extends Model
{
    use HasFactory;

    protected $table = 'sheet_discovered_columns';

    protected $fillable = [
        'snapshot_id',
        'column_identity',
        'col_letter',
        'physical_index',
        'observed_label',
        'inferred_type',
        'has_formula',
        'sample_values',
        'is_duplicate_label',
        'is_system_reserved',
    ];

    protected $casts = [
        'physical_index'     => 'integer',
        'has_formula'        => 'boolean',
        'sample_values'      => 'array',
        'is_duplicate_label' => 'boolean',
        'is_system_reserved' => 'boolean',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SheetDiscoverySnapshot::class, 'snapshot_id');
    }
}
