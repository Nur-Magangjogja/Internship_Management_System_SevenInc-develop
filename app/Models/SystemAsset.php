<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SystemAsset extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_LOGO        = 'logo';
    public const CATEGORY_LETTERHEAD  = 'letterhead';
    public const CATEGORY_SIGNATURE   = 'signature';
    public const CATEGORY_STAMP       = 'stamp';
    public const CATEGORY_BACKGROUND  = 'background';
    public const CATEGORY_ATTACHMENT  = 'attachment';
    public const CATEGORY_OTHER       = 'other';

    public const VALID_CATEGORIES = [
        self::CATEGORY_LOGO,
        self::CATEGORY_LETTERHEAD,
        self::CATEGORY_SIGNATURE,
        self::CATEGORY_STAMP,
        self::CATEGORY_BACKGROUND,
        self::CATEGORY_ATTACHMENT,
        self::CATEGORY_OTHER,
    ];

    public const SCOPE_GLOBAL      = 'global';
    public const SCOPE_BRAND       = 'brand';
    public const SCOPE_INSTITUTION = 'institution';

    public const VALID_SCOPES = [
        self::SCOPE_GLOBAL,
        self::SCOPE_BRAND,
        self::SCOPE_INSTITUTION,
    ];

    public const SYNC_STATUS_PENDING = 'pending';
    public const SYNC_STATUS_SYNCED  = 'synced';
    public const SYNC_STATUS_FAILED  = 'failed';

    protected $table = 'system_assets';

    protected $fillable = [
        'public_uuid',
        'name',
        'asset_code',
        'category',
        'scope',
        'brand_id',
        'institution_id',
        'mime_type',
        'file_size_bytes',
        'checksum_sha256',
        'local_path',
        'drive_file_id',
        'drive_folder_id',
        'drive_web_view_link',
        'version',
        'is_active',
        'is_default',
        'sync_status',
        'drive_synced_at',
        'sync_error_message',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'version'         => 'integer',
        'is_active'       => 'boolean',
        'is_default'      => 'boolean',
        'drive_synced_at' => 'datetime',
        'archived_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SystemAsset $asset) {
            if (empty($asset->public_uuid)) {
                $asset->public_uuid = (string) Str::uuid();
            }
            if (empty($asset->asset_code)) {
                $asset->asset_code = static::generateDefaultCode($asset);
            }
        });
    }

    public static function generateDefaultCode(SystemAsset $asset): string
    {
        $prefix = match ($asset->category) {
            self::CATEGORY_LOGO        => 'asset.logo',
            self::CATEGORY_LETTERHEAD  => 'asset.letterhead',
            self::CATEGORY_SIGNATURE   => 'asset.signature',
            self::CATEGORY_STAMP       => 'asset.stamp',
            self::CATEGORY_BACKGROUND  => 'asset.background',
            default                    => 'asset.file',
        };

        $slug = Str::slug($asset->name, '_');
        return "{$prefix}.{$slug}";
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeForScope($query, string $scope, ?int $brandId = null, ?int $institutionId = null)
    {
        return $query->where('scope', $scope)
            ->when($scope === self::SCOPE_BRAND && $brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when($scope === self::SCOPE_INSTITUTION && $institutionId, fn ($q) => $q->where('institution_id', $institutionId));
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->file_size_bytes;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    public function getVariablePlaceholderAttribute(): string
    {
        return '{{' . ($this->asset_code ?: 'asset.' . $this->category) . '}}';
    }
}
