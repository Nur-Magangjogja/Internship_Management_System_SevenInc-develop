<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'webinar_id',
        'user_id',
        'proof_file',
        'proof_note',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'certificate_id',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(WebinarCertificate::class, 'certificate_id');
    }

    public function isPending(): bool  { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool { return $this->status === self::STATUS_REJECTED; }
}
