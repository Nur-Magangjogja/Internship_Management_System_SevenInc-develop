<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SpreadsheetConnection extends Model
{
    use HasFactory;

    public const KEY_REGISTRATION = 'registration';
    public const KEY_FOLLOWUP = 'followup';

    public const VALID_KEYS = [
        self::KEY_REGISTRATION,
        self::KEY_FOLLOWUP,
    ];

    public const STATUS_UNTESTED = 'untested';
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_PENDING_TRANSPORT = 'pending_transport';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';

    protected $table = 'spreadsheet_connections';

    protected $fillable = [
        'public_uuid',
        'connection_key',
        'display_name',
        'spreadsheet_url',
        'spreadsheet_id',
        'apps_script_endpoint_url',
        'is_enabled',
        'connection_status',
        'last_tested_at',
        'last_successful_test_at',
        'last_error_summary',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_successful_test_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SpreadsheetConnection $connection) {
            if (empty($connection->public_uuid)) {
                $connection->public_uuid = (string) Str::uuid();
            }

            if (!empty($connection->spreadsheet_url) && empty($connection->spreadsheet_id)) {
                $connection->spreadsheet_id = self::extractSpreadsheetId($connection->spreadsheet_url);
            }
        });

        static::updating(function (SpreadsheetConnection $connection) {
            if ($connection->isDirty('spreadsheet_url') && !empty($connection->spreadsheet_url)) {
                $extracted = self::extractSpreadsheetId($connection->spreadsheet_url);
                if ($extracted) {
                    $connection->spreadsheet_id = $extracted;
                }
            }
        });
    }

    /**
     * Ekstrak spreadsheet ID dari URL Google Sheets secara ketat.
     */
    public static function extractSpreadsheetId(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $trimmed = trim($url);

        // Jika berupa URL
        $parsed = parse_url($trimmed);
        if (isset($parsed['host'])) {
            $host = strtolower($parsed['host']);
            if ($host !== 'docs.google.com') {
                return null;
            }

            $path = $parsed['path'] ?? '';
            if (preg_match('#^/spreadsheets/d/([a-zA-Z0-9-_]{25,100})#', $path, $matches)) {
                return $matches[1];
            }

            return null;
        }

        // Jika user langsung memasukkan ID dokumen Google Spreadsheet valid (panjang 25-100 karakter)
        if (preg_match('/^[a-zA-Z0-9-_]{25,100}$/', $trimmed)) {
            return $trimmed;
        }

        return null;
    }

    public function schemaVersions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SpreadsheetSchemaVersion::class, 'spreadsheet_connection_id')->orderBy('year', 'desc');
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
