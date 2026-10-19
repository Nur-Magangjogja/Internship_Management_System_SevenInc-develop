@php
    $cfg = array_merge(\App\Models\MouTemplate::getDefaultPageConfig(), $pageConfig ?? []);
    $assets = array_merge(\App\Models\MouTemplate::getDefaultMediaAssets(), $mediaAssets ?? []);
    $placeholders = \App\Models\MouTemplate::getDefaultPlaceholders();
    $metaMap = $placeholderMeta ?? \App\Models\MouTemplate::getPlaceholderMetadata();
    $placeholderGroups = [];
    foreach ($metaMap as $phKey => $phItem) {
        $grp = $phItem['group'] ?? 'Lainnya';
        $placeholderGroups[$grp][] = $phKey;
    }
    $savedTemplates = $existingTemplatesPayload ?? [];
    $sampleWordHtml = \App\Models\MouTemplate::getWordStandardTemplateContent();
    $defaultParafHtml = \App\Models\MouTemplate::getDefaultFooterParafHtml();

    $initialBodyContent = old('template_html', $initialHtml ?? $sampleWordHtml);
    $initialHeaderContent = old('header_html', $cfg['header_html'] ?? '');
    $initialFooterContent = old('footer_html', array_key_exists('footer_html', $cfg) && $cfg['footer_html'] !== null ? $cfg['footer_html'] : $defaultParafHtml);
    $initialFirstHeaderContent = old('first_page_header_html', $cfg['first_page_header_html'] ?? '');
    $initialFirstFooterContent = old('first_page_footer_html', array_key_exists('first_page_footer_html', $cfg) && $cfg['first_page_footer_html'] !== null ? $cfg['first_page_footer_html'] : $initialFooterContent);
    $initialEditorJson = old('editor_json', isset($editorJson) && is_array($editorJson) ? json_encode($editorJson) : '');
    $resolvedCompanyLogo = $assets['company_logo'] ?? $assets['company_logo_url'] ?? '/images/mou/seven-inc-logo.png';
    $resolvedInstitutionLogo = $assets['institution_logo'] ?? $assets['institution_logo_url'] ?? '/images/mou/institution-logo-placeholder.png';
@endphp

<style>
    {!! \App\Services\MouDocumentRenderer::getCanonicalDocumentCss($cfg) !!}

    /* Tiptap / ProseMirror Word Studio Styles */
    .mou-word-studio-shell {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 0.75rem;
        overflow: hidden;
        box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.06);
        display: flex;
        flex-direction: column;
    }
    .mou-studio-sticky-header {
        position: sticky;
        top: 0;
        z-index: 30;
        background: #ffffff;
        border-bottom: 1px solid #cbd5e1;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        flex-shrink: 0;
    }
    .mou-ribbon-tab-btn {
        padding: 0.55rem 1.1rem;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #475569;
        border-bottom: 2px solid transparent;
        transition: all 0.15s ease;
        white-space: nowrap;
    }
    .mou-ribbon-tab-btn:hover {
        color: #1e293b;
        background: #f1f5f9;
    }
    .mou-ribbon-tab-btn.active {
        color: #1d4ed8;
        border-bottom-color: #2563eb;
        background: #ffffff;
    }
    .mou-ribbon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        height: 2.125rem;
        padding: 0 0.65rem;
        border-radius: 0.375rem;
        border: 1px solid transparent;
        font-size: 0.78rem;
        font-weight: 600;
        color: #334155;
        background: transparent;
        transition: all 0.12s ease;
        cursor: pointer;
        white-space: nowrap;
        line-height: 1;
    }
    .mou-ribbon-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
    }
    .mou-ribbon-btn-active {
        background: #dbeafe !important;
        border-color: #93c5fd !important;
        color: #1d4ed8 !important;
    }
    .mou-ribbon-select {
        height: 2.125rem;
        font-size: 0.78rem;
        border-radius: 0.375rem;
        border: 1px solid #cbd5e1;
        padding: 0 0.5rem;
        color: #1e293b;
        background-color: #ffffff;
        outline: none;
    }
    .mou-ribbon-select:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 1px #3b82f6;
    }
    .mou-ribbon-group {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 0.35rem 0.75rem;
        border-right: 1px solid #e2e8f0;
        flex-shrink: 0;
    }
    .mou-ribbon-group-label {
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        text-align: center;
        margin-top: 0.35rem;
        white-space: nowrap;
    }

    /* Studio Workspace Row & Viewport */
    .mou-studio-workspace-row {
        position: relative;
        flex: 1 1 auto;
        display: flex;
        overflow: hidden;
        min-height: 700px;
        max-height: calc(100dvh - 130px);
    }
    .mou-workspace-viewport {
        flex: 1 1 auto;
        overflow-y: auto;
        overflow-x: auto;
        background-color: #cbd5e1;
        padding: 2rem 1rem 4rem 1rem;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    /* Horizontal Millimeter Ruler */
    .mou-ruler-bar {
        width: 210mm;
        height: 22px;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-bottom: none;
        position: relative;
        overflow: hidden;
        user-select: none;
        box-sizing: border-box;
    }
    .mou-ruler-margin-mask {
        position: absolute;
        top: 0;
        bottom: 0;
        background: #cbd5e1;
        opacity: 0.45;
        pointer-events: none;
        z-index: 1;
    }
    .mou-ruler-cm {
        position: absolute;
        top: 0;
        bottom: 0;
        border-left: 1px solid #64748b;
        font-size: 9px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        color: #475569;
        padding-left: 2px;
        line-height: 12px;
        z-index: 2;
    }
    .mou-ruler-half-cm {
        position: absolute;
        bottom: 0;
        height: 6px;
        border-left: 1px solid #94a3b8;
        z-index: 2;
    }

    /* A4 Paper Canvas */
    .mou-a4-paper-sheet {
        width: 210mm;
        min-width: 210mm;
        max-width: 210mm;
        min-height: 297mm;
        background: #ffffff;
        box-shadow: 0 12px 32px -6px rgba(15, 23, 42, 0.22), 0 4px 12px -2px rgba(15, 23, 42, 0.12);
        border: 1px solid #94a3b8;
        box-sizing: border-box;
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        color: #000000;
        transform-origin: top center;
        transition: transform 0.15s ease;
    }

    /* Header, Body, Footer Zones on A4 Sheet */
    .mou-header-zone-wrapper {
        position: relative;
        min-height: 12mm;
        margin-bottom: 3mm;
        border: 1px dashed transparent;
        border-radius: 4px;
        transition: all 0.15s ease;
    }
    .mou-header-zone-wrapper:hover {
        border-color: #cbd5e1;
        background: rgba(248, 250, 252, 0.5);
    }
    .mou-footer-zone-wrapper {
        position: relative;
        min-height: 16mm;
        margin-top: 4mm;
        padding-top: 2mm;
        border: 1px dashed transparent;
        border-radius: 4px;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 8mm;
        transition: all 0.15s ease;
    }
    .mou-footer-zone-wrapper:hover {
        border-color: #cbd5e1;
        background: rgba(248, 250, 252, 0.5);
    }
    .mou-zone-active {
        border-color: #f59e0b !important;
        background: rgba(254, 243, 199, 0.18) !important;
        box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.15);
    }
    .mou-zone-dimmed {
        opacity: 0.65;
    }
    .mou-zone-tag {
        position: absolute;
        top: -18px;
        left: 4px;
        font-size: 9px;
        font-family: ui-sans-serif, system-ui, sans-serif;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 1px 6px;
        border-radius: 3px;
        background: #f59e0b;
        color: #ffffff;
        display: none;
        pointer-events: none;
        z-index: 20;
    }
    .mou-zone-active .mou-zone-tag {
        display: inline-block;
    }

    /* True Multi-Page A4 Paper Sheets & Desk Stage */
    .mou-pages-desk-stage {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding-bottom: 48px;
        transform-origin: top center;
        transition: transform 0.15s ease;
    }
    .mou-a4-page-header {
        position: relative;
        cursor: pointer;
        user-select: none;
        flex-shrink: 0;
        min-height: 12mm;
    }
    .mou-a4-page-footer {
        position: relative;
        cursor: pointer;
        user-select: none;
        flex-shrink: 0;
        min-height: 14mm;
    }
    .mou-page-body-content {
        flex: 1 1 0%;
        width: 100%;
        min-height: 0;
        position: relative;
        outline: none;
        word-wrap: break-word;
        overflow: visible;
    }
    .mou-page-body-content.mou-zone-dimmed {
        opacity: 0.45;
        pointer-events: none;
    }

    /* ProseMirror Content Area */
    .mou-a4-paper-sheet .ProseMirror {
        outline: none;
        min-height: 18px;
        word-wrap: break-word;
    }
    #mouWordCanvas .ProseMirror {
        min-height: 225mm;
    }
    .ProseMirror p.is-editor-empty:first-child::before {
        content: attr(data-placeholder);
        float: left;
        color: #94a3b8;
        pointer-events: none;
        height: 0;
        font-style: italic;
    }

    /* ProseMirror Table Resizing & Selection Enhancements */
    .ProseMirror .tableWrapper {
        margin: 4pt 0;
        overflow-x: visible;
        position: relative;
    }
    .ProseMirror table {
        border-collapse: collapse;
        table-layout: fixed;
    }
    .ProseMirror td,
    .ProseMirror th {
        position: relative;
        min-width: 36px;
        box-sizing: border-box;
    }
    .ProseMirror .selectedCell:after {
        z-index: 2;
        position: absolute;
        content: "";
        left: 0; right: 0; top: 0; bottom: 0;
        background: rgba(59, 130, 246, 0.14);
        pointer-events: none;
    }
    .ProseMirror .column-resize-handle {
        position: absolute;
        right: -2px;
        top: 0;
        bottom: -2px;
        width: 4px;
        background-color: #2563eb;
        pointer-events: none;
        z-index: 20;
    }
    .ProseMirror.resize-cursor {
        cursor: col-resize;
    }
    .mou-table-width-handle {
        position: absolute;
        right: -6px;
        bottom: -6px;
        width: 11px;
        height: 11px;
        background: #2563eb;
        border: 2px solid #ffffff;
        border-radius: 2px;
        cursor: ew-resize;
        opacity: 0;
        transition: opacity 0.15s ease;
        z-index: 25;
    }
    .ProseMirror .tableWrapper:hover .mou-table-width-handle,
    .mou-zone-active .tableWrapper .mou-table-width-handle {
        opacity: 1;
    }

    /* Row Height / Table Width Live Drag Guide */
    .mou-resize-guide-line {
        position: fixed;
        height: 0;
        border-top: 2px dashed #2563eb;
        z-index: 9999;
        pointer-events: none;
    }
    .mou-resize-guide-badge {
        position: absolute;
        right: 0;
        top: -22px;
        background: #1d4ed8;
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
        font-family: ui-sans-serif, system-ui, sans-serif;
        padding: 2px 6px;
        border-radius: 4px;
        white-space: nowrap;
    }

    /* Visual Page Break Indicator in Editor */
    .ProseMirror .mou-page-break {
        display: block;
        height: 20px;
        margin: 10pt 0;
        border-top: 2px dashed #64748b;
        position: relative;
        user-select: none;
        page-break-after: always;
    }
    .ProseMirror .mou-page-break::after {
        content: "PEMISAH HALAMAN MANUAL (PAGE BREAK)";
        position: absolute;
        top: -9px;
        left: 50%;
        transform: translateX(-50%);
        background: #f1f5f9;
        border: 1px solid #94a3b8;
        color: #334155;
        font-family: ui-sans-serif, system-ui, sans-serif;
        font-size: 9px;
        font-weight: 700;
        padding: 1px 8px;
        border-radius: 999px;
    }
    .mou-visual-page-break-guide {
        position: absolute;
        left: -18mm;
        right: -18mm;
        height: 0;
        border-top: 1.5px dashed #3b82f6;
        pointer-events: none;
        z-index: 15;
    }
    .mou-visual-page-break-pill {
        position: absolute;
        right: 4mm;
        top: -10px;
        background: #eff6ff;
        border: 1px solid #93c5fd;
        color: #1d4ed8;
        font-family: ui-sans-serif, system-ui, sans-serif;
        font-size: 9px;
        font-weight: 700;
        padding: 1px 8px;
        border-radius: 999px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    }

    /* Multi-Sheet A4 Preview Cards */
    .mou-preview-a4-sheet {
        width: 210mm;
        min-width: 210mm;
        max-width: 210mm;
        height: 297mm;
        min-height: 297mm;
        max-height: 297mm;
        background: #ffffff;
        border: 1px solid #94a3b8;
        box-shadow: 0 12px 28px -6px rgba(15, 23, 42, 0.22);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
        color: #000000;
    }
    .mou-preview-sheet-header {
        flex-shrink: 0;
        margin-bottom: 3mm;
    }
    .mou-preview-sheet-body {
        flex: 1 1 auto;
        overflow: hidden;
    }
    .mou-preview-sheet-footer {
        flex-shrink: 0;
        min-height: 15mm;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 8mm;
        padding-top: 2mm;
    }
    .mou-preview-sheet-footer-left {
        flex: 1 1 auto;
        min-width: 0;
    }
