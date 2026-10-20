@extends('layouts.dashboard', ['title' => 'Pratinjau Template MOU'])

@php
    $pageCfg = array_merge(\App\Models\MouTemplate::getDefaultPageConfig(), $pageConfig ?? []);
    $marginTop        = (float) ($pageCfg['margin_top_mm'] ?? 25.4);
    $marginRight      = (float) ($pageCfg['margin_right_mm'] ?? 25.4);
    $marginBottom     = (float) ($pageCfg['margin_bottom_mm'] ?? 25.4);
    $marginLeft       = (float) ($pageCfg['margin_left_mm'] ?? 25.4);
    $headerDistance   = (float) ($pageCfg['header_distance_mm'] ?? 12.0);
    $footerDistance   = (float) ($pageCfg['footer_distance_mm'] ?? 10.0);
    $showParaf        = (bool) ($pageCfg['show_paraf_box'] ?? $pageCfg['show_paraf_footer'] ?? true);
    $showPageNum      = (bool) ($pageCfg['show_page_number'] ?? $pageCfg['show_page_numbers'] ?? true);
    $differentFirstPage  = (bool) ($pageCfg['different_first_page'] ?? false);
    $headerHtml          = trim((string) ($pageCfg['header_html'] ?? ''));
    $firstPageHeaderHtml = trim((string) ($pageCfg['first_page_header_html'] ?? ''));
    $firstPageFooterHtml = trim((string) ($pageCfg['first_page_footer_html'] ?? ''));
    $rawFooterHtml       = array_key_exists('footer_html', $pageCfg)
        ? trim((string) ($pageCfg['footer_html'] ?? ''))
        : ($showParaf ? \App\Models\MouTemplate::getDefaultFooterParafHtml() : '');
    $footerHtml          = ($showParaf || !str_contains($rawFooterHtml, 'mou-paraf-table')) ? $rawFooterHtml : '';
    $pdfUrl              = route('admin.mou-templates.preview-pdf', ['mouTemplate' => $mouTemplate, 'institution_id' => $selectedInstitution?->id]);

    $padTopMm    = min($headerDistance, $marginTop);
    $padBtmMm    = min($footerDistance, $marginBottom);
    $headerGapMm = max(2.0, $marginTop - $headerDistance);
    $footerGapMm = max(2.0, $marginBottom - $footerDistance);

    $mediaAssets = $mouTemplate->getMediaAssets();
    $resolvedCompanyLogo = $mediaAssets['company_logo'] ?? '/images/mou/seven-inc-logo.png';
    $resolvedInstitutionLogo = $selectedInstitution?->logo_url ?? ($mediaAssets['institution_logo'] ?? '/images/mou/institution-logo-placeholder.png');
    $showHeaderLogos = (bool) ($pageCfg['show_header_logos'] ?? true);
    $showWatermark   = (bool) ($pageCfg['show_watermark'] ?? false);
@endphp

