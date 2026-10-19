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

    protected $table = 'cloud_storage_settings';

    protected $fillable = [
        'public_uuid',
        'name',
        'provider',
        'system_assets_folder_url',
        'system_assets_folder_id',
        'document_templates_folder_url',
        'document_templates_folder_id',
        'generated_docs_folder_url',
        'generated_docs_folder_id',
        'archives_folder_url',
        'archives_folder_id',
        'is_enabled',
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
            'system_assets_folder_url'     => 'system_assets_folder_id',
            'document_templates_folder_url' => 'document_templates_folder_id',
            'generated_docs_folder_url'    => 'generated_docs_folder_id',
            'archives_folder_url'          => 'archives_folder_id',
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
     * Dapatkan konfigurasi penyimpanan cloud sistem yang aktif (canonical singleton).
     */
    public static function getEffectiveSettings(): self
    {
        $setting = static::first();
        if (!$setting) {
            $setting = static::create([
                'name'              => 'Penyimpanan Google Drive Sistem',
                'provider'          => 'google_drive',
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
