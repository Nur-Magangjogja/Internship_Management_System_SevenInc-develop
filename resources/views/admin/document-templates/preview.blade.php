@extends('layouts.dashboard', ['title' => 'Pratinjau: ' . $template->name])

@php
    $pageCfg = array_merge(\App\Models\DocumentTemplate::getDefaultPageConfig($template->type), $template->page_config ?? []);
    $isCertificate = ($template->type === 'certificate' || $template->editor_type === 'visual_canvas');
    $paperSize = strtolower((string)($pageCfg['paper_size'] ?? 'a4'));
    $orientation = strtolower((string)($pageCfg['orientation'] ?? ($isCertificate ? 'landscape' : 'portrait')));
    
    $marginTop    = (float) ($pageCfg['margin_top_mm'] ?? 20.0);
    $marginRight  = (float) ($pageCfg['margin_right_mm'] ?? 20.0);
    $marginBottom = (float) ($pageCfg['margin_bottom_mm'] ?? 20.0);
    $marginLeft   = (float) ($pageCfg['margin_left_mm'] ?? 20.0);

    $pdfUrl = route('admin.document-templates.preview-pdf', $template);
    $rawUrl = route('admin.document-templates.preview', $template) . '?raw=1';

    $paperWidthMm  = $isCertificate ? ($orientation === 'landscape' ? 297 : 210) : ($orientation === 'landscape' ? 297 : 210);
    $paperHeightMm = $isCertificate ? ($orientation === 'landscape' ? 210 : 297) : ($orientation === 'landscape' ? 210 : 297);
@endphp

