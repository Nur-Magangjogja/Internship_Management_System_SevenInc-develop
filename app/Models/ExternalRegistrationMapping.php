<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalRegistrationMapping extends Model
{
    use HasFactory;

    public const SOURCE_ABSEN_DJURAGAN = 'absen_djuragan';

    protected $table = 'external_registration_mappings';

    protected $fillable = [
        'external_identity_mapping_id',
        'source_system',
        'external_registration_id',
        'ims_registration_id',
    ];

    /**
     * Identitas eksternal induk (ExternalIdentityMapping).
     */
    public function identityMapping(): BelongsTo
    {
        return $this->belongsTo(ExternalIdentityMapping::class, 'external_identity_mapping_id');
    }

    /**
     * Pendaftaran magang spesifik pada IMS.
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'ims_registration_id');
    }

    /**
     * Menjaga konsistensi source_system dengan entitas induk identityMapping.
     */
    protected static function booted(): void
    {
        static::saving(function (ExternalRegistrationMapping $mapping) {
            if ($mapping->identityMapping && $mapping->source_system !== $mapping->identityMapping->source_system) {
                $mapping->source_system = $mapping->identityMapping->source_system;
            }
        });
    }

    /**
     * Memvalidasi bahwa pendaftaran IMS dimiliki oleh pengguna IMS yang sama
     * dengan identitas eksternal (Registration Ownership Invariant).
     */
    public function validateOwnership(): bool
    {
        $identity = $this->identityMapping;
        $registration = $this->registration;

        if (!$identity || !$registration) {
            return false;
        }

        return (int) $identity->ims_user_id === (int) $registration->user_id;
    }
}
