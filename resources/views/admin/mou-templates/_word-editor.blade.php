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

    /* Collapsible Mail Merge Drawer Sidebar & Dedicated Scrollbar */
    .mou-mail-merge-sidebar {
        width: 340px;
        min-width: 340px;
        max-width: 360px;
        height: 100%;
        max-height: 100%;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border-left: 1px solid #cbd5e1;
        box-shadow: -4px 0 16px rgba(15, 23, 42, 0.08);
        position: relative;
        z-index: 30;
        overflow: hidden;
    }
    #mouSidebarTokenCategories {
        flex: 1 1 0%;
        min-height: 0;
        overflow-y: auto !important;
        overflow-x: hidden;
        scrollbar-width: thin;
        scrollbar-color: #94a3b8 #f1f5f9;
        -webkit-overflow-scrolling: touch;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar {
        width: 6px;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar-track {
        background: #f1f5f9;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar-thumb {
        background: #94a3b8;
        border-radius: 999px;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar-thumb:hover {
        background: #64748b;
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
        border-color: #2b579a !important;
        background: rgba(235, 243, 253, 0.45) !important;
        box-shadow: 0 0 0 1px #2b579a;
    }
    .mou-zone-dimmed {
        opacity: 0.35 !important;
        filter: grayscale(20%);
        pointer-events: none !important;
        user-select: none !important;
        transition: opacity 0.15s ease;
    }
    .mou-zone-tag {
        position: absolute;
        top: -18px;
        left: 4px;
        font-size: 9px;
        font-family: 'Segoe UI', ui-sans-serif, system-ui, sans-serif;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 2px 8px;
        border-radius: 2px 2px 0 0;
        background: #2b579a;
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
        border-bottom: 1.5px dashed #cbd5e1;
        transition: border-color 0.15s ease, background-color 0.15s ease;
    }
    .mou-a4-page-header:hover {
        border-bottom-color: #3b82f6;
        background-color: rgba(239, 246, 255, 0.45);
    }
    .mou-a4-page-header::after {
        content: "Header Dokumen (Klik untuk edit)";
        position: absolute;
        right: 0;
        bottom: 1px;
        font-size: 8.5px;
        color: #94a3b8;
        font-family: ui-sans-serif, system-ui, sans-serif;
        font-style: italic;
        pointer-events: none;
    }
    .mou-a4-page-footer {
        position: relative;
        cursor: pointer;
        user-select: none;
        flex-shrink: 0;
        min-height: 14mm;
        border-top: 1.5px dashed #cbd5e1;
        transition: border-color 0.15s ease, background-color 0.15s ease;
    }
    .mou-a4-page-footer:hover {
        border-top-color: #3b82f6;
        background-color: rgba(239, 246, 255, 0.45);
    }
    .mou-a4-page-footer::after {
        content: "Footer / Kotak Paraf (Klik untuk edit)";
        position: absolute;
        right: 0;
        top: 1px;
        font-size: 8.5px;
        color: #94a3b8;
        font-family: ui-sans-serif, system-ui, sans-serif;
        font-style: italic;
        pointer-events: none;
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

    /* Word 2013 Mailings - Highlight Merge Fields */
    .mou-merge-field-highlight {
        background-color: #fef08a !important;
        color: #854d0e !important;
        border: 1px dashed #ca8a04 !important;
        border-radius: 3px !important;
        padding: 0 3px !important;
        font-weight: 600 !important;
        box-shadow: 0 1px 2px rgba(180, 83, 9, 0.15) !important;
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
        <!-- Clean Title Bar -->
        <div class="flex items-center justify-between bg-[#2b579a] text-white px-3 py-1.5 text-xs select-none shadow-xs">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-white text-[#2b579a] font-black text-xs shadow-xs">W</span>
                <span class="text-white font-semibold text-[12px]">{{ old('name', $template->name ?? 'Editor Template Dokumen MoU') }}</span>
                <span class="text-blue-200 text-[10px] hidden sm:inline">(A4 Print Layout)</span>
            </div>

            <div class="flex items-center gap-2">
                <span id="activeEditorZoneBadge" class="inline-flex items-center gap-1.5 rounded-full bg-white/20 border border-white/30 px-2.5 py-0.5 text-[11px] font-semibold text-white">
                    Mode Edit: Isi Dokumen Utama
                </span>

                <button
                    type="button"
                    id="btnCloseHeaderFooter"
                    onclick="window.MouWordProcessor?.setActiveZone('body')"
                    class="hidden rounded bg-amber-400 hover:bg-amber-500 px-2.5 py-0.5 text-[11px] font-bold text-slate-900 shadow-xs transition"
                    title="Selesai Edit Header / Footer (Esc)"
                >
                    &check; Selesai Edit Header/Footer
                </button>
            </div>
        </div>

        <!-- SINGLE DYNAMIC TOOLBAR (Clean, Compact, Responsive) -->
        <div class="border-b border-slate-200 bg-white px-2.5 py-1.5 flex flex-wrap items-center justify-between gap-y-1.5 gap-x-2">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                <!-- Grup 1: Undo & Redo -->
                <div class="flex items-center gap-0.5 pr-2 border-r border-slate-200">
                    <button type="button" id="btnRibbonUndo" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.undo()" class="mou-ribbon-btn p-1.5 w-8 h-[32px]" title="Urungkan (Undo - Ctrl+Z)">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                    </button>
                    <button type="button" id="btnRibbonRedo" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.redo()" class="mou-ribbon-btn p-1.5 w-8 h-[32px]" title="Ulangi (Redo - Ctrl+Y)">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/></svg>
                    </button>
                </div>

                <!-- Grup 2: Dokumen & Template -->
                <div class="flex items-center gap-1.5 pr-2 border-r border-slate-200">
                    <button type="button" id="btnNewBlankDoc" class="mou-ribbon-btn border-slate-300 bg-slate-50 text-slate-700 text-xs px-2" title="Kosongkan halaman untuk menulis dari awal">
                        Halaman Kosong
                    </button>
                    <button type="button" id="btnLoadSampleMou" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700 font-medium text-xs px-2" title="Muat contoh template MoU standar Seven Inc">
                        Contoh MoU
                    </button>
                    <select id="ribbonSavedTemplateSelect" class="mou-ribbon-select max-w-[190px] text-xs" title="Pilih template MoU yang pernah disimpan">
                        <option value="">-- Muat Template Tersimpan --</option>
                        @foreach($savedTemplates as $tplItem)
                            <option value="{{ $tplItem['id'] }}">
                                {{ $tplItem['name'] }} (v{{ $tplItem['version_number'] }}){{ !empty($tplItem['is_active']) ? ' — Aktif' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <button type="button" id="btnLoadSelectedSavedTemplate" class="mou-ribbon-btn border-emerald-300 bg-emerald-50 text-emerald-800 font-semibold text-xs px-2" title="Muat template yang dipilih">
                        Muat
                    </button>
                </div>

                <!-- Grup 3: Font & Gaya Huruf -->
                <div class="flex items-center gap-1 pr-2 border-r border-slate-200">
                    <select id="ribbonFontFamily" class="mou-ribbon-select min-w-[130px] text-xs" title="Jenis Huruf">
                        <option value="Times New Roman">Times New Roman</option>
                        <option value="Arial">Arial</option>
                        <option value="Calibri">Calibri</option>
                        <option value="Georgia">Georgia</option>
                        <option value="Cambria">Cambria</option>
                        <option value="Courier New">Courier New</option>
                    </select>

                    <select id="ribbonFontSize" class="mou-ribbon-select w-16 text-xs" title="Ukuran Font (pt)">
                        @foreach([8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36] as $pt)
                            <option value="{{ $pt }}pt" {{ (int)($cfg['font_size_pt'] ?? 12) === $pt ? 'selected' : '' }}>{{ $pt }} pt</option>
                        @endforeach
                    </select>

                    <select id="ribbonHeadingStyle" class="mou-ribbon-select min-w-[125px] text-xs" title="Gaya Teks / Paragraf">
                        <option value="p">Paragraf Normal</option>
                        <option value="h1">Judul 1 (Heading 1)</option>
                        <option value="h2">Judul 2 (Heading 2)</option>
                        <option value="h3">Judul 3 (Heading 3)</option>
                        <option value="h4">Subjudul (Heading 4)</option>
                    </select>

                    <div class="flex items-center gap-0.5">
                        <button type="button" data-tiptap-mark="bold" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleBold()" class="mou-ribbon-btn font-bold w-6 text-xs" title="Tebal (Ctrl+B)">B</button>
                        <button type="button" data-tiptap-mark="italic" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleItalic()" class="mou-ribbon-btn italic w-6 font-serif text-xs" title="Miring (Ctrl+I)">I</button>
                        <button type="button" data-tiptap-mark="underline" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleUnderline()" class="mou-ribbon-btn underline w-6 text-xs" title="Garis Bawah (Ctrl+U)">U</button>
                        <button type="button" data-tiptap-mark="strike" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleStrike()" class="mou-ribbon-btn line-through w-6 text-xs" title="Coret">S</button>
                        <button type="button" data-tiptap-mark="subscript" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleSubscript()" class="mou-ribbon-btn w-7 text-[10px]" title="Subscript">X<sub>2</sub></button>
                        <button type="button" data-tiptap-mark="superscript" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleSuperscript()" class="mou-ribbon-btn w-7 text-[10px]" title="Superscript">X<sup>2</sup></button>
                        <button type="button" id="btnToggleUppercase" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleUppercaseSelection()" class="mou-ribbon-btn font-bold px-1 text-xs" title="Kapitalisasi (UPPERCASE/lowercase)">AA</button>

                        <label class="inline-flex items-center gap-0.5 text-xs text-slate-700 ml-0.5 cursor-pointer" title="Warna Teks">
                            <span class="font-bold underline text-[11px]">A</span>
                            <input type="color" id="ribbonTextColor" value="#000000" class="h-5 w-5 cursor-pointer rounded border border-slate-300 p-0">
                        </label>
                        <label class="inline-flex items-center gap-0.5 text-xs text-slate-700 ml-0.5 cursor-pointer" title="Warna Sorotan (Highlight)">
                            <span class="bg-yellow-200 px-0.5 rounded font-semibold text-[10px]">ab</span>
                            <input type="color" id="ribbonHighlightColor" value="#fef08a" class="h-5 w-5 cursor-pointer rounded border border-slate-300 p-0">
                        </label>
                        <button type="button" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.setHighlightColor('transparent')" class="mou-ribbon-btn text-[10px] text-slate-500 px-1" title="Hapus Highlight">No HL</button>
                    </div>
                </div>

                <!-- Grup 4: Paragraf & Daftar -->
                <div class="flex items-center gap-0.5 pr-2 border-r border-slate-200">
                    <button type="button" data-tiptap-align="left" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.setTextAlign('left')" class="mou-ribbon-btn p-1" title="Rata Kiri">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg>
                    </button>
                    <button type="button" data-tiptap-align="center" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.setTextAlign('center')" class="mou-ribbon-btn p-1" title="Rata Tengah">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="10" x2="6" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="18" y1="18" x2="6" y2="18"/></svg>
                    </button>
                    <button type="button" data-tiptap-align="right" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.setTextAlign('right')" class="mou-ribbon-btn p-1" title="Rata Kanan">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="7" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="7" y2="18"/></svg>
                    </button>
                    <button type="button" data-tiptap-align="justify" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.setTextAlign('justify')" class="mou-ribbon-btn p-1" title="Rata Justify">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="3" y2="18"/></svg>
                    </button>

                    <select id="ribbonParagraphLineHeight" class="mou-ribbon-select min-w-[90px] text-xs" title="Spasi Baris Paragraf">
                        <option value="1">Spasi 1.0</option>
                        <option value="1.15" selected>Spasi 1.15</option>
                        <option value="1.5">Spasi 1.5</option>
                        <option value="2">Spasi 2.0</option>
                    </select>

                    <button type="button" id="btnToggleBulletList" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleBulletList()" class="mou-ribbon-btn px-1.5 text-xs" title="Daftar Poin">
                        &bull; Poin
                    </button>
                    <button type="button" id="btnToggleOrderedList" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.toggleOrderedList()" class="mou-ribbon-btn px-1.5 text-xs" title="Daftar Bernomor">
                        1. Nomor
                    </button>
                    <select id="ribbonOrderedListStyle" class="mou-ribbon-select min-w-[70px] text-xs" title="Format Penomoran">
                        <option value="1">1, 2, 3</option>
                        <option value="a">a, b, c</option>
                        <option value="A">A, B, C</option>
                        <option value="i">i, ii, iii</option>
                    </select>
                    <button type="button" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.adjustIndent(-6)" class="mou-ribbon-btn px-1 text-xs" title="Kurangi Indentasi">&larr; Indent</button>
                    <button type="button" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.adjustIndent(6)" class="mou-ribbon-btn px-1 text-xs" title="Tambah Indentasi">Indent &rarr;</button>
                </div>

                <!-- Grup 5: Sisipkan (Tautan, Simbol & Tabel) -->
                <div class="flex items-center gap-1.5 pr-2 border-r border-slate-200 relative">
                    <button type="button" id="btnInsertPageBreak" onmousedown="event.preventDefault()" onclick="window.MouWordProcessor?.insertPageBreak()" class="mou-ribbon-btn border-slate-200 bg-slate-50 text-xs px-1.5" title="Sisipkan Halaman Baru (Ctrl+Enter)">+ Hal Baru</button>
                    <button type="button" id="btnInsertHyperlink" class="mou-ribbon-btn border-slate-200 bg-slate-50 text-xs px-1.5" title="Sisipkan Hyperlink">+ Tautan</button>
                    <select id="ribbonQuickSymbolSelect" class="mou-ribbon-select min-w-[85px] text-xs" title="Sisipkan Simbol">
                        <option value="">Simbol...</option>
                        <option value="§">§ (Pasal)</option>
                        <option value="•">• (Bullet)</option>
                        <option value="–">– (En Dash)</option>
                        <option value="—">— (Em Dash)</option>
                        <option value="©">© (Copyright)</option>
                        <option value="®">® (Registered)</option>
                        <option value="±">± (Kurang Lebih)</option>
                        <option value="°">° (Derajat)</option>
                    </select>

                    <!-- Menu Dropdown Tabel Kontekstual -->
                    <div class="relative inline-block text-left">
                        <button type="button" id="btnTableContextMenuToggle" class="mou-ribbon-btn inline-flex items-center gap-1 border-slate-200 bg-slate-50 text-slate-700 font-semibold px-2 text-xs" title="Menu Pengelolaan Tabel">
                            <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
                            <span>Tabel</span>
                            <svg class="h-3 w-3 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                        <div id="tableContextMenuDropdown" class="hidden absolute left-0 mt-1 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 divide-y divide-slate-100 p-1 text-xs">
                            <div class="p-1">
                                <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase">Sisipkan Tabel Baru</span>
                                <button type="button" onclick="window.MouWordProcessor?.insertTable(2, 2, false)" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">Tabel 2 &times; 2</button>
                                <button type="button" onclick="window.MouWordProcessor?.insertTable(3, 3, false)" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">Tabel 3 &times; 3</button>
                                <button type="button" onclick="window.MouWordProcessor?.insertTable(4, 2, false)" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">Tabel Tanda Tangan (4 &times; 2)</button>
                            </div>
                            <div class="p-1">
                                <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase">Baris & Kolom</span>
                                <button type="button" onclick="window.MouWordProcessor?.addRowBefore()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Baris Di Atas</button>
                                <button type="button" onclick="window.MouWordProcessor?.addRowAfter()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Baris Di Bawah</button>
                                <button type="button" onclick="window.MouWordProcessor?.addColumnBefore()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Kolom Di Kiri</button>
                                <button type="button" onclick="window.MouWordProcessor?.addColumnAfter()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Kolom Di Kanan</button>
                            </div>
                            <div class="p-1">
                                <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase">Manipulasi Sel & Hapus</span>
                                <button type="button" onclick="window.MouWordProcessor?.mergeCells()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">Gabung Sel (Merge)</button>
                                <button type="button" onclick="window.MouWordProcessor?.splitCell()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">Pisah Sel (Split)</button>
                                <button type="button" onclick="window.MouWordProcessor?.deleteRow()" class="w-full text-left px-2 py-1 hover:bg-rose-50 text-rose-600 rounded">Hapus Baris</button>
                                <button type="button" onclick="window.MouWordProcessor?.deleteColumn()" class="w-full text-left px-2 py-1 hover:bg-rose-50 text-rose-600 rounded">Hapus Kolom</button>
                                <button type="button" onclick="window.MouWordProcessor?.deleteTable()" class="w-full text-left px-2 py-1 hover:bg-rose-100 text-rose-700 rounded font-bold">Hapus Seluruh Tabel</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grup 6: Popover Ukuran Kertas & Margin -->
                <div class="relative inline-block text-left pr-2 border-r border-slate-200">
                    <button type="button" id="btnOpenLayoutPopover" class="mou-ribbon-btn inline-flex items-center gap-1.5 border-slate-200 bg-slate-50 text-slate-700 font-semibold px-2 text-xs" title="Pengaturan Ukuran Kertas & Margin">
                        <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h7"/></svg>
                        <span>Ukuran &amp; Margin</span>
                    </button>
                    <div id="popoverLayoutDropdown" class="hidden absolute left-0 mt-1 w-80 rounded-lg shadow-xl bg-white border border-slate-200 p-3 z-50 text-xs">
                        <div class="font-bold text-slate-800 pb-1.5 mb-2 border-b border-slate-100 flex items-center justify-between">
                            <span>Tata Letak Kertas &amp; Margin</span>
                            <span class="text-[10px] text-slate-400">A4 Standar</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 mb-2">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Ukuran Kertas</label>
                                <select id="layoutPaperSize" class="mou-ribbon-select w-full text-xs">
                                    <option value="A4" {{ strtoupper($cfg['paper_size'] ?? 'A4') === 'A4' ? 'selected' : '' }}>A4 (210 &times; 297 mm)</option>
                                    <option value="LETTER" {{ strtoupper($cfg['paper_size'] ?? 'A4') === 'LETTER' ? 'selected' : '' }}>Letter</option>
                                    <option value="LEGAL" {{ strtoupper($cfg['paper_size'] ?? 'A4') === 'LEGAL' ? 'selected' : '' }}>Legal</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Orientasi</label>
                                <select id="layoutOrientation" class="mou-ribbon-select w-full text-xs">
                                    <option value="portrait" {{ strtolower($cfg['orientation'] ?? 'portrait') === 'portrait' ? 'selected' : '' }}>Portrait (Tegak)</option>
                                    <option value="landscape" {{ strtolower($cfg['orientation'] ?? 'portrait') === 'landscape' ? 'selected' : '' }}>Landscape (Mendatar)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1">Preset Margin</label>
                            <div class="flex items-center gap-1">
                                <button type="button" data-margin-preset="25.4,25.4,25.4,25.4" class="px-2 py-0.5 text-[10px] rounded bg-slate-100 hover:bg-slate-200 border border-slate-300">Normal (25.4mm)</button>
                                <button type="button" data-margin-preset="19.1,19.1,19.1,19.1" class="px-2 py-0.5 text-[10px] rounded bg-slate-100 hover:bg-slate-200 border border-slate-300">Sedang (19.1mm)</button>
                                <button type="button" data-margin-preset="12.7,12.7,12.7,12.7" class="px-2 py-0.5 text-[10px] rounded bg-slate-100 hover:bg-slate-200 border border-slate-300">Sempit (12.7mm)</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-4 gap-1.5 mb-2">
                            <div>
                                <label class="block text-[9px] font-semibold text-slate-500">Atas (mm)</label>
                                <input type="number" step="0.5" id="layoutMarginTop" value="{{ $cfg['margin_top_mm'] ?? 25.4 }}" class="w-full h-7 rounded border border-slate-300 text-center text-xs">
                            </div>
                            <div>
                                <label class="block text-[9px] font-semibold text-slate-500">Bawah (mm)</label>
                                <input type="number" step="0.5" id="layoutMarginBottom" value="{{ $cfg['margin_bottom_mm'] ?? 25.4 }}" class="w-full h-7 rounded border border-slate-300 text-center text-xs">
                            </div>
                            <div>
                                <label class="block text-[9px] font-semibold text-slate-500">Kiri (mm)</label>
                                <input type="number" step="0.5" id="layoutMarginLeft" value="{{ $cfg['margin_left_mm'] ?? 25.4 }}" class="w-full h-7 rounded border border-slate-300 text-center text-xs">
                            </div>
                            <div>
                                <label class="block text-[9px] font-semibold text-slate-500">Kanan (mm)</label>
                                <input type="number" step="0.5" id="layoutMarginRight" value="{{ $cfg['margin_right_mm'] ?? 25.4 }}" class="w-full h-7 rounded border border-slate-300 text-center text-xs">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 pt-1 border-t border-slate-100">
                            <div>
                                <label class="block text-[9px] font-semibold text-slate-500">Jarak Header (mm)</label>
                                <input type="number" step="0.5" id="inputRibbonHeaderDist" value="{{ $cfg['header_distance_mm'] ?? 12.0 }}" class="w-full h-7 rounded border border-slate-300 text-center text-xs">
                            </div>
                            <div>
                                <label class="block text-[9px] font-semibold text-slate-500">Jarak Footer (mm)</label>
                                <input type="number" step="0.5" id="inputRibbonFooterDist" value="{{ $cfg['footer_distance_mm'] ?? 10.0 }}" class="w-full h-7 rounded border border-slate-300 text-center text-xs">
                            </div>
                        </div>
                        <div class="pt-2 border-t border-slate-100 space-y-1 mt-2">
                            <label class="flex items-center gap-1.5 text-[10px] text-slate-700 cursor-pointer">
                                <input type="checkbox" id="chkDifferentFirstPage" {{ !empty($cfg['different_first_page']) ? 'checked' : '' }} class="rounded border-slate-300 text-blue-600">
                                <span>Beda Halaman Pertama (First Page)</span>
                            </label>
                            <label class="flex items-center gap-1.5 text-[10px] text-slate-700 cursor-pointer">
                                <input type="checkbox" id="chkShowParafBox" {{ ($cfg['show_paraf_box'] ?? true) ? 'checked' : '' }} class="rounded border-slate-300 text-blue-600">
                                <span>Tampilkan Kotak Paraf</span>
                            </label>
                            <label class="flex items-center gap-1.5 text-[10px] text-slate-700 cursor-pointer">
                                <input type="checkbox" id="chkShowPageNumber" {{ ($cfg['show_page_number'] ?? true) ? 'checked' : '' }} class="rounded border-slate-300 text-blue-600">
                                <span>Tampilkan Nomor Halaman</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Grup 7: Cari & Ganti -->
                <div class="flex items-center gap-1">
                    <input type="text" id="ribbonFindInput" placeholder="Cari teks..." class="h-[30px] w-24 rounded-md border border-slate-300 px-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    <input type="text" id="ribbonReplaceInput" placeholder="Ganti..." class="h-[30px] w-20 rounded-md border border-slate-300 px-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    <button type="button" id="btnExecuteReplaceAll" class="mou-ribbon-btn border-slate-200 bg-slate-50 text-xs px-2" title="Ganti Semua">
                        Ganti Semua
                    </button>
                </div>
            </div>

            <!-- Grup Kanan: Panel Variabel (Mail Merge) & Mode -->
            <div class="flex items-center gap-2 shrink-0">
                <button
                    type="button"
                    id="btnHighlightMergeFields"
                    onmousedown="event.preventDefault()"
                    class="mou-ribbon-btn border border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-800 font-semibold text-xs px-2"
                    title="Sorot Kolom Gabungan (Highlight Merge Fields)"
                >
                    <span class="inline-block w-2 h-2 rounded-full bg-amber-400 border border-amber-600"></span>
                    Sorot Variabel
                </button>

                <button
                    type="button"
                    id="btnLivePreviewToggle"
                    class="mou-ribbon-btn border border-indigo-300 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold text-xs px-2.5"
                    title="Pratinjau Draft Cetak A4 Langsung"
                >
                    <svg class="h-3.5 w-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    Pratinjau Draft
                </button>

                <button
                    type="button"
                    id="btnTogglePlaceholderDrawer"
                    class="inline-flex items-center gap-2 rounded-lg border-2 border-blue-400 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 text-xs font-bold text-blue-800 shadow-sm transition"
                    title="Buka / Tutup Panel Variabel Dinamis (Mail Merge)"
                >
                    <svg class="h-4 w-4 text-blue-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="M14 9l3 3-3 3"/>
                    </svg>
                    <span>Panel Variabel</span>
                    <span id="drawerToggleBadge" class="rounded bg-blue-600 px-1.5 py-0.2 text-[9px] font-bold text-white uppercase">Buka</span>
                </button>
                <button type="button" id="btnModeEdit" class="hidden"></button>
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
            <div id="mouPagesDeskStage" class="mou-pages-desk-stage flex flex-col items-center w-full relative">
                <div id="mouWordCanvas" class="w-full flex flex-col items-center"></div>

                <!-- Live Header/Footer In-Place Editor Overlay (Stable container: positioned over active sheet without DOM detach/append) -->
                <div id="mouHfActiveEditorOverlay" class="hidden absolute z-30 transition-all pointer-events-auto">
                    <!-- Header In-Place Editor -->
                    <div id="mouHeaderZoneWrapper" class="hidden mou-header-zone-wrapper mou-zone-active bg-white/98 backdrop-blur-xs rounded-lg shadow-xl border-2 border-blue-600 p-2.5" title="Header dokumen">
                        <div class="flex items-center justify-between pb-1.5 mb-1.5 border-b border-blue-200">
                            <span class="text-xs font-bold text-blue-900 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-blue-600 animate-pulse"></span>
                                Sedang Mengedit Header Dokumen
                            </span>
                            <button type="button" onclick="window.MouWordProcessor?.setActiveZone('body')" class="text-xs font-bold text-rose-600 hover:text-rose-800 px-2 py-0.5 rounded bg-rose-50 hover:bg-rose-100 transition border border-rose-200 shadow-2xs">
                                &times; Selesai / Tutup (Esc)
                            </button>
                        </div>
                        <div id="mouHeaderCanvas"></div>
                    </div>

                    <!-- Footer In-Place Editor -->
                    <div id="mouFooterZoneWrapper" class="hidden mou-footer-zone-wrapper mou-zone-active bg-white/98 backdrop-blur-xs rounded-lg shadow-xl border-2 border-amber-600 p-2.5" title="Footer dokumen &amp; Kotak Paraf">
                        <div class="flex items-center justify-between pb-1.5 mb-1.5 border-b border-amber-200 w-full">
                            <span class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-amber-600 animate-pulse"></span>
                                Sedang Mengedit Footer &amp; Kotak Paraf
                            </span>
                            <button type="button" onclick="window.MouWordProcessor?.setActiveZone('body')" class="text-xs font-bold text-rose-600 hover:text-rose-800 px-2 py-0.5 rounded bg-rose-50 hover:bg-rose-100 transition border border-rose-200 shadow-2xs">
                                &times; Selesai / Tutup (Esc)
                            </button>
                        </div>
                        <div id="mouFooterCanvas" class="flex-1 min-w-0"></div>
                        <div id="mouFooterPageNumberPreview" class="font-serif text-[9pt] text-black pb-1 shrink-0 select-none">
                            Halaman 1 dari 1
                        </div>
                    </div>
                </div>
            </div>
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
                                    $phMustache = '{{' . $phKey . '}}';
                                @endphp
                                <div class="mou-token-item flex items-center justify-between gap-1 p-1 rounded hover:bg-slate-50 border border-transparent hover:border-slate-200 transition" data-token-search="{{ strtolower($phKey . ' ' . $phDesc . ' ' . $phMustache) }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[11px] font-mono font-bold text-blue-700 truncate" title="{{ $phBracket }} atau {{ $phMustache }}">{{ $phBracket }} <span class="text-[9px] text-slate-400 font-normal">({{ $phMustache }})</span></div>
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

<!-- Modal Pratinjau Draft A4 Interaktif (Render Draft Terkini Tanpa Save DB) -->
<div id="mouLivePreviewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
    <div class="relative w-full max-w-5xl bg-slate-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[94vh]">
        <!-- Top Bar -->
        <div class="flex items-center justify-between px-5 py-3.5 bg-slate-900 border-b border-slate-700 text-white shrink-0">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-indigo-600 font-bold text-xs text-white shadow-xs">P</span>
                <div>
                    <h3 class="font-bold text-sm text-white">Pratinjau Draft Naskah MoU (A4 Layout)</h3>
                    <p class="text-[11px] text-slate-400">Variabel Mail Merge disubstitusi secara dinamis dengan data simulasi. Mencerminkan naskah aktif di editor secara real-time.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span id="livePreviewPageCountBadge" class="rounded bg-indigo-900/60 border border-indigo-500/40 px-2.5 py-1 text-xs font-semibold text-indigo-200">
                    1 Halaman
                </span>
                <button
                    type="button"
                    id="btnPrintLivePreviewModal"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 px-3 py-1.5 text-xs font-semibold text-white shadow-xs transition"
                    title="Cetak atau Simpan sebagai PDF melalui Browser"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Cetak / Simpan PDF
                </button>
                <button
                    type="button"
                    id="btnCloseLivePreviewModal"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white transition text-lg font-bold"
                    title="Tutup (Esc)"
                >
                    &times;
                </button>
            </div>
        </div>

        <!-- Scrollable Stage for A4 Sheets -->
        <div id="mouLivePreviewScrollBody" class="flex-1 overflow-y-auto p-6 bg-slate-900/60 flex flex-col items-center gap-8">
            <div id="mouLivePreviewSheetsContainer" class="flex flex-col items-center gap-8 w-full"></div>
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
            const badge = document.getElementById('drawerToggleBadge');
            const updateDrawerUI = () => {
                const isHidden = sidebar.classList.contains('hidden');
                if (badge) badge.textContent = isHidden ? 'Buka' : 'Tutup';
                drawerBtn.classList.toggle('bg-blue-100', !isHidden);
                drawerBtn.classList.toggle('border-blue-400', !isHidden);
            };
            drawerBtn.addEventListener('click', () => {
                sidebar.classList.toggle('hidden');
                updateDrawerUI();
            });
            if (closeSidebarBtn) {
                closeSidebarBtn.addEventListener('click', () => {
                    sidebar.classList.add('hidden');
                    updateDrawerUI();
                });
            }
            updateDrawerUI();
        }

        // Table Context Menu Dropdown
        const tableMenuBtn = document.getElementById('btnTableContextMenuToggle');
        const tableMenuDropdown = document.getElementById('tableContextMenuDropdown');
        if (tableMenuBtn && tableMenuDropdown && tableMenuBtn.dataset.boundMenu !== '1') {
            tableMenuBtn.dataset.boundMenu = '1';
            tableMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const willOpen = tableMenuDropdown.classList.contains('hidden');
                document.getElementById('popoverLayoutDropdown')?.classList.add('hidden');
                tableMenuDropdown.classList.toggle('hidden', !willOpen);
            });
            tableMenuDropdown.addEventListener('click', (e) => {
                if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                    tableMenuDropdown.classList.add('hidden');
                }
            });
        }

        // Popover Layout Dropdown (Ukuran & Margin)
        const layoutPopoverBtn = document.getElementById('btnOpenLayoutPopover');
        const layoutPopoverDropdown = document.getElementById('popoverLayoutDropdown');
        if (layoutPopoverBtn && layoutPopoverDropdown && layoutPopoverBtn.dataset.boundPopover !== '1') {
            layoutPopoverBtn.dataset.boundPopover = '1';
            layoutPopoverBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const willOpen = layoutPopoverDropdown.classList.contains('hidden');
                document.getElementById('tableContextMenuDropdown')?.classList.add('hidden');
                layoutPopoverDropdown.classList.toggle('hidden', !willOpen);
            });
        }

        // Close dropdowns on outside click
        document.addEventListener('click', (e) => {
            if (tableMenuDropdown && !tableMenuDropdown.classList.contains('hidden')) {
                if (!tableMenuDropdown.contains(e.target) && !tableMenuBtn?.contains(e.target)) {
                    tableMenuDropdown.classList.add('hidden');
                }
            }
            if (layoutPopoverDropdown && !layoutPopoverDropdown.classList.contains('hidden')) {
                if (!layoutPopoverDropdown.contains(e.target) && !layoutPopoverBtn?.contains(e.target)) {
                    layoutPopoverDropdown.classList.add('hidden');
                }
            }
        });

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

        // Insert Placeholder from Ribbon Mailings Dropdown
        document.getElementById('btnInsertMailingsPlaceholder')?.addEventListener('click', () => {
            const sel = document.getElementById('ribbonMailingsPlaceholderSelect');
            if (sel && sel.value) {
                processor.insertPlaceholder(sel.value);
                sel.value = '';
            }
        });

        // Highlight Merge Fields Toggle (Surat Masal)
        const btnHighlight = document.getElementById('btnHighlightMergeFields');
        if (btnHighlight) {
            btnHighlight.addEventListener('click', () => {
                const isActive = processor.toggleHighlightMergeFields();
                btnHighlight.classList.toggle('bg-amber-400', isActive);
                btnHighlight.classList.toggle('text-amber-950', isActive);
                btnHighlight.classList.toggle('border-amber-600', isActive);
                btnHighlight.classList.toggle('font-black', isActive);
                btnHighlight.classList.toggle('bg-amber-50', !isActive);
                btnHighlight.classList.toggle('text-amber-800', !isActive);
            });
        }

        // Live Preview Action Button (Render Active Memory Draft on A4 Modal)
        const btnLivePreview = document.getElementById('btnLivePreviewToggle');
        const liveModal = document.getElementById('mouLivePreviewModal');
        const liveContainer = document.getElementById('mouLivePreviewSheetsContainer');
        const liveBadge = document.getElementById('livePreviewPageCountBadge');
        const btnCloseModal = document.getElementById('btnCloseLivePreviewModal');
        const btnPrintModal = document.getElementById('btnPrintLivePreviewModal');

        function closeLivePreviewModal() {
            if (liveModal) liveModal.classList.add('hidden');
        }

        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', closeLivePreviewModal);
        }

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && liveModal && !liveModal.classList.contains('hidden')) {
                closeLivePreviewModal();
            }
        });

        if (btnLivePreview) {
            btnLivePreview.addEventListener('click', () => {
                if (!liveModal || !liveContainer) return;
                processor.syncAllToHiddenInputs();

                liveContainer.innerHTML = '';
                const pageDoms = document.querySelectorAll('#mouWordCanvas .mou-a4-paper-sheet');
                const totalPages = pageDoms.length || 1;
                if (liveBadge) liveBadge.textContent = `${totalPages} Halaman A4`;

                const differentFirst = document.getElementById('inputDifferentFirstPage')?.value === '1';
                const firstHeaderHtml = document.getElementById('inputFirstPageHeaderHtml')?.value || '';
                const firstFooterHtml = document.getElementById('inputFirstPageFooterHtml')?.value || '';
                const runningHeaderHtml = document.getElementById('inputHeaderHtml')?.value || '';
                const runningFooterHtml = document.getElementById('inputFooterHtml')?.value || '';
                const showParaf = document.getElementById('inputShowParafBox')?.value === '1';
                const showPageNum = document.getElementById('inputShowPageNumber')?.value === '1';

                const mTop = parseFloat(document.getElementById('inputMarginTopMm')?.value) || 25.4;
                const mBtm = parseFloat(document.getElementById('inputMarginBottomMm')?.value) || 25.4;
                const mLft = parseFloat(document.getElementById('inputMarginLeftMm')?.value) || 25.4;
                const mRgt = parseFloat(document.getElementById('inputMarginRightMm')?.value) || 25.4;
                const hDist = parseFloat(document.getElementById('inputHeaderDistanceMm')?.value) || 12;
                const fDist = parseFloat(document.getElementById('inputFooterDistanceMm')?.value) || 10;

                pageDoms.forEach((pageEl, pIdx) => {
                    const pageNum = pIdx + 1;
                    const sheet = document.createElement('div');
                    sheet.className = 'mou-preview-a4-sheet bg-white text-black shadow-2xl relative';
                    sheet.style.width = '210mm';
                    sheet.style.minHeight = '297mm';
                    sheet.style.height = '297mm';
                    sheet.style.maxHeight = '297mm';
                    sheet.style.boxSizing = 'border-box';
                    sheet.style.padding = `${mTop}mm ${mRgt}mm ${mBtm}mm ${mLft}mm`;
                    sheet.style.display = 'flex';
                    sheet.style.flexDirection = 'column';
                    sheet.style.justifyContent = 'space-between';
                    sheet.style.overflow = 'hidden';
                    sheet.style.backgroundColor = '#ffffff';

                    // Header
                    const curHeaderRaw = (differentFirst && pageNum === 1) ? firstHeaderHtml : runningHeaderHtml;
                    const headerDiv = document.createElement('div');
                    headerDiv.className = 'mou-preview-sheet-header shrink-0';
                    if (curHeaderRaw && curHeaderRaw.trim() !== '') {
                        headerDiv.innerHTML = processor.replaceLivePreviewPlaceholders(curHeaderRaw);
                        headerDiv.style.marginBottom = `${Math.max(2, mTop - hDist)}mm`;
                    }
                    sheet.appendChild(headerDiv);

                    // Body Content
                    const bodyDiv = document.createElement('div');
                    bodyDiv.className = 'mou-preview-sheet-body flex-1 overflow-hidden';
                    const contentDom = pageEl.querySelector('.mou-page-body-content');
                    if (contentDom) {
                        const cloneContent = contentDom.cloneNode(true);
                        cloneContent.querySelectorAll('[contenteditable]').forEach(el => el.removeAttribute('contenteditable'));
                        cloneContent.querySelectorAll('.ProseMirror-trailingBreak, .ProseMirror-widget, .column-resize-handle, .mou-table-width-handle').forEach(el => el.remove());
                        bodyDiv.innerHTML = processor.replaceLivePreviewPlaceholders(cloneContent.innerHTML);
                    }
                    sheet.appendChild(bodyDiv);

                    // Footer
                    const curFooterRaw = (differentFirst && pageNum === 1 && firstFooterHtml) ? firstFooterHtml : runningFooterHtml;
                    const footerDiv = document.createElement('div');
                    footerDiv.className = 'mou-preview-sheet-footer shrink-0 flex items-end justify-between gap-4 w-full';
                    footerDiv.style.marginTop = `${Math.max(2, mBtm - fDist)}mm`;

                    const parafDiv = document.createElement('div');
                    parafDiv.className = 'flex-1 min-w-0';
                    parafDiv.style.visibility = showParaf ? 'visible' : 'hidden';
                    parafDiv.innerHTML = curFooterRaw ? processor.replaceLivePreviewPlaceholders(curFooterRaw) : '';

                    const pnumDiv = document.createElement('div');
                    pnumDiv.className = 'font-serif text-[9pt] text-black pb-0.5 shrink-0 select-none text-right whitespace-nowrap';
                    pnumDiv.style.visibility = showPageNum ? 'visible' : 'hidden';
                    pnumDiv.textContent = `Halaman ${pageNum} dari ${totalPages}`;

                    footerDiv.appendChild(parafDiv);
                    footerDiv.appendChild(pnumDiv);
                    sheet.appendChild(footerDiv);

                    liveContainer.appendChild(sheet);
                });

                liveModal.classList.remove('hidden');
            });
        }

        if (btnPrintModal) {
            btnPrintModal.addEventListener('click', () => {
                const printWindow = window.open('', '_blank');
                if (!printWindow) {
                    alert('Jendela pop-up terblokir oleh peramban. Izinkan pop-up untuk mencetak pratinjau.');
                    return;
                }
                const sheetsHtml = document.getElementById('mouLivePreviewSheetsContainer')?.innerHTML || '';
                const printTitle = document.getElementById('name')?.value || 'Naskah Draft MoU';
                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>${printTitle}</title>
                        <style>
                            @page {
                                size: A4 portrait;
                                margin: 0;
                            }
                            * {
                                box-sizing: border-box;
                            }
                            body {
                                margin: 0;
                                padding: 0;
                                background: #ffffff;
                                -webkit-print-color-adjust: exact;
                                print-color-adjust: exact;
                            }
                            .mou-preview-a4-sheet {
                                width: 210mm;
                                height: 297mm;
                                page-break-after: always;
                                margin: 0 auto;
                                overflow: hidden;
                            }
                            .mou-preview-a4-sheet:last-child {
                                page-break-after: auto;
                            }
                            table {
                                border-collapse: collapse;
                            }
                        </style>
                    </head>
                    <body>
                        ${sheetsHtml}
                        <script>
                            window.onload = function() {
                                window.focus();
                                window.print();
                                window.onafterprint = function() { window.close(); };
                            };
                        <\/script>
                    </body>
                    </html>
                `);
                printWindow.document.close();
            });
        }

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

