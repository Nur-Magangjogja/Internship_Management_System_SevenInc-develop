<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\FormSubmitSetting;
use App\Models\SpreadsheetConnection;
use App\Services\FormBuilderService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FormFieldController extends Controller
{
    protected FormBuilderService $formBuilderService;

    public function __construct(FormBuilderService $formBuilderService)
    {
        $this->formBuilderService = $formBuilderService;
    }

    /**
     * Periksa apakah tabel database skema dan field telah siap termigrasi.
     */
    protected function isSchemaReady(): bool
    {
        // Mendukung simulasi testing kesiapan skema tanpa mutasi destruktif DDL (S17.18G.4R Fix 4)
        if (config('internship.simulate_unmigrated_form_schema', false)) {
            return false;
        }

        return \Illuminate\Support\Facades\Schema::hasTable('form_schemas')
            && \Illuminate\Support\Facades\Schema::hasTable('form_fields');
    }

    /**
     * Validasi dan ekstrak parameter tahun skema formulir.
     * Di luar unit test, tahun otomatis mengikuti tahun berjalan (Tahun Ini) dan tahun berikutnya (Tahun Depan).
     */
    protected function resolveYear(Request $request): int
    {
        $currentYear = (int) now()->year;
        $nextYear    = $currentYear + 1;
        $raw         = $request->input('year', $currentYear);
        if (!is_numeric($raw)) {
            abort(400, 'Tahun tidak valid.');
        }
        $year = (int) $raw;
        if ($year < 2020 || $year > 2099) {
            abort(400, 'Tahun formulir harus berada dalam rentang 2020 - 2099.');
        }

        if (!app()->runningUnitTests()) {
            if ($year < $currentYear) {
                return $currentYear;
            }
            if ($year > $nextYear) {
                return $nextYear;
            }
        }

        return $year;
    }

    /**
     * Ambil daftar divisi aktif beserta fallback standar jika tabel divisions kosong.
     */
    protected function getActiveDivisions(): \Illuminate\Support\Collection
    {
        $divisions = \App\Models\Division::active()->pluck('name');
        if ($divisions->isEmpty()) {
            return collect([
                'UI/UX Designer', 'Marketing / Sales', 'Social Media Specialist',
                'Programmer', 'Marcom/Public Relation', 'Tiktok Creator',
                'Desain Grafis', 'Content Writer', 'Host/Presenter',
                'Fotografer', 'Content Planner', 'Voice Over Talent',
                'Videografer', 'Administrasi', 'Las', 'Digital Marketing',
                'Project Manager', 'Human Resource', 'Research & Development',
                'Animasi', 'Customer Service',
            ]);
        }
        return $divisions;
    }

    /**
     * GET /admin/form-settings/fields
     * Halaman utama pengaturan form dengan live preview dan draft management.
     */
    public function index(Request $request)
    {
        if (!$this->isSchemaReady()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Layanan Pengaturan Formulir belum siap (tabel database belum termigrasi).',
                ], 503);
            }
            return view('admin.form_settings.unavailable', [
                'message' => 'Layanan Pengaturan Formulir Pendaftaran sedang dalam tahap persiapan. Tabel database formulir dinamis belum termigrasi.',
            ]);
        }

        $currentYear = (int) now()->year;
        $nextYear    = $currentYear + 1;
        $year        = $this->resolveYear($request);

        // Resolusi Brand & Universal Spreadsheet Connection
        $brands = Brand::orderBy('name')->get();
        $brandId = $request->input('brand_id');
        $effectiveBrand = null;

        if ($brandId === 'global') {
            $effectiveBrand = null;
        } elseif ($brandId !== null && $brandId !== '') {
            $effectiveBrand = $brands->firstWhere('id', (int) $brandId);
        } elseif ($request->attributes->has('current_brand')) {
            $effectiveBrand = $request->attributes->get('current_brand');
        } elseif (session()->has('registration_brand_id')) {
            $effectiveBrand = $brands->firstWhere('id', (int) session('registration_brand_id'));
        } else {
            $effectiveBrand = $brands->firstWhere('is_default', true) ?? $brands->first();
        }

        $currentDraft    = $this->formBuilderService->getOrCreateDraft($year, auth()->user(), $effectiveBrand?->id);
        $activePublished = $this->formBuilderService->resolveEffectivePublishedSchema($effectiveBrand?->id, $year);

        // Ambil field dari draf aktif dikelompokkan ke 5 grup
        $allActiveDraftFields = $currentDraft->fields()->notArchived()->get();
        $groupedFields = [
            FormField::GROUP_DATA_DIRI          => $allActiveDraftFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_DATA_DIRI)->values(),
            FormField::GROUP_PILIHAN_DURASI     => $allActiveDraftFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_PILIHAN_DURASI)->values(),
            FormField::GROUP_BERKAS             => $allActiveDraftFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_BERKAS)->values(),
            FormField::GROUP_INFORMASI_TAMBAHAN => $allActiveDraftFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_INFORMASI_TAMBAHAN)->values(),
            FormField::GROUP_FINALISASI_SUBMIT  => $allActiveDraftFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_FINALISASI_SUBMIT)->values(),
        ];
        $mainFields     = $allActiveDraftFields->filter(fn($f) => $f->resolved_group !== FormField::GROUP_INFORMASI_TAMBAHAN)->values();
        $extraFields    = $groupedFields[FormField::GROUP_INFORMASI_TAMBAHAN];
        $archivedFields = $currentDraft->fields()->where('is_archived', true)->get();

        $types     = FormField::supportedTypes();
        $groups    = FormField::groups();
        $divisions = $this->getActiveDivisions();

        $brandConnection = null;
        $mappedColumnsCount = 0;
        $tabProfilesCount = 0;

        if ($effectiveBrand) {
            $brandConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
                ->where('brand_id', $effectiveBrand->id)
                ->with(['tabProfiles', 'schemaVersions.columnMappings'])
                ->first();

            if (!$brandConnection) {
                $brandConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
                    ->whereNull('brand_id')
                    ->with(['tabProfiles', 'schemaVersions.columnMappings'])
                    ->first();
            }

            if ($brandConnection) {
                $activeSchema = $brandConnection->schemaVersions()->where('is_active', true)->first();
                if ($activeSchema) {
                    $mappedColumnsCount = $activeSchema->columnMappings()->count();
                }
                $tabProfilesCount = $brandConnection->tabProfiles()->count();
            }
        }

        $submitSettings = FormSubmitSetting::forYear($year, $effectiveBrand?->id);

        return view('admin.form_settings.form_fields', compact(
            'groupedFields', 'mainFields', 'extraFields', 'archivedFields', 'types', 'groups',
            'currentDraft', 'activePublished', 'year', 'currentYear', 'nextYear', 'divisions',
            'brands', 'effectiveBrand', 'brandConnection', 'mappedColumnsCount', 'tabProfilesCount',
            'submitSettings'
        ));
    }

    /**
     * POST /admin/form-settings/fields
     * Tambah field baru ke dalam draf aktif.
     */
    public function store(Request $request)
    {
        if (!$this->isSchemaReady()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => 'Layanan Pengaturan Formulir belum siap.'], 503);
            }
            return back()->with('error', 'Layanan Pengaturan Formulir belum siap.');
        }

        $year = $this->resolveYear($request);
        $brandId = $request->input('brand_id');
        $effectiveBrandId = ($brandId && $brandId !== 'global') ? (int) $brandId : null;

        $validated = $request->validate([
            'field_type'       => 'required|in:' . implode(',', array_keys(FormField::supportedTypes())),
            'label'            => 'required|string|max:255',
            'placeholder'      => 'nullable|string|max:255',
            'helper_text'      => 'nullable|string|max:500',
            'is_required'      => 'nullable|boolean',
            'group_name'       => 'nullable|in:,data_diri_edukasi,pilihan_durasi,berkas_portofolio,informasi_tambahan,finalisasi_submit,main',
            'column_span'      => 'nullable|integer|in:1,2',
            'options'          => 'nullable|string', // JSON string dari textarea
            'validation_rules' => 'nullable',
        ]);

        $currentDraft = $this->formBuilderService->getOrCreateDraft($year, auth()->user(), $effectiveBrandId);

        // Buat field_key unik dari label
        $baseKey = 'custom_' . Str::slug($validated['label'], '_');
        $key     = $baseKey;
        $i       = 1;
        while ($currentDraft->fields()->withTrashed()->where('field_key', $key)->exists()) {
            $key = $baseKey . '_' . $i++;
        }

        $options = null;
        if (!empty($validated['options'])) {
            $decoded = json_decode($validated['options'], true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        $validationRules = null;
        if (!empty($validated['validation_rules'])) {
            if (is_array($validated['validation_rules'])) {
                $validationRules = $validated['validation_rules'];
            } elseif (is_string($validated['validation_rules'])) {
                $decoded = json_decode($validated['validation_rules'], true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $msg = 'Format validation_rules tidak valid (harus berupa JSON object).';
                    if ($request->wantsJson() || $request->ajax()) {
                        return response()->json(['ok' => false, 'message' => $msg], 422);
                    }
                    return back()->withInput()->withErrors(['validation_rules' => $msg])->with('error', $msg);
                }
                $validationRules = $decoded;
            }
        }

        try {
            $field = $this->formBuilderService->addFieldToDraft($currentDraft, [
                'field_key'        => $key,
                'field_type'       => $validated['field_type'],
                'label'            => trim($validated['label']),
                'placeholder'      => $validated['placeholder'] ?? null,
                'helper_text'      => $validated['helper_text'] ?? null,
                'is_required'      => (bool) ($validated['is_required'] ?? false),
                'is_active'        => true,
                'is_system'        => false,
                'group_name'       => ($validated['group_name'] ?? null) ?: null,
                'column_span'      => (int) ($validated['column_span'] ?? 1),
                'options'          => $options,
                'validation_rules' => $validationRules,
            ]);
            $this->formBuilderService->syncDraftToActivePublished($currentDraft);
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->withInput()->withErrors(['label' => $e->getMessage()])->with('error', $e->getMessage());
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => 'Field berhasil ditambahkan ke draft.', 'field' => $field]);
        }

        $redirectParams = ['year' => $year];
        if ($effectiveBrandId !== null) {
            $redirectParams['brand_id'] = $effectiveBrandId;
        }

        return redirect()->route('admin.form-settings.fields', $redirectParams)
            ->with('success', "Pertanyaan \"{$validated['label']}\" berhasil ditambahkan ke draf (masih bersifat sementara & dapat dihapus sebelum dipublikasikan).");
    }

    /**
     * PUT /admin/form-settings/fields/{field}
     * Update field dalam draf aktif.
     */
    public function update(Request $request, FormField $field)
    {
        $validated = $request->validate([
            'label'            => 'required|string|max:255',
            'placeholder'      => 'nullable|string|max:255',
            'helper_text'      => 'nullable|string|max:500',
            'is_required'      => 'nullable|boolean',
            'is_active'        => 'nullable|boolean',
            'column_span'      => 'nullable|integer|in:1,2',
            'options'          => 'nullable|string',
            'field_type'       => 'nullable|in:' . implode(',', array_keys(FormField::supportedTypes())),
            'group_name'       => 'nullable|in:,data_diri_edukasi,pilihan_durasi,berkas_portofolio,informasi_tambahan,finalisasi_submit,main',
            'validation_rules' => 'nullable',
        ]);

        $updateData = [
            'label'       => trim($validated['label']),
            'placeholder' => array_key_exists('placeholder', $validated) ? $validated['placeholder'] : $field->placeholder,
            'helper_text' => array_key_exists('helper_text', $validated) ? $validated['helper_text'] : $field->helper_text,
            'is_required' => array_key_exists('is_required', $validated) && $validated['is_required'] !== null
                                ? (bool) $validated['is_required']
                                : $field->is_required,
            'is_active'   => array_key_exists('is_active', $validated) && $validated['is_active'] !== null
                                ? (bool) $validated['is_active']
                                : $field->is_active,
            'column_span' => (int) ($validated['column_span'] ?? $field->column_span),
        ];

        if (array_key_exists('group_name', $validated)) {
            $updateData['group_name'] = $validated['group_name'] ?: null;
        }

        if (!$field->is_system && !$field->isStructuralLocked() && !empty($validated['field_type'])) {
            $updateData['field_type'] = $validated['field_type'];
        }

        if (isset($validated['options']) && $validated['options'] !== null && $validated['options'] !== '') {
            $decoded = json_decode($validated['options'], true);
            if (is_array($decoded)) {
                $updateData['options'] = $decoded;
            }
        }

        if (array_key_exists('validation_rules', $validated)) {
            $rawRules = $validated['validation_rules'];
            if (empty($rawRules)) {
                $updateData['validation_rules'] = null;
            } elseif (is_array($rawRules)) {
                $updateData['validation_rules'] = $rawRules;
            } elseif (is_string($rawRules)) {
                $decoded = json_decode($rawRules, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $msg = 'Format validation_rules tidak valid (harus berupa JSON object).';
                    if ($request->wantsJson() || $request->ajax()) {
                        return response()->json(['ok' => false, 'message' => $msg], 422);
                    }
                    return back()->withInput()->withErrors(['validation_rules' => $msg])->with('error', $msg);
                }
                $updateData['validation_rules'] = $decoded;
            }
        }

        try {
            $updated = $this->formBuilderService->updateFieldInDraft($field, $updateData);
            if ($field->schema) {
                $this->formBuilderService->syncDraftToActivePublished($field->schema);
            }
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->withInput()->withErrors(['label' => $e->getMessage()])->with('error', $e->getMessage());
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok'      => true,
                'message' => 'Pertanyaan berhasil diperbarui.',
                'field'   => $updated,
            ]);
        }

        $targetYear = $field->schema?->year ?? $this->resolveYear($request);
        return redirect()->route('admin.form-settings.fields', ['year' => $targetYear])
            ->with('success', "Pertanyaan \"{$updated->label}\" berhasil diperbarui.");
    }

    /**
     * POST /admin/form-settings/fields/publish
     * Publikasikan versi formulir draf aktif secara atomik.
     */
    public function publish(Request $request)
    {
        if (!$this->isSchemaReady()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => 'Layanan Pengaturan Formulir belum siap.'], 503);
            }
            return redirect()->route('admin.form-settings.fields')
                ->with('error', 'Layanan Pengaturan Formulir belum siap.');
        }

        $year = $this->resolveYear($request);
        $brandId = $request->input('brand_id');
        $effectiveBrandId = ($brandId && $brandId !== 'global') ? (int) $brandId : null;
        $currentDraft = $this->formBuilderService->getOrCreateDraft($year, auth()->user(), $effectiveBrandId);

        try {
            $published = $this->formBuilderService->publishVersion($currentDraft, auth()->user());
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            $redirectParams = ['year' => $year];
            if ($effectiveBrandId !== null) {
                $redirectParams['brand_id'] = $effectiveBrandId;
            }
            return redirect()->route('admin.form-settings.fields', $redirectParams)
                ->with('error', "Gagal mempublikasikan versi formulir: {$e->getMessage()}");
        }

        // Ambil status outbox event yang dibuat secara atomik dalam transaksi publikasi
        $outboxEvent = \App\Models\IntegrationOutboxEvent::where('aggregate_type', \App\Models\FormSchema::class)
            ->where('aggregate_id', (string) $published->id)
            ->latest('id')
            ->first();

        $remoteSyncStatus = $outboxEvent ? $outboxEvent->status : 'pending';
        $remoteSyncMessage = $remoteSyncStatus === \App\Models\IntegrationOutboxEvent::STATUS_SKIPPED
            ? 'Koneksi Google Spreadsheet belum dikonfigurasi atau dinonaktifkan.'
            : 'Sinkronisasi Spreadsheet dijadwalkan secara asinkron melalui antrean outbox terverifikasi.';

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok'                => true,
                'form_status'       => 'published',
                'sheet_sync_status' => $remoteSyncStatus,
                'message'           => "Versi {$published->version_code} berhasil dipublikasikan lokal. Status remote spreadsheet: {$remoteSyncStatus}.",
                'schema'            => $published,
            ]);
        }

        $flashType = $remoteSyncStatus === 'verified' ? 'success' : 'warning';
        $redirectParams = ['year' => $year];
        if ($effectiveBrandId !== null) {
            $redirectParams['brand_id'] = $effectiveBrandId;
        }
        return redirect()->route('admin.form-settings.fields', $redirectParams)
            ->with($flashType, "Versi {$published->version_code} berhasil dipublikasikan di database. Status sinkronisasi Spreadsheet: {$remoteSyncStatus} ({$remoteSyncMessage}).");
    }

    /**
     * POST /admin/form-settings/fields/sync-previous
     * Salin / sinkronkan pertanyaan dari form Tahun Ini ke draf Tahun Depan.
     */
    public function syncFromPreviousYear(Request $request)
    {
        if (!$this->isSchemaReady()) {
            return redirect()->route('admin.form-settings.fields')
                ->with('error', 'Layanan Pengaturan Formulir belum siap.');
        }

        $currentYear = (int) now()->year;
        $nextYear    = $currentYear + 1;
        $brandId     = $request->input('brand_id');
        $effectiveBrandId = ($brandId && $brandId !== 'global') ? (int) $brandId : null;

        try {
            $this->formBuilderService->syncDraftFromPreviousYear($nextYear, auth()->user(), $effectiveBrandId);
        } catch (\Throwable $e) {
            $redirectParams = ['year' => $nextYear];
            if ($effectiveBrandId !== null) {
                $redirectParams['brand_id'] = $effectiveBrandId;
            }
            return redirect()->route('admin.form-settings.fields', $redirectParams)
                ->with('error', "Gagal menyalin struktur form: {$e->getMessage()}");
        }

        $redirectParams = ['year' => $nextYear];
        if ($effectiveBrandId !== null) {
            $redirectParams['brand_id'] = $effectiveBrandId;
        }
        return redirect()->route('admin.form-settings.fields', $redirectParams)
            ->with('success', "Struktur pertanyaan Form Tahun {$nextYear} berhasil disamakan dengan Form Tahun {$currentYear}.");
    }

    /**
     * POST /admin/form-settings/fields/fork-global
     * Salin template baseline global menjadi Draf Skema Khusus Brand.
     */
    public function forkGlobalTemplate(Request $request)
    {
        if (!$this->isSchemaReady()) {
            return redirect()->route('admin.form-settings.fields')->with('error', 'Layanan Pengaturan Formulir belum siap.');
        }

        $year = $this->resolveYear($request);
        $brandId = (int) $request->input('brand_id');
        if (!$brandId) {
            return back()->with('error', 'Brand ID wajib dipilih untuk menyalin dari template global.');
        }

        try {
            $draft = $this->formBuilderService->forkGlobalTemplateToBrandDraft($year, $brandId, auth()->user());
            return redirect()->route('admin.form-settings.fields', ['year' => $year, 'brand_id' => $brandId])
                ->with('success', "Template Baseline Global berhasil disalin menjadi Draf Skema Khusus ({$draft->version_code}).");
        } catch (\Throwable $e) {
            return back()->with('error', "Gagal menyalin template global: {$e->getMessage()}");
        }
    }

    /**
     * POST /admin/form-settings/fields/{field}/archive
     * Arsipkan field (soft-archive).
     */
    public function archive(FormField $field)
    {
        $this->formBuilderService->archiveField($field);
        if ($field->schema) {
            $this->formBuilderService->syncDraftToActivePublished($field->schema);
        }

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'ok'      => true,
                'message' => "Pertanyaan \"{$field->label}\" berhasil diarsipkan.",
            ]);
        }

        return redirect()->route('admin.form-settings.fields', ['year' => $field->schema?->year ?? (int) now()->year])
            ->with('success', "Pertanyaan \"{$field->label}\" berhasil diarsipkan.");
    }

    /**
     * POST /admin/form-settings/fields/{field}/toggle
     * Aktifkan / nonaktifkan field (is_active) khusus pada skema draft.
     */
    public function toggle(FormField $field)
    {
        if (!$field->schema || !$field->schema->isDraft()) {
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Hanya pertanyaan pada skema berstatus draft yang dapat diaktifkan atau dinonaktifkan.',
                ], 422);
            }
            return redirect()->route('admin.form-settings.fields')
                ->with('error', 'Hanya pertanyaan pada skema berstatus draft yang dapat diaktifkan atau dinonaktifkan.');
        }

        $field->update(['is_active' => !$field->is_active]);
        $this->formBuilderService->syncDraftToActivePublished($field->schema);
        $status = $field->is_active ? 'diaktifkan' : 'dinonaktifkan';

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'ok'        => true,
                'is_active' => $field->is_active,
                'message'   => "Field \"{$field->label}\" berhasil {$status}.",
            ]);
        }

        return redirect()->route('admin.form-settings.fields', ['year' => $field->schema?->year ?? (int) now()->year])
            ->with('success', "Field \"{$field->label}\" berhasil {$status}.");
    }

    /**
     * POST /admin/form-settings/fields/{field}/toggle-required
     * Toggle wajib diisi (is_required) khusus pada skema draft.
     */
    public function toggleRequired(FormField $field)
    {
        if (!$field->schema || !$field->schema->isDraft()) {
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Hanya pertanyaan pada skema berstatus draft yang dapat diubah status wajibnya.',
                ], 422);
            }
            return redirect()->route('admin.form-settings.fields')
                ->with('error', 'Hanya pertanyaan pada skema berstatus draft yang dapat diubah status wajibnya.');
        }

        $field->update(['is_required' => !$field->is_required]);
        $this->formBuilderService->syncDraftToActivePublished($field->schema);
        $status = $field->is_required ? 'dijadikan wajib' : 'dijadikan opsional';

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'ok'          => true,
                'is_required' => $field->is_required,
                'message'     => "Field \"{$field->label}\" berhasil {$status}.",
            ]);
        }

        return redirect()->route('admin.form-settings.fields', ['year' => $field->schema?->year ?? (int) now()->year])
            ->with('success', "Field \"{$field->label}\" berhasil {$status}.");
    }

    /**
     * POST /admin/form-settings/fields/reorder
     * Reorder via AJAX drag & drop dengan validasi skema ketat (S17.18G.5 Wave 2A Fix 4).
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:form_fields,id',
        ]);

        $ids = $validated['ids'];

        // 1. Tolak duplikasi ID (Fix 4.5)
        if (count($ids) !== count(array_unique($ids))) {
            return response()->json([
                'ok'      => false,
                'message' => 'Ditemukan ID duplikat dalam permintaan reorder.',
            ], 422);
        }

        // 2. Muat seluruh field dengan relasi skema
        $fields = FormField::with('schema')->whereIn('id', $ids)->get()->keyBy('id');

        if ($fields->count() !== count($ids)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Sebagian field tidak ditemukan di database.',
            ], 422);
        }

        // 3. Validasi skema: Semua field harus memiliki skema dan berasal dari SATU skema yang sama (Fix 4.2)
        $schemaIds = $fields->pluck('form_schema_id')->unique()->filter();
        if ($schemaIds->count() !== 1) {
            return response()->json([
                'ok'      => false,
                'message' => 'Seluruh field yang direorder harus berasal dari satu skema formulir yang sama.',
            ], 422);
        }

        $schema = $fields->first()->schema;
        if (!$schema) {
            return response()->json([
                'ok'      => false,
                'message' => 'Skema formulir untuk field yang direorder tidak ditemukan.',
            ], 422);
        }

        // 4. Tolak jika skema berstatus published atau archived (Fix 4.3)
        if (!$schema->isDraft()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Reorder urutan pertanyaan hanya diizinkan pada skema berstatus draft.',
            ], 422);
        }

        // 5. Validasi tahun jika disertakan dalam request (Fix 4.4)
        if ($request->has('year')) {
            $expectedYear = (int) $request->input('year');
            if ($schema->year !== $expectedYear) {
                return response()->json([
                    'ok'      => false,
                    'message' => "Field yang direorder berasal dari skema tahun {$schema->year}, bukan tahun {$expectedYear}.",
                ], 422);
            }
        }

        // 6. Jalankan update urutan secara atomik dalam database transaction dengan model update (Fix 4.7 & 4.8)
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            foreach ($ids as $order => $id) {
                $field = $fields->get($id);
                // Update via Eloquent model agar model guard tetap dihormati
                $field->update([
                    'sort_order' => ($order + 1) * 10,
                ]);
            }
            \Illuminate\Support\Facades\DB::commit();
            $this->formBuilderService->syncDraftToActivePublished($schema);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'ok'      => false,
                'message' => 'Gagal mengubah urutan pertanyaan: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Urutan pertanyaan berhasil diperbarui.',
        ]);
    }

    /**
     * DELETE /admin/form-settings/fields/{field}
     * Hapus field (hanya diizinkan jika belum dipublikasikan dan belum memiliki jawaban).
     */
    public function destroy(FormField $field)
    {
        $targetYear = $field->schema?->year ?? (int) now()->year;
        $schema     = $field->schema;

        if ($field->is_system) {
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Field inti sistem tidak dapat dihapus.',
                ], 422);
            }

            return redirect()->route('admin.form-settings.fields', ['year' => $targetYear])
                ->with('error', 'Field inti sistem tidak dapat dihapus.');
        }

        $label = $field->label;

        try {
            $this->formBuilderService->deleteFieldFromDraft($field);
            if ($schema) {
                $this->formBuilderService->syncDraftToActivePublished($schema);
            }
        } catch (\Throwable $e) {
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            return redirect()->route('admin.form-settings.fields', ['year' => $targetYear])->with('error', $e->getMessage());
        }

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json(['ok' => true, 'message' => "Field \"{$label}\" berhasil dihapus."]);
        }

        return redirect()->route('admin.form-settings.fields', ['year' => $targetYear])
            ->with('success', "Field \"{$label}\" berhasil dihapus.");
    }

    /**
     * GET /admin/form-settings/fields/{field}/data
     */
    public function show(FormField $field)
    {
        $fieldData = $field->toArray();
        $fieldData['is_deletable'] = $field->isDeletable();
        $fieldData['is_structural_locked'] = $field->isStructuralLocked();
        $fieldData['group_name'] = $field->resolved_group;
        return response()->json(['field' => $fieldData]);
    }

    /**
     * GET /admin/form-settings/fields/preview-data
     */
    public function previewData(Request $request)
    {
        if (!$this->isSchemaReady()) {
            return response()->json([
                'ok'           => false,
                'main_fields'  => [],
                'extra_fields' => [],
                'divisions'    => [],
                'draft_version'=> 'offline',
            ], 503);
        }

        $year = $this->resolveYear($request);
        $brandId = $request->input('brand_id');
        $effectiveBrandId = ($brandId && $brandId !== 'global') ? (int) $brandId : null;
        $currentDraft = $this->formBuilderService->getOrCreateDraft($year, auth()->user(), $effectiveBrandId);
        $allFields = $currentDraft->fields()->active()->get();

        $groupedFields = [
            'data_diri_edukasi'  => $allFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_DATA_DIRI)->values(),
            'pilihan_durasi'     => $allFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_PILIHAN_DURASI)->values(),
            'berkas_portofolio'  => $allFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_BERKAS)->values(),
            'informasi_tambahan' => $allFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_INFORMASI_TAMBAHAN)->values(),
            'finalisasi_submit'  => $allFields->filter(fn($f) => $f->resolved_group === FormField::GROUP_FINALISASI_SUBMIT)->values(),
        ];
        $mainFields  = $allFields->filter(fn($f) => $f->resolved_group !== FormField::GROUP_INFORMASI_TAMBAHAN)->values();
        $extraFields = $groupedFields['informasi_tambahan'];
        $divisions   = $this->getActiveDivisions();
        $brandId     = $request->input('brand_id');
        $submitSettings = FormSubmitSetting::forYear($year, $brandId ? (int) $brandId : null);

        return response()->json([
            'ok'              => true,
            'grouped_fields'  => $groupedFields,
            'main_fields'     => $mainFields,
            'extra_fields'    => $extraFields,
            'divisions'       => $divisions,
            'submit_settings' => $submitSettings,
            'draft_version'   => $currentDraft->version_code,
            'year'            => $year,
        ]);
    }

    /**
     * POST /admin/form-settings/fields/submit-settings
     * Simpan pengaturan khusus area No 5 Submit.
     */
    public function saveSubmitSettings(Request $request)
    {
        $validated = $request->validate([
            'year'               => 'required|integer',
            'brand_id'           => 'nullable|integer',
            'section_title'      => 'required|string|max:255',
            'section_subtitle'   => 'nullable|string|max:255',
            'show_notice_box'    => 'nullable|boolean',
            'notice_title'       => 'nullable|string|max:100',
            'notice_text'        => 'nullable|string|max:2000',
            'admin_wa_number'    => 'nullable|string|max:50',
            'admin_wa_message'   => 'nullable|string|max:255',
            'submit_button_text' => 'required|string|max:100',
            'save_draft_enabled' => 'nullable|boolean',
        ]);

        $year = (int) $validated['year'];
        $brandId = !empty($validated['brand_id']) ? (int) $validated['brand_id'] : null;

        $setting = FormSubmitSetting::updateOrCreate(
            ['year' => $year, 'brand_id' => $brandId],
            [
                'section_title'      => trim($validated['section_title']),
                'section_subtitle'   => isset($validated['section_subtitle']) ? trim($validated['section_subtitle']) : '',
                'show_notice_box'    => $request->boolean('show_notice_box'),
                'notice_title'       => !empty($validated['notice_title']) ? trim($validated['notice_title']) : 'Perhatian:',
                'notice_text'        => $validated['notice_text'] ?? '',
                'admin_wa_number'    => $validated['admin_wa_number'] ?? '',
                'admin_wa_message'   => $validated['admin_wa_message'] ?? '',
                'submit_button_text' => trim($validated['submit_button_text']),
                'save_draft_enabled' => $request->boolean('save_draft_enabled'),
            ]
        );

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok'       => true,
                'message'  => 'Pengaturan Area Submit berhasil disimpan.',
                'settings' => $setting,
            ]);
        }

        return back()->with('success', 'Pengaturan Area Submit berhasil disimpan.');
    }
}
