<?php

namespace App\Services;

use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class FormBuilderService
{
    public const ALLOWED_VALIDATION_RULE_KEYS = [
        'min_length',
        'max_length',
        'min_value',
        'max_value',
        'min_date',
        'max_date',
        'allowed_extensions',
        'max_file_size_kb',
        'min_items',
        'max_items',
    ];

    public const CORE_FIELD_KEYS = [
        'fullname', 'student_id', 'born_date', 'birth_place', 'religion', 'institution_name', 'faculty',
        'study_program', 'current_city', 'gender', 'phone_number', 'email',
        'internship_interest', 'start_date', 'end_date', 'internship_type',
        'internship_arrangement', 'current_status', 'wfo_readiness',
        'schedule_shift_readiness', 'has_laptop', 'planned_duration',
        'cv_ktp_portofolio_pdf', 'profile_photo', 'cv', 'identity', 'portfolio',
        'cover_letter', 'proposal', 'supporting_document', 'design_software',
        'video_software', 'programming_languages', 'digital_marketing_type',
        'owned_tools', 'supervisor_name', 'supervisor_contact',
        'current_activities', 'boarding_info', 'social_media_instagram',
        'internship_info_sources',
    ];

    public const SPREADSHEET_RESERVED_KEYS = [
        'timestamp', 'nama_lengkap', 'nim', 'program_studi', 'email', 'jenis_kelamin',
        'no_hp', 'asal_sekolah', 'asal_kampus', 'kota_tinggal', 'alasan_magang',
        'jenis_magang', 'status_saat_ini', 'kesiapan_wfo', 'dosen_pembimbing',
        'kesanggupan_jadwal', 'laptop_sendiri', 'alat_dimiliki', 'rencana_mulai',
        'durasi_magang', 'sumber_info', 'instagram', 'info_kost', 'program_minat',
        'upload_pdf', 'ket', 'sertif_sync_id', 'sertif_registration_id',
        'sertif_sync_status', 'sertif_last_sync_at', 'form_schema_version',
    ];

    protected ?int $customGracePeriodMinutes = null;

    public function setGracePeriodMinutes(?int $minutes): self
    {
        $this->customGracePeriodMinutes = $minutes !== null ? max(0, $minutes) : null;
        return $this;
    }

    public function setGracePeriodDays(?int $days): self
    {
        $this->customGracePeriodMinutes = $days !== null ? max(0, $days * 1440) : null;
        return $this;
    }

    public function resetGracePeriodOverride(): self
    {
        $this->customGracePeriodMinutes = null;
        return $this;
    }

    public function getEffectiveGracePeriodMinutes(?FormSchema $schema = null, ?int $explicitMinutes = null): int
    {
        if ($explicitMinutes !== null) {
            return max(0, $explicitMinutes);
        }

        if ($this->customGracePeriodMinutes !== null) {
            return $this->customGracePeriodMinutes;
        }

        if ($schema && $schema->grace_period_days !== null && $schema->grace_period_days >= 0) {
            return (int) ($schema->grace_period_days * 1440);
        }

        return (int) (config('internship.form_grace_period_days', 7) * 1440);
    }

    /**
     * Eksekusi callable dengan override grace period sementara yang otomatis di-reset (S17.18G.4R Fix 3).
     */
    public function runWithGracePeriodOverride(?int $minutes, callable $callback): mixed
    {
        $previous = $this->customGracePeriodMinutes;
        $this->customGracePeriodMinutes = $minutes !== null ? max(0, $minutes) : null;
        try {
            return $callback($this);
        } finally {
            $this->customGracePeriodMinutes = $previous;
        }
    }

    public function getGracePeriodMinutes(): int
    {
        return $this->getEffectiveGracePeriodMinutes();
    }

    /**
     * Dapatkan atau buat draf skema formulir kerja untuk tahun tertentu.
     */
    public function getOrCreateDraft(int $year = 2026, ?User $creator = null): FormSchema
    {
        $existingDraft = FormSchema::where('year', $year)
            ->where('status', FormSchema::STATUS_DRAFT)
            ->first();

        if ($existingDraft) {
            return $existingDraft;
        }

        // Jika belum ada draf, cek apakah ada versi published aktif
        $activePublished = FormSchema::where('year', $year)
            ->where('status', FormSchema::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->with('fields')
            ->first();

        if ($activePublished) {
            return $this->clonePublishedToDraft($activePublished, $creator);
        }

        // Jika sama sekali belum ada skema, buat draf pertama
        return FormSchema::create([
            'version_code' => "v{$year}.1-draft",
            'year'         => $year,
            'title'        => "Formulir Pendaftaran Magang {$year}",
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => false,
            'created_by'   => $creator?->id,
        ]);
    }

    /**
     * Pastikan formulir tahun berjalan (Tahun Ini) dan tahun berikutnya (Tahun Depan)
     * memiliki daftar pertanyaan awal yang lengkap.
     * - Untuk Tahun Ini ($year <= tahun sekarang): otomatis mengisi field default (atau menyalin dari tahun sebelumnya saat pergantian tahun)
     *   dan menerbitkan versi dasar pertama jika belum pernah diterbitkan sama sekali.
     * - Untuk Tahun Depan ($year > tahun sekarang): otomatis menyalin struktur dari formulir Tahun Ini agar mirip dan siap diedit.
     */
    public function ensureYearFormInitialized(int $year, ?User $creator = null, bool $forceInTests = false): FormSchema
    {
        if (app()->runningUnitTests() && !$forceInTests) {
            return $this->getOrCreateDraft($year, $creator);
        }

        $currentYear = (int) now()->year;

        // Jika menginisialisasi tahun depan, pastikan tahun berjalan sudah terinisialisasi lebih dulu
        if ($year > $currentYear) {
            $this->initializeSingleYearSchema($currentYear, $creator);
        }

        return $this->initializeSingleYearSchema($year, $creator);
    }

    protected function initializeSingleYearSchema(int $year, ?User $creator = null): FormSchema
    {
        $currentYear = (int) now()->year;
        $draft = $this->getOrCreateDraft($year, $creator);

        // 1. Jika draf masih kosong (0 field), isi dari skema tahun sebelumnya atau dari field default
        if ($draft->fields()->withTrashed()->count() === 0) {
            $previousYearSchema = FormSchema::where('year', $year - 1)
                ->where('status', FormSchema::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->with('fields')
                ->first();

            if (!$previousYearSchema || $previousYearSchema->fields->isEmpty()) {
                $previousYearSchema = FormSchema::where('year', $year - 1)
                    ->with('fields')
                    ->orderByDesc('id')
                    ->first();
            }

            if ($previousYearSchema && $previousYearSchema->fields()->where('is_archived', false)->count() > 0) {
                $this->copyFieldsFromSchemaToDraft($previousYearSchema, $draft);
            } else {
                $this->seedDefaultFieldsIntoDraft($draft);
            }
        } else {
            // Pastikan seluruh field sistem bawaan dari FormFieldSeeder selalu lengkap (backfill jika ada yang sempat belum masuk)
            $this->backfillMissingDefaultFields($draft);

            if ($year > $currentYear) {
                // Untuk form Tahun Depan yang belum pernah dipublikasikan, sinkronkan field baru yang sudah dipublikasikan di Tahun Ini
                $hasYearPublished = FormSchema::where('year', $year)
                    ->whereIn('status', [FormSchema::STATUS_PUBLISHED, FormSchema::STATUS_ARCHIVED])
                    ->exists();

                if (!$hasYearPublished) {
                    $currentPublished = FormSchema::where('year', $currentYear)
                        ->where('status', FormSchema::STATUS_PUBLISHED)
                        ->where('is_active', true)
                        ->with('fields')
                        ->first();

                    if ($currentPublished) {
                        foreach ($currentPublished->fields()->where('is_archived', false)->get() as $srcField) {
                            $existsInDraft = $draft->fields()->withTrashed()->where('field_key', $srcField->field_key)->exists();
                            if (!$existsInDraft) {
                                FormField::create([
                                    'form_schema_id'   => $draft->id,
                                    'field_key'        => $srcField->field_key,
                                    'field_type'       => $srcField->field_type,
                                    'label'            => $srcField->label,
                                    'placeholder'      => $srcField->placeholder,
                                    'helper_text'      => $srcField->helper_text,
                                    'options'          => $srcField->options,
                                    'validation_rules' => $srcField->validation_rules,
                                    'is_required'      => $srcField->is_required,
                                    'is_active'        => $srcField->is_active,
                                    'is_archived'      => false,
                                    'is_system'        => $srcField->is_system,
                                    'group_name'       => $srcField->group_name,
                                    'sort_order'       => $srcField->sort_order,
                                    'column_span'      => $srcField->column_span,
                                ]);
                            }
                        }
                    }
                }
            }
        }

        // 2. Jika ini adalah Tahun Ini (atau saat pergantian tahun masuk ke tahun baru)
        // dan belum ada versi yang terbit sama sekali, publikasikan baseline awal secara otomatis
        // sehingga form langsung aktif dan field bawaan terkunci dari penghapusan.
        if ($year <= $currentYear) {
            $activePublished = FormSchema::where('year', $year)
                ->where('status', FormSchema::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->first();

            if ($activePublished) {
                $this->backfillMissingDefaultFields($activePublished);
            }

            $hasAnyPublishedForYear = FormSchema::where('year', $year)
                ->whereIn('status', [FormSchema::STATUS_PUBLISHED, FormSchema::STATUS_ARCHIVED])
                ->exists();

            if (!$hasAnyPublishedForYear && $draft->fields()->count() > 0) {
                $this->publishVersion($draft, $creator);
                $draft = $this->getOrCreateDraft($year, $creator);
            }
        }

        return $draft;
    }

    /**
     * Lengkapi field sistem bawaan yang belum ada pada skema eksisting tanpa menimpa kustomisasi admin.
     */
    protected function backfillMissingDefaultFields(FormSchema $schema): void
    {
        $defaultFields = \Database\Seeders\FormFieldSeeder::getDefaultFields();
        $existingByKey = $schema->fields()->withTrashed()->get()->keyBy('field_key');

        foreach ($defaultFields as $fieldDef) {
            $key = $fieldDef['field_key'];
            $existing = $existingByKey->get($key);

            if (!$existing) {
                FormField::create([
                    'form_schema_id'   => $schema->id,
                    'field_key'        => $key,
                    'field_type'       => $fieldDef['field_type'],
                    'label'            => $fieldDef['label'],
                    'placeholder'      => $fieldDef['placeholder'] ?? null,
                    'helper_text'      => $fieldDef['helper_text'] ?? null,
                    'options'          => $fieldDef['options'] ?? null,
                    'validation_rules' => $fieldDef['validation_rules'] ?? null,
                    'is_required'      => (bool) ($fieldDef['is_required'] ?? false),
                    'is_active'        => (bool) ($fieldDef['is_active'] ?? true),
                    'is_archived'      => false,
                    'is_system'        => (bool) ($fieldDef['is_system'] ?? true),
                    'group_name'       => $fieldDef['group_name'] ?? null,
                    'sort_order'       => (int) ($fieldDef['sort_order'] ?? 10),
                    'column_span'      => (int) ($fieldDef['column_span'] ?? 1),
                ]);
            } elseif ($key === 'has_laptop' && is_array($existing->options)) {
                // Pastikan opsi has_laptop mendukung nilai '1'/'0' sesuai validasi controller
                $vals = collect($existing->options)->pluck('value')->all();
                if (in_array('Ya', $vals, true) && !in_array('1', $vals, true)) {
                    $existing->update(['options' => $fieldDef['options']]);
                }
            }
        }
    }

    /**
     * Salin / sinkronkan struktur pertanyaan dari Tahun Ini ke draf Tahun Depan.
     * Mempertahankan pertanyaan sementara (belum publish) yang sudah ditambahkan khusus di Tahun Depan.
     */
    public function syncDraftFromPreviousYear(int $targetYear, ?User $actor = null): FormSchema
    {
        $sourceYear = $targetYear - 1;
        $this->initializeSingleYearSchema($sourceYear, $actor);

        $sourceSchema = FormSchema::where('year', $sourceYear)
            ->where('status', FormSchema::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->with('fields')
            ->first()
            ?? FormSchema::where('year', $sourceYear)->with('fields')->orderByDesc('id')->first();

        $draft = $this->getOrCreateDraft($targetYear, $actor);

        if (!$sourceSchema) {
            return $draft;
        }

        return DB::transaction(function () use ($sourceSchema, $draft) {
            foreach ($sourceSchema->fields()->where('is_archived', false)->get() as $srcField) {
                $existing = $draft->fields()->withTrashed()->where('field_key', $srcField->field_key)->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update([
                        'label'            => $srcField->label,
                        'placeholder'      => $srcField->placeholder,
                        'helper_text'      => $srcField->helper_text,
                        'options'          => $srcField->options,
                        'validation_rules' => $srcField->validation_rules,
                        'is_required'      => $srcField->is_required,
                        'is_active'        => $srcField->is_active,
                        'is_archived'      => false,
                        'group_name'       => $srcField->group_name,
                        'sort_order'       => $srcField->sort_order,
                        'column_span'      => $srcField->column_span,
                    ]);
                } else {
                    FormField::create([
                        'form_schema_id'   => $draft->id,
                        'field_key'        => $srcField->field_key,
                        'field_type'       => $srcField->field_type,
                        'label'            => $srcField->label,
                        'placeholder'      => $srcField->placeholder,
                        'helper_text'      => $srcField->helper_text,
                        'options'          => $srcField->options,
                        'validation_rules' => $srcField->validation_rules,
                        'is_required'      => $srcField->is_required,
                        'is_active'        => $srcField->is_active,
                        'is_archived'      => false,
                        'is_system'        => $srcField->is_system,
                        'group_name'       => $srcField->group_name,
                        'sort_order'       => $srcField->sort_order,
                        'column_span'      => $srcField->column_span,
                    ]);
                }
            }

            return $draft->fresh();
        });
    }

    protected function copyFieldsFromSchemaToDraft(FormSchema $sourceSchema, FormSchema $targetDraft): void
    {
        foreach ($sourceSchema->fields()->where('is_archived', false)->orderBy('sort_order')->get() as $field) {
            FormField::create([
                'form_schema_id'   => $targetDraft->id,
                'field_key'        => $field->field_key,
                'field_type'       => $field->field_type,
                'label'            => $field->label,
                'placeholder'      => $field->placeholder,
                'helper_text'      => $field->helper_text,
                'options'          => $field->options,
                'validation_rules' => $field->validation_rules,
                'is_required'      => $field->is_required,
                'is_active'        => $field->is_active,
                'is_archived'      => false,
                'is_system'        => $field->is_system,
                'group_name'       => $field->group_name,
                'sort_order'       => $field->sort_order,
                'column_span'      => $field->column_span,
            ]);
        }
    }

    protected function seedDefaultFieldsIntoDraft(FormSchema $draft): void
    {
        $defaultFields = \Database\Seeders\FormFieldSeeder::getDefaultFields();
        $legacyByKey = FormField::whereNull('form_schema_id')->get()->keyBy('field_key');

        foreach ($defaultFields as $fieldDef) {
            $key = $fieldDef['field_key'];
            $legacy = $legacyByKey->get($key);

            FormField::create([
                'form_schema_id'   => $draft->id,
                'field_key'        => $key,
                'field_type'       => $fieldDef['field_type'],
                'label'            => $legacy?->label ?? $fieldDef['label'],
                'placeholder'      => $legacy?->placeholder ?? ($fieldDef['placeholder'] ?? null),
                'helper_text'      => $legacy?->helper_text ?? ($fieldDef['helper_text'] ?? null),
                'options'          => $legacy?->options ?? ($fieldDef['options'] ?? null),
                'validation_rules' => $legacy?->validation_rules ?? ($fieldDef['validation_rules'] ?? null),
                'is_required'      => $legacy !== null ? (bool) $legacy->is_required : (bool) ($fieldDef['is_required'] ?? false),
                'is_active'        => $legacy !== null ? (bool) $legacy->is_active : (bool) ($fieldDef['is_active'] ?? true),
                'is_archived'      => false,
                'is_system'        => (bool) ($fieldDef['is_system'] ?? true),
                'group_name'       => $fieldDef['group_name'] ?? null,
                'sort_order'       => (int) ($fieldDef['sort_order'] ?? 10),
                'column_span'      => (int) ($fieldDef['column_span'] ?? 1),
            ]);
        }
    }

    /**
     * Klon skema published menjadi draf kerja baru.
     */
    public function clonePublishedToDraft(FormSchema $published, ?User $creator = null): FormSchema
    {
        if (!$published->isPublished()) {
            throw new DomainException("Hanya skema yang telah dipublikasikan yang dapat dikloning menjadi draf baru.");
        }

        return DB::transaction(function () use ($published, $creator) {
            $nextVersionCode = $this->generateNextDraftVersionCode($published);

            $draft = FormSchema::create([
                'version_code' => $nextVersionCode,
                'year'         => $published->year,
                'title'        => $published->title,
                'status'       => FormSchema::STATUS_DRAFT,
                'schema_hash'  => null,
                'is_active'    => false,
                'published_at' => null,
                'published_by' => null,
                'created_by'   => $creator?->id,
            ]);

            // Klon semua field aktif yang belum diarsipkan
            foreach ($published->fields()->where('is_archived', false)->get() as $field) {
                FormField::create([
                    'form_schema_id'   => $draft->id,
                    'field_key'        => $field->field_key,
                    'field_type'       => $field->field_type,
                    'label'            => $field->label,
                    'placeholder'      => $field->placeholder,
                    'helper_text'      => $field->helper_text,
                    'options'          => $field->options,
                    'validation_rules' => $field->validation_rules,
                    'is_required'      => $field->is_required,
                    'is_active'        => $field->is_active,
                    'is_archived'      => false,
                    'is_system'        => $field->is_system,
                    'group_name'       => $field->group_name,
                    'sort_order'       => $field->sort_order,
                    'column_span'      => $field->column_span,
                ]);
            }

            return $draft;
        });
    }

    /**
     * Validasi struktur dan nilai aturan validasi form field (allowlist enforcement).
     */
    public function validateFieldValidationRulesStructure(?array $rules, string $fieldType): ?array
    {
        if ($rules === null || empty($rules)) {
            return null;
        }

        foreach ($rules as $ruleKey => $ruleVal) {
            if (!in_array($ruleKey, self::ALLOWED_VALIDATION_RULE_KEYS, true)) {
                throw new InvalidArgumentException("Aturan validasi '{$ruleKey}' tidak didukung atau tidak ada dalam allowlist.");
            }
        }

        // min_length & max_length (text, textarea, email, tel, url)
        if (isset($rules['min_length'])) {
            if (!is_int($rules['min_length']) || $rules['min_length'] < 0) {
                throw new InvalidArgumentException("Aturan 'min_length' harus berupa bilangan bulat >= 0.");
            }
        }
        if (isset($rules['max_length'])) {
            if (!is_int($rules['max_length']) || $rules['max_length'] < 0) {
                throw new InvalidArgumentException("Aturan 'max_length' harus berupa bilangan bulat >= 0.");
            }
        }
        if (isset($rules['min_length'], $rules['max_length']) && $rules['min_length'] > $rules['max_length']) {
            throw new InvalidArgumentException("Aturan 'min_length' ({$rules['min_length']}) tidak boleh lebih besar dari 'max_length' ({$rules['max_length']}).");
        }

        // min_value & max_value (number)
        if (isset($rules['min_value'])) {
            if (!is_numeric($rules['min_value'])) {
                throw new InvalidArgumentException("Aturan 'min_value' harus berupa angka.");
            }
        }
        if (isset($rules['max_value'])) {
            if (!is_numeric($rules['max_value'])) {
                throw new InvalidArgumentException("Aturan 'max_value' harus berupa angka.");
            }
        }
        if (isset($rules['min_value'], $rules['max_value']) && floatval($rules['min_value']) > floatval($rules['max_value'])) {
            throw new InvalidArgumentException("Aturan 'min_value' ({$rules['min_value']}) tidak boleh lebih besar dari 'max_value' ({$rules['max_value']}).");
        }

        // min_date & max_date (date)
        if (isset($rules['min_date'])) {
            $d = \DateTime::createFromFormat('Y-m-d', (string)$rules['min_date']);
            if (!$d || $d->format('Y-m-d') !== (string)$rules['min_date']) {
                throw new InvalidArgumentException("Aturan 'min_date' harus berformat tanggal YYYY-MM-DD.");
            }
        }
        if (isset($rules['max_date'])) {
            $d = \DateTime::createFromFormat('Y-m-d', (string)$rules['max_date']);
            if (!$d || $d->format('Y-m-d') !== (string)$rules['max_date']) {
                throw new InvalidArgumentException("Aturan 'max_date' harus berformat tanggal YYYY-MM-DD.");
            }
        }
        if (isset($rules['min_date'], $rules['max_date']) && $rules['min_date'] > $rules['max_date']) {
            throw new InvalidArgumentException("Aturan 'min_date' ({$rules['min_date']}) tidak boleh lebih lambat dari 'max_date' ({$rules['max_date']}).");
        }

        // allowed_extensions & max_file_size_kb (file)
        if (isset($rules['allowed_extensions'])) {
            if (!is_array($rules['allowed_extensions']) || empty($rules['allowed_extensions'])) {
                throw new InvalidArgumentException("Aturan 'allowed_extensions' harus berupa daftar ekstensi berkas (array non-kosong).");
            }
            $permittedExts = ['pdf', 'jpg', 'jpeg', 'png'];
            foreach ($rules['allowed_extensions'] as $ext) {
                if (!in_array(strtolower((string)$ext), $permittedExts, true)) {
                    throw new InvalidArgumentException("Ekstensi berkas '{$ext}' tidak didukung. Ekstensi yang diizinkan hanya: " . implode(', ', $permittedExts));
                }
            }
        }
        if (isset($rules['max_file_size_kb'])) {
            if (!is_int($rules['max_file_size_kb']) || $rules['max_file_size_kb'] <= 0 || $rules['max_file_size_kb'] > 5120) {
                throw new InvalidArgumentException("Aturan 'max_file_size_kb' harus berupa bilangan bulat antara 1 hingga 5120 KB (maksimal 5MB).");
            }
        }

        // min_items & max_items (checkbox)
        if (isset($rules['min_items'])) {
            if (!is_int($rules['min_items']) || $rules['min_items'] < 0) {
                throw new InvalidArgumentException("Aturan 'min_items' harus berupa bilangan bulat >= 0.");
            }
        }
        if (isset($rules['max_items'])) {
            if (!is_int($rules['max_items']) || $rules['max_items'] < 0) {
                throw new InvalidArgumentException("Aturan 'max_items' harus berupa bilangan bulat >= 0.");
            }
        }
        if (isset($rules['min_items'], $rules['max_items']) && $rules['min_items'] > $rules['max_items']) {
            throw new InvalidArgumentException("Aturan 'min_items' ({$rules['min_items']}) tidak boleh lebih besar dari 'max_items' ({$rules['max_items']}).");
        }

        return $rules;
    }

    /**
     * Tambah field baru ke draf skema dengan validasi identitas field_key permanen.
     */
    public function addFieldToDraft(FormSchema $draft, array $data): FormField
    {
        if (!$draft->isDraft()) {
            throw new DomainException("Modifikasi pertanyaan hanya diizinkan pada skema berstatus draft.");
        }

        $fieldKey = trim((string) ($data['field_key'] ?? ''));
        $fieldType = trim((string) ($data['field_type'] ?? 'text'));
        $label = trim((string) ($data['label'] ?? ''));
        $options = $data['options'] ?? null;

        if (empty($fieldKey) || empty($label)) {
            throw new InvalidArgumentException("field_key dan label wajib diisi.");
        }

        // Tolak field_key yang bertabrakan dengan kolom inti atau reserved spreadsheet keys
        if (in_array(strtolower($fieldKey), array_map('strtolower', self::CORE_FIELD_KEYS), true)
            || in_array(strtolower($fieldKey), array_map('strtolower', self::SPREADSHEET_RESERVED_KEYS), true)) {
            throw new InvalidArgumentException("field_key '{$fieldKey}' merupakan nama sistem atau kolom spreadsheet yang dilindungi dan tidak boleh digunakan.");
        }

        // Validasi identitas lintas versi terhadap registry
        $this->validateFieldKeyIdentityConsistency($fieldKey, $fieldType, $label, $options);

        // Pastikan tidak ada duplikasi field_key dalam draft yang sama
        if ($draft->fields()->where('field_key', $fieldKey)->exists()) {
            throw new InvalidArgumentException("field_key '{$fieldKey}' sudah ada dalam draf formulir ini.");
        }

        $rawRules = $data['validation_rules'] ?? null;
        $validationRules = is_array($rawRules) ? $rawRules : (json_decode((string) $rawRules, true) ?? null);
        if ($rawRules !== null && !empty($rawRules) && !is_array($validationRules)) {
            throw new InvalidArgumentException("validation_rules harus berupa JSON array/object yang valid.");
        }
        $validationRules = $this->validateFieldValidationRulesStructure($validationRules, $fieldType);

        $maxOrder = $draft->fields()->max('sort_order') ?? 0;

        return FormField::create([
            'form_schema_id'   => $draft->id,
            'field_key'        => $fieldKey,
            'field_type'       => $fieldType,
            'label'            => $label,
            'placeholder'      => $data['placeholder'] ?? null,
            'helper_text'      => $data['helper_text'] ?? null,
            'options'          => is_array($options) ? $options : (json_decode((string) $options, true) ?? null),
            'validation_rules' => $validationRules,
            'is_required'      => (bool) ($data['is_required'] ?? false),
            'is_active'        => (bool) ($data['is_active'] ?? true),
            'is_archived'      => false,
            'is_system'        => (bool) ($data['is_system'] ?? false),
            'group_name'       => $data['group_name'] ?? null,
            'sort_order'       => (int) ($data['sort_order'] ?? ($maxOrder + 10)),
            'column_span'      => (int) ($data['column_span'] ?? 1),
        ]);
    }

    /**
     * Perbarui field dalam draf skema.
     */
    public function updateFieldInDraft(FormField $field, array $data): FormField
    {
        // Jika field pada skema yang telah dipublikasikan/diarsipkan, hanya izinkan pembaruan teks aman
        if ($field->schema && ($field->schema->isPublished() || $field->schema->isArchived())) {
            $safePayload = [];
            if (array_key_exists('label', $data)) $safePayload['label'] = trim((string)$data['label']);
            if (array_key_exists('placeholder', $data)) $safePayload['placeholder'] = $data['placeholder'];
            if (array_key_exists('helper_text', $data)) $safePayload['helper_text'] = $data['helper_text'];
            if (array_key_exists('column_span', $data)) $safePayload['column_span'] = (int) $data['column_span'];
            if (array_key_exists('options', $data)) {
                $newOpts = is_array($data['options']) ? $data['options'] : (json_decode((string) $data['options'], true) ?? null);
                if ($newOpts !== null) {
                    $safePayload['options'] = $this->mergeAllowedOptions($field->options, $newOpts);
                }
            }
            $field->update($safePayload);
            return $field->fresh();
        }

        // Jika field sudah pernah digunakan/terkunci secara struktural, pertahankan tipe aslinya dan tolak perubahan inkompatibel
        if (isset($data['field_type']) && $data['field_type'] !== $field->field_type) {
            $isLocked = method_exists($field, 'isStructuralLocked') && $field->isStructuralLocked();
            if ($isLocked) {
                throw new InvalidArgumentException("Tipe field untuk '{$field->field_key}' telah dikunci permanen sebagai '{$field->field_type}' dan tidak dapat diubah.");
            }
        }

        $newType = $data['field_type'] ?? $field->field_type;
        $newLabel = $data['label'] ?? $field->label;
        $newOptions = array_key_exists('options', $data) ? $data['options'] : $field->options;

        // Validasi konsistensi identitas
        $this->validateFieldKeyIdentityConsistency($field->field_key, $newType, $newLabel, $newOptions);

        $validationRules = $field->validation_rules;
        if (array_key_exists('validation_rules', $data)) {
            $rawRules = $data['validation_rules'];
            $parsedRules = is_array($rawRules) ? $rawRules : (json_decode((string) $rawRules, true) ?? null);
            if ($rawRules !== null && !empty($rawRules) && !is_array($parsedRules)) {
                throw new InvalidArgumentException("validation_rules harus berupa JSON array/object yang valid.");
            }
            $validationRules = $this->validateFieldValidationRulesStructure($parsedRules, $newType);
        }

        $updatePayload = [
            'field_type'       => $newType,
            'label'            => $newLabel,
            'placeholder'      => array_key_exists('placeholder', $data) ? $data['placeholder'] : $field->placeholder,
            'helper_text'      => array_key_exists('helper_text', $data) ? $data['helper_text'] : $field->helper_text,
            'options'          => is_array($newOptions) ? $newOptions : (json_decode((string) $newOptions, true) ?? null),
            'validation_rules' => $validationRules,
            'is_required'      => array_key_exists('is_required', $data) ? (bool) $data['is_required'] : $field->is_required,
            'is_active'        => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $field->is_active,
            'group_name'       => array_key_exists('group_name', $data) ? $data['group_name'] : $field->group_name,
            'sort_order'       => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $field->sort_order,
            'column_span'      => array_key_exists('column_span', $data) ? (int) $data['column_span'] : $field->column_span,
        ];

        $field->update($updatePayload);
        return $field->fresh();
    }

    /**
     * Arsipkan field (soft-archive).
     */
    public function archiveField(FormField $field): bool
    {
        if ($field->schema && ($field->schema->isPublished() || $field->schema->isArchived())) {
            throw new DomainException("Pertanyaan pada skema yang telah dipublikasikan atau diarsipkan dilarang diarsipkan langsung. Gunakan draf versi penerus.");
        }

        return $field->update([
            'is_archived' => true,
            'is_active'   => false,
        ]);
    }

    /**
     * Hapus field fisik (hanya diizinkan jika belum pernah dipublikasikan dan belum memiliki jawaban).
     */
    public function deleteFieldFromDraft(FormField $field): bool
    {
        if ($field->is_system) {
            throw new DomainException("Pertanyaan inti sistem tidak dapat dihapus.");
        }

        if ($field->schema && ($field->schema->isPublished() || $field->schema->isArchived())) {
            throw new DomainException("Pertanyaan pada skema yang telah dipublikasikan dilarang dihapus fisik. Gunakan arsipkan.");
        }

        // Cek apakah pernah dipublikasikan di versi lain atau memiliki jawaban
        $isRegistered = FormFieldRegistry::where('field_key', $field->field_key)->where('is_locked', true)->exists();
        $existsInPublishedSchema = FormField::where('field_key', $field->field_key)
            ->whereHas('schema', function ($q) {
                $q->whereIn('status', [FormSchema::STATUS_PUBLISHED, FormSchema::STATUS_ARCHIVED]);
            })
            ->exists();
        $hasAnswers = RegistrationCustomAnswer::where('field_key', $field->field_key)->exists();

        if ($isRegistered || $existsInPublishedSchema || $hasAnswers) {
            throw new DomainException("Pertanyaan '{$field->label}' telah dipublikasikan atau memiliki jawaban historis dan tidak boleh dihapus sama sekali.");
        }

        return (bool) $field->delete();
    }

    /**
     * Publikasikan draf skema secara atomik dan aman dari race condition.
     */
    public function publishVersion(FormSchema $draft, ?User $publisher = null): FormSchema
    {
        if (!$draft->isDraft()) {
            throw new DomainException("Hanya skema berstatus draft yang dapat dipublikasikan.");
        }

        return DB::transaction(function () use ($draft, $publisher) {
            // Lock tabel skema untuk tahun terkait guna mencegah concurrent publish
            $lockedDraft = FormSchema::where('id', $draft->id)->lockForUpdate()->first();
            if (!$lockedDraft || !$lockedDraft->isDraft()) {
                throw new DomainException("Draf formulir telah berubah atau sedang diproses oleh proses lain.");
            }

            // Hitung schema_hash deterministik dari field aktif
            $activeFields = $lockedDraft->fields()->where('is_active', true)->where('is_archived', false)->get();
            $hashPayload = $activeFields->map(fn($f) => [
                'key'      => $f->field_key,
                'type'     => $f->field_type,
                'label'    => $f->label,
                'req'      => $f->is_required,
                'opts'     => $f->options,
                'rules'    => $f->validation_rules,
                'order'    => $f->sort_order,
            ])->toArray();

            $schemaHash = hash('sha256', json_encode($hashPayload));

            // Kunci versi kode definitif (hilangkan suffix -draft)
            $publishedVersionCode = preg_replace('/-draft$/', '', $lockedDraft->version_code);

            // Arsipkan versi published aktif sebelumnya melalui Eloquent model update
            $previousPublishedSchemas = FormSchema::where('year', $lockedDraft->year)
                ->where('status', FormSchema::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->get();

            foreach ($previousPublishedSchemas as $prevSchema) {
                $prevSchema->update([
                    'status'    => FormSchema::STATUS_ARCHIVED,
                    'is_active' => false,
                ]);
            }

            // Terbitkan draf
            $lockedDraft->update([
                'version_code' => $publishedVersionCode,
                'status'       => FormSchema::STATUS_PUBLISHED,
                'schema_hash'  => $schemaHash,
                'is_active'    => true,
                'published_at' => now(),
                'published_by' => $publisher?->id,
            ]);

            // Daftarkan seluruh field aktif ke FormFieldRegistry dan kunci identitasnya
            foreach ($activeFields as $field) {
                $registry = FormFieldRegistry::where('field_key', $field->field_key)->first();
                if (!$registry) {
                    FormFieldRegistry::create([
                        'field_key'               => $field->field_key,
                        'field_type'              => $field->field_type,
                        'canonical_label'         => $field->label,
                        'first_published_version' => $publishedVersionCode,
                        'is_locked'               => true,
                        'allowed_options'         => $field->options,
                    ]);
                } else {
                    // first_published_version HANYA diisi pada publikasi pertama dan tidak boleh ditimpa
                    $existingOptions = $registry->allowed_options ?? [];
                    $mergedOptions = $this->mergeAllowedOptions($existingOptions, $field->options);

                    $registry->update([
                        'canonical_label' => $field->label,
                        'allowed_options' => $mergedOptions,
                        'is_locked'       => true,
                    ]);
                }
            }

            // Buat draf baru untuk iterasi admin berikutnya
            $this->clonePublishedToDraft($lockedDraft, $publisher);

            return $lockedDraft->fresh();
        });
    }

    /**
     * Gabungkan options baru ke options lama tanpa menghilangkan opsi yang pernah dipublikasikan.
     */
    public function mergeAllowedOptions(?array $existing, mixed $incoming): array
    {
        $existing = $existing ?? [];
        $incomingArr = is_array($incoming) ? $incoming : (json_decode((string) $incoming, true) ?? []);

        $merged = $existing;
        $existingValues = collect($existing)->pluck('value')->filter()->all();

        foreach ($incomingArr as $opt) {
            $val = $opt['value'] ?? null;
            if ($val !== null && !in_array($val, $existingValues, true)) {
                $merged[] = $opt;
                $existingValues[] = $val;
            }
        }

        return $merged;
    }

    /**
     * Validasi identitas field_key permanen lintas versi.
     */
    public function validateFieldKeyIdentityConsistency(
        string $fieldKey,
        string $fieldType,
        string $label,
        mixed $options = null
    ): void {
        $registry = FormFieldRegistry::where('field_key', $fieldKey)->first();
        if (!$registry || !$registry->is_locked) {
            return;
        }

        // 1. Tipe tidak boleh diubah secara tidak kompatibel
        if ($registry->field_type !== $fieldType) {
            throw new InvalidArgumentException(
                "field_key '{$fieldKey}' telah dikunci pada versi '{$registry->first_published_version}' dengan tipe '{$registry->field_type}'. " .
                "Mengubah tipe menjadi '{$fieldType}' tidak diizinkan. Gunakan field_key baru."
            );
        }

        // 2. Options lama tidak boleh dihilangkan
        if (!empty($registry->allowed_options) && is_array($registry->allowed_options)) {
            $incomingOptions = is_array($options) ? $options : (json_decode((string) $options, true) ?? []);
            $incomingValues = collect($incomingOptions)->pluck('value')->filter()->all();
            $registeredValues = collect($registry->allowed_options)->pluck('value')->filter()->all();

            foreach ($registeredValues as $regVal) {
                if (!in_array($regVal, $incomingValues, true)) {
                    throw new InvalidArgumentException(
                        "Opsi '{$regVal}' pada field_key '{$fieldKey}' pernah dipublikasikan dan tidak boleh dihapus karena berpotensi merusak jawaban historis."
                    );
                }
            }
        }
    }

    /**
     * Validasi versi formulir saat pengiriman submisi dengan kebijakan TTL & Grace Period.
     */
    public function validateSubmissionVersion(
        string $submittedVersionCode,
        array $inputAnswers = [],
        ?int $explicitGracePeriodMinutes = null
    ): array {
        $schema = FormSchema::where('version_code', $submittedVersionCode)->first();
        if (!$schema) {
            return [
                'is_valid'          => false,
                'reason'            => 'VERSION_NOT_FOUND',
                'message'           => "Versi formulir '{$submittedVersionCode}' tidak valid atau tidak ditemukan.",
                'preserved_inputs'  => $inputAnswers,
            ];
        }

        if ($schema->isPublished() && $schema->is_active) {
            return [
                'is_valid'          => true,
                'schema'            => $schema,
                'is_grace_period'   => false,
                'preserved_inputs'  => $inputAnswers,
            ];
        }

        // Jika skema sudah diarsipkan (pernah dipublikasikan namun ada versi baru yang aktif)
        if ($schema->isArchived()) {
            // Penerus langsung yang menyebabkan skema ini diarsipkan (skema pada tahun sama yang terbit setelah skema ini)
            $directSuccessor = FormSchema::where('year', $schema->year)
                ->where(function ($q) use ($schema) {
                    if ($schema->published_at) {
                        $q->where('published_at', '>', $schema->published_at);
                    } else {
                        $q->where('id', '>', $schema->id);
                    }
                })
                ->orderBy('published_at', 'asc')
                ->first();

            // Skema aktif saat ini untuk informasi pembaruan ke peserta
            $activeSuccessor = FormSchema::where('year', $schema->year)
                ->where('status', FormSchema::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->first();

            $archivedTime = $directSuccessor?->published_at ?? $schema->updated_at;
            $effectiveMinutes = $this->getEffectiveGracePeriodMinutes($schema, $explicitGracePeriodMinutes);
            $cutoff = $archivedTime->copy()->addMinutes($effectiveMinutes);

            if (now()->lessThanOrEqualTo($cutoff)) {
                // Masih dalam masa toleransi grace period versinya sendiri
                return [
                    'is_valid'                       => true,
                    'schema'                         => $schema,
                    'is_grace_period'                => true,
                    'effective_grace_period_minutes' => $effectiveMinutes,
                    'preserved_inputs'               => $inputAnswers,
                ];
            }

            return [
                'is_valid'                       => false,
                'reason'                         => 'VERSION_EXPIRED',
                'message'                        => 'Formulir pendaftaran telah diperbarui oleh Admin. Data yang Anda masukkan telah diamankan.',
                'effective_grace_period_minutes' => $effectiveMinutes,
                'preserved_inputs'               => $inputAnswers,
                'successor_schema'               => $activeSuccessor ?? $directSuccessor,
            ];
        }

        return [
            'is_valid'          => false,
            'reason'            => 'VERSION_INACTIVE',
            'message'           => 'Versi formulir saat ini tidak aktif.',
            'preserved_inputs'  => $inputAnswers,
        ];
    }

    /**
     * Validasi jawaban kustom dinamis terhadap skema atau snapshot aktif dengan matriks tipe lengkap.
     */
    public function validateCustomAnswers(
        FormSchema|RegistrationFormSnapshot $schemaOrSnapshot,
        array $inputs,
        ?InternshipRegistration $registration = null
    ): array {
        if ($registration === null && $schemaOrSnapshot instanceof RegistrationFormSnapshot) {
            $registration = $schemaOrSnapshot->registration;
        }

        $fields = collect();
        if ($schemaOrSnapshot instanceof FormSchema) {
            $fields = $schemaOrSnapshot->activeFields()
                ->where('is_system', false)
                ->whereNotIn('field_key', self::CORE_FIELD_KEYS)
                ->get();
        } elseif ($schemaOrSnapshot instanceof RegistrationFormSnapshot) {
            $rawFields = $schemaOrSnapshot->schema_snapshot['fields'] ?? [];
            $fields = collect($rawFields)->filter(function ($f) {
                return empty($f['is_system']) && !in_array($f['field_key'] ?? '', FormBuilderService::CORE_FIELD_KEYS, true);
            });
        }

        $cleaned = [];
        $errors = [];

        // DESIGN DECISION: Whitelist filtering — hanya field_key yang terdaftar secara sah dalam skema/snapshot
        // yang divalidasi dan dimasukkan ke dalam $cleaned. Seluruh field_key ekstra atau asing diabaikan (discarded)
        // secara aman dari persistensi database tanpa memblokir pengiriman formulir pengguna yang sah.
        $cumulativeUploadSize = 0;

        foreach ($fields as $field) {
            $key = is_array($field) ? ($field['field_key'] ?? null) : $field->field_key;
            $type = is_array($field) ? ($field['field_type'] ?? 'text') : $field->field_type;
            $label = is_array($field) ? ($field['label'] ?? $key) : $field->label;
            $isRequired = is_array($field) ? !empty($field['is_required']) : (bool) $field->is_required;
            $options = is_array($field) ? ($field['options'] ?? []) : ($field->options ?? []);

            $rawRules = is_array($field) ? ($field['validation_rules'] ?? null) : $field->validation_rules;
            $rules = is_array($rawRules) ? $rawRules : (json_decode((string)$rawRules, true) ?? []);
            if (!is_array($rules)) {
                $rules = [];
            }

            $val = $inputs[$key] ?? null;

            // Penanganan khusus berkas (tipe file)
            if ($type === 'file') {
                $hasExistingAnswer = false;
                $existingFilePath = null;
                if ($registration) {
                    $existingAnswer = RegistrationCustomAnswer::where('registration_id', $registration->id)
                        ->where('field_key', $key)
                        ->first();
                    if ($existingAnswer && !empty($existingAnswer->answer_value) && Storage::disk('local')->exists($existingAnswer->answer_value)) {
                        $hasExistingAnswer = true;
                        $existingFilePath = $existingAnswer->answer_value;
                    }
                }

                if ($val instanceof \Illuminate\Http\UploadedFile) {
                    if (!$val->isValid()) {
                        $errors[$key] = "Berkas '{$label}' gagal diunggah atau tidak valid.";
                        continue;
                    }

                    $cumulativeUploadSize += $val->getSize();
                    if ($cumulativeUploadSize > 20 * 1024 * 1024) {
                        $errors['total_upload_quota'] = "Total ukuran berkas unggahan melebihi batas kuota 20MB per pendaftaran.";
                    }

                    $allowedExts = isset($rules['allowed_extensions']) && is_array($rules['allowed_extensions']) && !empty($rules['allowed_extensions'])
                        ? array_map('strtolower', $rules['allowed_extensions'])
                        : ['pdf', 'jpg', 'jpeg', 'png'];

                    $maxSizeKb = isset($rules['max_file_size_kb']) && is_int($rules['max_file_size_kb'])
                        ? $rules['max_file_size_kb']
                        : 5120;

                    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
                    $ext = strtolower($val->getClientOriginalExtension());
                    $mime = strtolower($val->getMimeType() ?: '');

                    if (!in_array($ext, $allowedExts, true)) {
                        $errors[$key] = "Berkas '{$label}' harus berformat: " . implode(', ', $allowedExts);
                    } elseif (!in_array($mime, $allowedMimes, true)) {
                        $errors[$key] = "Tipe konten (MIME) berkas '{$label}' tidak diizinkan.";
                    } elseif ($val->getSize() > $maxSizeKb * 1024) {
                        $sizeFormatted = $maxSizeKb >= 1024 ? ($maxSizeKb / 1024) . "MB" : "{$maxSizeKb}KB";
                        $errors[$key] = "Ukuran berkas '{$label}' maksimal {$sizeFormatted}.";
                    } else {
                        $cleaned[$key] = $val;
                    }
                } elseif (is_null($val) || $val === '' || (is_array($val) && empty($val))) {
                    if ($hasExistingAnswer) {
                        $cleaned[$key] = $existingFilePath;
                    } elseif ($isRequired) {
                        $errors[$key] = "Pertanyaan '{$label}' wajib diisi.";
                    } else {
                        $cleaned[$key] = null;
                    }
                } elseif (is_string($val)) {
                    $trimmedVal = trim($val);
                    if ($trimmedVal === '') {
                        if ($hasExistingAnswer) {
                            $cleaned[$key] = $existingFilePath;
                        } elseif ($isRequired) {
                            $errors[$key] = "Pertanyaan '{$label}' wajib diisi.";
                        } else {
                            $cleaned[$key] = null;
                        }
                    } else {
                        // Tolak null-byte, path traversal, remote URLs
                        if (str_contains($trimmedVal, "\0") || str_contains($trimmedVal, '..') || preg_match('~^[a-z]+://~i', $trimmedVal)) {
                            $errors[$key] = "Referensi berkas '{$label}' tidak valid atau mengandung karakter terlarang.";
                            continue;
                        }

                        // Hanya izinkan jika exact match dengan existing answer milik registration ini dan file ada di storage lokal
                        if ($hasExistingAnswer && $existingFilePath === $trimmedVal) {
                            $cleaned[$key] = $existingFilePath;
                        } else {
                            $errors[$key] = "Referensi berkas '{$label}' tidak valid atau bukan milik pendaftaran ini. Silakan unggah berkas baru.";
                        }
                    }
                } else {
                    $errors[$key] = "Berkas '{$label}' tidak valid.";
                }

                continue;
            }

            $isSubmittedInPayload = array_key_exists($key, $inputs);

            // Jika field non-file tidak disertakan pada payload sama sekali (misal partial update atau switch draft),
            // dan pendaftaran telah memiliki jawaban eksis untuk field ini, ambil nilai lama untuk divalidasi semantik.
            if (!$isSubmittedInPayload && $registration) {
                $existingAnswer = RegistrationCustomAnswer::where('registration_id', $registration->id)
                    ->where('field_key', $key)
                    ->first();
                if ($existingAnswer !== null && $existingAnswer->answer_value !== null) {
                    $val = $existingAnswer->answer_value;
                }
            }

            // Periksa required untuk field non-file (0 dan '0' bukan empty!)
            $isEmpty = is_null($val) || $val === '' || (is_array($val) && empty($val));
            if ($isRequired && $isEmpty) {
                $errors[$key] = "Pertanyaan '{$label}' wajib diisi.";
                continue;
            }

            if ($isEmpty) {
                $cleaned[$key] = null;
                continue;
            }

            // Validasi berdasarkan tipe field non-file
            switch ($type) {
                case 'select':
                case 'radio':
                    if (is_array($val)) {
                        $errors[$key] = "Pilihan untuk '{$label}' harus berupa nilai tunggal, bukan daftar.";
                        break;
                    }
                    $allowed = collect($options)->pluck('value')->map(fn($v) => (string)$v)->all();
                    if (!in_array((string)$val, $allowed, true)) {
                        $errors[$key] = "Pilihan untuk '{$label}' tidak valid.";
                    } else {
                        $cleaned[$key] = (string)$val;
                    }
                    break;

                case 'checkbox':
                    $rawArr = is_array($val) ? $val : array_map('trim', explode(',', (string)$val));
                    $allowed = collect($options)->pluck('value')->map(fn($v) => (string)$v)->all();
                    $invalid = array_diff(array_map('strval', $rawArr), $allowed);
                    if (!empty($invalid)) {
                        $errors[$key] = "Pilihan untuk '{$label}' berisi nilai yang tidak valid.";
                    } else {
                        if (isset($rules['min_items']) && is_int($rules['min_items']) && count($rawArr) < $rules['min_items']) {
                            $errors[$key] = "Pilihan untuk '{$label}' minimal {$rules['min_items']} item.";
                        } elseif (isset($rules['max_items']) && is_int($rules['max_items']) && count($rawArr) > $rules['max_items']) {
                            $errors[$key] = "Pilihan untuk '{$label}' maksimal {$rules['max_items']} item.";
                        } else {
                            $cleaned[$key] = $rawArr;
                        }
                    }
                    break;

                case 'date':
                    if (is_array($val)) {
                        $errors[$key] = "Format tanggal untuk '{$label}' tidak valid.";
                        break;
                    }
                    $dateStr = trim((string) $val);
                    $d = \DateTime::createFromFormat('Y-m-d', $dateStr);
                    if (!$d || $d->format('Y-m-d') !== $dateStr) {
                        $errors[$key] = "Format tanggal untuk '{$label}' tidak valid (gunakan format YYYY-MM-DD).";
                    } else {
                        if (!empty($rules['min_date']) && $dateStr < $rules['min_date']) {
                            $errors[$key] = "Tanggal '{$label}' tidak boleh lebih awal dari {$rules['min_date']}.";
                        } elseif (!empty($rules['max_date']) && $dateStr > $rules['max_date']) {
                            $errors[$key] = "Tanggal '{$label}' tidak boleh lebih lambat dari {$rules['max_date']}.";
                        } else {
                            $cleaned[$key] = $dateStr;
                        }
                    }
                    break;

                case 'email':
                    if (is_array($val) || !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $errors[$key] = "Format email untuk '{$label}' tidak valid.";
                    } else {
                        $emailStr = (string)$val;
                        $len = mb_strlen($emailStr);
                        if (isset($rules['min_length']) && is_int($rules['min_length']) && $len < $rules['min_length']) {
                            $errors[$key] = "Panjang '{$label}' minimal {$rules['min_length']} karakter.";
                        } elseif (isset($rules['max_length']) && is_int($rules['max_length']) && $len > $rules['max_length']) {
                            $errors[$key] = "Panjang '{$label}' maksimal {$rules['max_length']} karakter.";
                        } else {
                            $cleaned[$key] = $emailStr;
                        }
                    }
                    break;

                case 'url':
                    if (is_array($val)) {
                        $errors[$key] = "Format URL untuk '{$label}' tidak valid.";
                        break;
                    }
                    $urlStr = trim((string) $val);
                    if (!filter_var($urlStr, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $urlStr)) {
                        $errors[$key] = "Format URL untuk '{$label}' tidak valid (harus diawali http:// atau https://).";
                    } else {
                        $len = mb_strlen($urlStr);
                        if (isset($rules['min_length']) && is_int($rules['min_length']) && $len < $rules['min_length']) {
                            $errors[$key] = "Panjang '{$label}' minimal {$rules['min_length']} karakter.";
                        } elseif (isset($rules['max_length']) && is_int($rules['max_length']) && $len > $rules['max_length']) {
                            $errors[$key] = "Panjang '{$label}' maksimal {$rules['max_length']} karakter.";
                        } else {
                            $cleaned[$key] = $urlStr;
                        }
                    }
                    break;

                case 'number':
                    if (is_array($val) || !is_numeric($val)) {
                        $errors[$key] = "Nilai '{$label}' harus berupa angka.";
                    } else {
                        $numVal = floatval($val);
                        if (isset($rules['min_value']) && is_numeric($rules['min_value']) && $numVal < floatval($rules['min_value'])) {
                            $errors[$key] = "Nilai '{$label}' minimal {$rules['min_value']}.";
                        } elseif (isset($rules['max_value']) && is_numeric($rules['max_value']) && $numVal > floatval($rules['max_value'])) {
                            $errors[$key] = "Nilai '{$label}' maksimal {$rules['max_value']}.";
                        } else {
                            $cleaned[$key] = (string)$val;
                        }
                    }
                    break;

                default: // text, textarea, tel, dll
                    if (is_array($val)) {
                        $errors[$key] = "Isian '{$label}' harus berupa teks, bukan daftar.";
                    } else {
                        $strVal = is_string($val) ? trim($val) : (string)$val;
                        $len = mb_strlen($strVal);
                        $maxLimit = isset($rules['max_length']) && is_int($rules['max_length']) ? $rules['max_length'] : 2000;
                        $minLimit = isset($rules['min_length']) && is_int($rules['min_length']) ? $rules['min_length'] : null;

                        if ($minLimit !== null && $len < $minLimit) {
                            $errors[$key] = "Panjang '{$label}' minimal {$minLimit} karakter.";
                        } elseif ($len > $maxLimit) {
                            $errors[$key] = "Isian '{$label}' melebihi batas maksimal {$maxLimit} karakter.";
                        } else {
                            $cleaned[$key] = $strVal;
                        }
                    }
                    break;
            }
        }

        return [
            'is_valid' => empty($errors),
            'cleaned'  => $cleaned,
            'errors'   => $errors,
        ];
    }

    /**
     * Bekukan snapshot skema imutabel untuk pendaftaran yang disubmit resmi.
     */
    public function freezeSnapshot(InternshipRegistration $registration, FormSchema $schema): RegistrationFormSnapshot
    {
        $existing = RegistrationFormSnapshot::where('registration_id', $registration->id)->first();
        if ($existing) {
            // Snapshot bersifat imutabel: jangan timpa jika sudah ada
            return $existing;
        }

        $activeFields = $schema->fields()
            ->where('is_active', true)
            ->where('is_archived', false)
            ->get();

        $snapshotPayload = [
            'version_code' => $schema->version_code,
            'year'         => $schema->year,
            'title'        => $schema->title,
            'frozen_at'    => now()->toIso8601String(),
            'fields'       => $activeFields->map(fn($f) => [
                'field_key'        => $f->field_key,
                'field_type'       => $f->field_type,
                'label'            => $f->label,
                'placeholder'      => $f->placeholder,
                'helper_text'      => $f->helper_text,
                'options'          => $f->options,
                'validation_rules' => $f->validation_rules,
                'is_required'      => $f->is_required,
                'is_system'        => $f->is_system,
                'group_name'       => $f->group_name,
                'sort_order'       => $f->sort_order,
                'column_span'      => $f->column_span,
            ])->toArray(),
        ];

        return RegistrationFormSnapshot::create([
            'registration_id'     => $registration->id,
            'form_schema_id'      => $schema->id,
            'form_schema_version' => $schema->version_code,
            'schema_hash'         => $schema->schema_hash,
            'schema_snapshot'     => $snapshotPayload,
            'submitted_at'        => now(),
        ]);
    }

    /**
     * Simpan atau perbarui jawaban dinamis peserta dengan otorisasi & audit log non-PII.
     * Mengembalikan daftar path berkas baru yang disimpan pada pemanggilan ini.
     *
     * @return array<string> Daftar path berkas baru yang disimpan pada storage
     */
    public function saveCustomAnswers(
        InternshipRegistration $registration,
        array $answers,
        mixed $schemaOrSnapshotOrActor = null,
        ?User $actor = null
    ): array {
        $schemaOrSnapshot = null;
        if ($schemaOrSnapshotOrActor instanceof User) {
            $actor = $schemaOrSnapshotOrActor;
        } elseif ($schemaOrSnapshotOrActor instanceof FormSchema || $schemaOrSnapshotOrActor instanceof RegistrationFormSnapshot) {
            $schemaOrSnapshot = $schemaOrSnapshotOrActor;
        }

        $updatedKeys = [];
        $valueHashes = [];
        $newlyStoredFiles = [];

        // Petakan label pilihan dari skema/snapshot jika ada
        $optionsMap = [];
        if ($schemaOrSnapshot instanceof FormSchema) {
            foreach ($schemaOrSnapshot->activeFields as $f) {
                if (!empty($f->options)) {
                    $optionsMap[$f->field_key] = collect($f->options)->pluck('label', 'value')->all();
                }
            }
        } elseif ($schemaOrSnapshot instanceof RegistrationFormSnapshot) {
            foreach (($schemaOrSnapshot->schema_snapshot['fields'] ?? []) as $f) {
                if (!empty($f['options'])) {
                    $optionsMap[$f['field_key']] = collect($f['options'])->pluck('label', 'value')->all();
                }
            }
        }

        try {
            foreach ($answers as $key => $val) {
                $rawKey = trim((string) $key);
                if (empty($rawKey)) {
                    continue;
                }

                // Jika berupa UploadedFile, simpan ke private storage secara atomik
                if ($val instanceof \Illuminate\Http\UploadedFile) {
                    $storedPath = $val->store('private/custom_answers', 'local');
                    $newlyStoredFiles[] = $storedPath;
                    $valString = $storedPath;
                    $label = basename($storedPath);
                } else {
                    $valString = is_array($val) ? json_encode($val) : (is_null($val) ? null : (string) $val);

                    // Tentukan answer_label
                    $label = $valString;
                    if (isset($optionsMap[$rawKey])) {
                        if (is_array($val)) {
                            $labels = array_map(fn($v) => $optionsMap[$rawKey][$v] ?? $v, $val);
                            $label = implode(', ', $labels);
                        } elseif (isset($optionsMap[$rawKey][$valString])) {
                            $label = $optionsMap[$rawKey][$valString];
                        }
                    }
                }

                RegistrationCustomAnswer::updateOrCreate(
                    [
                        'registration_id' => $registration->id,
                        'field_key'       => $rawKey,
                    ],
                    [
                        'answer_value'    => $valString,
                        'answer_label'    => $label,
                    ]
                );

                $updatedKeys[] = $rawKey;
                $valueHashes[$rawKey] = hash('sha256', (string) $valString);
            }
        } catch (\Throwable $e) {
            // Bersihkan file yang baru disimpan jika terjadi kegagalan
            foreach ($newlyStoredFiles as $path) {
                try {
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
                } catch (\Throwable) {}
            }
            throw $e;
        }

        // Catat ke audit log jika ada perbaikan oleh aktor (tanpa membocorkan PII atau dokumen mentah)
        if ($actor && !empty($updatedKeys)) {
            ActivityLogger::log(
                action: 'registration_custom_answers_updated',
                description: "Jawaban dinamis pendaftaran #{$registration->id} diperbarui oleh {$actor->name}.",
                subject: $registration,
                metadata: [
                    'registration_id' => $registration->id,
                    'updated_keys'    => $updatedKeys,
                    'actor_id'        => $actor->id,
                    'value_hashes'    => $valueHashes,
                ]
            );
        }

        return $newlyStoredFiles;
    }

    protected function generateNextDraftVersionCode(FormSchema $published): string
    {
        $current = $published->version_code;
        if (preg_match('/v(\d+)\.(\d+)/', $current, $m)) {
            $major = (int) $m[1];
            $minor = (int) $m[2] + 1;
            return "v{$major}.{$minor}-draft";
        }

        return "v{$published->year}.2-draft";
    }
}
