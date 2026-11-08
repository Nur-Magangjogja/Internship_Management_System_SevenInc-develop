@extends('layouts.dashboard', ['title' => 'Ubah Template MOU'])

@php
    $compDefs = array_merge(\App\Models\MouTemplate::getDefaultCompanyValues(), $companyDefaults ?? []);
@endphp

@section('content')
<div class="px-6 py-8 max-w-[1440px] mx-auto">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-4">
        <div class="flex items-center gap-3 min-w-0">
            <a
                href="{{ route('admin.mou-templates.index') }}"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-2xs hover:bg-slate-50 transition"
                title="Kembali ke Daftar Template"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>
            <div class="min-w-0">
                <h1 class="text-base font-bold text-slate-800">Ubah Template MOU</h1>
                <p class="text-[11px] text-slate-400 mt-0.5">Sunting naskah Nota Kesepahaman dengan editor dokumen visual A4 (Standar Word)</p>
            </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <div class="inline-flex items-center rounded-lg border border-slate-200 bg-white shadow-2xs overflow-hidden">
                <a
                    href="{{ route('admin.mou-templates.preview', $mouTemplate) }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 transition"
                    title="Pratinjau Dokumen"
                >
                    <svg class="h-3.5 w-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <span>Pratinjau</span>
                </a>
                <span class="h-4 w-px bg-slate-200"></span>
                <a
                    href="{{ route('admin.mou-templates.preview-pdf', $mouTemplate) }}"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-blue-700 bg-blue-50/50 hover:bg-blue-50 transition"
                    title="Unduh PDF Simulasi (A4)"
                >
                    <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <span>PDF (A4)</span>
                </a>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="mouTemplateForm" method="POST" action="{{ route('admin.mou-templates.update', $mouTemplate) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <!-- Top Metadata & Default Pihak Kedua Grid -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <!-- Informasi Dasar Template (4 Cols) -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-4 flex flex-col justify-between">
                <div>
                    <h2 class="text-sm font-bold text-slate-800 mb-3 pb-2 border-b border-slate-100">Informasi Template</h2>

                    <div class="space-y-3.5">
                        <div>
                            <label for="name" class="block text-xs font-semibold text-slate-700">
                                Nama Template <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="{{ old('name', $mouTemplate->name) }}"
                                placeholder="Masukkan nama template (contoh: Template MoU)"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="version_number" class="block text-xs font-semibold text-slate-700">
                                Versi Template <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="number"
                                id="version_number"
                                name="version_number"
                                min="1"
                                max="999"
                                value="{{ old('version_number', $mouTemplate->version_number) }}"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div class="pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    {{ old('is_active', $mouTemplate->is_active) ? 'checked' : '' }}
                                    class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                >
                                <span class="text-xs font-semibold text-slate-700">Jadikan sebagai Template Aktif Utama (Default)</span>
                            </label>
                            <p class="mt-1 text-[11px] text-slate-400 pl-6">Template aktif utama akan otomatis digunakan saat staf HR membuat naskah MOU baru.</p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                    <span>Format Kertas: <strong>A4 Portrait</strong></span>
                    <span>Dokumen Terbit Historis: <strong>Tetap Aman</strong></span>
                </div>
            </div>

            <!-- Pengaturan Nilai Default Pihak Kedua & Masa Berlaku (8 Cols) -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-8">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-100">
                    <div>
                        <h2 class="text-sm font-bold text-slate-800">Default Pihak Kedua & Masa Berlaku</h2>
                        <p class="text-[11px] text-slate-400">Nilai baku yang otomatis mengisi variabel Pihak Kedua saat dokumen diterbitkan.</p>
                    </div>
                </div>

                <!-- Pemilihan Identitas Brand (Sinkronisasi Otomatis) -->
                <div class="mb-4 p-3 bg-blue-50/60 rounded-xl border border-blue-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <label for="templateBrandSelect" class="block text-xs font-bold text-slate-800">
                            Identitas Brand Perusahaan <span class="text-blue-600 font-normal">(Pilih brand untuk sinkronisasi otomatis)</span>
                        </label>
                        <p class="text-[11px] text-slate-500 mt-0.5">Memilih brand akan mengisi otomatis nama, pimpinan, alamat, dan nomor surat rekomendasi brand.</p>
                    </div>
                    <select
                        id="templateBrandSelect"
                        name="brand_id"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 focus:border-blue-500 focus:outline-none shrink-0"
                        onchange="handleTemplateBrandSelectionChange(this.value)"
                    >
                        <option value="">-- Kustom / Tanpa Brand Khusus --</option>
                        @foreach($brands ?? [] as $brandOption)
                            <option
                                value="{{ $brandOption->id }}"
                                {{ (int) old('brand_id', $compDefs['brand_id'] ?? null) === (int) $brandOption->id ? 'selected' : '' }}
                            >
                                {{ $brandOption->name }} ({{ $brandOption->code }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-3">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="company_mou_number" class="block text-xs font-semibold text-slate-700">Nomor MOU Default (Pihak Kedua)</label>
                            <button
                                type="button"
                                onclick="resetTemplateMouNumberToBrand()"
                                class="text-[11px] text-blue-600 hover:text-blue-800 hover:underline cursor-pointer"
                                title="Gunakan nomor saran brand terpilih"
                            >
                                Format Brand
                            </button>
                        </div>
                        <input
                            type="text"
                            id="company_mou_number"
                            name="company_mou_number"
                            value="{{ old('company_mou_number', $compDefs['company_mou_number'] ?? '') }}"
                            placeholder="Contoh: 001/SPK.MAGANG/CEO/SEVEN/XI/2026"
                            class="w-full rounded-lg border border-slate-300 p-2 text-xs font-mono text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="company_name" class="block text-xs font-semibold text-slate-700">Nama Perusahaan</label>
                        <input
                            type="text"
                            id="company_name"
                            name="company_name"
                            value="{{ old('company_name', $compDefs['company_name']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="company_signatory_name" class="block text-xs font-semibold text-slate-700">Nama Pimpinan / Direktur</label>
                        <input
                            type="text"
                            id="company_signatory_name"
                            name="company_signatory_name"
                            value="{{ old('company_signatory_name', $compDefs['company_signatory_name']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="company_signatory_title" class="block text-xs font-semibold text-slate-700">Jabatan Pimpinan</label>
                        <input
                            type="text"
                            id="company_signatory_title"
                            name="company_signatory_title"
                            value="{{ old('company_signatory_title', $compDefs['company_signatory_title']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="agreement_place" class="block text-xs font-semibold text-slate-700">Kota Penandatanganan</label>
                        <input
                            type="text"
                            id="agreement_place"
                            name="agreement_place"
                            value="{{ old('agreement_place', $compDefs['agreement_place']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="company_phone" class="block text-xs font-semibold text-slate-700">Telepon Perusahaan</label>
                        <input
                            type="text"
                            id="company_phone"
                            name="company_phone"
                            value="{{ old('company_phone', $compDefs['company_phone']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="company_fax" class="block text-xs font-semibold text-slate-700">Fax Perusahaan</label>
                        <input
                            type="text"
                            id="company_fax"
                            name="company_fax"
                            value="{{ old('company_fax', $compDefs['company_fax']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="company_email" class="block text-xs font-semibold text-slate-700">Email Resmi</label>
                        <input
                            type="email"
                            id="company_email"
                            name="company_email"
                            value="{{ old('company_email', $compDefs['company_email']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="company_correspondence_unit" class="block text-xs font-semibold text-slate-700">U.p / Unit Korespondensi</label>
                        <input
                            type="text"
                            id="company_correspondence_unit"
                            name="company_correspondence_unit"
                            value="{{ old('company_correspondence_unit', $compDefs['company_correspondence_unit']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label for="agreement_duration" class="block text-xs font-semibold text-slate-700">Durasi Baku Perjanjian</label>
                        <input
                            type="text"
                            id="agreement_duration"
                            name="agreement_duration"
                            value="{{ old('agreement_duration', $compDefs['agreement_duration']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>

                    <div class="sm:col-span-2">
                        <label for="company_address" class="block text-xs font-semibold text-slate-700">Alamat Lengkap Kantor</label>
                        <input
                            type="text"
                            id="company_address"
                            name="company_address"
                            value="{{ old('company_address', $compDefs['company_address']) }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- Word-Style Visual Document Editor Studio -->
        @include('admin.mou-templates._word-editor', [
            'initialHtml'              => $mouTemplate->template_html,
            'pageConfig'               => $pageConfig,
            'editorJson'               => $editorJson ?? null,
            'mediaAssets'              => $mediaAssets,
            'companyDefaults'          => $compDefs,
            'placeholderMeta'          => $placeholderMeta,
            'existingTemplatesPayload' => $existingTemplatesPayload ?? [],
        ])

        <!-- Bottom Action Bar -->
        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-6 py-4 shadow-sm">
            <div class="text-xs text-slate-500">
                Perubahan pada template ini berlaku untuk dokumen MOU yang diterbitkan berikutnya dan tidak mengubah arsip MOU yang telah diterbitkan.
            </div>
            <div class="flex items-center gap-3">
                <a
                    href="{{ route('admin.mou-templates.index') }}"
                    class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                >
                    Batal
                </a>
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/>
                    </svg>
                    Simpan Perubahan Template
                </button>
            </div>
        </div>
    </form>
</div>

<script type="application/json" id="templateBrandsMapJson">{!! json_encode($brandsMap ?? []) !!}</script>
<script type="application/json" id="defaultCompanyValuesJson">{!! json_encode(\App\Models\MouTemplate::getDefaultCompanyValues()) !!}</script>
<script>
const templateBrandsMap = JSON.parse(document.getElementById('templateBrandsMapJson')?.textContent || '{}');
const defaultCompanyValues = JSON.parse(document.getElementById('defaultCompanyValuesJson')?.textContent || '{}');
let isTemplateMouNumberManuallyEdited = {{ old('company_mou_number') && old('company_mou_number') !== ($compDefs['company_mou_number'] ?? '') ? 'true' : 'false' }};

document.addEventListener('DOMContentLoaded', function () {
    const numInput = document.getElementById('company_mou_number');
    if (numInput) {
        numInput.addEventListener('input', function () {
            isTemplateMouNumberManuallyEdited = true;
        });
    }
});

function resetTemplateMouNumberToBrand() {
    const select = document.getElementById('templateBrandSelect');
    const brandId = select ? select.value : '';
    const numInput = document.getElementById('company_mou_number');
    if (!numInput) return;

    if (brandId && templateBrandsMap[brandId]?.suggested_number) {
        numInput.value = templateBrandsMap[brandId].suggested_number;
    } else {
        numInput.value = defaultCompanyValues.company_mou_number || '';
    }
    isTemplateMouNumberManuallyEdited = false;
}

function handleTemplateBrandSelectionChange(brandId) {
    if (!brandId || !templateBrandsMap[brandId]) {
        // Reset kembali ke identitas baku dasar perusahaan (SEVEN INC / Kustom tanpa brand khusus)
        const defaultLogo = defaultCompanyValues.company_logo || '/images/mou/seven-inc-logo.png';
        const logoInput = document.getElementById('companyLogoPathInput') || document.querySelector('input[name="company_logo"]');
        if (logoInput) logoInput.value = defaultLogo;
        window.MouWordProcessor?.setCompanyLogo?.(defaultLogo);

        const nameInput = document.getElementById('company_name');
        if (nameInput) nameInput.value = defaultCompanyValues.company_name || 'SEVEN INC';

        const sigInput = document.getElementById('company_signatory_name');
        if (sigInput) sigInput.value = defaultCompanyValues.company_signatory_name || 'Rekario Danny Sanjaya, S.Kom.';

        const titleInput = document.getElementById('company_signatory_title');
        if (titleInput) titleInput.value = defaultCompanyValues.company_signatory_title || 'Pimpinan';

        const emailInput = document.getElementById('company_email');
        if (emailInput) emailInput.value = defaultCompanyValues.company_email || 'magangjogjadotcom@gmail.com';

        const addrInput = document.getElementById('company_address');
        if (addrInput) addrInput.value = defaultCompanyValues.company_address || 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198';

        const phoneInput = document.getElementById('company_phone');
        if (phoneInput) phoneInput.value = defaultCompanyValues.company_phone || '089529002944';

        const faxInput = document.getElementById('company_fax');
        if (faxInput) faxInput.value = defaultCompanyValues.company_fax || '-';

        const unitInput = document.getElementById('company_correspondence_unit');
        if (unitInput) unitInput.value = defaultCompanyValues.company_correspondence_unit || 'CEO';

        const placeInput = document.getElementById('agreement_place');
        if (placeInput) placeInput.value = defaultCompanyValues.company_city || defaultCompanyValues.agreement_place || 'Yogyakarta';

        const durInput = document.getElementById('agreement_duration');
        if (durInput) durInput.value = defaultCompanyValues.duration_years || defaultCompanyValues.agreement_duration || '5 (lima) tahun';

        const numInput = document.getElementById('company_mou_number');
        if (numInput) {
            numInput.value = defaultCompanyValues.company_mou_number || '';
            isTemplateMouNumberManuallyEdited = false;
        }

        if (window.MouWordProcessor) {
            window.MouWordProcessor.state.mediaAssets = window.MouWordProcessor.state.mediaAssets || {};
            window.MouWordProcessor.state.mediaAssets.company_logo = defaultLogo;
            window.MouWordProcessor.refreshAllPageViews?.();
        }
        return;
    }

    const brand = templateBrandsMap[brandId];

    const nameInput = document.getElementById('company_name');
    if (nameInput && brand.name) nameInput.value = brand.name;

    const sigInput = document.getElementById('company_signatory_name');
    if (sigInput && brand.signatory_name) sigInput.value = brand.signatory_name;

    const titleInput = document.getElementById('company_signatory_title');
    if (titleInput && brand.signatory_position) titleInput.value = brand.signatory_position;

    const emailInput = document.getElementById('company_email');
    if (emailInput && brand.signatory_email) emailInput.value = brand.signatory_email;

    const addrInput = document.getElementById('company_address');
    if (addrInput && brand.company_address) addrInput.value = brand.company_address;

    const numInput = document.getElementById('company_mou_number');
    if (numInput && !isTemplateMouNumberManuallyEdited && brand.suggested_number) {
        numInput.value = brand.suggested_number;
    }

    // Jika editor memiliki input company_logo, perbarui juga
    const logoInput = document.getElementById('companyLogoPathInput') || document.querySelector('input[name="company_logo"]');
    const targetLogo = brand.logo_url || '/images/mou/seven-inc-logo.png';
    if (logoInput) {
        logoInput.value = targetLogo;
    }
    window.MouWordProcessor?.setCompanyLogo?.(targetLogo);
}
</script>
@endsection
