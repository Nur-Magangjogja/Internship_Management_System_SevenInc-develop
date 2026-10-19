<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GeneratedDocument extends Model
{
    use HasFactory;

    public const TYPE_LOA            = 'loa';
    public const TYPE_SKL            = 'skl';
    public const TYPE_CERTIFICATE    = 'certificate';
    public const TYPE_ASSESSMENT     = 'assessment';
    public const TYPE_RECOMMENDATION = 'recommendation';
    public const TYPE_MOU            = 'mou';

    public const STATUS_DRAFT        = 'DRAFT';
    public const STATUS_GENERATED    = 'GENERATED';
    public const STATUS_SYNC_PENDING = 'SYNC_PENDING';
    public const STATUS_SYNCED       = 'SYNCED';
    public const STATUS_FAILED       = 'FAILED';

    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_GENERATED,
        self::STATUS_SYNC_PENDING,
        self::STATUS_SYNCED,
        self::STATUS_FAILED,
    ];

    protected $table = 'generated_documents';

    protected $fillable = [
        'public_uuid',
        'document_type',
        'document_number',
        'internship_registration_id',
        'institution_id',
        'template_type',
        'template_id',
        'template_version',
        'variable_snapshot',
        'asset_references',
        'file_path',
        'file_name',
        'file_size_bytes',
        'checksum_sha256',
        'drive_file_id',
        'drive_folder_id',
        'drive_web_view_link',
        'status',
        'idempotency_key',
        'retry_count',
        'sync_error_message',
        'status_history',
        'published_at',
        'synced_at',
        'generated_by',
    ];

    protected $casts = [
        'template_version'   => 'integer',
        'file_size_bytes'    => 'integer',
        'retry_count'        => 'integer',
        'variable_snapshot'  => 'array',
        'asset_references'   => 'array',
        'status_history'     => 'array',
        'published_at'       => 'datetime',
        'synced_at'          => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (GeneratedDocument $doc) {
            if (empty($doc->public_uuid)) {
                $doc->public_uuid = (string) Str::uuid();
            }
            if (empty($doc->status_history)) {
                $doc->status_history = [
                    [
                        'status'    => $doc->status ?? self::STATUS_DRAFT,
                        'timestamp' => now()->toIso8601String(),
                        'note'      => 'Inisialisasi dokumen baru',
                    ]
                ];
            }
        });
    }

    public function recordStatusChange(string $newStatus, ?string $note = null): void
    {
        $history = $this->status_history ?? [];
        $history[] = [
            'status'    => $newStatus,
            'timestamp' => now()->toIso8601String(),
            'note'      => $note,
        ];

        $this->update([
            'status'         => $newStatus,
            'status_history' => $history,
        ]);
    }

    public function internshipRegistration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
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
}
