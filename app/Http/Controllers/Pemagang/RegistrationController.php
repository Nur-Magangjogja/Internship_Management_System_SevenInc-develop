<?php

namespace App\Http\Controllers\Pemagang;

use App\Http\Controllers\Controller;
use App\Models\InternshipRegistration as IR;
use App\Models\RegistrationAttachment;
use App\Models\RegistrationCustomAnswer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class RegistrationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Tampilkan form pendaftaran.
     * Jika sudah ada data (termasuk draft), isi form dengan data tersebut.
     */
    public function showForm()
    {
        $user         = auth()->user();
        $lifecycle    = app(\App\Services\RegistrationLifecycleService::class);
        $registration = $lifecycle->getOpenOrDraftRegistration($user)
            ?? \App\Models\InternshipRegistration::where('user_id', $user->id)->latest('id')->first();
        $registration?->loadMissing(['institution', 'city', 'facultyRel', 'studyProgramRel', 'division', 'brandRel', 'attachments']);

        // Ambil divisi aktif dari DB; fallback ke list hardcode jika DB kosong
        $divisions = \App\Models\Division::active()->pluck('name');

        if ($divisions->isEmpty()) {
            $divisions = collect([
                'UI/UX Designer', 'Marketing / Sales', 'Social Media Specialist',
                'Programmer', 'Marcom/Public Relation', 'Tiktok Creator',
                'Desain Grafis', 'Content Writer', 'Host/Presenter',
                'Fotografer', 'Content Planner', 'Voice Over Talent',
                'Videografer', 'Administrasi', 'Las', 'Digital Marketing',
                'Project Manager', 'Human Resource', 'Research & Development',
                'Animasi', 'Customer Service',
            ]);
        }

        // Ambil konfigurasi field dari DB (jika tabel sudah ada)
        $mainFields       = collect();
        $extraFields      = collect();
        $customFields     = collect();
        $customAnswers    = collect();
        $activeSchema     = null;
        $useFormFields    = false;

        $rawQueryDate       = request('start_date');
        $validatedQueryDate = $this->validateCalendarDateString($rawQueryDate);
        $isQueryDateInvalid = !empty($rawQueryDate) && $validatedQueryDate === null;
        $queryDateYear      = $validatedQueryDate ? (int) substr($validatedQueryDate, 0, 4) : null;
        $effectiveStartDate = null;

        try {
            if ($registration && $registration->formSnapshot && $registration->formSnapshot->schema) {
                $activeSchema       = $registration->formSnapshot->schema;
                $effectiveStartDate = $registration->start_date
                    ? ($registration->start_date instanceof \DateTimeInterface ? $registration->start_date->format('Y-m-d') : substr((string) $registration->start_date, 0, 10))
                    : null;
            } else {
                if ($validatedQueryDate !== null) {
                    $targetYear         = $queryDateYear;
                    $effectiveStartDate = $validatedQueryDate;
                    $activeSchema       = $this->resolveActiveSchemaForYear($targetYear);
                } elseif ($isQueryDateInvalid) {
                    $targetYear         = null;
                    $activeSchema       = null;
                    $effectiveStartDate = ($registration && $registration->start_date)
                        ? ($registration->start_date instanceof \DateTimeInterface ? $registration->start_date->format('Y-m-d') : substr((string) $registration->start_date, 0, 10))
                        : null;
                } else {
                    $savedStartDate     = ($registration && $registration->start_date)
                        ? ($registration->start_date instanceof \DateTimeInterface ? $registration->start_date->format('Y-m-d') : substr((string) $registration->start_date, 0, 10))
                        : null;
                    $validatedSavedDate = $this->validateCalendarDateString($savedStartDate);
                    if ($validatedSavedDate !== null) {
                        $targetYear         = (int) substr($validatedSavedDate, 0, 4);
                        $effectiveStartDate = $validatedSavedDate;
                    } else {
                        $targetYear         = (int) now()->year;
                        $effectiveStartDate = null;
                    }
                    $activeSchema = $this->resolveActiveSchemaForYear($targetYear);
                }
            }
            if ($activeSchema) {
                $activeFields  = $activeSchema->activeFields;
                $groupedFields = [
                    \App\Models\FormField::GROUP_DATA_DIRI          => $activeFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_DATA_DIRI)->values(),
                    \App\Models\FormField::GROUP_PILIHAN_DURASI     => $activeFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_PILIHAN_DURASI)->values(),
                    \App\Models\FormField::GROUP_BERKAS             => $activeFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_BERKAS)->values(),
                    \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN => $activeFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN)->values(),
                ];
                $mainFields    = $activeFields->filter(fn($f) => $f->resolved_group !== \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN && $f->is_system)->values();
                $extraFields   = $groupedFields[\App\Models\FormField::GROUP_INFORMASI_TAMBAHAN];
                $customFields  = $activeFields->where('is_system', false)->whereNotIn('field_key', \App\Services\FormBuilderService::CORE_FIELD_KEYS)->values();
                $useFormFields = $activeFields->isNotEmpty();
            } else {
                // Fallback jika belum ada FormSchema aktif namun form_fields lama ada
                try {
                    $legacyFields  = \App\Models\FormField::active()->whereNull('form_schema_id')->get();
                } catch (\Illuminate\Database\QueryException) {
                    $legacyFields  = \App\Models\FormField::active()->get();
                }
                $groupedFields = [
                    \App\Models\FormField::GROUP_DATA_DIRI          => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_DATA_DIRI)->values(),
                    \App\Models\FormField::GROUP_PILIHAN_DURASI     => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_PILIHAN_DURASI)->values(),
                    \App\Models\FormField::GROUP_BERKAS             => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_BERKAS)->values(),
                    \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN)->values(),
                ];
                $mainFields    = $legacyFields->filter(fn($f) => $f->resolved_group !== \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN)->values();
                $extraFields   = $groupedFields[\App\Models\FormField::GROUP_INFORMASI_TAMBAHAN];
                $customFields  = collect();
                $useFormFields = $legacyFields->isNotEmpty();
            }

            if ($registration) {
                try {
                    $customAnswers = $registration->customAnswers()->pluck('answer_value', 'field_key');
                } catch (\Illuminate\Database\QueryException) {
                    // Tabel registration_custom_answers belum termigrasi
                    $customAnswers = collect();
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Tabel form_schemas belum termigrasi → fallback ke form_fields legacy atau form statis
            try {
                $legacyFields  = \App\Models\FormField::active()->get();
                $groupedFields = [
                    \App\Models\FormField::GROUP_DATA_DIRI          => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_DATA_DIRI)->values(),
                    \App\Models\FormField::GROUP_PILIHAN_DURASI     => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_PILIHAN_DURASI)->values(),
                    \App\Models\FormField::GROUP_BERKAS             => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_BERKAS)->values(),
                    \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN => $legacyFields->filter(fn($f) => $f->resolved_group === \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN)->values(),
                ];
                $mainFields    = $legacyFields->filter(fn($f) => $f->resolved_group !== \App\Models\FormField::GROUP_INFORMASI_TAMBAHAN)->values();
                $extraFields   = $groupedFields[\App\Models\FormField::GROUP_INFORMASI_TAMBAHAN];
                $customFields  = collect();
                $useFormFields = $legacyFields->isNotEmpty();
            } catch (\Illuminate\Database\QueryException) {
                $groupedFields = [];
                $useFormFields = false;
            }
        }

        $cities = \App\Models\City::select('id', 'name')->orderBy('name')->get();
        $institutions = \App\Models\Institution::select('id', 'name')
            ->where('is_active', true)
            ->when($registration && $registration->institution_id, function ($q) use ($registration) {
                $q->orWhere('id', $registration->institution_id);
            })
            ->orderBy('name')
            ->get();
        $faculties = \App\Models\Faculty::select('id', 'name')->orderBy('name')->get();
        $studyPrograms = \App\Models\StudyProgram::select('id', 'name')->orderBy('name')->get();

        return view('pemagang.registration.form', compact(
            'registration', 'divisions', 'groupedFields', 'mainFields', 'extraFields', 'customFields',
            'customAnswers', 'activeSchema', 'useFormFields',
            'cities', 'institutions', 'faculties', 'studyPrograms',
            'effectiveStartDate', 'validatedQueryDate', 'isQueryDateInvalid', 'queryDateYear'
        ));
    }

    /**
     * Kirim pendaftaran resmi (is_draft = false, status = waiting).
     */
    public function store(Request $request)
    {
        return $this->saveRegistration($request, isDraft: false);
    }

    /**
     * Simpan sebagai draft (is_draft = true, status tidak berubah).
     */
    public function saveDraft(Request $request)
    {
        return $this->saveRegistration($request, isDraft: true);
    }

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    private function saveRegistration(Request $request, bool $isDraft): \Illuminate\Http\RedirectResponse
    {
        $user = auth()->user();

        // Pemeriksaan otorisasi edit pemagang:
        // Pemagang hanya boleh mengubah pendaftaran yang berstatus draft atau baru/menunggu review (belum di-accept/reject).
        $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
        $existing  = $lifecycle->getOpenOrDraftRegistration($user);

        if ($existing && !$existing->is_draft) {
            // Pendaftaran sudah resmi disubmit: perubahan hanya diizinkan saat masih berstatus NEW/WAITING,
            // atau jika akun di-provisioning otomatis dari Absen Djuragan dan belum pernah melengkapi form pendaftaran.
            $isAutoProvisionedFromAbsen = in_array($existing->internship_status, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE], true)
                && empty($existing->formSnapshot)
                && empty($existing->internship_reason);

            if (!in_array($existing->internship_status, [IR::STATUS_NEW, IR::STATUS_WAITING], true) && !$isAutoProvisionedFromAbsen) {
                return back()->withInput()->withErrors([
                    'status' => 'Pendaftaran Anda telah diproses oleh admin dan tidak dapat diubah lagi.'
                ]);
            }
        }

        // Validasi format tanggal start_date jika dikirim (menolak non-string, array, nested array, atau kalender invalid)
        if ($request->has('start_date')) {
            $rawPostStartDate = $request->input('start_date');
            if ($rawPostStartDate !== null && $rawPostStartDate !== '') {
                $validatedPostStartDate = $this->validateCalendarDateString($rawPostStartDate);
                if ($validatedPostStartDate === null) {
                    return back()
                        ->withInput()
                        ->withErrors([
                            'start_date' => 'Format tanggal mulai magang yang dipilih tidak valid. Harap pilih tanggal kalender yang sah.'
                        ]);
                }
            }
        }

        // Binding versi skema formulir dan validasi custom answers
        $formBuilder = app(\App\Services\FormBuilderService::class);
        $boundSchema = null;
        $cleanedCustomAnswers = [];
        $isDraftYearChanged = false;
        $targetYear = null;

        if (Schema::hasTable('form_schemas')) {
            if ($existing && $existing->formSnapshot && $existing->formSnapshot->schema) {
                // Binding ke versi skema historis pendaftaran yang sudah pernah disubmit
                $boundSchema = $existing->formSnapshot->schema;
                $snapshotYear = (int) $boundSchema->year;

                // Tolak perubahan start_date yang mengubah tahun pada pendaftaran yang telah memiliki snapshot
                if ($request->filled('start_date')) {
                    $validatedInputDate = $this->validateCalendarDateString($request->input('start_date'));
                    $inputYear = $validatedInputDate ? (int) substr($validatedInputDate, 0, 4) : null;
                    if ($inputYear !== null && $inputYear !== $snapshotYear) {
                        return back()
                            ->withInput()
                            ->withErrors([
                                'start_date' => "Tanggal mulai magang tidak dapat diubah ke tahun {$inputYear} karena pendaftaran ini telah terikat pada formulir periode tahun {$snapshotYear}."
                            ]);
                    }
                }
            } else {
                $targetYear = $this->resolveTargetRegistrationYear($existing, $request->input('start_date'));
                $hasAnyPublishedSchema = \App\Models\FormSchema::where('status', \App\Models\FormSchema::STATUS_PUBLISHED)->exists();

                if (!$isDraft && $targetYear === null && $hasAnyPublishedSchema) {
                    return back()
                        ->withInput()
                        ->withErrors([
                            'start_date' => 'Tanggal mulai magang wajib diisi untuk menentukan formulir pendaftaran.'
                        ]);
                }

                if ($targetYear !== null) {
                    $targetYearSchema = $this->resolveActiveSchemaForYear($targetYear);

                    if (!$targetYearSchema && !$isDraft && $hasAnyPublishedSchema) {
                        return back()
                            ->withInput()
                            ->withErrors([
                                'form_version' => "Formulir pendaftaran untuk periode magang tahun {$targetYear} belum dibuka atau belum dipublikasikan oleh Admin."
                            ]);
                    }

                    $submittedVersion = $request->input('form_schema_version');
                    if (!empty($submittedVersion)) {
                        $versionCheck = $formBuilder->validateSubmissionVersion($submittedVersion, $request->all());
                        if (!$versionCheck['is_valid']) {
                            if (($versionCheck['reason'] ?? '') === 'VERSION_EXPIRED') {
                                return back()
                                    ->withInput($versionCheck['preserved_inputs'])
                                    ->withErrors([
                                        'form_version' => $versionCheck['message'] ?? 'Formulir pendaftaran telah diperbarui oleh Admin. Data yang Anda masukkan telah diamankan.'
                                    ]);
                            }
                            return back()
                                ->withInput()
                                ->withErrors([
                                    'form_version' => $versionCheck['message'] ?? 'Versi formulir tidak valid.'
                                ]);
                        }
                        $submittedSchema = $versionCheck['schema'] ?? null;

                        // CROSS-YEAR VERSION SPOOFING PREVENTION:
                        // Versi skema WAJIB berasal dari $targetYear (YEAR(start_date))!
                        if ($submittedSchema && (int) $submittedSchema->year !== $targetYear) {
                            return back()
                                ->withInput()
                                ->withErrors([
                                    'form_version' => "Versi formulir ({$submittedSchema->version_code} - {$submittedSchema->year}) tidak sesuai dengan tahun mulai magang ({$targetYear})."
                                ]);
                        }

                        $boundSchema = $submittedSchema;
                    } else {
                        $boundSchema = $targetYearSchema;
                    }

                    // 1. Verifikasi snapshot historis jika pendaftaran telah berstatus submitted/snapshot
                    if ($existing && $existing->formSnapshot) {
                        if (empty($existing->formSnapshot->form_schema_id) || !$existing->formSnapshot->schema) {
                            return back()
                                ->withInput()
                                ->withErrors([
                                    'start_date' => "Pendaftaran memiliki snapshot formulir tetapi form_schema_id tidak valid atau skema tidak ditemukan di database."
                                ]);
                        }
                        $snapshotYear = (int) $existing->formSnapshot->schema->year;
                        if ($snapshotYear && $targetYear !== $snapshotYear) {
                            return back()
                                ->withInput()
                                ->withErrors([
                                    'start_date' => "Pendaftaran ini telah memiliki snapshot formulir tahun {$snapshotYear} dan tidak dapat diubah ke periode tahun {$targetYear}."
                                ]);
                        }
                    }

                    // 2. Deteksi perubahan tahun start_date pada draft yang belum memiliki snapshot.
                    // Jika draft telah memiliki jawaban isian, lakukan validasi semantik ketat lintas skema:
                    // DILARANG menghapus jawaban secara diam-diam! Jika ada isian yang tidak kompatibel
                    // (tipe berbeda, makna berbeda, opsi tidak sesuai, validasi lebih ketat, atau skema asal tidak dapat diverifikasi),
                    // tolak perubahan tahun fail-closed dan pertahankan seluruh jawaban serta referensi file lama.
                    if ($existing && !$existing->formSnapshot) {
                        $existingAnswers = $existing->customAnswers()->get();

                        if ($existingAnswers->isNotEmpty()) {
                            // Wajib verifikasi tanggal mulai magang asal
                            $rawExistingStartDate = $existing->getRawOriginal('start_date') ?: ($existing->start_date ? (is_string($existing->start_date) ? substr($existing->start_date, 0, 10) : $existing->start_date->format('Y-m-d')) : null);
                            if (empty($rawExistingStartDate)) {
                                return back()
                                    ->withInput()
                                    ->withErrors([
                                        'start_date' => "Tanggal mulai magang tidak dapat diubah karena draf pendaftaran memiliki isian formulir tetapi tanggal mulai magang asal tidak terdaftar sehingga skema asal tidak dapat diverifikasi secara unik."
                                    ]);
                            }

                            $validatedExistingDate = $this->validateCalendarDateString($rawExistingStartDate);
                            if ($validatedExistingDate === null) {
                                return back()
                                    ->withInput()
                                    ->withErrors([
                                        'start_date' => "Tanggal mulai magang tidak dapat diubah karena tahun asal pendaftaran tidak valid."
                                    ]);
                            }
                            $existingYear = (int) substr($validatedExistingDate, 0, 4);

                            if ($targetYear !== null && $existingYear !== $targetYear) {
                                // Resolusi skema asal secara ketat:
                                // Ambil seluruh skema published/archived pada tahun asal
                                $existingYearSchemas = \App\Models\FormSchema::where('year', $existingYear)
                                    ->whereIn('status', [\App\Models\FormSchema::STATUS_PUBLISHED, \App\Models\FormSchema::STATUS_ARCHIVED])
                                    ->get();

                                if ($existingYearSchemas->isEmpty()) {
                                    return back()
                                        ->withInput()
                                        ->withErrors([
                                            'start_date' => "Tanggal mulai magang tidak dapat diubah ke tahun {$targetYear} karena skema formulir asal untuk periode {$existingYear} tidak ditemukan atau belum dipublikasikan."
                                        ]);
                                }

                                $oldSchema = null;
                                if ($existingYearSchemas->count() === 1) {
                                    $oldSchema = $existingYearSchemas->first();
                                } else {
                                    // Terdapat beberapa versi skema pada tahun asal (misal v1 dan v2).
                                    // Sesuai aturan: DILARANG menganggap v2 sebagai skema asal v1 tanpa bukti.
                                    // Jika asal versi tidak dapat dibuktikan secara unik pada draf, tolak fail-closed!
                                    return back()
                                        ->withInput()
                                        ->withErrors([
                                            'start_date' => "Tanggal mulai magang tidak dapat diubah ke tahun {$targetYear} karena periode asal {$existingYear} memiliki beberapa versi formulir ({$existingYearSchemas->pluck('version_code')->implode(', ')}) dan versi asal isian draf tidak dapat diverifikasi secara unik. Harap selesaikan pendaftaran pada periode asal atau buat pendaftaran baru."
                                        ]);
                                }

                                if (!$boundSchema) {
                                    return back()
                                        ->withInput()
                                        ->withErrors([
                                            'start_date' => "Tanggal mulai magang tidak dapat diubah ke tahun {$targetYear} karena skema formulir untuk periode {$targetYear} belum tersedia."
                                        ]);
                                }

                                $incompatibleReasons = [];
                                $activeFieldsMap = $boundSchema->activeFields->keyBy('field_key');
                                $oldFieldsMap = $oldSchema->fields->keyBy('field_key');

                                foreach ($existingAnswers as $ans) {
                                    $fKey = $ans->field_key;

                                    // A. Verifikasi eksistensi field di skema asal (FAIL-CLOSED jika oldField null)
                                    if (!$oldFieldsMap->has($fKey)) {
                                        $incompatibleReasons[] = "pertanyaan asal '{$fKey}' tidak dapat diverifikasi pada skema asal ({$oldSchema->version_code})";
                                        continue;
                                    }
                                    $oldField = $oldFieldsMap->get($fKey);

                                    // B. Verifikasi eksistensi field di skema target
                                    if (!$activeFieldsMap->has($fKey)) {
                                        $incompatibleReasons[] = "pertanyaan '{$fKey}' tidak tersedia pada formulir tahun {$targetYear}";
                                        continue;
                                    }
                                    $targetField = $activeFieldsMap->get($fKey);

                                    // C. Cek kesesuaian tipe data
                                    if ($oldField->field_type !== $targetField->field_type) {
                                        $incompatibleReasons[] = "tipe pertanyaan '{$fKey}' berbeda ({$oldField->field_type} vs {$targetField->field_type})";
                                        continue;
                                    }

                                    // D. Cek makna dan label pertanyaan
                                    $oldLabelClean = trim(preg_replace('/\s+/', ' ', mb_strtolower((string)$oldField->label)));
                                    $targetLabelClean = trim(preg_replace('/\s+/', ' ', mb_strtolower((string)$targetField->label)));
                                    if ($oldLabelClean !== $targetLabelClean) {
                                        $incompatibleReasons[] = "makna/label pertanyaan '{$fKey}' berbeda antara periode lama ('{$oldField->label}') dan periode {$targetYear} ('{$targetField->label}')";
                                        continue;
                                    }

                                    // E. Cek kesesuaian opsi untuk select/radio/checkbox
                                    if (in_array($targetField->field_type, ['select', 'radio', 'checkbox'], true)) {
                                        $allowedOpts = collect($targetField->options ?? [])->pluck('value')->map(fn($v) => (string)$v)->all();
                                        $ansVal = $ans->answer_value;
                                        if ($ansVal !== null && $ansVal !== '') {
                                            if ($targetField->field_type === 'checkbox') {
                                                $parsedVals = is_array($ansVal) ? $ansVal : (json_decode((string)$ansVal, true) ?: array_map('trim', explode(',', (string)$ansVal)));
                                                $invalidDiff = array_diff(array_map('strval', (array)$parsedVals), $allowedOpts);
                                                if (!empty($invalidDiff)) {
                                                    $incompatibleReasons[] = "pilihan pada '{$fKey}' tidak sesuai dengan opsi tahun {$targetYear}";
                                                    continue;
                                                }
                                            } else {
                                                if (!in_array((string)$ansVal, $allowedOpts, true)) {
                                                    $incompatibleReasons[] = "pilihan jawaban '{$ansVal}' pada '{$fKey}' tidak sesuai dengan opsi tahun {$targetYear}";
                                                    continue;
                                                }
                                            }
                                        }
                                    }

                                    // F. Cek validation rules (stricter validation, numeric bounds, text length, dates, items)
                                    $targetRules = is_array($targetField->validation_rules) ? $targetField->validation_rules : (json_decode((string)$targetField->validation_rules, true) ?? []);
                                    if (!is_array($targetRules)) {
                                        $targetRules = [];
                                    }

                                    // Text/textarea/tel/email/url length
                                    if (in_array($targetField->field_type, ['text', 'textarea', 'email', 'tel', 'url'], true) && $ans->answer_value !== null && $ans->answer_value !== '') {
                                        $strLen = mb_strlen((string)$ans->answer_value);
                                        if (isset($targetRules['min_length']) && $strLen < $targetRules['min_length']) {
                                            $incompatibleReasons[] = "panjang jawaban '{$fKey}' ({$strLen} karakter) kurang dari batas minimal tahun {$targetYear} ({$targetRules['min_length']} karakter)";
                                            continue;
                                        }
                                        if (isset($targetRules['max_length']) && $strLen > $targetRules['max_length']) {
                                            $incompatibleReasons[] = "panjang jawaban '{$fKey}' ({$strLen} karakter) melebihi batas maksimal tahun {$targetYear} ({$targetRules['max_length']} karakter)";
                                            continue;
                                        }
                                    }

                                    // Number bounds
                                    if ($targetField->field_type === 'number' && $ans->answer_value !== null && $ans->answer_value !== '') {
                                        if (!is_numeric($ans->answer_value)) {
                                            $incompatibleReasons[] = "nilai pada '{$fKey}' bukan berupa angka";
                                            continue;
                                        }
                                        $numVal = floatval($ans->answer_value);
                                        if (isset($targetRules['min_value']) && $numVal < floatval($targetRules['min_value'])) {
                                            $incompatibleReasons[] = "nilai angka pada '{$fKey}' ({$ans->answer_value}) kurang dari batas minimal tahun {$targetYear} ({$targetRules['min_value']})";
                                            continue;
                                        }
                                        if (isset($targetRules['max_value']) && $numVal > floatval($targetRules['max_value'])) {
                                            $incompatibleReasons[] = "nilai angka pada '{$fKey}' ({$ans->answer_value}) melebihi batas maksimal tahun {$targetYear} ({$targetRules['max_value']})";
                                            continue;
                                        }
                                    }

                                    // Date bounds
                                    if ($targetField->field_type === 'date' && !empty($ans->answer_value)) {
                                        if (isset($targetRules['min_date']) && $ans->answer_value < $targetRules['min_date']) {
                                            $incompatibleReasons[] = "tanggal pada '{$fKey}' ({$ans->answer_value}) lebih awal dari batas minimal tahun {$targetYear} ({$targetRules['min_date']})";
                                            continue;
                                        }
                                        if (isset($targetRules['max_date']) && $ans->answer_value > $targetRules['max_date']) {
                                            $incompatibleReasons[] = "tanggal pada '{$fKey}' ({$ans->answer_value}) lebih lambat dari batas maksimal tahun {$targetYear} ({$targetRules['max_date']})";
                                            continue;
                                        }
                                    }

                                    // Checkbox items bounds
                                    if ($targetField->field_type === 'checkbox' && !empty($ans->answer_value)) {
                                        $count = count((array)$parsedVals);
                                        if (isset($targetRules['min_items']) && $count < $targetRules['min_items']) {
                                            $incompatibleReasons[] = "jumlah pilihan pada '{$fKey}' ({$count}) kurang dari batas minimal tahun {$targetYear} ({$targetRules['min_items']})";
                                            continue;
                                        }
                                        if (isset($targetRules['max_items']) && $count > $targetRules['max_items']) {
                                            $incompatibleReasons[] = "jumlah pilihan pada '{$fKey}' ({$count}) melebihi batas maksimal tahun {$targetYear} ({$targetRules['max_items']})";
                                            continue;
                                        }
                                    }

                                    // G. Cek berkas jika tipe file
                                    if ($targetField->field_type === 'file' && !empty($ans->answer_value)) {
                                        if (str_contains($ans->answer_value, '..') || str_starts_with($ans->answer_value, '/') || str_starts_with($ans->answer_value, '\\')) {
                                            $incompatibleReasons[] = "jalur berkas '{$fKey}' tidak aman";
                                            continue;
                                        }
                                        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($ans->answer_value)) {
                                            $incompatibleReasons[] = "berkas '{$fKey}' tidak ditemukan di penyimpanan server";
                                            continue;
                                        }
                                        if (!empty($targetRules['allowed_extensions']) && is_array($targetRules['allowed_extensions'])) {
                                            $ext = strtolower(pathinfo($ans->answer_value, PATHINFO_EXTENSION));
                                            $allowedExts = array_map('strtolower', $targetRules['allowed_extensions']);
                                            if (!in_array($ext, $allowedExts, true)) {
                                                $incompatibleReasons[] = "ekstensi berkas '{$fKey}' (.{$ext}) tidak diizinkan pada skema tahun {$targetYear}";
                                                continue;
                                            }
                                        }
                                        if (!empty($targetRules['max_file_size_kb']) && is_int($targetRules['max_file_size_kb'])) {
                                            $fileSizeKb = round(\Illuminate\Support\Facades\Storage::disk('local')->size($ans->answer_value) / 1024);
                                            if ($fileSizeKb > $targetRules['max_file_size_kb']) {
                                                $incompatibleReasons[] = "ukuran berkas '{$fKey}' ({$fileSizeKb} KB) melebihi batas maksimal tahun {$targetYear} ({$targetRules['max_file_size_kb']} KB)";
                                                continue;
                                            }
                                        }
                                    }
                                }

                                if (!empty($incompatibleReasons)) {
                                    $reasonText = implode(', ', array_slice($incompatibleReasons, 0, 3));
                                    return back()
                                        ->withInput()
                                        ->withErrors([
                                            'start_date' => "Tanggal mulai magang tidak dapat diubah ke tahun {$targetYear} karena draft pendaftaran memiliki isian formulir periode {$existingYear} yang tidak kompatibel dengan formulir periode {$targetYear} ({$reasonText}). Seluruh isian lama tetap dipertahankan."
                                        ]);
                                }

                                $isDraftYearChanged = true;
                                $schemaAdjustedNotice = "Formulir pendaftaran Anda telah disesuaikan dengan periode tahun {$targetYear} sesuai tanggal mulai magang yang dipilih.";
                            }
                        } elseif ($boundSchema && !empty($existing->start_date)) {
                            // Draft tidak memiliki custom answers: perubahan tahun bebas
                            try {
                                $existingYear = (int) \Carbon\Carbon::parse($existing->start_date)->year;
                                if ($existingYear !== $targetYear) {
                                    $isDraftYearChanged = true;
                                    $schemaAdjustedNotice = "Formulir pendaftaran Anda telah disesuaikan dengan periode tahun {$targetYear} sesuai tanggal mulai magang yang dipilih.";
                                }
                            } catch (\Throwable) {}
                        }
                    }
                }
            }

            if ($boundSchema) {
                $customValidation = $formBuilder->validateCustomAnswers($boundSchema, $request->all(), $existing);
                if (!$isDraft && !$customValidation['is_valid']) {
                    return back()->withInput()->withErrors($customValidation['errors']);
                }
                $cleanedCustomAnswers = $customValidation['cleaned'] ?? [];
            }
        }

        // Aturan validasi core — draft boleh isi sebagian, submit wajib semua
        $rules = $isDraft
            ? $this->draftRules()
            : $this->submitRules();

        // Jika skema aktif mengatur field, sesuaikan aturan validasi agar pertanyaan yang dinonaktifkan
        // atau dijadikan opsional oleh admin tidak memblokir submit pemagang.
        if ($boundSchema && !$isDraft) {
            $schemaFields = $boundSchema->fields()->get()->keyBy('field_key');
            foreach ($rules as $fieldKey => $rule) {
                if ($schemaField = $schemaFields->get($fieldKey)) {
                    if (!$schemaField->is_active || !$schemaField->is_required) {
                        if (is_string($rule)) {
                            $rules[$fieldKey] = str_replace('required', 'nullable', $rule);
                        } elseif (is_array($rule)) {
                            $rules[$fieldKey] = array_map(fn($r) => $r === 'required' ? 'nullable' : $r, $rule);
                        }
                    }
                }
            }
        }

        $validated = $request->validate($rules, [
            'phone_number.regex'               => 'No. HP hanya boleh berisi angka (10-15 digit).',
            'phone_number.required'            => 'No. HP aktif wajib diisi.',
            'fullname.required'                => 'Nama lengkap wajib diisi.',
            'student_id.required'              => 'Nomor Induk Mahasiswa / Nomor Induk Siswa Nasional wajib diisi.',
            'email.required'                   => 'Email wajib diisi.',
            'gender.required'                  => 'Jenis kelamin wajib dipilih.',
            'religion.required'                => 'Agama wajib dipilih.',
            'institution_name.required'        => 'Asal sekolah/kampus wajib diisi.',
            'study_program.required'           => 'Program studi wajib diisi.',
            'faculty.required'                 => 'Fakultas wajib diisi.',
            'internship_reason.required'       => 'Alasan magang wajib diisi.',
            'internship_interest.required'     => 'Program magang/PKL yang diminati wajib dipilih.',
            'wfo_readiness.required'           => 'Kesiapan WFO Yogyakarta wajib dipilih.',
            'schedule_shift_readiness.required' => 'Kesanggupan jadwal & shifting wajib dipilih.',
            'has_laptop.required'               => 'Kepemilikan laptop wajib dipilih.',
            'planned_duration.required'         => 'Durasi magang wajib dipilih/diisi.',
            'birth_place.max'                   => 'Tempat lahir maksimal 100 karakter.',
            'religion.in'                       => 'Pilihan agama tidak valid.',
            'cv_ktp_portofolio_pdf.mimes'       => 'Berkas lampiran harus berformat PDF.',
            'cv_ktp_portofolio_pdf.max'         => 'Ukuran berkas lampiran maksimal 10MB.',
        ]);

        // Normalisasi tanggal
        foreach (['born_date', 'start_date', 'end_date'] as $field) {
            if (!empty($validated[$field])) {
                try {
                    $validated[$field] = Carbon::parse($validated[$field])->format('Y-m-d');
                } catch (\Throwable) { /* biarkan string asli */ }
            } else {
                $validated[$field] = null;
            }
        }

        // Normalisasi boolean has_laptop
        if (isset($validated['has_laptop']) && $validated['has_laptop'] !== null && $validated['has_laptop'] !== '') {
            $validated['has_laptop'] = filter_var($validated['has_laptop'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
            $validated['has_laptop'] = null;
        }

        // Normalisasi string kosong birth_place & religion ke NULL
        if (array_key_exists('birth_place', $validated) && trim((string)$validated['birth_place']) === '') {
            $validated['birth_place'] = null;
        }
        if (array_key_exists('religion', $validated) && trim((string)$validated['religion']) === '') {
            $validated['religion'] = null;
        }

        // Cegah silent data loss: jika database belum memiliki kolom yang dibutuhkan,
        // tolak pengisian data baru dengan pesan validasi eksplisit, jangan membuang input secara diam-diam.
        $hasBirthPlaceCol = Schema::hasColumn('internship_registrations', 'birth_place');
        $hasReligionCol   = Schema::hasColumn('internship_registrations', 'religion');

        if (!$hasBirthPlaceCol && !empty($validated['birth_place'])) {
            return back()->withInput()->withErrors([
                'birth_place' => 'Kolom tempat lahir belum didukung oleh konfigurasi database saat ini.'
            ]);
        }

        if (!$hasReligionCol && !empty($validated['religion'])) {
            return back()->withInput()->withErrors([
                'religion' => 'Kolom agama belum didukung oleh konfigurasi database saat ini.'
            ]);
        }

        // Checkbox arrays → CSV
        $validated['owned_tools'] = $this->arrayToCsv($request->input('owned_tools'));
        $infoSourcesArr = $request->input('internship_info_sources', []);

        // Draft atau submit? Unanswered fields in draft remain null (No fake defaults)
        if ($isDraft) {
            if ($existing && !$existing->is_draft) {
                // Pendaftaran sudah disubmit resmi. Dilarang meregresikan status kembali ke STATUS_NEW
                unset($validated['internship_status']);
            } else {
                $validated['internship_status'] = IR::STATUS_NEW;
            }
        } else {
            if ($existing && !$existing->is_draft) {
                unset($validated['internship_status']);
            } else {
                $validated['internship_status'] = IR::STATUS_WAITING;
            }
            $validated['user_id'] = $user->id;
        }
        unset($validated['is_draft'], $validated['draft_saved_at']);

        // Pra-validasi Divisi & Status Institusi sebelum transaksi
        $divisionInput = $request->input('division_id') ?? $request->input('internship_interest') ?? ($validated['internship_interest'] ?? null);
        if (!empty($divisionInput) && $divisionInput !== '-') {
            $divisionId = self::resolveDivisionId($divisionInput);
            if ($divisionId) {
                $validated['division_id'] = $divisionId;
            } else {
                if ($isDraft) {
                    $validated['division_id'] = null;
                } else {
                    return back()->withInput()->withErrors([
                        'internship_interest' => 'Divisi yang dipilih tidak valid atau tidak tersedia.'
                    ]);
                }
            }
        } elseif (!$isDraft && empty($validated['division_id'])) {
            $validated['division_id'] = \App\Models\Division::where('is_active', true)->first()?->id ?? 1;
        }
        unset($validated['internship_interest']);

        if (!empty($validated['institution_name'])) {
            $inputName = trim($validated['institution_name']);
            $normName  = \App\Models\Institution::normalizeName($inputName);

            $existingInst = \App\Models\Institution::whereRaw('LOWER(name) = ?', [strtolower($inputName)])
                ->orWhere('normalized_name', $normName)
                ->first();

            $currentReg = IR::where('user_id', $user->id)->latest('id')->first();
            if ($existingInst && !$existingInst->is_active && (!$currentReg || $currentReg->institution_id !== $existingInst->id)) {
                return back()->withInput()->withErrors([
                    'institution_name' => "Institusi {$existingInst->name} saat ini sedang tidak aktif menerima pendaftaran baru."
                ]);
            }
        }

        // Submisi Atomik via Database Transaction (termasuk resolusi master data)
        $intern = null;
        $storedRegistrationFiles = [];
        $createdAttachments = [];
        $schemaAdjustedNotice = null;

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use (
                $user, $isDraft, $validated, $existing, $infoSourcesArr,
                $request, $boundSchema, $cleanedCustomAnswers, $formBuilder,
                $isDraftYearChanged, $targetYear,
                &$intern, &$storedRegistrationFiles, &$createdAttachments, &$schemaAdjustedNotice
            ) {
                $getRelationId = function($modelClass, $name, $extra = []) {
                    if (empty($name)) return null;
                    $name = ucwords(strtolower(trim($name)));
                    $query = $modelClass::whereRaw('LOWER(name) = ?', [strtolower($name)]);
                    foreach ($extra as $col => $val) {
                        if ($val !== null) {
                            $query->where($col, $val);
                        }
                    }
                    $existingModel = $query->first();
                    if ($existingModel) return $existingModel->id;
                    return $modelClass::create(array_merge(['name' => $name], $extra))->id;
                };

                if (!empty($validated['current_city'])) {
                    $validated['city_id'] = $getRelationId(\App\Models\City::class, $validated['current_city']);
                    unset($validated['current_city']);
                }

                if (!empty($validated['institution_name'])) {
                    $inputName = trim($validated['institution_name']);
                    $normName  = \App\Models\Institution::normalizeName($inputName);

                    $existingInst = \App\Models\Institution::whereRaw('LOWER(name) = ?', [strtolower($inputName)])
                        ->orWhere('normalized_name', $normName)
                        ->first();

                    if ($existingInst) {
                        $validated['institution_id'] = $existingInst->id;
                    } else {
                        $validated['institution_id'] = \App\Models\Institution::create([
                            'name'            => ucwords(strtolower($inputName)),
                            'normalized_name' => $normName,
                            'is_active'       => true,
                        ])->id;
                    }
                    unset($validated['institution_name']);
                }

                // Resolusi Fakultas & Program Studi di dalam transaksi
                if (!empty($validated['faculty'])) {
                    $extra = isset($validated['institution_id']) ? ['institution_id' => $validated['institution_id']] : [];
                    $validated['faculty_id'] = $getRelationId(\App\Models\Faculty::class, $validated['faculty'], $extra);
                    unset($validated['faculty']);
                }

                if (!empty($validated['study_program'])) {
                    $progName = trim($validated['study_program']);
                    $instId   = $validated['institution_id'] ?? null;

                    $scopedProg = \App\Models\StudyProgram::whereRaw('LOWER(name) = ?', [strtolower($progName)])
                        ->when($instId, function ($q) use ($instId) {
                            $q->whereHas('faculty', fn($fq) => $fq->where('institution_id', $instId));
                        })
                        ->when(isset($validated['faculty_id']), function ($q) use ($validated) {
                            $q->where('faculty_id', $validated['faculty_id']);
                        })
                        ->first();

                    if ($scopedProg) {
                        $validated['study_program_id'] = $scopedProg->id;
                        $validated['faculty_id']       = $scopedProg->faculty_id;
                    } else {
                        if (!isset($validated['faculty_id']) && $instId) {
                            $defaultFac = \App\Models\Faculty::where('institution_id', $instId)->first();
                            if (!$defaultFac) {
                                $defaultFac = \App\Models\Faculty::create([
                                    'institution_id' => $instId,
                                    'name'           => 'Fakultas Umum',
                                ]);
                            }
                            $validated['faculty_id'] = $defaultFac->id;
                        }

                        $extra = isset($validated['faculty_id']) ? ['faculty_id' => $validated['faculty_id']] : [];
                        $validated['study_program_id'] = $getRelationId(\App\Models\StudyProgram::class, $progName, $extra);
                    }
                    unset($validated['study_program']);
                }

                // Proteksi kompatibilitas environment unmigrated
                if (!Schema::hasColumn('internship_registrations', 'birth_place')) {
                    unset($validated['birth_place']);
                }
                if (!Schema::hasColumn('internship_registrations', 'religion')) {
                    unset($validated['religion']);
                }

                if ($existing) {
                    if ($isDraft) {
                        if ($existing->is_draft) {
                            $validated['is_draft'] = 1;
                            $validated['draft_saved_at'] = now();
                            $validated['internship_status'] = IR::STATUS_NEW;
                        } else {
                            // Pendaftaran sudah resmi disubmit: jangan pernah demosi menjadi draft atau mengubah status
                            $validated['is_draft'] = 0;
                            unset($validated['internship_status']);
                        }
                    } else {
                        $validated['is_draft'] = 0;
                        if ($existing->is_draft) {
                            $validated['internship_status'] = IR::STATUS_WAITING;
                        } else {
                            unset($validated['internship_status']);
                        }
                    }
                    $existing->fill($validated)->save();
                    $intern = $existing;

                    // Jika start_date berubah tahun pada draft yang belum memiliki snapshot,
                    // seluruh isian kompatibel dipertahankan utuh. DILARANG menghapus isian otomatis (Fix 1).
                    // Catat pesan penyesuaian untuk ditampilkan SETELAH transaksi commit (Fix 3).
                    if ($isDraftYearChanged && $boundSchema && !$existing->formSnapshot) {
                        $schemaAdjustedNotice = "Periode magang berhasil disesuaikan ke tahun {$targetYear}. Isian formulir yang kompatibel tetap dipertahankan.";
                    }
                } else {
                    $validated['user_id'] = $user->id;
                    if (!$isDraft) {
                        $validated['internship_status'] = IR::STATUS_WAITING;
                        $validated['is_draft'] = 0;
                        if ($user->role !== 'pemagang') {
                            $user->role = 'pemagang';
                            $user->save();
                        }
                    } else {
                        $validated['internship_status'] = IR::STATUS_NEW;
                        $validated['is_draft'] = 1;
                        $validated['draft_saved_at'] = now();
                    }
                    $intern = IR::create($validated);
                }

                // Bekukan snapshot imutabel & simpan custom answers jika skema aktif tersedia
                if ($intern && $boundSchema) {
                    if (!$isDraft) {
                        $formBuilder->freezeSnapshot($intern, $boundSchema);
                    }
                    if (!empty($cleanedCustomAnswers)) {
                        $newCustomFiles = $formBuilder->saveCustomAnswers($intern, $cleanedCustomAnswers, $boundSchema, $user);
                        if (!empty($newCustomFiles)) {
                            foreach ($newCustomFiles as $ncf) {
                                $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $ncf];
                            }
                        }
                    }
                }

                if ($intern) {
                    $yearFolder = $targetYear ?? date('Y');
                    $baseDir = "private/registrations/{$yearFolder}/{$intern->id}";

                    // 1. CV
                    if ($request->hasFile('cv')) {
                        $file = $request->file('cv');
                        if ($file->isValid()) {
                            $path = $this->storePrivateFile($file, $baseDir);
                            $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $path];
                            $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                'attachment_type'   => 'cv',
                                'category'          => 'cv',
                                'file_path'         => $path,
                                'original_name'     => $file->getClientOriginalName(),
                                'file_size'         => $file->getSize(),
                                'mime_type'         => $file->getMimeType() ?: 'application/pdf',
                                'drive_sync_status' => 'pending',
                            ]);
                        }
                    }

                    // 2. KTM / KTP (Identity)
                    if ($request->hasFile('identity')) {
                        $file = $request->file('identity');
                        if ($file->isValid()) {
                            $path = $this->storePrivateFile($file, $baseDir);
                            $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $path];
                            $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                'attachment_type'   => 'identity',
                                'category'          => 'identity',
                                'file_path'         => $path,
                                'original_name'     => $file->getClientOriginalName(),
                                'file_size'         => $file->getSize(),
                                'mime_type'         => $file->getMimeType() ?: 'application/pdf',
                                'drive_sync_status' => 'pending',
                            ]);
                        }
                    }

                    // 3. Portofolio (Bisa multi-file)
                    if ($request->hasFile('portfolio')) {
                        $files = is_array($request->file('portfolio')) ? $request->file('portfolio') : [$request->file('portfolio')];
                        foreach ($files as $file) {
                            if ($file && $file->isValid()) {
                                $path = $this->storePrivateFile($file, $baseDir);
                                $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $path];
                                $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                    'attachment_type'   => 'portfolio',
                                    'category'          => 'portfolio',
                                    'file_path'         => $path,
                                    'original_name'     => $file->getClientOriginalName(),
                                    'file_size'         => $file->getSize(),
                                    'mime_type'         => $file->getMimeType() ?: 'application/octet-stream',
                                    'drive_sync_status' => 'pending',
                                ]);
                            }
                        }
                    }

                    // 4. Surat Pengantar
                    if ($request->hasFile('cover_letter')) {
                        $file = $request->file('cover_letter');
                        if ($file->isValid()) {
                            $path = $this->storePrivateFile($file, $baseDir);
                            $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $path];
                            $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                'attachment_type'   => 'cover_letter',
                                'category'          => 'cover_letter',
                                'file_path'         => $path,
                                'original_name'     => $file->getClientOriginalName(),
                                'file_size'         => $file->getSize(),
                                'mime_type'         => $file->getMimeType() ?: 'application/pdf',
                                'drive_sync_status' => 'pending',
                            ]);
                        }
                    }

                    // 5. Proposal
                    if ($request->hasFile('proposal')) {
                        $file = $request->file('proposal');
                        if ($file->isValid()) {
                            $path = $this->storePrivateFile($file, $baseDir);
                            $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $path];
                            $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                'attachment_type'   => 'proposal',
                                'category'          => 'proposal',
                                'file_path'         => $path,
                                'original_name'     => $file->getClientOriginalName(),
                                'file_size'         => $file->getSize(),
                                'mime_type'         => $file->getMimeType() ?: 'application/pdf',
                                'drive_sync_status' => 'pending',
                            ]);
                        }
                    }

                    // 6. Dokumen Pendukung Tambahan (Bisa multi-file)
                    if ($request->hasFile('supporting_document')) {
                        $files = is_array($request->file('supporting_document')) ? $request->file('supporting_document') : [$request->file('supporting_document')];
                        foreach ($files as $file) {
                            if ($file && $file->isValid()) {
                                $path = $this->storePrivateFile($file, $baseDir);
                                $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $path];
                                $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                    'attachment_type'   => 'supporting_document',
                                    'category'          => 'supporting_document',
                                    'file_path'         => $path,
                                    'original_name'     => $file->getClientOriginalName(),
                                    'file_size'         => $file->getSize(),
                                    'mime_type'         => $file->getMimeType() ?: 'application/octet-stream',
                                    'drive_sync_status' => 'pending',
                                ]);
                            }
                        }
                    }

                    // 7. Foto Profil
                    if ($request->hasFile('profile_photo')) {
                        $file = $request->file('profile_photo');
                        if ($file->isValid()) {
                            $path = $this->storeFile($file, 'uploads');
                            $storedRegistrationFiles[] = ['disk' => 'public', 'path' => $path];
                            $intern->profile_photo = $path;
                            $intern->save();
                            $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                'attachment_type'   => 'profile_photo',
                                'category'          => 'profile_photo',
                                'file_path'         => $path,
                                'original_name'     => $file->getClientOriginalName(),
                                'file_size'         => $file->getSize(),
                                'mime_type'         => $file->getMimeType() ?: 'image/jpeg',
                                'drive_sync_status' => 'pending',
                            ]);
                        }
                    }

                    // 8. Berkas Gabungan Legacy (Kompatibilitas Penuh)
                    if ($request->hasFile('cv_ktp_portofolio_pdf')) {
                        $file = $request->file('cv_ktp_portofolio_pdf');
                        if ($file->isValid()) {
                            $path = $this->storePrivateFile($file, $baseDir);
                            $storedRegistrationFiles[] = ['disk' => 'local', 'path' => $path];
                            $createdAttachments[] = $this->createAttachmentSafely($intern, [
                                'attachment_type'   => 'cv_ktp_portofolio',
                                'category'          => 'legacy_bundle',
                                'file_path'         => $path,
                                'original_name'     => $file->getClientOriginalName(),
                                'file_size'         => $file->getSize(),
                                'mime_type'         => $file->getMimeType() ?: 'application/pdf',
                                'drive_sync_status' => 'pending',
                            ]);
                        }
                    }

                    // Sync Skills
                    $intern->skills()->delete();
                    $skillCategories = [
                        'design' => $validated['design_software'] ?? '',
                        'video' => $validated['video_software'] ?? '',
                        'programming' => $validated['programming_languages'] ?? '',
                        'digital_marketing' => $validated['digital_marketing_type'] ?? ''
                    ];
                    foreach ($skillCategories as $cat => $val) {
                        if (!empty($val) && $val !== '-') {
                            $intern->skills()->create(['skill_category' => $cat, 'skill_name' => $val]);
                        }
                    }

                    // Sync Tools
                    $intern->tools()->delete();
                    $mergedTools = collect(explode(',', $validated['owned_tools'] ?? ''))
                        ->map('trim')->filter(fn($val) => !empty($val) && $val !== '-')->unique();
                    foreach ($mergedTools as $t) {
                        $intern->tools()->create(['tool_name' => $t]);
                    }

                    // Sync Info Sources
                    $intern->infoSources()->delete();
                    if (!empty($infoSourcesArr)) {
                        foreach ($infoSourcesArr as $s) {
                            $intern->infoSources()->create(['source_name' => trim($s)]);
                        }
                    }

                    \App\Services\ContactMatchingService::attemptAutoLink($intern);

                    if (!$isDraft) {
                        \App\Services\ActivityLogger::log(
                            action: 'intern_registration_submitted',
                            description: "Pendaftaran magang berhasil dikirim oleh {$user->name}.",
                            subject: $intern,
                            metadata: [
                                'registration_id'   => $intern->id,
                                'institution_name'  => $intern->institution_name,
                                'division_id'       => $intern->division_id,
                                'internship_status' => $intern->internship_status,
                            ]
                        );

                        if (app(\App\Services\RegistrationSpreadsheetMappingService::class)->isEligibleForSync($intern)) {
                            try {
                                app(\App\Services\SpreadsheetSyncEngineService::class)->queueSyncForRegistration($intern);
                            } catch (\Throwable) {
                                // Outbound sync failure must not abort registration
                            }
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            foreach ($storedRegistrationFiles as $f) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($f['disk'])->delete($f['path']);
                } catch (\Throwable) {}
            }
            throw $e;
        }

        // Flash pesan penyesuaian skema HANYA SETELAH transaksi commit sukses (Fix 3)
        if ($schemaAdjustedNotice !== null) {
            session()->flash('schema_adjusted', $schemaAdjustedNotice);
        }

        // Sinkronisasi berkas pendaftaran ke Google Drive (CloudSyncManager)
        if (!empty($createdAttachments)) {
            $syncManager = app(\App\Services\CloudStorage\CloudSyncManager::class);
            foreach ($createdAttachments as $createdAtt) {
                if (!$createdAtt) {
                    continue;
                }
                try {
                    $syncManager->syncRegistrationAttachment($createdAtt, $user);
                } catch (\Throwable $driveEx) {
                    \Illuminate\Support\Facades\Log::warning("Drive sync failed for attachment #{$createdAtt->id}: " . $driveEx->getMessage());
                    \App\Jobs\UploadRegistrationAttachmentToDriveJob::dispatch($createdAtt->id);
                }
            }
        }

        if ($intern && in_array($intern->internship_status, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE], true)) {
            \App\Services\AbsenDjuraganSyncService::syncIntern($intern, null, $intern->internship_status);
        }

        if ($isDraft) {
            $savedTime = now()->format('H:i:s');
            return back()->with('success', "Draft formulir berhasil disimpan pada pukul {$savedTime}. Seluruh isian Anda tersimpan dengan aman di server.");
        }

        $isNewSubmission = !$existing;

        if (!$isNewSubmission) {
            return back()->with('success', 'Data pendaftaran berhasil diperbarui.');
        }

        // Logout setelah submit perdana — user harus login ulang untuk cek status
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('registration_success', true)
            ->with('success', 'Pendaftaran berhasil dikirim. Silakan masuk untuk memantau status magang Anda.');
    }

    private function draftRules(): array
    {
        // Draft: semua field opsional tanpa default palsu
        return [
            'fullname'                 => 'nullable|string|max:150',
            'born_date'                => 'nullable|string|max:255',
            'birth_place'              => 'nullable|string|max:100',
            'student_id'               => 'nullable|string|max:50',
            'email'                    => 'nullable|string|max:255',
            'gender'                   => 'nullable|string|max:50',
            'religion'                 => 'nullable|string|max:50',
            'phone_number'             => 'nullable|regex:/^[0-9]{0,15}$/',
            'institution_name'         => 'nullable|string|max:255',
            'study_program'            => 'nullable|string|max:255',
            'faculty'                  => 'nullable|string|max:255',
            'current_city'             => 'nullable|string|max:255',
            'internship_reason'        => 'nullable|string',
            'internship_type'          => 'nullable|string|max:50',
            'internship_arrangement'   => 'nullable|string|max:50',
            'current_status'           => 'nullable|string|max:100',
            'wfo_readiness'            => 'nullable|string|max:100',
            'schedule_shift_readiness' => 'nullable|string|max:100',
            'has_laptop'               => 'nullable|in:0,1,true,false',
            'planned_duration'         => 'nullable|string|max:50',
            'english_book_ability'     => 'nullable|string|max:50',
            'internship_interest'      => 'nullable|string|max:255',
            'start_date'               => 'nullable|string|max:255',
            'end_date'                 => 'nullable|string|max:255',
            // Multi-Upload Kategori Berkas (Modul 10)
            'cv'                       => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'identity'                 => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'portfolio'                => 'nullable|array',
            'portfolio.*'              => 'nullable|file|mimes:pdf,zip,rar,mp4,doc,docx,png,jpg,jpeg|max:10240',
            'cover_letter'             => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'proposal'                 => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'supporting_document'      => 'nullable|array',
            'supporting_document.*'    => 'nullable|file|mimes:pdf,doc,docx,zip,rar,png,jpg,jpeg|max:10240',
            'profile_photo'            => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cv_ktp_portofolio_pdf'    => 'nullable|file|mimes:pdf|max:10240',
            'portofolio_visual'        => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'design_software'          => 'nullable|string|max:255',
            'video_software'           => 'nullable|string|max:255',
            'programming_languages'    => 'nullable|string|max:255',
            'digital_marketing_type'   => 'nullable|string|max:255',
            'owned_tools'              => 'nullable|string|max:255',
            'supervisor_name'          => 'nullable|string|max:255',
            'supervisor_contact'       => 'nullable|string|max:50',
            'current_activities'       => 'nullable|string',
            'boarding_info'            => 'nullable|string|max:50',
            'social_media_instagram'   => 'nullable|string|max:255',
            'internship_info_sources'  => 'nullable|array',
            'internship_info_sources.*'=> 'nullable|string|max:100',
        ];
    }

    private function submitRules(): array
    {
        // Submit: wajib mengisi field contract form 2026.
        // Field legacy (born_date, faculty, english_book_ability, dll) tidak diwajibkan.
        // Berkas lampiran dan foto profil opsional saat submit awal namun divalidasi ketat bila diunggah.
        return array_merge($this->draftRules(), [
            // Data Diri
            'fullname'                 => 'required|string|max:150',
            'gender'                   => 'required|string|in:Laki-laki,Perempuan',
            'email'                    => 'required|email|max:255',
            'phone_number'             => 'required|regex:/^[0-9]{10,15}$/',

            // Data Akademik
            'current_status'           => 'required|string|max:100',
            'student_id'               => 'required|string|max:50',
            'institution_name'         => 'required|string|max:255',
            'study_program'            => 'required|string|max:255',

            // Data Magang
            'internship_interest'      => 'required|string|max:255',
            'internship_type'          => 'required|string|max:50',
            'internship_reason'        => 'required|string',
            'wfo_readiness'            => 'required|string|max:100',
            'schedule_shift_readiness' => 'required|string|max:100',
            'has_laptop'               => 'required|in:0,1,true,false',
            'planned_duration'         => 'required|string|max:50',
        ]);
    }

    private function arrayToCsv(mixed $input): ?string
    {
        if (!is_array($input)) return is_string($input) && $input !== '' ? $input : null;
        $vals = array_filter(array_map('trim', $input), fn($v) => $v !== '');
        return empty($vals) ? null : implode(', ', array_values($vals));
    }

    private function storeFile(\Illuminate\Http\UploadedFile $file, string $dir): string
    {
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $ext      = strtolower($file->getClientOriginalExtension());
        // Gunakan slug dengan underscore, hindari karakter spesial termasuk tanda kurung
        $safe     = Str::slug($original, '_');
        $i = 0;

        do {
            $name = $i === 0 ? "{$safe}.{$ext}" : "{$safe}_{$i}.{$ext}";
            $path = "{$dir}/{$name}";
            $i++;
        } while (Storage::disk('public')->exists($path));

        return $file->storeAs($dir, $name, 'public');
    }

    /**
     * Unduh berkas dokumen pendaftaran (CV/KTP/Portofolio) dengan kontrol akses ketat.
     */
    public function downloadDocument(RegistrationAttachment $attachment)
    {
        $user = auth()->user();
        if (!$user) {
            abort(401, 'Silakan login terlebih dahulu.');
        }

        $registration = $attachment->registration;
        if (!$registration) {
            abort(404, 'Data pendaftaran tidak ditemukan.');
        }

        $isAdminOrStaff = in_array($user->role, ['admin', 'hr_staff'], true);
        $isOwner        = (int) $registration->user_id === (int) $user->id;

        if (!$isAdminOrStaff && !$isOwner) {
            abort(403, 'Anda tidak memiliki hak akses untuk mengunduh berkas ini.');
        }

        $filePath     = $attachment->file_path;
        $originalName = $attachment->original_name ?: basename($filePath);

        // Sanitasi path traversal untuk mencegah akses berkas sembarang di luar direktori (S17.18G.4R Fix 2)
        if (str_contains($filePath, '..') || str_starts_with($filePath, '/') || str_starts_with($filePath, '\\')) {
            abort(400, 'Jalur berkas tidak valid.');
        }

        // 1. Cek pada private local disk
        if (Storage::disk('local')->exists($filePath)) {
            return Storage::disk('local')->download($filePath, $originalName);
        }

        // 2. Fallback untuk dokumen legacy pada public disk
        if (Storage::disk('public')->exists($filePath)) {
            return Storage::disk('public')->download($filePath, $originalName);
        }

        abort(404, 'Berkas dokumen tidak ditemukan di penyimpanan server.');
    }

    private function storePrivateFile(\Illuminate\Http\UploadedFile $file, string $dir = 'private/registrations'): string
    {
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $ext      = strtolower($file->getClientOriginalExtension());
        $safe     = Str::slug($original, '_');
        $i = 0;

        do {
            $name = $i === 0 ? "{$safe}.{$ext}" : "{$safe}_{$i}.{$ext}";
            $path = "{$dir}/{$name}";
            $i++;
        } while (Storage::disk('local')->exists($path));

        return $file->storeAs($dir, $name, 'local');
    }

    /**
     * Validasi string tanggal kalender secara ketat.
     * Mengembalikan string 'YYYY-MM-DD' yang valid atau null jika format/kalender tidak valid.
     * Mencegah crash HTTP 500 pada non-scalar (array, nested array) atau tanggal invalid seperti leap year salah (2027-02-29), range (2027-99-99).
     */
    public function validateCalendarDateString(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }
        $trimmed = trim($date);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches)) {
            return null;
        }
        $year  = (int) $matches[1];
        $month = (int) $matches[2];
        $day   = (int) $matches[3];

        if ($year < 2020 || $year > 2099) {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Resolusi target tahun pendaftaran secara deterministik HANYA dari start_date (atau snapshot historis).
     * Sesuai ketetapan bisnis resmi: TAHUN FORM BUILDER WAJIB MENGIKUTI TAHUN MULAI MAGANG (start_date).
     * DILARANG menggunakan created_at atau tahun kalender server.
     * Mengembalikan null jika start_date belum diisi (misal draft awal) atau tanggal tidak valid.
     */
    protected function resolveTargetRegistrationYear(?IR $registration, mixed $startDateInput = null): ?int
    {
        // 1. Jika pendaftaran historis telah memiliki snapshot skema terverifikasi, gunakan tahun snapshot
        if ($registration && $registration->formSnapshot && $registration->formSnapshot->schema) {
            return (int) $registration->formSnapshot->schema->year;
        }

        // 2. Sumber kebenaran satu-satunya untuk pendaftaran baru/draft adalah YEAR(start_date)
        $validatedInputDate = $this->validateCalendarDateString($startDateInput);
        if ($validatedInputDate !== null) {
            return (int) substr($validatedInputDate, 0, 4);
        }

        if ($registration && $registration->start_date) {
            $rawStartDate = $registration->start_date instanceof \DateTimeInterface
                ? $registration->start_date->format('Y-m-d')
                : substr((string) $registration->start_date, 0, 10);
            $validatedSavedDate = $this->validateCalendarDateString($rawStartDate);
            if ($validatedSavedDate !== null) {
                return (int) substr($validatedSavedDate, 0, 4);
            }
        }

        // Jangan pernah menggunakan created_at atau tahun kalender server!
        return null;
    }

    /**
     * Ambil FormSchema aktif terbit khusus untuk target tahun pendaftaran.
     * Mengembalikan null jika targetYear null atau skema tahun tersebut belum dipublikasikan.
     * Jangan diam-diam meminjam skema tahun lain atau tahun kalender server.
     */
    protected function resolveActiveSchemaForYear(?int $targetYear): ?\App\Models\FormSchema
    {
        if ($targetYear === null) {
            return null;
        }

        // Saat sudah masuk ke tahun berjalan (atau tahun berganti ke tahun berikutnya),
        // otomatis aktifkan & lengkapi skema tahun berjalan jika belum terbit/belum lengkap (di luar unit test).
        if (!app()->runningUnitTests() && $targetYear <= (int) now()->year) {
            try {
                app(\App\Services\FormBuilderService::class)->ensureYearFormInitialized($targetYear);
            } catch (\Throwable) {
            }
        }

        $schema = \App\Models\FormSchema::with('activeFields')
            ->where('year', $targetYear)
            ->activePublished()
            ->first();

        return $schema;
    }

    /**
     * Hapus berkas attachment yang diunggah pemagang sebelum submit final / saat revisi.
     */
    public function deleteAttachment(\App\Models\RegistrationAttachment $attachment)
    {
        $user = auth()->user();
        $registration = $attachment->registration;

        if (!$registration || $registration->user_id !== $user->id) {
            abort(403, 'Anda tidak memiliki hak akses untuk menghapus berkas ini.');
        }

        if (!$registration->is_draft && !in_array($registration->internship_status, [IR::STATUS_NEW, IR::STATUS_WAITING], true)) {
            return back()->withErrors(['status' => 'Berkas pendaftaran yang telah diproses tidak dapat dihapus.']);
        }

        if ($attachment->file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($attachment->file_path)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($attachment->file_path);
        }
        if ($attachment->file_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($attachment->file_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($attachment->file_path);
        }

        $fileName = $attachment->original_name;
        $attachment->delete();

        return back()->with('success', "Berkas '{$fileName}' berhasil dihapus.");
    }

    /**
     * Simpan attachment dengan kompatibilitas skema database (cek keberadaan kolom category & document_version).
     */
    protected function createAttachmentSafely(IR $intern, array $data): \App\Models\RegistrationAttachment
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('registration_attachments', 'category')) {
            unset($data['category']);
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('registration_attachments', 'document_version')) {
            unset($data['document_version']);
        }
        return $intern->attachments()->create($data);
    }

    /**
     * Resolusi ID Divisi dengan dukungan ID numerik, nama persis, kamus sinonim, dan flexible matching.
     */
    public static function resolveDivisionId(mixed $input): ?int
    {
        if (empty($input) || $input === '-') return null;

        // 1. Jika ID numerik dan ada di database (hanya kembalikan jika aktif)
        if (is_numeric($input)) {
            $div = \App\Models\Division::find((int) $input);
            if ($div) {
                return $div->is_active ? $div->id : null;
            }
        }

        $rawName = trim((string) $input);
        $clean   = strtolower($rawName);

        // 2. Cek kecocokan nama langsung (case-insensitive)
        $div = \App\Models\Division::whereRaw('LOWER(name) = ?', [$clean])->first();
        if ($div) {
            return $div->is_active ? $div->id : null;
        }

        // 3. Kamus sinonim & variasi nama
        $synonyms = [
            'programmer (front end / backend)'  => 'Programmer',
            'programmer (frontend / backend)'   => 'Programmer',
            'web programmer'                    => 'Programmer',
            'developer'                         => 'Programmer',
            'frontend'                          => 'Programmer',
            'backend'                           => 'Programmer',
            'administration'                    => 'Administrasi',
            'admin'                             => 'Administrasi',
            'adm'                               => 'Administrasi',
            'human resources (hr)'              => 'Human Resource',
            'human resources'                   => 'Human Resource',
            'hrd'                               => 'Human Resource',
            'hr'                                => 'Human Resource',
            'ui/ux'                             => 'UI/UX Designer',
            'uiux'                              => 'UI/UX Designer',
            'ui-ux'                             => 'UI/UX Designer',
            'photographer'                      => 'Fotografer',
            'fotografi'                         => 'Fotografer',
            'videographer'                      => 'Videografer',
            'videografi'                        => 'Videografer',
            'graphic designer (konten kreatif)' => 'Desain Grafis',
            'graphic designer'                  => 'Desain Grafis',
            'desainer grafis'                   => 'Desain Grafis',
            'social media'                      => 'Social Media Specialist',
            'smm'                               => 'Social Media Specialist',
            'content writter'                   => 'Content Writer',
            'penulis konten'                    => 'Content Writer',
            'sales & marketing'                 => 'Marketing / Sales',
            'sales and marketing'               => 'Marketing / Sales',
            'marketing'                         => 'Marketing / Sales',
            'sales'                             => 'Marketing / Sales',
            'public relations (marcomm)'        => 'Marcom/Public Relation',
            'public relations'                  => 'Marcom/Public Relation',
            'marcomm'                           => 'Marcom/Public Relation',
            'marcom'                            => 'Marcom/Public Relation',
            'pr'                                => 'Marcom/Public Relation',
            'tiktok'                            => 'Tiktok Creator',
            'kreator tiktok'                    => 'Tiktok Creator',
            'pengelasan'                        => 'Las',
            'welder'                            => 'Las',
            'welding'                           => 'Las',
            'animation'                         => 'Animasi',
            'animator'                          => 'Animasi',
            'cs'                                => 'Customer Service',
            'layanan pelanggan'                 => 'Customer Service',
            'host'                              => 'Host/Presenter',
            'presenter'                         => 'Host/Presenter',
            'voice over'                        => 'Voice Over Talent',
            'pengisi suara'                     => 'Voice Over Talent',
            'rnd'                               => 'Research & Development',
            'r&d'                               => 'Research & Development',
        ];

        if (isset($synonyms[$clean])) {
            $mapped = \App\Models\Division::where('name', $synonyms[$clean])->first();
            if ($mapped) {
                return $mapped->is_active ? $mapped->id : null;
            }
        }

        // 4. Flexible LIKE search (dua arah)
        $flexible = \App\Models\Division::where(function ($q) use ($clean) {
            $q->whereRaw('LOWER(name) LIKE ?', ["%{$clean}%"])
              ->orWhereRaw('? LIKE CONCAT("%", LOWER(name), "%")', [$clean]);
        })->first();

        if ($flexible) {
            return $flexible->is_active ? $flexible->id : null;
        }

        // 5. Jika benar-benar baru, buat record divisi baru yang aktif
        $newDiv = \App\Models\Division::create([
            'name'      => ucwords($clean),
            'slug'      => \Illuminate\Support\Str::slug($clean, '_'),
            'is_active' => true,
        ]);

        return $newDiv->id;
    }
}

