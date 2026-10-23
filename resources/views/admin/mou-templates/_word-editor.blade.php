@php
    $docType = $documentType ?? 'mou';
    $isMou = ($docType === 'mou');

    $defaultCfg = $isMou
        ? \App\Models\MouTemplate::getDefaultPageConfig()
        : \App\Models\DocumentTemplate::getDefaultPageConfig($docType);
    $cfg = array_merge($defaultCfg, $pageConfig ?? []);

    $defaultAssets = $isMou
        ? \App\Models\MouTemplate::getDefaultMediaAssets()
        : [
            'company_logo' => '/images/mou/seven-inc-logo.png',
        ];
    $assets = array_merge($defaultAssets, $mediaAssets ?? []);

    if ($isMou) {
        $placeholders = $placeholders ?? \App\Models\MouTemplate::getDefaultPlaceholders();
        $metaMap = $placeholderMeta ?? \App\Models\MouTemplate::getPlaceholderMetadata();
    } else {
        $metaMap = $placeholderMeta ?? \App\Services\DocumentTemplates\MailMergePlaceholderContract::getAvailablePlaceholders($docType);
        $placeholders = $placeholders ?? array_map(fn($item) => is_array($item) ? ($item['label'] ?? '') : (string)$item, $metaMap);
    }

    $placeholderGroups = [];
    foreach ($metaMap as $phKey => $phItem) {
        $grp = is_array($phItem) ? ($phItem['group'] ?? 'Lainnya') : 'Lainnya';
        $placeholderGroups[$grp][] = $phKey;
    }

    $savedTemplates = $existingTemplatesPayload ?? [];
    $sampleWordHtml = $sampleWordHtml ?? ($isMou ? \App\Models\MouTemplate::getWordStandardTemplateContent() : \App\Models\DocumentTemplate::getDefaultStandardContent($docType));
    $defaultParafHtml = $defaultParafHtml ?? ($isMou ? \App\Models\MouTemplate::getDefaultFooterParafHtml() : '');

    $initialBodyContent = old('template_html', old('content_html', $initialHtml ?? $sampleWordHtml));
    $initialHeaderContent = old('header_html', $cfg['header_html'] ?? '');
    $initialFooterContent = old('footer_html', array_key_exists('footer_html', $cfg) && $cfg['footer_html'] !== null ? $cfg['footer_html'] : $defaultParafHtml);
    $initialFirstHeaderContent = old('first_page_header_html', $cfg['first_page_header_html'] ?? '');
    $initialFirstFooterContent = old('first_page_footer_html', array_key_exists('first_page_footer_html', $cfg) && $cfg['first_page_footer_html'] !== null ? $cfg['first_page_footer_html'] : $initialFooterContent);
    $initialEditorJson = old('editor_json', isset($editorJson) && is_array($editorJson) ? json_encode($editorJson) : (is_string($editorJson ?? null) ? $editorJson : ''));
    $resolvedCompanyLogo = $assets['company_logo'] ?? $assets['company_logo_url'] ?? '/images/mou/seven-inc-logo.png';
    $resolvedInstitutionLogo = $isMou ? ($assets['institution_logo'] ?? $assets['institution_logo_url'] ?? '/images/mou/institution-logo-placeholder.png') : '';
    $resolvedUploadMediaUrl = $uploadMediaUrl ?? (Route::has('admin.mou-templates.upload-media') ? route('admin.mou-templates.upload-media') : '/admin/mou-templates/upload-media');
@endphp

