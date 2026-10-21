<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $mou->company_mou_number }} - Naskah Kerja Sama MOU</title>
    @vite(['resources/css/app.css'])
    @php
        $pageCfg = $pageConfig ?? ($mou->render_data_snapshot['_page_config'] ?? ($mou->template ? $mou->template->getPageConfig() : \App\Models\MouTemplate::getDefaultPageConfig()));
        $marginTop    = (float) ($pageCfg['margin_top_mm'] ?? 25.4);
        $marginRight  = (float) ($pageCfg['margin_right_mm'] ?? 25.4);
        $marginBottom = (float) ($pageCfg['margin_bottom_mm'] ?? 25.4);
        $marginLeft   = (float) ($pageCfg['margin_left_mm'] ?? 25.4);
        $showParaf    = (bool) ($pageCfg['show_paraf_box'] ?? $pageCfg['show_paraf_footer'] ?? true);
        $showPageNum  = (bool) ($pageCfg['show_page_number'] ?? $pageCfg['show_page_numbers'] ?? true);
        $diffFirstPage = (bool) ($pageCfg['different_first_page'] ?? false);
        $headerHtml   = (string) ($pageCfg['header_html'] ?? '');
        $firstPageHeaderHtml = (string) ($pageCfg['first_page_header_html'] ?? '');
        $footerHtml   = array_key_exists('footer_html', $pageCfg) && $pageCfg['footer_html'] !== null
            ? (string) $pageCfg['footer_html']
            : ($showParaf ? \App\Models\MouTemplate::getDefaultFooterParafHtml() : '');
        $firstPageFooterHtml = array_key_exists('first_page_footer_html', $pageCfg) && $pageCfg['first_page_footer_html'] !== null
            ? (string) $pageCfg['first_page_footer_html']
            : $footerHtml;
        $showHeaderLogos = (bool) ($pageCfg['show_header_logos'] ?? true);
        $showWatermark   = (bool) ($pageCfg['show_watermark'] ?? false);
        $rawInstLogo = $mou->render_data_snapshot['institution_logo_path']
            ?? ($mou->institution?->logo_url ?: ($mou->institution?->logo ? ('/storage/' . $mou->institution->logo) : '/images/mou/institution-logo-placeholder.png'));
        $resolvedInstitutionLogo = \App\Services\MouDocumentRenderer::convertLocalPathToBase64($rawInstLogo) ?? $rawInstLogo;
        $rawCompanyLogo = $mou->render_data_snapshot['company_logo_path']
            ?? ($mou->render_data_snapshot['company_logo']
            ?? ($mou->render_data_snapshot['_media_assets']['company_logo']
            ?? '/images/mou/seven-inc-logo.png'));
        $resolvedCompanyLogo = \App\Services\MouDocumentRenderer::convertLocalPathToBase64($rawCompanyLogo) ?? $rawCompanyLogo;
        $isHr = request()->routeIs('hr.*');
        $routePrefix = $isHr ? 'hr.mous' : 'admin.mous';
    @endphp
    <style>
        {!! $canonicalCss ?? \App\Services\MouDocumentRenderer::getCanonicalDocumentCss($pageCfg) !!}

        .mou-print-pages-stage {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 28px;
            width: 100%;
        }
        .mou-a4-page {
            width: 210mm;
            min-width: 210mm;
            max-width: 210mm;
            height: 297mm;
            min-height: 297mm;
            max-height: 297mm;
            background: #ffffff;
            box-shadow: 0 12px 28px -6px rgba(15, 23, 42, 0.22), 0 4px 12px -2px rgba(15, 23, 42, 0.12);
            border: 1px solid #94a3b8;
            box-sizing: border-box;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            color: #000000;
            padding: {{ $marginTop }}mm {{ $marginRight }}mm {{ $marginBottom }}mm {{ $marginLeft }}mm;
            font-family: '{{ $pageCfg['font_family'] ?? 'Times New Roman' }}', Times, serif;
            font-size: {{ (int)($pageCfg['font_size_pt'] ?? 12) }}pt;
            line-height: {{ (float)($pageCfg['line_height'] ?? 1.15) }};
        }
        .mou-a4-page-header {
            width: 100%;
            flex-shrink: 0;
            margin-bottom: 3mm;
            box-sizing: border-box;
        }
        .mou-a4-page-body {
            flex: 1 1 auto;
            width: 100%;
            overflow: hidden;
        }
        .mou-a4-page-footer {
            width: 100%;
            min-height: 15mm;
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 8mm;
            padding-top: 2mm;
            box-sizing: border-box;
        }
        .mou-a4-page-footer-left {
            flex: 1 1 auto;
            min-width: 0;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 0;
            }
            *, *::before, *::after {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print {
                display: none !important;
            }
            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                min-height: 0 !important;
                overflow: visible !important;
            }
            .mou-print-pages-stage {
                display: block !important;
                width: 100% !important;
                gap: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .mou-a4-page {
                width: 210mm !important;
                min-height: 296.5mm !important;
                max-height: 297mm !important;
                border: none !important;
                box-shadow: none !important;
                margin: 0 auto !important;
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
            }
            .mou-a4-page:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
        }
    </style>
