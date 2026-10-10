<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class InstitutionContact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'public_uuid',
        'institution_id',
        'name',
        'normalized_name',
        'position_title',
        'phone',
        'normalized_phone',
        'email',
        'is_active',
        'is_verified',
        'verified_by',
        'verified_at',
        'notes',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active'   => true,
        'is_verified' => false,
    ];

    protected static function booted()
    {
        static::creating(function ($contact) {
            if (empty($contact->public_uuid)) {
                $contact->public_uuid = (string) Str::uuid();
            }
        });

        static::saving(function ($contact) {
            if (!empty($contact->name)) {
                $contact->normalized_name = static::normalizeName($contact->name);
            }

            if (!empty($contact->phone)) {
                $contact->normalized_phone = static::normalizePhone($contact->phone);
            } else {
                $contact->normalized_phone = null;
            }
        });
    }

    /**
     * Normalisasi nama kontak pembimbing (menghapus gelar akademis & kehormatan).
     */
    public static function normalizeName(?string $name): ?string
    {
        if (empty($name)) {
            return null;
        }

        $clean = mb_strtolower(trim($name));

        // Hapus gelar dan sapaan umum yang sering ditambahkan/dihilangkan
        $patterns = [
            '/\b(prof|dr|drs|dra|ir|h|hj|bpk|bapak|pak|ibu|bu)\b/ui',
            '/\b(m\.?pd|m\.?kom|m\.?si|m\.?ti|m\.?sc|m\.?eng|m\.?sn|m\.?stat|m\.?ba)\b/ui',
            '/\b(s\.?t|s\.?pd|s\.?kom|s\.?si|s\.?e|s\.?sos|s\.?hum|s\.?ip|s\.?ked|s\.?h|s\.?ag)\b/ui',
            '/\b(ph\.?d|b\.?sc|m\.?h|m\.?m|m\.?ag)\b/ui',
        ];
        $clean = preg_replace($patterns, ' ', $clean);

        // Hapus tanda baca
        $clean = preg_replace('/[.,\-_()\/]/', ' ', $clean);

        // Standarisasi spasi
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        return $clean !== '' ? $clean : mb_strtolower(trim($name));
    }

    /**
     * Normalisasi nomor telepon untuk standardisasi pencarian dan indeks.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Hapus karakter non-digit
        $digits = preg_replace('/[^\d]/', '', $phone);

        // Jika diawali 08 -> ganti dengan 628
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Scope untuk hanya mengambil kontak yang aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk mengambil kontak yang telah diverifikasi oleh staf/admin.
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope untuk mengambil kontak kandidat yang belum diverifikasi.
     */
    public function scopeUnverified($query)
    {
        return $query->where('is_verified', false);
    }

    /**
     * Relasi ke pengguna staf/admin yang memverifikasi kontak.
     */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Relasi ke Institusi induk.
     */
    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    /**
     * Relasi ke perantara pivot registration_contact_links.
     */
    public function registrationLinks()
    {
        return $this->hasMany(RegistrationContactLink::class, 'institution_contact_id');
    }

    /**
     * Relasi ke pendaftaran magang pemagang yang dibimbing.
     */
    public function internshipRegistrations()
    {
        return $this->belongsToMany(
            InternshipRegistration::class,
            'registration_contact_links',
            'institution_contact_id',
            'internship_registration_id'
        )->withPivot(['public_uuid', 'relation_year', 'is_primary', 'source', 'confirmed_by', 'confirmed_at'])
         ->withTimestamps();
    }

    /**
     * Relasi ke siklus follow-up yang menghubungi kontak ini.
     */
    public function followUpCycles()
    {
        return $this->hasMany(FollowUpCycle::class, 'institution_contact_id');
    }
}

