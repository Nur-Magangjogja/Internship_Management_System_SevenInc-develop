<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegistrationContactLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_uuid',
        'internship_registration_id',
        'institution_contact_id',
        'relation_year',
        'is_primary',
        'source',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'is_primary'   => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($link) {
            if (empty($link->public_uuid)) {
                $link->public_uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Relasi ke pendaftaran magang pemagang.
     */
    public function internshipRegistration()
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id')->withTrashed();
    }

    /**
     * Relasi ke kontak pembimbing institusi.
     */
    public function institutionContact()
    {
        return $this->belongsTo(InstitutionContact::class, 'institution_contact_id');
    }

    /**
     * User admin/HR yang mengonfirmasi link kontak pembimbing.
     */
    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