</head>
<body class="bg-slate-300 min-h-screen antialiased text-slate-900 py-6">

    <!-- Top Action Bar (No Print) -->
    <div class="no-print mx-auto max-w-4xl mb-6 px-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm border border-slate-200">
            <div class="flex items-center gap-3">
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                </span>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-bold text-slate-800">{{ $mou->company_mou_number }}</h1>
                        <span id="printPageCountBadge" class="rounded bg-blue-50 border border-blue-200 px-2 py-0.5 text-[11px] font-semibold text-blue-700">1 Halaman A4</span>
                    </div>
                    <p class="text-xs text-slate-500">Pratinjau Cetak Naskah Perjanjian Kerja Sama A4 (210 &times; 297 mm)</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    onclick="window.close()"
                    class="rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    Tutup
                </button>
                <a
                    href="{{ route($routePrefix . '.download-pdf', $mou) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Unduh PDF (A4)
                </a>
                <button
                    type="button"
                    onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Cetak Browser
                </button>
            </div>
        </div>
    </div>

    <div id="printCanonicalSource" class="hidden">{!! $mou->rendered_document_html !!}</div>

    <!-- Multi-Page A4 Container -->
    <div id="printA4PagesStage" class="mou-print-pages-stage"></div>

    <script>
    (function () {
        const sourceEl = document.getElementById('printCanonicalSource');
        const stageEl = document.getElementById('printA4PagesStage');
        const badgeEl = document.getElementById('printPageCountBadge');
        const showParaf = @json($showParaf);
        const showPageNum = @json($showPageNum);
        const diffFirstPage = @json($diffFirstPage);
        const headerHtml = @json($headerHtml);
        const firstPageHeaderHtml = @json($firstPageHeaderHtml);
        const footerHtml = @json($footerHtml);
        const firstPageFooterHtml = @json($firstPageFooterHtml);
        const showHeaderLogos = @json($showHeaderLogos);
        const showWatermark = @json($showWatermark);
        const resolvedInstitutionLogo = @json($resolvedInstitutionLogo);
        const resolvedCompanyLogo = @json($resolvedCompanyLogo);

        if (!sourceEl || !stageEl) return;

        function createSheet(index) {
            const page = document.createElement('div');
            page.className = 'mou-a4-page a4-container';
            page.style.position = 'relative';

            if (index === 1 && showHeaderLogos) {
                const wmLayer = document.createElement('div');
                wmLayer.className = 'mou-watermark-layer';
                wmLayer.innerHTML = `
                    <div class="mou-watermark-fixed-header">
                        <img src="${resolvedInstitutionLogo}" class="mou-watermark-logo-left" alt="Logo Institusi" />
                        <img src="${resolvedCompanyLogo}" class="mou-watermark-logo-right" alt="Logo Seven Inc" />
                    </div>
                    ${showWatermark ? `<img src="${resolvedCompanyLogo}" class="mou-watermark-center" alt="Watermark" />` : ''}
                `;
                page.appendChild(wmLayer);
            } else if (showWatermark) {
                const wmLayer = document.createElement('div');
                wmLayer.className = 'mou-watermark-layer';
                wmLayer.innerHTML = `<img src="${resolvedCompanyLogo}" class="mou-watermark-center" alt="Watermark" />`;
                page.appendChild(wmLayer);
            }

            const activeHeaderHtml = (diffFirstPage && index === 1) ? firstPageHeaderHtml : headerHtml;
            const activeFooterHtml = (diffFirstPage && index === 1) ? firstPageFooterHtml : footerHtml;

            let header = null;
            if (activeHeaderHtml && activeHeaderHtml.trim() !== '') {
                header = document.createElement('div');
                header.className = 'mou-a4-page-header mou-doc-canvas';
                header.innerHTML = activeHeaderHtml;
                page.appendChild(header);
            }

            const body = document.createElement('div');
            body.className = 'mou-a4-page-body mou-doc-canvas';

            const footer = document.createElement('div');
            footer.className = 'mou-a4-page-footer';
            footer.innerHTML = `
                <div class="mou-a4-page-footer-left mou-doc-canvas" style="${showParaf ? '' : 'visibility: hidden;'}">
                    ${showParaf ? activeFooterHtml : ''}
                </div>
                <div class="mou-print-pagenum font-serif text-[9pt] text-black pb-1 shrink-0" style="${showPageNum ? '' : 'visibility: hidden;'}">
                    Halaman ${index} dari 1
                </div>
            `;

            page.appendChild(body);
            page.appendChild(footer);
            return { page, header, body, footer };
        }

        function getMaxBodyHeight(sheet) {
            const cs = window.getComputedStyle(sheet.page);
            const headerH = sheet.header ? (sheet.header.offsetHeight + 12) : 0;
            const footerH = sheet.footer ? (sheet.footer.offsetHeight + 8) : 64;
            return Math.max(220, (sheet.page.offsetHeight || 1122.5) - (parseFloat(cs.paddingTop) || 96) - (parseFloat(cs.paddingBottom) || 96) - headerH - footerH - 8);
        }

        function getUsedHeight(bodyEl) {
            if (!bodyEl || bodyEl.children.length === 0) return 0;
            const last = bodyEl.lastElementChild;
            const mb = parseFloat(window.getComputedStyle(last).marginBottom) || 0;
            return Math.max(bodyEl.offsetHeight, last.offsetTop + last.offsetHeight + mb);
        }

        function splitAndAppendTable(tableNode, curSheet, openPageFn, getMaxBodyHeightFn, getUsedHeightFn) {
            const trs = Array.from(tableNode.querySelectorAll('tr'));
            if (trs.length <= 1) {
                curSheet.body.appendChild(tableNode);
                return curSheet;
            }

            const colgroup = tableNode.querySelector('colgroup')?.cloneNode(true);
            const thead = tableNode.querySelector('thead')?.cloneNode(true);

            function createSubTable() {
                const t = document.createElement('table');
                t.className = tableNode.className;
                if (tableNode.getAttribute('style')) t.setAttribute('style', tableNode.getAttribute('style'));
                if (tableNode.getAttribute('data-table-style')) t.setAttribute('data-table-style', tableNode.getAttribute('data-table-style'));
                if (tableNode.getAttribute('data-table-width')) t.setAttribute('data-table-width', tableNode.getAttribute('data-table-width'));
                if (colgroup) t.appendChild(colgroup.cloneNode(true));
                if (thead) t.appendChild(thead.cloneNode(true));
                const tb = document.createElement('tbody');
                t.appendChild(tb);
                return { table: t, tbody: tb };
            }

            let sheet = curSheet;
            let sub = createSubTable();
            sheet.body.appendChild(sub.table);

            trs.forEach(tr => {
                const trClone = tr.cloneNode(true);
                sub.tbody.appendChild(trClone);
                const maxH = getMaxBodyHeightFn(sheet);
                const usedH = getUsedHeightFn(sheet.body);

                if (usedH > maxH && sub.tbody.children.length > 1) {
                    sub.tbody.removeChild(trClone);
                    sheet = openPageFn();
                    sub = createSubTable();
                    sheet.body.appendChild(sub.table);
                    sub.tbody.appendChild(trClone);
                }
            });

            return sheet;
        }

        function paginatePrint() {
            let innerRoot = sourceEl.querySelector('.mou-document');
            if (!innerRoot) {
                const canvases = sourceEl.querySelectorAll('.mou-doc-canvas');
                if (canvases.length > 0) {
                    innerRoot = canvases[canvases.length - 1];
                } else {
                    innerRoot = sourceEl.querySelector('.mou-published-snapshot') || sourceEl;
                }
            }

            stageEl.innerHTML = '';

            const pages = [];
            function openPage() {
                const p = createSheet(pages.length + 1);
                stageEl.appendChild(p.page);
                pages.push(p);
                return p;
            }

            // PRIORITAS 1: Jika source HTML memiliki kontainer halaman terstruktur (.mou-a4-page-node atau [data-mou-page="true"])
            const structuredPageNodes = Array.from(innerRoot.querySelectorAll('.mou-a4-page-node, [data-mou-page="true"]'));
            if (structuredPageNodes.length > 0) {
                structuredPageNodes.forEach(pNode => {
                    const sheet = openPage();
                    const contentEl = pNode.querySelector('.mou-page-body-content') || pNode;
                    Array.from(contentEl.children).forEach(ch => {
                        if (ch.classList.contains('mou-a4-page-header') || ch.classList.contains('mou-a4-page-footer') || ch.hasAttribute('data-pagination-artifact')) {
                            return;
                        }
                        sheet.body.appendChild(ch.cloneNode(true));
                    });
                });

                const total = pages.length || 1;
                pages.forEach((p, i) => {
                    const lbl = p.page.querySelector('.mou-print-pagenum');
                    if (lbl) lbl.textContent = `Halaman ${i + 1} dari ${total}`;
                });
                if (badgeEl) badgeEl.textContent = `${total} Halaman A4`;
                return;
            }

            // PRIORITAS 2: Pagination cerdas berbasis aliran naskah alami & pemisah halaman manual (Page Break)
            const cloneRoot = innerRoot.cloneNode(true);
            cloneRoot.querySelectorAll('style, .mou-watermark-layer').forEach(el => el.remove());

            const blocks = Array.from(cloneRoot.children).filter(el => {
                return !el.classList.contains('mou-a4-page-header') &&
                       !el.classList.contains('mou-a4-page-footer') &&
                       !el.hasAttribute('data-pagination-artifact');
            });

            function isHeadingElement(el) {
                if (!el) return false;
                if (el.classList?.contains('mou-pasal-heading') || el.classList?.contains('mou-header-block')) return true;
                const text = (el.textContent || '').trim();
                if (/^Pasal\s+\d+/i.test(text)) return true;
                if (el.tagName === 'P' && (el.querySelector('strong') || el.querySelector('b'))) {
                    const upper = text.toUpperCase();
                    if (upper === text && text.length >= 3 && text.length <= 60 && !text.includes(':')) {
                        return true;
                    }
                }
                return false;
            }

            let cur = openPage();
            let maxH = getMaxBodyHeight(cur);

            for (let idx = 0; idx < blocks.length; idx++) {
                const block = blocks[idx].cloneNode(true);
                if (block.classList.contains('mou-page-break') || block.getAttribute('data-page-break') === 'true') {
                    if (cur.body.children.length > 0 && idx < blocks.length - 1) {
                        cur = openPage();
                        maxH = getMaxBodyHeight(cur);
                    }
                    continue;
                }

                // Proteksi Orphan Heading
                if (isHeadingElement(blocks[idx]) && cur.body.children.length > 0) {
                    let hEnd = idx;
                    while (hEnd < blocks.length && isHeadingElement(blocks[hEnd])) {
                        hEnd++;
                    }
                    const testClones = [];
                    for (let k = idx; k <= Math.min(hEnd, blocks.length - 1); k++) {
                        const cl = blocks[k].cloneNode(true);
                        testClones.push(cl);
                        cur.body.appendChild(cl);
                    }
                    const testUsedH = getUsedHeight(cur.body);
                    testClones.forEach(cl => cur.body.removeChild(cl));

                    if (testUsedH > maxH) {
                        cur = openPage();
                        maxH = getMaxBodyHeight(cur);
                    }
                }

                cur.body.appendChild(block);
                if (getUsedHeight(cur.body) <= maxH) continue;
                cur.body.removeChild(block);

                if (block.classList.contains('mou-pasal') || block.getAttribute('data-pasal') === 'true') {
                    const heading = block.querySelector('.mou-pasal-heading');
                    const body = block.querySelector('.mou-pasal-body') || block;
                    const units = [];
                    Array.from(body.children).forEach(ch => {
                        if (ch === heading) return;
                        if (ch.tagName === 'OL' && ch.classList.contains('mou-ayat-list')) {
                            Array.from(ch.children).forEach(li => units.push({ type: 'ayat', node: li.cloneNode(true) }));
                        } else {
                            units.push({ type: 'block', node: ch.cloneNode(true) });
                        }
                    });

                    const primary = document.createElement('div');
                    primary.className = 'mou-pasal';
                    if (heading) primary.appendChild(heading.cloneNode(true));
                    const pBody = document.createElement('div');
                    pBody.className = 'mou-pasal-body';
                    primary.appendChild(pBody);

                    function appendUnit(targetBody, u) {
                        if (u.type === 'ayat') {
                            let ol = targetBody.lastElementChild;
                            let createdOl = null;
                            if (!ol || ol.tagName !== 'OL') {
                                ol = document.createElement('ol');
                                ol.className = 'mou-ayat-list';
                                targetBody.appendChild(ol);
                                createdOl = ol;
                            }
                            const li = u.node.cloneNode(true);
                            ol.appendChild(li);
                            return { added: li, createdOl };
                        } else {
                            const n = u.node.cloneNode(true);
                            targetBody.appendChild(n);
                            return { added: n, createdOl: null };
                        }
                    }

                    if (units.length > 0) appendUnit(pBody, units[0]);
                    const hadPrior = cur.body.children.length > 0;
                    cur.body.appendChild(primary);
                    if (hadPrior && getUsedHeight(cur.body) > maxH) {
                        cur.body.removeChild(primary);
                        cur = openPage();
                        cur.body.appendChild(primary);
                    }

                    let activeBody = pBody;
                    for (let i = 1; i < units.length; i++) {
                        const { added, createdOl } = appendUnit(activeBody, units[i]);
                        if (getUsedHeight(cur.body) > maxH) {
                            if (createdOl) createdOl.remove(); else added.remove();
                            cur = openPage();
                            const cont = document.createElement('div');
                            cont.className = 'mou-pasal mou-pasal-continuation';
                            activeBody = document.createElement('div');
                            activeBody.className = 'mou-pasal-body';
                            cont.appendChild(activeBody);
                            cur.body.appendChild(cont);
                            appendUnit(activeBody, units[i]);
                        }
                    }
                } else if (block.tagName === 'TABLE') {
                    if (cur.body.children.length > 0) {
                        cur = openPage();
                        maxH = getMaxBodyHeight(cur);
                    }
                    cur = splitAndAppendTable(block, cur, openPage, getMaxBodyHeight, getUsedHeight);
                    maxH = getMaxBodyHeight(cur);
                } else {
                    if (cur.body.children.length > 0) {
                        cur = openPage();
                        maxH = getMaxBodyHeight(cur);
                    }
                    cur.body.appendChild(block);
                }
            });

            const total = pages.length || 1;
            pages.forEach((p, i) => {
                const lbl = p.page.querySelector('.mou-print-pagenum');
                if (lbl) lbl.textContent = `Halaman ${i + 1} dari ${total}`;
            });
            if (badgeEl) badgeEl.textContent = `${total} Halaman A4`;
        }

        paginatePrint();
        window.addEventListener('load', paginatePrint);
    })();
    </script>
</body>
</html>
