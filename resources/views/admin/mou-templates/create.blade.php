@extends('layouts.dashboard', ['title' => 'Buat Template MOU Baru'])

@section('content')
<div class="px-6 py-8">
    <div class="mb-6 flex items-center gap-3">
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
            <p class="text-sm text-slate-500">Tentukan format klausul, pasal kerja sama, dan token dinamis untuk perjanjian kerja sama institusi.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.mou-templates.store') }}" class="space-y-6">
        @csrf

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Left 2 Cols: Form Utama & Editor -->
            <div class="space-y-6 lg:col-span-2">
                <!-- Data Dasar Template -->
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-800 mb-4 pb-2 border-b border-slate-100">Informasi Template</h2>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="name" class="block text-xs font-semibold text-slate-700">
                                Nama Template <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="{{ old('name', 'Perjanjian Kerja Sama Standar Seven Inc') }}"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
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
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>
                    </div>

                    <div class="mt-4">
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

                <!-- Pengaturan Nilai Default Pihak Kedua (Seven Inc) -->
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-800 mb-1 pb-2 border-b border-slate-100">Default Pihak Kedua (Seven Inc)</h2>
                    <p class="text-[11px] text-slate-400 mb-4">Nilai baku yang otomatis mengisi token pihak kedua saat dokumen dibuat.</p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="company_name" class="block text-xs font-semibold text-slate-700">Nama Perusahaan</label>
                            <input
                                type="text"
                                id="company_name"
                                name="company_name"
                                value="{{ old('company_name', 'SEVEN INC (PT Tujuh Kreasi Digital)') }}"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="company_signatory_name" class="block text-xs font-semibold text-slate-700">Nama Pimpinan / Direktur</label>
                            <input
                                type="text"
                                id="company_signatory_name"
                                name="company_signatory_name"
                                value="{{ old('company_signatory_name', 'Rekso Pamungkas') }}"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="company_signatory_title" class="block text-xs font-semibold text-slate-700">Jabatan Pimpinan</label>
                            <input
                                type="text"
                                id="company_signatory_title"
                                name="company_signatory_title"
                                value="{{ old('company_signatory_title', 'Direktur Utama Seven Inc') }}"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="agreement_place" class="block text-xs font-semibold text-slate-700">Kota Penandatanganan</label>
                            <input
                                type="text"
                                id="agreement_place"
                                name="agreement_place"
                                value="{{ old('agreement_place', 'Yogyakarta') }}"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="company_phone" class="block text-xs font-semibold text-slate-700">Telepon / WhatsApp</label>
                            <input
                                type="text"
                                id="company_phone"
                                name="company_phone"
                                value="{{ old('company_phone', '0812-2555-5777') }}"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="company_email" class="block text-xs font-semibold text-slate-700">Email Resmi</label>
                            <input
                                type="email"
                                id="company_email"
                                name="company_email"
                                value="{{ old('company_email', 'partnership@seveninc.co.id') }}"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>

                        <div class="sm:col-span-2">
                            <label for="company_address" class="block text-xs font-semibold text-slate-700">Alamat Kantor</label>
                            <input
                                type="text"
                                id="company_address"
                                name="company_address"
                                value="{{ old('company_address', 'Jl. Ringroad Timur, Wonocatur, Banguntapan, Bantul, D.I. Yogyakarta') }}"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                        </div>
                    </div>
                </div>

                <!-- Editor Konten Dokumen (HTML & Placeholders) -->
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-100">
                        <div>
                            <h2 class="text-sm font-bold text-slate-800">Konten Naskah Perjanjian (HTML)</h2>
                            <p class="text-[11px] text-slate-400">Gunakan tag HTML standar untuk tata letak dan sisipkan token [placeholder] pada teks yang dinamis.</p>
                        </div>
                    </div>

                    <textarea
                        id="template_html"
                        name="template_html"
                        rows="22"
                        required
                        class="w-full rounded-lg border border-slate-300 p-4 font-mono text-xs text-slate-800 focus:border-blue-500 focus:outline-none leading-relaxed"
                    >{{ old('template_html', $defaultContent) }}</textarea>
                </div>

                <!-- Buttons -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <a
                        href="{{ route('admin.mou-templates.index') }}"
                        class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                    >
                        Batal
                    </a>
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                        </svg>
                        Simpan Template MOU
                    </button>
                </div>
            </div>

            <!-- Right Col: Panduan Placeholders -->
            <div>
                <div class="sticky top-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                    <div class="pb-3 border-b border-slate-100">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Panduan Token Placeholder</h3>
                        <p class="text-[11px] text-slate-500 mt-1">Klik token di bawah untuk menyalin kode ke clipboard, lalu tempelkan ke dalam editor naskah.</p>
                    </div>

                    <div class="space-y-3 max-h-[70vh] overflow-y-auto pr-1">
                        @foreach ($placeholders as $token => $desc)
                            <div class="group rounded-lg border border-slate-200 bg-slate-50 p-2.5 transition hover:border-blue-300 hover:bg-blue-50/50">
                                <div class="flex items-center justify-between">
                                    <button
                                        type="button"
                                        onclick="copyPlaceholder('[{{ $token }}]')"
                                        class="font-mono text-[11px] font-bold text-blue-700 hover:underline text-left"
                                        title="Klik untuk menyalin"
                                    >
                                        [{{ $token }}]
                                    </button>
                                    <button
                                        type="button"
                                        onclick="copyPlaceholder('[{{ $token }}]')"
                                        class="rounded p-1 text-slate-400 hover:text-blue-600"
                                        title="Salin token"
                                    >
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                        </svg>
                                    </button>
                                </div>
                                <p class="text-[10px] text-slate-500 mt-0.5">{{ $desc }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    function copyPlaceholder(token) {
        navigator.clipboard.writeText(token).then(() => {
            alert('Token disalin: ' + token);
        });
    }
</script>
@endsection
