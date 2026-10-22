<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SheetDiscoverySnapshot extends Model
{
    use HasFactory;

    protected $table = 'sheet_discovery_snapshots';

    protected $fillable = [
        'public_uuid',
        'spreadsheet_connection_id',
        'spreadsheet_id',
        'sheet_id',
        'tab_name',
        'header_row',
        'data_start_row',
        'schema_hash',
        'columns_count',
        'scan_metadata',
        'scanned_by',
        'scanned_at',
    ];

    protected $casts = [
        'sheet_id'       => 'integer',
        'header_row'     => 'integer',
        'data_start_row' => 'integer',
        'columns_count'  => 'integer',
        'scan_metadata'  => 'array',
        'scanned_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->public_uuid)) {
                $model->public_uuid = (string) Str::uuid();
            }
            if (empty($model->scanned_at)) {
                $model->scanned_at = now();
            }
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(SheetDiscoveredColumn::class, 'snapshot_id')->orderBy('physical_index');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(SheetMappingProposal::class, 'snapshot_id');
    }

    public function scannedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
