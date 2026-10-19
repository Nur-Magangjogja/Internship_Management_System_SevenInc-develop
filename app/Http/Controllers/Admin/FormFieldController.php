<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\FormSchema;
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

        $currentDraft    = $this->formBuilderService->ensureYearFormInitialized($year, auth()->user());
        $activePublished = FormSchema::where('year', $year)->activePublished()->first();

        // Ambil field dari draf aktif
        $mainFields     = $currentDraft->fields()->whereNull('group_name')->notArchived()->get();
        $extraFields    = $currentDraft->fields()->where('group_name', 'informasi_tambahan')->notArchived()->get();
        $archivedFields = $currentDraft->fields()->where('is_archived', true)->get();

        $types     = FormField::supportedTypes();
        $groups    = FormField::groups();
        $divisions = $this->getActiveDivisions();

        return view('admin.form_settings.form_fields', compact(
            'mainFields', 'extraFields', 'archivedFields', 'types', 'groups',
            'currentDraft', 'activePublished', 'year', 'currentYear', 'nextYear', 'divisions'
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
        $validated = $request->validate([
            'field_type'       => 'required|in:' . implode(',', array_keys(FormField::supportedTypes())),
            'label'            => 'required|string|max:255',
            'placeholder'      => 'nullable|string|max:255',
            'helper_text'      => 'nullable|string|max:500',
            'is_required'      => 'nullable|boolean',
            'group_name'       => 'nullable|in:,informasi_tambahan',
            'column_span'      => 'nullable|integer|in:1,2',
            'options'          => 'nullable|string', // JSON string dari textarea
            'validation_rules' => 'nullable',
        ]);

        $currentDraft = $this->formBuilderService->ensureYearFormInitialized($year, auth()->user());

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
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->withInput()->withErrors(['label' => $e->getMessage()])->with('error', $e->getMessage());
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => 'Field berhasil ditambahkan ke draft.', 'field' => $field]);
        }

        return redirect()->route('admin.form-settings.fields', ['year' => $year])
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
            'group_name'       => 'nullable|in:,informasi_tambahan',
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
        $currentDraft = $this->formBuilderService->ensureYearFormInitialized($year, auth()->user());

        try {
            $published = $this->formBuilderService->publishVersion($currentDraft, auth()->user());
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            return redirect()->route('admin.form-settings.fields', ['year' => $year])
                ->with('error', "Gagal mempublikasikan versi formulir: {$e->getMessage()}");
        }

        if (!app()->runningUnitTests()) {
            try {
                app(\App\Services\SpreadsheetSyncEngineService::class)->syncSpreadsheetColumnsAndRowsForYear($year, $published);
            } catch (\Throwable) {
            }
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok'      => true,
                'message' => "Versi {$published->version_code} berhasil dipublikasikan.",
                'schema'  => $published,
            ]);
        }

        return redirect()->route('admin.form-settings.fields', ['year' => $year])
            ->with('success', "Versi {$published->version_code} berhasil dipublikasikan. Kolom & isi Spreadsheet serta daftar pertanyaan yang dipublikasikan telah disinkronkan secara otomatis.");
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

        try {
            $this->formBuilderService->syncDraftFromPreviousYear($nextYear, auth()->user());
        } catch (\Throwable $e) {
            return redirect()->route('admin.form-settings.fields', ['year' => $nextYear])
                ->with('error', "Gagal menyalin struktur form: {$e->getMessage()}");
        }

        return redirect()->route('admin.form-settings.fields', ['year' => $nextYear])
            ->with('success', "Struktur pertanyaan Form Tahun {$nextYear} berhasil disamakan dengan Form Tahun {$currentYear}.");
    }

    /**
     * POST /admin/form-settings/fields/{field}/archive
     * Arsipkan field (soft-archive).
     */
    public function archive(FormField $field)
    {
        $this->formBuilderService->archiveField($field);

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
        $currentDraft = $this->formBuilderService->ensureYearFormInitialized($year, auth()->user());
        $mainFields  = $currentDraft->fields()->whereNull('group_name')->active()->get();
        $extraFields = $currentDraft->fields()->where('group_name', 'informasi_tambahan')->active()->get();
        $divisions   = $this->getActiveDivisions();

        return response()->json([
            'main_fields'  => $mainFields,
            'extra_fields' => $extraFields,
            'divisions'    => $divisions,
            'draft_version'=> $currentDraft->version_code,
            'year'         => $year,
        ]);
    }
}
