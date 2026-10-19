@extends('layouts.dashboard', ['title' => 'Buat Template MOU Baru'])

@php
    $compDefs = array_merge(\App\Models\MouTemplate::getDefaultCompanyValues(), $defaultCompanyValues ?? []);
@endphp

@section('content')
<div class="px-6 py-8 max-w-[1440px] mx-auto">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
                <h1 class="text-2xl font-bold text-slate-800">Buat Template MOU Baru</h1>
                <p class="text-sm text-slate-500">Susun naskah Nota Kesepahaman berkonsep aplikasi pengolah kata (Word Office) tanpa kode HTML manual.</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                Mode Dokumen Visual A4 (Standar Word)
            </span>
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

    <form id="mouTemplateForm" method="POST" action="{{ route('admin.mou-templates.store') }}" class="space-y-6">
        @csrf

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
                                value="{{ old('name', 'Nota Kesepahaman Tri Dharma Perguruan Tinggi (Standar Word)') }}"
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
                                value="{{ old('version_number', $nextVersion) }}"
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
                                    {{ old('is_active', true) ? 'checked' : '' }}
                                    class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                >
                                <span class="text-xs font-semibold text-slate-700">Jadikan sebagai Template Aktif Utama (Default)</span>
                            </label>
                            <p class="mt-1 text-[11px] text-slate-400 pl-6">Jika dicentang, template aktif sebelumnya akan dialihkan menjadi arsip versi.</p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                    <span>Format Kertas: <strong>A4 Portrait</strong></span>
                    <span>Penomoran Pasal: <strong>Otomatis</strong></span>
                </div>
            </div>

            <!-- Pengaturan Nilai Default Pihak Kedua (Seven Inc) (8 Cols) -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-8">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-100">
                    <div>
                        <h2 class="text-sm font-bold text-slate-800">Default Pihak Kedua (Seven Inc) & Masa Berlaku</h2>
                        <p class="text-[11px] text-slate-400">Nilai baku yang otomatis mengisi variabel Pihak Kedua saat dokumen diterbitkan.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-3">
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
                        <label for="company_correspondence_unit" class="block text-xs font-semibold text-slate-700">U.p / Unit Korespondensi Seven Inc</label>
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

                    <div class="sm:col-span-3">
                        <label for="company_address" class="block text-xs font-semibold text-slate-700">Alamat Lengkap Kantor Seven Inc</label>
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
            'initialHtml'              => $defaultContent,
            'pageConfig'               => $defaultPageConfig,
            'mediaAssets'              => $defaultMediaAssets,
            'companyDefaults'          => $compDefs,
            'placeholderMeta'          => $placeholderMeta,
            'existingTemplatesPayload' => $existingTemplatesPayload ?? [],
        ])

        <!-- Bottom Action Bar -->
        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-6 py-4 shadow-sm">
            <div class="text-xs text-slate-500">
                Seluruh pengaturan format paragraf, tabel, margin A4, dan nomor Pasal akan disimpan otomatis saat Anda menekan tombol Simpan.
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
                    Simpan Template MOU
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