</style>

<!-- Hidden Inputs for Form Persistence (Canonical HTML + ProseMirror JSON + Page Config V3) -->
<input type="hidden" name="template_html" id="inputTemplateHtml" value="{{ $initialBodyContent }}">
<input type="hidden" name="editor_json" id="inputEditorJson" value="{{ $initialEditorJson }}">
<input type="hidden" name="header_html" id="inputHeaderHtml" value="{{ $initialHeaderContent }}">
<input type="hidden" name="footer_html" id="inputFooterHtml" value="{{ $initialFooterContent }}">
<input type="hidden" name="first_page_header_html" id="inputFirstPageHeaderHtml" value="{{ $initialFirstHeaderContent }}">
<input type="hidden" name="first_page_footer_html" id="inputFirstPageFooterHtml" value="{{ $initialFirstFooterContent }}">
<input type="hidden" name="different_first_page" id="inputDifferentFirstPage" value="{{ !empty($cfg['different_first_page']) ? '1' : '0' }}">
<input type="hidden" name="show_paraf_box" id="inputShowParafBox" value="{{ ($cfg['show_paraf_box'] ?? true) ? '1' : '0' }}">
<input type="hidden" name="show_page_number" id="inputShowPageNumber" value="{{ ($cfg['show_page_number'] ?? true) ? '1' : '0' }}">
<input type="hidden" name="paper_size" id="inputPaperSize" value="{{ strtoupper($cfg['paper_size'] ?? 'A4') }}">
<input type="hidden" name="orientation" id="inputOrientation" value="{{ strtolower($cfg['orientation'] ?? 'portrait') }}">
<input type="hidden" name="allow_blank_template" id="inputAllowBlankTemplate" value="0">
<input type="hidden" name="margin_top_mm" id="inputMarginTopMm" value="{{ $cfg['margin_top_mm'] ?? 25.4 }}">
<input type="hidden" name="margin_right_mm" id="inputMarginRightMm" value="{{ $cfg['margin_right_mm'] ?? 25.4 }}">
<input type="hidden" name="margin_bottom_mm" id="inputMarginBottomMm" value="{{ $cfg['margin_bottom_mm'] ?? 25.4 }}">
<input type="hidden" name="margin_left_mm" id="inputMarginLeftMm" value="{{ $cfg['margin_left_mm'] ?? 25.4 }}">
<input type="hidden" name="header_distance_mm" id="inputHeaderDistanceMm" value="{{ $cfg['header_distance_mm'] ?? 12.0 }}">
<input type="hidden" name="footer_distance_mm" id="inputFooterDistanceMm" value="{{ $cfg['footer_distance_mm'] ?? 10.0 }}">
<input type="hidden" name="font_family" id="inputFontFamily" value="{{ $cfg['font_family'] ?? 'Times New Roman' }}">
<input type="hidden" name="font_size_pt" id="inputFontSizePt" value="{{ $cfg['font_size_pt'] ?? 12 }}">
<input type="hidden" name="line_height" id="inputLineHeight" value="{{ $cfg['line_height'] ?? 1.15 }}">
<input type="hidden" name="institution_logo" id="inputInstitutionLogo" value="{{ $resolvedInstitutionLogo }}">
<input type="hidden" name="company_logo" id="inputCompanyLogo" value="{{ $resolvedCompanyLogo }}">
<input type="hidden" name="institution_logo_url" id="inputInstitutionLogoUrl" value="{{ $resolvedInstitutionLogo }}">
<input type="hidden" name="company_logo_url" id="inputCompanyLogoUrl" value="{{ $resolvedCompanyLogo }}">

<!-- Diagnostic Error Banner (Only visible if JS runtime fails to mount Tiptap) -->
<div id="mouEditorDiagnosticBanner" class="hidden mb-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-900">
    <div class="font-bold">Peringatan Diagnostik Editor Dokumen (Tiptap / ProseMirror):</div>
    <p id="mouEditorDiagnosticText" class="mt-0.5 text-rose-800">Mesin pengolah kata gagal dimuat. Pastikan aset Vite telah dikompilasi (npm run build).</p>
</div>

