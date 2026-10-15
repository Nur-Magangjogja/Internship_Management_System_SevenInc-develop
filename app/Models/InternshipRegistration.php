<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Carbon\Carbon;

class InternshipRegistration extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'internship_registrations';

    // ===== Workflow status (dipakai filter/menu) =====
    public const STATUS_NEW         = 'new';
    public const STATUS_WAITING     = 'waiting';
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_EXITED      = 'exited';
    public const STATUS_PENDING     = 'pending';
    public const STATUS_ACCEPTED    = 'accepted';  
    public const STATUS_REJECTED    = 'rejected';   

    // ===== 8 Follow-Up Statuses (S17.18C) =====
    public const FOLLOWUP_NOT_FOLLOWED_UP       = 'not_followed_up';
    public const FOLLOWUP_AWAITING_AGREEMENT    = 'awaiting_agreement';
    public const FOLLOWUP_MOVED_TO_PARTICIPANTS = 'moved_to_participants';
    public const FOLLOWUP_INTERNSHIP_STARTED    = 'internship_started';
    public const FOLLOWUP_INTERNSHIP_CANCELLED  = 'internship_cancelled';
    public const FOLLOWUP_NOT_YET_ACC           = 'not_yet_acc';
    public const FOLLOWUP_INTERNSHIP_COMPLETED  = 'internship_completed';
    public const FOLLOWUP_AWAITING_DATES        = 'awaiting_dates';

    public const FOLLOWUP_STATUS_LABELS = [
        self::FOLLOWUP_NOT_FOLLOWED_UP       => 'Belum di-follow-up (belum dikirim surat penerimaan magang)',
        self::FOLLOWUP_AWAITING_AGREEMENT    => 'Sudah di-follow-up, masih menunggu jawaban "setuju" dengan ketentuan, perlu dicek ulang',
        self::FOLLOWUP_MOVED_TO_PARTICIPANTS => 'Sudah di-follow-up (sudah dinyatakan diterima, sudah dipindah ke daftar peserta)',
        self::FOLLOWUP_INTERNSHIP_STARTED    => 'Sudah masuk magang',
        self::FOLLOWUP_INTERNSHIP_CANCELLED  => 'Tidak jadi magang',
        self::FOLLOWUP_NOT_YET_ACC           => 'Sudah di-follow-up, tetapi belum ACC, perlu dicek ulang',
        self::FOLLOWUP_INTERNSHIP_COMPLETED  => 'Sudah selesai magang',
        self::FOLLOWUP_AWAITING_DATES        => 'Mau magang, tetapi masih menunggu kepastian tanggal dari sekolah/kampus',
    ];

    protected $fillable = [
        'user_id',
        'fullname', 'born_date', 'birth_place', 'student_id', 'gender', 'religion', 'phone_number',
        'city_id', 'institution_id', 'study_program_id', 'faculty_id',
        'internship_reason', 'internship_type', 'internship_arrangement',
        'current_status', 'english_book_ability',
        'wfo_readiness', 'schedule_shift_readiness', 'has_laptop',
        'division_id', 'brand_id',
        'start_date', 'planned_duration', 'end_date',
        'internship_status',
        'followup_status',
        'supervisor_name', 'supervisor_contact',
        'current_activities', 'social_media_instagram',
        'profile_photo',
        'boarding_info',
    ];

    /**
     * Catatan casts:
     * - JANGAN cast born_date/start_date/end_date sebagai 'date' karena sudah ditangani
     *   oleh Accessor/Mutator di bawah. Casting ganda akan menyebabkan konflik di Laravel 9+.
     * - Simpan created_at/updated_at saja sebagai datetime.
     */
    protected $casts = [
        'has_laptop'     => 'boolean',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->invalidateConditionCache();
        });
    }

    protected $appends = [
        'email', 'current_city', 'institution_name', 'study_program', 'faculty', 'internship_interest', 'brand',
        'owned_tools', 'internship_info_sources', 'laptop_equipment',
        'design_software', 'video_software', 'programming_languages', 'digital_marketing_type',
        'is_draft',
        'followup_status_label',
    ];

    protected $with = [
        'user', 'city', 'institution', 'studyProgramRel', 'facultyRel', 'division', 'brandRel',
        'tools', 'infoSources', 'skills'
    ];

    /* ============================================================
     |  Normalisasi tanggal → string 'Y-m-d' (untuk penyimpanan)
     * ============================================================ */

    /**
     * Coba ubah berbagai format tanggal (termasuk "08 Agustus 2022")
     * menjadi string 'YYYY-MM-DD'. Jika tidak bisa, kembalikan string asli.
     */
    private function toYmdString(mixed $value): ?string
    {
        if ($value === null) return null;
        $s = trim((string) $value);
        if ($s === '') return null;

        // 1) Format yang sudah benar
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }

        // 2) DD-MM-YYYY atau DD/MM/YYYY
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        // 3) "08 Agustus 2022" / "8 agustus 2022"
        $bulan = [
            'januari'=>1,'februari'=>2,'maret'=>3,'april'=>4,'mei'=>5,'juni'=>6,
            'juli'=>7,'agustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'desember'=>12
        ];
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/u', $s, $m)) {
            $mon = strtolower($m[2]);
            if (isset($bulan[$mon])) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], $bulan[$mon], (int)$m[1]);
            }
        }

        // 4) ISO/Datetime → pakai Carbon sebagai fallback
        try {
            return Carbon::parse($s)->format('Y-m-d');
        } catch (\Throwable $e) {
            // Tidak bisa diparse → biarkan apa adanya
            return $s;
        }
    }

    /* ============================================================
     |  Accessor/Mutator: SELALU kembalikan STRING, bukan Carbon
     * ============================================================ */

    /** born_date: simpan Y-m-d bila bisa, get selalu string apa adanya atau null */
    protected function bornDate(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : (string) $value,
            set: fn ($value) => $this->toYmdString($value)
        );
    }

    /** start_date: simpan Y-m-d bila bisa, get selalu string apa adanya atau null */
    protected function startDate(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : (string) $value,
            set: fn ($value) => $this->toYmdString($value)
        );
    }

    /** end_date: simpan Y-m-d bila bisa, get selalu string apa adanya atau null */
    protected function endDate(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : (string) $value,
            set: fn ($value) => $this->toYmdString($value)
        );
    }

    /* ============================================================
     |  Scopes (query helper)
     * ============================================================ */
    public function scopeStatus($query, string $status)
    {
        return $query->where('internship_status', $status);
    }

    public function scopeIsNew($query)     { return $query->status(self::STATUS_WAITING); }
    public function scopeActive($query)    { return $query->status(self::STATUS_ACTIVE); }
    public function scopeCompleted($query) { return $query->status(self::STATUS_COMPLETED); }
    public function scopeExited($query)    { return $query->status(self::STATUS_EXITED); }
    public function scopePending($query)   { return $query->status(self::STATUS_PENDING); }
    public function scopeAccepted($query)  { return $query->status(self::STATUS_ACCEPTED); }
    public function scopeRejected($query)  { return $query->status(self::STATUS_REJECTED); }

    /* ============================================================
     |  Helpers untuk UI (label & badge)
     * ============================================================ */
    public function getStatusLabelAttribute(): string
    {
        return [
            self::STATUS_WAITING    => 'Menunggu',
            self::STATUS_ACTIVE     => 'Aktif',
            self::STATUS_COMPLETED  => 'Selesai',
            self::STATUS_EXITED     => 'Keluar',
            self::STATUS_PENDING    => 'Pending',
            self::STATUS_ACCEPTED   => 'Diterima',
            self::STATUS_REJECTED   => 'Ditolak',
        ][$this->internship_status] ?? ucfirst((string) $this->internship_status);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return [
            self::STATUS_WAITING    => 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-200',
            self::STATUS_ACTIVE     => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
            self::STATUS_COMPLETED  => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
            self::STATUS_EXITED     => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
            self::STATUS_PENDING    => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            self::STATUS_ACCEPTED   => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
            self::STATUS_REJECTED   => 'bg-gray-200 text-gray-700 dark:bg-gray-800/60 dark:text-gray-200',
        ][$this->internship_status] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
    }

    public function getFollowupStatusLabelAttribute(): ?string
    {
        return $this->followup_status !== null
            ? (self::FOLLOWUP_STATUS_LABELS[$this->followup_status] ?? (string) $this->followup_status)
            : null;
    }

    public function getFollowupStatusBadgeClassAttribute(): string
    {
        return match ($this->followup_status) {
            self::FOLLOWUP_NOT_FOLLOWED_UP       => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            self::FOLLOWUP_AWAITING_AGREEMENT    => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
            self::FOLLOWUP_MOVED_TO_PARTICIPANTS => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
            self::FOLLOWUP_INTERNSHIP_STARTED    => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
            self::FOLLOWUP_INTERNSHIP_CANCELLED  => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
            self::FOLLOWUP_NOT_YET_ACC           => 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200',
            self::FOLLOWUP_INTERNSHIP_COMPLETED  => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
            self::FOLLOWUP_AWAITING_DATES        => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200',
            default                              => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        };
    }

    protected ?array $resolvedConditionCache = null;

    public function invalidateConditionCache(): self
    {
        $this->resolvedConditionCache = null;
        return $this;
    }

    public function setResolvedCondition(array $condition): self
    {
        $this->resolvedConditionCache = $condition;
        return $this;
    }

    public function getResolvedCondition(?\Illuminate\Support\Collection $preloadedLogs = null): array
    {
        if ($this->resolvedConditionCache === null) {
            $this->resolvedConditionCache = app(\App\Services\RegistrationLifecycleService::class)
                ->resolveCurrentCondition($this, $preloadedLogs);
        }

        return $this->resolvedConditionCache;
    }

    public function getCurrentConditionLabelAttribute(): string
    {
        return $this->getResolvedCondition()['condition_label'];
    }

    public function getCurrentConditionCaseAttribute(): string
    {
        return $this->getResolvedCondition()['case'];
    }

    public function scopeFollowupStatus($query, ?string $status)
    {
        return $query->where('followup_status', $status);
    }

    /* ============================================================
     |  Virtual Attributes dari Relasi
     * ============================================================ */
    public function getOwnedToolsAttribute(): string
    {
        return $this->tools->pluck('tool_name')->implode(', ');
    }

    public function getLaptopEquipmentAttribute(): string
    {
        return '';
    }

    public function getInternshipInfoSourcesAttribute(): string
    {
        return $this->infoSources->pluck('source_name')->implode(', ');
    }

    public function getDesignSoftwareAttribute(): string
    {
        return $this->skills->where('skill_category', 'design')->pluck('skill_name')->implode(', ');
    }

    public function getVideoSoftwareAttribute(): string
    {
        return $this->skills->where('skill_category', 'video')->pluck('skill_name')->implode(', ');
    }

    public function getProgrammingLanguagesAttribute(): string
    {
        return $this->skills->where('skill_category', 'programming')->pluck('skill_name')->implode(', ');
    }

    public function getDigitalMarketingTypeAttribute(): string
    {
        return $this->skills->where('skill_category', 'digital_marketing')->pluck('skill_name')->implode(', ');
    }

    /* ============================================================
     |  Ringkasan cepat untuk dashboard
     * ============================================================ */
    public static function countsByStatus(): array
    {
        return [
            'waiting'   => static::status(self::STATUS_WAITING)->count(),
            'active'    => static::status(self::STATUS_ACTIVE)->count(),
            'completed' => static::status(self::STATUS_COMPLETED)->count(),
            'exited'    => static::status(self::STATUS_EXITED)->count(),
            'pending'   => static::status(self::STATUS_PENDING)->count(),
            'accepted'  => static::status(self::STATUS_ACCEPTED)->count(),
            'rejected'  => static::status(self::STATUS_REJECTED)->count(),
        ];
    }

    // app/Models/InternshipRegistration.php

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id')->withTrashed();
    }

    public function city()
    {
        return $this->belongsTo(\App\Models\City::class, 'city_id');
    }

    public function institution()
    {
        return $this->belongsTo(\App\Models\Institution::class, 'institution_id');
    }

    public function attachments()
    {
        return $this->hasMany(\App\Models\RegistrationAttachment::class, 'internship_registration_id');
    }

    public function rowSyncs()
    {
        return $this->morphMany(\App\Models\SpreadsheetRowSync::class, 'syncable');
    }

    public function contactLinks()
    {
        return $this->hasMany(\App\Models\RegistrationContactLink::class, 'internship_registration_id');
    }

    public function institutionContacts()
    {
        return $this->belongsToMany(
            \App\Models\InstitutionContact::class,
            'registration_contact_links',
            'internship_registration_id',
            'institution_contact_id'
        )->withPivot(['public_uuid', 'relation_year', 'is_primary', 'source', 'confirmed_by', 'confirmed_at'])
         ->withTimestamps();
    }

    public function skills()
    {
        return $this->hasMany(\App\Models\InternSkill::class, 'internship_registration_id');
    }

    public function tools()
    {
        return $this->hasMany(\App\Models\InternTool::class, 'internship_registration_id');
    }

    public function infoSources()
    {
        return $this->hasMany(\App\Models\InternInfoSource::class, 'internship_registration_id');
    }

    public function studyProgramRel()
    {
        return $this->belongsTo(\App\Models\StudyProgram::class, 'study_program_id');
    }

    public function prodi()
    {
        return $this->studyProgramRel();
    }

    public function facultyRel()
    {
        return $this->belongsTo(\App\Models\Faculty::class, 'faculty_id');
    }

    public function faculty()
    {
        return $this->facultyRel();
    }

    public function getEmailAttribute()
    {
        return $this->user ? $this->user->email : null;
    }

    public function getCurrentCityAttribute()
    {
        return $this->city ? $this->city->name : null;
    }

    public function getInstitutionNameAttribute()
    {
        return $this->institution ? $this->institution->name : null;
    }

    public function getStudyProgramAttribute()
    {
        return $this->studyProgramRel ? $this->studyProgramRel->name : null;
    }

    public function getFacultyAttribute()
    {
        return $this->facultyRel ? $this->facultyRel->name : null;
    }

    public function division()
    {
        return $this->belongsTo(\App\Models\Division::class, 'division_id');
    }

    public function brandRel()
    {
        return $this->belongsTo(\App\Models\Brand::class, 'brand_id');
    }

    public function getInternshipInterestAttribute()
    {
        return $this->division ? $this->division->name : null;
    }

    public function getBrandAttribute()
    {
        return $this->brandRel ? $this->brandRel->name : null;
    }

    public function dailyReports()
    {
        return $this->hasMany(\App\Models\DailyReport::class, 'intern_id');
    }

    public function leaveRequests()
    {
        return $this->hasMany(\App\Models\LeaveRequest::class, 'intern_id');
    }

    public function pendingTasks()
    {
        return $this->hasMany(\App\Models\PendingTask::class, 'intern_id');
    }

    public function feedback()
    {
        return $this->hasOne(\App\Models\Feedback::class, 'intern_id');
    }

    public function assessment()
    {
        return $this->hasOne(\App\Models\InternAssessment::class, 'intern_id');
    }

    public function assessments()
    {
        return $this->hasMany(\App\Models\InternAssessment::class, 'intern_id');
    }

    public function membercards()
    {
        return $this->hasMany(\App\Models\AlumniMembercard::class, 'intern_id');
    }

    public function loas()
    {
        return $this->hasMany(\App\Models\InternLoa::class, 'intern_id');
    }

    public function skls()
    {
        return $this->hasMany(\App\Models\SklDocument::class, 'intern_id');
    }

    public function certificates()
    {
        return $this->hasMany(\App\Models\InternCertificate::class, 'intern_id');
    }

    public function hasDocuments(): bool
    {
        return $this->membercards()->exists()
            || $this->loas()->exists()
            || $this->skls()->exists()
            || $this->certificates()->exists()
            || $this->assessments()->exists();
    }

    /**
     * Memeriksa apakah pendaftaran berada pada status tertutup (riwayat masa lalu).
     */
    public function isClosed(): bool
    {
        return in_array($this->internship_status, [
            self::STATUS_COMPLETED,
            self::STATUS_EXITED,
            self::STATUS_REJECTED,
        ], true);
    }

    /**
     * Memeriksa apakah pendaftaran sedang aktif atau dalam proses.
     */
    public function isOpen(): bool
    {
        return in_array($this->internship_status, [
            self::STATUS_WAITING,
            self::STATUS_PENDING,
            self::STATUS_ACCEPTED,
            self::STATUS_ACTIVE,
        ], true);
    }

    public function formSnapshot(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\RegistrationFormSnapshot::class, 'registration_id');
    }

    public function customAnswers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\RegistrationCustomAnswer::class, 'registration_id');
    }

    public function sidecarSync(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\SpreadsheetSidecarSync::class, 'internship_registration_id');
    }

    /**
     * Accessor untuk is_draft. Bernilai true jika status kosong atau masih 'new'.
     */
    public function getIsDraftAttribute(): bool
    {
        return empty($this->internship_status) || $this->internship_status === self::STATUS_NEW;
    }

    /**
     * Pemetaan registrasi ke sistem eksternal (misal Absen Djuragan).
     */
    public function externalRegistrationMappings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ExternalRegistrationMapping::class, 'ims_registration_id');
    }
}

