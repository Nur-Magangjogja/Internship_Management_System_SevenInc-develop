<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Webinar extends Model
{
    use HasFactory;

    public const CERT_SOURCE_TEMPLATE = 'template';
    public const CERT_SOURCE_GDRIVE   = 'gdrive';

    protected $fillable = [
        'title',
        'description',
        'event_date',
        'zoom_link',
        'brand_id',
        'allowed_brands',
        'certificate_source',
        'certificate_template_id',
        'gdrive_certificate_link',
    ];

    protected $casts = [
        'event_date'     => 'datetime',
        'allowed_brands' => 'array',    // null = semua brand boleh ikut
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function certificateTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'certificate_template_id');
    }

    public function isTemplateCertificate(): bool
    {
        return ($this->certificate_source ?? self::CERT_SOURCE_TEMPLATE) === self::CERT_SOURCE_TEMPLATE;
    }

    public function isGdriveCertificate(): bool
    {
        return ($this->certificate_source ?? '') === self::CERT_SOURCE_GDRIVE;
    }

    /**
     * Perusahaan penerbit sertifikat = nama brand yang dipilih.
     * Hapus field certificate_company dari DB-driven logic, pakai brand label saja.
     */
    public static function brandLabel(string $code): string
    {
        return self::brandList()[$code] ?? $code;
    }

    public static function brandList(): array
    {
        return [
            'SI'  => 'SEVEN INC',
            'SVII'=> 'SEVEN INC',
            'RW'  => 'Republik Web',
            'AKM' => 'Ada Kamar',
            'AKI' => 'Ada Kamar',
            'MJ'  => 'Magang Jogja',
            'MK'  => 'Magangku',
            'TT'  => 'Tuan Tanah',
            'TN'  => 'Tuan Tanah',
            'MO'  => 'Makeover',
            'BC'  => 'Bimbel Cerdas',
            'AK'  => 'Area Kerja',
            'TL'  => 'Tukang Las',
            'TS'  => 'Titip Sini',
            'AP'  => 'Ambilpaket',
            'BK'  => 'Bikinkepo',
            'LK'  => 'Latihankerja.com',
            'LJT' => 'Lowkerjateng.com',
            'LJG' => 'Lowkerjogja.com',
            'PJ'  => 'Pijatjogja.com',
            'SB'  => 'Sayabantu.com',
            'TV'  => 'Titikvisual',
        ];
    }

    /**
     * Normalisasi brand string ke kode (misal "Seven Inc" -> "SI", "MJ" -> "MJ").
     */
    public static function normalizeBrandCode(?string $brand): ?string
    {
        if (!$brand) {
            return null;
        }

        $brandMap = self::brandList();

        if (isset($brandMap[strtoupper($brand)])) {
            return strtoupper($brand);
        }

        foreach ($brandMap as $code => $label) {
            if (strcasecmp($label, $brand) === 0) {
                return $code;
            }
        }

        return $brand;
    }

    /**
     * Cek apakah webinar diizinkan untuk brand tertentu.
     */
    public function isAllowedForBrand(?string $brandCode): bool
    {
        if (is_null($this->allowed_brands)) {
            return true;
        }

        if ($brandCode && in_array($brandCode, $this->allowed_brands, true)) {
            return true;
        }

        if (!$brandCode) {
            return true;
        }

        return false;
    }

    /**
     * Scope untuk webinar yang belum selesai (akan datang).
     */
    public function scopeUpcoming($query)
    {
        return $query->where('event_date', '>', now());
    }

    /**
     * Cek apakah webinar sudah selesai.
     */
    public function isFinished(): bool
    {
        return $this->event_date ? $this->event_date->isPast() : false;
    }

    /** Admin yang membuat webinar */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Semua attendance/bukti kehadiran webinar ini */
    public function attendances(): HasMany
    {
        return $this->hasMany(WebinarAttendance::class);
    }

    /** Attendance yang sudah di-approve */
    public function approvedAttendances(): HasMany
    {
        return $this->hasMany(WebinarAttendance::class)->where('status', 'approved');
    }

    /** Attendance yang masih pending */
    public function pendingAttendances(): HasMany
    {
        return $this->hasMany(WebinarAttendance::class)->where('status', 'pending');
    }

    /** Cek apakah user tertentu sudah submit bukti */
    public function hasAttendance(int $userId): bool
    {
        return $this->attendances()->where('user_id', $userId)->exists();
    }

    /** Ambil attendance milik user tertentu */
    public function attendanceOf(int $userId): ?WebinarAttendance
    {
        return $this->attendances()->where('user_id', $userId)->first();
    }
}