<div class="mou-word-studio-shell">
    <!-- Pinned / Sticky Top Ribbon Header -->
    <div id="mouStudioStickyHeader" class="mou-studio-sticky-header">
        <!-- Top Ribbon Tab Bar -->
        <div class="flex flex-wrap items-center justify-between border-b border-slate-200 bg-slate-100 px-3 pt-1.5">
            <div class="flex items-center gap-1 overflow-x-auto">
                <button type="button" data-ribbon-tab="home" class="mou-ribbon-tab-btn active">Beranda</button>
                <button type="button" data-ribbon-tab="insert" class="mou-ribbon-tab-btn">Sisipkan</button>
                <button type="button" data-ribbon-tab="layout" class="mou-ribbon-tab-btn">Tata Letak</button>
                <button type="button" data-ribbon-tab="table" class="mou-ribbon-tab-btn inline-flex items-center gap-1.5">
                    <span>Desain & Tata Letak Tabel</span>
                    <span id="ribbonTableContextBadge" class="hidden rounded bg-blue-600 px-1.5 py-0.5 text-[10px] font-bold uppercase text-white">Aktif</span>
                </button>
                <button type="button" data-ribbon-tab="headerfooter" class="mou-ribbon-tab-btn">Header & Footer</button>
            </div>

            <div class="flex items-center gap-2 pb-1.5">
                <span id="activeEditorZoneBadge" class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 border border-blue-200 px-3 py-1 text-xs font-semibold text-blue-700">
                    Mode Edit: Isi Dokumen Utama
                </span>

                <button
                    type="button"
                    id="btnCloseHeaderFooter"
                    onclick="window.MouWordProcessor?.setActiveZone('body')"
                    class="hidden rounded-md bg-amber-600 px-3 py-1 text-xs font-bold text-white shadow-sm hover:bg-amber-700"
                >
                    Tutup Header / Footer
                </button>

                <button
                    type="button"
                    id="btnTogglePlaceholderDrawer"
                    class="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-100 transition"
                    title="Buka / Tutup Panel Variabel Dinamis (Mail Merge)"
                >
                    <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>
                    </svg>
                    <span>Panel Variabel (Mail Merge)</span>
                </button>

                <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5">
                    <button
                        type="button"
                        id="btnModeEdit"
                        class="rounded-md bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs"
                    >
                        Editor Word
                    </button>
                    <button
                        type="button"
                        id="btnModePreview"
                        class="rounded-md px-3 py-1 text-xs font-semibold text-slate-600 hover:text-slate-900 transition"
                    >
                        Pratinjau Lembar A4
                    </button>
                </div>
            </div>
        </div>

        <!-- RIBBON COMMAND PANELS -->
        <div class="border-b border-slate-200 bg-white px-2.5 py-2">
            <!-- TAB 1: BERANDA (HOME) -->
            <div id="ribbonPanel-home" data-ribbon-panel="home" class="flex flex-nowrap items-stretch gap-x-2 overflow-x-auto pb-1">
                <!-- Group: Riwayat & Dokumen -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.undo()" class="mou-ribbon-btn" title="Urungkan (Ctrl+Z)">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.redo()" class="mou-ribbon-btn" title="Ulangi (Ctrl+Y)">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/></svg>
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.clearFormatting()" class="mou-ribbon-btn" title="Bersihkan Pemformatan Teks">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M13 4L8 20"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
                        </button>
                        <button type="button" id="btnNewBlankDoc" class="mou-ribbon-btn border-slate-300 bg-slate-50" title="Kosongkan halaman untuk menulis dari awal">
                            Halaman Kosong
                        </button>
                        <button type="button" id="btnLoadSampleMou" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700" title="Muat contoh template MoU standar Seven Inc yang dapat diedit bebas">
                            Contoh MoU
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <select id="ribbonSavedTemplateSelect" class="mou-ribbon-select min-w-[240px] max-w-[290px]" title="Pilih template MoU yang pernah disimpan sebelumnya">
                            <option value="">-- Muat dari Template Tersimpan --</option>
                            @foreach($savedTemplates as $tplItem)
                                <option value="{{ $tplItem['id'] }}">
                                    {{ $tplItem['name'] }} (v{{ $tplItem['version_number'] }}){{ !empty($tplItem['is_active']) ? ' — Aktif' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <button type="button" id="btnLoadSelectedSavedTemplate" class="mou-ribbon-btn border-emerald-300 bg-emerald-50 text-emerald-800" title="Muat isi dan konfigurasi dari template tersimpan yang dipilih">
                            Muat Template
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Dokumen &amp; Template</div>
                </div>

                <!-- Group: Font & Gaya Huruf -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <select id="ribbonFontFamily" class="mou-ribbon-select min-w-[155px]">
                            <option value="Times New Roman">Times New Roman</option>
                            <option value="Arial">Arial</option>
                            <option value="Calibri">Calibri</option>
                            <option value="Georgia">Georgia</option>
                            <option value="Cambria">Cambria</option>
                            <option value="Courier New">Courier New</option>
                        </select>

                        <select id="ribbonFontSize" class="mou-ribbon-select w-20">
                            @foreach([8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36] as $pt)
                                <option value="{{ $pt }}pt" {{ (int)($cfg['font_size_pt'] ?? 12) === $pt ? 'selected' : '' }}>{{ $pt }} pt</option>
                            @endforeach
                        </select>

                        <select id="ribbonHeadingStyle" class="mou-ribbon-select min-w-[150px]" title="Gaya Paragraf / Judul">
                            <option value="p">Paragraf Normal</option>
                            <option value="h1">Judul 1 (Heading 1)</option>
                            <option value="h2">Judul 2 (Heading 2)</option>
                            <option value="h3">Judul 3 (Heading 3)</option>
                            <option value="h4">Subjudul (Heading 4)</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-1 mt-1.5">
                        <button type="button" data-tiptap-mark="bold" onclick="window.MouWordProcessor?.toggleBold()" class="mou-ribbon-btn font-bold w-8" title="Tebal (Ctrl+B)">B</button>
                        <button type="button" data-tiptap-mark="italic" onclick="window.MouWordProcessor?.toggleItalic()" class="mou-ribbon-btn italic w-8 font-serif" title="Miring (Ctrl+I)">I</button>
                        <button type="button" data-tiptap-mark="underline" onclick="window.MouWordProcessor?.toggleUnderline()" class="mou-ribbon-btn underline w-8" title="Garis Bawah (Ctrl+U)">U</button>
                        <button type="button" data-tiptap-mark="strike" onclick="window.MouWordProcessor?.toggleStrike()" class="mou-ribbon-btn line-through w-8" title="Coret">S</button>
                        <button type="button" data-tiptap-mark="subscript" onclick="window.MouWordProcessor?.toggleSubscript()" class="mou-ribbon-btn w-9" title="Subscript">X<sub>2</sub></button>
                        <button type="button" data-tiptap-mark="superscript" onclick="window.MouWordProcessor?.toggleSuperscript()" class="mou-ribbon-btn w-9" title="Superscript">X<sup>2</sup></button>
                        <button type="button" id="btnToggleUppercase" onclick="window.MouWordProcessor?.toggleUppercaseSelection()" class="mou-ribbon-btn font-bold px-2" title="Ubah Kapitalisasi Teks Terpilih (UPPERCASE / lowercase)">AA</button>

                        <label class="inline-flex items-center gap-1 text-xs text-slate-700 ml-1.5 cursor-pointer" title="Warna Teks">
                            <span class="font-bold underline">A</span>
                            <input type="color" id="ribbonTextColor" value="#000000" class="h-6 w-6 cursor-pointer rounded border border-slate-300 p-0">
                        </label>

                        <label class="inline-flex items-center gap-1 text-xs text-slate-700 ml-1 cursor-pointer" title="Warna Sorotan (Highlight)">
                            <span class="bg-yellow-200 px-1 rounded font-semibold text-[11px]">ab</span>
                            <input type="color" id="ribbonHighlightColor" value="#fef08a" class="h-6 w-6 cursor-pointer rounded border border-slate-300 p-0">
                        </label>
                        <button type="button" onclick="window.MouWordProcessor?.setHighlightColor('transparent')" class="mou-ribbon-btn text-xs text-slate-500" title="Hapus Highlight">No HL</button>
                    </div>
                    <div class="mou-ribbon-group-label">Font &amp; Gaya Huruf</div>
                </div>

                <!-- Group: Paragraf & Perataan -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1">
                        <button type="button" data-tiptap-align="left" onclick="window.MouWordProcessor?.setTextAlign('left')" class="mou-ribbon-btn" title="Rata Kiri">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg>
                        </button>
                        <button type="button" data-tiptap-align="center" onclick="window.MouWordProcessor?.setTextAlign('center')" class="mou-ribbon-btn" title="Rata Tengah">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="10" x2="6" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="18" y1="18" x2="6" y2="18"/></svg>
                        </button>
                        <button type="button" data-tiptap-align="right" onclick="window.MouWordProcessor?.setTextAlign('right')" class="mou-ribbon-btn" title="Rata Kanan">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="7" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="7" y2="18"/></svg>
                        </button>
                        <button type="button" data-tiptap-align="justify" onclick="window.MouWordProcessor?.setTextAlign('justify')" class="mou-ribbon-btn" title="Rata Kiri-Kanan (Justify)">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="3" y2="18"/></svg>
                        </button>

                        <select id="ribbonParagraphLineHeight" class="mou-ribbon-select min-w-[110px]" title="Spasi Baris Paragraf">
                            <option value="1">Spasi 1.0</option>
                            <option value="1.15" selected>Spasi 1.15</option>
                            <option value="1.5">Spasi 1.5</option>
                            <option value="2">Spasi 2.0</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-1 mt-1.5">
                        <button type="button" id="btnToggleBulletList" onclick="window.MouWordProcessor?.toggleBulletList()" class="mou-ribbon-btn" title="Daftar Poin (Bullet List)">
                            &bull; Poin
                        </button>
                        <button type="button" id="btnToggleOrderedList" onclick="window.MouWordProcessor?.toggleOrderedList()" class="mou-ribbon-btn" title="Daftar Bernomor (Numbered List)">
                            1. Nomor
                        </button>
                        <select id="ribbonOrderedListStyle" class="mou-ribbon-select min-w-[95px]" title="Format Penomoran Daftar">
                            <option value="1">1, 2, 3</option>
                            <option value="a">a, b, c</option>
                            <option value="A">A, B, C</option>
                            <option value="i">i, ii, iii</option>
                            <option value="I">I, II, III</option>
                        </select>
                        <button type="button" onclick="window.MouWordProcessor?.adjustIndent(-6)" class="mou-ribbon-btn" title="Kurangi Indentasi Kiri">
                            &larr; Indent
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.adjustIndent(6)" class="mou-ribbon-btn" title="Tambah Indentasi Kiri">
                            Indent &rarr;
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Paragraf &amp; Daftar</div>
                </div>

                <!-- Group: Cari & Ganti -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-1.5">
                        <input type="text" id="ribbonFindInput" placeholder="Cari teks..." class="h-[34px] w-32 rounded-md border border-slate-300 px-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                        <input type="text" id="ribbonReplaceInput" placeholder="Ganti dengan..." class="h-[34px] w-32 rounded-md border border-slate-300 px-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    </div>
                    <div class="flex items-center justify-between gap-1 mt-1.5">
                        <button type="button" id="btnExecuteReplaceAll" class="mou-ribbon-btn border-slate-200 bg-slate-50 w-full text-xs">
                            Ganti Semua di Area Aktif
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Cari &amp; Ganti</div>
                </div>
            </div>

            <!-- TAB 2: SISIPKAN (INSERT) -->
            <div id="ribbonPanel-insert" data-ribbon-panel="insert" class="hidden flex-nowrap items-stretch gap-x-2 overflow-x-auto pb-1">
                <!-- Group: Tabel -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <div class="flex items-center gap-1">
                            <label class="text-xs text-slate-600 font-semibold">Baris:</label>
                            <input type="number" id="insertTableRows" value="3" min="1" max="30" class="h-[34px] w-14 rounded-md border border-slate-300 px-1.5 text-xs text-center">
                            <label class="text-xs text-slate-600 font-semibold ml-1">Kolom:</label>
                            <input type="number" id="insertTableCols" value="2" min="1" max="12" class="h-[34px] w-14 rounded-md border border-slate-300 px-1.5 text-xs text-center">
                        </div>
                        <button type="button" id="btnInsertCustomTable" class="mou-ribbon-btn border-blue-300 bg-blue-50 text-blue-700">
                            + Sisipkan Tabel
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.insertTable(2, 2, false)" class="mou-ribbon-btn border-slate-200 bg-slate-50">Tabel 2&times;2</button>
                        <button type="button" onclick="window.MouWordProcessor?.insertTable(4, 3, false)" class="mou-ribbon-btn border-slate-200 bg-slate-50">Tabel 4&times;3</button>
                        <button type="button" onclick="window.MouWordProcessor?.insertTable(4, 2, false)" class="mou-ribbon-btn border-slate-200 bg-slate-50">Tabel TTD (4&times;2)</button>
                    </div>
                    <div class="mou-ribbon-group-label">Tabel Bebas</div>
                </div>

                <!-- Group: Gambar & Logo -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <label class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700 cursor-pointer">
                            <span>Unggah Gambar</span>
                            <input type="file" id="inputUploadCustomImage" accept="image/png,image/jpeg,image/webp" class="hidden">
                        </label>
                        <label class="mou-ribbon-btn border-slate-200 bg-slate-50 cursor-pointer" title="Unggah &amp; Sisipkan Logo Institusi">
                            <span>Logo Institusi</span>
                            <input type="file" id="inputUploadInstitutionLogo" accept="image/png,image/jpeg,image/webp" class="hidden">
                        </label>
                        <button type="button" id="btnInsertCompanyLogo" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Sisipkan Logo Seven Inc">
                            Logo Seven Inc
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <span class="text-xs text-slate-500 font-semibold">Lebar (px):</span>
                        <input type="number" id="ribbonImageWidthPx" value="95" min="24" max="650" class="h-[34px] w-18 rounded-md border border-slate-300 px-2 text-xs text-center">
                        <button type="button" id="btnApplyImageSize" class="mou-ribbon-btn border-slate-200 bg-slate-50">Terapkan</button>
                        <button type="button" onclick="window.MouWordProcessor?.updateSelectedImage(null, 'left')" class="mou-ribbon-btn" title="Gambar Rata Kiri">Kiri</button>
                        <button type="button" onclick="window.MouWordProcessor?.updateSelectedImage(null, 'center')" class="mou-ribbon-btn" title="Gambar Rata Tengah">Tengah</button>
                        <button type="button" onclick="window.MouWordProcessor?.updateSelectedImage(null, 'right')" class="mou-ribbon-btn" title="Gambar Rata Kanan">Kanan</button>
                    </div>
                    <div class="mou-ribbon-group-label">Gambar &amp; Media</div>
                </div>

                <!-- Group: Elemen Dokumen, Tautan, Simbol & Variabel -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.insertTextBox()" class="mou-ribbon-btn border-slate-200 bg-slate-50">
                            + Kotak Teks
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.insertPageBreak()" class="mou-ribbon-btn border-slate-200 bg-slate-50">
                            + Pemisah Halaman
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.insertHorizontalRule()" class="mou-ribbon-btn border-slate-200 bg-slate-50">
                            + Garis Horizontal
                        </button>
                        <button type="button" id="btnInsertHyperlink" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Sisipkan Tautan / Hyperlink">
                            + Tautan (Link)
                        </button>
                        <select id="ribbonQuickSymbolSelect" class="mou-ribbon-select min-w-[125px]" title="Sisipkan Simbol Hukum / Karakter Khusus">
                            <option value="">Simbol (&sect;)...</option>
                            <option value="§">§ (Pasal / Section)</option>
                            <option value="•">• (Bullet)</option>
                            <option value="–">– (En Dash)</option>
                            <option value="—">— (Em Dash)</option>
                            <option value="©">© (Copyright)</option>
                            <option value="®">® (Registered)</option>
                            <option value="±">± (Kurang Lebih)</option>
                            <option value="°">° (Derajat)</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <select id="ribbonQuickPlaceholderSelect" class="mou-ribbon-select min-w-[250px]">
                            <option value="">-- Pilih Variabel Dinamis (Mail Merge) --</option>
                            @foreach($placeholders as $phKey => $phDesc)
                                @php
                                    $phBracket = '[' . $phKey . ']';
                                    $phMustache = chr(123) . chr(123) . $phKey . chr(125) . chr(125);
                                @endphp
                                <option value="{{ $phBracket }}">{{ $phBracket }} ({{ $phMustache }}) — {{ $phDesc }}</option>
                            @endforeach
                        </select>
                        <button type="button" id="btnInsertSelectedPlaceholder" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700">
                            Sisipkan Variabel
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Elemen, Simbol &amp; Variabel</div>
                </div>
            </div>

            <!-- TAB 3: TATA LETAK (PAGE LAYOUT) -->
            <div id="ribbonPanel-layout" data-ribbon-panel="layout" class="hidden flex-nowrap items-stretch gap-x-2 overflow-x-auto pb-1">
                <!-- Group: Ukuran Kertas & Orientasi -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-2">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 mb-0.5">Ukuran Kertas</label>
                            <select id="layoutPaperSize" class="mou-ribbon-select min-w-[160px]">
                                <option value="A4" {{ strtoupper($cfg['paper_size'] ?? 'A4') === 'A4' ? 'selected' : '' }}>A4 (210 &times; 297 mm)</option>
                                <option value="LETTER" {{ strtoupper($cfg['paper_size'] ?? 'A4') === 'LETTER' ? 'selected' : '' }}>Letter (215.9 &times; 279.4 mm)</option>
                                <option value="LEGAL" {{ strtoupper($cfg['paper_size'] ?? 'A4') === 'LEGAL' ? 'selected' : '' }}>Legal (215.9 &times; 355.6 mm)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 mb-0.5">Orientasi</label>
                            <select id="layoutOrientation" class="mou-ribbon-select min-w-[150px]">
                                <option value="portrait" {{ strtolower($cfg['orientation'] ?? 'portrait') === 'portrait' ? 'selected' : '' }}>Portrait (Tegak)</option>
                                <option value="landscape" {{ strtolower($cfg['orientation'] ?? 'portrait') === 'landscape' ? 'selected' : '' }}>Landscape (Mendatar)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mou-ribbon-group-label">Kertas &amp; Orientasi</div>
                </div>

                <!-- Group: Preset Margin -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <button type="button" data-margin-preset="25.4,25.4,25.4,25.4" class="mou-ribbon-btn border-slate-200 bg-slate-50">Normal (25.4 mm)</button>
                        <button type="button" data-margin-preset="19.1,19.1,19.1,19.1" class="mou-ribbon-btn border-slate-200 bg-slate-50">Sedang (19.1 mm)</button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <button type="button" data-margin-preset="12.7,12.7,12.7,12.7" class="mou-ribbon-btn border-slate-200 bg-slate-50">Sempit (12.7 mm)</button>
                        <button type="button" data-margin-preset="30,25.4,30,30" class="mou-ribbon-btn border-slate-200 bg-slate-50">Lebar (30 mm)</button>
                    </div>
                    <div class="mou-ribbon-group-label">Preset Margin</div>
                </div>

                <!-- Group: Margin Kustom (mm) -->
                <div class="mou-ribbon-group">
                    <div class="grid grid-cols-4 gap-2">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Atas (mm)</label>
                            <input type="number" step="0.1" min="5" max="60" id="layoutMarginTop" value="{{ $cfg['margin_top_mm'] ?? 25.4 }}" class="h-[34px] w-18 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Bawah (mm)</label>
                            <input type="number" step="0.1" min="5" max="60" id="layoutMarginBottom" value="{{ $cfg['margin_bottom_mm'] ?? 25.4 }}" class="h-[34px] w-18 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Kiri (mm)</label>
                            <input type="number" step="0.1" min="5" max="60" id="layoutMarginLeft" value="{{ $cfg['margin_left_mm'] ?? 25.4 }}" class="h-[34px] w-18 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Kanan (mm)</label>
                            <input type="number" step="0.1" min="5" max="60" id="layoutMarginRight" value="{{ $cfg['margin_right_mm'] ?? 25.4 }}" class="h-[34px] w-18 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                    </div>
                    <div class="mou-ribbon-group-label">Ukuran Margin Kertas (mm)</div>
                </div>

                <!-- Group: Indentasi & Spasi Paragraf -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-2">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Indent Baris 1 (mm)</label>
                            <input type="number" step="1" min="0" max="50" id="layoutFirstLineIndent" value="0" class="h-[34px] w-20 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Spasi Sebelum (pt)</label>
                            <input type="number" step="1" min="0" max="48" id="layoutSpaceBefore" value="0" class="h-[34px] w-20 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Spasi Sesudah (pt)</label>
                            <input type="number" step="1" min="0" max="48" id="layoutSpaceAfter" value="6" class="h-[34px] w-20 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                        <button type="button" id="btnApplyParagraphLayout" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700 mt-3.5">
                            Terapkan
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Indentasi &amp; Jarak Antarparagraf</div>
                </div>
            </div>

            <!-- TAB 4: DESAIN & TATA LETAK TABEL (TABLE TOOLS) -->
            <div id="ribbonPanel-table" data-ribbon-panel="table" class="hidden flex-nowrap items-stretch gap-x-2 overflow-x-auto pb-1">
                <!-- Group: Baris & Kolom -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.addRowBefore()" class="mou-ribbon-btn border-slate-200 bg-slate-50">+ Baris Atas</button>
                        <button type="button" onclick="window.MouWordProcessor?.addRowAfter()" class="mou-ribbon-btn border-slate-200 bg-slate-50">+ Baris Bawah</button>
                        <button type="button" onclick="window.MouWordProcessor?.deleteRow()" class="mou-ribbon-btn text-rose-600 hover:bg-rose-50">- Hapus Baris</button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.addColumnBefore()" class="mou-ribbon-btn border-slate-200 bg-slate-50">+ Kolom Kiri</button>
                        <button type="button" onclick="window.MouWordProcessor?.addColumnAfter()" class="mou-ribbon-btn border-slate-200 bg-slate-50">+ Kolom Kanan</button>
                        <button type="button" onclick="window.MouWordProcessor?.deleteColumn()" class="mou-ribbon-btn text-rose-600 hover:bg-rose-50">- Hapus Kolom</button>
                    </div>
                    <div class="mou-ribbon-group-label">Baris &amp; Kolom</div>
                </div>

                <!-- Group: Gabung & Hapus -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.mergeCells()" class="mou-ribbon-btn border-slate-200 bg-slate-50">Gabung Sel (Merge)</button>
                        <button type="button" onclick="window.MouWordProcessor?.splitCell()" class="mou-ribbon-btn border-slate-200 bg-slate-50">Pisah Sel (Split)</button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.deleteTable()" class="mou-ribbon-btn border-rose-200 bg-rose-50 text-rose-700 w-full">Hapus Tabel</button>
                    </div>
                    <div class="mou-ribbon-group-label">Gabung &amp; Tabel</div>
                </div>

                <!-- Group: Ukuran Tabel, Kolom & Tinggi Baris (TTD / Paraf) -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <label class="text-xs font-semibold text-slate-600">Lebar:</label>
                        <input type="text" id="ribbonTableWidthInput" value="100%" placeholder="100% / 220pt" class="h-[34px] w-20 rounded-md border border-slate-300 px-2 text-xs">
                        <button type="button" id="btnApplyTableWidth" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700">Set</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentTableWidth('100%')" class="mou-ribbon-btn border-slate-200 bg-slate-50">100%</button>

                        <label class="text-xs font-semibold text-slate-600 ml-1">Kolom (px):</label>
                        <input type="number" id="ribbonColWidthInput" placeholder="180" min="30" max="700" class="h-[34px] w-18 rounded-md border border-slate-300 px-2 text-xs text-center">
                        <button type="button" id="btnApplyColWidth" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700">Set</button>
                    </div>

                    <div class="flex items-center gap-1.5 mt-1.5">
                        <label class="text-xs font-semibold text-slate-600">Tinggi:</label>
                        <input type="text" id="ribbonRowHeightInput" placeholder="18pt / 26mm" class="h-[34px] w-20 rounded-md border border-slate-300 px-2 text-xs">
                        <button type="button" id="btnApplyRowHeight" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700">Set</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('18pt')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi standar ruang Paraf">18pt (Paraf)</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('28pt')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi ruang Paraf diperbesar">28pt (Paraf+)</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('26mm')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi standar ruang Tanda Tangan">26mm (TTD)</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('35mm')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi ruang Tanda Tangan besar">35mm (TTD+)</button>
                    </div>
                    <div class="mou-ribbon-group-label">Ukuran Tabel, Kolom &amp; Tinggi Baris</div>
                </div>

                <!-- Group: Garis Tepi (Borders), Padding & Perataan -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.applyTablePreset('grid')" class="mou-ribbon-btn border-slate-200 bg-slate-50">Bergaris Penuh</button>
                        <button type="button" onclick="window.MouWordProcessor?.applyTablePreset('borderless')" class="mou-ribbon-btn border-slate-200 bg-slate-50">Tanpa Garis (Komparisi/TTD)</button>
                        <button type="button" onclick="window.MouWordProcessor?.applyTablePreset('paraf')" class="mou-ribbon-btn border-slate-200 bg-slate-50">Gaya Kotak Paraf</button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <select id="ribbonCellPaddingSelect" class="mou-ribbon-select min-w-[130px]" title="Padding Sel">
                            <option value="">Padding Sel...</option>
                            <option value="2pt 4pt">Rapat (2pt 4pt)</option>
                            <option value="4pt 6pt">Normal (4pt 6pt)</option>
                            <option value="6pt 8pt">Lega (6pt 8pt)</option>
                        </select>
                        <select id="ribbonCellValignSelect" class="mou-ribbon-select min-w-[130px]" title="Perataan Vertikal Sel">
                            <option value="top">Vertikal Atas</option>
                            <option value="middle">Vertikal Tengah</option>
                            <option value="bottom">Vertikal Bawah</option>
                        </select>
                        <label class="inline-flex items-center gap-1 text-xs text-slate-700 cursor-pointer ml-1" title="Warna Latar Sel">
                            <span>Latar:</span>
                            <input type="color" id="ribbonCellBgColor" value="#ffffff" class="h-6 w-6 rounded border border-slate-300 p-0">
                        </label>
                    </div>
                    <div class="mou-ribbon-group-label">Batas Garis &amp; Sel</div>
                </div>
            </div>

            <!-- TAB 5: HEADER & FOOTER -->
            <div id="ribbonPanel-headerfooter" data-ribbon-panel="headerfooter" class="hidden flex-nowrap items-stretch gap-x-2 overflow-x-auto pb-1">
                <!-- Group: Akses Cepat Area Header & Footer -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.setActiveZone('header')" class="mou-ribbon-btn border-amber-300 bg-amber-50 text-amber-900">
                            Edit Header Dokumen
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.setActiveZone('footer')" class="mou-ribbon-btn border-amber-300 bg-amber-50 text-amber-900">
                            Edit Footer &amp; Kotak Paraf
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.setActiveZone('body')" class="mou-ribbon-btn border-slate-200 bg-slate-50">
                            Kembali ke Isi Dokumen Utama
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.resetFooterParafTable()" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700" title="Muat ulang tabel Kotak Paraf 2 kolom di Footer">
                            Reset Tabel Kotak Paraf
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Area Header &amp; Footer (Klik Ganda pada Kertas)</div>
                </div>

                <!-- Group: Beda Halaman Pertama & Opsi Tampilan -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 cursor-pointer">
                            <input type="checkbox" id="chkDifferentFirstPage" {{ !empty($cfg['different_first_page']) ? 'checked' : '' }} class="rounded border-slate-300 text-blue-600">
                            <span>Beda Halaman Pertama (Different First Page)</span>
                        </label>

                        <select id="selectHeaderFooterVariant" class="mou-ribbon-select min-w-[210px]">
                            <option value="default">Sedang Mengedit: Halaman Umum (Semua Halaman)</option>
                            <option value="first">Sedang Mengedit: Khusus Halaman Pertama</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-4 mt-1.5">
                        <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="chkShowParafBox" {{ ($cfg['show_paraf_box'] ?? true) ? 'checked' : '' }} class="rounded border-slate-300 text-blue-600">
                            <span>Tampilkan Area Footer / Kotak Paraf</span>
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="chkShowPageNumber" {{ ($cfg['show_page_number'] ?? true) ? 'checked' : '' }} class="rounded border-slate-300 text-blue-600">
                            <span>Tampilkan Nomor Halaman</span>
                        </label>
                    </div>
                    <div class="mou-ribbon-group-label">Pengaturan Halaman Pertama &amp; Visibilitas</div>
                </div>

                <!-- Group: Jarak Header/Footer -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-2">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Jarak Header (mm)</label>
                            <input type="number" step="0.5" min="5" max="40" id="inputRibbonHeaderDist" value="{{ $cfg['header_distance_mm'] ?? 12.0 }}" class="h-[34px] w-20 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500">Jarak Footer (mm)</label>
                            <input type="number" step="0.5" min="5" max="40" id="inputRibbonFooterDist" value="{{ $cfg['footer_distance_mm'] ?? 10.0 }}" class="h-[34px] w-20 rounded-md border border-slate-300 px-2 text-xs text-center">
                        </div>
                    </div>
                    <div class="mou-ribbon-group-label">Posisi dari Tepi Kertas</div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN STUDIO WORKSPACE ROW: Canvas + Right Mail Merge Drawer Sidebar -->
    <div id="mouStudioMainWorkspaceRow" class="mou-studio-workspace-row">
        <!-- WORKSPACE VIEW 1: LIVE WORD PROCESSOR CANVAS -->
        <div id="mouEditorWorkspaceView" class="mou-workspace-viewport">
            <!-- Horizontal Millimeter Ruler (0 - 210 mm) -->
            <div id="mouHorizontalRulerTrack" class="mou-ruler-bar mb-0 shadow-xs">
                <div id="mouRulerLeftMargin" class="mou-ruler-margin-mask" style="left: 0; width: {{ $cfg['margin_left_mm'] ?? 25.4 }}mm;"></div>
                <div id="mouRulerRightMargin" class="mou-ruler-margin-mask" style="right: 0; width: {{ $cfg['margin_right_mm'] ?? 25.4 }}mm;"></div>
            </div>

            <!-- True Multi-Page A4 Desk Stage: Holds individual physical A4 paper sheets -->
            <div id="mouPagesDeskStage" class="mou-pages-desk-stage flex flex-col items-center w-full">
                <div id="mouWordCanvas" class="w-full flex flex-col items-center"></div>
            </div>

            <!-- Staging container for Header and Footer Editors when active -->
            <div id="mouHfStagingContainer" class="hidden">
                <div id="mouHeaderZoneWrapper" class="mou-header-zone-wrapper" title="Header dokumen">
                    <span class="mou-zone-tag">Header Dokumen (Klik Tutup atau Tekan Esc untuk Kembali ke Isi)</span>
                    <div id="mouHeaderCanvas"></div>
                </div>
                <div id="mouFooterZoneWrapper" class="mou-footer-zone-wrapper" title="Footer dokumen &amp; Kotak Paraf">
                    <span class="mou-zone-tag">Footer &amp; Kotak Paraf (Tarik Garis Tabel untuk Ubah Ukuran)</span>
                    <div id="mouFooterCanvas" class="flex-1 min-w-0"></div>
                    <div id="mouFooterPageNumberPreview" class="font-serif text-[9pt] text-black pb-1 shrink-0 select-none">
                        Halaman 1 dari 1
                    </div>
                </div>
            </div>
        </div>

        <!-- WORKSPACE VIEW 2: MULTI-SHEET A4 PREVIEW SIMULATION -->
        <div id="mouMultiSheetPreviewContainer" class="hidden flex-1 overflow-y-auto overflow-x-auto bg-slate-300 py-8 px-4">
            <div class="mx-auto max-w-4xl mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 flex items-start gap-2.5">
                <svg class="h-4 w-4 text-amber-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>
                    <span class="font-bold">Informasi Akurasi Pagination A4 vs DomPDF:</span>
                    Pratinjau lembar di bawah ini adalah simulasi pembagian kertas A4 di browser berdasarkan tinggi elemen terukur (termasuk nilai Pihak Kedua yang sedang diisi, ruang Header, dan Tabel Kotak Paraf di Footer). Gunakan tombol <strong>Unduh PDF Simulasi (A4)</strong> sebagai acuan akhir pencetakan resmi.
                </div>
            </div>
            <div id="mouMultiSheetPreviewStage" class="flex flex-col items-center gap-7"></div>
        </div>

        <!-- RIGHT SIDEBAR: Collapsible Mail Merge / Dynamic Placeholder Drawer -->
        <aside id="mouMailMergeSidebar" class="mou-mail-merge-sidebar shadow-sm">
            <!-- Sidebar Header -->
            <div class="p-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-blue-800">Mail Merge</span>
                    <span class="text-xs font-bold text-slate-800">Variabel Dinamis</span>
                </div>
                <button
                    type="button"
                    id="btnCloseMailMergeSidebar"
                    class="h-7 w-7 inline-flex items-center justify-center rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-200 transition"
                    title="Tutup Panel Mail Merge"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>

            <!-- Search Filter for Variables -->
            <div class="p-2.5 border-b border-slate-100 bg-white">
                <div class="relative">
                    <input
                        type="text"
                        id="mouSidebarTokenSearch"
                        placeholder="Cari variabel (nama, alamat, dsb)..."
                        class="w-full h-8 pl-8 pr-3 text-xs rounded-lg border border-slate-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                    >
                    <svg class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </div>
                <p class="text-[10px] text-slate-500 mt-1">Klik tombol <strong>+ Sisip</strong> untuk memasukkan ke posisi kursor.</p>
            </div>

            <!-- Category Accordion List (Scrollable) -->
            <div id="mouSidebarTokenCategories" class="flex-1 overflow-y-auto p-2.5 space-y-2">
                @foreach($placeholderGroups as $groupLabel => $groupKeys)
                    <div class="mou-token-category-card rounded-lg border border-slate-200 bg-slate-50/70 overflow-hidden shadow-2xs">
                        <button
                            type="button"
                            class="mou-token-category-toggle w-full px-3 py-2 text-left flex items-center justify-between text-xs font-bold text-slate-700 hover:bg-slate-100 transition"
                        >
                            <span>{{ $groupLabel }} ({{ count($groupKeys) }})</span>
                            <svg class="h-3.5 w-3.5 text-slate-400 transform transition-transform duration-150 rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>
                        <div class="mou-token-category-body p-2 bg-white border-t border-slate-100 space-y-1.5">
                            @foreach($groupKeys as $phKey)
                                @php
                                    $phDesc = $placeholders[$phKey] ?? $phKey;
                                    $phBracket = '[' . $phKey . ']';
                                @endphp
                                <div class="mou-token-item flex items-center justify-between gap-1 p-1 rounded hover:bg-slate-50 border border-transparent hover:border-slate-200 transition" data-token-search="{{ strtolower($phKey . ' ' . $phDesc) }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[11px] font-mono font-bold text-blue-700 truncate" title="{{ $phBracket }}">{{ $phBracket }}</div>
                                        <div class="text-[10px] text-slate-500 truncate" title="{{ $phDesc }}">{{ $phDesc }}</div>
                                    </div>
                                    <button
                                        type="button"
                                        onmousedown="event.preventDefault()"
                                        onclick="window.MouWordProcessor?.insertPlaceholder('{{ $phBracket }}')"
                                        class="h-6 px-2 text-[10px] font-semibold rounded bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-600 hover:text-white transition shrink-0"
                                        title="Sisipkan {{ $phBracket }} ke posisi kursor aktif"
                                    >
                                        + Sisip
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </aside>
    </div>

    <!-- Pinned Bottom Word Status Bar -->
    <div id="mouStudioBottomStatusBar" class="shrink-0 border-t border-slate-200 bg-slate-100 px-4 py-2.5 text-xs text-slate-600 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-4">
            <span id="statusBarPageCount" class="font-semibold text-slate-800">Estimasi 1 Halaman A4</span>
            <span class="text-slate-300">|</span>
            <span id="statusBarWordCount">0 Kata (0 Karakter)</span>
            <span class="text-slate-300">|</span>
            <span>Kertas: <strong id="statusBarPaperSizeLabel">{{ strtoupper($cfg['paper_size'] ?? 'A4') }} (210 &times; 297 mm)</strong></span>
            <span class="text-slate-300">|</span>
            <span class="text-slate-500">Tips: Klik ganda area atas/bawah kertas untuk mengedit Header atau memperbesar Tabel Kotak Paraf.</span>
        </div>

        <div class="flex items-center gap-2">
            <label for="statusBarZoomSelect" class="font-semibold text-slate-600">Zoom:</label>
            <select id="statusBarZoomSelect" class="h-7 rounded border border-slate-300 bg-white px-2 py-0.5 text-xs text-slate-700 focus:border-blue-500 focus:outline-none">
                <option value="75">75%</option>
                <option value="90">90%</option>
                <option value="100" selected>100%</option>
                <option value="115">115%</option>
                <option value="125">125%</option>
            </select>
        </div>
    </div>
</div>

<!-- Template Data Store for Sample, Default Paraf, Saved Templates & Initial Editor JSON -->
<script type="application/json" id="mouSampleHtmlJson">{!! json_encode($sampleWordHtml) !!}</script>
<script type="application/json" id="mouDefaultParafHtmlJson">{!! json_encode($defaultParafHtml) !!}</script>
<script type="application/json" id="mouSavedTemplatesPayloadJson">{!! json_encode($savedTemplates) !!}</script>
<script type="application/json" id="mouInitialEditorJsonStore">{!! json_encode($initialEditorJson) !!}</script>

<script>
(function () {
    let initialized = false;

    function showDiagnosticError(msg) {
        const banner = document.getElementById('mouEditorDiagnosticBanner');
        const detail = document.getElementById('mouEditorDiagnosticMessage');
        if (banner) {
            banner.classList.remove('hidden');
            banner.classList.add('flex');
        }
        if (detail && msg) {
            detail.textContent = msg;
        }
    }

    // Bind Ribbon Tab Switching, Mail Merge Drawer & Token Search immediately
    function bindStaticRibbonShell() {
        const tabBtns = document.querySelectorAll('[data-ribbon-tab]');
        const tabPanels = document.querySelectorAll('[data-ribbon-panel]');
        tabBtns.forEach(btn => {
            if (btn.dataset.boundTab === '1') return;
            btn.dataset.boundTab = '1';
            btn.addEventListener('click', () => {
                const target = btn.getAttribute('data-ribbon-tab');
                tabBtns.forEach(b => b.classList.toggle('active', b === btn));
                tabPanels.forEach(p => {
                    const isMatch = p.getAttribute('data-ribbon-panel') === target;
                    p.classList.toggle('hidden', !isMatch);
                    p.classList.toggle('flex', isMatch);
                });
            });
        });

        const drawerBtn = document.getElementById('btnTogglePlaceholderDrawer');
        const sidebar = document.getElementById('mouMailMergeSidebar');
        const closeSidebarBtn = document.getElementById('btnCloseMailMergeSidebar');
        const searchInput = document.getElementById('mouSidebarTokenSearch');

        if (drawerBtn && sidebar && drawerBtn.dataset.boundDrawer !== '1') {
            drawerBtn.dataset.boundDrawer = '1';
            drawerBtn.addEventListener('click', () => {
                const isHidden = sidebar.classList.contains('hidden');
                sidebar.classList.toggle('hidden', !isHidden);
                drawerBtn.classList.toggle('bg-blue-100', isHidden);
                drawerBtn.classList.toggle('border-blue-400', isHidden);
            });
        }
        if (closeSidebarBtn && sidebar && closeSidebarBtn.dataset.boundClose !== '1') {
            closeSidebarBtn.dataset.boundClose = '1';
            closeSidebarBtn.addEventListener('click', () => {
                sidebar.classList.add('hidden');
                if (drawerBtn) {
                    drawerBtn.classList.remove('bg-blue-100', 'border-blue-400');
                }
            });
        }

        if (searchInput && searchInput.dataset.boundSearch !== '1') {
            searchInput.dataset.boundSearch = '1';
            searchInput.addEventListener('input', (e) => {
                const query = (e.target.value || '').trim().toLowerCase();
                const cards = document.querySelectorAll('.mou-token-category-card');
                cards.forEach(card => {
                    let visibleCount = 0;
                    const items = card.querySelectorAll('.mou-token-item');
                    items.forEach(item => {
                        const searchData = item.getAttribute('data-token-search') || '';
                        const match = !query || searchData.includes(query);
                        item.style.display = match ? 'flex' : 'none';
                        if (match) visibleCount++;
                    });
                    card.style.display = visibleCount > 0 ? 'block' : 'none';
                });
            });
        }

        document.querySelectorAll('.mou-token-category-toggle').forEach(btn => {
            if (btn.dataset.boundToggle === '1') return;
            btn.dataset.boundToggle = '1';
            btn.addEventListener('click', () => {
                const body = btn.nextElementSibling;
                const chevron = btn.querySelector('svg');
                if (body) {
                    body.classList.toggle('hidden');
                    if (chevron) {
                        chevron.classList.toggle('rotate-180', !body.classList.contains('hidden'));
                    }
                }
            });
        });
    }

    function bootMouStudio() {
        bindStaticRibbonShell();
        if (initialized) return;

        const bodyMountEl = document.getElementById('mouWordCanvas');
        const headerMountEl = document.getElementById('mouHeaderCanvas');
        const footerMountEl = document.getElementById('mouFooterCanvas');

        if (!bodyMountEl || typeof window.initMouWordProcessor !== 'function') {
            return;
        }
        initialized = true;

        let sampleWordHtml = '';
        let defaultParafHtml = '';
        let savedTemplatesList = [];
        let initialEditorJson = null;

        try {
            sampleWordHtml = JSON.parse(document.getElementById('mouSampleHtmlJson')?.textContent || '""');
            defaultParafHtml = JSON.parse(document.getElementById('mouDefaultParafHtmlJson')?.textContent || '""');
            savedTemplatesList = JSON.parse(document.getElementById('mouSavedTemplatesPayloadJson')?.textContent || '[]');
            initialEditorJson = JSON.parse(document.getElementById('mouInitialEditorJsonStore')?.textContent || 'null');
        } catch (parseErr) {
            console.warn('MouWordProcessor JSON store parse warning:', parseErr);
        }

        let processor = null;
        try {
            processor = window.initMouWordProcessor({
                bodyMountEl,
                headerMountEl,
                footerMountEl,
                initialBodyHtml: document.getElementById('inputTemplateHtml')?.value || sampleWordHtml || '<p></p>',
                initialEditorJson: initialEditorJson,
                initialHeaderHtml: document.getElementById('inputHeaderHtml')?.value || '',
                initialFooterHtml: document.getElementById('inputFooterHtml')?.value || defaultParafHtml,
                initialFirstPageHeaderHtml: document.getElementById('inputFirstPageHeaderHtml')?.value || '',
                initialFirstPageFooterHtml: document.getElementById('inputFirstPageFooterHtml')?.value || defaultParafHtml,
                defaultSampleHtml: sampleWordHtml,
                defaultParafHtml: defaultParafHtml,
                pageConfig: @json($cfg),
                mediaAssets: @json($assets),
                uploadMediaUrl: '{{ route('admin.mou-templates.upload-media') }}',
                csrfToken: '{{ csrf_token() }}',
            });
        } catch (initErr) {
            console.error('MouWordProcessor initialization error:', initErr);
            showDiagnosticError('Gagal menginisialisasi Tiptap Word Processor: ' + (initErr?.message || String(initErr)));
            return;
        }

        // Mode Edit vs Mode Pratinjau Lembar A4
        const btnModeEdit = document.getElementById('btnModeEdit');
        const btnModePreview = document.getElementById('btnModePreview');
        const editorView = document.getElementById('mouEditorWorkspaceView');
        const previewView = document.getElementById('mouMultiSheetPreviewContainer');

        if (btnModeEdit && btnModePreview && editorView && previewView) {
            btnModeEdit.addEventListener('click', () => {
                editorView.classList.remove('hidden');
                previewView.classList.add('hidden');
                btnModeEdit.className = 'rounded-md bg-blue-600 px-2.5 py-1 text-[11px] font-semibold text-white';
                btnModePreview.className = 'rounded-md px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:text-slate-900';
            });
            btnModePreview.addEventListener('click', () => {
                processor.renderMultiSheetPreview();
                editorView.classList.add('hidden');
                previewView.classList.remove('hidden');
                btnModePreview.className = 'rounded-md bg-blue-600 px-2.5 py-1 text-[11px] font-semibold text-white';
                btnModeEdit.className = 'rounded-md px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:text-slate-900';
            });
        }

        // Blank Document, Sample MoU & Saved Template Loader
        document.getElementById('btnNewBlankDoc')?.addEventListener('click', () => {
            if (confirm('Kosongkan area dokumen utama untuk mulai menulis dari kertas kosong?')) {
                processor.newBlankDocument();
            }
        });
        document.getElementById('btnLoadSampleMou')?.addEventListener('click', () => {
            if (confirm('Muat contoh naskah MoU standar Seven Inc. ke dalam editor? Isi saat ini akan digantikan.')) {
                processor.loadSampleMouDocument();
            }
        });
        document.getElementById('btnLoadSelectedSavedTemplate')?.addEventListener('click', () => {
            const sel = document.getElementById('ribbonSavedTemplateSelect');
            const selectedId = parseInt(sel?.value || '0', 10);
            if (!selectedId) {
                alert('Pilih salah satu template MoU tersimpan terlebih dahulu dari daftar dropdown.');
                return;
            }
            const found = (savedTemplatesList || []).find(item => Number(item.id) === selectedId);
            if (!found) {
                alert('Data template tidak ditemukan.');
                return;
            }
            if (confirm(`Muat isi, header/footer, margin, dan nilai default dari "${found.template_name} (v${found.version_number})"? Isi editor saat ini akan digantikan.`)) {
                processor.loadExistingTemplateData(found);
            }
        });

        // Font & Paragraph Ribbon Controls
        document.getElementById('ribbonFontFamily')?.addEventListener('change', (e) => {
            processor.setFontFamily(e.target.value);
        });
        document.getElementById('ribbonFontSize')?.addEventListener('change', (e) => {
            processor.setFontSize(e.target.value);
        });
        document.getElementById('ribbonHeadingStyle')?.addEventListener('change', (e) => {
            processor.setBlockStyle(e.target.value);
        });
        document.getElementById('ribbonTextColor')?.addEventListener('input', (e) => {
            processor.setTextColor(e.target.value);
        });
        document.getElementById('ribbonHighlightColor')?.addEventListener('input', (e) => {
            processor.setHighlightColor(e.target.value);
        });
        document.getElementById('ribbonParagraphLineHeight')?.addEventListener('change', (e) => {
            processor.setLineHeight(e.target.value);
        });
        document.getElementById('ribbonOrderedListStyle')?.addEventListener('change', (e) => {
            processor.toggleOrderedList(e.target.value);
        });

        // Find & Replace
        document.getElementById('btnExecuteReplaceAll')?.addEventListener('click', () => {
            const findVal = document.getElementById('ribbonFindInput')?.value || '';
            const replaceVal = document.getElementById('ribbonReplaceInput')?.value || '';
            if (!findVal) return;
            processor.findAndReplace(findVal, replaceVal);
        });

        // Insert Custom Table
        document.getElementById('btnInsertCustomTable')?.addEventListener('click', () => {
            const r = parseInt(document.getElementById('insertTableRows')?.value || '3', 10);
            const c = parseInt(document.getElementById('insertTableCols')?.value || '2', 10);
            processor.insertTable(r, c, false);
        });

        // Insert Hyperlink & Special Symbol
        document.getElementById('btnInsertHyperlink')?.addEventListener('click', () => {
            const url = prompt('Masukkan URL Tautan (contoh: https://seveninc.co.id):', 'https://');
            if (url === null) return;
            processor.setLink(url.trim());
        });
        document.getElementById('ribbonQuickSymbolSelect')?.addEventListener('change', (e) => {
            if (e.target.value) {
                processor.insertSymbol(e.target.value);
                e.target.value = '';
            }
        });

        // Insert Placeholder from Ribbon Dropdown
        document.getElementById('btnInsertSelectedPlaceholder')?.addEventListener('click', () => {
            const sel = document.getElementById('ribbonQuickPlaceholderSelect');
            if (sel && sel.value) {
                processor.insertPlaceholder(sel.value);
                sel.value = '';
            }
        });

        // Media Upload & Logo Insert
        document.getElementById('inputUploadCustomImage')?.addEventListener('change', async (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            try {
                await processor.uploadImageFile(file, 'custom_image');
            } catch (err) {
                alert(err.message);
            }
            e.target.value = '';
        });
        document.getElementById('inputUploadInstitutionLogo')?.addEventListener('change', async (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            try {
                await processor.uploadImageFile(file, 'institution_logo');
            } catch (err) {
                alert(err.message);
            }
            e.target.value = '';
        });
        document.getElementById('btnInsertCompanyLogo')?.addEventListener('click', () => {
            const url = processor.state.mediaAssets.company_logo || processor.state.mediaAssets.company_logo_url || '/images/mou/seven-inc-logo.png';
            processor.insertImageFromUrl(url, 88, 'center');
        });
        document.getElementById('btnApplyImageSize')?.addEventListener('click', () => {
            const w = document.getElementById('ribbonImageWidthPx')?.value || '95';
            processor.updateSelectedImage(`${w}px`, null);
        });

        // Paper Size & Orientation Controls
        document.getElementById('layoutPaperSize')?.addEventListener('change', (e) => {
            processor.setPaperSize(e.target.value);
        });
        document.getElementById('layoutOrientation')?.addEventListener('change', (e) => {
            processor.setOrientation(e.target.value);
        });

        // Layout Margin Presets & Custom Inputs
        document.querySelectorAll('[data-margin-preset]').forEach(btn => {
            btn.addEventListener('click', () => {
                const parts = (btn.getAttribute('data-margin-preset') || '').split(',').map(Number);
                if (parts.length === 4) {
                    processor.state.marginTopMm = parts[0];
                    processor.state.marginRightMm = parts[1];
                    processor.state.marginBottomMm = parts[2];
                    processor.state.marginLeftMm = parts[3];
                    document.getElementById('layoutMarginTop').value = parts[0];
                    document.getElementById('layoutMarginRight').value = parts[1];
                    document.getElementById('layoutMarginBottom').value = parts[2];
                    document.getElementById('layoutMarginLeft').value = parts[3];
                    processor.applyPageLayoutToCanvas();
                }
            });
        });

        ['layoutMarginTop', 'layoutMarginBottom', 'layoutMarginLeft', 'layoutMarginRight'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () => {
                processor.state.marginTopMm = parseFloat(document.getElementById('layoutMarginTop')?.value || '25.4') || 25.4;
                processor.state.marginBottomMm = parseFloat(document.getElementById('layoutMarginBottom')?.value || '25.4') || 25.4;
                processor.state.marginLeftMm = parseFloat(document.getElementById('layoutMarginLeft')?.value || '25.4') || 25.4;
                processor.state.marginRightMm = parseFloat(document.getElementById('layoutMarginRight')?.value || '25.4') || 25.4;
                processor.applyPageLayoutToCanvas();
            });
        });

        document.getElementById('btnApplyParagraphLayout')?.addEventListener('click', () => {
            const indent = document.getElementById('layoutFirstLineIndent')?.value || '0';
            const before = document.getElementById('layoutSpaceBefore')?.value || '0';
            const after = document.getElementById('layoutSpaceAfter')?.value || '6';
            processor.setFirstLineIndent(indent);
            processor.setParagraphSpacing(before, after);
        });

        // Table Resize & Styling Controls
        document.getElementById('btnApplyTableWidth')?.addEventListener('click', () => {
            const val = document.getElementById('ribbonTableWidthInput')?.value || '100%';
            processor.setCurrentTableWidth(val.trim());
        });
        document.getElementById('btnApplyColWidth')?.addEventListener('click', () => {
            const val = document.getElementById('ribbonColWidthInput')?.value || '';
            if (val) processor.setCurrentColumnWidth(val);
        });
        document.getElementById('btnApplyRowHeight')?.addEventListener('click', () => {
            let val = (document.getElementById('ribbonRowHeightInput')?.value || '').trim();
            if (val && /^\d+(\.\d+)?$/.test(val)) val = `${val}pt`;
            processor.setCurrentRowHeight(val);
        });
        document.getElementById('ribbonCellPaddingSelect')?.addEventListener('change', (e) => {
            if (e.target.value) {
                processor.updateActiveTableCells({ padding: e.target.value }, false);
                e.target.value = '';
            }
        });
        document.getElementById('ribbonCellValignSelect')?.addEventListener('change', (e) => {
            if (e.target.value) {
                processor.updateActiveTableCells({ verticalAlign: e.target.value }, false);
            }
        });
        document.getElementById('ribbonCellBgColor')?.addEventListener('input', (e) => {
            processor.updateActiveTableCells({ backgroundColor: e.target.value }, false);
        });

        // Header & Footer Controls
        document.getElementById('chkDifferentFirstPage')?.addEventListener('change', (e) => {
            processor.state.differentFirstPage = e.target.checked;
            processor.syncAllToHiddenInputs();
        });
        document.getElementById('selectHeaderFooterVariant')?.addEventListener('change', (e) => {
            processor.setHeaderFooterVariant(e.target.value === 'first');
        });
        document.getElementById('chkShowParafBox')?.addEventListener('change', (e) => {
            processor.state.showParafBox = e.target.checked;
            processor.applyPageLayoutToCanvas();
        });
        document.getElementById('chkShowPageNumber')?.addEventListener('change', (e) => {
            processor.state.showPageNumber = e.target.checked;
            processor.applyPageLayoutToCanvas();
        });
        document.getElementById('inputRibbonHeaderDist')?.addEventListener('input', (e) => {
            processor.state.headerDistanceMm = parseFloat(e.target.value || '12') || 12;
            processor.syncAllToHiddenInputs();
        });
        document.getElementById('inputRibbonFooterDist')?.addEventListener('input', (e) => {
            processor.state.footerDistanceMm = parseFloat(e.target.value || '10') || 10;
            processor.syncAllToHiddenInputs();
        });

        // Zoom Control
        document.getElementById('statusBarZoomSelect')?.addEventListener('change', (e) => {
            const z = parseInt(e.target.value || '100', 10) || 100;
            processor.state.zoomPercent = z;
            const stage = document.getElementById('mouPagesDeskStage') || document.getElementById('mouWordCanvas');
            if (stage) {
                stage.style.transform = z === 100 ? 'none' : `scale(${z / 100})`;
                stage.style.transformOrigin = 'top center';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootMouStudio);
    } else {
        bootMouStudio();
    }
    window.addEventListener('mou-word-processor:ready', bootMouStudio);

    // Watchdog: if after 2.5 seconds after load, Tiptap still hasn't mounted .ProseMirror, display diagnostic banner
    window.addEventListener('load', () => {
        setTimeout(() => {
            if (!initialized || !document.querySelector('#mouWordCanvas .ProseMirror')) {
                showDiagnosticError(
                    typeof window.initMouWordProcessor !== 'function'
                        ? 'Fungsi window.initMouWordProcessor tidak ditemukan di bundle JavaScript. Pastikan npm run build telah dijalankan dan file public/hot tidak menghalangi pemuatan aset.'
                        : 'Instance editor Tiptap (.ProseMirror) belum terbentuk pada #mouWordCanvas.'
                );
            }
        }, 2500);
    });
})();
</script>

