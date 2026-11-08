<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SpreadsheetSearchIndexRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $table = 'spreadsheet_search_index_runs';

    protected $fillable = [
        'public_uuid',
        'spreadsheet_connection_id',
        'sheet_id',
        'tab_name',
        'table_identifier',
        'mapping_version_id',
        'index_generation',
        'status',
        'total_rows',
        'processed_rows',
        'indexed_rows',
        'skipped_rows',
        'failed_rows',
        'current_page',
        'page_size',
        'started_at',
        'completed_at',
        'last_error',
    ];

    protected $casts = [
        'sheet_id'         => 'integer',
        'index_generation' => 'integer',
        'total_rows'       => 'integer',
        'processed_rows'   => 'integer',
        'indexed_rows'     => 'integer',
        'skipped_rows'     => 'integer',
        'failed_rows'      => 'integer',
        'current_page'     => 'integer',
        'page_size'        => 'integer',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SpreadsheetSearchIndexRun $run) {
            if (empty($run->public_uuid)) {
                $run->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }
}
