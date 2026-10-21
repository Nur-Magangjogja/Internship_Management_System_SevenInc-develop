<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\DailyReport;
use App\Models\LeaveRequest;
use App\Models\PendingTask;
use App\Models\InternshipRegistration;
use App\Models\InternLoa;
use App\Models\SklDocument;
use App\Models\InternCertificate;
use App\Models\InternAssessment;
use App\Models\AlumniMembercard;
use App\Models\InternExtra;
use App\Models\WebinarCertificate;
use App\Models\WebinarAttendance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_HR_STAFF = 'hr_staff';
    public const ROLE_PEMAGANG = 'pemagang';
    public const ROLE_USER = 'user';

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isHrStaff(): bool
    {
        return $this->role === self::ROLE_HR_STAFF;
    }

    public function isPemagang(): bool
    {
        return $this->role === self::ROLE_PEMAGANG;
    }

    /**
     * Memeriksa apakah akun pengguna aktif (tidak diblokir/banned dan tidak dihapus secara soft-delete).
     */
    public function isActive(): bool
    {
        return !$this->is_banned
            && $this->banned_at === null
            && !$this->trashed();
    }

    /**
     * Mengembalikan daftar ID koneksi spreadsheet yang diizinkan untuk pengguna ini.
     * Mengembalikan null jika pengguna memiliki akses global penuh (unrestricted admin/superadmin).
     * Mengembalikan array kosong [] jika pengguna tidak memiliki hak akses sama sekali.
     *
     * Invarian Otorisasi Eksplisit:
     * 1. Akun tidak aktif / terblokir -> [] (zero access)
     * 2. Persistent config mapping: config("services.spreadsheet_scopes.{id}")
     * 3. Properti in-memory / dynamic: $this->allowed_connection_ids
     * 4. Role 'hr_staff' -> terbatas pada koneksi 'followup' secara persisten dari database
     * 5. Role 'admin' / 'superadmin' -> akses global penuh (null)
     */
    public function getAllowedConnectionIds(): ?array
    {
        if (!$this->isActive()) {
            return [];
        }

        // 1. Properti in-memory / instance jika diatur secara eksplisit
        if (isset($this->allowed_connection_ids) && is_array($this->allowed_connection_ids)) {
            return $this->allowed_connection_ids;
        }

        // 2. Persistent configuration mapping berdasarkan User ID
        $configured = config("services.spreadsheet_scopes.{$this->id}");
        if (is_array($configured)) {
            return $configured;
        }

        // 3. Hak akses global eksplisit: Role 'admin' dan 'superadmin' memiliki kewenangan penuh
        if (in_array($this->role, [self::ROLE_ADMIN, 'superadmin'], true)) {
            return null;
        }

        // 4. Role 'hr_staff' dibatasi secara persisten hanya pada koneksi follow-up
        if ($this->role === self::ROLE_HR_STAFF) {
            return \App\Models\SpreadsheetConnection::where('connection_key', \App\Models\SpreadsheetConnection::KEY_FOLLOWUP)
                ->pluck('id')
                ->all();
        }

        return [];
    }

    /**
     * Memeriksa apakah admin memiliki izin untuk mengakses koneksi spreadsheet tertentu.
     */
    public function canAccessConnection(?SpreadsheetConnection $connection = null): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($connection === null) {
            return true;
        }

        $allowedIds = $this->getAllowedConnectionIds();

        // null menandakan kewenangan global penuh
        if ($allowedIds === null) {
            return true;
        }

        return in_array($connection->id, $allowedIds, true);
    }

    /**
     * Memeriksa apakah pengguna berwenang menjalankan manual retry sinkronisasi.
     */
    public function canRetrySync(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if (isset($this->can_retry_sync)) {
            return (bool) $this->can_retry_sync;
        }

        $configuredPermission = config("services.spreadsheet_retry_permission.{$this->id}");
        if ($configuredPermission !== null) {
            return (bool) $configuredPermission;
        }

        return in_array($this->role, [self::ROLE_ADMIN, 'superadmin', self::ROLE_HR_STAFF], true);
    }

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
        'banned_at',
        'ban_reason',
        'is_gps_support',
        'is_gps_active',
        'is_gps_activate',
        'documents_viewed_at',
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
        'email_verified_at'   => 'datetime',
        'banned_at'           => 'datetime',
        'documents_viewed_at' => 'datetime',
        'is_banned'           => 'boolean',
        'is_online'           => 'boolean',
        'is_gps_support'      => 'boolean',
        'is_gps_active'       => 'boolean',
        'is_gps_activate'     => 'boolean',
    ];

    public function internshipRegistration(): HasOne
    {
        return $this->hasOne(\App\Models\InternshipRegistration::class, 'user_id')
            ->latestOfMany('id');
    }

    /**
     * Registrasi terbaru (terakhir dibuat) milik pengguna, apapun statusnya.
     */
    public function latestRegistration(): HasOne
    {
        return $this->hasOne(\App\Models\InternshipRegistration::class, 'user_id')
            ->latestOfMany('id');
    }

    /**
     * Registrasi operasional yang sedang berjalan atau draf aktif milik pengguna.
     * Mengembalikan null jika seluruh registrasi terdahulu sudah terminal/closed (completed/rejected/exited).
     */
    public function currentRegistration(): ?\App\Models\InternshipRegistration
    {
        return app(\App\Services\RegistrationLifecycleService::class)->getOpenOrDraftRegistration($this);
    }

    /**
     * Registrasi berstatus 'completed' terakhir milik pengguna (untuk fallback entitlement dokumen alumni).
     */
    public function latestCompletedRegistration(): HasOne
    {
        return $this->hasOne(\App\Models\InternshipRegistration::class, 'user_id')
            ->where('internship_status', \App\Models\InternshipRegistration::STATUS_COMPLETED)
            ->latestOfMany('id');
    }

    /**
     * Seluruh riwayat pendaftaran magang multi-periode milik pengguna.
     */
    public function internshipRegistrations(): HasMany
    {
        return $this->hasMany(\App\Models\InternshipRegistration::class, 'user_id');
    }

    /**
     * Periksa apakah pengguna merupakan alumni (memiliki riwayat magang yang completed).
     */
    public function hasCompletedInternship(): bool
    {
        return $this->internshipRegistrations()
            ->where('internship_status', \App\Models\InternshipRegistration::STATUS_COMPLETED)
            ->exists();
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

    public function createMemberCard(?InternshipRegistration $targetRegistration = null): ?\App\Models\AlumniMembercard
    {
        $intern = $targetRegistration;

        if (!$intern) {
            $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
            $intern = $lifecycle->resolveRegistrationForDocuments($this, null, 'membercard');
        }

        if (!$intern || !$intern->start_date) {
            return null;
        }

        if (!in_array($intern->internship_status, [InternshipRegistration::STATUS_COMPLETED, InternshipRegistration::STATUS_ACTIVE], true)) {
            return null;
        }

        $angkatanYear = \Carbon\Carbon::parse($intern->start_date)->format('Y');
        $angkatan     = substr($angkatanYear, -2);
        $idPadded     = str_pad($this->id, 3, '0', STR_PAD_LEFT);
        $brand        = $intern->brand ?? 'magangjogja.com';
        $prefix       = $this->getBrandPrefix($brand);
        $code         = "{$prefix}{$angkatan}{$idPadded}";

        return \App\Models\AlumniMembercard::firstOrCreate(
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

    /**
     * Nama tampilan (display name) untuk antarmuka pengguna:
     * Memprioritaskan nama lengkap registrasi terbaru jika ada,
     * dengan fallback ke nama akun / username asli.
     */
    public function getDisplayNameAttribute(): string
    {
        $reg = $this->internshipRegistration ?? $this->registration;
        if ($reg && !empty($reg->fullname)) {
            return $reg->fullname;
        }

        return (string) ($this->attributes['name'] ?? '');
    }

    /**
     * Nama lengkap dari pendaftaran magang terbaru (jika ada).
     */
    public function getLatestRegistrationNameAttribute(): ?string
    {
        $reg = $this->internshipRegistration ?? $this->registration;
        return $reg?->fullname;
    }

    public function getProfilePictureAttribute()
    {
        return $this->registration ? $this->registration->profile_photo : null;
    }

    public function getPhoneNumberAttribute()
    {
        return $this->registration ? $this->registration->phone_number : null;
    }

    /**
     * Pemetaan identitas eksternal (misal Absen Djuragan).
     */
    public function externalIdentityMappings(): HasMany
    {
        return $this->hasMany(ExternalIdentityMapping::class, 'ims_user_id');
    }

    /**
     * State aktivasi akun (khusus akun baru dari integrasi eksternal).
     */
    public function accountActivation(): HasOne
    {
        return $this->hasOne(UserAccountActivation::class, 'user_id');
    }

    /**
     * Memeriksa apakah akun masih menunggu aktivasi email.
     * Mengembalikan false jika tabel aktivasi belum dimigrasikan atau akun tidak memiliki record aktivasi.
     */
    public function isActivationPending(): bool
    {
        if (!\Illuminate\Support\Facades\Schema::connection($this->getConnectionName())->hasTable('user_account_activations')) {
            return false;
        }

        $activation = $this->accountActivation;
        return $activation !== null && $activation->isPending();
    }

    /**
     * Tandai bahwa pengguna (pemagang) telah membuka halaman Dokumen Saya.
     */
    public function markDocumentsAsViewed(): void
    {
        $now = now();
        if (\Illuminate\Support\Facades\Schema::connection($this->getConnectionName())->hasColumn('users', 'documents_viewed_at')) {
            $this->update(['documents_viewed_at' => $now]);
        }
        session(['documents_viewed_at' => $now]);
        \Illuminate\Support\Facades\Cache::forever("user_{$this->id}_documents_viewed_at", $now->toDateTimeString());
    }

    /**
     * Cek apakah ada file/dokumen baru untuk pemagang yang belum dibuka.
     */
    public function hasUnreadDocuments(): bool
    {
        if ($this->role !== self::ROLE_PEMAGANG) {
            return false;
        }

        $registration = $this->internshipRegistration
            ?? InternshipRegistration::where('user_id', $this->id)->latest('id')->first();

        if (!$registration || $registration->is_draft) {
            return false;
        }

        $status = $registration->internship_status;
        $isAccepted = in_array($status, [
            InternshipRegistration::STATUS_ACCEPTED,
            InternshipRegistration::STATUS_ACTIVE,
            InternshipRegistration::STATUS_COMPLETED,
        ], true);

        if (!$isAccepted) {
            return false;
        }

        $lastViewed = $this->documents_viewed_at
            ?? session('documents_viewed_at')
            ?? \Illuminate\Support\Facades\Cache::get("user_{$this->id}_documents_viewed_at");

        $timestamps = [];

        // 1. LOA
        $loaTime = InternLoa::where('intern_id', $registration->id)->latest('id')->value('created_at');
        if ($loaTime) {
            $timestamps[] = $loaTime;
        } elseif ($registration->updated_at) {
            $timestamps[] = $registration->updated_at;
        }

        // 2. Dokumen Kelulusan jika status completed
        if ($status === InternshipRegistration::STATUS_COMPLETED) {
            $sklTime = SklDocument::where('intern_id', $registration->id)->latest('id')->value('created_at');
            $timestamps[] = $sklTime ?: $registration->updated_at;

            $sertifTime = InternCertificate::where('intern_id', $registration->id)->latest('id')->value('created_at');
            if ($sertifTime) {
                $timestamps[] = $sertifTime;
            }

            $assessTime = InternAssessment::where('intern_id', $registration->id)->latest('id')->value('created_at');
            if ($assessTime) {
                $timestamps[] = $assessTime;
            }

            $membercardTime = AlumniMembercard::where('intern_id', $registration->id)->latest('id')->value('created_at');
            if ($membercardTime) {
                $timestamps[] = $membercardTime;
            }
        }

        // 3. Surat Rekomendasi
        $extra = InternExtra::where('intern_id', $registration->id)->first();
        if ($extra && $extra->surat_rekomendasi) {
            $timestamps[] = $extra->updated_at ?? $extra->created_at;
        }

        // 4. Sertifikat Webinar
        $webinarTime = WebinarCertificate::whereHas('attendance', function ($q) {
            $q->where('user_id', $this->id)
              ->where('status', WebinarAttendance::STATUS_APPROVED);
        })->latest('id')->value('created_at');
        if ($webinarTime) {
            $timestamps[] = $webinarTime;
        }

        if (empty($timestamps)) {
            return false;
        }

        $latestDocTime = max($timestamps);

        if (!$lastViewed) {
            return true;
        }

        return Carbon::parse($latestDocTime)->gt(Carbon::parse($lastViewed));
    }
}
