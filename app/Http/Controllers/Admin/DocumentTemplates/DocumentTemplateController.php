<?php

namespace App\Http\Controllers\Admin\DocumentTemplates;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Services\DocumentTemplates\AssetLibraryContract;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DocumentTemplateController extends Controller
{
    /**
     * Tampilkan daftar template dokumen dan sertifikat.
     */
    public function index(Request $request): View
    {
        $query = DocumentTemplate::query()->with(['creator', 'updater']);

        // Filter Tipe Dokumen
        if ($request->filled('type') && in_array($request->type, array_keys(DocumentTemplate::getDocumentTypes()), true)) {
            $query->where('type', $request->type);
        }

        // Filter Status Aktif
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'draft') {
                $query->where('is_active', false);
            } elseif ($request->status === 'default') {
                $query->where('is_default', true);
            }
        }

        // Pencarian Nama / Deskripsi
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $templates = $query->orderBy('is_default', 'desc')
                           ->orderBy('updated_at', 'desc')
                           ->paginate(12)
                           ->withQueryString();

        // Ringkasan Jumlah untuk Filter Tab
        $counts = [
            'total'          => DocumentTemplate::count(),
            'active'         => DocumentTemplate::where('is_active', true)->count(),
            'loa'            => DocumentTemplate::where('type', 'loa')->count(),
            'skl'            => DocumentTemplate::where('type', 'skl')->count(),
            'certificate'    => DocumentTemplate::where('type', 'certificate')->count(),
            'assessment'     => DocumentTemplate::where('type', 'assessment')->count(),
            'recommendation' => DocumentTemplate::where('type', 'recommendation')->count(),
        ];

        return view('admin.document-templates.index', [
            'templates'     => $templates,
            'counts'        => $counts,
            'documentTypes' => DocumentTemplate::getDocumentTypes(),
            'currentType'   => $request->type ?? 'all',
            'currentStatus' => $request->status ?? 'all',
            'searchQuery'   => $request->search ?? '',
        ]);
    }

    /**
     * Tampilkan form pembuatan template baru.
     */
    public function create(Request $request): View
    {
        $selectedType = $request->query('type', 'loa');
        if (!array_key_exists($selectedType, DocumentTemplate::getDocumentTypes())) {
            $selectedType = 'loa';
        }

        return view('admin.document-templates.create', [
            'documentTypes' => DocumentTemplate::getDocumentTypes(),
            'selectedType'  => $selectedType,
        ]);
    }

    /**
     * Simpan template baru ke database.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'type'           => 'required|string|in:' . implode(',', array_keys(DocumentTemplate::getDocumentTypes())),
            'description'    => 'nullable|string|max:1000',
            'preset_type'    => 'nullable|string|in:standard,blank',
        ]);

        $type = $validated['type'];
        $isCanvas = ($type === 'certificate');
        $editorType = $isCanvas ? 'visual_canvas' : 'visual_doc';
        $useStandardPreset = ($request->input('preset_type', 'standard') === 'standard');

        $contentHtml = '';
        $canvasLayout = null;

        if ($useStandardPreset) {
            if ($isCanvas) {
                $canvasLayout = DocumentTemplate::getDefaultCertificateCanvasLayout();
            } else {
                $contentHtml = DocumentTemplate::getDefaultStandardContent($type);
            }
        }

        $pageConfig = DocumentTemplate::getDefaultPageConfig($type);

        $template = DocumentTemplate::create([
            'type'           => $type,
            'name'           => $validated['name'],
            'description'    => $validated['description'] ?? null,
            'editor_type'    => $editorType,
            'content_html'   => $contentHtml,
            'editor_json'    => null,
            'canvas_layout'  => $canvasLayout,
            'page_config'    => $pageConfig,
            'is_active'      => false, // Draf baru selalu non-aktif
            'is_default'     => false,
            'version_number' => 1,
            'created_by'     => auth()->id(),
            'updated_by'     => auth()->id(),
        ]);

        // Simpan snapshot versi 1
        $template->createVersionSnapshot('Pembuatan template awal (Versi 1)', auth()->id());

        return redirect()
            ->route('admin.document-templates.edit', $template)
            ->with('success', "Template '{$template->name}' berhasil dibuat. Silakan atur isi dan tata letak dokumen.");
    }

    /**
     * Tampilkan editor visual dokumen atau sertifikat.
     */
    public function edit(DocumentTemplate $documentTemplate): View
    {
        $documentTemplate->loadCount('versions');

        $placeholders = DocumentTemplate::getDefaultPlaceholders();
        $mockData = DocumentTemplate::getMockPlaceholderData();
        $assetCatalog = AssetLibraryContract::getAssetCatalog();

        if ($documentTemplate->editor_type === 'visual_canvas' || $documentTemplate->type === 'certificate') {
            return view('admin.document-templates.edit-certificate', [
                'template'     => $documentTemplate,
                'placeholders' => $placeholders,
                'mockData'     => $mockData,
                'assetCatalog' => $assetCatalog,
            ]);
        }

        return view('admin.document-templates.edit-doc', [
            'template'     => $documentTemplate,
            'placeholders' => $placeholders,
            'mockData'     => $mockData,
            'assetCatalog' => $assetCatalog,
        ]);
    }

    /**
     * Perbarui konten, tata letak, dan konfigurasi template (Auto-versioning).
     */
    public function update(Request $request, DocumentTemplate $documentTemplate): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string|max:1000',
            'content_html'   => 'nullable|string',
            'editor_json'    => 'nullable',
            'canvas_layout'  => 'nullable',
            'page_config'    => 'nullable',
            'change_summary' => 'nullable|string|max:255',
            'is_active'      => 'nullable|boolean',
            'is_default'     => 'nullable|boolean',
        ]);

        // Parsing JSON jika payload dikirim sebagai serialized string
        $canvasLayout = $request->input('canvas_layout');
        if (is_string($canvasLayout)) {
            $decoded = json_decode($canvasLayout, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $canvasLayout = $decoded;
            }
        }

        $pageConfig = $request->input('page_config');
        if (is_string($pageConfig)) {
            $decoded = json_decode($pageConfig, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $pageConfig = $decoded;
            }
        }

        $editorJson = $request->input('editor_json');
        if (is_string($editorJson)) {
            $decoded = json_decode($editorJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $editorJson = $decoded;
            }
        }

        // Kelola status default jika dicentang
        $isDefault = $request->boolean('is_default', $documentTemplate->is_default);
        if ($isDefault) {
            DocumentTemplate::where('type', $documentTemplate->type)
                ->where('id', '!=', $documentTemplate->id)
                ->update(['is_default' => false]);
            // Default template harus aktif
            $isActive = true;
        } else {
            $isActive = $request->boolean('is_active', $documentTemplate->is_active);
        }

        // Tingkatkan nomor versi
        $nextVersion = $documentTemplate->version_number + 1;

        $documentTemplate->fill([
            'name'           => $validated['name'],
            'description'    => $validated['description'] ?? null,
            'content_html'   => $request->has('content_html') ? $request->input('content_html') : $documentTemplate->content_html,
            'editor_json'    => $editorJson ?? $documentTemplate->editor_json,
            'canvas_layout'  => $canvasLayout ?? $documentTemplate->canvas_layout,
            'page_config'    => $pageConfig ?? $documentTemplate->page_config,
            'is_active'      => $isActive,
            'is_default'     => $isDefault,
            'version_number' => $nextVersion,
            'updated_by'     => auth()->id(),
        ]);

        $documentTemplate->save();

        // Buat snapshot revisi baru
        $summary = $request->input('change_summary') ?: "Pembaruan versi {$nextVersion}";
        $documentTemplate->createVersionSnapshot($summary, auth()->id());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Template berhasil disimpan (Versi {$nextVersion}).",
                'version' => $nextVersion,
                'uuid'    => $documentTemplate->uuid,
            ]);
        }

        return redirect()
            ->route('admin.document-templates.edit', $documentTemplate)
            ->with('success', "Template '{$documentTemplate->name}' berhasil diperbarui ke Versi {$nextVersion}.");
    }

    /**
     * Duplikasi template menjadi draf salinan baru.
     */
    public function duplicate(DocumentTemplate $documentTemplate): RedirectResponse
    {
        $clone = $documentTemplate->duplicate(null, auth()->id());

        return redirect()
            ->route('admin.document-templates.edit', $clone)
            ->with('success', "Template '{$clone->name}' berhasil dibuat dari salinan. Status: Draf.");
    }

    /**
     * Jadikan template sebagai default untuk tipe dokumen ini.
     */
    public function setDefault(Request $request, DocumentTemplate $documentTemplate): JsonResponse|RedirectResponse
    {
        DocumentTemplate::where('type', $documentTemplate->type)
            ->update(['is_default' => false]);

        $documentTemplate->update([
            'is_default' => true,
            'is_active'  => true,
            'updated_by' => auth()->id(),
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Template '{$documentTemplate->name}' ditetapkan sebagai template default.",
            ]);
        }

        return back()->with('success', "Template '{$documentTemplate->name}' sekarang menjadi template default untuk {$documentTemplate->getTypeLabel($documentTemplate->type)}.");
    }

    /**
     * Toggle status aktif / draf template.
     */
    public function toggleActive(Request $request, DocumentTemplate $documentTemplate): JsonResponse|RedirectResponse
    {
        if ($documentTemplate->is_default && $documentTemplate->is_active) {
            $msg = 'Template default tidak dapat dinonaktifkan secara langsung. Tetapkan template lain sebagai default terlebih dahulu.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        $newState = !$documentTemplate->is_active;
        $documentTemplate->update([
            'is_active'  => $newState,
            'updated_by' => auth()->id(),
        ]);

        $statusLabel = $newState ? 'diaktifkan' : 'dinonaktifkan (draf)';
        $msg = "Template '{$documentTemplate->name}' berhasil {$statusLabel}.";

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success'   => true,
                'message'   => $msg,
                'is_active' => $newState,
            ]);
        }

        return back()->with('success', $msg);
    }

    /**
     * Hapus template (Soft Delete).
     */
    public function destroy(DocumentTemplate $documentTemplate): RedirectResponse
    {
        if ($documentTemplate->is_default) {
            return back()->with('error', 'Template default tidak dapat dihapus. Tetapkan template lain sebagai default terlebih dahulu.');
        }

        $name = $documentTemplate->name;
        $documentTemplate->delete();

        return redirect()
            ->route('admin.document-templates.index')
            ->with('success', "Template '{$name}' berhasil dipindahkan ke tempat sampah.");
    }

    /**
     * Preview interaktif dalam browser dengan data contoh.
     */
    public function preview(Request $request, DocumentTemplate $documentTemplate): View|Response|JsonResponse
    {
        $mockData = DocumentTemplate::getMockPlaceholderData();

        if ($documentTemplate->editor_type === 'visual_canvas' || $documentTemplate->type === 'certificate') {
            $renderedHtml = DocumentTemplateRenderer::renderCertificateHtml($documentTemplate, $mockData);
        } else {
            $renderedHtml = DocumentTemplateRenderer::renderLetterHtml($documentTemplate, $mockData);
        }

        if ($request->query('raw') === '1' || $request->ajax()) {
            return response($renderedHtml, 200, ['Content-Type' => 'text/html']);
        }

        return view('admin.document-templates.preview', [
            'template'     => $documentTemplate,
            'renderedHtml' => $renderedHtml,
            'mockData'     => $mockData,
        ]);
    }

    /**
     * Preview PDF nyata menggunakan DomPDF.
     */
    public function previewPdf(DocumentTemplate $documentTemplate): Response
    {
        $mockData = DocumentTemplate::getMockPlaceholderData();
        $binaryPdf = DocumentTemplateRenderer::renderPdfBinary($documentTemplate, $mockData);

        $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $documentTemplate->name);
        $filename = "Preview-{$safeName}-v{$documentTemplate->version_number}.pdf";

        return response($binaryPdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Histori versi template dan audit trail.
     */
    public function versions(DocumentTemplate $documentTemplate): View
    {
        $versions = $documentTemplate->versions()
            ->with('creator')
            ->orderBy('version_number', 'desc')
            ->get();

        return view('admin.document-templates.versions', [
            'template' => $documentTemplate,
            'versions' => $versions,
        ]);
    }

    /**
     * Rollback/Pulihkan template ke versi sebelumnya.
     */
    public function restoreVersion(Request $request, DocumentTemplate $documentTemplate, DocumentTemplateVersion $version): RedirectResponse
    {
        if ($version->document_template_id !== $documentTemplate->id) {
            abort(404, 'Versi dokumen tidak sesuai dengan template ini.');
        }

        $nextVersion = $documentTemplate->version_number + 1;

        $documentTemplate->fill([
            'name'           => $version->name ?? $documentTemplate->name,
            'content_html'   => $version->content_html,
            'editor_json'    => $version->editor_json,
            'canvas_layout'  => $version->canvas_layout,
            'page_config'    => $version->page_config,
            'version_number' => $nextVersion,
            'updated_by'     => auth()->id(),
        ]);

        $documentTemplate->save();

        $documentTemplate->createVersionSnapshot("Pemulihan (rollback) dari revisi Versi {$version->version_number}", auth()->id());

        return redirect()
            ->route('admin.document-templates.edit', $documentTemplate)
            ->with('success', "Template '{$documentTemplate->name}' berhasil dipulihkan dari Versi {$version->version_number}. Nomor versi kini: {$nextVersion}.");
    }

    /**
     * Endpoint API JSON untuk katalog aset sistem (logo, stempel, tanda tangan, background).
     */
    public function apiAssets(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => AssetLibraryContract::getAssetCatalog(),
        ]);
    }
}
