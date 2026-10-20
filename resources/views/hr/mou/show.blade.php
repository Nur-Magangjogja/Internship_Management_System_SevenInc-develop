@extends('layouts.dashboard', ['title' => 'Detail Dokumen MOU - ' . $mou->company_mou_number])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.mous' : 'admin.mous';
@endphp

@section('content')
<div class="px-6 py-8 max-w-6xl mx-auto">
    <!-- Breadcrumb & Back -->
    <div class="mb-4 flex items-center justify-between no-print">
        <a href="{{ route($routePrefix . '.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800 transition flex items-center gap-1.5">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Kembali ke Daftar Dokumen MOU
        </a>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                onclick="handleMouPrint()"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak Browser
            </button>

            <a
                href="{{ route($routePrefix . '.download-pdf', $mou) }}"
                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Unduh Draf PDF (A4)
            </a>

            @if($mou->has_signed_file)
                <a
                    href="{{ route($routePrefix . '.download-signed', $mou) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Unduh Dokumen Bertandatangan (PDF)
                </a>
            @endif
        </div>
    </div>

    <!-- Alert Notifications -->
    @if(session('success'))
        <div class="mb-6 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 no-print">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 no-print">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Header Card -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm no-print">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold border {{ $mou->status_badge_classes }}">
                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        {{ $mou->status_label }}
                    </span>
                    @if($mou->superseded_by_id)
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 border border-slate-200">
                            Digantikan Dokumen:
                            <a href="{{ route($routePrefix . '.show', $mou->supersededBy) }}" class="underline hover:text-slate-900 ml-1 font-semibold">{{ $mou->supersededBy->company_mou_number }}</a>
                        </span>
                    @endif
                    @if($mou->supersedes_mou_id)
                        <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 border border-blue-200">
                            Pengganti Dokumen:
                            <a href="{{ route($routePrefix . '.show', $mou->supersedes) }}" class="underline hover:text-blue-900 ml-1 font-semibold">{{ $mou->supersedes->company_mou_number }}</a>
                        </span>
                    @endif
                </div>
                <h1 class="text-2xl font-bold text-slate-800 mt-2">{{ $mou->company_mou_number }}</h1>
                <p class="text-xs text-slate-500 mt-0.5">
                    Institusi Mitra: <span class="font-semibold text-slate-700">{{ $mou->first_party_institution_name }}</span>
                    @if($mou->institution_mou_number)
                        | No. Mitra: <span class="font-mono text-slate-600">{{ $mou->institution_mou_number }}</span>
                    @endif
                </p>
            </div>

            <!-- Action Buttons Toolbar -->
            <div class="flex flex-wrap items-center gap-2">
                @if($mou->status === \App\Models\Mou::STATUS_GENERATED)
                    <button
                        type="button"
                        onclick="document.getElementById('modalDelivered').classList.remove('hidden')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                        Tandai Serah Terima
                    </button>
                @endif

                @if($mou->status === \App\Models\Mou::STATUS_DELIVERED)
                    <button
                        type="button"
                        onclick="document.getElementById('modalUploadSigned').classList.remove('hidden')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        Unggah Naskah Bertandatangan & Aktifkan
                    </button>
                @endif

                @if(!in_array($mou->status, [\App\Models\Mou::STATUS_CANCELLED, \App\Models\Mou::STATUS_SUPERSEDED]))
                    <button
                        type="button"
                        onclick="document.getElementById('modalCancel').classList.remove('hidden')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3.5 py-2 text-xs font-semibold text-rose-600 shadow-sm hover:bg-rose-50 transition"
                    >
                        Batalkan Naskah
                    </button>
                @endif

                @if($mou->has_signed_file || $mou->signed_file_path)
                    @if($mou->is_drive_synced)
                        <a
                            href="{{ $mou->drive_web_view_link }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-xs font-semibold text-emerald-700 shadow-sm hover:bg-emerald-100 transition"
                            title="Buka naskah di Google Drive"
                        >
                            <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                            <span>Buka di Cloud Drive</span>
                        </a>
                    @endif

                    <form action="{{ route($routePrefix . '.sync-drive', $mou) }}" method="POST" class="inline-block">
                        @csrf
                        <button
                            type="submit"
                            title="{{ $mou->is_drive_synced ? 'Sinkronkan ulang berkas ke Google Drive' : 'Unggah salinan berkas ke Google Drive' }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition"
                        >
                            <svg class="h-3.5 w-3.5 {{ $mou->is_drive_synced ? 'text-emerald-600' : 'text-slate-500' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
                            </svg>
                            <span>{{ $mou->is_drive_synced ? 'Re-sync Cloud' : 'Sinkronkan ke Cloud' }}</span>
                        </button>
                    </form>
                @endif
            </div>

        </div>
    </div>

    <!-- Lifecycle Timeline Progress Tracker -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm no-print">
        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-6">
            Alur Siklus Hidup Dokumen (Lifecycle Timeline)
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 relative">
            <!-- Step 1: Generated -->
            <div class="flex items-start gap-3">
                <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0 {{ $mou->generated_at ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-800">1. Naskah Dibuat</div>
                    <div class="text-[11px] text-slate-500 mt-0.5">
                        {{ $mou->generated_at ? $mou->generated_at->isoFormat('D MMM Y, HH:mm') : '-' }}
                    </div>
                    <div class="text-[10px] text-slate-400">
                        Oleh: {{ $mou->generatedBy?->name ?? 'Sistem' }}
                    </div>
                </div>
            </div>

            <!-- Step 2: Delivered -->
            <div class="flex items-start gap-3">
                <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0 {{ $mou->delivered_at ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-800">2. Diserahkan ke Mitra</div>
                    <div class="text-[11px] text-slate-500 mt-0.5">
                        {{ $mou->delivered_at ? $mou->delivered_at->isoFormat('D MMM Y, HH:mm') : 'Menunggu' }}
                    </div>
                    @if($mou->delivery_method)
                        <div class="text-[10px] text-slate-400">
                            Via {{ ucfirst($mou->delivery_method) }}
                            @if($mou->delivery_recipient) ({{ $mou->delivery_recipient }}) @endif
                        </div>
                    @endif
                </div>
            </div>

            <!-- Step 3: Signed & Active -->
            <div class="flex items-start gap-3">
                <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0 {{ $mou->signed_at ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-400' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-800">3. Ditandatangani & Sah</div>
                    <div class="text-[11px] text-slate-500 mt-0.5">
                        {{ $mou->signed_at ? $mou->signed_at->isoFormat('D MMM Y, HH:mm') : 'Menunggu Berkas' }}
                    </div>
                    @if($mou->signedUploadedBy)
                        <div class="text-[10px] text-slate-400">
                            Diunggah oleh: {{ $mou->signedUploadedBy->name }}
                        </div>
                    @endif
                </div>
            </div>

            <!-- Step 4: Status Masa Berlaku -->
            <div class="flex items-start gap-3">
                <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0 {{ $mou->is_active ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-400' }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-800">4. Periode Kerja Sama</div>
                    <div class="text-[11px] text-slate-500 mt-0.5">
                        s/d {{ $mou->effective_end ? \Carbon\Carbon::parse($mou->effective_end)->isoFormat('D MMM Y') : '-' }}
                    </div>
                    <div class="text-[10px] text-slate-400">
                        {{ $mou->status === 'expired' ? 'Masa Berlaku Berakhir' : ($mou->is_active ? 'Sedang Berjalan' : 'Belum Berjalan') }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Metadata Details Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 no-print">
        <!-- Pihak Pertama -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 pb-2 border-b border-slate-100">
                Pihak Pertama (Institusi Mitra)
            </h3>
            <table class="w-full text-xs">
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400 w-36">Nama Institusi</td>
                    <td class="py-2 font-semibold text-slate-800">{{ $mou->first_party_institution_name }}</td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Unit Korespondensi</td>
                    <td class="py-2 text-slate-700">{{ $mou->first_party_correspondence_unit ?? '-' }}</td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Diwakili Oleh</td>
                    <td class="py-2 font-semibold text-slate-800">{{ $mou->first_party_signatory_name }}</td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Jabatan</td>
                    <td class="py-2 text-slate-700">{{ $mou->first_party_signatory_title }}</td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Kontak / Email</td>
                    <td class="py-2 text-slate-700">{{ $mou->first_party_phone ?? '-' }} / {{ $mou->first_party_email ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="py-2 text-slate-400 align-top">Alamat</td>
                    <td class="py-2 text-slate-700">{{ $mou->first_party_address ?? '-' }}</td>
                </tr>
            </table>
        </div>

        <!-- Pihak Kedua -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 pb-2 border-b border-slate-100">
                Pihak Kedua (Seven Inc)
            </h3>
            <table class="w-full text-xs">
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400 w-36">Nama Perusahaan</td>
                    <td class="py-2 font-semibold text-slate-800">
                        {{ $mou->second_party_company_name }}
                        @if($mou->brand)
                            <span class="ml-1.5 inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700">
                                Brand: {{ $mou->brand->name }} ({{ $mou->brand->code }})
                            </span>
                        @endif
                    </td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Diwakili Oleh</td>
                    <td class="py-2 font-semibold text-slate-800">{{ $mou->second_party_signatory_name }}</td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Jabatan</td>
                    <td class="py-2 text-slate-700">{{ $mou->second_party_signatory_title }}</td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Tempat Penandatanganan</td>
                    <td class="py-2 text-slate-700">{{ $mou->agreement_place }}</td>
                </tr>
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-400">Tanggal Naskah</td>
                    <td class="py-2 text-slate-700">{{ \Carbon\Carbon::parse($mou->agreement_date)->isoFormat('D MMMM Y') }}</td>
                </tr>
                <tr>
                    <td class="py-2 text-slate-400">Masa Berlaku</td>
                    <td class="py-2 font-semibold text-emerald-700">
                        {{ \Carbon\Carbon::parse($mou->effective_start)->isoFormat('D MMMM Y') }} s/d {{ \Carbon\Carbon::parse($mou->effective_end)->isoFormat('D MMMM Y') }}
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Live Document Naskah A4 Multi-Sheet Preview & PDF Viewer -->
    @php
        $cfg = $pageConfig ?? ($mou->render_data_snapshot['_page_config'] ?? \App\Models\MouTemplate::getDefaultPageConfig());
        $marginTop    = (float) ($cfg['margin_top_mm'] ?? 25.4);
        $marginRight  = (float) ($cfg['margin_right_mm'] ?? 25.4);
        $marginBottom = (float) ($cfg['margin_bottom_mm'] ?? 25.4);
        $marginLeft   = (float) ($cfg['margin_left_mm'] ?? 25.4);
        $showParaf = (bool) ($cfg['show_paraf_box'] ?? $cfg['show_paraf_footer'] ?? true);
        $showPageNum = (bool) ($cfg['show_page_number'] ?? $cfg['show_page_numbers'] ?? true);
        $headerHtml = (string) ($cfg['header_html'] ?? '');
        $firstPageHeaderHtml = (string) ($cfg['first_page_header_html'] ?? '');
        $footerHtml = array_key_exists('footer_html', $cfg) && $cfg['footer_html'] !== null
            ? (string) $cfg['footer_html']
            : ($showParaf ? \App\Models\MouTemplate::getDefaultFooterParafHtml() : '');
        $firstPageFooterHtml = array_key_exists('first_page_footer_html', $cfg) && $cfg['first_page_footer_html'] !== null
            ? (string) $cfg['first_page_footer_html']
            : $footerHtml;
        $differentFirstPage = (bool) ($cfg['different_first_page'] ?? false);
        $showHeaderLogos = (bool) ($cfg['show_header_logos'] ?? true);
        $showWatermark   = (bool) ($cfg['show_watermark'] ?? false);
        $rawInstLogo = $mou->render_data_snapshot['institution_logo_path']
            ?? ($mou->institution?->logo_url ?: ($mou->institution?->logo ? ('/storage/' . $mou->institution->logo) : '/images/mou/institution-logo-placeholder.png'));
        $resolvedInstitutionLogo = \App\Services\MouDocumentRenderer::convertLocalPathToBase64($rawInstLogo) ?? $rawInstLogo;
        $rawCompanyLogo = $mou->render_data_snapshot['company_logo_path']
            ?? ($mou->render_data_snapshot['company_logo']
            ?? ($mou->render_data_snapshot['_media_assets']['company_logo']
            ?? '/images/mou/seven-inc-logo.png'));
        $resolvedCompanyLogo = \App\Services\MouDocumentRenderer::convertLocalPathToBase64($rawCompanyLogo) ?? $rawCompanyLogo;
        $pdfUrl = route($routePrefix . '.download-pdf', $mou);
    @endphp

    <div class="mou-preview-card-wrapper rounded-xl border border-slate-200 bg-white p-6 shadow-sm mb-8 no-print:p-6 print:border-none print:shadow-none print:p-0">
        <!-- Section Header -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-100 no-print">
            <div class="flex items-center gap-2.5">
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-slate-800">
                        Pratinjau Naskah Perjanjian (A4 Layout — 210 &times; 297 mm)
                    </h3>
                    <p class="text-xs text-slate-400">
                        Tata letak lembar standar A4 &bull; Margin {{ $marginTop }}/{{ $marginRight }}/{{ $marginBottom }}/{{ $marginLeft }} mm
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span id="showPageCountBadge" class="rounded bg-blue-50 border border-blue-200 px-2.5 py-1 text-xs font-semibold text-blue-700">
                    Menghitung Halaman...
                </span>
                <a
                    href="{{ $pdfUrl }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-2xs hover:bg-blue-700 transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Unduh PDF (A4)
                </a>
            </div>
        </div>

        <!-- Mode Switcher, Zoom, & Status Toolbar -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 shadow-2xs no-print">
            <div class="flex flex-wrap items-center gap-3">
                <!-- Mode Switcher -->
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-200/70 p-0.5 text-xs">
                    <button
                        type="button"
                        id="btnTabSheetShow"
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
                        id="btnTabOfficialPdfShow"
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
            </div>

            <!-- Zoom Controls & Quick Action -->
            <div id="showZoomControlGroup" class="flex flex-wrap items-center gap-2.5">
                <div class="flex items-center gap-1.5">
                    <label for="showZoomSelect" class="text-xs font-medium text-slate-500">Zoom:</label>
                    <select
                        id="showZoomSelect"
                        onchange="applyShowZoom(this.value)"
                        class="rounded-lg border border-slate-200 bg-white py-1 pl-2.5 pr-7 text-xs font-medium text-slate-700 focus:border-blue-500 focus:outline-none shadow-2xs"
                    >
                        <option value="fit-width" selected>Fit Lebar</option>
                        <option value="0.75">75%</option>
                        <option value="0.9">90%</option>
                        <option value="1.0">100%</option>
                        <option value="1.15">115%</option>
                    </select>
                </div>

                <button
                    type="button"
                    onclick="handleMouPrint()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Cetak Browser
                </button>
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
                <strong>Catatan:</strong> Tab <em>Lembar Kertas A4</em> menampilkan simulasi tata letak visual halaman naskah. Untuk pratinjau keluaran berkas cetak resmi yang 100% presisi, gunakan tab <strong>Official PDF Preview</strong> atau unduh langsung PDF.
            </span>
        </div>

        <!-- Hidden Raw Rendered Source for Multi-Page A4 Pagination -->
        <div id="showCanonicalSource" class="hidden">{!! $mou->rendered_document_html !!}</div>

        <!-- TAB 1: Multi-Page A4 Paper Sheets Simulation -->
        <div id="panelSheetShow" class="bg-slate-300/80 py-8 px-4 rounded-xl border border-slate-300 overflow-x-auto print:bg-white print:p-0 print:border-none">
            <div id="showScaleContainer" class="flex flex-col items-center origin-top transition-transform duration-150">
                <div
                    id="showA4PagesStage"
                    class="mou-show-pages-stage"
                    style="--mou-margin-top: {{ $marginTop }}mm; --mou-margin-right: {{ $marginRight }}mm; --mou-margin-bottom: {{ $marginBottom }}mm; --mou-margin-left: {{ $marginLeft }}mm; --mou-font-family: '{{ $cfg['font_family'] ?? 'Times New Roman' }}', Times, serif; --mou-font-size: {{ (int)($cfg['font_size_pt'] ?? 12) }}pt; --mou-line-height: {{ (float)($cfg['line_height'] ?? 1.15) }};"
                ></div>
            </div>
        </div>

        <!-- TAB 2: Official DomPDF Preview Panel -->
        <div id="panelOfficialPdfShow" class="hidden rounded-xl border border-slate-300 bg-slate-800 p-3 shadow-inner no-print">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-200 px-2">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                    <span class="font-medium">Pratinjau Keluaran PDF Resmi (Dirender langsung oleh mesin DomPDF)</span>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        id="btnReloadOfficialPdfShow"
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
                <div id="pdfLoadingIndicatorShow" class="hidden absolute inset-0 flex flex-col items-center justify-center bg-slate-900/90 text-slate-300 z-10 transition-opacity duration-200">
                    <svg class="animate-spin h-8 w-8 text-blue-500 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm font-semibold text-white">Merender PDF Dokumen Resmi...</span>
                    <span class="text-xs text-slate-400 mt-1">Menghasilkan tata letak halaman A4, header, footer paraf, dan logo institusi</span>
                </div>

                <!-- Fallback Message if browser blocks embedded PDF -->
                <div id="pdfFallbackContainerShow" class="hidden absolute inset-0 flex flex-col items-center justify-center bg-slate-900 text-slate-300 p-6 text-center z-5">
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
                    id="officialPdfShowIframe"
                    data-src="{{ $pdfUrl }}"
                    title="Official PDF Preview"
                    class="w-full h-full bg-white border-0"
                    style="height: 85vh;"
                ></iframe>
            </div>
        </div>
    </div>

    <style>
        {!! $canonicalCss ?? \App\Services\MouDocumentRenderer::getCanonicalDocumentCss($cfg) !!}

        .mou-show-pages-stage {
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
            padding-top: var(--mou-margin-top, 25.4mm);
            padding-right: var(--mou-margin-right, 25.4mm);
            padding-bottom: var(--mou-margin-bottom, 25.4mm);
            padding-left: var(--mou-margin-left, 25.4mm);
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
            margin-top: 3mm;
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
            .no-print,
            header,
            aside,
            footer,
            nav,
            #admin-topbar,
            #admin-sidebar,
            #admin-navbar,
            [class*="navbar"],
            [class*="sidebar"],
            .admin-sidebar {
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
            #admin-main-content,
            main,
            .px-6,
            .py-8,
            .max-w-6xl,
            .mx-auto {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                min-height: 0 !important;
                max-width: none !important;
                margin-left: 0 !important;
                padding-top: 0 !important;
            }
            .mou-preview-card-wrapper {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
            }
            #panelSheetShow {
                background: transparent !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
                box-shadow: none !important;
                display: block !important;
            }
            #showScaleContainer {
                transform: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                display: block !important;
            }
            .mou-show-pages-stage {
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
        const sourceEl = document.getElementById('showCanonicalSource');
        const stageEl = document.getElementById('showA4PagesStage');
        const badgeEl = document.getElementById('showPageCountBadge');
        const showParaf = @json($showParaf);
        const showPageNum = @json($showPageNum);
        const headerHtml = @json($headerHtml);
        const firstPageHeaderHtml = @json($firstPageHeaderHtml);
        const footerHtml = @json($footerHtml);
        const firstPageFooterHtml = @json($firstPageFooterHtml);
        const differentFirstPage = @json($differentFirstPage);
        const showHeaderLogos = @json($showHeaderLogos);
        const showWatermark = @json($showWatermark);
        const resolvedInstitutionLogo = @json($resolvedInstitutionLogo);
        const resolvedCompanyLogo = @json($resolvedCompanyLogo);

        if (!sourceEl || !stageEl) return;

        // Zoom Handler dideklarasikan sebagai fungsi terhoist agar tersedia saat inisialisasi pagination
        function applyShowZoom(zoomVal) {
            const scrollContainer = document.getElementById('panelSheetShow');
            const scaleContainer = document.getElementById('showScaleContainer');
            const stage = document.getElementById('showA4PagesStage');
            if (!scrollContainer || !scaleContainer || !stage) return;

            const a4W = 210 * (96 / 25.4); // ~793.7px
            const a4H = 297 * (96 / 25.4); // ~1122.5px
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
        window.applyShowZoom = applyShowZoom;

        // Cetak Browser Handler: pastikan tab Lembar Kertas A4 aktif sebelum dialog print peramban terbuka
        window.handleMouPrint = function () {
            const btnTabSheet = document.getElementById('btnTabSheetShow');
            const panelSheet = document.getElementById('panelSheetShow');
            if (panelSheet && panelSheet.classList.contains('hidden') && btnTabSheet) {
                btnTabSheet.click();
            }
            setTimeout(() => {
                window.print();
            }, 100);
        };

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

            const zoomSel = document.getElementById('showZoomSelect');
            if (zoomSel) {
                applyShowZoom(zoomSel.value);
            }
        }

        function paginateShow() {
            // Cari kontainer naskah terdalam yang memuat blok-blok paragraf dan tabel asli
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

                finalizePageNumbers(pages);
                return;
            }

            // PRIORITAS 2: Pagination cerdas berbasis aliran naskah alami & pemisah halaman manual (Page Break)
            // Bersihkan style internal atau layer watermark bawaan dari snapshot mentah agar tidak terduplikasi
            const cloneRoot = innerRoot.cloneNode(true);
            cloneRoot.querySelectorAll('style, .mou-watermark-layer').forEach(el => el.remove());

            const rawBlocks = Array.from(cloneRoot.children).filter(el => {
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

                // Pemisah Halaman Manual (Page Break)
                if (block.classList.contains('mou-page-break') || block.getAttribute('data-page-break') === 'true') {
                    if (cur.body.children.length > 0 && i < rawBlocks.length - 1) {
                        cur = openPage();
                    }
                    continue;
                }

                // Proteksi Orphan Heading
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

                cur.body.appendChild(block);
                const maxH = getMaxBodyHeight(cur);
                const usedH = getUsedHeight(cur.body);

                if (usedH <= maxH) {
                    continue;
                }

                cur.body.removeChild(block);

                if (cur.body.children.length > 0) {
                    cur = openPage();
                }

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

        window.paginateShow = paginateShow;
        paginateShow();
        window.addEventListener('load', paginateShow);

        window.addEventListener('resize', () => {
            const zoomSel = document.getElementById('showZoomSelect');
            if (zoomSel && zoomSel.value === 'fit-width') {
                applyShowZoom('fit-width');
            }
        });

        const zoomSelectEl = document.getElementById('showZoomSelect');
        if (zoomSelectEl) {
            zoomSelectEl.addEventListener('change', function () {
                applyShowZoom(this.value);
            });
        }

        // Tab Switching & Official DomPDF Loader
        const btnTabSheet = document.getElementById('btnTabSheetShow');
        const btnTabPdf = document.getElementById('btnTabOfficialPdfShow');
        const panelSheet = document.getElementById('panelSheetShow');
        const panelPdf = document.getElementById('panelOfficialPdfShow');
        const pdfIframe = document.getElementById('officialPdfShowIframe');
        const pdfLoading = document.getElementById('pdfLoadingIndicatorShow');
        const pdfFallback = document.getElementById('pdfFallbackContainerShow');
        const btnReloadPdf = document.getElementById('btnReloadOfficialPdfShow');
        const zoomGroup = document.getElementById('showZoomControlGroup');

        let cachedPdfBlobUrl = null;
        let isPdfLoading = false;

        function loadOfficialPdfShow(forceReload = false) {
            if (!pdfIframe) return;
            const pdfUrl = pdfIframe.getAttribute('data-src');
            if (!pdfUrl) return;

            // Jika sudah pernah dimuat dan tidak diminta muat ulang paksa
            if (cachedPdfBlobUrl && !forceReload) {
                if (pdfLoading) pdfLoading.classList.add('hidden');
                return;
            }

            if (isPdfLoading && !forceReload) return;
            isPdfLoading = true;

            if (pdfLoading) pdfLoading.classList.remove('hidden');
            if (pdfFallback) pdfFallback.classList.add('hidden');

            let dismissed = false;
            const safetyTimer = setTimeout(() => {
                if (!dismissed) {
                    dismissed = true;
                    if (pdfLoading) pdfLoading.classList.add('hidden');
                    if (!pdfIframe.src || pdfIframe.src === 'about:blank') {
                        pdfIframe.src = pdfUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH';
                    }
                    isPdfLoading = false;
                }
            }, 3500);

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
                    if (!dismissed) {
                        dismissed = true;
                        clearTimeout(safetyTimer);
                        if (pdfLoading) pdfLoading.classList.add('hidden');
                        isPdfLoading = false;
                    }
                };
                pdfIframe.onerror = () => {
                    if (!dismissed) {
                        dismissed = true;
                        clearTimeout(safetyTimer);
                        if (pdfLoading) pdfLoading.classList.add('hidden');
                        if (pdfFallback) pdfFallback.classList.remove('hidden');
                        isPdfLoading = false;
                    }
                };

                pdfIframe.src = cachedPdfBlobUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH';

                setTimeout(() => {
                    if (!dismissed) {
                        dismissed = true;
                        clearTimeout(safetyTimer);
                        if (pdfLoading) pdfLoading.classList.add('hidden');
                        isPdfLoading = false;
                    }
                }, 1000);
            })
            .catch(err => {
                console.warn('Gagal memuat blob PDF, mencoba URL langsung:', err);
                pdfIframe.onload = () => {
                    if (!dismissed) {
                        dismissed = true;
                        clearTimeout(safetyTimer);
                        if (pdfLoading) pdfLoading.classList.add('hidden');
                        isPdfLoading = false;
                    }
                };
                pdfIframe.onerror = () => {
                    if (!dismissed) {
                        dismissed = true;
                        clearTimeout(safetyTimer);
                        if (pdfLoading) pdfLoading.classList.add('hidden');
                        if (pdfFallback) pdfFallback.classList.remove('hidden');
                        isPdfLoading = false;
                    }
                };

                pdfIframe.src = pdfUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH';

                setTimeout(() => {
                    if (!dismissed) {
                        dismissed = true;
                        clearTimeout(safetyTimer);
                        if (pdfLoading) pdfLoading.classList.add('hidden');
                        isPdfLoading = false;
                    }
                }, 1500);
            });
        }

        const activeTabClass = 'inline-flex items-center gap-1.5 rounded-md bg-white px-3 py-1.5 font-semibold text-slate-800 shadow-2xs transition';
        const inactiveTabClass = 'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-semibold text-slate-600 hover:text-slate-900 transition';

        if (btnTabSheet && btnTabPdf && panelSheet && panelPdf) {
            btnTabSheet.addEventListener('click', () => {
                panelSheet.classList.remove('hidden');
                panelPdf.classList.add('hidden');
                if (zoomGroup) zoomGroup.classList.remove('hidden');
                btnTabSheet.className = activeTabClass;
                btnTabPdf.className = inactiveTabClass;
                applyShowZoom(document.getElementById('showZoomSelect')?.value || 'fit-width');
                paginateShow();
            });

            btnTabPdf.addEventListener('click', () => {
                panelSheet.classList.add('hidden');
                panelPdf.classList.remove('hidden');
                if (zoomGroup) zoomGroup.classList.add('hidden');
                btnTabPdf.className = activeTabClass;
                btnTabSheet.className = inactiveTabClass;
                loadOfficialPdfShow();
            });

            if (btnReloadPdf) {
                btnReloadPdf.addEventListener('click', () => {
                    loadOfficialPdfShow(true);
                });
            }
        }

        // Print Zoom Resets
        let prePrintTransform = '';
        window.addEventListener('beforeprint', () => {
            const scaleContainer = document.getElementById('showScaleContainer');
            if (scaleContainer) {
                prePrintTransform = scaleContainer.style.transform;
                scaleContainer.style.transform = 'none';
                scaleContainer.style.marginBottom = '0px';
            }
        });

        window.addEventListener('afterprint', () => {
            const scaleContainer = document.getElementById('showScaleContainer');
            if (scaleContainer && prePrintTransform) {
                scaleContainer.style.transform = prePrintTransform;
                const zoomSel = document.getElementById('showZoomSelect');
                if (zoomSel) applyShowZoom(zoomSel.value);
            }
        });
    })();
    </script>
</div>

<!-- Modal 1: Tandai Serah Terima (Delivered) -->
<div id="modalDelivered" class="no-print fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 hidden">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-slate-800 text-sm">Catat Serah Terima Naskah MOU</h3>
            <button type="button" onclick="document.getElementById('modalDelivered').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                &times;
            </button>
        </div>

        <form method="POST" action="{{ route($routePrefix . '.delivered', $mou) }}">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Metode Pengiriman / Serah Terima</label>
                    <select name="delivery_method" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none">
                        <option value="email">Surat Elektronik (Email)</option>
                        <option value="kurir">Kurir / Ekspedisi / Pos</option>
                        <option value="langsung">Penyerahan Langsung Fisik</option>
                        <option value="whatsapp">WhatsApp / Dokumen Digital</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Nama Penerima / Kontak Tujuan</label>
                    <input type="text" name="delivery_recipient" placeholder="Contoh: Bagian Kerjasama / Dekanat" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Tanggal & Waktu Serah Terima</label>
                    <input type="datetime-local" name="delivered_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Catatan Tambahan</label>
                    <textarea name="delivery_notes" rows="2" placeholder="Nomor resi, alamat tujuan, atau pesan pendukung..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 mt-6 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('modalDelivered').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg">
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm">
                    Simpan Status Terkirim
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Unggah Naskah Bertandatangan (PDF) -->
<div id="modalUploadSigned" class="no-print fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 hidden">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-slate-800 text-sm">Unggah Naskah Bertandatangan (PDF)</h3>
            <button type="button" onclick="document.getElementById('modalUploadSigned').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                &times;
            </button>
        </div>

        <form method="POST" action="{{ route($routePrefix . '.upload-signed', $mou) }}" enctype="multipart/form-data">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Pilih Berkas Dokumen (PDF, Maks. 10MB) <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="file"
                        name="signed_file"
                        accept="application/pdf"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                    <p class="text-[11px] text-slate-400 mt-1">Harus merupakan scan dokumen naskah yang telah ditandatangani kedua belah pihak dan bermeterai.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Sah Ditandatangani <span class="text-rose-500">*</span></label>
                    <input
                        type="date"
                        name="signed_at"
                        value="{{ date('Y-m-d') }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Nomor Registrasi Pihak Institusi</label>
                    <input
                        type="text"
                        name="institution_mou_number"
                        value="{{ $mou->institution_mou_number }}"
                        placeholder="Contoh: 045/UNIV/MOU/XI/2026"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Mulai Berlaku</label>
                        <input
                            type="date"
                            name="effective_start"
                            value="{{ $mou->effective_start ? \Carbon\Carbon::parse($mou->effective_start)->format('Y-m-d') : date('Y-m-d') }}"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Selesai Berlaku</label>
                        <input
                            type="date"
                            name="effective_end"
                            value="{{ $mou->effective_end ? \Carbon\Carbon::parse($mou->effective_end)->format('Y-m-d') : date('Y-m-d', strtotime('+2 years')) }}"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 mt-6 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('modalUploadSigned').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg">
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-sm">
                    Unggah & Aktifkan MOU
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Batalkan Dokumen -->
<div id="modalCancel" class="no-print fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 hidden">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-rose-800 text-sm">Konfirmasi Pembatalan Dokumen MOU</h3>
            <button type="button" onclick="document.getElementById('modalCancel').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                &times;
            </button>
        </div>

        <form method="POST" action="{{ route($routePrefix . '.cancel', $mou) }}">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">
                    Alasan Pembatalan <span class="text-rose-500">*</span>
                </label>
                <textarea
                    name="cancel_reason"
                    rows="3"
                    required
                    placeholder="Jelaskan alasan pembatalan naskah dokumen ini..."
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-rose-500 focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 mt-6 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('modalCancel').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg">
                    Kembali
                </button>
                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700 rounded-lg shadow-sm">
                    Batalkan Naskah
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
