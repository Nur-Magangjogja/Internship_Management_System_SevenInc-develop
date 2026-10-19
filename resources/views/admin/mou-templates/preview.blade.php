@extends('layouts.dashboard', ['title' => 'Pratinjau Template MOU'])

@php
    $pageCfg = array_merge(\App\Models\MouTemplate::getDefaultPageConfig(), $pageConfig ?? []);
    $marginTop    = (float) ($pageCfg['margin_top_mm'] ?? 25.4);
    $marginRight  = (float) ($pageCfg['margin_right_mm'] ?? 25.4);
    $marginBottom = (float) ($pageCfg['margin_bottom_mm'] ?? 25.4);
    $marginLeft   = (float) ($pageCfg['margin_left_mm'] ?? 25.4);
    $showParaf    = (bool) ($pageCfg['show_paraf_box'] ?? $pageCfg['show_paraf_footer'] ?? true);
    $showPageNum  = (bool) ($pageCfg['show_page_number'] ?? $pageCfg['show_page_numbers'] ?? true);
    $differentFirstPage  = (bool) ($pageCfg['different_first_page'] ?? false);
    $headerHtml          = trim((string) ($pageCfg['header_html'] ?? ''));
    $firstPageHeaderHtml = trim((string) ($pageCfg['first_page_header_html'] ?? ''));
    $firstPageFooterHtml = trim((string) ($pageCfg['first_page_footer_html'] ?? ''));
    $rawFooterHtml       = array_key_exists('footer_html', $pageCfg)
        ? trim((string) ($pageCfg['footer_html'] ?? ''))
        : ($showParaf ? \App\Models\MouTemplate::getDefaultFooterParafHtml() : '');
    $footerHtml          = ($showParaf || !str_contains($rawFooterHtml, 'mou-paraf-table')) ? $rawFooterHtml : '';
    $pdfUrl       = route('admin.mou-templates.preview-pdf', ['mouTemplate' => $mouTemplate, 'institution_id' => $selectedInstitution?->id]);
@endphp

