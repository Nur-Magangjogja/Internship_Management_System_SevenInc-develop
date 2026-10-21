<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CloudFileRegistry extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cloud_file_registry';

    public const CATEGORY_CV                     = 'cv';
    public const CATEGORY_IDENTITY               = 'identity';
    public const CATEGORY_PORTFOLIO              = 'portfolio';
    public const CATEGORY_RECOMMENDATION_LETTER  = 'recommendation_letter';
    public const CATEGORY_PROPOSAL               = 'proposal';
    public const CATEGORY_BRAND_LOGO             = 'brand_logo';
    public const CATEGORY_INSTITUTION_LOGO        = 'institution_logo';
    public const CATEGORY_BRAND_SIGNATURE        = 'brand_signature';
    public const CATEGORY_INSTITUTION_SIGNATURE  = 'institution_signature';
    public const CATEGORY_ISSUED_DOCUMENT        = 'issued_document';

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_VERIFIED  = 'verified';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_ARCHIVED  = 'archived';

    protected $fillable = [
        'public_uuid',
        'owner_type',
        'owner_id',
        'category',
        'logical_filename',
        'provider',
        'canonical_root_id',
        'drive_folder_id',
        'drive_file_id',
        'byte_size',
        'mime_type',
        'checksum_sha256',
        'provider_version',
        'is_current',
        'sync_status',
        'error_message',
        'generated_from_issuance_id',
        'created_by',
        'verified_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'is_current'       => 'boolean',
        'byte_size'        => 'integer',
        'verified_at'      => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CloudFileRegistry $file) {
            if (empty($file->public_uuid)) {
                $file->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isVerified(): bool
    {
        return $this->sync_status === self::STATUS_VERIFIED && !empty($this->drive_file_id);
    }
}
