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
    .mou-doc-canvas ul, .mou-document ul, #mouBodyMount ul, #mouWordCanvas ul, #mouHeaderCanvas ul, #mouFooterCanvas ul, .ProseMirror ul {
        list-style-type: disc !important;
        padding-left: 24pt !important;
        margin-top: 0 !important;
        margin-bottom: 6pt !important;
    }
    .mou-doc-canvas ul ul, #mouBodyMount ul ul, #mouWordCanvas ul ul, .ProseMirror ul ul {
        list-style-type: circle !important;
    }
    .mou-doc-canvas ul ul ul, #mouBodyMount ul ul ul, #mouWordCanvas ul ul ul, .ProseMirror ul ul ul {
        list-style-type: square !important;
    }
    .mou-doc-canvas ol, .mou-document ol, #mouBodyMount ol, #mouWordCanvas ol, #mouHeaderCanvas ol, #mouFooterCanvas ol, .ProseMirror ol {
        list-style-type: decimal;
        padding-left: 24pt !important;
        margin-top: 0 !important;
        margin-bottom: 6pt !important;
    }
    /* Explicit, case-sensitive list styles (Class-based & data-list-type selectors) */
    ol.mou-list-decimal, ol[data-list-type="1"], ol[type="1" s], .mou-doc-canvas ol.mou-list-decimal, #mouBodyMount ol.mou-list-decimal, #mouWordCanvas ol.mou-list-decimal, .ProseMirror ol.mou-list-decimal,
    ol.mou-list-decimal > li, ol[data-list-type="1"] > li, ol[type="1" s] > li {
        list-style-type: decimal !important;
    }
    ol.mou-list-lower-alpha, ol[data-list-type="a"], ol[type="a" s], .mou-doc-canvas ol.mou-list-lower-alpha, #mouBodyMount ol.mou-list-lower-alpha, #mouWordCanvas ol.mou-list-lower-alpha, .ProseMirror ol.mou-list-lower-alpha,
    ol.mou-list-lower-alpha > li, ol[data-list-type="a"] > li, ol[type="a" s] > li {
        list-style-type: lower-alpha !important;
    }
    ol.mou-list-upper-alpha, ol[data-list-type="A"], ol[type="A" s], .mou-doc-canvas ol.mou-list-upper-alpha, #mouBodyMount ol.mou-list-upper-alpha, #mouWordCanvas ol.mou-list-upper-alpha, .ProseMirror ol.mou-list-upper-alpha,
    ol.mou-list-upper-alpha > li, ol[data-list-type="A"] > li, ol[type="A" s] > li {
        list-style-type: upper-alpha !important;
    }
    ol.mou-list-lower-roman, ol[data-list-type="i"], ol[type="i" s], .mou-doc-canvas ol.mou-list-lower-roman, #mouBodyMount ol.mou-list-lower-roman, #mouWordCanvas ol.mou-list-lower-roman, .ProseMirror ol.mou-list-lower-roman,
    ol.mou-list-lower-roman > li, ol[data-list-type="i"] > li, ol[type="i" s] > li {
        list-style-type: lower-roman !important;
    }
    ol.mou-list-upper-roman, ol[data-list-type="I"], ol[type="I" s], .mou-doc-canvas ol.mou-list-upper-roman, #mouBodyMount ol.mou-list-upper-roman, #mouWordCanvas ol.mou-list-upper-roman, .ProseMirror ol.mou-list-upper-roman,
    ol.mou-list-upper-roman > li, ol[data-list-type="I"] > li, ol[type="I" s] > li {
        list-style-type: upper-roman !important;
    }
    .mou-doc-canvas li, #mouBodyMount li, #mouWordCanvas li, #mouHeaderCanvas li, #mouFooterCanvas li, .ProseMirror li {
        display: list-item !important;
        margin-bottom: 2pt !important;
    }
    .mou-doc-canvas li > p, #mouBodyMount li > p, #mouWordCanvas li > p, .ProseMirror li > p, .mou-document li > p {
        display: block !important;
        margin: 0 !important;
    }
    .mou-header-zone-wrapper.mou-zone-active,
    .mou-footer-zone-wrapper.mou-zone-active,
    .mou-header-zone-wrapper.mou-zone-active *,
    .mou-footer-zone-wrapper.mou-zone-active * {
        user-select: text !important;
    }

    /* Tiptap / ProseMirror Word Studio Styles */
    .mou-word-studio-shell {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 0.75rem;
        overflow: visible;
        box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.06);
        display: flex;
        flex-direction: column;
        position: relative;
        z-index: 10;
    }
    .mou-studio-sticky-header {
        position: sticky;
        top: 4rem; /* 64px - Tepat di bawah batas bawah #admin-topbar fixed */
        z-index: 20; /* Di bawah navbar admin (z-30) dan sidebar admin (z-40) */
        background: #ffffff;
        border-top-left-radius: 0.75rem;
        border-top-right-radius: 0.75rem;
        border-bottom: 1px solid #cbd5e1;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
        flex-shrink: 0;
        transition: top 0.15s ease;
    }
    body.mou-preview-modal-open .mou-studio-sticky-header,
    body.mou-preview-modal-open #mouStudioStickyHeader {
        display: none !important;
        visibility: hidden !important;
    }
    #mouA4PreviewModal {
        position: fixed !important;
        inset: 0 !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        z-index: 99999 !important;
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
    .mou-ribbon-tall-btn {
        min-height: 52px;
        min-width: 50px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 0.25rem 0.45rem;
        border-radius: 0.375rem;
        border: 1px solid transparent;
        background: transparent;
        cursor: pointer;
        transition: all 0.12s ease;
    }
    .mou-ribbon-tall-btn:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }
    .mou-border-painter-mode,
    .mou-border-painter-mode table,
    .mou-border-painter-mode td,
    .mou-border-painter-mode th {
        cursor: crosshair !important;
    }
    .mou-border-painter-mode td:hover,
    .mou-border-painter-mode th:hover {
        outline: 2px dashed #2563eb !important;
        outline-offset: -2px;
    }
    .mou-ribbon-panels-container {
        border-bottom: 1px solid #cbd5e1;
        background-color: #ffffff;
        padding: 0.375rem 0.625rem;
        overflow-y: hidden;
    }
    [data-ribbon-panel] {
        overflow-x: auto;
        overflow-y: hidden !important;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
        min-height: 84px;
    }
    [data-ribbon-panel]::-webkit-scrollbar {
        height: 4px;
    }
    [data-ribbon-panel]::-webkit-scrollbar-track {
        background: transparent;
    }
    [data-ribbon-panel]::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 9999px;
    }
    [data-ribbon-panel]::-webkit-scrollbar-thumb:hover {
        background-color: #94a3b8;
    }

    /* Studio Workspace Row & Viewport */
    .mou-studio-workspace-row {
        position: relative;
        flex: 1 1 auto;
        display: flex;
        overflow: hidden;
        height: clamp(520px, calc(100vh - 210px), 820px);
        min-height: 480px;
        isolation: isolate;
        z-index: 10;
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
        z-index: 1;
        scrollbar-width: thin;
        scrollbar-color: #94a3b8 #cbd5e1;
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
        z-index: 15;
        box-sizing: border-box;
    }
    @media (max-width: 640px) {
        .mou-mail-merge-sidebar {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
            flex: 0 0 100% !important;
            z-index: 25;
            box-shadow: -4px 0 16px rgba(0, 0, 0, 0.2);
        }
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

    /* Horizontal Millimeter Ruler (MS Word Slim Style - 16px) */
    .mou-ruler-sticky-header {
        width: 100%;
        display: block;
        background-color: #e2e8f0 !important;
        border-bottom: 1px solid #cbd5e1;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        user-select: none;
        -webkit-user-select: none;
        height: 16px !important;
        box-sizing: border-box;
        overflow: hidden;
        position: relative;
        z-index: 20;
    }
    .mou-ruler-bar {
        position: absolute;
        top: 0;
        bottom: 0;
        left: 0;
        width: 210mm;
        height: 16px !important;
        background: #ffffff;
        border-left: 1px solid #cbd5e1;
        border-right: 1px solid #cbd5e1;
        overflow: visible;
        user-select: none;
        -webkit-user-select: none;
        box-sizing: border-box;
        cursor: crosshair;
        will-change: transform;
    }
    .mou-ruler-margin-mask {
        position: absolute;
        top: 0;
        bottom: 0;
        background: #cbd5e1;
        pointer-events: none;
        z-index: 1;
    }
    .mou-ruler-ticks-container {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
        z-index: 2;
    }
    .mou-ruler-cm {
        position: absolute;
        top: 0;
        height: 7px;
        border-left: 1px solid #475569;
        font-size: 7.5px;
        font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-weight: 600;
        color: #334155;
        z-index: 2;
    }
    .mou-ruler-cm span {
        position: absolute;
        top: 7px;
        left: 0;
        transform: translateX(-50%);
        font-size: 7.5px;
        color: #475569;
        font-weight: 600;
        line-height: 8px;
    }
    .mou-ruler-half-cm {
        position: absolute;
        top: 0;
        height: 4.5px;
        border-left: 1px solid #64748b;
        z-index: 2;
    }
    .mou-ruler-mm {
        position: absolute;
        top: 0;
        height: 2.5px;
        border-left: 1px solid #94a3b8;
        opacity: 0.65;
        z-index: 2;
    }

    /* Object Span on Ruler (Highlights active object position on ruler like Word) */
    .mou-ruler-object-range {
        position: absolute;
        top: 0;
        bottom: 0;
        background-color: rgba(2, 132, 199, 0.22);
        border-left: 1.5px solid #0284c7;
        border-right: 1.5px solid #0284c7;
        z-index: 3;
        pointer-events: none;
    }

    /* Margin Boundary Resizers (Left & Right) */
    .mou-ruler-margin-handle {
        position: absolute;
        top: 0;
        bottom: 0;
        width: 10px;
        margin-left: -5px;
        cursor: col-resize;
        z-index: 20;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .mou-ruler-handle-line {
        width: 1.5px;
        height: 100%;
        background-color: #64748b;
        transition: background-color 0.15s ease, width 0.15s ease;
    }
    .mou-ruler-margin-handle:hover .mou-ruler-handle-line,
    .mou-ruler-margin-handle.is-dragging .mou-ruler-handle-line {
        background-color: #2563eb;
        width: 2.5px;
    }

    /* Interactive Indent Markers (MS Word Classic Icons) */
    .mou-ruler-markers-layer {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
        z-index: 25;
    }
    .mou-ruler-marker {
        position: absolute;
        pointer-events: auto;
        cursor: ew-resize;
        z-index: 26;
        user-select: none;
        -webkit-user-select: none;
    }
    /* First Line Indent (Top Inverted Triangle ▼) */
    .mou-ruler-first-line-indent {
        top: 0;
        width: 9px;
        height: 6px;
        margin-left: -4.5px;
    }
    /* Hanging Indent (Middle Upward Triangle ▲) */
    .mou-ruler-hanging-indent {
        bottom: 3.5px;
        width: 9px;
        height: 6px;
        margin-left: -4.5px;
    }
    /* Left Indent Box (Bottom Rectangle ▄) */
    .mou-ruler-left-indent-box {
        bottom: 0;
        width: 9px;
        height: 3px;
        margin-left: -4.5px;
        background-color: #334155;
        border: 1px solid #1e293b;
        border-radius: 0.5px;
    }
    .mou-ruler-left-indent-box:hover,
    .mou-ruler-left-indent-box.is-dragging {
        background-color: #2563eb;
        border-color: #1d4ed8;
    }
    /* Right Indent (Bottom Upward Triangle ▲) */
    .mou-ruler-right-indent {
        bottom: 0;
        width: 9px;
        height: 6px;
        margin-left: -4.5px;
    }

    /* Table Column Markers on Ruler */
    .mou-ruler-col-marker {
        position: absolute;
        top: 1px;
        bottom: 1px;
        width: 8px;
        margin-left: -4px;
        cursor: col-resize;
        pointer-events: auto;
        z-index: 22;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: space-between;
    }
    .mou-ruler-col-marker::before,
    .mou-ruler-col-marker::after {
        content: '';
        width: 5px;
        height: 2px;
        background-color: #0284c7;
        border-radius: 0.5px;
    }
    .mou-ruler-col-marker .col-line {
        width: 1.5px;
        height: 100%;
        background-color: #0284c7;
    }

    /* Vertical Alignment Guideline (Garis Pencocokan Presisi) */
    .mou-ruler-guide-line {
        position: absolute;
        top: 0;
        bottom: 0;
        width: 1px;
        border-left: 1px dashed #2563eb;
        background-color: rgba(37, 99, 235, 0.04);
        z-index: 60;
        pointer-events: none;
    }
    .mou-ruler-tooltip-badge {
        position: absolute;
        top: 10px;
        left: 8px;
        background: #0f172a;
        color: #ffffff;
        font-size: 10px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-weight: 600;
        padding: 2.5px 7px;
        border-radius: 4px;
        white-space: nowrap;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.25);
        pointer-events: none;
        border: 1px solid rgba(255, 255, 255, 0.2);
        z-index: 65;
    }

    /* Smart Guides (Panduan Pintar & Penyelarasan Objek seperti MS Word) */
    .mou-smart-guides-container {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
        z-index: 80;
    }
    .mou-smart-guide-line {
        position: absolute;
        pointer-events: none;
        z-index: 85;
    }
    .mou-smart-guide-v {
        top: 0;
        bottom: 0;
        width: 1px;
        border-left: 1.5px dashed #0284c7;
        background-color: rgba(2, 132, 199, 0.08);
    }
    .mou-smart-guide-h {
        left: 0;
        right: 0;
        height: 1px;
        border-top: 1.5px dashed #ec4899;
        background-color: rgba(236, 72, 153, 0.08);
    }
    .mou-smart-guide-label {
        position: absolute;
        background-color: #0f172a;
        color: #38bdf8;
        font-size: 10px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 3px;
        white-space: nowrap;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(56, 189, 248, 0.3);
        pointer-events: none;
    }
    .mou-smart-guide-v .mou-smart-guide-label {
        top: 16px;
        left: 6px;
    }
    .mou-smart-guide-h .mou-smart-guide-label {
        left: 20px;
        top: -20px;
        color: #f472b6;
        border-color: rgba(244, 114, 182, 0.3);
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
        min-height: 28mm;
        max-height: 35mm;
        box-sizing: border-box;
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
        min-height: 28mm;
        max-height: 35mm;
        box-sizing: border-box;
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

    /* ProseMirror Content Area & Selection Support */
    .mou-a4-paper-sheet,
    .mou-a4-paper-sheet *,
    .ProseMirror,
    .ProseMirror * {
        -webkit-user-select: text !important;
        -moz-user-select: text !important;
        -ms-user-select: text !important;
        user-select: text !important;
    }
    .mou-a4-paper-sheet .ProseMirror,
    .ProseMirror,
    .mou-doc-canvas {
        outline: none;
        word-wrap: break-word;
        white-space: pre-wrap !important;
        tab-size: 1.27cm;
        -moz-tab-size: 1.27cm;
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

    /* Selection highlight matching MS Word */
    .ProseMirror ::selection,
    .mou-a4-paper-sheet ::selection {
        background-color: #2563eb !important;
        color: #ffffff !important;
    }

    /* Non-editable overlay elements must not interfere with selection */
    .no-print,
    .mou-page-watermark-layer,
    .mou-watermark-layer,
    .mou-resize-guide-line,
    .mou-header-logos-interactive-stage,
    [data-pagination-artifact="true"],
    .mou-table-width-handle {
        -webkit-user-select: none !important;
        user-select: none !important;
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
        width: 100%;
        max-width: 100% !important;
        box-sizing: border-box;
    }
    .ProseMirror td,
    .ProseMirror th {
        position: relative;
        min-width: 14px;
        box-sizing: border-box;
        word-break: normal;
        overflow-wrap: break-word;
        white-space: pre-wrap;
        tab-size: 1.27cm;
        -moz-tab-size: 1.27cm;
    }
    .ProseMirror table.mou-table-borderless td:first-child,
    .ProseMirror table.mou-table-borderless th:first-child {
        white-space: nowrap !important;
    }

    /* Table Presets & Explicit Right Border Enforcement */
    .mou-table-borderless,
    .ProseMirror table.mou-table-borderless,
    table.mou-table-borderless {
        border: none !important;
        border-collapse: collapse !important;
    }
    .mou-table-borderless td,
    .mou-table-borderless th,
    .ProseMirror table.mou-table-borderless td,
    .ProseMirror table.mou-table-borderless th {
        border: none !important;
    }

    .ProseMirror table.mou-table-bordered,
    .ProseMirror table.mou-correspondence-table:not(.mou-table-borderless),
    .ProseMirror table.mou-paraf-table,
    table.mou-table-bordered,
    table.mou-correspondence-table:not(.mou-table-borderless),
    table.mou-paraf-table {
        border: 1px solid #000000 !important;
        border-collapse: collapse !important;
        box-sizing: border-box !important;
    }
    .ProseMirror table.mou-table-bordered td,
    .ProseMirror table.mou-table-bordered th,
    .ProseMirror table.mou-correspondence-table:not(.mou-table-borderless) td,
    .ProseMirror table.mou-correspondence-table:not(.mou-table-borderless) th,
    .ProseMirror table.mou-paraf-table td,
    .ProseMirror table.mou-paraf-table th,
    table.mou-table-bordered td,
    table.mou-table-bordered th,
    table.mou-correspondence-table:not(.mou-table-borderless) td,
    table.mou-correspondence-table:not(.mou-table-borderless) th,
    table.mou-paraf-table td,
    table.mou-paraf-table th {
        border: 1px solid #000000 !important;
    }
    .ProseMirror table.mou-table-bordered td:last-child,
    .ProseMirror table.mou-table-bordered th:last-child,
    .ProseMirror table.mou-correspondence-table:not(.mou-table-borderless) td:last-child,
    .ProseMirror table.mou-correspondence-table:not(.mou-table-borderless) th:last-child,
    .ProseMirror table.mou-paraf-table td:last-child,
    .ProseMirror table.mou-paraf-table th:last-child,
    table.mou-table-bordered td:last-child,
    table.mou-table-bordered th:last-child,
    table.mou-correspondence-table:not(.mou-table-borderless) td:last-child,
    table.mou-correspondence-table:not(.mou-table-borderless) th:last-child,
    table.mou-paraf-table td:last-child,
    table.mou-paraf-table th:last-child {
        border-right: 1px solid #000000 !important;
    }

    /* Table Gridlines ala MS Word (Garis Kisi Bantu Tabel Tanpa Batas) */
    .mou-table-gridlines-active .ProseMirror table.mou-table-borderless,
    .mou-table-gridlines-active .ProseMirror table[data-table-style="borderless"],
    .mou-table-gridlines-active .ProseMirror table:not(.mou-table-bordered):not(.mou-paraf-table) {
        outline: 1px dashed #94a3b8 !important;
        outline-offset: -1px;
    }
    .mou-table-gridlines-active .ProseMirror table.mou-table-borderless td,
    .mou-table-gridlines-active .ProseMirror table.mou-table-borderless th,
    .mou-table-gridlines-active .ProseMirror table[data-table-style="borderless"] td,
    .mou-table-gridlines-active .ProseMirror table[data-table-style="borderless"] th,
    .mou-table-gridlines-active .ProseMirror td[data-border-style="none"],
    .mou-table-gridlines-active .ProseMirror th[data-border-style="none"],
    .mou-table-gridlines-active .ProseMirror table:not(.mou-table-bordered):not(.mou-paraf-table) td,
    .mou-table-gridlines-active .ProseMirror table:not(.mou-table-bordered):not(.mou-paraf-table) th {
        outline: 1px dashed #94a3b8 !important;
        outline-offset: -1px;
    }

    /* Print & Preview modal MUST NEVER show gridlines */
    @media print {
        .ProseMirror table,
        .ProseMirror td,
        .ProseMirror th,
        .mou-preview-pages-stage table,
        .mou-preview-pages-stage td,
        .mou-preview-pages-stage th {
            outline: none !important;
        }
    }
    .mou-preview-pages-stage table,
    .mou-preview-pages-stage td,
    .mou-preview-pages-stage th,
    .mou-preview-modal-open table,
    .mou-preview-modal-open td,
    .mou-preview-modal-open th {
        outline: none !important;
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
        display: none !important;
    }
    .ProseMirror.resize-cursor {
        cursor: col-resize;
    }
    .mou-table-width-handle {
        position: absolute;
        right: 0px;
        bottom: 0px;
        width: 11px;
        height: 11px;
        background: #2563eb;
        border: 2px solid #ffffff;
        border-radius: 2px;
        cursor: ew-resize;
        opacity: 0;
        transition: opacity 0.15s ease;
        z-index: 25;
        box-shadow: 0 1px 3px rgba(0,0,0,0.25);
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
        z-index: 62 !important;
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
        pointer-events: auto !important;
        box-sizing: border-box;
        z-index: 65 !important;
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
        z-index: 68 !important;
    }

    /* Rotator Handle */
    .mou-image-rotator-wrapper {
        position: absolute;
        top: -24px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        flex-direction: column;
        align-items: center;
        pointer-events: auto !important;
        z-index: 66 !important;
    }
    .mou-image-rotator-line {
        width: 1.5px;
        height: 14px;
        background: #2563eb;
    }
    .mou-image-rotator-handle {
        width: 12px;
        height: 12px;
        background: #ffffff;
        border: 2px solid #2563eb;
        border-radius: 50%;
        cursor: grab;
        pointer-events: auto !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.25);
        transition: transform 0.1s ease, background-color 0.1s ease;
    }
    .mou-image-rotator-handle:hover,
    .mou-image-rotator-handle:active {
        background: #2563eb;
        cursor: grabbing;
        transform: scale(1.25);
    }

    /* Interactive Floating Header Logos Layer (Tier 1 Priority) */
    .mou-header-logos-interactive-stage,
    .mou-header-logos-interactive-stage *,
    .mou-header-logo-interactive-box,
    .mou-header-logo-interactive-box * {
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
        user-select: none !important;
        -webkit-user-drag: none !important;
    }
    .mou-header-logos-interactive-stage {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 15;
        box-sizing: border-box;
    }
    .mou-header-logo-interactive-box {
        position: absolute;
        pointer-events: auto;
        cursor: default;
        touch-action: none !important;
        line-height: 0;
        box-sizing: border-box;
        border: 1px dashed transparent;
        max-width: 105px !important;
        max-height: 105px !important;
        z-index: 15;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    /* When header mode is NOT active, logo is strictly locked & non-interactive */
    .mou-header-logos-interactive-stage:not(.is-header-active) .mou-header-logo-interactive-box {
        cursor: default !important;
        border-color: transparent !important;
        box-shadow: none !important;
        outline: none !important;
    }
    .mou-header-logos-interactive-stage:not(.is-header-active) .mou-image-handle-overlay,
    .mou-header-logos-interactive-stage:not(.is-header-active) .mou-header-logo-quick-bar {
        display: none !important;
    }

    /* ONLY when Header Mode is ACTIVE: Header Logo becomes fully editable */
    .mou-header-logos-interactive-stage.is-header-active {
        pointer-events: auto;
        z-index: 55 !important;
    }
    .mou-header-logos-interactive-stage.is-header-active .mou-header-logo-interactive-box {
        pointer-events: auto !important;
        cursor: grab !important;
        z-index: 56 !important;
    }
    .mou-header-logos-interactive-stage.is-header-active .mou-header-logo-interactive-box:hover {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.4) !important;
    }
    .mou-header-logos-interactive-stage.is-header-active .mou-header-logo-interactive-box.is-selected {
        outline: 2px solid #2563eb !important;
        outline-offset: 1px;
        z-index: 65 !important;
    }
    .mou-header-logos-interactive-stage.is-header-active .mou-header-logo-interactive-box.is-dragging {
        cursor: grabbing !important;
        z-index: 75 !important;
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35) !important;
        transition: none !important;
    }
    .mou-header-logo-interactive-box .mou-logo-img-el {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
        pointer-events: none;
    }
    .mou-header-logo-quick-bar {
        position: absolute;
        bottom: -28px;
        left: 50%;
        transform: translateX(-50%);
        display: none;
        align-items: center;
        gap: 3px;
        background: #0f172a;
        color: #f8fafc;
        padding: 2px 6px;
        border-radius: 5px;
        font-size: 9px;
        font-weight: 600;
        white-space: nowrap;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3);
        z-index: 75 !important;
        pointer-events: auto;
    }
    .mou-header-logo-interactive-box.is-selected .mou-header-logo-quick-bar {
        display: flex;
    }
    .mou-header-logo-quick-bar button {
        background: #334155;
        color: #f8fafc;
        border: none;
        border-radius: 3px;
        padding: 2px 5px;
        font-size: 9px;
        cursor: pointer;
        transition: background-color 0.12s ease;
    }
    .mou-header-logo-quick-bar button:hover {
        background: #2563eb;
        color: #ffffff;
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
<input type="hidden" name="header_logos_config" id="inputHeaderLogosConfig" value="{{ json_encode($cfg['header_logos_config'] ?? []) }}">
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
        <!-- Top Ribbon Header: Tabs & Quick Utilities Bar -->
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-100 px-3 py-1 min-h-[44px]">
            <!-- Sisi Kiri: Tab Navigasi Ribbon -->
            <div class="flex items-center gap-0.5 overflow-x-auto shrink-0">
                <button type="button" data-ribbon-tab="home" class="mou-ribbon-tab-btn active">Beranda</button>
                <button type="button" data-ribbon-tab="insert" class="mou-ribbon-tab-btn">Sisipkan</button>
                <button type="button" data-ribbon-tab="layout" class="mou-ribbon-tab-btn">Tata Letak</button>
                <button type="button" data-ribbon-tab="table" class="mou-ribbon-tab-btn inline-flex items-center gap-1.5">
                    <span>Desain &amp; Tata Letak Tabel</span>
                    <span id="ribbonTableContextBadge" class="hidden rounded bg-blue-600 px-1.5 py-0.5 text-[10px] font-bold uppercase text-white">Aktif</span>
                </button>
                <button type="button" data-ribbon-tab="headerfooter" class="mou-ribbon-tab-btn">Header &amp; Footer</button>
            </div>

            <!-- Sisi Kanan: Mail Merge Toggle & Mode Switcher -->
            <div class="flex items-center gap-2 shrink-0">
                <!-- Tombol Akses Cepat Variabel Mail Merge -->
                <button
                    type="button"
                    id="btnTogglePlaceholderDrawer"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-blue-300 bg-white px-2.5 py-1 text-xs font-semibold text-blue-700 shadow-2xs hover:bg-blue-50 hover:border-blue-400 transition"
                    title="Buka / Tutup Panel Variabel Dinamis (Mail Merge)"
                >
                    <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>
                    </svg>
                    <span>Variabel Mail Merge</span>
                    <span class="inline-flex items-center justify-center rounded-full bg-blue-100 text-blue-800 text-[10px] font-bold px-1.5 py-0.2">
                        {{ count($placeholders) }}
                    </span>
                </button>

                <!-- Quick Ruler Toggle Button -->
                <button
                    type="button"
                    id="btnQuickToggleRuler"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 hover:border-slate-400 transition"
                    title="Tampilkan / Sembunyikan Penggaris & Garis Penyelarasan (MS Word Ruler)"
                >
                    <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M2 12h20M2 12v4M6 12v2M10 12v3M14 12v2M18 12v3M22 12v4"/>
                        <rect x="2" y="7" width="20" height="10" rx="1"/>
                    </svg>
                    <span>Penggaris (Ruler)</span>
                </button>

                <!-- Quick Table Gridlines Toggle Button (ala MS Word View Gridlines) -->
                <button
                    type="button"
                    id="btnQuickToggleGridlines"
                    onclick="window.MouWordProcessor?.toggleTableGridlines()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-blue-500 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 shadow-2xs hover:bg-blue-100 transition"
                    title="Tampilkan / Sembunyikan Garis Kisi Tabel Tanpa Batas (MS Word Table Gridlines)"
                >
                    <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="3 3"/>
                        <line x1="3" y1="9" x2="21" y2="9" stroke-dasharray="2 2"/>
                        <line x1="3" y1="15" x2="21" y2="15" stroke-dasharray="2 2"/>
                        <line x1="9" y1="3" x2="9" y2="21" stroke-dasharray="2 2"/>
                        <line x1="15" y1="3" x2="15" y2="21" stroke-dasharray="2 2"/>
                    </svg>
                    <span>Garis Kisi</span>
                </button>

                <!-- Mode Switcher -->
                <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5 shadow-2xs">
                    <button
                        type="button"
                        id="btnModeEdit"
                        class="rounded-md bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white shadow-xs"
                    >
                        Editor Word
                    </button>
                    <button
                        type="button"
                        id="btnModePreview"
                        class="rounded-md px-2.5 py-1 text-xs font-semibold text-slate-600 hover:text-slate-900 transition"
                    >
                        Pratinjau Lembar A4
                    </button>
                </div>
            </div>
        </div>

        <!-- RIBBON COMMAND PANELS -->
        <div class="mou-ribbon-panels-container">
            <!-- TAB 1: BERANDA (HOME) -->
            <div id="ribbonPanel-home" data-ribbon-panel="home" class="flex flex-nowrap items-stretch gap-x-2 overflow-x-auto pb-1">
                <!-- Group: Riwayat & Dokumen -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <button type="button" id="btnRibbonUndo" onclick="window.MouWordProcessor?.undo()" class="mou-ribbon-btn" title="Urungkan (Ctrl+Z)">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                        </button>
                        <button type="button" id="btnRibbonRedo" onclick="window.MouWordProcessor?.redo()" class="mou-ribbon-btn" title="Ulangi (Ctrl+Y)">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/></svg>
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.clearFormatting()" class="mou-ribbon-btn" title="Bersihkan Pemformatan Teks (Ctrl+Space / Ctrl+\)">
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none">
                                <path d="M3.5 17L8 4.5h1L13.5 17" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M5.5 12h6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                                <path d="m14 17.5 4-4a1.8 1.8 0 0 1 2.5 0l1 1a1.8 1.8 0 0 1 0 2.5l-4 4a1.8 1.8 0 0 1-2.5 0l-1-1a1.8 1.8 0 0 1 0-2.5z" fill="#f43f5e" stroke="#be123c" stroke-width="1.2" stroke-linejoin="round"/>
                                <path d="m17.5 14 3 3" stroke="#ffffff" stroke-width="1.2" stroke-linecap="round"/>
                                <path d="M12 21h8" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
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
                        <button type="button" onclick="window.MouWordProcessor?.clearFormatting()" class="mou-ribbon-btn" title="Bersihkan Pemformatan Teks (Ctrl+Space / Ctrl+\)">
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none">
                                <path d="M3.5 17L8 4.5h1L13.5 17" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M5.5 12h6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                                <path d="m14 17.5 4-4a1.8 1.8 0 0 1 2.5 0l1 1a1.8 1.8 0 0 1 0 2.5l-4 4a1.8 1.8 0 0 1-2.5 0l-1-1a1.8 1.8 0 0 1 0-2.5z" fill="#f43f5e" stroke="#be123c" stroke-width="1.2" stroke-linejoin="round"/>
                                <path d="m17.5 14 3 3" stroke="#ffffff" stroke-width="1.2" stroke-linecap="round"/>
                                <path d="M12 21h8" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                        </button>

                        <label class="inline-flex items-center gap-1.5 px-1.5 py-0.5 rounded border border-slate-200 bg-slate-50 hover:bg-slate-100 text-xs text-slate-700 ml-1 cursor-pointer transition-colors" title="Warna Teks (Pilih Warna Font)">
                            <span class="font-bold underline decoration-2 decoration-current">A</span>
                            <input type="color" id="ribbonTextColor" value="#000000" class="h-5 w-5 cursor-pointer rounded border border-slate-300 p-0 bg-transparent">
                        </label>

                        <label class="inline-flex items-center gap-1.5 px-1.5 py-0.5 rounded border border-slate-200 bg-slate-50 hover:bg-slate-100 text-xs text-slate-700 ml-0.5 cursor-pointer transition-colors" title="Warna Sorotan (Highlight Stabilo)">
                            <span class="bg-yellow-200 text-slate-900 px-1 py-0.5 rounded font-semibold text-[11px] leading-tight">ab</span>
                            <input type="color" id="ribbonHighlightColor" value="#fef08a" class="h-5 w-5 cursor-pointer rounded border border-slate-300 p-0 bg-transparent">
                        </label>
                        <button type="button" id="btnRemoveHighlight" onclick="window.MouWordProcessor?.removeHighlight()" class="mou-ribbon-btn text-slate-600 hover:text-rose-600 hover:bg-rose-50" title="Hapus Sorotan Teks (Tanpa Warna)">
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none">
                                <path d="m9 11-6 6v3h3l6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <line x1="2" y1="22" x2="6" y2="22" stroke="#facc15" stroke-width="2.5" stroke-linecap="round"/>
                                <line x1="4" y1="4" x2="20" y2="20" stroke="#e11d48" stroke-width="2.2" stroke-linecap="round"/>
                            </svg>
                        </button>
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

                        <!-- Pengaturan Spasi Antar Baris Paragraf (Line Spacing) ala MS Word Custom Dropdown -->
                        <div class="relative inline-flex items-center">
                            <button type="button" id="btnParagraphLineHeightMenuToggle" class="h-[2.125rem] px-2 flex items-center gap-1.5 border border-slate-300 rounded-md bg-white shadow-xs hover:bg-slate-50 text-slate-700 font-medium text-xs transition focus:outline-none select-none" title="Jarak Spasi Antar Baris Paragraf (Line Spacing)">
                                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M7 3v18"/>
                                    <path d="m10 6-3-3-3 3"/>
                                    <path d="m10 18-3 3-3-3"/>
                                    <line x1="14" y1="6" x2="21" y2="6"/>
                                    <line x1="14" y1="12" x2="21" y2="12"/>
                                    <line x1="14" y1="18" x2="21" y2="18"/>
                                </svg>
                                <span id="ribbonLineHeightActiveLabel">Spasi 1.0</span>
                                <svg class="h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <select id="ribbonParagraphLineHeight" class="hidden" title="Pilih Jarak Spasi Baris Paragraf">
                                <option value="1" selected>Spasi 1.0</option>
                                <option value="1.5">Spasi 1.5</option>
                                <option value="2">Spasi 2.0</option>
                                <option value="2.5">Spasi 2.5</option>
                                <option value="3">Spasi 3.0</option>
                            </select>

                            <!-- Menu Dropdown Pilihan Spasi Baris Paragraf (Floating Word-style) -->
                            <div id="paragraphLineHeightDropdown" class="hidden fixed w-48 rounded-xl shadow-2xl bg-white border border-slate-200 ring-1 ring-black/5 divide-y divide-slate-100 p-1.5 text-xs select-none" style="z-index: 99999;">
                                <div class="px-2 py-1 text-[10px] font-bold text-slate-400 uppercase tracking-wider">Jarak Spasi Baris</div>
                                <div class="p-1 space-y-0.5">
                                    <button type="button" data-line-height="1" class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-3.5 w-3.5 text-blue-600 opacity-0 group-[.is-active]:opacity-100 transition-opacity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span class="font-medium">Spasi 1.0</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Tunggal</span>
                                    </button>
                                    <button type="button" data-line-height="1.5" class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-3.5 w-3.5 text-blue-600 opacity-0 group-[.is-active]:opacity-100 transition-opacity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span class="font-medium">Spasi 1.5</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 group-hover:text-blue-500">1.5 Baris</span>
                                    </button>
                                    <button type="button" data-line-height="2" class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-3.5 w-3.5 text-blue-600 opacity-0 group-[.is-active]:opacity-100 transition-opacity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span class="font-medium">Spasi 2.0</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Ganda</span>
                                    </button>
                                    <button type="button" data-line-height="2.5" class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-3.5 w-3.5 text-blue-600 opacity-0 group-[.is-active]:opacity-100 transition-opacity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span class="font-medium">Spasi 2.5</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Lebar</span>
                                    </button>
                                    <button type="button" data-line-height="3" class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-3.5 w-3.5 text-blue-600 opacity-0 group-[.is-active]:opacity-100 transition-opacity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span class="font-medium">Spasi 3.0</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Ekstra</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Home Tab Borders Dropdown Trigger (ala MS Word Paragraph Borders) -->
                        <div class="relative inline-flex items-center">
                            <button
                                type="button"
                                id="btnHomeBordersDropdownToggle"
                                class="h-[2.125rem] px-2 flex items-center gap-1 border border-slate-300 rounded-md bg-white shadow-xs hover:bg-slate-50 text-slate-700 font-medium text-xs transition focus:outline-none select-none"
                                title="Batas Garis Tabel & Paragraf (MS Word Borders)"
                            >
                                <svg class="h-3.5 w-3.5 text-slate-700" viewBox="0 0 16 16" fill="none">
                                    <rect x="1.5" y="1.5" width="13" height="13" stroke="currentColor" stroke-width="1.5"/>
                                    <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="currentColor" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                                    <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="currentColor" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                                </svg>
                                <svg class="h-2.5 w-2.5 text-slate-500" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-1 mt-1.5">
                        <button type="button" id="btnToggleBulletList" onclick="window.MouWordProcessor?.toggleBulletList()" class="mou-ribbon-btn" title="Daftar Poin (Bullet List)">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="9" y1="6" x2="20" y2="6"/>
                                <line x1="9" y1="12" x2="20" y2="12"/>
                                <line x1="9" y1="18" x2="20" y2="18"/>
                                <circle cx="4" cy="6" r="1.5" fill="currentColor"/>
                                <circle cx="4" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="4" cy="18" r="1.5" fill="currentColor"/>
                            </svg>
                            <span>Poin</span>
                        </button>
                        <!-- Word-style Split Button untuk Format Penomoran (Numbering) -->
                        <div class="relative inline-flex items-center rounded-md border border-slate-300 bg-white shadow-xs">
                            <button type="button" id="btnToggleOrderedList" onclick="window.MouWordProcessor?.toggleOrderedList()" class="mou-ribbon-btn border-0 shadow-none rounded-r-none pr-1.5 focus:outline-none" title="Aktifkan / Nonaktifkan Penomoran (Numbered List)">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="10" y1="6" x2="21" y2="6"/>
                                    <line x1="10" y1="12" x2="21" y2="12"/>
                                    <line x1="10" y1="18" x2="21" y2="18"/>
                                    <path d="M4 6h1v4"/>
                                    <path d="M4 10h2"/>
                                </svg>
                                <span id="ribbonOrderedListActiveLabel">1. Nomor</span>
                            </button>
                            <button type="button" id="btnOrderedListMenuToggle" class="h-[2.125rem] px-1.5 flex items-center justify-center text-slate-500 hover:text-slate-800 hover:bg-slate-100 border-l border-slate-200 rounded-r-md transition focus:outline-none" title="Pilihan Gaya Penomoran (Angka, Huruf, Romawi)">
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <select id="ribbonOrderedListStyle" class="hidden" title="Format Penomoran Daftar">
                                <option value="1">1, 2, 3</option>
                                <option value="a">a, b, c</option>
                                <option value="A">A, B, C</option>
                                <option value="i">i, ii, iii</option>
                                <option value="I">I, II, III</option>
                            </select>

                            <!-- Menu Dropdown Pilihan Gaya Penomoran (Floating Word-style) -->
                            <div id="orderedListStyleDropdown" class="hidden fixed w-60 rounded-xl shadow-2xl bg-white border border-slate-200 ring-1 ring-black/5 divide-y divide-slate-100 p-1.5 text-xs select-none" style="z-index: 99999;">
                                <div class="px-2 py-1 text-[10px] font-bold text-slate-400 uppercase tracking-wider">Format Penomoran</div>
                                <div class="p-1 space-y-0.5">
                                    <button type="button" data-list-type="1" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-slate-100 group-hover:bg-blue-100 font-mono font-bold text-xs text-slate-800 group-hover:text-blue-700 shrink-0">1.</span>
                                        <div class="flex flex-col">
                                            <span class="font-medium text-xs">1, 2, 3</span>
                                            <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Angka Biasa</span>
                                        </div>
                                    </button>
                                    <button type="button" data-list-type="a" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-slate-100 group-hover:bg-blue-100 font-mono font-bold text-xs text-slate-800 group-hover:text-blue-700 shrink-0">a.</span>
                                        <div class="flex flex-col">
                                            <span class="font-medium text-xs">a, b, c</span>
                                            <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Huruf Kecil</span>
                                        </div>
                                    </button>
                                    <button type="button" data-list-type="A" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-slate-100 group-hover:bg-blue-100 font-mono font-bold text-xs text-slate-800 group-hover:text-blue-700 shrink-0">A.</span>
                                        <div class="flex flex-col">
                                            <span class="font-medium text-xs">A, B, C</span>
                                            <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Huruf Besar / Kapital</span>
                                        </div>
                                    </button>
                                    <button type="button" data-list-type="i" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-slate-100 group-hover:bg-blue-100 font-mono font-bold text-xs text-slate-800 group-hover:text-blue-700 shrink-0">i.</span>
                                        <div class="flex flex-col">
                                            <span class="font-medium text-xs">i, ii, iii</span>
                                            <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Romawi Kecil</span>
                                        </div>
                                    </button>
                                    <button type="button" data-list-type="I" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded-lg text-left text-slate-700 transition group">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-slate-100 group-hover:bg-blue-100 font-mono font-bold text-xs text-slate-800 group-hover:text-blue-700 shrink-0">I.</span>
                                        <div class="flex flex-col">
                                            <span class="font-medium text-xs">I, II, III</span>
                                            <span class="text-[10px] text-slate-400 group-hover:text-blue-500">Romawi Besar / Kapital</span>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="btnRibbonOutdent" onclick="window.MouWordProcessor?.adjustIndent(-6)" class="mou-ribbon-btn" title="Kurangi Indentasi Kiri (Outdent / Shift+Tab)">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="21" y1="4" x2="11" y2="4"/>
                                <line x1="21" y1="12" x2="11" y2="12"/>
                                <line x1="21" y1="20" x2="11" y2="20"/>
                                <polyline points="7 8 3 12 7 16"/>
                            </svg>
                            <span>&larr; Indent</span>
                        </button>
                        <button type="button" id="btnRibbonIndent" onclick="window.MouWordProcessor?.adjustIndent(6)" class="mou-ribbon-btn" title="Tambah Indentasi Kiri (Indent / Tab)">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="21" y1="4" x2="11" y2="4"/>
                                <line x1="21" y1="12" x2="11" y2="12"/>
                                <line x1="21" y1="20" x2="11" y2="20"/>
                                <polyline points="3 8 7 12 3 16"/>
                            </svg>
                            <span>Indent &rarr;</span>
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
                <!-- Group 1: Tabel -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-1.5">
                        <button type="button" id="btnTableContextMenuToggle" class="mou-ribbon-btn inline-flex items-center gap-1 border-blue-200 bg-blue-50 text-blue-700 font-semibold px-2.5 text-xs hover:bg-blue-100" title="Menu Pengelolaan &amp; Opsi Tabel Lengkap">
                            <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
                            <span>Tabel</span>
                            <svg class="h-3 w-3 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                        <div class="flex items-center gap-1 text-[11px] font-semibold text-slate-600 bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">
                            <input type="number" id="insertTableRows" value="3" min="1" max="30" class="h-6 w-9 rounded border border-slate-300 px-1 text-xs text-center font-bold" title="Jumlah Baris">
                            <span>&times;</span>
                            <input type="number" id="insertTableCols" value="2" min="1" max="12" class="h-6 w-9 rounded border border-slate-300 px-1 text-xs text-center font-bold" title="Jumlah Kolom">
                            <button type="button" id="btnInsertCustomTable" class="ml-0.5 px-1.5 py-0.5 rounded bg-blue-600 text-white text-[11px] font-semibold hover:bg-blue-700 transition" title="Sisipkan Tabel Sesuai Baris x Kolom">
                                + Buat
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 mt-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.insertTable(2, 2, false)" class="mou-ribbon-btn border-slate-200 bg-slate-50 px-2 text-xs" title="Sisipkan Tabel 2 Baris x 2 Kolom">2&times;2</button>
                        <button type="button" onclick="window.MouWordProcessor?.insertTable(4, 3, false)" class="mou-ribbon-btn border-slate-200 bg-slate-50 px-2 text-xs" title="Sisipkan Tabel 4 Baris x 3 Kolom">4&times;3</button>
                        <button type="button" onclick="window.MouWordProcessor?.insertTable(4, 2, false)" class="mou-ribbon-btn border-slate-200 bg-slate-50 px-2 text-xs font-semibold text-slate-700" title="Sisipkan Tabel Tanda Tangan (4x2)">TTD (4&times;2)</button>
                    </div>
                    <div class="mou-ribbon-group-label">Tabel Bebas</div>

                    <!-- Menu Dropdown Tabel Kontekstual (Fixed Floating) -->
                    <div id="tableContextMenuDropdown" class="hidden fixed w-64 rounded-xl shadow-2xl bg-white border border-slate-200 ring-1 ring-black/5 divide-y divide-slate-100 p-1.5 text-xs" style="z-index: 99999;">
                        <div class="p-1">
                            <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase tracking-wider">Sisipkan Tabel Baru</span>
                            <button type="button" onclick="window.MouWordProcessor?.insertTable(2, 2, false)" class="w-full text-left px-2 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded transition text-slate-700">Tabel 2 &times; 2</button>
                            <button type="button" onclick="window.MouWordProcessor?.insertTable(3, 3, false)" class="w-full text-left px-2 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded transition text-slate-700">Tabel 3 &times; 3</button>
                            <button type="button" onclick="window.MouWordProcessor?.insertTable(4, 2, false)" class="w-full text-left px-2 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded transition text-slate-700 font-semibold">Tabel Tanda Tangan (4 &times; 2)</button>
                        </div>
                        <div class="p-1">
                            <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase tracking-wider">Baris &amp; Kolom</span>
                            <button type="button" onclick="window.MouWordProcessor?.addRowBefore()" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">+ Tambah Baris Di Atas</button>
                            <button type="button" onclick="window.MouWordProcessor?.addRowAfter()" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">+ Tambah Baris Di Bawah</button>
                            <button type="button" onclick="window.MouWordProcessor?.addColumnBefore()" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">+ Tambah Kolom Di Kiri</button>
                            <button type="button" onclick="window.MouWordProcessor?.addColumnAfter()" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">+ Tambah Kolom Di Kanan</button>
                        </div>
                        <div class="p-1">
                            <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase tracking-wider">Batas Garis Tabel</span>
                            <button type="button" onclick="window.MouWordProcessor?.setTableBorders('borderless'); document.getElementById('tableContextMenuDropdown')?.classList.add('hidden');" class="w-full text-left px-2 py-1.5 hover:bg-blue-50 hover:text-blue-700 rounded text-slate-700 font-medium">Tanpa Batas (Borderless)</button>
                            <button type="button" onclick="window.MouWordProcessor?.setTableBorders('all'); document.getElementById('tableContextMenuDropdown')?.classList.add('hidden');" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">Semua Batas (All Borders)</button>
                            <button type="button" onclick="window.MouWordProcessor?.setTableBorders('outside'); document.getElementById('tableContextMenuDropdown')?.classList.add('hidden');" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">Batas Luar (Outside)</button>
                        </div>
                        <div class="p-1">
                            <span class="block px-2 py-0.5 text-[9px] font-bold text-slate-400 uppercase tracking-wider">Manipulasi Sel &amp; Hapus</span>
                            <button type="button" onclick="window.MouWordProcessor?.mergeCells()" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">Gabung Sel (Merge)</button>
                            <button type="button" onclick="window.MouWordProcessor?.splitCell()" class="w-full text-left px-2 py-1.5 hover:bg-slate-100 rounded text-slate-700">Pisah Sel (Split)</button>
                            <button type="button" onclick="window.MouWordProcessor?.deleteRow()" class="w-full text-left px-2 py-1.5 hover:bg-rose-50 text-rose-600 rounded">Hapus Baris</button>
                            <button type="button" onclick="window.MouWordProcessor?.deleteColumn()" class="w-full text-left px-2 py-1.5 hover:bg-rose-50 text-rose-600 rounded">Hapus Kolom</button>
                            <button type="button" onclick="window.MouWordProcessor?.deleteTable()" class="w-full text-left px-2 py-1.5 hover:bg-rose-100 text-rose-700 rounded font-bold">Hapus Seluruh Tabel</button>
                        </div>
                    </div>
                </div>

                <!-- Group 2: Media & Aset -->
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
                        <label class="mou-ribbon-btn border-slate-200 bg-slate-50 cursor-pointer" title="Unggah Logo Institusi (Tetap / Sudut Kiri Atas)">
                            <span>Logo Institusi</span>
                            <input type="file" id="inputUploadInstitutionLogo" accept="image/png,image/jpeg,image/webp" class="hidden">
                        </label>
                        <button type="button" id="btnInsertCompanyLogo" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Tetapkan Logo Seven Inc (Tetap / Sudut Kanan Atas)">
                            Logo Seven Inc
                        </button>
                        @endif
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        @if($isMou)
                        <button type="button" id="btnToggleHeaderLogos" class="mou-ribbon-btn border-slate-200 bg-slate-50 {{ ($cfg['show_header_logos'] ?? true) ? 'mou-ribbon-btn-active' : '' }}" title="Tampilkan / Sembunyikan Logo Tetap di Sudut Atas Kertas">
                            Logo Header
                        </button>
                        @endif
                        <button type="button" id="btnToggleWatermark" class="mou-ribbon-btn border-slate-200 bg-slate-50 {{ ($cfg['show_watermark'] ?? true) ? 'mou-ribbon-btn-active' : '' }}" title="Aktifkan / Nonaktifkan Watermark Latar Tengah Kertas">
                            Watermark Tengah
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">{{ $isMou ? 'Logo & Sisip Media' : 'Watermark & Media' }}</div>
                </div>

                <!-- Group 3: Format Gambar, Ukuran & Rotasi -->
                <div class="mou-ribbon-group bg-blue-50/40 rounded-lg px-2">
                    <div class="flex items-center gap-1.5">
                        <label class="text-[10px] font-semibold text-slate-600">Lebar:</label>
                        <input type="number" id="ribbonImageWidthPx" placeholder="100" min="20" max="640" step="5" class="h-[28px] w-14 rounded border border-slate-300 px-1 text-xs text-center focus:border-blue-500 focus:outline-none" title="Lebar gambar (px)">
                        <span class="text-[10px] text-slate-400">px</span>

                        <label class="text-[10px] font-semibold text-slate-600 ml-1">Tinggi:</label>
                        <input type="number" id="ribbonImageHeightPx" placeholder="Auto" min="20" max="800" step="5" class="h-[28px] w-14 rounded border border-slate-300 px-1 text-xs text-center focus:border-blue-500 focus:outline-none" title="Tinggi gambar (px)">
                        <span class="text-[10px] text-slate-400">px</span>

                        <button type="button" id="btnApplyImageSize" class="mou-ribbon-btn border-blue-300 bg-blue-600 text-white font-medium hover:bg-blue-700" title="Terapkan ukuran">Set</button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <label class="text-[10px] font-semibold text-slate-600">Putar:</label>
                        <input type="number" id="ribbonImageRotationDeg" placeholder="0" min="0" max="360" step="5" class="h-[28px] w-12 rounded border border-slate-300 px-1 text-xs text-center focus:border-blue-500 focus:outline-none" title="Sudut putar (0-360 derajat)">
                        <span class="text-[10px] text-slate-400">&deg;</span>

                        <button type="button" id="btnRotateImage90" class="mou-ribbon-btn border-slate-200 bg-slate-50 hover:bg-slate-100" title="Putar 90 Derajat">&#8635; 90&deg;</button>
                        <button type="button" id="btnResetImageAsset" class="mou-ribbon-btn border-slate-200 bg-slate-50 hover:bg-slate-100" title="Reset Ukuran &amp; Rotasi Normal">Reset</button>
                        <button type="button" id="btnDeleteSelectedImage" class="mou-ribbon-btn border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100" title="Hapus Gambar Terpilih">Hapus</button>
                    </div>
                    <div class="mou-ribbon-group-label">Format Gambar &amp; Rotasi</div>
                </div>

                <!-- Group 4: Variabel Mail Merge (Dedicated Group) -->
                <div class="mou-ribbon-group bg-blue-50/30 rounded-lg px-2.5 border-r border-blue-100">
                    <div class="flex items-center gap-1.5">
                        <select id="ribbonQuickPlaceholderSelect" class="mou-ribbon-select min-w-[200px] max-w-[270px]">
                            <option value="">-- Pilih Variabel Mail Merge --</option>
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
                        <button type="button" id="btnInsertSelectedPlaceholder" class="mou-ribbon-btn border-blue-300 bg-blue-600 text-white font-medium hover:bg-blue-700" title="Sisipkan variabel terpilih ke kursor">
                            + Sisipkan
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <button
                            type="button"
                            onclick="document.getElementById('btnTogglePlaceholderDrawer')?.click()"
                            class="mou-ribbon-btn border-blue-200 bg-white text-blue-700 hover:bg-blue-50 w-full justify-center text-xs font-semibold shadow-2xs"
                            title="Buka sidebar kanan untuk mencari dan melihat seluruh variabel lengkap"
                        >
                            <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>
                            </svg>
                            <span>Buka Panel Variabel Lengkap (Drawer)</span>
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label text-blue-700 font-bold">Variabel Mail Merge</div>
                </div>

                <!-- Group 5: Elemen Dokumen & Simbol -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="window.MouWordProcessor?.insertTextBox()" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Sisipkan Kotak Teks">
                            + Kotak Teks
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.insertPageBreak()" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Pemisah Halaman Baru">
                            + Pemisah Halaman
                        </button>
                        <button type="button" onclick="window.MouWordProcessor?.insertHorizontalRule()" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Garis Batas Horizontal">
                            + Garis Horizontal
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <button type="button" id="btnInsertHyperlink" class="mou-ribbon-btn border-slate-200 bg-slate-50" title="Sisipkan Tautan / Hyperlink">
                            + Tautan (Link)
                        </button>
                        <select id="ribbonQuickSymbolSelect" class="mou-ribbon-select min-w-[130px]" title="Sisipkan Simbol Hukum / Karakter Khusus">
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
                    <div class="mou-ribbon-group-label">Elemen &amp; Simbol</div>
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
                <div class="mou-ribbon-group">
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

                <!-- Group: Garis Bantu & Penggaris (Ruler) -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-1.5 cursor-pointer text-xs font-semibold text-slate-700 select-none py-1 px-2 rounded-md hover:bg-slate-100 transition-colors" title="Tampilkan atau sembunyikan garis penggaris horizontal di atas halaman">
                            <input type="checkbox" id="ribbonToggleRuler" checked class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 h-4 w-4">
                            <span>Penggaris (Ruler)</span>
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer text-xs font-semibold text-slate-700 select-none py-1 px-2 rounded-md hover:bg-slate-100 transition-colors" title="Tampilkan atau sembunyikan garis kisi pada tabel tanpa batas (MS Word View Gridlines)">
                            <input type="checkbox" id="ribbonToggleGridlinesLayout" checked class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 h-4 w-4">
                            <span>Garis Kisi Tabel</span>
                        </label>
                        <button type="button" id="btnResetRulerMargins" class="mou-ribbon-btn border-slate-200 bg-slate-50 text-[11px] text-slate-700 font-medium" title="Kembalikan Margin ke Standar Normal (25.4 mm / 1 Inci)">
                            Reset Margin
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Garis Bantu, Kisi &amp; Penggaris</div>
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
                        <button type="button" id="btnFitTableToMargins" onclick="window.MouWordProcessor?.fitTableToMargins()" class="mou-ribbon-btn border-blue-200 bg-blue-50 text-blue-700" title="Sesuaikan lebar tabel dan seluruh kolom tepat memenuhi batas margin dokumen (100%)">Ratakan Margin</button>
                        <button type="button" id="btnDistributeColsEvenly" onclick="window.MouWordProcessor?.distributeColumnsEvenly()" class="mou-ribbon-btn border-indigo-200 bg-indigo-50 text-indigo-700" title="Bagi lebar kolom sama rata">Bagi Rata Kolom</button>

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

                <!-- Group: Batas Tabel & Border Painter (MS Word Authentic Interface) -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-2">
                        <!-- Borders Tall Split-like Button -->
                        <button
                            type="button"
                            id="btnTableBordersDropdownToggle"
                            class="mou-ribbon-tall-btn text-slate-700 hover:text-blue-700 hover:bg-blue-50/70 border border-slate-200/80 bg-white shadow-2xs"
                            title="Menu Pilihan Batas Garis Tabel (MS Word Borders)"
                        >
                            <!-- 24x24 border icon matching Word -->
                            <svg class="h-6 w-6 text-slate-700 mb-0.5" viewBox="0 0 24 24" fill="none">
                                <rect x="3.5" y="3.5" width="17" height="17" stroke="currentColor" stroke-width="1.8" fill="#f8fafc"/>
                                <line x1="3.5" y1="12" x2="20.5" y2="12" stroke="currentColor" stroke-width="1.3" stroke-dasharray="2 2"/>
                                <line x1="12" y1="3.5" x2="12" y2="20.5" stroke="currentColor" stroke-width="1.3" stroke-dasharray="2 2"/>
                            </svg>
                            <div class="inline-flex items-center gap-0.5 text-[11px] font-semibold text-slate-800 leading-none">
                                <span>Borders</span>
                                <svg class="h-2.5 w-2.5 text-slate-500" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </button>

                        <!-- Border Painter Tall Button -->
                        <button
                            type="button"
                            id="btnBorderPainterToggle"
                            onclick="window.MouWordProcessor?.toggleBorderPainter()"
                            class="mou-ribbon-tall-btn text-slate-700 hover:text-blue-700 hover:bg-blue-50/70 border border-slate-200/80 bg-white shadow-2xs"
                            title="Border Painter: Klik untuk menyalin dan mengecat batas garis pada sel dokumen (Tekan Esc untuk batal)"
                        >
                            <!-- Paintbrush + Table Grid 24x24 Icon -->
                            <svg class="h-6 w-6 mb-0.5 text-slate-700" viewBox="0 0 24 24" fill="none">
                                <rect x="3" y="3" width="13" height="13" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="2 2" fill="#f8fafc"/>
                                <line x1="3" y1="9.5" x2="16" y2="9.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                                <line x1="9.5" y1="3" x2="9.5" y2="16" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                                <line x1="3" y1="16" x2="16" y2="16" stroke="#2563eb" stroke-width="2"/>
                                <!-- Brush -->
                                <path d="M19 4l-6 6-1.5 4 4-1.5 6-6a1.5 1.5 0 0 0-2.5-2.5z" fill="#3b82f6" stroke="#1d4ed8" stroke-width="1.2" stroke-linejoin="round"/>
                                <path d="M11.5 14.5l-3 3c-.5.5-.5 1.5 0 2s1.5.5 2 0l1-1" stroke="#2563eb" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <div class="text-[10px] font-semibold text-slate-800 leading-tight text-center">
                                <div>Border</div>
                                <div>Painter</div>
                            </div>
                        </button>

                        <div class="h-10 w-[1px] bg-slate-200 mx-0.5"></div>

                        <!-- Border Width & Color and 1-Click Common Presets -->
                        <div class="flex flex-col justify-center gap-1.5">
                            <div class="flex items-center gap-1.5">
                                <label class="text-[11px] font-semibold text-slate-600">Tebal:</label>
                                <select id="ribbonTableBorderWidth" class="h-[24px] rounded border border-slate-300 bg-white px-1.5 text-xs text-slate-800 focus:outline-none">
                                    <option value="0.5px">1/2 pt</option>
                                    <option value="0.75px">3/4 pt</option>
                                    <option value="1px" selected>1 pt</option>
                                    <option value="1.5px">1 1/2 pt</option>
                                    <option value="2.25px">2 1/4 pt</option>
                                    <option value="3px">3 pt</option>
                                    <option value="4.5px">4 1/2 pt</option>
                                    <option value="6px">6 pt</option>
                                </select>
                                <label class="text-[11px] font-semibold text-slate-600 ml-1">Warna:</label>
                                <div class="inline-flex items-center gap-1">
                                    <input type="color" id="ribbonTableBorderColor" value="#000000" class="h-5 w-6 rounded border border-slate-300 p-0 cursor-pointer">
                                    <span id="ribbonTableBorderColorHex" class="text-[10px] text-slate-500 font-mono">#000000</span>
                                </div>
                            </div>

                            <div class="flex items-center gap-1">
                                <button type="button" onclick="window.MouWordProcessor?.setTableBorders('borderless')" class="mou-ribbon-btn border-slate-200 bg-slate-50 hover:bg-blue-50 hover:text-blue-700 py-0.5 px-1.5 text-[11px] font-medium" title="Hapus seluruh garis pada tabel (Tanpa Batas / Borderless)">
                                    Tanpa Batas
                                </button>
                                <button type="button" onclick="window.MouWordProcessor?.setTableBorders('all')" class="mou-ribbon-btn border-slate-200 bg-slate-50 py-0.5 px-1.5 text-[11px]" title="Beri garis solid pada seluruh sel tabel (Semua Batas)">
                                    Semua Batas
                                </button>
                                <button type="button" onclick="window.MouWordProcessor?.setTableBorders('outside')" class="mou-ribbon-btn border-slate-200 bg-slate-50 py-0.5 px-1.5 text-[11px]" title="Beri garis bingkai luar (Batas Luar)">
                                    Batas Luar
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mou-ribbon-group-label">Batas Tabel (Borders)</div>
                </div>

                <!-- Group: Garis Kisi Tabel (View Gridlines ala MS Word) -->
                <div class="mou-ribbon-group">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            id="btnRibbonViewGridlines"
                            onclick="window.MouWordProcessor?.toggleTableGridlines()"
                            class="mou-ribbon-btn inline-flex items-center gap-1.5 border-blue-400 bg-blue-50 text-blue-700 font-bold px-2.5 py-1 text-xs hover:bg-blue-100 transition"
                            title="Tampilkan garis kisi bantu putus-putus pada tabel tanpa batas (Hanya tampil saat mengedit, tidak ikut tercetak)"
                        >
                            <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="3 3"/>
                                <line x1="3" y1="9" x2="21" y2="9" stroke-dasharray="2 2"/>
                                <line x1="3" y1="15" x2="21" y2="15" stroke-dasharray="2 2"/>
                                <line x1="9" y1="3" x2="9" y2="21" stroke-dasharray="2 2"/>
                                <line x1="15" y1="3" x2="15" y2="21" stroke-dasharray="2 2"/>
                            </svg>
                            <span id="ribbonGridlinesStatusText">Garis Kisi: Aktif</span>
                        </button>
                    </div>
                    <div class="text-[10px] text-slate-400 mt-1 max-w-[130px] leading-tight">
                        Garis bantu sel tanpa batas (tidak dicetak).
                    </div>
                    <div class="mou-ribbon-group-label">Lihat Garis Kisi (Gridlines)</div>
                </div>

                <!-- Group: Format & Perataan Sel -->
                <div class="mou-ribbon-group border-r-0">
                    <div class="flex items-center gap-1.5">
                        <select id="ribbonCellPaddingSelect" class="mou-ribbon-select min-w-[125px]" title="Padding Sel">
                            <option value="">Padding Sel...</option>
                            <option value="2pt 4pt">Rapat (2pt 4pt)</option>
                            <option value="4pt 6pt">Normal (4pt 6pt)</option>
                            <option value="6pt 8pt">Lega (6pt 8pt)</option>
                        </select>
                        <select id="ribbonCellValignSelect" class="mou-ribbon-select min-w-[125px]" title="Perataan Vertikal Sel">
                            <option value="top">Vertikal Atas</option>
                            <option value="middle">Vertikal Tengah</option>
                            <option value="bottom">Vertikal Bawah</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <label class="inline-flex items-center gap-1 text-xs text-slate-700 cursor-pointer" title="Warna Latar Sel">
                            <span>Latar Sel:</span>
                            <input type="color" id="ribbonCellBgColor" value="#ffffff" class="h-6 w-6 rounded border border-slate-300 p-0 cursor-pointer">
                        </label>
                        <button type="button" onclick="window.MouWordProcessor?.applyTablePreset('paraf')" class="mou-ribbon-btn border-slate-200 bg-slate-50 text-xs px-2" title="Format cepat sebagai Kotak Paraf">
                            Kotak Paraf
                        </button>
                    </div>
                    <div class="mou-ribbon-group-label">Format Sel &amp; Latar</div>
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

    <!-- MS WORD AUTHENTIC FLOATING BORDERS DROPDOWN MENU -->
    <div
        id="wordBordersFloatingDropdown"
        class="hidden fixed w-64 bg-white rounded-md shadow-2xl border border-slate-300 py-1 text-xs select-none"
        style="z-index: 99999;"
    >
        <!-- Section 1: Single Edge Borders -->
        <div class="px-1 py-0.5 space-y-0.5">
            <!-- 1. Bottom Border -->
            <button type="button" data-border-action="bottom" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1" y1="14.5" x2="15" y2="14.5" stroke="#0f172a" stroke-width="2.2" stroke-linecap="square"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black"><u>B</u>ottom Border</span>
            </button>

            <!-- 2. Top Border -->
            <button type="button" data-border-action="top" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <line x1="1" y1="1.5" x2="15" y2="1.5" stroke="#0f172a" stroke-width="2.2" stroke-linecap="square"/>
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black"><u>T</u>op Border</span>
            </button>

            <!-- 3. Left Border -->
            <button type="button" data-border-action="left" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <line x1="1.5" y1="1" x2="1.5" y2="15" stroke="#0f172a" stroke-width="2.2" stroke-linecap="square"/>
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black"><u>L</u>eft Border</span>
            </button>

            <!-- 4. Right Border -->
            <button type="button" data-border-action="right" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <line x1="14.5" y1="1" x2="14.5" y2="15" stroke="#0f172a" stroke-width="2.2" stroke-linecap="square"/>
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black"><u>R</u>ight Border</span>
            </button>
        </div>

        <!-- Divider -->
        <div class="border-t border-slate-200 my-1"></div>

        <!-- Section 2: Presets (No Border, All, Outside, Inside) -->
        <div class="px-1 py-0.5 space-y-0.5">
            <!-- 5. No Border -->
            <button type="button" data-border-action="borderless" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black"><u>N</u>o Border</span>
            </button>

            <!-- 6. All Borders -->
            <button type="button" data-border-action="all" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#0f172a" stroke-width="1.8"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#0f172a" stroke-width="1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#0f172a" stroke-width="1.5"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black"><u>A</u>ll Borders</span>
            </button>

            <!-- 7. Outside Borders -->
            <button type="button" data-border-action="outside" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#0f172a" stroke-width="1.8"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black">Out<u>s</u>ide Borders</span>
            </button>

            <!-- 8. Inside Borders -->
            <button type="button" data-border-action="inside" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#0f172a" stroke-width="1.8"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#0f172a" stroke-width="1.8"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black"><u>I</u>nside Borders</span>
            </button>
        </div>

        <!-- Divider -->
        <div class="border-t border-slate-200 my-1"></div>

        <!-- Section 3: Inside Horizontal & Vertical -->
        <div class="px-1 py-0.5 space-y-0.5">
            <!-- 9. Inside Horizontal Border -->
            <button type="button" data-border-action="inside-horizontal" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#0f172a" stroke-width="2"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black">Inside <u>H</u>orizontal Border</span>
            </button>

            <!-- 10. Inside Vertical Border -->
            <button type="button" data-border-action="inside-vertical" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#94a3b8" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#0f172a" stroke-width="2"/>
                    </svg>
                </span>
                <span class="font-normal text-slate-800 group-hover:text-black">Inside <u>V</u>ertical Border</span>
            </button>
        </div>

        <!-- Divider -->
        <div class="border-t border-slate-200 my-1"></div>

        <!-- Section 4: View Gridlines & MoU Paraf -->
        <div class="px-1 py-0.5 space-y-0.5">
            <!-- 11. View Gridlines -->
            <button type="button" data-border-action="gridlines" class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-[#e5f1fb] text-slate-800 rounded transition group text-left">
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                        <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none">
                            <rect x="1.5" y="1.5" width="13" height="13" stroke="#2563eb" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                            <line x1="1.5" y1="8" x2="14.5" y2="8" stroke="#2563eb" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                            <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="#2563eb" stroke-width="1" stroke-dasharray="1.5 1.5"/>
                        </svg>
                    </span>
                    <span class="font-normal text-slate-800 group-hover:text-black"><u>V</u>iew Gridlines</span>
                </div>
                <span id="bordersDropdownGridlinesCheck" class="text-blue-600 font-bold text-xs">✓</span>
            </button>

            <!-- 12. Gaya Kotak Paraf Dokumen -->
            <button type="button" data-border-action="paraf" class="w-full flex items-center gap-2.5 px-2.5 py-1.5 hover:bg-amber-50 text-slate-800 rounded transition group text-left">
                <span class="inline-flex items-center justify-center w-5 h-5 shrink-0">
                    <svg class="h-4 w-4 text-amber-600" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="1.5" width="13" height="13" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="8" y1="1.5" x2="8" y2="14.5" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="1.5" y1="6" x2="14.5" y2="6" stroke="currentColor" stroke-width="1.5"/>
                    </svg>
                </span>
                <span class="font-medium text-amber-800">Format Kotak <u>P</u>araf</span>
            </button>
        </div>
    </div>

    <!-- MAIN STUDIO WORKSPACE ROW: Canvas + Right Mail Merge Drawer Sidebar -->
    <div id="mouStudioMainWorkspaceRow" class="mou-studio-workspace-row w-full flex overflow-hidden relative">
        <!-- WORKSPACE VIEW 1: LIVE WORD PROCESSOR CANVAS -->
        <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden relative">
            <!-- Dedicated Word Ruler Shelf (Fixed directly beneath Ribbon, never floats over paper content) -->
            <div id="mouRulerStickyHeader" class="mou-ruler-sticky-header shrink-0">
                <div id="mouHorizontalRulerTrack" class="mou-ruler-bar">
                    <!-- Left Margin Gray Mask -->
                    <div id="mouRulerLeftMargin" class="mou-ruler-margin-mask" style="left: 0; width: {{ $cfg['margin_left_mm'] ?? 25.4 }}mm;"></div>
                    
                    <!-- Right Margin Gray Mask -->
                    <div id="mouRulerRightMargin" class="mou-ruler-margin-mask" style="right: 0; width: {{ $cfg['margin_right_mm'] ?? 25.4 }}mm;"></div>

                    <!-- Ticks Layer (generated dynamically in JS) -->
                    <div id="mouHorizontalRulerTicksContainer" class="mou-ruler-ticks-container"></div>

                    <!-- Active Object Horizontal Range (Highlight bar on ruler when dragging objects) -->
                    <div id="mouRulerObjectRange" class="mou-ruler-object-range hidden"></div>

                    <!-- Left Margin Resizer Handle -->
                    <div id="mouRulerLeftMarginHandle" class="mou-ruler-margin-handle" style="left: {{ $cfg['margin_left_mm'] ?? 25.4 }}mm;" title="Batas Margin Kiri (Seret untuk mengatur margin)">
                        <div class="mou-ruler-handle-line"></div>
                    </div>

                    <!-- Right Margin Resizer Handle -->
                    <div id="mouRulerRightMarginHandle" class="mou-ruler-margin-handle" style="right: {{ $cfg['margin_right_mm'] ?? 25.4 }}mm;" title="Batas Margin Kanan (Seret untuk mengatur margin)">
                        <div class="mou-ruler-handle-line"></div>
                    </div>

                    <!-- Markers Container -->
                    <div id="mouRulerMarkersLayer" class="mou-ruler-markers-layer">
                        <!-- 1. First Line Indent (Top Downward Triangle ▼) -->
                        <div id="mouRulerFirstLineIndent" class="mou-ruler-marker mou-ruler-first-line-indent" style="left: {{ $cfg['margin_left_mm'] ?? 25.4 }}mm;" title="Indentasi Baris Pertama (First Line Indent)">
                            <svg viewBox="0 0 10 10" class="w-full h-full fill-slate-700 hover:fill-blue-600 transition-colors pointer-events-none">
                                <polygon points="0,0 10,0 5,8"/>
                            </svg>
                        </div>

                        <!-- 2. Hanging Indent (Middle Upward Triangle ▲) -->
                        <div id="mouRulerHangingIndent" class="mou-ruler-marker mou-ruler-hanging-indent" style="left: {{ $cfg['margin_left_mm'] ?? 25.4 }}mm;" title="Indentasi Menggantung (Hanging Indent)">
                            <svg viewBox="0 0 10 10" class="w-full h-full fill-slate-700 hover:fill-blue-600 transition-colors pointer-events-none">
                                <polygon points="5,2 0,10 10,10"/>
                            </svg>
                        </div>

                        <!-- 3. Left Indent Box (Bottom Rectangle ▄) -->
                        <div id="mouRulerLeftIndentBox" class="mou-ruler-marker mou-ruler-left-indent-box" style="left: {{ $cfg['margin_left_mm'] ?? 25.4 }}mm;" title="Indentasi Kiri (Left Indent)"></div>

                        <!-- 4. Right Indent (Bottom Upward Triangle ▲ on Right) -->
                        <div id="mouRulerRightIndent" class="mou-ruler-marker mou-ruler-right-indent" style="right: {{ $cfg['margin_right_mm'] ?? 25.4 }}mm;" title="Indentasi Kanan (Right Indent)">
                            <svg viewBox="0 0 10 10" class="w-full h-full fill-slate-700 hover:fill-blue-600 transition-colors pointer-events-none">
                                <polygon points="5,2 0,10 10,10"/>
                            </svg>
                        </div>

                        <!-- 5. Dynamic Table Column Markers Layer -->
                        <div id="mouRulerTableColumnsLayer" class="mou-ruler-table-cols-layer"></div>
                    </div>
                </div>
            </div>

            <!-- Scrollable Viewport: Document desk and paper sheets scroll cleanly inside here -->
            <div id="mouEditorWorkspaceView" class="mou-workspace-viewport flex-1 overflow-y-auto overflow-x-auto bg-slate-200/90 py-6 px-4" style="height: 100%; max-height: 100%; scroll-behavior: auto !important;">
                <!-- True Multi-Page A4 Desk Stage: Holds individual physical A4 paper sheets -->
                <div id="mouPagesDeskStage" class="mou-pages-desk-stage flex flex-col items-center w-full relative">
                    <!-- Vertical Alignment Guide Line (Garis Pencocokan Presisi) -->
                    <div id="mouRulerGuideLine" class="mou-ruler-guide-line hidden pointer-events-none">
                        <div id="mouRulerTooltipBadge" class="mou-ruler-tooltip-badge">2.54 cm</div>
                    </div>

                    <!-- Smart Guides / Alignment Guides Layer (Panduan Pintar MS Word) -->
                    <div id="mouSmartGuidesContainer" class="mou-smart-guides-container pointer-events-none">
                        <div id="mouSmartGuideV" class="mou-smart-guide-line mou-smart-guide-v hidden">
                            <span id="mouSmartGuideVLabel" class="mou-smart-guide-label">Tengah Lembar</span>
                        </div>
                        <div id="mouSmartGuideH" class="mou-smart-guide-line mou-smart-guide-h hidden">
                            <span id="mouSmartGuideHLabel" class="mou-smart-guide-label">Sejajar Logo</span>
                        </div>
                    </div>

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
    <div id="mouStudioBottomStatusBar" class="shrink-0 border-t border-slate-200 bg-slate-100 px-4 py-2 text-xs text-slate-600 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3 flex-wrap">
            <!-- Active Zone Status Badge & Quick Exit -->
            <div id="statusBarZoneContainer" class="flex items-center gap-2">
                <span id="activeEditorZoneBadge" class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 border border-blue-200 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700 whitespace-nowrap">
                    <svg class="h-3.5 w-3.5 text-blue-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    <span>Mode: Lembar Dokumen A4</span>
                </span>

                <button
                    type="button"
                    id="btnCloseHeaderFooter"
                    onclick="window.MouWordProcessor?.setActiveZone('body')"
                    class="hidden inline-flex items-center gap-1 rounded-md bg-amber-600 px-2.5 py-0.5 text-[11px] font-bold text-white shadow-xs hover:bg-amber-700 transition whitespace-nowrap"
                    title="Kembali ke Isi Dokumen Utama (Selesai mengedit Header/Footer)"
                >
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                    <span>Tutup Header / Footer</span>
                </button>
            </div>

            <span class="text-slate-300">|</span>
            <span id="statusBarPageCount" class="font-semibold text-slate-800">Estimasi 1 Halaman A4</span>
            <span class="text-slate-300">|</span>
            <span id="statusBarWordCount">0 Kata (0 Karakter)</span>
            <span class="text-slate-300">|</span>
            <span>Kertas: <strong id="statusBarPaperSizeLabel">{{ strtoupper($cfg['paper_size'] ?? 'A4') }} (210 &times; 297 mm)</strong></span>
            <span class="text-slate-300 hidden lg:inline">|</span>
            <span class="text-slate-500 hidden lg:inline">Tips: Klik ganda area atas/bawah kertas untuk mengedit Header atau memperbesar Tabel Kotak Paraf.</span>
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
    class="fixed inset-0 z-[99999] hidden flex-col bg-slate-900/80 backdrop-blur-xs p-0 sm:p-1.5 md:p-2 no-print"
    tabindex="-1"
    role="dialog"
    aria-modal="true"
    aria-labelledby="mouModalTitle"
    style="position: fixed !important; inset: 0 !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 99999 !important;"
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
<div id="mouAssetLibraryModal" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/80 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
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
                setTimeout(() => { window.MouWordProcessor?.syncRuler?.(); }, 30);
                setTimeout(() => { window.MouWordProcessor?.syncRuler?.(); }, 120);
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
                if (isHidden) {
                    drawerBtn.classList.add('bg-blue-600', 'text-white', 'border-blue-600', 'shadow-xs');
                    drawerBtn.classList.remove('bg-white', 'text-blue-700', 'border-blue-300');
                    const icon = drawerBtn.querySelector('svg');
                    if (icon) icon.classList.replace('text-blue-600', 'text-white');
                } else {
                    drawerBtn.classList.remove('bg-blue-600', 'text-white', 'border-blue-600', 'shadow-xs');
                    drawerBtn.classList.add('bg-white', 'text-blue-700', 'border-blue-300');
                    const icon = drawerBtn.querySelector('svg');
                    if (icon) icon.classList.replace('text-white', 'text-blue-600');
                }
                setTimeout(() => { window.MouWordProcessor?.syncRuler?.(); }, 30);
                setTimeout(() => { window.MouWordProcessor?.syncRuler?.(); }, 150);
            });
        }
        if (closeSidebarBtn && sidebar && closeSidebarBtn.dataset.boundClose !== '1') {
            closeSidebarBtn.dataset.boundClose = '1';
            closeSidebarBtn.addEventListener('click', () => {
                sidebar.classList.add('hidden');
                if (drawerBtn) {
                    drawerBtn.classList.remove('bg-blue-600', 'text-white', 'border-blue-600', 'shadow-xs');
                    drawerBtn.classList.add('bg-white', 'text-blue-700', 'border-blue-300');
                    const icon = drawerBtn.querySelector('svg');
                    if (icon) icon.classList.replace('text-white', 'text-blue-600');
                }
                setTimeout(() => { window.MouWordProcessor?.syncRuler?.(); }, 30);
                setTimeout(() => { window.MouWordProcessor?.syncRuler?.(); }, 150);
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

            // Pastikan modal langsung menjadi child dari document.body agar berada di stacking context teratas
            // dan latar samar hitam menutupi seluruh layar (termasuk topbar & sidebar admin) secara sempurna
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }

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

        // Pindahkan modal pratinjau ke document.body sejak awal agar tidak terkurung dalam stacking context layout
        const initPreviewModal = document.getElementById('mouA4PreviewModal');
        if (initPreviewModal && initPreviewModal.parentElement !== document.body) {
            document.body.appendChild(initPreviewModal);
        }

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
        document.getElementById('btnRemoveHighlight')?.addEventListener('click', () => {
            processor.removeHighlight();
        });
        document.getElementById('ribbonParagraphLineHeight')?.addEventListener('change', (e) => {
            processor.setLineHeight(e.target.value);
        });
        document.getElementById('ribbonOrderedListStyle')?.addEventListener('change', (e) => {
            if (typeof processor.setOrderedListType === 'function') {
                processor.setOrderedListType(e.target.value);
            } else {
                processor.toggleOrderedList(e.target.value);
            }
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
            tableMenuBtn.addEventListener('mousedown', (e) => e.preventDefault());
            tableMenuDropdown.addEventListener('mousedown', (e) => e.preventDefault());
            tableMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                listMenuDropdown?.classList.add('hidden');
                const willOpen = tableMenuDropdown.classList.contains('hidden');
                if (willOpen) {
                    const rect = tableMenuBtn.getBoundingClientRect();
                    tableMenuDropdown.style.position = 'fixed';
                    tableMenuDropdown.style.top = `${rect.bottom + 5}px`;
                    tableMenuDropdown.style.left = `${Math.min(rect.left, window.innerWidth - 265)}px`;
                    tableMenuDropdown.style.zIndex = '99999';
                    tableMenuDropdown.classList.remove('hidden');
                } else {
                    tableMenuDropdown.classList.add('hidden');
                }
            });
            tableMenuDropdown.addEventListener('click', (e) => {
                if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                    tableMenuDropdown.classList.add('hidden');
                }
            });
            window.addEventListener('click', () => {
                tableMenuDropdown.classList.add('hidden');
            });
            window.addEventListener('scroll', () => {
                tableMenuDropdown.classList.add('hidden');
            }, true);
        }

        // Numbering Style Dropdown Menu (Word-style Split Button)
        const listMenuBtn = document.getElementById('btnOrderedListMenuToggle');
        const listMenuDropdown = document.getElementById('orderedListStyleDropdown');
        if (listMenuBtn && listMenuDropdown && listMenuBtn.dataset.boundListMenu !== '1') {
            listMenuBtn.dataset.boundListMenu = '1';

            // CRITICAL: Prevent mousedown from stealing editor focus so selected text stays highlighted!
            listMenuBtn.addEventListener('mousedown', (e) => e.preventDefault());
            listMenuDropdown.addEventListener('mousedown', (e) => e.preventDefault());

            listMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const willOpen = listMenuDropdown.classList.contains('hidden');
                if (willOpen) {
                    tableMenuDropdown?.classList.add('hidden');
                    const rect = listMenuBtn.getBoundingClientRect();
                    listMenuDropdown.style.position = 'fixed';
                    listMenuDropdown.style.top = `${rect.bottom + 5}px`;
                    listMenuDropdown.style.left = `${Math.min(rect.left, window.innerWidth - 260)}px`;
                    listMenuDropdown.style.zIndex = '99999';
                    listMenuDropdown.classList.remove('hidden');
                } else {
                    listMenuDropdown.classList.add('hidden');
                }
            });

            listMenuDropdown.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-list-type]');
                if (btn) {
                    const listType = btn.getAttribute('data-list-type');
                    if (typeof processor.setOrderedListType === 'function') {
                        processor.setOrderedListType(listType);
                    } else {
                        processor.toggleOrderedList(listType);
                    }
                    listMenuDropdown.classList.add('hidden');
                }
            });

            window.addEventListener('click', () => {
                listMenuDropdown.classList.add('hidden');
            });
            window.addEventListener('scroll', () => {
                listMenuDropdown.classList.add('hidden');
            }, true);
        }

        // Line Height Spacing Dropdown Menu (Word-style Dropdown)
        const lhMenuBtn = document.getElementById('btnParagraphLineHeightMenuToggle');
        const lhMenuDropdown = document.getElementById('paragraphLineHeightDropdown');
        if (lhMenuBtn && lhMenuDropdown && lhMenuBtn.dataset.boundLhMenu !== '1') {
            lhMenuBtn.dataset.boundLhMenu = '1';

            // CRITICAL: Prevent mousedown from stealing editor focus so selected text stays highlighted!
            lhMenuBtn.addEventListener('mousedown', (e) => e.preventDefault());
            lhMenuDropdown.addEventListener('mousedown', (e) => e.preventDefault());

            lhMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                tableMenuDropdown?.classList.add('hidden');
                listMenuDropdown?.classList.add('hidden');
                const willOpen = lhMenuDropdown.classList.contains('hidden');
                if (willOpen) {
                    if (typeof processor.updateToolbarState === 'function') {
                        processor.updateToolbarState();
                    }
                    const rect = lhMenuBtn.getBoundingClientRect();
                    lhMenuDropdown.style.position = 'fixed';
                    lhMenuDropdown.style.top = `${rect.bottom + 5}px`;
                    lhMenuDropdown.style.left = `${Math.min(rect.left, window.innerWidth - 220)}px`;
                    lhMenuDropdown.style.zIndex = '99999';
                    lhMenuDropdown.classList.remove('hidden');
                } else {
                    lhMenuDropdown.classList.add('hidden');
                }
            });

            lhMenuDropdown.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-line-height]');
                if (btn) {
                    const lhVal = btn.getAttribute('data-line-height');
                    if (typeof processor.setLineHeight === 'function') {
                        processor.setLineHeight(lhVal);
                    }
                    lhMenuDropdown.classList.add('hidden');
                }
            });

            window.addEventListener('click', () => {
                lhMenuDropdown.classList.add('hidden');
            });
            window.addEventListener('scroll', () => {
                lhMenuDropdown.classList.add('hidden');
            }, true);
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
            const w = document.getElementById('ribbonImageWidthPx')?.value;
            const h = document.getElementById('ribbonImageHeightPx')?.value;
            const rot = document.getElementById('ribbonImageRotationDeg')?.value;
            processor.updateSelectedImage(w ? `${w}px` : null, h ? `${h}px` : null, rot !== '' ? parseInt(rot, 10) : null);
        });
        document.getElementById('ribbonImageRotationDeg')?.addEventListener('change', (e) => {
            const deg = parseInt(e.target.value || '0', 10);
            processor.setSelectedImageRotation(deg);
        });
        document.getElementById('btnRotateImage90')?.addEventListener('click', () => {
            processor.rotateSelectedImage(90);
        });
        document.getElementById('btnResetImageAsset')?.addEventListener('click', () => {
            processor.resetSelectedImage();
        });
        document.getElementById('btnDeleteSelectedImage')?.addEventListener('click', () => {
            processor.deleteSelectedAsset();
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
        const btnImportDocxInputEl = document.getElementById('btnImportDocxInput');
        if (btnImportDocxInputEl && !btnImportDocxInputEl.dataset.boundChange) {
            btnImportDocxInputEl.dataset.boundChange = '1';
            btnImportDocxInputEl.addEventListener('change', async (e) => {
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
        }

        const btnExportDocxEl = document.getElementById('btnExportDocx');
        if (btnExportDocxEl && !btnExportDocxEl.dataset.boundClick) {
            btnExportDocxEl.dataset.boundClick = '1';
            btnExportDocxEl.addEventListener('click', async () => {
                if (btnExportDocxEl.disabled || btnExportDocxEl.classList.contains('pointer-events-none')) return;
                try {
                    btnExportDocxEl.disabled = true;
                    btnExportDocxEl.classList.add('opacity-50', 'pointer-events-none');
                    const tplName = (document.getElementById('name')?.value || document.querySelector('input[name="name"]')?.value || 'template-dokumen').replace(/[^a-zA-Z0-9_-]/g, '_');
                    await processor.exportDocx(`${tplName}.docx`);
                } catch (err) {
                    alert("Gagal mengekspor dokumen Word: " + err.message);
                } finally {
                    setTimeout(() => {
                        btnExportDocxEl.disabled = false;
                        btnExportDocxEl.classList.remove('opacity-50', 'pointer-events-none');
                    }, 1200);
                }
            });
        }

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

