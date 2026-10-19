@extends('layouts.dashboard', ['title' => 'Detail Dokumen MOU - ' . $mou->company_mou_number])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.mous' : 'admin.mous';
@endphp

@section('content')
<div class="px-6 py-8 max-w-6xl mx-auto">
    <!-- Breadcrumb & Back -->
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route($routePrefix . '.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800 transition flex items-center gap-1.5">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Kembali ke Daftar Dokumen MOU
        </a>

        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ route($routePrefix . '.print', $mou) }}"
                target="_blank"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak Naskah A4
            </a>

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
        <div class="mb-6 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
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
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Header Card -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
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
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
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
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
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
                    <td class="py-2 font-semibold text-slate-800">{{ $mou->second_party_company_name }}</td>
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

    <!-- Live Document Naskah A4 Container Preview -->
    @php
        $cfg = $pageConfig ?? ($mou->render_data_snapshot['_page_config'] ?? \App\Models\MouTemplate::getDefaultPageConfig());
        $mTop    = (float) ($cfg['margin_top_mm'] ?? 25.4);
        $mRight  = (float) ($cfg['margin_right_mm'] ?? 25.4);
        $mBottom = (float) ($cfg['margin_bottom_mm'] ?? 25.4);
        $mLeft   = (float) ($cfg['margin_left_mm'] ?? 25.4);
        $showParaf = (bool) ($cfg['show_paraf_box'] ?? $cfg['show_paraf_footer'] ?? true);
        $headerHtml = (string) ($cfg['header_html'] ?? '');
        $footerHtml = array_key_exists('footer_html', $cfg) && $cfg['footer_html'] !== null
            ? (string) $cfg['footer_html']
            : ($showParaf ? \App\Models\MouTemplate::getDefaultFooterParafHtml() : '');
    @endphp
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm mb-8">
        <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
            <h3 class="text-sm font-bold text-slate-800">
                Pratinjau Naskah Perjanjian (A4 Layout — 210 &times; 297 mm)
            </h3>
            <span class="text-xs text-slate-400">
                Render Snapshot Token Aman (Margin {{ $mTop }} / {{ $mRight }} / {{ $mBottom }} / {{ $mLeft }} mm)
            </span>
        </div>

        <style>
            {!! $canonicalCss ?? \App\Services\MouDocumentRenderer::getCanonicalDocumentCss($cfg) !!}
        </style>

        <div class="bg-slate-300/80 py-6 px-3 rounded-xl border border-slate-300 overflow-x-auto">
            <div class="mx-auto bg-white shadow-lg border border-slate-300 font-serif text-black flex flex-col justify-between" style="width: 210mm; max-width: 100%; min-height: 297mm; padding: {{ $mTop }}mm {{ $mRight }}mm {{ $mBottom }}mm {{ $mLeft }}mm; box-sizing: border-box;">
                @if(trim($headerHtml) !== '')
                    <div class="mou-doc-canvas mb-4">
                        {!! $headerHtml !!}
                    </div>
                @endif
                <div class="mou-doc-canvas flex-1">
                    {!! $mou->rendered_document_html !!}
                </div>
                @if($showParaf && trim($footerHtml) !== '')
                    <div class="mou-doc-canvas mt-6 pt-2">
                        {!! $footerHtml !!}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Modal 1: Tandai Serah Terima (Delivered) -->
<div id="modalDelivered" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 hidden">
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
<div id="modalUploadSigned" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 hidden">
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
<div id="modalCancel" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 hidden">
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
