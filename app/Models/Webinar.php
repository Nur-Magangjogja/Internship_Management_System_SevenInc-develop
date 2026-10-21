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
            'MJ'=>'Magangjogja','AK'=>'Areakerja','RW'=>'Republikweb','TS'=>'Titipsini',
            'AP'=>'Ambilpaket','BK'=>'Bikinkepo','BC'=>'Bimbelcerdas.com','LK'=>'Latihankerja.com',
            'LJT'=>'Lowkerjateng.com','LJG'=>'Lowkerjogja.com','PJ'=>'Pijatjogja.com',
            'SB'=>'Sayabantu.com','TV'=>'Titikvisual','TN'=>'Tuantanah','TL'=>'Tukanglas.org',
            'AKI'=>'Adakamar.id','SI'=>'Seven Inc',
        ];
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