<style>
    {!! \App\Services\MouDocumentRenderer::getCanonicalDocumentCss($cfg) !!}

    /* Explicit List Styles to override any Tailwind Preflight reset */
    .mou-doc-canvas ul, .mou-document ul, #mouBodyMount ul, #mouHeaderCanvas ul, #mouFooterCanvas ul {
        list-style-type: disc !important;
        padding-left: 24pt !important;
        margin-top: 0 !important;
        margin-bottom: 6pt !important;
    }
    .mou-doc-canvas ul ul, #mouBodyMount ul ul {
        list-style-type: circle !important;
    }
    .mou-doc-canvas ul ul ul, #mouBodyMount ul ul ul {
        list-style-type: square !important;
    }
    .mou-doc-canvas ol, .mou-document ol, #mouBodyMount ol, #mouHeaderCanvas ol, #mouFooterCanvas ol {
        list-style-type: decimal !important;
        padding-left: 24pt !important;
        margin-top: 0 !important;
        margin-bottom: 6pt !important;
    }
    .mou-doc-canvas ol[type="a"], #mouBodyMount ol[type="a"] {
        list-style-type: lower-alpha !important;
    }
    .mou-doc-canvas ol[type="A"], #mouBodyMount ol[type="A"] {
        list-style-type: upper-alpha !important;
    }
    .mou-doc-canvas ol[type="i"], #mouBodyMount ol[type="i"] {
        list-style-type: lower-roman !important;
    }
    .mou-doc-canvas ol[type="I"], #mouBodyMount ol[type="I"] {
        list-style-type: upper-roman !important;
    }
    .mou-doc-canvas li, #mouBodyMount li, #mouHeaderCanvas li, #mouFooterCanvas li {
        display: list-item !important;
        margin-bottom: 2pt !important;
    }
    .mou-doc-canvas li > p, #mouBodyMount li > p {
        display: inline !important;
        margin-bottom: 0 !important;
    }
    .mou-header-zone-wrapper.mou-zone-active,
    .mou-footer-zone-wrapper.mou-zone-active,
    .mou-header-zone-wrapper.mou-zone-active *,
    .mou-footer-zone-wrapper.mou-zone-active * {
        user-select: text !important;
    }

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
        top: 4rem;
        z-index: 20;
        background: #ffffff;
        border-bottom: 1px solid #cbd5e1;
        box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.05);
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
        height: 750px;
        min-height: 650px;
        max-height: calc(100vh - 130px);
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
        height: 100%;
        max-height: 100%;
    }

    /* Collapsible Mail Merge Drawer Sidebar */
    .mou-mail-merge-sidebar {
        width: 320px;
        min-width: 320px;
        max-width: 340px;
        flex: 0 0 320px;
        align-self: stretch;
        height: 100% !important;
        max-height: 100% !important;
        min-height: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        border-left: 1px solid #e2e8f0;
        background-color: #ffffff;
        overflow: hidden !important;
        z-index: 20;
        box-sizing: border-box;
    }
    .mou-mail-merge-sidebar.hidden {
        display: none !important;
    }
    #mouSidebarTokenCategories {
        flex: 1 1 0px !important;
        height: 0 !important;
        min-height: 0 !important;
        max-height: calc(100% - 105px) !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar {
        width: 6px;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar-track {
        background: #f8fafc;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
    }
    #mouSidebarTokenCategories::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
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

    /* Fixed Watermark & Fixed Logo Layer */
    .mou-page-watermark-layer,
    .mou-watermark-layer {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        user-select: none;
        z-index: 1;
        overflow: hidden;
        box-sizing: border-box;
    }
    .mou-watermark-fixed-header {
        position: absolute;
        top: 18mm;
        left: 25.4mm;
        right: 25.4mm;
        pointer-events: none;
        user-select: none;
        box-sizing: border-box;
        height: 86px;
    }
    .mou-watermark-fixed-header.mou-watermark-single-logo {
        right: auto !important;
    }
    .mou-watermark-logo-left {
        position: absolute;
        top: 0;
        left: 0;
        height: 70px;
        width: auto;
        max-width: 140px;
        object-fit: contain;
        pointer-events: none;
        user-select: none;
    }
    .mou-watermark-logo-right {
        position: absolute;
        top: 0;
        right: 0;
        height: 70px;
        width: auto;
        max-width: 140px;
        object-fit: contain;
        pointer-events: none;
        user-select: none;
    }
    .mou-watermark-center-wrapper {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        user-select: none;
        z-index: 1;
    }
    .mou-watermark-center {
        width: 320px;
        max-width: 65%;
        height: auto;
        pointer-events: none;
        user-select: none;
        margin: auto;
        display: block;
    }

    /* Header, Body, Footer Zones on A4 Sheet */
    .mou-header-zone-wrapper {
        position: relative;
        margin: 0;
        padding: 0;
        width: 100%;
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
        margin: 0;
        padding: 0;
        width: 100%;
        border: 1px dashed transparent;
        border-radius: 4px;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 4mm;
        box-sizing: border-box;
        transition: all 0.15s ease;
    }
    .mou-footer-zone-wrapper:hover {
        border-color: #cbd5e1;
        background: rgba(248, 250, 252, 0.5);
    }
    .mou-zone-active {
        border-color: #3b82f6 !important;
        background: rgba(239, 246, 255, 0.25) !important;
        box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.25);
    }
    .mou-zone-dimmed {
        opacity: 0.85;
    }
    .mou-zone-tag {
        display: none !important;
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
        z-index: 2;
        cursor: pointer;
        user-select: none;
        flex-shrink: 0;
        min-height: 12mm;
    }
    .mou-a4-page-footer {
        position: relative;
        z-index: 2;
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
        z-index: 2;
        outline: none;
        word-wrap: break-word;
        overflow: visible;
    }
    .mou-page-body-content.mou-zone-dimmed {
        opacity: 0.85;
        pointer-events: auto;
    }

    /* ProseMirror Content Area */
    .mou-a4-paper-sheet .ProseMirror {
        outline: none;
        word-wrap: break-word;
    }
    .mou-page-body-content > .ProseMirror {
        min-height: 180mm;
    }
    #mouHeaderCanvas,
    #mouFooterCanvas {
        min-height: auto !important;
        height: auto !important;
    }
    #mouHeaderCanvas .ProseMirror,
    #mouFooterCanvas .ProseMirror {
        min-height: auto !important;
        height: auto !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    #mouFooterCanvas .tableWrapper {
        margin: 0 !important;
    }
    .ProseMirror p.is-editor-empty:first-child::before {
        content: attr(data-placeholder);
        float: left;
        color: #94a3b8;
        pointer-events: none;
        height: 0;
        font-style: italic;
    }

    /* ProseMirror Table Resizing & Printable Containment */
    .ProseMirror .tableWrapper {
        margin: 4pt 0;
        overflow-x: visible;
        position: relative;
        max-width: 100% !important;
        box-sizing: border-box;
    }
    .ProseMirror table {
        border-collapse: collapse;
        table-layout: fixed;
        max-width: 100% !important;
        box-sizing: border-box;
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

    /* Signature & Official Wet-Ink Stamp Seal Overlapping Container */
    .signature-block,
    div[data-signature-block="true"] {
        height: 75px !important;
        margin: 8px 0 !important;
        position: relative !important;
        text-align: center;
        overflow: visible !important;
    }
    .signature-block .mou-image-wrapper[style*="position: absolute"],
    .signature-block .mou-image-wrapper.is-stamp-seal,
    .mou-image-wrapper.is-stamp-seal {
        position: absolute !important;
        overflow: visible !important;
    }

    /* Interactive Object Sizing Handles (Images & Objects) */
    .mou-image-wrapper {
        display: inline-block;
        position: relative;
        max-width: 100%;
        line-height: 0;
        vertical-align: middle;
        user-select: none;
        box-sizing: border-box;
    }
    .mou-image-wrapper.is-selected,
    .mou-image-wrapper:hover {
        outline: 2px solid #2563eb;
        outline-offset: 1px;
    }
    .mou-image-handle-overlay {
        position: absolute;
        inset: -2px;
        pointer-events: none;
        z-index: 30;
    }
    .mou-image-wrapper.is-selected .mou-image-handle-overlay {
        display: block !important;
    }
    .mou-image-handle {
        position: absolute;
        width: 9px;
        height: 9px;
        background: #ffffff;
        border: 2px solid #2563eb;
        border-radius: 1px;
        pointer-events: auto;
        box-sizing: border-box;
        z-index: 35;
    }
    .mou-image-handle.nw { top: -5px; left: -5px; cursor: nwse-resize; }
    .mou-image-handle.ne { top: -5px; right: -5px; cursor: nesw-resize; }
    .mou-image-handle.se { bottom: -5px; right: -5px; cursor: nwse-resize; }
    .mou-image-handle.sw { bottom: -5px; left: -5px; cursor: nesw-resize; }
    .mou-image-handle.n { top: -5px; left: 50%; transform: translateX(-50%); cursor: ns-resize; }
    .mou-image-handle.s { bottom: -5px; left: 50%; transform: translateX(-50%); cursor: ns-resize; }
    .mou-image-handle.w { top: 50%; left: -5px; transform: translateY(-50%); cursor: ew-resize; }
    .mou-image-handle.e { top: 50%; right: -5px; transform: translateY(-50%); cursor: ew-resize; }
    .mou-image-size-badge {
        position: absolute;
        bottom: -22px;
        left: 50%;
        transform: translateX(-50%);
        background: #1e293b;
        color: #ffffff;
        font-size: 10px;
        font-weight: 600;
        padding: 1px 6px;
        border-radius: 4px;
        white-space: nowrap;
        pointer-events: none;
        z-index: 40;
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
    .mou-preview-pages-stage {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 28px;
        width: 100%;
    }
    .mou-preview-a4-sheet {
        width: 210mm;
        min-width: 210mm;
        max-width: 210mm;
        height: 297mm;
        min-height: 297mm;
        max-height: 297mm;
        background: #ffffff;
        border: 1px solid #94a3b8;
        box-shadow: 0 12px 28px -6px rgba(15, 23, 42, 0.22), 0 4px 12px -2px rgba(15, 23, 42, 0.12);
        box-sizing: border-box;
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
        color: #000000;
    }
    .mou-preview-sheet-header {
        position: relative;
        z-index: 2;
        flex-shrink: 0;
        margin-bottom: 3mm;
    }
    .mou-preview-sheet-body {
        position: relative;
        z-index: 2;
        flex: 1 1 0%;
        width: 100%;
        min-height: 0;
        overflow: hidden;
    }
    .mou-preview-sheet-body .mou-page-break {
        display: none !important;
    }
    .mou-preview-sheet-footer {
        position: relative;
        z-index: 2;
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

    @media print {
        @page {
            size: A4 portrait;
            margin: 0;
        }
        body.mou-preview-modal-open > *:not(#mouA4PreviewModal),
        body.mou-preview-modal-open #mouA4PreviewModal .no-print {
            display: none !important;
        }
        body.mou-preview-modal-open,
        body.mou-preview-modal-open main {
            background: white !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        body.mou-preview-modal-open #mouA4PreviewModal {
            position: static !important;
            display: block !important;
            background: white !important;
            padding: 0 !important;
            margin: 0 !important;
            width: 100% !important;
            height: auto !important;
            overflow: visible !important;
            z-index: auto !important;
        }
        body.mou-preview-modal-open .mou-modal-card {
            border: none !important;
            box-shadow: none !important;
            max-width: none !important;
            width: 100% !important;
            height: auto !important;
            border-radius: 0 !important;
            overflow: visible !important;
        }
        body.mou-preview-modal-open #mouModalPaperDeskScroll {
            background: white !important;
            padding: 0 !important;
            overflow: visible !important;
        }
        body.mou-preview-modal-open #mouModalScaleContainer {
            transform: none !important;
            margin-bottom: 0 !important;
        }
        body.mou-preview-modal-open .mou-preview-pages-stage {
            gap: 0 !important;
        }
        body.mou-preview-modal-open .mou-preview-a4-sheet {
            box-shadow: none !important;
            border: none !important;
            page-break-after: always !important;
            break-after: page !important;
        }
        body.mou-preview-modal-open .mou-preview-a4-sheet:last-child {
            page-break-after: auto !important;
            break-after: auto !important;
        }
    }
</style>

<!-- Hidden Inputs for Form Persistence (Canonical HTML + ProseMirror JSON + Page Config V3) -->
<input type="hidden" name="template_html" id="inputTemplateHtml" value="{{ $initialBodyContent }}">
<input type="hidden" name="content_html" id="inputContentHtml" value="{{ $initialBodyContent }}">
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
@if($isMou)
<input type="hidden" name="institution_logo" id="inputInstitutionLogo" value="{{ $resolvedInstitutionLogo }}">
<input type="hidden" name="institution_logo_url" id="inputInstitutionLogoUrl" value="{{ $resolvedInstitutionLogo }}">
@endif
<input type="hidden" name="company_logo" id="inputCompanyLogo" value="{{ $resolvedCompanyLogo }}">
<input type="hidden" name="company_logo_url" id="inputCompanyLogoUrl" value="{{ $resolvedCompanyLogo }}">
<input type="hidden" name="show_header_logos" id="inputShowHeaderLogos" value="{{ ($cfg['show_header_logos'] ?? true) ? '1' : '0' }}">
<input type="hidden" name="show_watermark" id="inputShowWatermark" value="{{ ($cfg['show_watermark'] ?? (!$isMou ? true : false)) ? '1' : '0' }}">
<input type="hidden" name="watermark_opacity" id="inputWatermarkOpacity" value="{{ $cfg['watermark_opacity'] ?? 0.05 }}">

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
                        <label for="btnImportDocxInput" class="mou-ribbon-btn border-indigo-200 bg-indigo-50 text-indigo-700 cursor-pointer" title="Import template dari file Microsoft Word (.docx) yang editable">
                            <svg class="h-3.5 w-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                            <span>Import DOCX</span>
                            <input type="file" id="btnImportDocxInput" accept=".docx" class="hidden">
                        </label>
                        <button type="button" id="btnExportDocx" class="mou-ribbon-btn border-emerald-200 bg-emerald-50 text-emerald-700" title="Download template ini dalam format Microsoft Word (.docx) yang dapat diedit di Word/LibreOffice">
                            <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <span>Export DOCX</span>
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
                                    <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase">Baris &amp; Kolom</span>
                                    <button type="button" onclick="window.MouWordProcessor?.addRowBefore()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Baris Di Atas</button>
                                    <button type="button" onclick="window.MouWordProcessor?.addRowAfter()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Baris Di Bawah</button>
                                    <button type="button" onclick="window.MouWordProcessor?.addColumnBefore()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Kolom Di Kiri</button>
                                    <button type="button" onclick="window.MouWordProcessor?.addColumnAfter()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">+ Tambah Kolom Di Kanan</button>
                                </div>
                                <div class="p-1">
                                    <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase">Manipulasi Sel &amp; Hapus</span>
                                    <button type="button" onclick="window.MouWordProcessor?.mergeCells()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">Gabung Sel (Merge)</button>
                                    <button type="button" onclick="window.MouWordProcessor?.splitCell()" class="w-full text-left px-2 py-1 hover:bg-slate-100 rounded text-slate-700">Pisah Sel (Split)</button>
                                    <button type="button" onclick="window.MouWordProcessor?.deleteRow()" class="w-full text-left px-2 py-1 hover:bg-rose-50 text-rose-600 rounded">Hapus Baris</button>
                                    <button type="button" onclick="window.MouWordProcessor?.deleteColumn()" class="w-full text-left px-2 py-1 hover:bg-rose-50 text-rose-600 rounded">Hapus Kolom</button>
                                    <button type="button" onclick="window.MouWordProcessor?.deleteTable()" class="w-full text-left px-2 py-1 hover:bg-rose-100 text-rose-700 rounded font-bold">Hapus Seluruh Tabel</button>
                                </div>
                            </div>
                        </div>
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
                        <label class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700 cursor-pointer" title="Unggah gambar bebas ke dalam teks">
                            <span>+ Gambar</span>
                            <input type="file" id="inputUploadCustomImage" accept="image/png,image/jpeg,image/webp" class="hidden">
                        </label>
                        <button type="button" id="btnOpenAssetLibraryModal" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700 font-semibold text-xs px-2" title="Pilih Aset dari Perpustakaan Aset (Logo, Kop, TTD, Stempel)">
                            <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            <span>+ Aset</span>
                        </button>
                        @if($isMou)
                        <label class="mou-ribbon-btn border-slate-200 bg-slate-50 cursor-pointer" title="Unggah Logo Institusi (Tetap / Watermark Kiri Atas)">
                            <span>Logo Institusi</span>
                            <input type="file" id="inputUploadInstitutionLogo" accept="image/png,image/jpeg,image/webp" class="hidden">
                        </label>
                        <button type="button" id="btnInsertCompanyLogo" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tetapkan Logo Seven Inc (Tetap / Watermark Kanan Atas)">
                            Logo Seven Inc
                        </button>
                        @endif
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        @if($isMou)
                        <button type="button" id="btnToggleHeaderLogos" class="mou-ribbon-btn border-slate-200 bg-slate-50 {{ ($cfg['show_header_logos'] ?? true) ? 'mou-ribbon-btn-active' : '' }}" title="Tampilkan / Sembunyikan Logo Tetap di Sudut Atas Kertas">
                            Logo Tetap (Header)
                        </button>
                        @endif
                        <button type="button" id="btnToggleWatermark" class="mou-ribbon-btn border-slate-200 bg-slate-50 {{ ($cfg['show_watermark'] ?? true) ? 'mou-ribbon-btn-active' : '' }}" title="Aktifkan / Nonaktifkan Watermark Latar Tengah Kertas">
                            Watermark Tengah
                        </button>
                        <button type="button" id="btnApplyImageSize" class="mou-ribbon-btn border-slate-200 bg-slate-50">Terapkan Ukuran</button>
                    </div>
                    <div class="mou-ribbon-group-label">{{ $isMou ? 'Logo Tetap & Media' : 'Watermark & Media' }}</div>
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
                                    $rawKey = trim($phKey, '{}[]');
                                    $phBracket = '[' . $rawKey . ']';
                                    $phMustache = '{{' . $rawKey . '}}';
                                    $phLabel = is_array($phDesc) ? ($phDesc['label'] ?? $phDesc['canonical'] ?? $rawKey) : (string)$phDesc;
                                @endphp
                                <option value="{{ $phMustache }}">{{ $phMustache }} ({{ $phBracket }}) — {{ $phLabel }}</option>
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

                <!-- Group: Ukuran Tabel, Kolom & Tinggi Baris (Spin-Box Numerik) -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <label class="text-xs font-semibold text-slate-600">Lebar:</label>
                        <div class="inline-flex items-center rounded-md border border-slate-300 bg-white">
                            <input type="text" id="ribbonTableWidthInput" value="100%" placeholder="100%" class="h-[30px] w-14 border-0 px-1 text-xs text-center focus:ring-0">
                            <div class="flex flex-col border-l border-slate-200">
                                <button type="button" id="btnSpinTableWidthUp" onclick="window.MouWordProcessor?.adjustTableWidthSpin(5)" class="h-[15px] px-1 text-[8px] hover:bg-slate-100 flex items-center justify-center text-slate-600" title="Lebarkan tabel (+5%)">▲</button>
                                <button type="button" id="btnSpinTableWidthDown" onclick="window.MouWordProcessor?.adjustTableWidthSpin(-5)" class="h-[15px] px-1 text-[8px] hover:bg-slate-100 flex items-center justify-center text-slate-600 border-t border-slate-200" title="Sempitkan tabel (-5%)">▼</button>
                            </div>
                        </div>
                        <button type="button" id="btnApplyTableWidth" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700">Set</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentTableWidth('100%')" class="mou-ribbon-btn border-slate-200 bg-slate-50">100%</button>

                        <label class="text-xs font-semibold text-slate-600 ml-1">Kolom:</label>
                        <div class="inline-flex items-center rounded-md border border-slate-300 bg-white">
                            <input type="number" id="ribbonColWidthInput" placeholder="180" min="30" max="700" step="5" class="h-[30px] w-14 border-0 px-1 text-xs text-center focus:ring-0">
                            <div class="flex flex-col border-l border-slate-200">
                                <button type="button" id="btnSpinColWidthUp" onclick="window.MouWordProcessor?.adjustColWidthSpin(5)" class="h-[15px] px-1 text-[8px] hover:bg-slate-100 flex items-center justify-center text-slate-600" title="Lebarkan kolom (+5px)">▲</button>
                                <button type="button" id="btnSpinColWidthDown" onclick="window.MouWordProcessor?.adjustColWidthSpin(-5)" class="h-[15px] px-1 text-[8px] hover:bg-slate-100 flex items-center justify-center text-slate-600 border-t border-slate-200" title="Sempitkan kolom (-5px)">▼</button>
                            </div>
                        </div>
                        <button type="button" id="btnApplyColWidth" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700">Set</button>
                    </div>

                    <div class="flex items-center gap-1.5 mt-1.5">
                        <label class="text-xs font-semibold text-slate-600">Tinggi:</label>
                        <div class="inline-flex items-center rounded-md border border-slate-300 bg-white">
                            <input type="number" id="ribbonRowHeightInput" placeholder="18" min="12" max="250" step="1" class="h-[30px] w-14 border-0 px-1 text-xs text-center focus:ring-0">
                            <div class="flex flex-col border-l border-slate-200">
                                <button type="button" id="btnSpinRowHeightUp" onclick="window.MouWordProcessor?.adjustRowHeightSpin(1)" class="h-[15px] px-1 text-[8px] hover:bg-slate-100 flex items-center justify-center text-slate-600" title="Tambah tinggi baris (+1pt)">▲</button>
                                <button type="button" id="btnSpinRowHeightDown" onclick="window.MouWordProcessor?.adjustRowHeightSpin(-1)" class="h-[15px] px-1 text-[8px] hover:bg-slate-100 flex items-center justify-center text-slate-600 border-t border-slate-200" title="Kurangi tinggi baris (-1pt)">▼</button>
                            </div>
                        </div>
                        <button type="button" id="btnApplyRowHeight" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700">Set</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('18pt')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi standar ruang Paraf">18pt (Paraf)</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('28pt')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi ruang Paraf diperbesar">28pt (Paraf+)</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('26mm')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi standar ruang Tanda Tangan">26mm (TTD)</button>
                        <button type="button" onclick="window.MouWordProcessor?.setCurrentRowHeight('35mm')" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tinggi ruang Tanda Tangan besar">35mm (TTD+)</button>
                    </div>
                    <div class="mou-ribbon-group-label">Ukuran Tabel, Kolom &amp; Tinggi Baris (Spin Box)</div>
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
    <div id="mouStudioMainWorkspaceRow" class="mou-studio-workspace-row" style="height: 750px; min-height: 650px; max-height: calc(100vh - 130px); display: flex; overflow: hidden; position: relative;">
        <!-- WORKSPACE VIEW 1: LIVE WORD PROCESSOR CANVAS -->
        <div id="mouEditorWorkspaceView" class="mou-workspace-viewport" style="height: 100%; max-height: 100%; overflow-y: auto;">
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
                    <div id="mouHeaderCanvas"></div>
                </div>
                <div id="mouFooterZoneWrapper" class="mou-footer-zone-wrapper" title="Footer dokumen &amp; Kotak Paraf">
                    <div id="mouFooterCanvas" class="flex-1 min-w-0"></div>
                    <div id="mouFooterPageNumberPreview" class="font-serif text-[9pt] text-black pb-0.5 shrink-0 select-none">
                        Halaman 1 dari 1
                    </div>
                </div>
            </div>
        </div>

        <!-- WORKSPACE VIEW 2: MULTI-SHEET A4 PREVIEW SIMULATION -->
        <div id="mouMultiSheetPreviewContainer" class="hidden flex-1 overflow-y-auto overflow-x-auto bg-slate-300/80 py-6 px-4">
            <!-- Inline Preview Toolbar -->
            <div class="mx-auto max-w-4xl mb-4 rounded-xl border border-slate-200 bg-white p-3 shadow-xs flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-slate-800">Pratinjau Lembar A4</span>
                    <span id="inlinePreviewPageCountBadge" class="rounded bg-blue-50 border border-blue-200 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                        1 Halaman A4
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <label for="inlinePreviewZoomSelect" class="text-xs font-semibold text-slate-600">Zoom:</label>
                    <select
                        id="inlinePreviewZoomSelect"
                        class="rounded-md border border-slate-300 bg-white py-1 pl-2 pr-6 text-xs font-medium text-slate-700 focus:border-blue-500 focus:outline-none"
                    >
                        <option value="fit-width" selected>Lebar Pas (Fit Width)</option>
                        <option value="fit-page">Satu Halaman Penuh</option>
                        <option value="0.75">75%</option>
                        <option value="0.90">90%</option>
                        <option value="1.00">100% (Ukuran Nyata)</option>
                    </select>

                    <button
                        type="button"
                        id="btnOpenA4ModalFromInline"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-blue-700 transition"
                        title="Buka pratinjau lembar A4 ke jendela modal layar penuh"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="15 3 21 3 21 9"/>
                            <polyline points="9 21 3 21 3 15"/>
                            <line x1="21" y1="3" x2="14" y2="10"/>
                            <line x1="3" y1="21" x2="10" y2="14"/>
                        </svg>
                        <span>Layar Penuh</span>
                    </button>
                </div>
            </div>

            <div class="mx-auto max-w-4xl mb-5 rounded-xl border border-blue-200 bg-blue-50/90 px-4 py-2.5 text-xs text-blue-800 flex items-start gap-2.5">
                <svg class="h-4 w-4 text-blue-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>
                    <span class="font-bold">Simulasi Pemenggalan Halaman A4:</span>
                    Pratinjau membagi halaman kertas A4 secara otomatis berdasarkan batas margin, ruang Kop Surat, dan Tabel Kotak Paraf. Gunakan tombol <strong>Layar Penuh</strong> di atas untuk membaca atau memeriksa naskah dengan nyaman.
                </div>
            </div>

            <div id="mouInlineScaleContainer" style="transform-origin: top center; transition: transform 0.15s ease-out; display: flex; flex-direction: column; align-items: center; width: 100%;">
                <div id="mouMultiSheetPreviewStage" class="mou-preview-pages-stage flex flex-col items-center gap-7"></div>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: Collapsible Mail Merge / Dynamic Placeholder Drawer -->
        <aside id="mouMailMergeSidebar" class="mou-mail-merge-sidebar shadow-sm flex flex-col w-80 shrink-0 border-l border-slate-200 bg-white overflow-hidden" style="height: 100%; max-height: 100%; min-height: 0; display: flex; flex-direction: column;">
            <!-- Sidebar Header -->
            <div class="shrink-0 p-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between" style="flex-shrink: 0;">
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
            <div class="shrink-0 p-2.5 border-b border-slate-100 bg-white" style="flex-shrink: 0;">
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
            <div id="mouSidebarTokenCategories" class="flex-1 min-h-0 overflow-y-auto p-2.5 space-y-2" style="flex: 1 1 0px; min-height: 0; height: calc(100% - 105px); max-height: calc(100% - 105px); overflow-y: auto !important; overflow-x: hidden;">
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
                                    $rawKey = trim($phKey, '{}[]');
                                    $phItem = $metaMap[$phKey] ?? ($placeholders[$phKey] ?? $rawKey);
                                    $phDesc = is_array($phItem) ? ($phItem['label'] ?? $phItem['canonical'] ?? $rawKey) : (string)$phItem;
                                    $phMustache = '{{' . $rawKey . '}}';
                                    $phBracket = '[' . $rawKey . ']';
                                    $insertToken = $isMou ? $phBracket : $phMustache;
                                @endphp
                                <div class="mou-token-item flex items-center justify-between gap-1 p-1 rounded hover:bg-slate-50 border border-transparent hover:border-slate-200 transition" data-token-search="{{ strtolower($rawKey . ' ' . $phDesc) }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[11px] font-mono font-bold text-blue-700 truncate" title="{{ $phMustache }}">{{ $phMustache }}</div>
                                        <div class="text-[10px] text-slate-500 truncate" title="{{ $phDesc }}">{{ $phDesc }}</div>
                                    </div>
                                    <button
                                        type="button"
                                        onmousedown="event.preventDefault()"
                                        onclick="window.MouWordProcessor?.insertPlaceholder('{{ $insertToken }}')"
                                        class="h-6 px-2 text-[10px] font-semibold rounded bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-600 hover:text-white transition shrink-0"
                                        title="Sisipkan {{ $insertToken }} ke posisi kursor aktif"
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

<!-- ========================================================================= -->
<!-- MODAL FULLSCREEN / EXPANSIVE PRATINJAU LEMBAR A4 (IDENTIK DENGAN ADMIN UI) -->
<!-- ========================================================================= -->
<div
    id="mouA4PreviewModal"
    class="fixed inset-0 z-50 hidden flex-col bg-slate-900/75 backdrop-blur-xs p-0 sm:p-1.5 md:p-2 no-print"
    tabindex="-1"
    role="dialog"
    aria-modal="true"
    aria-labelledby="mouModalTitle"
>
    <div class="mou-modal-card flex flex-col w-full max-w-[1560px] mx-auto h-full rounded-none sm:rounded-xl bg-white shadow-2xl border border-slate-300 overflow-hidden">
        <!-- Top Action Bar Header (Compact & Full to Top) -->
        <div class="flex flex-wrap items-center justify-between gap-2.5 border-b border-slate-200 bg-white px-4 py-2 shrink-0">
            <!-- Left: Title, Icon & Page Badge -->
            <div class="flex items-center gap-2.5">
                <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-blue-200 bg-blue-50 text-blue-600 shadow-xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 id="mouModalTitle" class="text-sm font-bold text-slate-800">
                            Pratinjau Lembar Kertas A4
                        </h2>
                        <span id="mouModalPageCountBadge" class="rounded bg-blue-50 border border-blue-200 px-2 py-0.5 text-xs font-semibold text-blue-700">
                            1 Halaman A4
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 hidden sm:block">
                        Simulasi tata letak fisik naskah pada lembar A4 (210 &times; 297 mm) sesuai template saat menyusun.
                    </p>
                </div>
            </div>

            <!-- Right: Controls (Zoom In/Out, Presets, Cetak Browser, Tutup) -->
            <div class="flex items-center gap-2">
                <!-- Zoom Controls Group: Zoom Out (-), Dropdown, Zoom In (+) -->
                <div class="inline-flex items-center gap-0.5 rounded-lg border border-slate-300 bg-slate-100 p-0.5 shadow-xs">
                    <button
                        type="button"
                        id="btnModalZoomOut"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-white text-slate-700 shadow-xs hover:bg-slate-50 hover:text-slate-900 transition"
                        title="Perkecil Tampilan (Zoom Out)"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                    </button>

                    <select
                        id="mouModalZoomSelect"
                        class="h-7 rounded-md border-0 bg-transparent py-0 pl-2 pr-6 text-xs font-bold text-slate-800 focus:outline-none cursor-pointer"
                        title="Pilihan Skala Zoom"
                    >
                        <option value="fit-page" selected>Satu Halaman Penuh</option>
                        <option value="fit-width">Lebar Pas (Fit Width)</option>
                        <option value="0.50">50%</option>
                        <option value="0.65">65%</option>
                        <option value="0.75">75%</option>
                        <option value="0.85">85%</option>
                        <option value="1.00">100% (Ukuran Nyata)</option>
                        <option value="1.15">115%</option>
                        <option value="1.25">125%</option>
                        <option value="1.50">150%</option>
                    </select>

                    <button
                        type="button"
                        id="btnModalZoomIn"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-white text-slate-700 shadow-xs hover:bg-slate-50 hover:text-slate-900 transition"
                        title="Perbesar Tampilan (Zoom In)"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                    </button>
                </div>

                <!-- Quick Presets -->
                <button
                    type="button"
                    id="btnModalZoomFitPage"
                    class="hidden md:inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-xs hover:bg-slate-50 transition"
                    title="Paskan tinggi 1 halaman penuh di layar"
                >
                    Penuh 1 Layar
                </button>
                <button
                    type="button"
                    id="btnModalZoomFitWidth"
                    class="hidden md:inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-xs hover:bg-slate-50 transition"
                    title="Paskan lebar kertas dengan lebar jendela"
                >
                    Lebar Pas
                </button>

                <!-- Cetak Browser Button -->
                <button
                    type="button"
                    id="btnModalPrintBrowser"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-blue-700"
                    title="Cetak langsung ke printer atau simpan PDF melalui browser"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    <span>Cetak</span>
                </button>

                <!-- Tutup / Esc Button -->
                <button
                    type="button"
                    id="btnModalClosePreview"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50"
                    title="Tutup pratinjau dan kembali ke editor (Esc)"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                    <span>Tutup</span>
                </button>
            </div>
        </div>

        <!-- Info / Notice Bar (Compact) -->
        <div class="flex items-center justify-between border-b border-blue-100 bg-blue-50/90 px-4 py-1.5 text-xs text-blue-800 shrink-0">
            <span>
                <strong>Pratinjau Lembar A4:</strong> Menampilkan pembagian halaman dan tata letak persis seperti yang Anda susun pada editor dokumen.
            </span>
            <span class="text-[11px] text-blue-600 font-mono hidden md:inline">
                A4 (210 &times; 297 mm)
            </span>
        </div>

        <!-- Paper Desk Stage Canvas (Full to top with minimal padding pt-3) -->
        <div id="mouModalPaperDeskScroll" class="flex-1 overflow-y-auto overflow-x-auto bg-slate-300/80 pt-3 pb-8 px-4 flex flex-col items-center">
            <div id="mouModalScaleContainer" style="transform-origin: top center; transition: transform 0.15s ease-out; display: flex; flex-direction: column; align-items: center; width: 100%;">
                <div id="modalPreviewPagesStage" class="mou-preview-pages-stage flex flex-col items-center gap-6">
                    <!-- Dynamic A4 Sheet cards populated by renderMultiSheetPreview -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Pemilihan Aset Sistem (Perpustakaan Aset) -->
<div id="mouAssetLibraryModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
    <div class="relative w-full max-w-4xl bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Top Bar -->
        <div class="flex items-center justify-between px-5 py-3.5 bg-slate-900 border-b border-slate-700 text-white shrink-0">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-blue-600 font-bold text-xs text-white shadow-xs">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </span>
                <div>
                    <h3 class="font-bold text-sm text-white">Perpustakaan Aset &amp; Dokumen</h3>
                    <p class="text-[11px] text-slate-400">Pilih logo, kop surat, tanda tangan, stempel, atau background dari penyimpanan terpusat.</p>
                </div>
            </div>
            <button
                type="button"
                id="btnCloseAssetLibraryModal"
                class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white transition text-lg font-bold"
                title="Tutup (Esc)"
            >
                &times;
            </button>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 shrink-0">
            <!-- Category Tabs -->
            <div class="flex items-center gap-1 overflow-x-auto text-xs" id="mouAssetCategoryTabs">
                <button type="button" data-cat="" class="px-2.5 py-1 rounded-md font-semibold bg-blue-600 text-white">Semua</button>
                <button type="button" data-cat="logo" class="px-2.5 py-1 rounded-md font-medium text-slate-600 hover:bg-slate-200">Logo</button>
                <button type="button" data-cat="letterhead" class="px-2.5 py-1 rounded-md font-medium text-slate-600 hover:bg-slate-200">Kop Surat</button>
                <button type="button" data-cat="signature" class="px-2.5 py-1 rounded-md font-medium text-slate-600 hover:bg-slate-200">Tanda Tangan</button>
                <button type="button" data-cat="stamp" class="px-2.5 py-1 rounded-md font-medium text-slate-600 hover:bg-slate-200">Stempel</button>
                <button type="button" data-cat="background" class="px-2.5 py-1 rounded-md font-medium text-slate-600 hover:bg-slate-200">Background</button>
                <button type="button" data-cat="supporting_image" class="px-2.5 py-1 rounded-md font-medium text-slate-600 hover:bg-slate-200">Gambar Pendukung</button>
            </div>

            <!-- Search Field -->
            <div class="relative w-full sm:w-64">
                <input
                    type="text"
                    id="mouAssetSearchInput"
                    placeholder="Cari aset (nama, kode)..."
                    class="w-full h-8 pl-8 pr-3 text-xs rounded-lg border border-slate-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                >
                <svg class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </div>
        </div>

        <!-- Scrollable Asset Grid -->
        <div id="mouAssetGridContainer" class="flex-1 overflow-y-auto p-4 min-h-[300px] max-h-[60vh] bg-slate-100">
            <div id="mouAssetLoadingState" class="hidden flex flex-col items-center justify-center py-12 text-slate-400">
                <svg class="animate-spin h-6 w-6 text-blue-600 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span class="text-xs">Memuat daftar aset...</span>
            </div>
            <div id="mouAssetEmptyState" class="hidden text-center py-12 text-slate-400 text-xs">
                Tidak ada aset yang sesuai kriteria pencarian.
            </div>
            <div id="mouAssetItemsList" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                <!-- Dynamically populated via JavaScript -->
            </div>
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
        const detail = document.getElementById('mouEditorDiagnosticText') || document.getElementById('mouEditorDiagnosticMessage');
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
                documentType: '{{ $docType }}',
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
                uploadMediaUrl: '{{ $resolvedUploadMediaUrl }}',
                csrfToken: '{{ csrf_token() }}',
            });
        } catch (initErr) {
            console.error('MouWordProcessor initialization error:', initErr);
            showDiagnosticError('Gagal menginisialisasi Tiptap Word Processor: ' + (initErr?.message || String(initErr)));
            return;
        }

        // Zoom computation helpers
        let currentModalScale = 1.0;
        const MODAL_ZOOM_LEVELS = [0.4, 0.5, 0.65, 0.75, 0.85, 1.0, 1.15, 1.25, 1.5, 1.75, 2.0];

        function applyModalZoom(zoomVal) {
            const scrollContainer = document.getElementById('mouModalPaperDeskScroll');
            const scaleContainer = document.getElementById('mouModalScaleContainer');
            const stage = document.getElementById('modalPreviewPagesStage');
            const zoomSel = document.getElementById('mouModalZoomSelect');
            if (!scrollContainer || !scaleContainer || !stage) return;

            const a4W = 210 * (96 / 25.4); // ~793.7px
            const a4H = 297 * (96 / 25.4); // ~1122.5px
            let scale = 1.0;

            if (zoomVal === 'fit-page') {
                const availH = Math.max(250, scrollContainer.clientHeight - 24);
                scale = Math.min(1.2, Math.max(0.35, availH / a4H));
            } else if (zoomVal === 'fit-width') {
                const availW = Math.max(250, scrollContainer.clientWidth - 40);
                scale = Math.min(1.5, Math.max(0.35, availW / a4W));
            } else {
                scale = parseFloat(zoomVal) || 1.0;
            }

            currentModalScale = scale;
            scaleContainer.style.transform = `scale(${scale})`;
            scaleContainer.style.transformOrigin = 'top center';

            const totalSheets = stage.querySelectorAll('.mou-preview-a4-sheet').length || 1;
            const gap = 24;
            const naturalH = totalSheets * a4H + (totalSheets - 1) * gap;
            const scaledH = naturalH * scale;
            scaleContainer.style.marginBottom = `${scaledH - naturalH}px`;

            if (zoomSel && typeof zoomVal === 'string' && (zoomVal === 'fit-page' || zoomVal === 'fit-width')) {
                zoomSel.value = zoomVal;
            }
        }

        function stepModalZoom(direction) {
            let cur = currentModalScale || 1.0;
            let target = cur;

            if (direction > 0) {
                target = MODAL_ZOOM_LEVELS.find(lvl => lvl > cur + 0.03) || MODAL_ZOOM_LEVELS[MODAL_ZOOM_LEVELS.length - 1];
            } else {
                const reversed = [...MODAL_ZOOM_LEVELS].reverse();
                target = reversed.find(lvl => lvl < cur - 0.03) || MODAL_ZOOM_LEVELS[0];
            }

            const zoomSel = document.getElementById('mouModalZoomSelect');
            if (zoomSel) {
                for (let opt of zoomSel.options) {
                    if (parseFloat(opt.value) && Math.abs(parseFloat(opt.value) - target) < 0.03) {
                        zoomSel.value = opt.value;
                        break;
                    }
                }
            }

            applyModalZoom(target);
        }

        function applyInlineZoom(zoomVal) {
            const scrollContainer = document.getElementById('mouMultiSheetPreviewContainer');
            const scaleContainer = document.getElementById('mouInlineScaleContainer');
            const stage = document.getElementById('mouMultiSheetPreviewStage');
            if (!scrollContainer || !scaleContainer || !stage) return;

            const a4W = 210 * (96 / 25.4);
            const a4H = 297 * (96 / 25.4);
            let scale = 1.0;

            if (zoomVal === 'fit-page') {
                const availH = Math.max(300, scrollContainer.clientHeight - 130);
                scale = Math.min(1.0, Math.max(0.35, availH / a4H));
            } else if (zoomVal === 'fit-width') {
                const availW = Math.max(300, scrollContainer.clientWidth - 56);
                scale = Math.min(1.15, Math.max(0.4, availW / a4W));
            } else {
                scale = parseFloat(zoomVal) || 1.0;
            }

            scaleContainer.style.transform = `scale(${scale})`;
            scaleContainer.style.transformOrigin = 'top center';

            const totalSheets = stage.querySelectorAll('.mou-preview-a4-sheet').length || 1;
            const gap = 28;
            const naturalH = totalSheets * a4H + (totalSheets - 1) * gap;
            const scaledH = naturalH * scale;
            scaleContainer.style.marginBottom = `${scaledH - naturalH}px`;
        }

        function openA4PreviewModal() {
            const modal = document.getElementById('mouA4PreviewModal');
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden', 'mou-preview-modal-open');

            requestAnimationFrame(() => {
                processor.renderMultiSheetPreview('modalPreviewPagesStage', 'mouModalPageCountBadge');
                const zoomSel = document.getElementById('mouModalZoomSelect');
                applyModalZoom(zoomSel?.value || 'fit-page');
            });
        }

        function closeA4PreviewModal() {
            const modal = document.getElementById('mouA4PreviewModal');
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden', 'mou-preview-modal-open');
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
                btnModeEdit.className = 'rounded-md bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs';
                btnModePreview.className = 'rounded-md px-3 py-1 text-xs font-semibold text-slate-600 hover:text-slate-900 transition';
            });

            btnModePreview.addEventListener('click', () => {
                openA4PreviewModal();
            });
        }

        // Inline Preview Toolbar handlers
        document.getElementById('btnOpenA4ModalFromInline')?.addEventListener('click', () => {
            openA4PreviewModal();
        });
        document.getElementById('inlinePreviewZoomSelect')?.addEventListener('change', (e) => {
            applyInlineZoom(e.target.value);
        });

        // Modal Controls: Zoom In, Zoom Out, Select, Fit Buttons, Print, Close
        document.getElementById('btnModalZoomIn')?.addEventListener('click', () => {
            stepModalZoom(1);
        });
        document.getElementById('btnModalZoomOut')?.addEventListener('click', () => {
            stepModalZoom(-1);
        });
        document.getElementById('btnModalZoomFitPage')?.addEventListener('click', () => {
            const zoomSel = document.getElementById('mouModalZoomSelect');
            if (zoomSel) zoomSel.value = 'fit-page';
            applyModalZoom('fit-page');
        });
        document.getElementById('btnModalZoomFitWidth')?.addEventListener('click', () => {
            const zoomSel = document.getElementById('mouModalZoomSelect');
            if (zoomSel) zoomSel.value = 'fit-width';
            applyModalZoom('fit-width');
        });
        document.getElementById('mouModalZoomSelect')?.addEventListener('change', (e) => {
            applyModalZoom(e.target.value);
        });
        document.getElementById('btnModalPrintBrowser')?.addEventListener('click', () => {
            window.print();
        });
        document.getElementById('btnModalClosePreview')?.addEventListener('click', closeA4PreviewModal);
        document.getElementById('mouA4PreviewModal')?.addEventListener('click', (e) => {
            if (e.target.id === 'mouA4PreviewModal') {
                closeA4PreviewModal();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const modal = document.getElementById('mouA4PreviewModal');
                if (modal && !modal.classList.contains('hidden')) {
                    closeA4PreviewModal();
                }
            }
        });
        window.addEventListener('resize', () => {
            const modal = document.getElementById('mouA4PreviewModal');
            if (modal && !modal.classList.contains('hidden')) {
                const sel = document.getElementById('mouModalZoomSelect');
                if (sel && (sel.value === 'fit-page' || sel.value === 'fit-width')) {
                    applyModalZoom(sel.value);
                }
            }
            const inlineContainer = document.getElementById('mouMultiSheetPreviewContainer');
            if (inlineContainer && !inlineContainer.classList.contains('hidden')) {
                const inlineSel = document.getElementById('inlinePreviewZoomSelect');
                if (inlineSel && (inlineSel.value === 'fit-page' || inlineSel.value === 'fit-width')) {
                    applyInlineZoom(inlineSel.value);
                }
            }
        });

        // Prevent ribbon buttons and tabs from stealing focus / collapsing ProseMirror text selection
        document.querySelectorAll('.mou-ribbon-btn, .mou-ribbon-tab-btn').forEach(btn => {
            btn.addEventListener('mousedown', (e) => {
                if (btn.tagName !== 'SELECT' && btn.tagName !== 'INPUT') {
                    e.preventDefault();
                }
            });
        });

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

        // Table Context Menu Dropdown
        const tableMenuBtn = document.getElementById('btnTableContextMenuToggle');
        const tableMenuDropdown = document.getElementById('tableContextMenuDropdown');
        if (tableMenuBtn && tableMenuDropdown && tableMenuBtn.dataset.boundMenu !== '1') {
            tableMenuBtn.dataset.boundMenu = '1';
            tableMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const willOpen = tableMenuDropdown.classList.contains('hidden');
                tableMenuDropdown.classList.toggle('hidden', !willOpen);
            });
            tableMenuDropdown.addEventListener('click', (e) => {
                if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                    tableMenuDropdown.classList.add('hidden');
                }
            });
            window.addEventListener('click', () => {
                tableMenuDropdown.classList.add('hidden');
            });
        }

        // Asset Library Modal Integration
        const btnOpenAsset = document.getElementById('btnOpenAssetLibraryModal');
        const assetModal = document.getElementById('mouAssetLibraryModal');
        const btnCloseAsset = document.getElementById('btnCloseAssetLibraryModal');
        const assetGrid = document.getElementById('mouAssetItemsList');
        const assetLoading = document.getElementById('mouAssetLoadingState');
        const assetEmpty = document.getElementById('mouAssetEmptyState');
        const assetSearch = document.getElementById('mouAssetSearchInput');
        const categoryTabs = document.getElementById('mouAssetCategoryTabs');

        let currentCategory = '';
        let currentSearch = '';

        async function fetchSystemAssets() {
            if (!assetGrid) return;
            assetGrid.innerHTML = '';
            assetLoading?.classList.remove('hidden');
            assetEmpty?.classList.add('hidden');

            try {
                const params = new URLSearchParams();
                if (currentCategory) params.append('category', currentCategory);
                if (currentSearch) params.append('search', currentSearch);

                const response = await fetch(`{{ route('admin.system-assets.api.index') }}?${params.toString()}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const resData = await response.json();
                assetLoading?.classList.add('hidden');

                const items = resData.data || [];
                if (items.length === 0) {
                    assetEmpty?.classList.remove('hidden');
                    return;
                }

                items.forEach(asset => {
                    const card = document.createElement('div');
                    card.className = 'bg-white rounded-xl border border-slate-200 p-2.5 flex flex-col justify-between hover:shadow-md transition';

                    const previewUrl = asset.preview_url || `/admin/system-assets/${asset.id}/preview`;
                    card.innerHTML = `
                        <div class="h-24 bg-slate-50 rounded-lg flex items-center justify-center p-2 mb-2 border border-slate-100 overflow-hidden">
                            <img src="${previewUrl}" alt="${asset.name}" class="max-h-full max-w-full object-contain">
                        </div>
                        <div class="mb-2">
                            <span class="inline-block px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-slate-100 text-slate-700">${asset.category_label || asset.category}</span>
                            <div class="text-xs font-semibold text-slate-800 truncate mt-1" title="${asset.name}">${asset.name}</div>
                            <div class="text-[10px] text-slate-400 font-mono truncate">${asset.code || '-'}</div>
                        </div>
                        <button type="button" class="btn-insert-this-asset w-full py-1 text-center text-xs font-bold rounded-lg bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-600 hover:text-white transition">
                            + Sisipkan
                        </button>
                    `;

                    card.querySelector('.btn-insert-this-asset')?.addEventListener('click', () => {
                        window.MouWordProcessor?.insertSystemAsset(asset);
                        closeAssetLibraryModal();
                    });

                    assetGrid.appendChild(card);
                });
            } catch (err) {
                assetLoading?.classList.add('hidden');
                if (assetEmpty) {
                    assetEmpty.textContent = 'Gagal memuat aset: ' + err.message;
                    assetEmpty.classList.remove('hidden');
                }
            }
        }

        function openAssetLibraryModal() {
            if (assetModal) {
                assetModal.classList.remove('hidden');
                fetchSystemAssets();
            }
        }

        function closeAssetLibraryModal() {
            if (assetModal) assetModal.classList.add('hidden');
        }

        btnOpenAsset?.addEventListener('click', openAssetLibraryModal);
        btnCloseAsset?.addEventListener('click', closeAssetLibraryModal);

        categoryTabs?.querySelectorAll('button[data-cat]').forEach(btn => {
            btn.addEventListener('click', () => {
                categoryTabs.querySelectorAll('button[data-cat]').forEach(b => {
                    b.className = 'px-2.5 py-1 rounded-md font-medium text-slate-600 hover:bg-slate-200';
                });
                btn.className = 'px-2.5 py-1 rounded-md font-semibold bg-blue-600 text-white';
                currentCategory = btn.getAttribute('data-cat') || '';
                fetchSystemAssets();
            });
        });

        let searchDebounce;
        assetSearch?.addEventListener('input', (e) => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                currentSearch = e.target.value.trim();
                fetchSystemAssets();
            }, 300);
        });

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (assetModal && !assetModal.classList.contains('hidden')) {
                    closeAssetLibraryModal();
                }
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
        document.getElementById('inputUploadCompanyLogoCustom')?.addEventListener('change', async (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            try {
                await processor.uploadImageFile(file, 'company_logo');
            } catch (err) {
                alert(err.message);
            }
            e.target.value = '';
        });
        document.getElementById('btnInsertCompanyLogo')?.addEventListener('click', () => {
            processor.state.showHeaderLogos = true;
            processor.applyPageLayoutToCanvas();
            processor.syncAllToHiddenInputs();
            const btn = document.getElementById('btnToggleHeaderLogos');
            if (btn) btn.classList.add('mou-ribbon-btn-active');
        });
        document.getElementById('btnToggleHeaderLogos')?.addEventListener('click', (e) => {
            const active = processor.toggleHeaderLogos();
            e.currentTarget.classList.toggle('mou-ribbon-btn-active', active);
        });
        document.getElementById('btnToggleWatermark')?.addEventListener('click', (e) => {
            const active = processor.toggleWatermark();
            e.currentTarget.classList.toggle('mou-ribbon-btn-active', active);
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

        // Table Resize & Styling Controls (with spin box & keyboard navigation)
        document.getElementById('btnApplyTableWidth')?.addEventListener('click', () => {
            const val = document.getElementById('ribbonTableWidthInput')?.value || '100%';
            processor.setCurrentTableWidth(val.trim());
        });
        document.getElementById('ribbonTableWidthInput')?.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp') { e.preventDefault(); processor.adjustTableWidthSpin(5); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); processor.adjustTableWidthSpin(-5); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                const val = e.target.value.trim();
                if (val) processor.setCurrentTableWidth(val);
            }
        });

        document.getElementById('btnApplyColWidth')?.addEventListener('click', () => {
            const val = document.getElementById('ribbonColWidthInput')?.value || '';
            if (val) processor.setCurrentColumnWidth(val);
        });
        document.getElementById('ribbonColWidthInput')?.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp') { e.preventDefault(); processor.adjustColWidthSpin(5); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); processor.adjustColWidthSpin(-5); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                const val = e.target.value.trim();
                if (val) processor.setCurrentColumnWidth(val);
            }
        });

        document.getElementById('btnApplyRowHeight')?.addEventListener('click', () => {
            let val = (document.getElementById('ribbonRowHeightInput')?.value || '').trim();
            if (val && /^\d+(\.\d+)?$/.test(val)) val = `${val}pt`;
            processor.setCurrentRowHeight(val);
        });
        document.getElementById('ribbonRowHeightInput')?.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp') { e.preventDefault(); processor.adjustRowHeightSpin(1); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); processor.adjustRowHeightSpin(-1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                let val = e.target.value.trim();
                if (val && /^\d+(\.\d+)?$/.test(val)) val = `${val}pt`;
                processor.setCurrentRowHeight(val);
            }
        });

        // DOCX Import & Export Listeners
        document.getElementById('btnImportDocxInput')?.addEventListener('change', async (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            try {
                const doReplace = confirm("Import Dokumen Microsoft Word (.docx):\n\nKlik 'OK' untuk MENGGANTI seluruh isi halaman dokumen dengan isi Word,\natau klik 'Batal' untuk MENYISIPKAN isi Word di posisi kursor aktif saat ini.");
                const mode = doReplace ? 'replace' : 'insert';
                const res = await processor.importDocx(file, mode);
                alert(`Dokumen Word berhasil diimpor!\n- Paragraf: ${res.summary.paragraphsCount}\n- Tabel: ${res.summary.tablesCount}\n- Variabel Mail Merge: ${res.summary.placeholdersCount} (${res.summary.placeholders.join(', ') || 'tidak ada'})`);
            } catch (err) {
                alert("Gagal mengimpor file Word: " + err.message);
            }
            e.target.value = '';
        });

        document.getElementById('btnExportDocx')?.addEventListener('click', async () => {
            try {
                const tplName = (document.getElementById('name')?.value || document.querySelector('input[name="name"]')?.value || 'template-dokumen').replace(/[^a-zA-Z0-9_-]/g, '_');
                await processor.exportDocx(`${tplName}.docx`);
            } catch (err) {
                alert("Gagal mengekspor dokumen Word: " + err.message);
            }
        });

        // Status Bar Zoom Listener
        document.getElementById('statusBarZoomSelect')?.addEventListener('change', (e) => {
            processor.setZoomPercent(e.target.value);
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