@section('content')
<div class="px-6 py-8">
    <!-- Top Action Bar -->
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between no-print">
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.mou-templates.index') }}"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:bg-slate-50"
                title="Kembali ke Daftar Template"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-bold text-slate-800">
                        Pratinjau: {{ $mouTemplate->name }}
                    </h1>
                    <span class="rounded bg-slate-100 border border-slate-200 px-2 py-0.5 text-xs font-mono text-slate-600">
                        Versi {{ $mouTemplate->version_number }}
                    </span>
                    <span id="previewPageCountBadge" class="rounded bg-blue-50 border border-blue-200 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                        1 Halaman A4
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">Simulasi naskah Nota Kesepahaman pada lembar kertas A4 (210 &times; 297 mm) beserta pratinjau PDF resmi.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Mode Switcher: Lembar A4 vs Official PDF Preview -->
            <div class="inline-flex rounded-lg border border-slate-300 bg-slate-100 p-0.5 text-xs">
                <button
                    type="button"
                    id="btnTabSheetPreview"
                    class="rounded-md bg-white px-3 py-1.5 font-semibold text-slate-800 shadow-sm transition"
                >
                    Lembar Kertas A4
                </button>
                <button
                    type="button"
                    id="btnTabOfficialPdf"
                    class="rounded-md px-3 py-1.5 font-semibold text-slate-600 hover:text-slate-900 transition"
                >
                    Official PDF Preview
                </button>
            </div>

            <!-- Selector Simulasi Institusi Nyata -->
            <form method="GET" action="{{ route('admin.mou-templates.preview', $mouTemplate) }}" class="flex items-center gap-2">
                <select
                    name="institution_id"
                    onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-xs font-medium text-slate-700 focus:border-blue-500 focus:outline-none"
                >
                    <option value="">-- Simulasi: Data Contoh --</option>
                    @foreach ($institutions as $inst)
                        <option value="{{ $inst->id }}" {{ $selectedInstitution?->id === $inst->id ? 'selected' : '' }}>
                            Institusi: {{ $inst->name }}
                        </option>
                    @endforeach
                </select>
            </form>

            <a
                href="{{ route('admin.mou-templates.edit', $mouTemplate) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Ubah Naskah
            </a>

            <a
                href="{{ $pdfUrl }}"
                target="_blank"
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
                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
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

    <!-- Info Notice: Browser Pagination vs Official DomPDF Reference -->
    <div class="mb-4 flex items-center justify-between rounded-lg border border-blue-200 bg-blue-50/80 px-4 py-2 text-xs text-blue-800 no-print">
        <span>
            <strong>Catatan Akurasi Halaman:</strong> Tab <em>Lembar Kertas A4</em> menampilkan simulasi pemenggalan halaman berbasis pengukuran DOM browser. Untuk hasil cetak final yang presisi 100%, gunakan tab <strong>Official PDF Preview</strong> (mesin DomPDF).
        </span>
    </div>

    <!-- Hidden Raw Rendered HTML Source for Multi-Page A4 Pagination -->
    <div id="previewCanonicalSource" class="hidden">{!! $renderedHtml !!}</div>

    <!-- TAB 1: Multi-Page A4 Paper Sheets Simulation -->
    <div id="panelSheetPreview" class="bg-slate-300/80 py-8 px-4 rounded-xl border border-slate-300 overflow-x-auto print:bg-white print:p-0 print:border-none">
        <div
            id="previewA4PagesStage"
            class="mou-preview-pages-stage"
            style="--mou-margin-top: {{ $marginTop }}mm; --mou-margin-right: {{ $marginRight }}mm; --mou-margin-bottom: {{ $marginBottom }}mm; --mou-margin-left: {{ $marginLeft }}mm; --mou-font-family: '{{ $pageCfg['font_family'] ?? 'Times New Roman' }}', Times, serif; --mou-font-size: {{ (int)($pageCfg['font_size_pt'] ?? 12) }}pt; --mou-line-height: {{ (float)($pageCfg['line_height'] ?? 1.15) }};"
        ></div>
    </div>

    <!-- TAB 2: Official DomPDF Preview Iframe -->
    <div id="panelOfficialPdf" class="hidden rounded-xl border border-slate-300 bg-slate-800 p-3 shadow-inner no-print">
        <div class="mb-2 flex items-center justify-between text-xs text-slate-200 px-2">
            <span>Pratinjau Keluaran PDF Resmi (Dirender langsung oleh mesin A4 DomPDF)</span>
            <a href="{{ $pdfUrl }}" target="_blank" class="text-blue-300 hover:underline font-semibold">Buka PDF di Tab Baru</a>
        </div>
        <iframe
            id="officialPdfPreviewIframe"
            data-src="{{ $pdfUrl }}"
            title="Official PDF Preview"
            class="w-full rounded-lg bg-white border border-slate-700"
            style="height: 85vh;"
        ></iframe>
    </div>
</div>

<style>
{!! $canonicalCss ?? '' !!}

.mou-preview-pages-stage {
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
    padding: var(--mou-margin-top, 25.4mm) var(--mou-margin-right, 25.4mm) var(--mou-margin-bottom, 25.4mm) var(--mou-margin-left, 25.4mm);
    font-family: var(--mou-font-family, 'Times New Roman', Times, serif);
    font-size: var(--mou-font-size, 12pt);
    line-height: var(--mou-line-height, 1.15);
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
}
.mou-a4-page-footer {
    width: 100%;
    min-height: 15mm;
    flex-shrink: 0;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 8px;
    padding-top: 2mm;
    box-sizing: border-box;
}

