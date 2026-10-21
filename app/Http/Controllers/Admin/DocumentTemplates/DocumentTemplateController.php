<?php

namespace App\Http\Controllers\Admin\DocumentTemplates;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Services\DocumentTemplates\AssetLibraryContract;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\DocumentTemplates\TemplateCatalogService;
use App\Services\DocumentTemplates\TemplateLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

class DocumentTemplateController extends Controller
{
    /**
     * Tampilkan katalog gabungan 6 jenis template dokumen dan sertifikat.
     */
    public function index(Request $request, TemplateCatalogService $catalogService): View
    {
        $page = (int) $request->input('page', 1);
        $catalog = $catalogService->getCatalog([
            'type'   => $request->type ?? 'all',
            'status' => $request->status ?? 'all',
            'search' => $request->search ?? '',
        ], perPage: 12, page: $page);

        return view('admin.document-templates.index', [
            'templates'          => $catalog['paginator'],
            'counts'             => $catalog['counts'],
            'documentTypes'      => $catalog['document_types'],
            'currentType'        => $catalog['current_type'],
            'currentStatus'      => $catalog['current_status'],
            'searchQuery'        => $catalog['search_query'],
            'activeCountsByType' => $catalog['active_counts_by_type'],
            'zeroActiveTypes'    => $catalog['zero_active_types'],
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

        $placeholders = DocumentTemplate::getDefaultPlaceholders($documentTemplate->type);
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
            'template_html'  => 'nullable|string',
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
        if (!is_array($pageConfig)) {
            $pageConfig = $documentTemplate->page_config ?? [];
        }

        // Merge any explicit top-level fields from the Word processor studio into $pageConfig
        $studioPageFields = [
            'header_html', 'footer_html', 'first_page_header_html', 'first_page_footer_html',
            'different_first_page', 'show_paraf_box', 'show_page_number',
            'paper_size', 'orientation',
            'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm',
            'header_distance_mm', 'footer_distance_mm',
            'font_family', 'font_size_pt', 'line_height'
        ];
        foreach ($studioPageFields as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                if (in_array($field, ['different_first_page', 'show_paraf_box', 'show_page_number'])) {
                    $pageConfig[$field] = $request->boolean($field);
                } elseif (in_array($field, ['margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'header_distance_mm', 'footer_distance_mm', 'line_height'])) {
                    $pageConfig[$field] = (float) $val;
                } elseif ($field === 'font_size_pt') {
                    $pageConfig[$field] = (int) $val;
                } else {
                    $pageConfig[$field] = $val;
                }
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

        // Content HTML dari field content_html atau template_html
        $contentHtml = $request->input('content_html') 
            ?? $request->input('template_html') 
            ?? $documentTemplate->content_html;

        // Tingkatkan nomor versi
        $nextVersion = $documentTemplate->version_number + 1;

        $documentTemplate->fill([
            'name'           => $validated['name'],
            'description'    => $validated['description'] ?? null,
            'content_html'   => $contentHtml,
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
    public function duplicate(DocumentTemplate $documentTemplate, TemplateLifecycleService $lifecycleService): RedirectResponse
    {
        $clone = $lifecycleService->duplicate($documentTemplate, null, auth()->id() ?? 1);

        return redirect()
            ->route('admin.document-templates.edit', $clone)
            ->with('success', "Template '{$clone->name}' berhasil dibuat dari salinan. Status: Draf.");
    }

    /**
     * Jadikan template sebagai default untuk tipe dokumen ini secara konsisten & atomik (INV-08).
     */
    public function setDefault(Request $request, DocumentTemplate $documentTemplate, TemplateLifecycleService $lifecycleService): JsonResponse|RedirectResponse
    {
        try {
            $lifecycleService->setDefault($documentTemplate, auth()->id() ?? 1);

            $msg = "Template '{$documentTemplate->name}' ditetapkan sebagai template default.";
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => $msg]);
            }

            return back()->with('success', "Template '{$documentTemplate->name}' sekarang menjadi template default untuk {$documentTemplate->getTypeLabel($documentTemplate->type)}.");
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Toggle status aktif / draf template dengan validasi lifecycle (INV-09).
     */
    public function toggleActive(Request $request, DocumentTemplate $documentTemplate, TemplateLifecycleService $lifecycleService): JsonResponse|RedirectResponse
    {
        try {
            if ($documentTemplate->is_active) {
                $lifecycleService->deactivate($documentTemplate, auth()->id() ?? 1);
                $newState = false;
                $statusLabel = 'dinonaktifkan (draf)';
            } else {
                $lifecycleService->activate($documentTemplate, auth()->id() ?? 1);
                $newState = true;
                $statusLabel = 'diaktifkan';
            }

            $msg = "Template '{$documentTemplate->name}' berhasil {$statusLabel}.";
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success'   => true,
                    'message'   => $msg,
                    'is_active' => $newState,
                ]);
            }

            return back()->with('success', $msg);
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Publikasikan draf menjadi snapshot versi kanonikal (Published Revision).
     */
    public function publish(Request $request, DocumentTemplate $documentTemplate, TemplateLifecycleService $lifecycleService): JsonResponse|RedirectResponse
    {
        try {
            $summary = $request->input('change_summary', 'Publikasi revisi resmi');
            $version = $lifecycleService->publishRevision($documentTemplate, $summary, auth()->id() ?? 1);

            $msg = "Revisi template '{$documentTemplate->name}' berhasil dipublikasikan (Versi {$version->version_number}).";
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'version' => $version->version_number,
                ]);
            }

            return back()->with('success', $msg);
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Arsipkan template (Archived).
     */
    public function archive(Request $request, DocumentTemplate $documentTemplate, TemplateLifecycleService $lifecycleService): JsonResponse|RedirectResponse
    {
        try {
            $lifecycleService->archive($documentTemplate, auth()->id() ?? 1);
            $msg = "Template '{$documentTemplate->name}' berhasil diarsipkan.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => $msg]);
            }

            return back()->with('success', $msg);
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }
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
