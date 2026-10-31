<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SpreadsheetSchemaVersion extends Model
{
    use HasFactory;

    protected $table = 'spreadsheet_schema_versions';

    protected $fillable = [
        'public_uuid',
        'spreadsheet_connection_id',
        'year',
        'sheet_tab_name',
        'schema_version',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SpreadsheetSchemaVersion $version) {
            if (empty($version->public_uuid)) {
                $version->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }

    public function spreadsheetConnection(): BelongsTo
    {
        return $this->connection();
    }

    public function columnMappings(): HasMany
    {
        return $this->hasMany(SpreadsheetColumnMapping::class, 'spreadsheet_schema_version_id')->orderBy('column_order', 'asc');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
