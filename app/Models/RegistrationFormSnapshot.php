<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationFormSnapshot extends Model
{
    use HasFactory;

    protected $table = 'registration_form_snapshots';

    protected $fillable = [
        'registration_id',
        'form_schema_id',
        'form_schema_version',
        'schema_hash',
        'schema_snapshot',
        'submitted_at',
    ];

    protected $casts = [
        'schema_snapshot' => 'array',
        'submitted_at'    => 'datetime',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'registration_id');
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(FormSchema::class, 'form_schema_id');
    }

    protected static function booted(): void
    {
        static::updating(function (RegistrationFormSnapshot $snapshot) {
            throw new \DomainException("RegistrationFormSnapshot bersifat imutabel dan dilarang dimutasi setelah dibekukan.");
        });

        static::deleting(function (RegistrationFormSnapshot $snapshot) {
            throw new \DomainException("RegistrationFormSnapshot dilarang dihapus.");
        });
    }
}
