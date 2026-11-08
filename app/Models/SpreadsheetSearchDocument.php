<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SpreadsheetSearchDocument extends Model
{
    use HasFactory;

    public const IDENTITY_STABLE      = 'stable';
    public const IDENTITY_PROVISIONAL = 'provisional';
    public const IDENTITY_AMBIGUOUS   = 'ambiguous';

    protected $table = 'spreadsheet_search_documents';

    protected $fillable = [
        'public_uuid',
        'brand_id',
        'spreadsheet_connection_id',
        'sheet_id',
        'tab_name',
        'table_identifier',
        'row_sync_id',
        'local_registration_id',
        'source_record_key',
        'source_row_number',
        'source_row_fingerprint',
        'identity_type',
        'mapping_version_id',
        'normalized_name',
        'normalized_email',
        'normalized_phone',
        'raw_phone',
        'searchable_text',
        'searchable_payload',
        'indexed_at',
        'source_updated_at',
        'index_generation',
    ];

    protected $casts = [
        'sheet_id'          => 'integer',
        'source_row_number' => 'integer',
        'index_generation'  => 'integer',
        'searchable_payload'=> 'array',
        'indexed_at'        => 'datetime',
        'source_updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SpreadsheetSearchDocument $doc) {
            if (empty($doc->public_uuid)) {
                $doc->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }

    public function rowSync(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetRowSync::class, 'row_sync_id');
    }

    public function localRegistration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'local_registration_id');
    }
}
