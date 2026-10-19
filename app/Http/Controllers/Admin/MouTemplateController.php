<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MouTemplate;
use App\Services\ActivityLogger;
use App\Services\MouDocumentRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MouTemplateController extends Controller
{
    /**
     * Daftar seluruh template MOU dan riwayat versinya.
     */
    public function index()
    {
        $templates = MouTemplate::with(['createdBy', 'updatedBy'])
            ->latest('id')
            ->paginate(15);

        $activeTemplate = MouTemplate::active()->latest('version_number')->first();

        $stats = [
            'total'          => MouTemplate::count(),
            'active_name'    => $activeTemplate ? $activeTemplate->name . ' (v' . $activeTemplate->version_number . ')' : 'Belum Ada',
            'latest_version' => MouTemplate::max('version_number') ?? 1,
        ];

        return view('admin.mou-templates.index', compact('templates', 'activeTemplate', 'stats'));
    }

    /**
     * Form pembuatan template MOU baru dengan editor visual berkonsep Word Office.
     */
    public function create()
    {
        $placeholders             = MouTemplate::getDefaultPlaceholders();
        $placeholderMeta          = MouTemplate::getPlaceholderMetadata();
        $defaultContent           = MouTemplate::getDefaultTemplateContent();
        $defaultCompanyValues     = MouTemplate::getDefaultCompanyValues();
        $defaultPageConfig        = MouTemplate::getDefaultPageConfig();
        $defaultMediaAssets       = MouTemplate::getDefaultMediaAssets();
        $nextVersion              = (MouTemplate::max('version_number') ?? 0) + 1;
        $existingTemplatesPayload = $this->buildExistingTemplatesPayload();

        return view('admin.mou-templates.create', compact(
            'placeholders',
            'placeholderMeta',
            'defaultContent',
            'defaultCompanyValues',
            'defaultPageConfig',
            'defaultMediaAssets',
            'nextVersion',
            'existingTemplatesPayload'
        ));
    }

    /**
     * Simpan template MOU baru beserta konfigurasi halaman A4, aset logo, dan default perusahaan.
     */
    public function store(Request $request)
    {
        $this->validateTemplateRequest($request);

        $defaultValues = $this->extractStructuredDefaultValues($request);
        $isActive = $request->boolean('is_active', true);

        // Jika template baru dijadikan aktif, nonaktifkan template lainnya
        if ($isActive) {
            MouTemplate::where('is_active', true)->update(['is_active' => false]);
        }

        $template = MouTemplate::create([
            'name'                 => $request->name,
            'version_number'       => (int) $request->version_number,
            'template_html'        => $request->template_html,
            'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
            'default_values'       => $defaultValues,
            'is_active'            => $isActive,
            'created_by'           => auth()->id(),
        ]);

        ActivityLogger::log(
            action: 'mou_template_created',
            description: "Membuat template MOU baru: {$template->name} (v{$template->version_number})",
            subject: $template,
            after: [
                'name'           => $template->name,
                'version_number' => $template->version_number,
                'is_active'      => $template->is_active,
            ],
            actor: auth()->user()
        );

        return redirect()->route('admin.mou-templates.index')
            ->with('success', 'Template MOU berhasil dibuat dan disimpan.');
    }

    /**
     * Form ubah template MOU dengan editor visual berkonsep Word Office.
     */
    public function edit(MouTemplate $mouTemplate)
    {
        $placeholders             = MouTemplate::getDefaultPlaceholders();
        $placeholderMeta          = MouTemplate::getPlaceholderMetadata();
        $companyDefaults          = $mouTemplate->getCompanyDefaultValues();
        $pageConfig               = $mouTemplate->getPageConfig();
        $mediaAssets              = $mouTemplate->getMediaAssets();
        $editorJson               = $mouTemplate->getEditorJson();
        $existingTemplatesPayload = $this->buildExistingTemplatesPayload();

        return view('admin.mou-templates.edit', compact(
            'mouTemplate',
            'placeholders',
            'placeholderMeta',
            'companyDefaults',
            'pageConfig',
            'mediaAssets',
            'editorJson',
            'existingTemplatesPayload'
        ));
    }

    /**
     * Update template MOU.
     */
    public function update(Request $request, MouTemplate $mouTemplate)
    {
        $this->validateTemplateRequest($request);

        $incomingHtml = (string) $request->input('template_html', '');
        $strippedIncoming = trim(strip_tags($incomingHtml, '<img><table>'));
        $existingStripped = trim(strip_tags((string) $mouTemplate->template_html, '<img><table>'));

        // Proteksi integritas data: jangan menimpa template lama yang berisi naskah dengan dokumen kosong
        // apabila terjadi kegagalan muat, kecuali pengguna secara eksplisit memilih mode dokumen kosong.
        if ($strippedIncoming === '' && $existingStripped !== '' && !$request->boolean('allow_blank_template')) {
            return back()->withInput()->withErrors([
                'template_html' => 'Dokumen tidak boleh disimpan dalam keadaan kosong kecuali opsi konfirmasi dokumen kosong dicentang.',
            ]);
        }

        $defaultValues = $this->extractStructuredDefaultValues($request, $mouTemplate);

        $beforeData = [
            'name'           => $mouTemplate->name,
            'version_number' => $mouTemplate->version_number,
            'is_active'      => $mouTemplate->is_active,
        ];

        $isActive = $request->boolean('is_active', $mouTemplate->is_active);

        if ($isActive && !$mouTemplate->is_active) {
            MouTemplate::where('id', '!=', $mouTemplate->id)->where('is_active', true)->update(['is_active' => false]);
        }

        $mouTemplate->update([
            'name'                 => $request->name,
            'version_number'       => (int) $request->version_number,
            'template_html'        => $incomingHtml,
            'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
            'default_values'       => $defaultValues,
            'is_active'            => $isActive,
            'updated_by'           => auth()->id(),
        ]);

        ActivityLogger::log(
            action: 'mou_template_updated',
            description: "Memperbarui template MOU: {$mouTemplate->name} (v{$mouTemplate->version_number})",
            subject: $mouTemplate,
            before: $beforeData,
            after: [
                'name'           => $mouTemplate->name,
                'version_number' => $mouTemplate->version_number,
                'is_active'      => $mouTemplate->is_active,
            ],
            actor: auth()->user()
        );

        return redirect()->route('admin.mou-templates.index')
            ->with('success', 'Template MOU berhasil diperbarui.');
    }

    /**
     * Pratinjau render dokumen template MOU dengan data dummy atau institusi nyata.
     */
    public function preview(Request $request, MouTemplate $mouTemplate)
    {
        $institutions = Institution::active()->orderBy('name')->get();
        $selectedInstitutionId = $request->input('institution_id');

        $selectedInstitution = $selectedInstitutionId
            ? Institution::with('contacts')->find($selectedInstitutionId)
            : null;

        $mockData = $this->buildPreviewMockData($mouTemplate, $selectedInstitution);
        $renderedHtml = $mouTemplate->renderContent($mockData, false);
        $pageConfig = MouDocumentRenderer::renderPageConfig($mouTemplate, $mockData, false);
        $canonicalCss = MouDocumentRenderer::getCanonicalDocumentCss($pageConfig);

        return view('admin.mou-templates.preview', compact(
            'mouTemplate',
            'institutions',
            'selectedInstitution',
            'renderedHtml',
            'pageConfig',
            'canonicalCss'
        ));
    }

    /**
     * Ekspor / Pratinjau PDF A4 langsung dari Template MOU.
     */
    public function previewPdf(Request $request, MouTemplate $mouTemplate)
    {
        $selectedInstitutionId = $request->input('institution_id');
        $selectedInstitution = $selectedInstitutionId
            ? Institution::with('contacts')->find($selectedInstitutionId)
            : null;

        $mockData = $this->buildPreviewMockData($mouTemplate, $selectedInstitution);
        $renderedHtml = $mouTemplate->renderContent($mockData, false);
        $pageConfig = MouDocumentRenderer::renderPageConfig($mouTemplate, $mockData, false);

        $pdfBinary = MouDocumentRenderer::renderPdfBinary(
            $renderedHtml,
            $pageConfig,
            $mouTemplate->name . ' (v' . $mouTemplate->version_number . ')'
        );

        $safeFileName = Str::slug($mouTemplate->name, '-') . '-v' . $mouTemplate->version_number . '.pdf';

        return response($pdfBinary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $safeFileName . '"',
        ]);
    }

    /**
     * Unggah aset logo/gambar untuk template MOU dengan validasi MIME dan dimensi ketat.
     */
    public function uploadMedia(Request $request)
    {
        $inputKey = $request->hasFile('media_file') ? 'media_file' : 'image';

        $request->validate([
            $inputKey => 'required|file|mimes:png,jpg,jpeg,webp|mimetypes:image/png,image/jpeg,image/webp|max:2048',
            'slot'    => 'nullable|string|max:64',
        ]);

        $file = $request->file($inputKey);
        $realPath = $file->getRealPath();

        $imgInfo = $realPath ? @getimagesize($realPath) : false;
        if ($imgInfo === false || !in_array($imgInfo['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return response()->json([
                'message' => 'Berkas yang diunggah bukan gambar valid (hanya PNG, JPG, atau WEBP yang diizinkan).',
            ], 422);
        }

        if (($imgInfo[0] ?? 0) > 4000 || ($imgInfo[1] ?? 0) > 4000) {
            return response()->json([
                'message' => 'Dimensi gambar terlalu besar (maksimal 4000x4000 piksel).',
            ], 422);
        }

        $extMap = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        $ext = $extMap[$imgInfo['mime']] ?? 'png';
        $fileName = 'mou-logo-' . Str::uuid() . '.' . $ext;
        $storedPath = $file->storeAs('mou-templates/media', $fileName, 'public');

        if (!$storedPath || !Storage::disk('public')->exists($storedPath)) {
            return response()->json([
                'message' => 'Gagal menyimpan berkas gambar ke penyimpanan.',
            ], 500);
        }

        $publicUrl = '/storage/' . ltrim($storedPath, '/');

        ActivityLogger::log(
            action: 'mou_template_media_uploaded',
            description: "Mengunggah aset gambar template MOU: {$fileName}",
            actor: auth()->user(),
            metadata: ['url' => $publicUrl, 'mime' => $imgInfo['mime']]
        );

        return response()->json([
            'url'      => $publicUrl,
            'filename' => $fileName,
            'slot'     => $request->input('slot', 'custom'),
            'width'    => (int) ($imgInfo[0] ?? 0),
            'height'   => (int) ($imgInfo[1] ?? 0),
        ]);
    }

    /**
     * Jadikan template ini sebagai template aktif utama.
     */
    public function toggleActive(MouTemplate $mouTemplate)
    {
        MouTemplate::where('id', '!=', $mouTemplate->id)->update(['is_active' => false]);
        $mouTemplate->update(['is_active' => true, 'updated_by' => auth()->id()]);

        ActivityLogger::log(
            action: 'mou_template_activated',
            description: "Mengaktifkan template MOU: {$mouTemplate->name} (v{$mouTemplate->version_number}) sebagai template aktif utama",
            subject: $mouTemplate,
            actor: auth()->user()
        );

        return back()->with('success', "Template {$mouTemplate->name} (v{$mouTemplate->version_number}) telah ditetapkan sebagai template aktif.");
    }

    /**
     * Soft delete template MOU.
     */
    public function destroy(MouTemplate $mouTemplate)
    {
        $templateName = $mouTemplate->name;
        $mouTemplate->delete();

        ActivityLogger::log(
            action: 'mou_template_deleted',
            description: "Menghapus template MOU: {$templateName}",
            subject: $mouTemplate,
            actor: auth()->user()
        );

        return back()->with('success', "Template {$templateName} berhasil dihapus.");
    }

    /**
     * Validasi request store & update template MOU.
     */
    protected function validateTemplateRequest(Request $request): void
    {
        $request->validate([
            'name'                        => 'required|string|max:255',
            'version_number'              => 'required|integer|min:1|max:999',
            'template_html'               => 'required|string',
            'company_name'                => 'nullable|string|max:255',
            'company_address'             => 'nullable|string|max:500',
            'company_phone'               => 'nullable|string|max:100',
            'company_fax'                 => 'nullable|string|max:100',
            'company_email'               => 'nullable|email|max:100',
            'company_correspondence_unit' => 'nullable|string|max:255',
            'company_signatory_name'      => 'nullable|string|max:255',
            'company_signatory_title'     => 'nullable|string|max:255',
            'agreement_place'             => 'nullable|string|max:100',
            'agreement_duration'          => 'nullable|string|max:100',
            'paper_size'                  => 'nullable|in:A4,LETTER,LEGAL',
            'orientation'                 => 'nullable|in:portrait,landscape',
            'margin_top_mm'               => 'nullable|numeric|min:5|max:60',
            'margin_bottom_mm'            => 'nullable|numeric|min:5|max:60',
            'margin_left_mm'              => 'nullable|numeric|min:5|max:60',
            'margin_right_mm'             => 'nullable|numeric|min:5|max:60',
            'header_distance_mm'          => 'nullable|numeric|min:2|max:50',
            'footer_distance_mm'          => 'nullable|numeric|min:2|max:50',
            'different_first_page'        => 'nullable|boolean',
            'header_html'                 => 'nullable|string|max:65000',
            'footer_html'                 => 'nullable|string|max:65000',
            'first_page_header_html'      => 'nullable|string|max:65000',
            'first_page_footer_html'      => 'nullable|string|max:65000',
            'editor_json'                 => 'nullable|string|max:500000',
            'font_family'                 => 'nullable|string|max:100',
            'font_size_pt'                => 'nullable|integer|min:8|max:24',
            'line_height'                 => 'nullable|numeric|min:1|max:2.5',
            'company_logo'                => 'nullable|string|max:500',
            'institution_logo'            => 'nullable|string|max:500',
        ]);
    }

    /**
     * Susun array default_values yang memisahkan data perusahaan, _page_config, _media_assets, dan _editor_json secara bersih.
     */
    protected function extractStructuredDefaultValues(Request $request, ?MouTemplate $existing = null): array
    {
        $baseDefaults = $existing ? $existing->getCompanyDefaultValues() : MouTemplate::getDefaultCompanyValues();
        $basePage     = $existing ? $existing->getPageConfig() : MouTemplate::getDefaultPageConfig();
        $baseMedia    = $existing ? $existing->getMediaAssets() : MouTemplate::getDefaultMediaAssets();

        $rawCompanyLogo = $request->input('company_logo') ?: $request->input('company_logo_url') ?: $baseMedia['company_logo'];
        $companyLogo = MouDocumentRenderer::isValidImageSource((string) $rawCompanyLogo)
            ? $rawCompanyLogo
            : $baseMedia['company_logo'];

        $rawInstitutionLogo = $request->input('institution_logo') ?: $request->input('institution_logo_url') ?: $baseMedia['institution_logo'];
        $institutionLogo = ($rawInstitutionLogo === 'none' || MouDocumentRenderer::isValidImageSource((string) $rawInstitutionLogo))
            ? $rawInstitutionLogo
            : $baseMedia['institution_logo'];

        $showParaf = $request->has('show_paraf_footer')
            ? $request->boolean('show_paraf_footer')
            : ($request->has('show_paraf_box') ? $request->boolean('show_paraf_box') : (bool) ($basePage['show_paraf_box'] ?? true));

        $showPageNum = $request->has('show_page_numbers')
            ? $request->boolean('show_page_numbers')
            : ($request->has('show_page_number') ? $request->boolean('show_page_number') : (bool) ($basePage['show_page_number'] ?? true));

        $differentFirstPage = $request->has('different_first_page')
            ? $request->boolean('different_first_page')
            : (bool) ($basePage['different_first_page'] ?? false);

        $parafFirstLabel  = (string) $request->input('paraf_first_party_label', $basePage['paraf_first_party_label'] ?? 'Paraf PIHAK KESATU');
        $parafSecondLabel = (string) $request->input('paraf_second_party_label', $basePage['paraf_second_party_label'] ?? 'Paraf PIHAK KEDUA');

        $headerHtml = $request->has('header_html')
            ? MouDocumentRenderer::sanitizeHeaderFooterHtml((string) $request->input('header_html', ''))
            : (string) ($basePage['header_html'] ?? '');

        if ($request->has('footer_html')) {
            $footerHtml = MouDocumentRenderer::sanitizeHeaderFooterHtml((string) $request->input('footer_html', ''));
        } else {
            $footerHtml = $showParaf
                ? ((string) ($basePage['footer_html'] ?: MouTemplate::getDefaultFooterParafHtml($parafFirstLabel, $parafSecondLabel)))
                : '';
        }

        $firstPageHeaderHtml = $request->has('first_page_header_html')
            ? MouDocumentRenderer::sanitizeHeaderFooterHtml((string) $request->input('first_page_header_html', ''))
            : (string) ($basePage['first_page_header_html'] ?? '');

        $firstPageFooterHtml = $request->has('first_page_footer_html')
            ? MouDocumentRenderer::sanitizeHeaderFooterHtml((string) $request->input('first_page_footer_html', ''))
            : (string) ($basePage['first_page_footer_html'] ?? '');

        $pageConfig = [
            '_schema_version'          => 3,
            'paper_size'               => $request->input('paper_size', $basePage['paper_size']),
            'orientation'              => $request->input('orientation', $basePage['orientation']),
            'margin_top_mm'            => (float) $request->input('margin_top_mm', $basePage['margin_top_mm']),
            'margin_bottom_mm'         => (float) $request->input('margin_bottom_mm', $basePage['margin_bottom_mm']),
            'margin_left_mm'           => (float) $request->input('margin_left_mm', $basePage['margin_left_mm']),
            'margin_right_mm'          => (float) $request->input('margin_right_mm', $basePage['margin_right_mm']),
            'header_distance_mm'       => (float) $request->input('header_distance_mm', $basePage['header_distance_mm'] ?? 12.0),
            'footer_distance_mm'       => (float) $request->input('footer_distance_mm', $basePage['footer_distance_mm'] ?? 10.0),
            'different_first_page'     => $differentFirstPage,
            'header_html'              => $headerHtml,
            'footer_html'              => $footerHtml,
            'first_page_header_html'   => $firstPageHeaderHtml,
            'first_page_footer_html'   => $firstPageFooterHtml,
            'font_family'              => $request->input('font_family', $basePage['font_family']),
            'font_size_pt'             => (float) $request->input('font_size_pt', $basePage['font_size_pt']),
            'line_height'              => (float) $request->input('line_height', $basePage['line_height']),
            'show_paraf_box'           => $showParaf,
            'show_paraf_footer'        => $showParaf,
            'paraf_first_party_label'  => $parafFirstLabel,
            'paraf_second_party_label' => $parafSecondLabel,
            'show_page_number'         => $showPageNum,
            'show_page_numbers'        => $showPageNum,
        ];

        $mediaAssets = [
            'company_logo'     => $companyLogo,
            'institution_logo' => $institutionLogo,
        ];

        $editorJson = null;
        if ($request->filled('editor_json')) {
            $decoded = json_decode((string) $request->input('editor_json'), true);
            if (is_array($decoded) && ($decoded['type'] ?? '') === 'doc' && !empty($decoded['content']) && is_array($decoded['content'])) {
                $editorJson = $decoded;
            }
        } elseif (!$request->has('editor_json') && $existing) {
            $editorJson = $existing->getEditorJson();
        }

        $result = [
            'company_name'                => $request->input('company_name') ?: ($baseDefaults['company_name'] ?? 'SEVEN INC'),
            'company_address'             => $request->input('company_address') ?: ($baseDefaults['company_address'] ?? 'Yogyakarta'),
            'company_phone'               => $request->input('company_phone', $baseDefaults['company_phone'] ?? '089529002944'),
            'company_fax'                 => $request->input('company_fax', $baseDefaults['company_fax'] ?? '-'),
            'company_email'               => $request->input('company_email', $baseDefaults['company_email'] ?? 'magangjogjadotcom@gmail.com'),
            'company_correspondence_unit' => $request->input('company_correspondence_unit') ?: ($baseDefaults['company_correspondence_unit'] ?? 'CEO'),
            'company_signatory_name'      => $request->input('company_signatory_name', $baseDefaults['company_signatory_name'] ?? 'Rekario Danny Sanjaya, S.Kom.'),
            'company_signatory_title'     => $request->input('company_signatory_title') ?: ($baseDefaults['company_signatory_title'] ?? 'Pimpinan'),
            'agreement_place'             => $request->input('agreement_place') ?: ($baseDefaults['agreement_place'] ?? 'Yogyakarta'),
            'agreement_duration'          => $request->input('agreement_duration') ?: ($baseDefaults['agreement_duration'] ?? '5 (lima) tahun'),
            '_page_config'                => $pageConfig,
            '_media_assets'               => $mediaAssets,
            '_schema_version'             => 3,
        ];

        if ($editorJson !== null) {
            $sanitizedHtmlForHash = MouTemplate::sanitizeTemplateHtml((string) $request->input('template_html', ''));
            $result['_editor_json'] = $editorJson;
            $result['_editor_html_sha256'] = hash('sha256', trim($sanitizedHtmlForHash));
        }

        return $result;
    }

    /**
     * Susun daftar template yang telah tersimpan untuk pemilih "Muat dari Template Sebelumnya" di dalam editor.
     */
    protected function buildExistingTemplatesPayload(): array
    {
        return MouTemplate::orderByDesc('is_active')
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->get()
            ->map(function (MouTemplate $tpl) {
                return [
                    'id'               => $tpl->id,
                    'name'             => $tpl->name,
                    'version_number'   => $tpl->version_number,
                    'is_active'        => (bool) $tpl->is_active,
                    'template_html'    => $tpl->template_html,
                    'editor_json'      => $tpl->getEditorJson(),
                    'page_config'      => $tpl->getPageConfig(),
                    'media_assets'     => $tpl->getMediaAssets(),
                    'company_defaults' => $tpl->getCompanyDefaultValues(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Bangun data simulasi pratinjau (mendukung baik institusi contoh maupun institusi terpilih).
     */
    protected function buildPreviewMockData(MouTemplate $mouTemplate, ?Institution $selectedInstitution = null): array
    {
        $companyDefaults = $mouTemplate->getCompanyDefaultValues();
        $sampleDate      = now()->toDateString();
        $sampleStart     = now()->toDateString();
        $sampleEnd       = now()->addYears(5)->toDateString();

        $dateTokens = MouDocumentRenderer::computeDateAndDurationTokens(
            $sampleDate,
            $sampleStart,
            $sampleEnd,
            $companyDefaults['agreement_duration'] ?? '5 (lima) tahun'
        );

        return array_merge($companyDefaults, $dateTokens, [
            'institution_name'                => $selectedInstitution?->name ?? 'UNIVERSITAS CONTOH INDONESIA',
            'institution_mou_number'          => '045/UNIV-CI/MOU/XI/' . date('Y'),
            'institution_address'             => $selectedInstitution?->address ?? 'Jl. Budi Utomo No.10, Ronowijayan, Kec. Ponorogo, Kabupaten Ponorogo, Jawa Timur 63471',
            'institution_correspondence_unit' => $selectedInstitution?->correspondence_unit ?? 'Kepala Bidang Kerjasama Dalam Negeri',
            'institution_phone'               => $selectedInstitution?->phone ?? '0352-481124',
            'institution_fax'                 => '461796',
            'institution_email'               => $selectedInstitution?->email ?? 'kerjasama@univ-contoh.ac.id',
            'institution_signatory_name'      => $selectedInstitution?->contacts->first()?->name ?? 'Dr. Rido Kurnianto, M.Ag.',
            'institution_signatory_title'     => $selectedInstitution?->contacts->first()?->position_title ?? 'Rektor',
            'company_mou_number'              => '004/SPK.MAGANG/CEO/SEVEN/V/' . date('Y'),
        ]);
    }
}
