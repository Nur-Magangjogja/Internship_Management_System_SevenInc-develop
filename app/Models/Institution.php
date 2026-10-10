<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Institution extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'public_uuid',
        'name',
        'normalized_name',
        'address',
        'phone',
        'email',
        'fax',
        'correspondence_unit',
        'is_active',
        'inactive_reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($institution) {
            if (empty($institution->public_uuid)) {
                $institution->public_uuid = (string) Str::uuid();
            }
        });

        static::saving(function ($institution) {
            if (!empty($institution->name)) {
                $institution->normalized_name = self::normalizeName($institution->name);
            }
        });
    }

    /**
     * Scope query untuk hanya mengambil institusi aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Relasi ke pendaftaran magang pemagang.
     */
    public function internshipRegistrations()
    {
        return $this->hasMany(\App\Models\InternshipRegistration::class, 'institution_id');
    }

    /**
     * Relasi ke daftar kontak pembimbing (guru/dosen/PIC).
     */
    public function contacts()
    {
        return $this->hasMany(\App\Models\InstitutionContact::class, 'institution_id');
    }

    /**
     * Relasi ke daftar dokumen MOU / kerja sama institusi.
     */
    public function mous()
    {
        return $this->hasMany(\App\Models\Mou::class, 'institution_id');
    }

    /**
     * Relasi ke MOU yang saat ini aktif berlaku.
     */
    public function activeMou()
    {
        return $this->hasOne(\App\Models\Mou::class, 'institution_id')->where('status', \App\Models\Mou::STATUS_ACTIVE)->latestOfMany();
    }

    /**
     * Relasi ke riwayat siklus follow-up kemitraan institusi.
     */
    public function followUpCycles()
    {
        return $this->hasMany(\App\Models\FollowUpCycle::class, 'institution_id');
    }


    /**
     * Helper untuk normalisasi nama institusi (mencegah variasi singkatan / ejaan dan duplikasi).
     */
    public static function normalizeName(string $name): string
    {
        // 1. Lowercase dan trim
        $clean = mb_strtolower(trim($name));
        // 2. Ganti tanda baca dengan spasi
        $clean = preg_replace('/[.,\-_()\/]/', ' ', $clean);
        // 3. Normalisasi singkatan umum institusi pendidikan
        $words = explode(' ', $clean);
        $normalizedWords = [];
        foreach ($words as $w) {
            $w = trim($w);
            if ($w === '') continue;
            $w = match ($w) {
                'univ', 'university' => 'universitas',
                'inst', 'institute'  => 'institut',
                'poltek'             => 'politeknik',
                'akademi', 'akd'     => 'akademi',
                default              => $w,
            };
            $normalizedWords[] = $w;
        }
        return implode(' ', $normalizedWords);
    }
}
