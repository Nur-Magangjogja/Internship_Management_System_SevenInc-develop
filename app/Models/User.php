<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\DailyReport;
use App\Models\LeaveRequest;
use App\Models\PendingTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_online',
        'is_banned',
        'is_gps_support',
        'is_gps_active',
        'is_gps_activate',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'banned_at'         => 'datetime',
        'is_banned'         => 'boolean',
        'is_online'         => 'boolean',
        'is_gps_support'    => 'boolean',
        'is_gps_active'     => 'boolean',
        'is_gps_activate'   => 'boolean',
    ];

    public function internshipRegistration(): HasOne
    {
        return $this->hasOne(\App\Models\InternshipRegistration::class, 'user_id')
            ->latestOfMany('id');
    }



    public function dailyReports()
    {
        return $this->hasManyThrough(
            DailyReport::class,
            InternshipRegistration::class,
            'user_id', // Foreign key on internship_registrations table
            'intern_id', // Foreign key on daily_reports table
            'id', // Local key on users table
            'id' // Local key on internship_registrations table
        );
    }

    public function leaveRequests()
    {
        return $this->hasManyThrough(
            LeaveRequest::class,
            InternshipRegistration::class,
            'user_id',
            'intern_id',
            'id',
            'id'
        );
    }

    public function pendingTasks()
    {
        return $this->hasManyThrough(
            PendingTask::class,
            InternshipRegistration::class,
            'user_id',
            'intern_id',
            'id',
            'id'
        );
    }

    // Event to listen to when a user's status changes
    protected static function booted()
    {
        // createMemberCard() sekarang dipanggil secara eksplisit dari InternController
        // saat status berubah ke active atau completed — bukan otomatis di sini.
        // Ini mencegah kartu dibuat saat status masih 'accepted'.
    }

    public function createMemberCard()
    {
        $intern = $this->internshipRegistration;

        if (!$intern || !$intern->start_date) return;

        $angkatanYear = \Carbon\Carbon::parse($intern->start_date)->format('Y');
        $angkatan     = substr($angkatanYear, -2);
        $idPadded     = str_pad($this->id, 3, '0', STR_PAD_LEFT);
        $brand        = $intern->brand ?? 'magangjogja.com';
        $prefix       = $this->getBrandPrefix($brand);
        $code         = "{$prefix}{$angkatan}{$idPadded}";

        \App\Models\AlumniMembercard::firstOrCreate(
            ['intern_id' => $intern->id],
            [
                'member_code' => $code,
                'batch_year'  => $angkatanYear,
            ]
        );
    }




    public function getBrandPrefix(string $brand): string
    {
        $dbBrand = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim($brand))])
            ->orWhere('code', $brand)
            ->first();

        if ($dbBrand && $dbBrand->code) {
            return strtoupper(trim($dbBrand->code));
        }

        return match (strtolower($brand)) {
            'magangjogja.com' => 'MJ',
            'areakerja.com'   => 'AK',
            'republikweb.net'   => 'RW',
            'titipsini.com'   => 'TS',
            'ambilpaket.com'   => 'AP',
            'bikinkepo.com'   => 'BK',
            'bimbelcerdas.com'   => 'BC',
            'latihankerja.com'   => 'LK',
            'lowkerjateng.com'   => 'LJT',
            'lowkerjogja.com'   => 'LJG',
            'pijatjogja.com'   => 'PJ',
            'sayabantu.com'   => 'SB',
            'titikvisual.com'   => 'TV',
            'tuantanah.com'   => 'TN',
            'tukanglas.org'   => 'TL',
            'adakamarid'   => 'AKI',
            'seven inc'   => 'SI',
            default           => 'XX', // fallback default
        };
    }

    public function registration(): HasOne
    {
        return $this->hasOne(InternshipRegistration::class, 'user_id')->latestOfMany();
    }

    public function getNameAttribute()
    {
        // Prioritas: fullname dari registrasi (pemagang) -> name di kolom users (admin/user)
        if ($this->registration && $this->registration->fullname) {
            return $this->registration->fullname;
        }
        return $this->attributes['name'] ?? null;
    }

    public function getProfilePictureAttribute()
    {
        return $this->registration ? $this->registration->profile_photo : null;
    }

    public function getPhoneNumberAttribute()
    {
        return $this->registration ? $this->registration->phone_number : null;
    }
}
