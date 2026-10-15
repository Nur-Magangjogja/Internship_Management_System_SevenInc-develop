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

    protected $fillable = [
        'internship_registration_id',
        'attachment_type',
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
