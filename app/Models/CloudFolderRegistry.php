<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CloudFolderRegistry extends Model
{
    use HasFactory;

    protected $table = 'cloud_folder_registry';

    protected $fillable = [
        'public_uuid',
        'canonical_root_id',
        'owner_type',
        'owner_id',
        'category',
        'folder_path',
        'drive_folder_id',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (CloudFolderRegistry $folder) {
            if (empty($folder->public_uuid)) {
                $folder->public_uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Cari atau daftarkan folder secara aman berdasarkan canonical root dan path.
     */
    public static function findOrRegisterFolder(
        string $canonicalRootId,
        string $folderPath,
        string $driveFolderId,
        ?string $ownerType = null,
        ?int $ownerId = null,
        string $category = 'GENERAL'
    ): self {
        $cleanPath = trim(str_replace('\\', '/', $folderPath), '/');

        return static::firstOrCreate(
            [
                'canonical_root_id' => $canonicalRootId,
                'folder_path'       => $cleanPath,
            ],
            [
                'public_uuid'     => (string) Str::uuid(),
                'owner_type'      => $ownerType,
                'owner_id'        => $ownerId,
                'category'        => $category,
                'drive_folder_id' => $driveFolderId,
                'is_verified'     => true,
            ]
        );
    }
}