@section('content')
<div class="px-4 sm:px-6 py-6 max-w-7xl mx-auto">
    <!-- Top Action Bar -->
    <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-4 no-print">
        <div>
            <div class="flex flex-wrap items-center gap-2 mb-1.5">
                <a
                    href="{{ route('admin.document-templates.index') }}"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-2xs hover:bg-slate-50 transition"
                    title="Kembali ke Daftar Template"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                </a>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $template->getTypeBadgeClass($template->type) }}">
                    {{ $template->getTypeLabel($template->type) }}
                </span>
                <span class="text-xs font-mono bg-slate-100 border border-slate-200 text-slate-700 px-2 py-0.5 rounded">
                    Versi {{ $template->version_number }}
                </span>
                <span class="text-xs font-mono bg-blue-50 border border-blue-200 text-blue-700 px-2 py-0.5 rounded">
                    {{ strtoupper($paperSize) }} {{ ucfirst($orientation) }} ({{ $paperWidthMm }} × {{ $paperHeightMm }} mm)
                </span>
            </div>
            <h1 class="text-xl font-bold text-slate-800">Pratinjau: {{ $template->name }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">Tampilan tata letak resmi sesuai format kertas fisik dan data contoh pemagang Seven Inc.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ route('admin.document-templates.edit', $template) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                </svg>
                Kembali ke Editor
            </a>

            <button
                type="button"
                onclick="printPreviewDocument()"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak Dokumen
            </button>

            <a
                href="{{ $pdfUrl }}"
                target="_blank"
                class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                Unduh / Buka PDF Asli
            </a>
        </div>
    </div>

    <!-- Toolbar: Tab Switcher & Zoom Controls -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-2.5 shadow-2xs no-print">
        <div class="flex flex-wrap items-center gap-3">
            <!-- Mode Switcher -->
            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-100 p-0.5 text-xs">
                <button
                    type="button"
                    id="btnTabSheetPreview"
                    onclick="switchPreviewTab('sheet')"
                    class="inline-flex items-center gap-1.5 rounded-md bg-white px-3 py-1.5 font-semibold text-slate-800 shadow-2xs transition"
                >
                    <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    <span>Simulasi Format Kertas (A4)</span>
                </button>
                <button
                    type="button"
                    id="btnTabOfficialPdf"
                    onclick="switchPreviewTab('pdf')"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-semibold text-slate-600 hover:text-slate-900 transition"
                >
                    <svg class="h-3.5 w-3.5 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <path d="M9 15h6M9 11h6"/>
                    </svg>
                    <span>Pratinjau PDF Asli (DomPDF)</span>
                </button>
            </div>

            <!-- Zoom Controls (For Sheet Preview) -->
            <div id="previewZoomControlGroup" class="flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">
                <button
                    type="button"
                    onclick="adjustZoom(-0.1)"
                    title="Perkecil Zoom (-)"
                    class="p-1 rounded text-slate-600 hover:bg-slate-200 transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                </button>

                <select
                    id="previewZoomSelect"
                    onchange="setZoomLevel(this.value)"
                    class="bg-transparent border-none text-xs font-semibold focus:outline-none cursor-pointer text-slate-800 px-1 py-0.5"
                >
                    <option value="fit">Sesuaikan Layar</option>
                    <option value="1.0" selected>100% (Ukuran Nyata)</option>
                    <option value="0.9">90%</option>
                    <option value="0.8">80%</option>
                    <option value="0.75">75%</option>
                    <option value="0.6">60%</option>
                    <option value="0.5">50%</option>
                </select>

                <button
                    type="button"
                    onclick="adjustZoom(0.1)"
                    title="Perbesar Zoom (+)"
                    class="p-1 rounded text-slate-600 hover:bg-slate-200 transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Margin & Dimension Meta Info -->
        <div class="flex items-center gap-2 text-xs text-slate-500">
            @if(!$isCertificate)
                <span class="inline-flex items-center gap-1 rounded bg-slate-100 px-2.5 py-1 text-[11px] font-mono text-slate-600 border border-slate-200">
                    <svg class="h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    </svg>
                    Margin: Atas {{ $marginTop }}mm • Kanan {{ $marginRight }}mm • Bawah {{ $marginBottom }}mm • Kiri {{ $marginLeft }}mm
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 border border-amber-200">
                    Format Kanvas Sertifikat A4 Lanskap (Ukuran Penuh)
                </span>
            @endif
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
            <strong>Catatan Tampilan:</strong> Tab <em>Simulasi Format Kertas</em> menampilkan naskah dengan margin dan proporsi kertas {{ strtoupper($paperSize) }} asli tanpa terpotong tepi. Untuk memeriksa tata letak mesin cetak DomPDF, gunakan tab <strong>Pratinjau PDF Asli</strong>.
        </span>
    </div>

    <!-- TAB 1: Visual Paper Sheet Simulation (Sandboxed Iframe with Scale Container) -->
    <div id="panelSheetPreview" class="bg-slate-300/80 py-8 px-4 rounded-2xl border border-slate-300 overflow-x-auto shadow-inner flex flex-col items-center min-h-[600px] no-print:bg-slate-300/80 print:bg-white print:p-0 print:border-none">
        <div id="previewScaleContainer" class="flex flex-col items-center origin-top transition-transform duration-150 ease-out">
            <iframe
                id="paperSheetIframe"
                src="{{ $rawUrl }}"
                title="Pratinjau Lembar Kertas"
                class="border-0 bg-transparent transition-all rounded-xs shadow-2xl"
                style="width: {{ $paperWidthMm }}mm; min-width: {{ $paperWidthMm }}mm; height: {{ $paperHeightMm }}mm; min-height: {{ $paperHeightMm }}mm;"
                onload="handlePaperIframeLoaded(this)"
            ></iframe>
        </div>
    </div>

    <!-- TAB 2: Official DomPDF Preview Panel -->
    <div id="panelOfficialPdf" class="hidden rounded-2xl border border-slate-300 bg-slate-900 p-3 shadow-inner no-print">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-200 px-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                <span class="font-medium">Pratinjau Keluaran PDF Resmi (Dirender langsung oleh mesin DomPDF)</span>
            </div>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    onclick="reloadOfficialPdf()"
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
                    class="inline-flex items-center gap-1 rounded bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-blue-700 transition"
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

        <div class="relative w-full rounded-xl bg-slate-950 border border-slate-800 overflow-hidden" style="height: 85vh;">
            <iframe
                id="officialPdfPreviewIframe"
                data-src="{{ $pdfUrl }}"
                title="Official PDF Preview"
                class="w-full h-full bg-white border-0"
                style="height: 85vh;"
            ></iframe>
        </div>
    </div>

    <!-- Mock Data Substitution Legend (Collapsible) -->
    <details class="mt-8 rounded-xl border border-slate-200 bg-white p-5 shadow-2xs no-print group" open>
        <summary class="text-xs font-bold uppercase tracking-wider text-slate-600 cursor-pointer flex items-center justify-between select-none">
            <span class="flex items-center gap-2">
                <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <line x1="3" y1="9" x2="21" y2="9"/>
                    <line x1="9" y1="21" x2="9" y2="9"/>
                </svg>
                Data Simulasi yang Digunakan dalam Pratinjau Ini:
            </span>
            <span class="text-slate-400 group-open:rotate-180 transition-transform duration-150">▼</span>
        </summary>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 text-xs mt-4">
            <div class="rounded-lg bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">pemagang.nama</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['pemagang']['nama'] ?? '-' }}</span>
            </div>
            <div class="rounded-lg bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">pemagang.nim</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['pemagang']['nim'] ?? '-' }}</span>
            </div>
            <div class="rounded-lg bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">pemagang.institusi</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['pemagang']['institusi'] ?? '-' }}</span>
            </div>
            <div class="rounded-lg bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">magang.divisi</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['magang']['divisi'] ?? '-' }}</span>
            </div>
            <div class="rounded-lg bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">dokumen.nomor</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['dokumen']['nomor'] ?? '-' }}</span>
            </div>
            <div class="rounded-lg bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">perusahaan.pimpinan</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['perusahaan']['pimpinan'] ?? '-' }}</span>
            </div>
        </div>
    </details>
