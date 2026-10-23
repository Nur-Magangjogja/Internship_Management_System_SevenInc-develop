<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormField extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'form_fields';

    protected $fillable = [
        'form_schema_id',
        'field_key',
        'field_type',
        'label',
        'placeholder',
        'options',
        'validation_rules',
        'is_required',
        'is_active',
        'is_archived',
        'is_system',
        'group_name',
        'sort_order',
        'column_span',
        'helper_text',
    ];

    protected $casts = [
        'options'          => 'array',
        'validation_rules' => 'array',
        'is_required'      => 'boolean',
        'is_active'        => 'boolean',
        'is_archived'      => 'boolean',
        'is_system'        => 'boolean',
        'sort_order'       => 'integer',
        'column_span'      => 'integer',
    ];

    public function schema(): BelongsTo
    {
        return $this->belongsTo(FormSchema::class, 'form_schema_id');
    }

    protected static function booted(): void
    {
        static::updating(function (FormField $field) {
            // Jika field berelasi dengan skema yang telah dipublikasikan atau diarsipkan
            if ($field->schema && ($field->schema->isPublished() || $field->schema->isArchived())) {
                // Perubahan struktural kritis yang merusak integrasi dan data jawaban dilarang keras.
                // Perubahan teks tampilan (label, placeholder, helper_text) dan opsi non-destruktif diizinkan agar tidak mengganggu sistem.
                $forbiddenDirty = [
                    'field_key', 'field_type', 'form_schema_id'
                ];
                if ($field->isDirty($forbiddenDirty)) {
                    throw new \DomainException("Field pada skema formulir yang telah dipublikasikan atau diarsipkan tidak boleh diubah kunci (field_key) atau tipe datanya (field_type). Modifikasi struktural harus dilakukan melalui draf versi penerus.");
                }
            }

            // Jika field_key telah dikunci di registry, validasi bahwa field_type tidak diubah secara inkompatibel
            $registry = \App\Models\FormFieldRegistry::where('field_key', $field->field_key)->first();
            if ($registry && $registry->is_locked && $field->isDirty('field_type') && $field->field_type !== $registry->field_type) {
                throw new \DomainException("Tipe field untuk '{$field->field_key}' telah dikunci permanen sebagai '{$registry->field_type}'.");
            }
        });

        static::deleting(function (FormField $field) {
            if ($field->schema && ($field->schema->isPublished() || $field->schema->isArchived())) {
                throw new \DomainException("Field pada skema formulir yang telah atau pernah dipublikasikan dilarang dihapus. Gunakan arsipkan.");
            }

            if ($field->isForceDeleting()) {
                $hasAnswers = \App\Models\RegistrationCustomAnswer::where('field_key', $field->field_key)->exists();
                $isLocked = \App\Models\FormFieldRegistry::where('field_key', $field->field_key)->where('is_locked', true)->exists();
                if ($hasAnswers || $isLocked) {
                    throw new \DomainException("Field '{$field->field_key}' memiliki rekam jejak historis atau jawaban dan tidak boleh dihapus permanen.");
                }
            }
        });
    }

    /** Scope: hanya field aktif dan tidak diarsipkan, diurutkan */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_archived', false)->orderBy('sort_order');
    }

    /** Scope: belum diarsipkan */
    public function scopeNotArchived($query)
    {
        return $query->where('is_archived', false);
    }

    /** Scope: dalam skema tertentu */
    public function scopeInSchema($query, $schemaId)
    {
        return $query->where('form_schema_id', $schemaId);
    }

    public const GROUP_DATA_DIRI = 'data_diri_edukasi';
    public const GROUP_PILIHAN_DURASI = 'pilihan_durasi';
    public const GROUP_BERKAS = 'berkas_portofolio';
    public const GROUP_INFORMASI_TAMBAHAN = 'informasi_tambahan';

    /**
     * Deteksi grup default untuk suatu field berdasarkan key dan tipenya.
     */
    public static function detectDefaultGroup(?string $key, ?string $type = null): string
    {
        $dataDiriKeys = [
            'fullname', 'student_id', 'born_date',
            'gender', 'religion', 'institution_name', 'faculty',
            'study_program', 'email', 'phone_number',
            'current_status',
        ];
        $pilihanDurasiKeys = [
            'internship_interest', 'start_date', 'end_date', 'planned_duration',
            'internship_type', 'wfo_readiness', 'schedule_shift_readiness',
            'has_laptop', 'owned_tools',
            'internship_reason', 'design_software', 'video_software',
            'programming_languages', 'digital_marketing_type',
        ];
        $berkasKeys = [
            'cv', 'identity', 'portfolio', 'cover_letter', 'proposal',
            'supporting_document', 'profile_photo', 'cv_ktp_portofolio_pdf',
        ];
        $infoTambahanKeys = [
            'supervisor_name', 'supervisor_contact', 'boarding_info',
            'social_media_instagram', 'internship_info_sources', 'current_activities',
        ];

        if ($key && in_array($key, $dataDiriKeys, true)) {
            return self::GROUP_DATA_DIRI;
        }
        if ($key && in_array($key, $pilihanDurasiKeys, true)) {
            return self::GROUP_PILIHAN_DURASI;
        }
        if ($key && in_array($key, $berkasKeys, true)) {
            return self::GROUP_BERKAS;
        }
        if ($type === 'file') {
            return self::GROUP_BERKAS;
        }
        if ($key && in_array($key, $infoTambahanKeys, true)) {
            return self::GROUP_INFORMASI_TAMBAHAN;
        }

        return self::GROUP_INFORMASI_TAMBAHAN;
    }

    /**
     * Resolusi nama grup field secara cerdas dan kompatibel ke belakang.
     */
    public function getResolvedGroupAttribute(): string
    {
        if (!empty($this->group_name)) {
            if ($this->group_name === self::GROUP_DATA_DIRI
                || $this->group_name === self::GROUP_PILIHAN_DURASI
                || $this->group_name === self::GROUP_BERKAS
                || $this->group_name === self::GROUP_INFORMASI_TAMBAHAN) {
                return $this->group_name;
            }
            if ($this->group_name === 'informasi_tambahan') {
                return self::GROUP_INFORMASI_TAMBAHAN;
            }
        }

        return static::detectDefaultGroup($this->field_key, $this->field_type);
    }

    /** Scope: berdasarkan grup */
    public function scopeInGroup($query, ?string $group)
    {
        if (empty($group)) {
            return $query;
        }
        return $query->where('group_name', $group);
    }

    /**
     * Semua tipe field yang didukung.
     */
    public static function supportedTypes(): array
    {
        return [
            'text'     => 'Text',
            'email'    => 'Email',
            'tel'      => 'Telepon',
            'date'     => 'Tanggal',
            'textarea' => 'Teks Panjang (Textarea)',
            'select'   => 'Dropdown (Select)',
            'radio'    => 'Pilihan Tunggal (Radio)',
            'checkbox' => 'Pilihan Banyak (Checkbox)',
            'file'     => 'Upload File',
            'number'   => 'Angka (Number)',
            'url'      => 'Tautan Web (URL)',
        ];
    }

    /**
     * Daftar grup yang tersedia dengan penomoran berurutan.
     */
    public static function groups(): array
    {
        return [
            self::GROUP_DATA_DIRI          => '1. Data Diri & Edukasi',
            self::GROUP_PILIHAN_DURASI     => '2. Pilihan & Durasi',
            self::GROUP_BERKAS             => '3. Berkas & Portofolio',
            self::GROUP_INFORMASI_TAMBAHAN => '4. Informasi Tambahan',
        ];
    }

    /**
     * Label grup tanpa penomoran.
     */
    public static function groupLabels(): array
    {
        return [
            self::GROUP_DATA_DIRI          => 'Data Diri & Edukasi',
            self::GROUP_PILIHAN_DURASI     => 'Pilihan & Durasi',
            self::GROUP_BERKAS             => 'Berkas & Portofolio',
            self::GROUP_INFORMASI_TAMBAHAN => 'Informasi Tambahan',
        ];
    }

    /**
     * Periksa apakah field masih bersifat sementara/draft (belum pernah dipublikasikan)
     * sehingga masih diizinkan untuk dihapus.
     * Field yang sudah dipublikasikan atau field sistem tidak dapat dihapus sama sekali.
     */
    public function isDeletable(): bool
    {
        if ($this->is_system) {
            return false;
        }

        if ($this->schema && ($this->schema->isPublished() || $this->schema->isArchived())) {
            return false;
        }

        try {
            $isLockedInRegistry = FormFieldRegistry::where('field_key', $this->field_key)
                ->where('is_locked', true)
                ->exists();
            if ($isLockedInRegistry) {
                return false;
            }

            $existsInPublishedSchema = static::where('field_key', $this->field_key)
                ->whereHas('schema', function ($q) {
                    $q->whereIn('status', [FormSchema::STATUS_PUBLISHED, FormSchema::STATUS_ARCHIVED]);
                })
                ->exists();
            if ($existsInPublishedSchema) {
                return false;
            }

            $hasAnswers = RegistrationCustomAnswer::where('field_key', $this->field_key)->exists();
            if ($hasAnswers) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Cek apakah struktur field (field_key dan field_type) sudah dikunci permanen
     * karena sudah pernah dipublikasikan, memiliki jawaban, atau merupakan field sistem.
     * Field yang terkunci hanya dapat diedit teks tampilannya (label, placeholder, helper_text).
     */
    public function isStructuralLocked(): bool
    {
        if ($this->is_system) {
            return true;
        }

        if ($this->schema && ($this->schema->isPublished() || $this->schema->isArchived())) {
            return true;
        }

        try {
            $isLockedInRegistry = FormFieldRegistry::where('field_key', $this->field_key)
                ->where('is_locked', true)
                ->exists();
            if ($isLockedInRegistry) {
                return true;
            }

            $existsInPublishedSchema = static::where('field_key', $this->field_key)
                ->whereHas('schema', function ($q) {
                    $q->whereIn('status', [FormSchema::STATUS_PUBLISHED, FormSchema::STATUS_ARCHIVED]);
                })
                ->exists();
            if ($existsInPublishedSchema) {
                return true;
            }

            $hasAnswers = RegistrationCustomAnswer::where('field_key', $this->field_key)->exists();
            if ($hasAnswers) {
                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
