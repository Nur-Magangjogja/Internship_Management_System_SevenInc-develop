<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistrationAttachment extends Model
{
    use HasFactory;

    protected $table = 'registration_attachments';

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SYNCED     = 'synced';
    const STATUS_RETRYING   = 'retrying';
    const STATUS_FAILED     = 'failed';

    public const TYPE_CV                  = 'cv';
    public const TYPE_IDENTITY            = 'identity';
    public const TYPE_PORTFOLIO           = 'portfolio';
    public const TYPE_COVER_LETTER        = 'cover_letter';
    public const TYPE_PROPOSAL            = 'proposal';
    public const TYPE_SUPPORTING_DOCUMENT = 'supporting_document';
    public const TYPE_PROFILE_PHOTO       = 'profile_photo';
    public const TYPE_LEGACY_BUNDLE       = 'cv_ktp_portofolio';

    public static function getCategoryLabel(string $type): string
    {
        return match($type) {
            self::TYPE_CV                  => 'Curriculum Vitae (CV)',
            self::TYPE_IDENTITY            => 'KTM / KTP',
            self::TYPE_PORTFOLIO           => 'Portofolio',
            self::TYPE_COVER_LETTER        => 'Surat Pengantar',
            self::TYPE_PROPOSAL            => 'Proposal Magang',
            self::TYPE_SUPPORTING_DOCUMENT => 'Dokumen Pendukung',
            self::TYPE_PROFILE_PHOTO       => 'Foto Profil',
            self::TYPE_LEGACY_BUNDLE       => 'Berkas Gabungan CV/KTM/Portofolio (Legacy)',
            default                        => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    protected $fillable = [
        'internship_registration_id',
        'attachment_type',
        'category',
        'document_version',
        'file_path',
        'original_name',
        'file_size',
        'mime_type',
        'drive_file_id',
        'drive_web_view_link',
        'drive_sync_status',
        'drive_synced_at',
        'drive_error_message',
        'drive_locked_by',
        'drive_lock_expires_at',
        'drive_attempt_count',
        'drive_next_retry_at',
        'drive_file_checksum',
    ];

    protected $casts = [
        'drive_synced_at'       => 'datetime',
        'drive_lock_expires_at' => 'datetime',
        'drive_next_retry_at'   => 'datetime',
        'drive_attempt_count'   => 'integer',
    ];

    public function registration()
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id')->withTrashed();
    }

    /**
     * Accessor category yang mendukung fallback otomatis ke attachment_type jika kolom belum ada/kosong.
     */
    public function getCategoryAttribute($value): string
    {
        if (!empty($value)) {
            return $value;
        }

        return match ($this->attachment_type) {
            'cv'                     => self::TYPE_CV,
            'identity', 'ktm', 'ktp' => self::TYPE_IDENTITY,
            'portfolio'              => self::TYPE_PORTFOLIO,
            'cover_letter'           => self::TYPE_COVER_LETTER,
            'proposal'               => self::TYPE_PROPOSAL,
            'supporting_document'    => self::TYPE_SUPPORTING_DOCUMENT,
            'profile_photo'          => self::TYPE_PROFILE_PHOTO,
            'cv_ktp_portofolio'      => self::TYPE_LEGACY_BUNDLE,
            default                  => 'lampiran',
        };
    }

    /**
     * Cek apakah lampiran sudah tersinkronisasi secara sah ke Google Drive.
     */
    public function isSyncedToDrive(): bool
    {
        return $this->drive_sync_status === self::STATUS_SYNCED
            && !empty($this->drive_file_id)
            && !str_starts_with($this->drive_file_id, 'local_sim_')
            && $this->drive_file_id !== ('DRIVE-FILE-' . $this->id);
    }

    /**
     * Invalidate sync state jika file atau konten lampiran diperbarui.
     */
    public function invalidateDriveSync(string $reason = 'File content replaced'): void
    {
        $this->update([
            'drive_sync_status'     => self::STATUS_PENDING,
            'drive_file_id'         => null,
            'drive_web_view_link'   => null,
            'drive_synced_at'       => null,
            'drive_locked_by'       => null,
            'drive_lock_expires_at' => null,
            'drive_next_retry_at'   => null,
            'drive_error_message'   => $reason,
        ]);
    }
}
