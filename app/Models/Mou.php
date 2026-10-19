<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Mou extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_DRAFT      = 'draft';
    const STATUS_GENERATED  = 'generated';
    const STATUS_DELIVERED  = 'delivered';
    const STATUS_ACTIVE     = 'active';
    const STATUS_EXPIRED    = 'expired';
    const STATUS_SUPERSEDED = 'superseded';
    const STATUS_CANCELLED  = 'cancelled';

    protected $table = 'mous';

    protected $fillable = [
        'public_uuid',
        'institution_id',
        'followup_cycle_id',
        'mou_template_id',
        'company_mou_number',
        'institution_mou_number',
        'first_party_institution_name',
        'first_party_signatory_name',
        'first_party_signatory_title',
        'first_party_address',
        'first_party_phone',
        'first_party_email',
        'first_party_correspondence_unit',
        'second_party_company_name',
        'second_party_signatory_name',
        'second_party_signatory_title',
        'second_party_address',
        'second_party_phone',
        'second_party_email',
        'agreement_place',
        'agreement_date',
        'effective_start',
        'effective_end',
        'status',
        'rendered_document_html',
        'generated_file_path',
        'signed_file_path',
        'generated_at',
        'delivered_at',
        'signed_at',
        'delivery_method',
        'delivery_recipient',
        'delivery_notes',
        'generated_by',
        'delivered_by',
        'signed_uploaded_by',
        'render_data_snapshot',
        'notes',
        'supersedes_mou_id',
        'superseded_by_id',
        'superseded_at',
        'supersede_reason',
        'drive_file_id',
        'drive_web_view_link',
        'drive_synced_at',
    ];

    protected $casts = [
        'agreement_date'       => 'date',
        'effective_start'      => 'date',
        'effective_end'        => 'date',
        'generated_at'         => 'datetime',
        'delivered_at'         => 'datetime',
        'signed_at'            => 'datetime',
        'superseded_at'        => 'datetime',
        'drive_synced_at'      => 'datetime',
        'render_data_snapshot' => 'array',
    ];

    public function getIsDriveSyncedAttribute(): bool
    {
        return !empty($this->drive_file_id);
    }

    public function getStartDateAttribute()
    {
        return $this->effective_start;
    }

    public function getEndDateAttribute()
    {
        return $this->effective_end;
    }

    public function getInstitutionSignatoryNameAttribute()
    {
        return $this->first_party_signatory_name;
    }

    public function getInstitutionSignatoryPositionAttribute()
    {
        return $this->first_party_signatory_title;
    }


    protected static function booted(): void
    {
        static::creating(function (Mou $mou) {
            if (empty($mou->public_uuid)) {
                $mou->public_uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Menggunakan public_uuid untuk model binding di rute, mencegah ID auto-increment terekspos.
     */
    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    /**
     * Relasi ke institusi mitra.
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    /**
     * Relasi ke siklus follow-up yang mendasari MOU ini (jika ada).
     */
    public function followupCycle(): BelongsTo
    {
        return $this->belongsTo(FollowUpCycle::class, 'followup_cycle_id');
    }

    /**
     * Relasi ke template naskah MOU yang digunakan saat generate.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MouTemplate::class, 'mou_template_id');
    }

    /**
     * User yang men-generate naskah dokumen.
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * User yang mencatat serah terima dokumen.
     */
    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * User yang mengunggah naskah dokumen yang telah ditandatangani.
     */
    public function signedUploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_uploaded_by');
    }

    /**
     * MOU pengganti (jika dokumen ini berstatus superseded).
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Mou::class, 'superseded_by_id');
    }

    /**
     * MOU lama yang digantikan oleh dokumen ini.
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Mou::class, 'supersedes_mou_id');
    }

    /**
     * Relasi ke berkas GeneratedDocument terpusat.
     */
    public function generatedDocument(): HasOne
    {
        return $this->hasOne(GeneratedDocument::class, 'document_number', 'company_mou_number')
            ->where('document_type', GeneratedDocument::TYPE_MOU);
    }

    /**
     * Scope untuk MOU aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope untuk MOU yang masih dalam proses (generated atau delivered).
     */
    public function scopePendingAction($query)
    {
        return $query->whereIn('status', [self::STATUS_GENERATED, self::STATUS_DELIVERED]);
    }

    /**
     * Label status dalam Bahasa Indonesia.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT      => 'Konsep (Draft)',
            self::STATUS_GENERATED  => 'Naskah Dibuat (Generated)',
            self::STATUS_DELIVERED  => 'Terkirim ke Mitra (Delivered)',
            self::STATUS_ACTIVE     => 'Aktif Berlaku (Active)',
            self::STATUS_EXPIRED    => 'Masa Berlaku Berakhir (Expired)',
            self::STATUS_SUPERSEDED => 'Digantikan Dokumen Baru (Superseded)',
            self::STATUS_CANCELLED  => 'Dibatalkan (Cancelled)',
            default                 => ucfirst($this->status),
        };
    }

    /**
     * Format badge warna Tailwind untuk tampilan UI.
     */
    public function getStatusBadgeClassesAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::STATUS_GENERATED  => 'bg-blue-50 text-blue-700 border-blue-200',
            self::STATUS_DELIVERED  => 'bg-amber-50 text-amber-700 border-amber-200',
            self::STATUS_EXPIRED    => 'bg-rose-50 text-rose-700 border-rose-200',
            self::STATUS_SUPERSEDED => 'bg-slate-100 text-slate-600 border-slate-200',
            self::STATUS_CANCELLED  => 'bg-gray-100 text-gray-500 border-gray-200',
            default                 => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    }

    /**
     * Cek apakah dokumen ini berstatus aktif.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Cek apakah dokumen ini sudah memiliki berkas bertandatangan.
     */
    public function getHasSignedFileAttribute(): bool
    {
        return !empty($this->signed_file_path);
    }
}
