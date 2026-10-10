<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FollowUpCycle extends Model
{
    use HasFactory;

    protected $table = 'followup_cycles';

    // Statuses
    public const STATUS_OPEN                = 'open';
    public const STATUS_IN_PROGRESS          = 'in_progress';
    public const STATUS_WAITING_RESPONSE    = 'waiting_response';
    public const STATUS_COMPLETED           = 'completed';
    public const STATUS_CLOSED_NO_RESPONSE  = 'closed_no_response';
    public const STATUS_CANCELLED           = 'cancelled';

    // Final Results
    public const RESULT_WILLING             = 'willing';
    public const RESULT_NO_RESPONSE         = 'no_response';
    public const RESULT_DECLINED            = 'declined';
    public const RESULT_CALL_BACK           = 'call_back';
    public const RESULT_COOPERATION_STOPPED = 'cooperation_stopped';

    protected $fillable = [
        'public_uuid',
        'institution_id',
        'institution_contact_id',
        'cycle_year',
        'status',
        'final_result',
        'final_notes',
        'current_attempt_count',
        'started_at',
        'completed_at',
        'confirmed_by',
        'confirmed_at',
        'locked_by',
        'locked_at',
        'lock_expires_at',
        'last_activity_at',
    ];

    protected $casts = [
        'cycle_year'            => 'integer',
        'current_attempt_count' => 'integer',
        'started_at'            => 'datetime',
        'completed_at'          => 'datetime',
        'confirmed_at'          => 'datetime',
        'locked_at'             => 'datetime',
        'lock_expires_at'       => 'datetime',
        'last_activity_at'      => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($cycle) {
            if (empty($cycle->public_uuid)) {
                $cycle->public_uuid = (string) Str::uuid();
            }
            if (empty($cycle->cycle_year)) {
                $cycle->cycle_year = (int) date('Y');
            }
            if (empty($cycle->status)) {
                $cycle->status = self::STATUS_OPEN;
            }
            if (!empty($cycle->institution_contact_id) && empty($cycle->institution_id)) {
                $contact = InstitutionContact::find($cycle->institution_contact_id);
                if ($contact) {
                    $cycle->institution_id = $contact->institution_id;
                }
            }
        });

        static::saving(function ($cycle) {
            if (!empty($cycle->institution_contact_id)) {
                $contact = $cycle->contact ?? InstitutionContact::find($cycle->institution_contact_id);
                if ($contact && $cycle->institution_id !== $contact->institution_id) {
                    $cycle->institution_id = $contact->institution_id;
                }
            }
        });
    }

    /**
     * Menggunakan public_uuid untuk model binding di rute, mencegah ID auto-increment terekspos.
     */
    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('public_uuid', $value)
            ->orWhere('id', $value)
            ->firstOrFail();
    }

    /**
     * Scope untuk mengambil siklus yang masih terbuka/aktif.
     */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CLOSED_NO_RESPONSE,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Scope untuk tahun tertentu.
     */
    public function scopeForYear($query, int $year)
    {
        return $query->where('cycle_year', $year);
    }

    /**
     * Relasi ke institusi.
     */
    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    /**
     * Relasi ke kontak pembimbing/PIC.
     */
    public function contact()
    {
        return $this->belongsTo(InstitutionContact::class, 'institution_contact_id');
    }

    /**
     * Relasi ke daftar percobaan follow-up.
     */
    public function attempts()
    {
        return $this->hasMany(FollowUpAttempt::class, 'followup_cycle_id')->orderBy('attempt_number');
    }

    /**
     * User yang mengonfirmasi penyelesaian siklus.
     */
    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by')->withTrashed();
    }

    /**
     * User yang sedang memegang lock.
     */
    public function lockedByUser()
    {
        return $this->belongsTo(User::class, 'locked_by')->withTrashed();
    }

    /**
     * Relasi ke dokumen MOU yang dihasilkan dari siklus ini.
     */
    public function mous()
    {
        return $this->hasMany(Mou::class, 'followup_cycle_id');
    }

    /**
     * Apakah siklus sudah ditutup secara permanen?
     */
    public function isClosed(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_CLOSED_NO_RESPONSE,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Apakah masih dapat melakukan percobaan follow-up baru?
     */
    public function canAttempt(): bool
    {
        return !$this->isClosed() && $this->current_attempt_count < 3;
    }

    /**
     * Label status berbahasa Indonesia.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN               => 'Belum Dimulai',
            self::STATUS_IN_PROGRESS         => 'Sedang Berjalan',
            self::STATUS_WAITING_RESPONSE   => 'Menunggu Respons',
            self::STATUS_COMPLETED          => 'Selesai',
            self::STATUS_CLOSED_NO_RESPONSE => 'Ditutup (Tanpa Respons)',
            self::STATUS_CANCELLED          => 'Dibatalkan',
            default                         => ucfirst($this->status),
        };
    }

    /**
     * Warna badge status.
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN               => 'bg-blue-50 text-blue-700 border-blue-200',
            self::STATUS_IN_PROGRESS         => 'bg-amber-50 text-amber-700 border-amber-200',
            self::STATUS_WAITING_RESPONSE   => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            self::STATUS_COMPLETED          => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::STATUS_CLOSED_NO_RESPONSE => 'bg-slate-100 text-slate-700 border-slate-200',
            self::STATUS_CANCELLED          => 'bg-rose-50 text-rose-700 border-rose-200',
            default                         => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    }

    /**
     * Label hasil akhir.
     */
    public function finalResultLabel(): ?string
    {
        if (empty($this->final_result)) {
            return null;
        }

        return match ($this->final_result) {
            self::RESULT_WILLING             => 'Bersedia Kerjasama',
            self::RESULT_NO_RESPONSE         => 'Tidak Ada Jawaban',
            self::RESULT_DECLINED            => 'Menolak Kerjasama',
            self::RESULT_CALL_BACK           => 'Minta Dihubungi Kembali',
            self::RESULT_COOPERATION_STOPPED => 'Kerjasama Dihentikan',
            default                          => ucfirst($this->final_result),
        };
    }

    /**
     * Apakah siklus ini sedang dikunci oleh seseorang yang valid/aktif dan kuncinya belum kedaluwarsa?
     */
    public function isLocked(): bool
    {
        if (empty($this->locked_by) || $this->lock_expires_at === null) {
            return false;
        }

        if (!$this->lock_expires_at->isFuture()) {
            return false;
        }

        $owner = $this->lockedByUser; // withTrashed
        if (!$owner || !$owner->isActive()) {
            return false;
        }

        return true;
    }

    /**
     * Apakah siklus ini sedang dikunci oleh user yang diberikan?
     */
    public function isLockedBy(?User $user): bool
    {
        if (!$user || !$user->isActive() || !$this->isLocked()) {
            return false;
        }

        return (int) $this->locked_by === (int) $user->id;
    }

    /**
     * Apakah siklus ini sedang dikunci oleh orang lain (selain user yang diberikan)?
     */
    public function isLockedByOther(?User $user): bool
    {
        if (!$this->isLocked()) {
            return false;
        }

        if (!$user) {
            return true;
        }

        return (int) $this->locked_by !== (int) $user->id;
    }

    /**
     * Memeriksa apakah user berhak memutasi siklus (mencatat attempt / menyelesaikan siklus).
     * Rule: NO VALID LOCK = NO MUTATION
     */
    public function canMutate(?User $user): bool
    {
        return $this->isLockedBy($user);
    }

    /**
     * Sisa menit sebelum kunci kedaluwarsa.
     */
    public function lockExpiresInMinutes(): int
    {
        if (!$this->isLocked()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($this->lock_expires_at) / 60));
    }
}
