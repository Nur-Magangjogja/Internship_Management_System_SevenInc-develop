<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccountActivation extends Model
{
    use HasFactory;

    protected $table = 'user_account_activations';

    protected $fillable = [
        'user_id',
        'activated_at',
        'last_notification_requested_at',
        'resend_count',
    ];

    protected $casts = [
        'activated_at'                   => 'datetime',
        'last_notification_requested_at' => 'datetime',
        'resend_count'                   => 'integer',
    ];

    /**
     * Relasi ke akun User pemilik aktivasi.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Memeriksa apakah akun telah diaktivasi.
     */
    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Memeriksa apakah aktivasi masih pending.
     */
    public function isPending(): bool
    {
        return $this->activated_at === null;
    }

    /**
     * Menandai akun sebagai telah diaktivasi secara persisten.
     */
    public function markAsActivated(): bool
    {
        $this->activated_at = now();
        return $this->save();
    }

    /**
     * Mencatat pengiriman / pengiriman ulang notifikasi aktivasi.
     */
    public function recordNotificationSent(): void
    {
        $this->last_notification_requested_at = now();
        $this->resend_count = ($this->resend_count ?? 0) + 1;
        $this->save();
    }

    /**
     * Throttle helper untuk pengiriman ulang notifikasi.
     */
    public function canResendNotification(int $throttleSeconds = 60): bool
    {
        if (!$this->last_notification_requested_at) {
            return true;
        }

        return $this->last_notification_requested_at->addSeconds($throttleSeconds)->isPast();
    }
}
