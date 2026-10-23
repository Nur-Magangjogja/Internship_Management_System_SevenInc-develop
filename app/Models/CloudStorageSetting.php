<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CloudStorageSetting extends Model
{
    use HasFactory;

    public const STATUS_UNTESTED = 'untested';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';

    public const PROVIDER_APPS_SCRIPT_DRIVE = 'apps_script_drive';
    /** @deprecated Migrated to PROVIDER_APPS_SCRIPT_DRIVE in GAS-06 */
    public const PROVIDER_GOOGLE_DRIVE = 'google_drive';
    public const PROVIDER_LOCAL_FALLBACK = 'local_fallback';

    protected $table = 'cloud_storage_settings';

    protected $fillable = [
        'public_uuid',
        'name',
        'provider',
        'canonical_parent_folder_url',
        'canonical_parent_folder_id',
        'system_assets_folder_url',
        'system_assets_folder_id',
        'document_templates_folder_url',
        'document_templates_folder_id',
        'generated_docs_folder_url',
        'generated_docs_folder_id',
        'archives_folder_url',
        'archives_folder_id',
        'is_enabled',
        'is_verified',
        'verified_at',
        'validation_error',
        'connection_status',
        'last_tested_at',
        'last_successful_test_at',
        'last_error_summary',
        'last_synced_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled'               => 'boolean',
        'is_verified'              => 'boolean',
        'verified_at'              => 'datetime',
        'last_tested_at'           => 'datetime',
        'last_successful_test_at'  => 'datetime',
        'last_synced_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CloudStorageSetting $setting) {
            if (empty($setting->public_uuid)) {
                $setting->public_uuid = (string) Str::uuid();
            }
            $setting->extractAllFolderIds();
        });

        static::updating(function (CloudStorageSetting $setting) {
            $setting->extractAllFolderIds();
        });
    }

    /**
     * Ekstrak seluruh folder ID dari URL atau ID langsung sebelum disimpan.
     */
    public function extractAllFolderIds(): void
    {
        $fields = [
            'canonical_parent_folder_url'   => 'canonical_parent_folder_id',
            'system_assets_folder_url'      => 'system_assets_folder_id',
            'document_templates_folder_url' => 'document_templates_folder_id',
            'generated_docs_folder_url'     => 'generated_docs_folder_id',
            'archives_folder_url'           => 'archives_folder_id',
        ];

        foreach ($fields as $urlField => $idField) {
            if (!empty($this->$urlField)) {
                $extracted = self::extractDriveFolderId($this->$urlField);
                if ($extracted) {
                    $this->$idField = $extracted;
                }
            } elseif (empty($this->$urlField) && !empty($this->$idField)) {
                // Biarkan ID jika user menginputkan ID langsung
                $extracted = self::extractDriveFolderId($this->$idField);
                if ($extracted) {
                    $this->$idField = $extracted;
                }
            }
        }
    }

    /**
     * Dapatkan Folder ID kanonikal efektif untuk operasi Google Drive.
     * Aturan prioritas Addendum V4:
     * 1. Canonical parent folder ID dari UI settings jika terverifikasi/diisi
     * 2. Fallback bootstrap terverifikasi dari config/ENV services.google.drive_folder_id jika UI belum ada
     * 3. Jika terjadi perbedaan root eksplisit antara UI dan ENV, deteksi konflik (ROOT_CONFIG_CONFLICT)
     * 4. DILARANG KERAS fallback ke root Google Drive umum ('root' atau '/')
     */
    public function getEffectiveParentFolderId(): ?string
    {
        $uiRootId = $this->canonical_parent_folder_id ?: self::extractDriveFolderId($this->canonical_parent_folder_url);
        $envRootId = self::extractDriveFolderId(config('services.google_apps_script.root_folder_id') ?: config('services.google.drive_folder_id'));

        // Jika keduanya ada tapi berbeda, tandai konflik
        if (!empty($uiRootId) && !empty($envRootId) && $uiRootId !== $envRootId) {
            // UI canonical root yang tervalidasi tetap menjadi otoritas utama,
            // namun mencatat peringatan konflik
            return $uiRootId;
        }

        if (!empty($uiRootId)) {
            return $uiRootId;
        }

        if (!empty($envRootId)) {
            return $envRootId;
        }

        // Fallback ke folder registrasi yang sudah terkonfigurasi di Spreadsheet Connection
        try {
            $spreadsheetFolderId = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->value('drive_folder_id');
            if (!empty($spreadsheetFolderId)) {
                return $spreadsheetFolderId;
            }
        } catch (\Throwable) {
            // Ignore
        }

        // Tidak boleh fallback ke 'root' Drive umum
        return null;
    }

    /**
     * Dapatkan nama provider aktif dengan resolusi hierarkis.
     * Penanganan migrasi GAS-06: Jika konfigurasi database lama masih berisi 'google_drive',
     * alihkan secara transparan ke 'apps_script_drive' tanpa mengubah atau merusak data historis.
     */
    public function getActiveProvider(): string
    {
        $rawProvider = $this->provider ?: (config('services.cloud_storage.provider') ?: env('CLOUD_STORAGE_PROVIDER', self::PROVIDER_APPS_SCRIPT_DRIVE));

        if ($rawProvider === self::PROVIDER_GOOGLE_DRIVE || $rawProvider === 'google_drive') {
            return self::PROVIDER_APPS_SCRIPT_DRIVE;
        }

        return $rawProvider;
    }

    /**
     * Periksa apakah terjadi konflik konfigurasi root antara UI dan ENV.
     */
    public function hasRootConfigConflict(): bool
    {
        $uiRootId = $this->canonical_parent_folder_id ?: self::extractDriveFolderId($this->canonical_parent_folder_url);
        $envRootId = self::extractDriveFolderId(config('services.google_apps_script.root_folder_id') ?: config('services.google.drive_folder_id'));

        return (!empty($uiRootId) && !empty($envRootId) && $uiRootId !== $envRootId);
    }

    /**
     * Periksa apakah folder induk kanonikal berbeda dari folder registrasi spreadsheet.
     */
    public function hasSpreadsheetFolderMismatch(?SpreadsheetConnection $connection = null): bool
    {
        $conn = $connection ?: SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$conn || empty($conn->drive_folder_id) || empty($this->canonical_parent_folder_id)) {
            return false;
        }

        return $conn->drive_folder_id !== $this->canonical_parent_folder_id;
    }

    /**
     * Dapatkan konfigurasi penyimpanan cloud sistem yang aktif (canonical singleton).
     */
    public static function getEffectiveSettings(): self
    {
        $setting = static::first();
        if (!$setting) {
            $setting = static::create([
                'name'              => 'Penyimpanan Google Drive Sistem',
                'provider'          => self::PROVIDER_APPS_SCRIPT_DRIVE,
                'is_enabled'        => true,
                'connection_status' => self::STATUS_UNTESTED,
            ]);
        }
        return $setting;
    }

    /**
     * Ekstrak Folder ID dari URL Folder Google Drive atau ID langsung.
     */
    public static function extractDriveFolderId(?string $urlOrId): ?string
    {
        if (empty($urlOrId)) {
            return null;
        }

        $trimmed = trim($urlOrId);

        $parsed = parse_url($trimmed);
        if (isset($parsed['host'])) {
            $host = strtolower($parsed['host']);
            if (str_contains($host, 'drive.google.com')) {
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
            }
            return null;
        }

        // Jika user langsung memasukkan ID Folder Google Drive valid (panjang 15-100 karakter)
        if (preg_match('/^[a-zA-Z0-9-_]{15,100}$/', $trimmed)) {
            return $trimmed;
        }

        return null;
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
