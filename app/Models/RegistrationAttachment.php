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
    ];

    protected $casts = [
        'drive_synced_at' => 'datetime',
    ];

    public function registration()
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id')->withTrashed();
    }
}