@section('content')
<div class="px-6 py-8">
    <!-- Top Header & Action Bar -->
    <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between no-print">
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
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold text-slate-800">
                        Pratinjau: {{ $mouTemplate->name }}
                    </h1>
                    <span class="rounded bg-slate-100 border border-slate-200 px-2 py-0.5 text-xs font-mono text-slate-600">
                        v{{ $mouTemplate->version_number }}
                    </span>
                    <span id="previewPageCountBadge" class="rounded bg-blue-50 border border-blue-200 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                        1 Halaman A4
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">Pratinjau visual tata letak A4 dan berkas PDF resmi.</p>
            </div>
        </div>

        <!-- Action Dropdown Toggle -->
        <div class="relative inline-block text-left" id="previewActionDropdownContainer">
            <button
                type="button"
                id="btnPreviewActionToggle"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                aria-haspopup="true"
                aria-expanded="false"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="1"/>
                    <circle cx="12" cy="5" r="1"/>
                    <circle cx="12" cy="19" r="1"/>
                </svg>
                <span>Aksi Dokumen</span>
                <svg class="h-3.5 w-3.5 text-slate-400 transition-transform duration-150" id="previewActionChevron" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>

            <!-- Dropdown Menu -->
            <div
                id="previewActionDropdownMenu"
                class="hidden absolute right-0 mt-1.5 w-56 rounded-xl border border-slate-200 bg-white py-1.5 shadow-lg ring-1 ring-black/5 z-50 divide-y divide-slate-100"
                role="menu"
            >
                <div class="py-1">
                    <a
                        href="{{ route('admin.mou-templates.edit', $mouTemplate) }}"
                        class="flex items-center gap-2.5 px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                        role="menuitem"
                    >
                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-medium text-slate-800">Ubah Naskah</div>
                            <div class="text-[10px] text-slate-400">Buka editor Word studio</div>
                        </div>
                    </a>
                </div>
                <div class="py-1">
                    <a
                        href="{{ $pdfUrl }}"
                        target="_blank"
                        class="flex items-center gap-2.5 px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700 transition"
                        role="menuitem"
                    >
                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-medium text-slate-800">Unduh PDF (A4)</div>
                            <div class="text-[10px] text-slate-400">Ekspor berkas PDF resmi</div>
                        </div>
                    </a>

                    <button
                        type="button"
                        id="btnDropdownPrint"
                        onclick="window.print()"
                        class="flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition"
                        role="menuitem"
                    >
                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 6 2 18 2 18 9"/>
                                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                <rect x="6" y="14" width="12" height="8"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-medium text-slate-800">Cetak Browser</div>
                            <div class="text-[10px] text-slate-400">Dialog cetak halaman</div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar Bar: Mode View, Zoom, & Simulasi Data -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-2.5 shadow-2xs no-print">
        <div class="flex flex-wrap items-center gap-3">
            <!-- Mode Switcher -->
            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-100 p-0.5 text-xs">
                <button
                    type="button"
                    id="btnTabSheetPreview"
                    class="inline-flex items-center gap-1.5 rounded-md bg-white px-3 py-1.5 font-semibold text-slate-800 shadow-2xs transition"
                >
                    <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    Lembar Kertas A4
                </button>
                <button
                    type="button"
                    id="btnTabOfficialPdf"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-semibold text-slate-600 hover:text-slate-900 transition"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <path d="M9 15h6M9 11h6"/>
                    </svg>
                    Official PDF Preview
                </button>
            </div>

            <!-- Zoom Selector (Tab Lembar A4) -->
            <div id="previewZoomControlGroup" class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-700">
                <span class="font-medium text-slate-500">Zoom:</span>
                <select id="previewZoomSelect" class="bg-transparent border-none text-xs font-semibold focus:outline-none cursor-pointer text-slate-800">
                    <option value="fit-width">Fit Lebar</option>
                    <option value="1.0" selected>100%</option>
                    <option value="0.9">90%</option>
                    <option value="0.8">80%</option>
                    <option value="0.75">75%</option>
                    <option value="0.6">60%</option>
                    <option value="0.5">50%</option>
                </select>
            </div>
        </div>

        <!-- Simulasi Institusi Mitra -->
        <div class="flex items-center gap-2">
            <form method="GET" action="{{ route('admin.mou-templates.preview', $mouTemplate) }}" class="flex items-center gap-2">
                <label for="previewInstitutionSelect" class="text-xs font-medium text-slate-500 whitespace-nowrap">Simulasi Data:</label>
                <select
                    id="previewInstitutionSelect"
                    name="institution_id"
                    onchange="this.form.submit()"
                    class="rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-xs font-medium text-slate-700 focus:border-blue-500 focus:outline-none shadow-2xs"
                >
                    <option value="">-- Data Contoh (Placeholder) --</option>
                    @foreach ($institutions as $inst)
                        <option value="{{ $inst->id }}" {{ $selectedInstitution?->id === $inst->id ? 'selected' : '' }}>
                            {{ $inst->name }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    <!-- Info Catatan Ringkas -->
    <div class="mb-4 flex items-center gap-2.5 rounded-lg border border-blue-200/80 bg-blue-50/70 px-3.5 py-2 text-xs text-blue-900 no-print">
        <svg class="h-4 w-4 shrink-0 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
        </svg>
        <span class="leading-relaxed">
            <strong>Catatan:</strong> Tab <em>Lembar Kertas A4</em> mensimulasikan tata letak visual dokumen. Untuk hasil cetak resmi yang 100% presisi, gunakan tab <strong>Official PDF Preview</strong>.
        </span>
    </div>

    <!-- Hidden Raw Rendered HTML Source for Multi-Page A4 Pagination -->
    <div id="previewCanonicalSource" class="hidden">{!! $renderedHtml !!}</div>

    <!-- TAB 1: Multi-Page A4 Paper Sheets Simulation -->
    <div id="panelSheetPreview" class="bg-slate-300/80 py-8 px-4 rounded-xl border border-slate-300 overflow-x-auto print:bg-white print:p-0 print:border-none">
        <div id="previewScaleContainer" class="flex flex-col items-center origin-top transition-transform duration-150">
            <div
                id="previewA4PagesStage"
                class="mou-preview-pages-stage"
                style="--mou-pad-top: {{ $padTopMm }}mm; --mou-pad-bottom: {{ $padBtmMm }}mm; --mou-margin-top: {{ $marginTop }}mm; --mou-margin-right: {{ $marginRight }}mm; --mou-margin-bottom: {{ $marginBottom }}mm; --mou-margin-left: {{ $marginLeft }}mm; --mou-header-gap: {{ $headerGapMm }}mm; --mou-footer-gap: {{ $footerGapMm }}mm; --mou-font-family: '{{ $pageCfg['font_family'] ?? 'Times New Roman' }}', Times, serif; --mou-font-size: {{ (int)($pageCfg['font_size_pt'] ?? 12) }}pt; --mou-line-height: {{ (float)($pageCfg['line_height'] ?? 1.15) }};"
            ></div>
        </div>
    </div>

    <!-- TAB 2: Official DomPDF Preview Panel -->
    <div id="panelOfficialPdf" class="hidden rounded-xl border border-slate-300 bg-slate-800 p-3 shadow-inner no-print">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-200 px-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                <span class="font-medium">Pratinjau Keluaran PDF Resmi (Dirender langsung oleh mesin DomPDF)</span>
            </div>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    id="btnReloadOfficialPdf"
                    class="inline-flex items-center gap-1 text-slate-300 hover:text-white transition"
                    title="Muat Ulang PDF"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                    </svg>
                    Segarkan PDF
                </button>
                <a
                    href="{{ $pdfUrl }}"
                    target="_blank"
                    class="inline-flex items-center gap-1 rounded bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white shadow-xs hover:bg-blue-700 transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    Buka PDF di Tab Baru
                </a>
            </div>
        </div>

        <div class="relative w-full rounded-lg bg-slate-900 border border-slate-700 overflow-hidden" style="height: 85vh;">
            <!-- Loading Indicator -->
            <div id="pdfLoadingIndicator" class="hidden absolute inset-0 flex flex-col items-center justify-center bg-slate-900/90 text-slate-300 z-10 transition-opacity duration-200">
                <svg class="animate-spin h-8 w-8 text-blue-500 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-sm font-semibold text-white">Merender PDF Dokumen Resmi...</span>
                <span class="text-xs text-slate-400 mt-1">Menghasilkan tata letak halaman A4, header, footer paraf, dan logo institusi</span>
            </div>

            <!-- Fallback Message if browser blocks embedded PDF -->
            <div id="pdfFallbackContainer" class="hidden absolute inset-0 flex flex-col items-center justify-center bg-slate-900 text-slate-300 p-6 text-center z-5">
                <svg class="h-12 w-12 text-slate-500 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                <p class="text-sm font-semibold text-white mb-1">Browser Anda memblokir penampilan PDF langsung di dalam frame.</p>
                <p class="text-xs text-slate-400 mb-4 max-w-md">Dokumen PDF resmi berhasil dibuat oleh sistem. Silakan klik tombol di bawah untuk membuka naskah PDF di tab baru atau mengunduhnya.</p>
                <div class="flex items-center gap-3">
                    <a href="{{ $pdfUrl }}" target="_blank" class="rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow hover:bg-blue-700 transition">
                        Buka Naskah PDF Resmi
                    </a>
                    <a href="{{ $pdfUrl }}" download class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow hover:bg-emerald-700 transition">
                        Unduh Berkas PDF (A4)
                    </a>
                </div>
            </div>

            <!-- Iframe for PDF rendering -->
            <iframe
                id="officialPdfPreviewIframe"
                data-src="{{ $pdfUrl }}"
                title="Official PDF Preview"
                class="w-full h-full bg-white border-0"
                style="height: 85vh;"
            ></iframe>
        </div>
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
    padding-top: var(--mou-pad-top, 12mm);
    padding-right: var(--mou-margin-right, 25.4mm);
    padding-bottom: var(--mou-pad-bottom, 10mm);
    padding-left: var(--mou-margin-left, 25.4mm);
    font-family: var(--mou-font-family, 'Times New Roman', Times, serif);
    font-size: var(--mou-font-size, 12pt);
    line-height: var(--mou-line-height, 1.15);
}
.mou-a4-page-header {
    width: 100%;
    flex-shrink: 0;
    margin-bottom: var(--mou-header-gap, 3mm);
    box-sizing: border-box;
}
.mou-a4-page-body {
    flex: 1 1 0%;
    width: 100%;
    min-height: 0;
    position: relative;
    overflow: hidden;
}
.mou-a4-page-body .mou-page-break {
    display: none !important;
}
.mou-a4-page-footer {
    width: 100%;
    min-height: 15mm;
    flex-shrink: 0;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 8px;
    margin-top: var(--mou-footer-gap, 3mm);
    padding-top: 2mm;
    box-sizing: border-box;
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
    .no-print, header, aside, footer, nav, #admin-topbar, #admin-sidebar {
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
    main, #admin-main-content, .px-6, .py-8 {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        min-height: 0 !important;
        max-width: none !important;
    }
    #panelSheetPreview {
        background: transparent !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
        overflow: visible !important;
        box-shadow: none !important;
    }
    #previewScaleContainer {
        transform: none !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        display: block !important;
    }
    .mou-preview-pages-stage {
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
        margin: 0 auto !important;
        border: none !important;
        box-shadow: none !important;
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
    const showHeaderLogos = @json($showHeaderLogos);
    const showWatermark = @json($showWatermark);
    const resolvedInstitutionLogo = @json($resolvedInstitutionLogo);
    const resolvedCompanyLogo = @json($resolvedCompanyLogo);

    if (!sourceEl || !stageEl) return;

    function createSheet(index) {
        const page = document.createElement('div');
        page.className = 'mou-a4-page';
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

        const activeHeaderHtml = (differentFirstPage && index === 1) ? firstPageHeaderHtml : headerHtml;
        const activeFooterHtml = (differentFirstPage && index === 1 && firstPageFooterHtml !== '') ? firstPageFooterHtml : footerHtml;

        let header = null;
        if (activeHeaderHtml && activeHeaderHtml.trim() !== '' && activeHeaderHtml.trim() !== '<p></p>') {
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
            <div class="mou-a4-footer-custom flex-1 min-w-0" style="${activeFooterHtml ? '' : 'visibility: hidden;'}">
                ${activeFooterHtml || ''}
            </div>
            <div class="mou-preview-pagenum font-serif text-[9pt] text-black pb-0.5 shrink-0 select-none text-right whitespace-nowrap" style="${showPageNum ? '' : 'visibility: hidden;'}">
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
        return last.offsetTop + last.offsetHeight + mb;
    }

    function getMaxBodyHeight(sheet) {
        if (sheet.body && sheet.body.clientHeight > 100) {
            return sheet.body.clientHeight - 4;
        }
        const cs = window.getComputedStyle(sheet.page);
        const padTop = parseFloat(cs.paddingTop) || 45;
        const padBtm = parseFloat(cs.paddingBottom) || 38;
        const headerH = sheet.header ? (sheet.header.offsetHeight + 10) : 0;
        const footerH = sheet.footer ? (sheet.footer.offsetHeight + 8) : 60;
        return Math.max(200, (sheet.page.offsetHeight || 1122.5) - padTop - padBtm - headerH - footerH - 8);
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

    function splitAndAppendPasal(pasalNode, curSheet, openPageFn, getMaxBodyHeightFn, getUsedHeightFn) {
        const heading = pasalNode.querySelector('.mou-pasal-heading');
        const body = pasalNode.querySelector('.mou-pasal-body') || pasalNode;
        const units = [];
        Array.from(body.children).forEach(ch => {
            if (ch === heading) return;
            if (ch.tagName === 'OL' && ch.classList.contains('mou-ayat-list')) {
                Array.from(ch.children).forEach(li => units.push({ type: 'ayat', node: li.cloneNode(true) }));
            } else {
                units.push({ type: 'block', node: ch.cloneNode(true) });
            }
        });

        if (units.length <= 1) {
            curSheet.body.appendChild(pasalNode);
            return curSheet;
        }

        let sheet = curSheet;
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

        appendUnit(pBody, units[0]);
        sheet.body.appendChild(primary);

        let activeBody = pBody;
        for (let i = 1; i < units.length; i++) {
            const { added, createdOl } = appendUnit(activeBody, units[i]);
            const maxH = getMaxBodyHeightFn(sheet);
            const usedH = getUsedHeightFn(sheet.body);

            if (usedH > maxH) {
                if (createdOl) createdOl.remove(); else added.remove();
                sheet = openPageFn();
                const cont = document.createElement('div');
                cont.className = 'mou-pasal mou-pasal-continuation';
                activeBody = document.createElement('div');
                activeBody.className = 'mou-pasal-body';
                cont.appendChild(activeBody);
                sheet.body.appendChild(cont);
                appendUnit(activeBody, units[i]);
            }
        }

        return sheet;
    }

    function finalizePageNumbers(pages) {
        const total = pages.length || 1;
        pages.forEach((p, i) => {
            const lbl = p.page.querySelector('.mou-preview-pagenum');
            if (lbl) lbl.textContent = `Halaman ${i + 1} dari ${total}`;
        });
        if (badgeEl) badgeEl.textContent = `${total} Halaman A4`;

        const zoomSel = document.getElementById('previewZoomSelect');
        if (zoomSel) {
            applyPreviewZoom(zoomSel.value);
        }
    }

    function paginatePreview() {
        const innerRoot = sourceEl.querySelector('.mou-document, .mou-doc-canvas') || sourceEl;
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

            finalizePageNumbers(pages);
            return;
        }

        // PRIORITAS 2: Pagination cerdas berbasis aliran naskah alami & pemisah halaman manual (Page Break)
        const rawBlocks = Array.from(innerRoot.children).filter(el => {
            return !el.classList.contains('mou-a4-page-header') &&
                   !el.classList.contains('mou-a4-page-footer') &&
                   !el.hasAttribute('data-pagination-artifact');
        });

        if (rawBlocks.length === 0) {
            openPage();
            finalizePageNumbers(pages);
            return;
        }

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

        for (let i = 0; i < rawBlocks.length; i++) {
            const block = rawBlocks[i].cloneNode(true);

            // Pemisah Halaman Manual / Terkomposisi (Page Break)
            if (block.classList.contains('mou-page-break') || block.getAttribute('data-page-break') === 'true') {
                if (cur.body.children.length > 0 && i < rawBlocks.length - 1) {
                    cur = openPage();
                }
                continue;
            }

            // Proteksi Orphan Heading: Jika block adalah heading Pasal/Subjudul dan halaman sudah terisi,
            // uji apakah heading ini BESERTA blok isi pertamanya muat di sisa halaman.
            // Jika tidak muat bersama-sama, buka halaman baru agar heading tidak tertinggal sendirian di dasar lembar.
            if (isHeadingElement(rawBlocks[i]) && cur.body.children.length > 0) {
                let hEnd = i;
                while (hEnd < rawBlocks.length && isHeadingElement(rawBlocks[hEnd])) {
                    hEnd++;
                }
                const testClones = [];
                for (let k = i; k <= Math.min(hEnd, rawBlocks.length - 1); k++) {
                    const cl = rawBlocks[k].cloneNode(true);
                    testClones.push(cl);
                    cur.body.appendChild(cl);
                }
                const testMaxH = getMaxBodyHeight(cur);
                const testUsedH = getUsedHeight(cur.body);
                testClones.forEach(cl => cur.body.removeChild(cl));

                if (testUsedH > testMaxH) {
                    cur = openPage();
                }
            }

            // Uji masukkan ke lembar saat ini
            cur.body.appendChild(block);
            const maxH = getMaxBodyHeight(cur);
            const usedH = getUsedHeight(cur.body);

            if (usedH <= maxH) {
                // Muat dengan sempurna di halaman saat ini
                continue;
            }

            // Terjadi overflow: keluarkan block
            cur.body.removeChild(block);

            // Jika halaman saat ini sudah ada isinya, buka halaman baru
            if (cur.body.children.length > 0) {
                cur = openPage();
            }

            // Di lembar baru yang masih kosong:
            if (block.tagName === 'TABLE') {
                cur = splitAndAppendTable(block, cur, openPage, getMaxBodyHeight, getUsedHeight);
            } else if (block.classList.contains('mou-pasal') || block.getAttribute('data-pasal') === 'true') {
                cur = splitAndAppendPasal(block, cur, openPage, getMaxBodyHeight, getUsedHeight);
            } else {
                cur.body.appendChild(block);
            }
        }

        finalizePageNumbers(pages);
    }

    paginatePreview();
    window.addEventListener('load', paginatePreview);

    // Zoom Handler
    function applyPreviewZoom(zoomVal) {
        const scrollContainer = document.getElementById('panelSheetPreview');
        const scaleContainer = document.getElementById('previewScaleContainer');
        const stage = document.getElementById('previewA4PagesStage');
        if (!scrollContainer || !scaleContainer || !stage) return;

        const a4W = 210 * (96 / 25.4);
        const a4H = 297 * (96 / 25.4);
        let scale = 1.0;

        if (zoomVal === 'fit-width') {
            const availW = Math.max(300, scrollContainer.clientWidth - 56);
            scale = Math.min(1.15, Math.max(0.4, availW / a4W));
        } else {
            scale = parseFloat(zoomVal) || 1.0;
        }

        scaleContainer.style.transform = `scale(${scale})`;
        scaleContainer.style.transformOrigin = 'top center';

        const totalSheets = stage.querySelectorAll('.mou-a4-page').length || 1;
        const gap = 28;
        const naturalH = totalSheets * a4H + (totalSheets - 1) * gap;
        const scaledH = naturalH * scale;
        scaleContainer.style.marginBottom = `${scaledH - naturalH}px`;
    }

    let prePrintTransform = '';
    window.addEventListener('beforeprint', () => {
        const scaleContainer = document.getElementById('previewScaleContainer');
        if (scaleContainer) {
            prePrintTransform = scaleContainer.style.transform;
            scaleContainer.style.transform = 'none';
            scaleContainer.style.marginBottom = '0px';
        }
    });

    window.addEventListener('afterprint', () => {
        const scaleContainer = document.getElementById('previewScaleContainer');
        if (scaleContainer && prePrintTransform) {
            scaleContainer.style.transform = prePrintTransform;
            const zoomSel = document.getElementById('previewZoomSelect');
            if (zoomSel) applyPreviewZoom(zoomSel.value);
        }
    });

    // Tab Switcher & Official PDF Loader
    const btnSheet = document.getElementById('btnTabSheetPreview');
    const btnPdf = document.getElementById('btnTabOfficialPdf');
    const panelSheet = document.getElementById('panelSheetPreview');
    const panelPdf = document.getElementById('panelOfficialPdf');
    const pdfIframe = document.getElementById('officialPdfPreviewIframe');
    const pdfLoader = document.getElementById('pdfLoadingIndicator');
    const pdfFallback = document.getElementById('pdfFallbackContainer');
    const btnReloadPdf = document.getElementById('btnReloadOfficialPdf');
    const zoomGroup = document.getElementById('previewZoomControlGroup');

    let cachedPdfBlobUrl = null;
    let isPdfLoading = false;

    function loadOfficialPdf(forceReload = false) {
        if (!pdfIframe) return;
        const pdfUrl = pdfIframe.getAttribute('data-src');
        if (!pdfUrl) return;

        // Jika sudah pernah dimuat dan tidak diminta muat ulang paksa
        if (cachedPdfBlobUrl && !forceReload) {
            if (pdfLoader) pdfLoader.classList.add('hidden');
            return;
        }

        if (isPdfLoading && !forceReload) return;
        isPdfLoading = true;

        if (pdfLoader) pdfLoader.classList.remove('hidden');
        if (pdfFallback) pdfFallback.classList.add('hidden');

        fetch(pdfUrl, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP status ' + response.status);
            }
            return response.blob();
        })
        .then(blob => {
            if (cachedPdfBlobUrl) {
                URL.revokeObjectURL(cachedPdfBlobUrl);
            }
            cachedPdfBlobUrl = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));

            pdfIframe.onload = () => {
                if (pdfLoader) pdfLoader.classList.add('hidden');
                isPdfLoading = false;
            };
            pdfIframe.onerror = () => {
                if (pdfLoader) pdfLoader.classList.add('hidden');
                if (pdfFallback) pdfFallback.classList.remove('hidden');
                isPdfLoading = false;
            };

            pdfIframe.src = cachedPdfBlobUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH';

            // Chromium terkadang tidak memicu onload pada iframe yang memuat PDF viewer plugin
            setTimeout(() => {
                if (pdfLoader) pdfLoader.classList.add('hidden');
                isPdfLoading = false;
            }, 1200);
        })
        .catch(err => {
            console.warn('Gagal memuat blob PDF, mencoba URL langsung:', err);
            // Fallback direct URL jika fetch blob terkendala
            pdfIframe.onload = () => {
                if (pdfLoader) pdfLoader.classList.add('hidden');
                isPdfLoading = false;
            };
            pdfIframe.onerror = () => {
                if (pdfLoader) pdfLoader.classList.add('hidden');
                if (pdfFallback) pdfFallback.classList.remove('hidden');
                isPdfLoading = false;
            };

            pdfIframe.src = pdfUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH';

            setTimeout(() => {
                if (pdfLoader) pdfLoader.classList.add('hidden');
                isPdfLoading = false;
            }, 1800);
        });
    }

    const activeTabClass = 'inline-flex items-center gap-1.5 rounded-md bg-white px-3 py-1.5 font-semibold text-slate-800 shadow-2xs transition';
    const inactiveTabClass = 'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-semibold text-slate-600 hover:text-slate-900 transition';

    btnSheet?.addEventListener('click', function () {
        panelSheet.classList.remove('hidden');
        panelPdf.classList.add('hidden');
        if (zoomGroup) zoomGroup.classList.remove('hidden');
        btnSheet.className = activeTabClass;
        btnPdf.className = inactiveTabClass;
        applyPreviewZoom(document.getElementById('previewZoomSelect')?.value || '1.0');
    });

    btnPdf?.addEventListener('click', function () {
        panelSheet.classList.add('hidden');
        panelPdf.classList.remove('hidden');
        if (zoomGroup) zoomGroup.classList.add('hidden');
        btnPdf.className = activeTabClass;
        btnSheet.className = inactiveTabClass;
        loadOfficialPdf();
    });

    btnReloadPdf?.addEventListener('click', function () {
        loadOfficialPdf(true);
    });

    // Action Dropdown Toggle
    const btnActionToggle = document.getElementById('btnPreviewActionToggle');
    const actionMenu = document.getElementById('previewActionDropdownMenu');
    const actionChevron = document.getElementById('previewActionChevron');

    if (btnActionToggle && actionMenu) {
        btnActionToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const isHidden = actionMenu.classList.contains('hidden');
            actionMenu.classList.toggle('hidden', !isHidden);
            btnActionToggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            if (actionChevron) {
                actionChevron.classList.toggle('rotate-180', isHidden);
            }
        });

        actionMenu.addEventListener('click', function (e) {
            if (e.target.closest('a') || e.target.closest('button')) {
                actionMenu.classList.add('hidden');
                btnActionToggle.setAttribute('aria-expanded', 'false');
                if (actionChevron) actionChevron.classList.remove('rotate-180');
            }
        });

        window.addEventListener('click', function (e) {
            if (!actionMenu.classList.contains('hidden') && !e.target.closest('#previewActionDropdownContainer')) {
                actionMenu.classList.add('hidden');
                btnActionToggle.setAttribute('aria-expanded', 'false');
                if (actionChevron) actionChevron.classList.remove('rotate-180');
            }
        });

        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !actionMenu.classList.contains('hidden')) {
                actionMenu.classList.add('hidden');
                btnActionToggle.setAttribute('aria-expanded', 'false');
                if (actionChevron) actionChevron.classList.remove('rotate-180');
            }
        });
    }

    document.getElementById('previewZoomSelect')?.addEventListener('change', function (e) {
        applyPreviewZoom(e.target.value);
    });

    window.addEventListener('resize', function () {
        const zoomSel = document.getElementById('previewZoomSelect');
        if (zoomSel && zoomSel.value === 'fit-width') {
            applyPreviewZoom('fit-width');
        }
    });
})();
</script>
@endsection