@media print {
    @page {
        size: A4 portrait;
        margin: 0;
    }
    .no-print, header, aside, footer, nav {
        display: none !important;
    }
    body, main {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .mou-preview-pages-stage {
        gap: 0 !important;
    }
    .mou-a4-page {
        box-shadow: none !important;
        border: none !important;
        page-break-after: always;
        break-after: page;
    }
    .mou-a4-page:last-child {
        page-break-after: auto;
        break-after: auto;
    }
}
</style>

<script>
(function () {
    const sourceEl = document.getElementById('previewCanonicalSource');
    const stageEl = document.getElementById('previewA4PagesStage');
    const badgeEl = document.getElementById('previewPageCountBadge');
    const showParaf = @json($showParaf);
    const showPageNum = @json($showPageNum);
    const differentFirstPage = @json($differentFirstPage);
    const headerHtml = @json($headerHtml);
    const footerHtml = @json($footerHtml);
    const firstPageHeaderHtml = @json($firstPageHeaderHtml);
    const firstPageFooterHtml = @json($firstPageFooterHtml);

    if (!sourceEl || !stageEl) return;

    function createSheet(index) {
        const page = document.createElement('div');
        page.className = 'mou-a4-page';

        const activeHeaderHtml = (differentFirstPage && index === 1) ? firstPageHeaderHtml : headerHtml;
        const activeFooterHtml = (differentFirstPage && index === 1 && firstPageFooterHtml !== '') ? firstPageFooterHtml : footerHtml;

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
            <div class="mou-a4-footer-custom flex-1" style="${activeFooterHtml ? '' : 'visibility: hidden;'}">
                ${activeFooterHtml || ''}
            </div>
            <div class="mou-preview-pagenum font-serif text-[9pt] text-black pb-1 shrink-0" style="${showPageNum ? '' : 'visibility: hidden;'}">
                Halaman ${index} dari 1
            </div>
        `;

        page.appendChild(body);
        page.appendChild(footer);
        return { page, header, body, footer };
    }

    function getUsedHeight(bodyEl) {
        if (!bodyEl || bodyEl.children.length === 0) return 0;
        const last = bodyEl.lastElementChild;
        const mb = parseFloat(window.getComputedStyle(last).marginBottom) || 0;
        return Math.max(bodyEl.offsetHeight, last.offsetTop + last.offsetHeight + mb);
    }

    function getMaxBodyHeight(sheet) {
        const cs = window.getComputedStyle(sheet.page);
        const headerH = sheet.header ? (sheet.header.offsetHeight + 10) : 0;
        const footerH = sheet.footer ? (sheet.footer.offsetHeight + 8) : 60;
        return Math.max(200, (sheet.page.offsetHeight || 1122.5) - (parseFloat(cs.paddingTop) || 96) - (parseFloat(cs.paddingBottom) || 96) - headerH - footerH - 8);
    }

    function paginatePreview() {
        const innerRoot = sourceEl.querySelector('.mou-document, .mou-doc-canvas') || sourceEl;
        const blocks = Array.from(innerRoot.children);
        stageEl.innerHTML = '';

        const pages = [];
        function openPage() {
            const p = createSheet(pages.length + 1);
            stageEl.appendChild(p.page);
            pages.push(p);
            return p;
        }

        let cur = openPage();

        blocks.forEach((rawBlock, idx) => {
            const block = rawBlock.cloneNode(true);
            if (block.classList.contains('mou-page-break') || block.getAttribute('data-page-break') === 'true') {
                if (idx < blocks.length - 1) cur = openPage();
                return;
            }

            const maxH = getMaxBodyHeight(cur);
            cur.body.appendChild(block);
            if (getUsedHeight(cur.body) <= maxH) return;
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
            } else {
                if (cur.body.children.length > 0) cur = openPage();
                cur.body.appendChild(block);
            }
        });

        const total = pages.length || 1;
        pages.forEach((p, i) => {
            const lbl = p.page.querySelector('.mou-preview-pagenum');
            if (lbl) lbl.textContent = `Halaman ${i + 1} dari ${total}`;
        });
        if (badgeEl) badgeEl.textContent = `${total} Halaman A4`;
    }

    paginatePreview();
    window.addEventListener('load', paginatePreview);

    // Tab Switcher
    const btnSheet = document.getElementById('btnTabSheetPreview');
    const btnPdf = document.getElementById('btnTabOfficialPdf');
    const panelSheet = document.getElementById('panelSheetPreview');
    const panelPdf = document.getElementById('panelOfficialPdf');
    const pdfIframe = document.getElementById('officialPdfPreviewIframe');

    btnSheet?.addEventListener('click', function () {
        panelSheet.classList.remove('hidden');
        panelPdf.classList.add('hidden');
        btnSheet.className = 'rounded-md bg-white px-3 py-1.5 font-semibold text-slate-800 shadow-sm transition';
        btnPdf.className = 'rounded-md px-3 py-1.5 font-semibold text-slate-600 hover:text-slate-900 transition';
    });

    btnPdf?.addEventListener('click', function () {
        panelSheet.classList.add('hidden');
        panelPdf.classList.remove('hidden');
        btnPdf.className = 'rounded-md bg-white px-3 py-1.5 font-semibold text-slate-800 shadow-sm transition';
        btnSheet.className = 'rounded-md px-3 py-1.5 font-semibold text-slate-600 hover:text-slate-900 transition';
        if (pdfIframe && !pdfIframe.getAttribute('src')) {
            pdfIframe.setAttribute('src', pdfIframe.getAttribute('data-src'));
        }
    });
})();
</script>
@endsection
