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
        'brand_id',
        'display_name',
        'spreadsheet_url',
        'spreadsheet_id',
        'active_spreadsheet_id',
        'previous_spreadsheet_id',
        'target_generation',
        'apps_script_endpoint_url',
        'drive_folder_url',
        'drive_folder_id',
        'is_enabled',
        'connection_status',
        'last_tested_at',
        'last_successful_test_at',
        'last_error_summary',
        'created_by',
        'updated_by',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    protected $casts = [
        'is_enabled' => 'boolean',
        'target_generation' => 'integer',
        'last_tested_at' => 'datetime',
        'last_successful_test_at' => 'datetime',
    ];

    /**
     * Memeriksa apakah spreadsheet_id saat ini sudah aktif dan terotorisasi.
     */
    public function isTargetActive(): bool
    {
        return !empty($this->active_spreadsheet_id)
            && !empty($this->spreadsheet_id)
            && $this->active_spreadsheet_id === $this->spreadsheet_id;
    }

    /**
     * Memeriksa apakah spreadsheet_id telah diubah dari target yang aktif.
     */
    public function hasTargetChanged(): bool
    {
        return !empty($this->active_spreadsheet_id)
            && !empty($this->spreadsheet_id)
            && $this->active_spreadsheet_id !== $this->spreadsheet_id;
    }

    /**
     * Memeriksa apakah link folder Google Drive sudah terhubung dan valid.
     */
    public function isDriveConnected(): bool
    {
        return !empty($this->drive_folder_url) && !empty($this->drive_folder_id);
    }

    /**
     * Memeriksa apakah link Google Spreadsheet sudah terhubung dan valid.
     */
    public function isSpreadsheetConnected(): bool
    {
        return !empty($this->spreadsheet_url) && !empty($this->spreadsheet_id);
    }

    /**
     * Memeriksa apakah koneksi registrasi siap digunakan untuk operasi sinkronisasi data.
     */
    public function isRegistrationReady(): bool
    {
        return (bool) $this->is_enabled
            && $this->isSpreadsheetConnected()
            && $this->connection_status !== self::STATUS_NOT_CONFIGURED;
    }

    /**
     * Memeriksa apakah link penghubung (Spreadsheet atau Drive) belum terhubung ke database SertIF.
     */
    public function hasUnconnectedLinks(): bool
    {
        // Untuk koneksi registrasi, baik spreadsheet maupun drive folder wajib terhubung
        if ($this->connection_key === self::KEY_REGISTRATION) {
            return !$this->isSpreadsheetConnected() || !$this->isDriveConnected() || $this->connection_status !== self::STATUS_CONNECTED;
        }

        // Untuk koneksi lainnya, spreadsheet wajib terhubung dan status connected
        return !$this->isSpreadsheetConnected() || $this->connection_status !== self::STATUS_CONNECTED;
    }

    /**
     * Memeriksa apakah terdapat koneksi aktif yang link penghubungnya belum terhubung ke database SertIF.
     */
    public static function anyUnconnectedLinksExist(): bool
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('spreadsheet_connections')) {
                return false;
            }

            return static::where('is_enabled', true)
                ->get()
                ->contains(fn (SpreadsheetConnection $c) => $c->hasUnconnectedLinks());
        } catch (\Throwable) {
            return false;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (SpreadsheetConnection $connection) {
            if (empty($connection->public_uuid)) {
                $connection->public_uuid = (string) Str::uuid();
            }

            if (!empty($connection->spreadsheet_url) && empty($connection->spreadsheet_id)) {
                $connection->spreadsheet_id = self::extractSpreadsheetId($connection->spreadsheet_url);
            }

            if (!empty($connection->drive_folder_url) && empty($connection->drive_folder_id)) {
                $connection->drive_folder_id = self::extractDriveFolderId($connection->drive_folder_url);
            }
        });

        static::updating(function (SpreadsheetConnection $connection) {
            if ($connection->isDirty('spreadsheet_url') && !empty($connection->spreadsheet_url)) {
                $extracted = self::extractSpreadsheetId($connection->spreadsheet_url);
                if ($extracted) {
                    $connection->spreadsheet_id = $extracted;
                }
            }

            if ($connection->isDirty('drive_folder_url')) {
                $connection->drive_folder_id = !empty($connection->drive_folder_url)
                    ? self::extractDriveFolderId($connection->drive_folder_url)
                    : null;
            }
        });
    }

    /**
     * Pastikan kolom drive_folder_url dan drive_folder_id tersedia di tabel spreadsheet_connections.
     */
    public static function ensureDriveFolderColumns(): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('spreadsheet_connections')
                && !\Illuminate\Support\Facades\Schema::hasColumn('spreadsheet_connections', 'drive_folder_url')
            ) {
                \Illuminate\Support\Facades\Schema::table('spreadsheet_connections', function (\Illuminate\Database\Schema\Blueprint $table) {
                    if (!\Illuminate\Support\Facades\Schema::hasColumn('spreadsheet_connections', 'drive_folder_url')) {
                        $table->string('drive_folder_url', 500)->nullable()->after('apps_script_endpoint_url');
                    }
                    if (!\Illuminate\Support\Facades\Schema::hasColumn('spreadsheet_connections', 'drive_folder_id')) {
                        $table->string('drive_folder_id', 255)->nullable()->after('drive_folder_url');
                    }
                });
            }
        } catch (\Throwable) {
            // Abaikan jika gagal pada lingkungan read-only
        }
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

    /**
     * Ekstrak Folder ID dari URL Folder Google Drive atau ID langsung.
     */
    public static function extractDriveFolderId(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $trimmed = trim($url);

        $parsed = parse_url($trimmed);
        if (isset($parsed['host'])) {
            $host = strtolower($parsed['host']);
            if ($host !== 'drive.google.com') {
                return null;
            }

            $path = $parsed['path'] ?? '';
            if (preg_match('#/folders/([a-zA-Z0-9-_]{15,100})#', $path, $matches)) {
                return $matches[1];
            }

            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $queryParams);
                if (!empty($queryParams['id']) && preg_match('/^[a-zA-Z0-9-_]{15,100}$/', (string) $queryParams['id'])) {
                    return (string) $queryParams['id'];
                }
            }

            return null;
        }

        // Jika user langsung memasukkan ID Folder Google Drive valid (panjang 15-100 karakter)
        if (preg_match('/^[a-zA-Z0-9-_]{15,100}$/', $trimmed)) {
            return $trimmed;
        }

        return null;
    }

    public function schemaVersions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SpreadsheetSchemaVersion::class, 'spreadsheet_connection_id')->orderBy('year', 'desc');
    }

    public function tabProfiles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SpreadsheetTabProfile::class, 'spreadsheet_connection_id');
    }

    public function discoverySnapshots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SheetDiscoverySnapshot::class, 'spreadsheet_connection_id')->latest('scanned_at');
    }

    public function mappingProposals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SheetMappingProposal::class, 'spreadsheet_connection_id')->latest('id');
    }

    public function outboxEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IntegrationOutboxEvent::class, 'target_connection_id')->latest('id');
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
