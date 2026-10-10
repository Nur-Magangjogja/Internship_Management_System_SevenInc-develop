<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FollowUpAttempt extends Model
{
    use HasFactory;

    protected $table = 'followup_attempts';

    protected $fillable = [
        'public_uuid',
        'followup_cycle_id',
        'attempt_number',
        'performed_by',
        'followed_up_at',
        'channel',
        'result',
        'response_text',
        'notes',
        'next_followup_at',
    ];

    protected $casts = [
        'attempt_number'   => 'integer',
        'followed_up_at'   => 'datetime',
        'next_followup_at' => 'date',
    ];

    protected static function booted()
    {
        static::creating(function ($attempt) {
            if (empty($attempt->public_uuid)) {
                $attempt->public_uuid = (string) Str::uuid();
            }
            if ($attempt->attempt_number < 1 || $attempt->attempt_number > 3) {
                throw new \DomainException('Nomor percobaan follow-up harus bernilai antara 1 dan 3.');
            }
        });
    }

    /**
     * Relasi ke siklus induk.
     */
    public function cycle()
    {
        return $this->belongsTo(FollowUpCycle::class, 'followup_cycle_id');
    }

    /**
     * Staf yang mengeksekusi follow-up.
     */
    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by')->withTrashed();
    }

    /**
     * Label saluran kontak.
     */
    public function channelLabel(): string
    {
        return match ($this->channel) {
            'whatsapp' => 'WhatsApp',
            'phone'    => 'Panggilan Telepon',
            'email'    => 'Email',
            'onsite'   => 'Kunjungan Langsung',
            default    => ucfirst($this->channel ?? 'Lainnya'),
        };
    }

    /**
     * Label hasil kontak.
     */
    public function resultLabel(): string
    {
        return match ($this->result) {
            'responded'    => 'Direspons Positif',
            'call_back'    => 'Minta Dihubungi Lagi',
            'no_answer'    => 'Tidak Menjawab / Panggilan Tidak Diangkat',
            'busy'         => 'Sedang Sibuk',
            'wrong_number' => 'Nomor Tidak Valid / Salah Sambung',
            'declined'     => 'Menolak / Tidak Bersedia',
            default        => ucfirst($this->result ?? '-'),
        };
    }

    /**
     * Class badge hasil kontak.
     */
    public function resultBadgeClass(): string
    {
        return match ($this->result) {
            'responded'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'call_back'    => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'no_answer'    => 'bg-amber-50 text-amber-700 border-amber-200',
            'busy'         => 'bg-slate-100 text-slate-700 border-slate-200',
            'wrong_number' => 'bg-rose-50 text-rose-700 border-rose-200',
            'declined'     => 'bg-rose-50 text-rose-700 border-rose-200',
            default        => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    }
}
