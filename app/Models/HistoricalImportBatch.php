<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class HistoricalImportBatch extends Model
{
    use HasFactory;

    public const SOURCE_XLSX = 'xlsx';
    public const SOURCE_CSV = 'csv';
    public const SOURCE_GOOGLE_SHEETS = 'google_sheets_readonly';

    public const STATUS_STAGED = 'staged';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'historical_import_batches';

    protected $fillable = [
        'public_uuid',
        'source_type',
        'source_filename',
        'source_identifier',
        'source_tab_name',
        'source_file_hash',
        'total_rows_detected',
        'total_rows_imported',
        'total_rows_skipped',
        'total_rows_failed',
        'target_year',
        'column_mapping_config',
        'status',
        'committed_at',
        'created_by',
    ];

    protected $casts = [
        'column_mapping_config' => 'array',
        'committed_at'          => 'datetime',
        'target_year'           => 'integer',
        'total_rows_detected'   => 'integer',
        'total_rows_imported'   => 'integer',
        'total_rows_skipped'    => 'integer',
        'total_rows_failed'     => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (HistoricalImportBatch $batch) {
            if (empty($batch->public_uuid)) {
                $batch->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HistoricalImportRow::class, 'batch_id')->orderBy('row_index');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
