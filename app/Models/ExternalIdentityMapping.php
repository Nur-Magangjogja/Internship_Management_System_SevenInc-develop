<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalIdentityMapping extends Model
{
    use HasFactory;

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONFLICT = 'conflict';

    public const SOURCE_ABSEN_DJURAGAN = 'absen_djuragan';

    protected $table = 'external_identity_mappings';

    protected $fillable = [
        'source_system',
        'external_user_id',
        'ims_user_id',
        'verification_status',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    /**
     * Akun IMS yang dipetakan ke identitas eksternal ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ims_user_id');
    }

    /**
     * Staf/Admin IMS yang memverifikasi identitas ini (jika manual).
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Hubungan satu identitas eksternal dengan banyak pemetaan pendaftaran/periode magang.
     */
    public function registrationMappings(): HasMany
    {
        return $this->hasMany(ExternalRegistrationMapping::class, 'external_identity_mapping_id');
    }

    /**
     * Mengambil koleksi model InternshipRegistration yang terhubung secara multi-periode.
     * Menggantikan HasManyThrough yang tidak kompatibel dengan skema intermediate foreign key.
     *
     * @return \Illuminate\Support\Collection<\App\Models\InternshipRegistration>
     */
    public function getRegistrationsAttribute(): \Illuminate\Support\Collection
    {
        return $this->registrationMappings->map->registration->filter()->values();
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::STATUS_VERIFIED;
    }

    public function isPending(): bool
    {
        return $this->verification_status === self::STATUS_PENDING_VERIFICATION;
    }

    public function isConflict(): bool
    {
        return $this->verification_status === self::STATUS_CONFLICT;
    }
}