</div>

<style>
@media print {
    .no-print, header, aside, footer, nav, #admin-topbar, #admin-sidebar {
        display: none !important;
    }
    html, body {
        background: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    #panelSheetPreview {
        background: transparent !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
    }
    #previewScaleContainer {
        transform: none !important;
        width: 100% !important;
    }
    #paperSheetIframe {
        box-shadow: none !important;
        width: 100% !important;
    }
}
</style>

<script>
(function() {
    let currentTab = 'sheet';
    let currentZoom = '1.0';
    const isCertificate = @json($isCertificate);
    const paperWidthMm = @json($paperWidthMm);

    window.switchPreviewTab = function(tab) {
        currentTab = tab;
        const btnSheet = document.getElementById('btnTabSheetPreview');
        const btnPdf   = document.getElementById('btnTabOfficialPdf');
        const panelSheet = document.getElementById('panelSheetPreview');
        const panelPdf   = document.getElementById('panelOfficialPdf');
        const zoomGroup  = document.getElementById('previewZoomControlGroup');
        const pdfIframe  = document.getElementById('officialPdfPreviewIframe');

        if (tab === 'sheet') {
            btnSheet.classList.add('bg-white', 'text-slate-800', 'shadow-2xs');
            btnSheet.classList.remove('text-slate-600');
            btnPdf.classList.remove('bg-white', 'text-slate-800', 'shadow-2xs');
            btnPdf.classList.add('text-slate-600');

            panelSheet.classList.remove('hidden');
            panelPdf.classList.add('hidden');
            zoomGroup.classList.remove('hidden');
        } else {
            btnPdf.classList.add('bg-white', 'text-slate-800', 'shadow-2xs');
            btnPdf.classList.remove('text-slate-600');
            btnSheet.classList.remove('bg-white', 'text-slate-800', 'shadow-2xs');
            btnSheet.classList.add('text-slate-600');

            panelPdf.classList.remove('hidden');
            panelSheet.classList.add('hidden');
            zoomGroup.classList.add('hidden');

            // Lazy load the PDF iframe on first switch
            if (pdfIframe && !pdfIframe.src && pdfIframe.getAttribute('data-src')) {
                pdfIframe.src = pdfIframe.getAttribute('data-src');
            }
        }
    };

    window.reloadOfficialPdf = function() {
        const pdfIframe = document.getElementById('officialPdfPreviewIframe');
        if (pdfIframe) {
            const src = pdfIframe.getAttribute('data-src') || pdfIframe.src;
            const sep = src.includes('?') ? '&' : '?';
            pdfIframe.src = src + sep + '_t=' + Date.now();
        }
    };

    window.setZoomLevel = function(val) {
        currentZoom = val;
        applyZoom();
    };

    window.adjustZoom = function(delta) {
        let num = parseFloat(currentZoom);
        if (isNaN(num)) num = 1.0;
        let newZoom = Math.min(1.5, Math.max(0.4, Math.round((num + delta) * 10) / 10));
        currentZoom = newZoom.toFixed(1);
        const sel = document.getElementById('previewZoomSelect');
        if (sel) {
            let found = false;
            for (let opt of sel.options) {
                if (opt.value === currentZoom) {
                    sel.value = currentZoom;
                    found = true;
                    break;
                }
            }
            if (!found) {
                sel.value = '1.0';
            }
        }
        applyZoom();
    };

    function applyZoom() {
        const container = document.getElementById('previewScaleContainer');
        const panel = document.getElementById('panelSheetPreview');
        const iframe = document.getElementById('paperSheetIframe');
        if (!container || !panel || !iframe) return;

        let scale = 1.0;
        if (currentZoom === 'fit') {
            const availableW = panel.clientWidth - 48; // padding margin
            const targetW = isCertificate ? 1123 : 794; // approx px for 297mm vs 210mm
            scale = Math.min(1.0, Math.max(0.35, availableW / targetW));
        } else {
            scale = parseFloat(currentZoom) || 1.0;
        }

        container.style.transform = `scale(${scale})`;
        container.style.transformOrigin = 'top center';

        // Adjust parent container margin/height to accommodate scaled content
        const baseH = iframe.offsetHeight || (isCertificate ? 794 : 1123);
        const scaledH = baseH * scale;
        container.style.marginBottom = `${Math.max(0, scaledH - baseH + 30)}px`;
    }

    window.handlePaperIframeLoaded = function(iframe) {
        try {
            const doc = iframe.contentWindow.document;
            if (doc && doc.body) {
                // If content overflows standard page height, auto expand height
                const contentHeight = doc.body.scrollHeight;
                if (!isCertificate && contentHeight > iframe.offsetHeight) {
                    iframe.style.height = (contentHeight + 30) + 'px';
                }
            }
        } catch (e) {
            console.warn('Iframe cross-origin or load check:', e);
        }
        // Auto-fit on initial load if screen is narrow or certificate is wide
        const panel = document.getElementById('panelSheetPreview');
        if (panel && (isCertificate || panel.clientWidth < 850)) {
            const sel = document.getElementById('previewZoomSelect');
            if (sel) {
                sel.value = 'fit';
                currentZoom = 'fit';
            }
        }
        applyZoom();
    };

    window.printPreviewDocument = function() {
        if (currentTab === 'sheet') {
            const iframe = document.getElementById('paperSheetIframe');
            if (iframe && iframe.contentWindow) {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                return;
            }
        } else {
            const iframe = document.getElementById('officialPdfPreviewIframe');
            if (iframe && iframe.contentWindow) {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                return;
            }
        }
        window.print();
    };

    window.addEventListener('resize', () => {
        if (currentZoom === 'fit') {
            applyZoom();
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        applyZoom();
    });
})();
</script>
@endsection
