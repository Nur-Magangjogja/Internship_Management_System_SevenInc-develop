<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    use HasFactory;

    /**
     * Tabel ini bersifat append-only, tidak memiliki updated_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'public_uuid',
        'actor_user_id',
        'actor_name_snapshot',
        'actor_email_snapshot',
        'actor_role_snapshot',
        'action',
        'subject_type',
        'subject_id',
        'subject_public_ref',
        'description',
        'before_data',
        'after_data',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data'  => 'array',
        'metadata'    => 'array',
        'created_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log) {
            if (empty($log->public_uuid)) {
                $log->public_uuid = (string) Str::uuid();
            }
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    /**
     * Gunakan public_uuid sebagai route key untuk mencegah paparan ID database ke publik/URL.
     */
    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /**
     * Relasi ke user actor (nullable, karena user bisa saja di-soft-delete).
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
