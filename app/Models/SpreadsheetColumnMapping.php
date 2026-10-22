<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SpreadsheetColumnMapping extends Model
{
    use HasFactory;

    protected $table = 'spreadsheet_column_mappings';

    protected $fillable = [
        'public_uuid',
        'column_identity',
        'spreadsheet_schema_version_id',
        'sheet_id',
        'column_order',
        'sheet_header',
        'target_entity',
        'target_field',
        'data_type',
        'direction',
        'transform_key',
        'is_required',
        'mapping_status',
    ];

    protected $casts = [
        'column_order' => 'integer',
        'is_required' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SpreadsheetColumnMapping $mapping) {
            if (empty($mapping->public_uuid)) {
                $mapping->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetSchemaVersion::class, 'spreadsheet_schema_version_id');
    }
}
