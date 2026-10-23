@extends('layouts.dashboard')

@section('content')
@php
    $currentYear = $currentYear ?? (int) now()->year;
    $nextYear    = $nextYear ?? ($currentYear + 1);
    $activeYear  = $year ?? $currentYear;
    $isNextYear  = ((int) $activeYear === (int) $nextYear);
    $previewMainFields  = $mainFields->where('is_active', true)->values();
    $previewExtraFields = $extraFields->where('is_active', true)->values();
    $previewDivisions   = ($divisions ?? collect())->values();
@endphp
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Dashboard & Monitoring</p>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Pengaturan Form Pendaftaran</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Kelola pertanyaan form pendaftaran pemagang. Live Preview ditampilkan terlebih dahulu dan diperbarui secara langsung.</p>
        </div>

        {{-- Dynamic Current Year / Next Year Switcher & Publication Controls --}}
        <div class="flex flex-wrap items-center gap-3">
            {{-- Mode Tahun Ini vs Persiapan Tahun Depan --}}
            <div class="inline-flex rounded-[10px] border border-[#DCE7E1] bg-white p-1 shadow-sm">
                <a href="{{ route('admin.form-settings.fields', ['year' => $currentYear]) }}"
                   class="inline-flex items-center gap-1.5 rounded-[7px] px-3.5 py-2 text-xs font-bold transition {{ !$isNextYear ? 'bg-[#2D8659] text-white shadow-xs' : 'text-[#4B5F5A] hover:bg-[#F4F8F6]' }}">
                    <span class="h-2 w-2 rounded-full {{ !$isNextYear ? 'bg-white' : 'bg-emerald-500' }}"></span>
                    Form Tahun Ini ({{ $currentYear }})
                </a>
                <a href="{{ route('admin.form-settings.fields', ['year' => $nextYear]) }}"
                   class="inline-flex items-center gap-1.5 rounded-[7px] px-3.5 py-2 text-xs font-bold transition {{ $isNextYear ? 'bg-[#2D8659] text-white shadow-xs' : 'text-[#4B5F5A] hover:bg-[#F4F8F6]' }}">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Persiapan Tahun Depan ({{ $nextYear }})
                </a>
            </div>

            {{-- Draft & Published Badges --}}
            <div class="flex items-center gap-2 text-xs">
                <span class="rounded-[6px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 font-medium text-[#1B3A34]">
                    Draft: <strong class="text-[#2D8659]">{{ $currentDraft->version_code }}</strong>
                </span>
                <span class="rounded-[6px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 font-medium text-[#1B3A34]">
                    Publikasi: <strong>{{ $activePublished ? $activePublished->version_code : 'Belum Terbit' }}</strong>
                </span>
            </div>

            {{-- Publish Button --}}
            <form method="POST" action="{{ route('admin.form-settings.fields.publish') }}" onsubmit="return confirm('Publikasikan draf formulir {{ $currentDraft->version_code }} untuk tahun {{ $activeYear }}?\n\nPerhatian: Setelah dipublikasikan, pertanyaan baru yang ditambahkan akan dikunci permanen dan tidak dapat dihapus lagi (hanya dapat diedit).');">
                @csrf
                <input type="hidden" name="year" value="{{ $activeYear }}">
                <button type="submit"
                    class="inline-flex items-center gap-2 rounded-[8px] bg-[#2D8659] px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-[#1F5F3F] transition">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Publikasikan Formulir
                </button>
            </form>
        </div>
    </div>

    {{-- Banner Informasi Mode Tahun & Aturan Hapus --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-[10px] border {{ $isNextYear ? 'border-amber-200 bg-amber-50/90 text-amber-900' : 'border-[#DCE7E1] bg-white text-[#1B3A34]' }} px-4 py-3 text-xs shadow-xs">
        <div class="flex items-start gap-2.5">
            <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $isNextYear ? 'text-amber-600' : 'text-[#2D8659]' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div class="space-y-0.5 leading-relaxed">
                @if($isNextYear)
                    <p class="font-bold">Mode Persiapan Formulir Tahun Depan ({{ $nextYear }})</p>
                    <p class="text-[11.5px] opacity-90">
                        Struktur awal formulir tahun <strong>{{ $nextYear }}</strong> disamakan dengan formulir tahun <strong>{{ $currentYear }}</strong>. Ketika tahun kalender melewati {{ $currentYear }} (memasuki {{ $nextYear }}), formulir tahun {{ $nextYear }} ini akan <strong>otomatis menjadi formulir aktif</strong> yang digunakan pemagang.
                    </p>
                @else
                    <p class="font-bold">Mode Formulir Tahun Berjalan ({{ $currentYear }} — Aktif Digunakan)</p>
                    <p class="text-[11.5px] text-[#4B5F5A]">
                        Anda dapat <strong>Tambah</strong> dan <strong>Edit</strong> pertanyaan kapan saja. Tombol <strong>Hapus</strong> hanya tersedia untuk pertanyaan baru yang masih berstatus <em>Sementara (Belum Publish)</em>. Pertanyaan yang sudah dipublikasikan tidak dapat dihapus sama sekali.
                    </p>
                @endif
            </div>
        </div>

        @if($isNextYear)
        <form method="POST" action="{{ route('admin.form-settings.fields.sync-previous') }}" onsubmit="return confirm('Samakan kembali pertanyaan dasar Form Tahun {{ $nextYear }} dengan Form Tahun {{ $currentYear }}?');">
            @csrf
            <button type="submit"
                class="inline-flex items-center gap-1.5 rounded-[7px] border border-amber-300 bg-white px-3 py-1.5 text-[11.5px] font-bold text-amber-800 hover:bg-amber-100 transition">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                Samakan dari Form {{ $currentYear }}
            </button>
        </form>
        @endif
    </div>

    {{-- Alert --}}
    @if(session('success'))
    <div id="alert-success" class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
        <button onclick="document.getElementById('alert-success').remove()" class="ml-auto text-[#2D8659] hover:opacity-70">✕</button>
    </div>
    @endif
    @if(session('error'))
    <div id="alert-error" class="mb-4 flex items-center gap-3 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-[#D32F2F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        {{ session('error') }}
        <button onclick="document.getElementById('alert-error').remove()" class="ml-auto text-red-400 hover:opacity-70">✕</button>
    </div>
    @endif
    @if($errors->any())
    <div id="alert-validation" class="mb-4 flex items-start gap-3 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-[#D32F2F]">
        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div class="space-y-1">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
        <button onclick="document.getElementById('alert-validation').remove()" class="ml-auto text-red-400 hover:opacity-70">✕</button>
    </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">

        {{-- ===== PERTAMA / KIRI: LIVE PREVIEW FORM PEMAGANG (NAMPAK TERLEBIH DAHULU) ===== --}}
        <div class="lg:col-span-5">
            <div class="lg:sticky lg:top-6">
                <div class="rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between border-b border-[#DCE7E1] bg-[#F8FBF9] px-5 py-3.5">
                        <div class="flex items-center gap-2">
                            <span class="flex h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></span>
                            <h2 class="text-[13.5px] font-bold text-[#1B3A34]">Live Preview Form Pemagang</h2>
                        </div>
                        <span class="rounded-full bg-[#E8F5E9] px-2.5 py-0.5 text-[11px] font-semibold text-[#1F5F3F]">
                            Tampilan Aktual ({{ $activeYear }})
                        </span>
                    </div>
                    <div class="max-h-[calc(100vh-160px)] overflow-y-auto">
                        <div id="form-preview-container" class="p-5">
                            {{-- SSR Fallback + langsung dirender dari INITIAL_PREVIEW_DATA saat halaman tampil --}}
                            <h3 class="text-sm font-semibold text-gray-800 mb-1">Form Pendaftaran Magang ({{ $activeYear }})</h3>
                            <p class="text-xs text-gray-500 mb-3">Lengkapi data berikut untuk mendaftar program magang</p>

                            {{-- Stepper Progress Bar (5 Steps) --}}
                            <div class="mb-4 rounded-lg border border-gray-150 bg-gray-50/80 p-2 text-[10px]">
                                <div class="grid grid-cols-5 gap-1 text-center font-semibold text-gray-600">
                                    <div class="flex items-center gap-1 justify-center text-emerald-800"><span class="h-4 w-4 rounded-full bg-emerald-700 text-white flex items-center justify-center text-[9px] font-bold">1</span><span class="truncate">Data Diri</span></div>
                                    <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">2</span><span class="truncate">Pilihan</span></div>
                                    <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">3</span><span class="truncate">Berkas</span></div>
                                    <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">4</span><span class="truncate">Info</span></div>
                                    <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">5</span><span class="truncate">Submit</span></div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                @if(!empty($groupedFields['data_diri_edukasi']) && $groupedFields['data_diri_edukasi']->where('is_active', true)->isNotEmpty())
                                <div class="border-b border-gray-100 pb-3">
                                    <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                        <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">1</span>
                                        Data Diri & Edukasi
                                    </h4>
                                    <div class="space-y-2.5">
                                        @foreach($groupedFields['data_diri_edukasi']->where('is_active', true) as $pf)
                                            <div>
                                                <label class="block mb-1 text-xs font-medium text-gray-700">
                                                    {{ $pf->label }}
                                                    @if($pf->is_required) <span class="text-red-500">*</span> @elseif(!preg_match('/\(opsional\)/i', (string) $pf->label)) <span class="text-[11px] text-gray-400 font-normal font-sans ml-1">(Opsional)</span> @endif
                                                </label>
                                                <input type="text" disabled placeholder="{{ $pf->placeholder ?? '' }}"
                                                    class="block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 px-3 py-2 text-xs">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @endif

                                @if(!empty($groupedFields['pilihan_durasi']) && $groupedFields['pilihan_durasi']->where('is_active', true)->isNotEmpty())
                                <div class="border-b border-gray-100 pb-3">
                                    <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                        <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">2</span>
                                        Pilihan & Durasi
                                    </h4>
                                    <div class="space-y-2.5">
                                        @foreach($groupedFields['pilihan_durasi']->where('is_active', true) as $pf)
                                            <div>
                                                <label class="block mb-1 text-xs font-medium text-gray-700">
                                                    {{ $pf->label }}
                                                    @if($pf->is_required) <span class="text-red-500">*</span> @elseif(!preg_match('/\(opsional\)/i', (string) $pf->label)) <span class="text-[11px] text-gray-400 font-normal font-sans ml-1">(Opsional)</span> @endif
                                                </label>
                                                <input type="text" disabled placeholder="{{ $pf->placeholder ?? '' }}"
                                                    class="block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 px-3 py-2 text-xs">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @endif

                                @if(!empty($groupedFields['berkas_portofolio']) && $groupedFields['berkas_portofolio']->where('is_active', true)->isNotEmpty())
                                <div class="border-b border-gray-100 pb-3">
                                    <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                        <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">3</span>
                                        Berkas & Portofolio <span class="text-[10px] font-normal text-gray-400 normal-case">(Opsional)</span>
                                    </h4>
                                    <div class="space-y-2.5">
                                        @foreach($groupedFields['berkas_portofolio']->where('is_active', true) as $pf)
                                            <div>
                                                <label class="block mb-1 text-xs font-medium text-gray-700">
                                                    {{ $pf->label }}
                                                    @if($pf->is_required) <span class="text-red-500">*</span> @elseif(!preg_match('/\(opsional\)/i', (string) $pf->label)) <span class="text-[11px] text-gray-400 font-normal font-sans ml-1">(Opsional)</span> @endif
                                                </label>
                                                <input type="file" disabled
                                                    class="block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 px-3 py-2 text-xs">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @endif

                                @if(!empty($groupedFields['informasi_tambahan']) && $groupedFields['informasi_tambahan']->where('is_active', true)->isNotEmpty())
                                <div class="border-b border-gray-100 pb-3">
                                    <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                        <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">4</span>
                                        Informasi Tambahan <span class="text-[10px] font-normal text-gray-400 normal-case">(Opsional)</span>
                                    </h4>
                                    <div class="space-y-2.5">
                                        @foreach($groupedFields['informasi_tambahan']->where('is_active', true) as $pf)
                                            <div>
                                                <label class="block mb-1 text-xs font-medium text-gray-700">
                                                    {{ $pf->label }}
                                                    @if($pf->is_required) <span class="text-red-500">*</span> @elseif(!preg_match('/\(opsional\)/i', (string) $pf->label)) <span class="text-[11px] text-gray-400 font-normal font-sans ml-1">(Opsional)</span> @endif
                                                </label>
                                                <input type="text" disabled placeholder="{{ $pf->placeholder ?? '' }}"
                                                    class="block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 px-3 py-2 text-xs">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @endif

                                <div class="pt-1">
                                    <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                        <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">5</span>
                                        Finalisasi & Submit
                                    </h4>
                                    <div class="p-2.5 bg-amber-50 border border-amber-200 rounded text-[11px] text-amber-800 mb-2">
                                        Program magang ini bersifat <strong>unpaid / tidak bergaji</strong>.
                                    </div>
                                    <button disabled class="w-full py-2 bg-emerald-700 text-white rounded text-xs font-semibold opacity-80 cursor-not-allowed">
                                        Kirim Pendaftaran (Preview)
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== KEDUA / KANAN: FIELD MANAGER (TAMBAH, EDIT, HAPUS SEMENTARA) ===== --}}
        <div class="lg:col-span-7 space-y-5">

            {{-- Tambah Field Baru --}}
            <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-2 mb-4">
                    <div class="flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-[7px] bg-[#E8F5E9]">
                            <svg class="h-4 w-4 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                        <div>
                            <h2 class="text-[14px] font-bold text-[#1B3A34]">Tambah Pertanyaan Baru (Tahun {{ $activeYear }})</h2>
                            <p class="text-[11px] text-[#4B5F5A]">Pertanyaan baru bersifat sementara (dapat diedit & dihapus) sampai Anda menekan tombol <strong>Publikasikan Formulir</strong>.</p>
                        </div>
                    </div>
                </div>

                <form id="form-add-field" method="POST" action="{{ route('admin.form-settings.fields.store') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="year" value="{{ $activeYear }}">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Label Pertanyaan <span class="text-red-500">*</span></label>
                            <input type="text" name="label" id="new-label" required placeholder="Contoh: Link Portofolio GitHub / Behance"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Tipe Input <span class="text-red-500">*</span></label>
                            <select name="field_type" id="new-field-type" required
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                                @foreach($types as $val => $name)
                                <option value="{{ $val }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Grup</label>
                            <select name="group_name"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                                @foreach($groups as $val => $name)
                                <option value="{{ $val }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Lebar Kolom</label>
                            <select name="column_span"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                                <option value="1">Penuh (1 kolom)</option>
                                <option value="2">Setengah (2 kolom)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Placeholder</label>
                            <input type="text" name="placeholder" placeholder="Opsional"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        </div>
                        <div class="col-span-2">
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Teks Bantuan (helper)</label>
                            <input type="text" name="helper_text" placeholder="Teks kecil di bawah field, opsional"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        </div>

                        {{-- Options (tampil jika type = select/radio/checkbox) --}}
                        <div class="col-span-2 hidden" id="new-options-wrap">
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Opsi Pilihan</label>
                            <p class="mb-2 text-[11px] text-[#4B5F5A]">Tambahkan opsi satu per satu. Klik "+" untuk menambah baris baru.</p>
                            <div id="new-options-list" class="space-y-2 mb-2">
                                <div class="option-row flex items-center gap-2">
                                    <input type="text" placeholder="Value (contoh: ya)" data-role="value"
                                        class="flex-1 rounded-[7px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 text-[12px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                                    <input type="text" placeholder="Label (contoh: Ya, saya bisa)" data-role="label"
                                        class="flex-1 rounded-[7px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 text-[12px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                                    <button type="button" onclick="this.closest('.option-row').remove()" title="Hapus opsi"
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[7px] border border-red-200 bg-red-50 text-red-500 hover:bg-red-500 hover:text-white transition">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            </div>
                            <button type="button" id="btn-add-option"
                                class="flex items-center gap-1.5 text-[12px] font-medium text-[#2D8659] hover:underline">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Tambah Opsi
                            </button>
                            {{-- hidden input untuk JSON options --}}
                            <input type="hidden" name="options" id="new-options-json">
                        </div>

                        <div class="col-span-2 flex items-center gap-2">
                            <label class="flex items-center gap-2 cursor-pointer select-none text-[12.5px] font-medium text-[#1B3A34]">
                                <input type="checkbox" name="is_required" value="1"
                                    class="w-4 h-4 rounded accent-[#2D8659]">
                                Wajib diisi
                            </label>
                        </div>
                    </div>

                    <button type="submit"
                        class="flex w-full items-center justify-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1F5F3F] transition">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Tambah ke Form
                    </button>
                </form>
            </div>

            <div class="space-y-5">
                {{-- Grup 1: Data Diri & Edukasi --}}
                <x-form-field-group
                    title="1. Data Diri & Edukasi"
                    :fields="$groupedFields['data_diri_edukasi'] ?? collect()"
                    :types="$types"
                    group="data_diri_edukasi" />

                {{-- Grup 2: Pilihan & Durasi --}}
                <x-form-field-group
                    title="2. Pilihan & Durasi"
                    :fields="$groupedFields['pilihan_durasi'] ?? collect()"
                    :types="$types"
                    group="pilihan_durasi" />

                {{-- Grup 3: Berkas & Portofolio --}}
                <x-form-field-group
                    title="3. Berkas & Portofolio"
                    :fields="$groupedFields['berkas_portofolio'] ?? collect()"
                    :types="$types"
                    group="berkas_portofolio" />

                {{-- Grup 4: Informasi Tambahan --}}
                <x-form-field-group
                    title="4. Informasi Tambahan"
                    :fields="$groupedFields['informasi_tambahan'] ?? collect()"
                    :types="$types"
                    group="informasi_tambahan" />
            </div>

        </div>

    </div>
</div>

{{-- ===== MODAL EDIT FIELD ===== --}}
<div id="modal-edit" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-[2px] p-3 sm:p-4 md:p-6 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="relative w-full max-w-lg rounded-[16px] bg-white shadow-2xl overflow-hidden flex flex-col my-auto max-h-[90vh]">
        {{-- Sticky Header --}}
        <div class="flex items-center justify-between border-b border-[#DCE7E1] px-6 py-4 shrink-0 bg-white z-10">
            <div>
                <h3 class="text-[15px] font-bold text-[#1B3A34]">Edit Pertanyaan</h3>
                <p id="edit-lock-badge" class="text-[11px] text-[#4B5F5A] mt-0.5"></p>
            </div>
            <button type="button" onclick="closeEditModal()"
                class="flex h-8 w-8 items-center justify-center rounded-full text-[#4B5F5A] hover:bg-[#F4F8F6] transition">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        {{-- Form with scrollable body and pinned footer --}}
        <form id="form-edit-field" method="POST" class="flex flex-col flex-1 min-h-0 overflow-hidden">
            @csrf @method('PUT')
            <input type="hidden" name="year" value="{{ $activeYear }}">
            <input type="hidden" id="edit-field-id">

            {{-- Scrollable Content Body --}}
            <div class="p-6 space-y-4 overflow-y-auto flex-1">
                <div>
                    <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Label Pertanyaan <span class="text-red-500">*</span></label>
                    <input type="text" name="label" id="edit-label" required
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div id="edit-type-wrap">
                        <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Tipe Input</label>
                        <select name="field_type" id="edit-field-type"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                            @foreach($types as $val => $name)
                            <option value="{{ $val }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Grup</label>
                        <select name="group_name" id="edit-group-name"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                            @foreach($groups as $val => $name)
                            <option value="{{ $val }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Lebar Kolom</label>
                        <select name="column_span" id="edit-column-span"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                            <option value="1">Penuh (1 kolom)</option>
                            <option value="2">Setengah (2 kolom)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Placeholder</label>
                        <input type="text" name="placeholder" id="edit-placeholder"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    </div>
                    <div>
                        <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Teks Bantuan</label>
                        <input type="text" name="helper_text" id="edit-helper-text"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    </div>
                </div>

                {{-- Options (untuk select/radio/checkbox) --}}
                <div id="edit-options-wrap" class="hidden">
                    <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Opsi Pilihan</label>
                    <p id="edit-options-note" class="mb-2 text-[11px] text-[#4B5F5A]"></p>
                    <div id="edit-options-list" class="space-y-2 mb-2 max-h-48 overflow-y-auto pr-1"></div>
                    <button type="button" onclick="addOptionRow('edit-options-list', '', '', '', false)"
                        class="flex items-center gap-1.5 text-[12px] font-medium text-[#2D8659] hover:underline">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Tambah Opsi Baru
                    </button>
                    <input type="hidden" name="options" id="edit-options-json">
                </div>

                <div class="flex items-center gap-4 pt-1">
                    <input type="hidden" name="is_required" value="0">
                    <label class="flex items-center gap-2 cursor-pointer select-none text-[12.5px] font-medium text-[#1B3A34]">
                        <input type="checkbox" name="is_required" id="edit-is-required" value="1"
                            class="w-4 h-4 rounded accent-[#2D8659]">
                        Wajib diisi
                    </label>

                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-center gap-2 cursor-pointer select-none text-[12.5px] font-medium text-[#1B3A34]">
                        <input type="checkbox" name="is_active" id="edit-is-active" value="1"
                            class="w-4 h-4 rounded accent-[#2D8659]">
                        Aktif (tampil di form)
                    </label>
                </div>
            </div>

            {{-- Sticky Footer --}}
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-[#DCE7E1] bg-[#F4F8F6]/60 shrink-0">
                <button type="button" onclick="closeEditModal()"
                    class="rounded-[8px] border border-[#DCE7E1] bg-white px-4 py-2 text-[13px] font-medium text-[#4B5F5A] hover:bg-[#F4F8F6] transition">
                    Batal
                </button>
                <button type="submit"
                    class="rounded-[8px] bg-[#2D8659] px-5 py-2 text-[13px] font-semibold text-white hover:bg-[#1F5F3F] shadow-sm transition">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// ================================================================
// CSRF Token & Initial Preview Data (Server-Side Hydrated)
// ================================================================
const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const CURRENT_YEAR = {{ (int) $activeYear }};
@php
    $previewGroupedFields = [
        'data_diri_edukasi'  => ($groupedFields['data_diri_edukasi'] ?? collect())->where('is_active', true)->values(),
        'pilihan_durasi'     => ($groupedFields['pilihan_durasi'] ?? collect())->where('is_active', true)->values(),
        'berkas_portofolio'  => ($groupedFields['berkas_portofolio'] ?? collect())->where('is_active', true)->values(),
        'informasi_tambahan' => ($groupedFields['informasi_tambahan'] ?? collect())->where('is_active', true)->values(),
    ];
@endphp
const INITIAL_PREVIEW_DATA = {
    grouped_fields: @json($previewGroupedFields),
    main_fields: @json($previewMainFields),
    extra_fields: @json($previewExtraFields),
    divisions: @json($previewDivisions),
    draft_version: @json($currentDraft->version_code),
    year: {{ (int) $activeYear }}
};

// ================================================================
// Tipe yg butuh options
// ================================================================
const TYPES_WITH_OPTIONS = ['select', 'radio', 'checkbox'];

// ================================================================
// Toggle opsi wrapper saat tipe berubah (form tambah & edit)
// ================================================================
document.getElementById('new-field-type')?.addEventListener('change', function () {
    const wrap = document.getElementById('new-options-wrap');
    wrap.classList.toggle('hidden', !TYPES_WITH_OPTIONS.includes(this.value));
});

document.getElementById('edit-field-type')?.addEventListener('change', function () {
    const wrap = document.getElementById('edit-options-wrap');
    wrap.classList.toggle('hidden', !TYPES_WITH_OPTIONS.includes(this.value));
});

// ================================================================
// Tambah baris opsi (dipakai oleh form tambah & edit)
// ================================================================
function addOptionRow(containerId, value = '', label = '', description = '', isLockedOption = false) {
    const list = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'option-row flex items-center gap-2';
    row.dataset.description = description || '';

    const readonlyAttr = isLockedOption ? 'readonly title="Nilai opsi yang sudah pernah dipublikasikan dikunci agar data historis tetap aman"' : '';
    const valueBgCls   = isLockedOption ? 'bg-gray-100 text-gray-500 cursor-not-allowed' : 'bg-white text-[#1B3A34]';
    const removeBtnHtml = isLockedOption
        ? `<button type="button" disabled title="Opsi yang sudah pernah dipublikasikan tidak dapat dihapus"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[7px] border border-gray-200 bg-gray-100 text-gray-300 cursor-not-allowed">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </button>`
        : `<button type="button" onclick="this.closest('.option-row').remove()" title="Hapus opsi"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[7px] border border-red-200 bg-red-50 text-red-500 hover:bg-red-500 hover:text-white transition">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>`;

    row.innerHTML = `
        <input type="text" placeholder="Value (contoh: ya)" data-role="value" value="${escHtml(value)}" ${readonlyAttr}
            class="flex-1 rounded-[7px] border border-[#DCE7E1] ${valueBgCls} px-2.5 py-1.5 text-[12px] outline-none focus:border-[#2D8659]">
        <input type="text" placeholder="Label (contoh: Ya, saya bisa)" data-role="label" value="${escHtml(label)}"
            class="flex-1 rounded-[7px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 text-[12px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
        ${removeBtnHtml}`;
    list.appendChild(row);
}

document.getElementById('btn-add-option')?.addEventListener('click', () => addOptionRow('new-options-list'));

function escHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// ================================================================
// Kumpulkan options dari DOM list → JSON
// ================================================================
function collectOptions(listId) {
    const list = document.getElementById(listId);
    if (!list) return null;
    const rows = list.querySelectorAll('.option-row');
    const opts = [];
    rows.forEach(row => {
        const v = row.querySelector('[data-role="value"]').value.trim();
        const l = row.querySelector('[data-role="label"]').value.trim();
        const d = row.dataset.description || '';
        if (v || l) {
            const item = { value: v || l, label: l || v };
            if (d) item.description = d;
            opts.push(item);
        }
    });
    return opts.length ? JSON.stringify(opts) : null;
}

// ================================================================
// Submit form tambah: inject options JSON sebelum kirim
// ================================================================
document.getElementById('form-add-field')?.addEventListener('submit', function () {
    const type = document.getElementById('new-field-type').value;
    if (TYPES_WITH_OPTIONS.includes(type)) {
        const opts = collectOptions('new-options-list');
        document.getElementById('new-options-json').value = opts || '';
    }
});

// ================================================================
// Modal Edit
// ================================================================
function openEditModal(id) {
    fetch(`/admin/form-settings/fields/${id}/data`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        const f = data.field;
        const isDeletable = !!f.is_deletable;

        document.getElementById('edit-field-id').value = f.id;

        const form = document.getElementById('form-edit-field');
        form.action = `/admin/form-settings/fields/${f.id}`;

        document.getElementById('edit-label').value         = f.label ?? '';
        document.getElementById('edit-placeholder').value   = f.placeholder ?? '';
        document.getElementById('edit-helper-text').value   = f.helper_text ?? '';
        document.getElementById('edit-is-required').checked = !!f.is_required;
        document.getElementById('edit-is-active').checked   = !!f.is_active;

        const typeSelect = document.getElementById('edit-field-type');
        typeSelect.value = f.field_type ?? 'text';

        const groupSelect = document.getElementById('edit-group-name');
        if (groupSelect) {
            groupSelect.value = f.group_name ?? '';
        }

        const colSpan = document.getElementById('edit-column-span');
        colSpan.value = f.column_span ?? 1;

        const isStructuralLocked = !!f.is_structural_locked;

        const lockBadge = document.getElementById('edit-lock-badge');
        if (isStructuralLocked) {
            lockBadge.textContent = '🔒 Status: Terkunci Permanen — Pertanyaan ini sudah dipublikasikan/pernah digunakan. Tipe input dan identitas dikunci agar tidak merusak data/integrasi spreadsheet. Anda tetap dapat mengedit teks label, placeholder, dan teks bantuan.';
            lockBadge.className = 'text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded px-2 py-1 mt-1 font-medium';
        } else {
            lockBadge.textContent = '✏️ Status: Draf Sementara — Belum dipublikasikan. Anda bebas mengubah tipe input atau menghapusnya.';
            lockBadge.className = 'text-[11px] text-emerald-800 bg-emerald-50 border border-emerald-200 rounded px-2 py-1 mt-1 font-medium';
        }

        // Kunci tipe input jika struktur terkunci
        document.getElementById('edit-type-wrap').style.opacity = isStructuralLocked ? '0.6' : '1';
        typeSelect.disabled = isStructuralLocked;

        // Options
        const optsWrap = document.getElementById('edit-options-wrap');
        const optsList = document.getElementById('edit-options-list');
        const optsNote = document.getElementById('edit-options-note');
        optsList.innerHTML = '';

        if (TYPES_WITH_OPTIONS.includes(f.field_type)) {
            optsWrap.classList.remove('hidden');
            optsNote.textContent = isDeletable
                ? 'Anda dapat menambah, mengubah, atau menghapus opsi pilihan.'
                : 'Label opsi dapat diperbarui dan Anda dapat menambah opsi baru (opsi yang sudah terbit tidak dapat dihapus).';
            if (Array.isArray(f.options)) {
                f.options.forEach(opt => addOptionRow('edit-options-list', opt.value, opt.label, opt.description || '', !isDeletable));
            }
        } else {
            optsWrap.classList.add('hidden');
        }

        const modal = document.getElementById('modal-edit');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    })
    .catch(err => showToast('Gagal memuat data field: ' + err.message, 'error'));
}

function closeEditModal() {
    const modal = document.getElementById('modal-edit');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.getElementById('form-edit-field')?.addEventListener('submit', function () {
    const type = document.getElementById('edit-field-type').value;
    if (TYPES_WITH_OPTIONS.includes(type)) {
        const opts = collectOptions('edit-options-list');
        document.getElementById('edit-options-json').value = opts || '';
    }
});

document.getElementById('modal-edit')?.addEventListener('click', function (e) {
    if (e.target === this) closeEditModal();
});

// ================================================================
// Toggle required via AJAX
// ================================================================
function toggleRequired(id) {
    fetch(`/admin/form-settings/fields/${id}/toggle-required`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const btn   = document.getElementById('btn-req-' + id);
            const label = document.getElementById('req-label-' + id);
            if (data.is_required) {
                btn.className = btn.className
                    .replace(/border-gray-200 bg-gray-50 text-gray-400 hover:border-\[#2D8659\] hover:text-\[#2D8659\]/g, '')
                    + ' border-red-200 bg-red-50 text-red-600 hover:bg-red-500 hover:text-white';
                label.textContent = '*wajib';
                btn.title = 'Jadikan Opsional';
            } else {
                btn.className = btn.className
                    .replace(/border-red-200 bg-red-50 text-red-600 hover:bg-red-500 hover:text-white/g, '')
                    + ' border-gray-200 bg-gray-50 text-gray-400 hover:border-[#2D8659] hover:text-[#2D8659]';
                label.textContent = 'opsional';
                btn.title = 'Jadikan Wajib';
            }
            showToast(data.message, 'success');
            refreshPreview();
        } else {
            showToast(data.message || 'Terjadi kesalahan.', 'error');
        }
    });
}

// ================================================================
// Toggle aktif via AJAX
// ================================================================
function toggleField(id) {
    fetch(`/admin/form-settings/fields/${id}/toggle`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message || 'Terjadi kesalahan.', 'error');
        }
    });
}

// ================================================================
// Hapus field sementara (belum dipublikasikan) via AJAX
// ================================================================
function deleteField(id, label) {
    if (!confirm(`Hapus pertanyaan sementara "${label}"?\n\nCatatan: Pertanyaan ini masih berstatus sementara (belum dipublikasikan) sehingga dapat dihapus.`)) return;
    fetch(`/admin/form-settings/fields/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast(data.message, 'success');
            document.getElementById('field-row-' + id)?.remove();
            refreshPreview();
        } else {
            showToast(data.message || 'Pertanyaan tidak dapat dihapus.', 'error');
        }
    });
}

// ================================================================
// Drag & Drop Reorder
// ================================================================
function initSortable(listId, group) {
    const el = document.getElementById(listId);
    if (!el || typeof Sortable === 'undefined') return;

    Sortable.create(el, {
        animation: 150,
        handle: '.drag-handle',
        ghostClass: 'opacity-30',
        onEnd: function () {
            const ids = Array.from(el.querySelectorAll('[data-field-id]'))
                .map(r => parseInt(r.dataset.fieldId));

            fetch('/admin/form-settings/fields/reorder', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': CSRF,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ ids, group, year: CURRENT_YEAR })
            })
            .then(r => r.json())
            .then(() => refreshPreview());
        }
    });
}

// ================================================================
// Live Preview
// ================================================================
function refreshPreview() {
    const container = document.getElementById('form-preview-container');
    if (!container) return;

    fetch(`/admin/form-settings/fields/preview-data?year=${CURRENT_YEAR}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        container.innerHTML = buildPreviewHtml(data);
    })
    .catch(() => {
        container.innerHTML = '<p class="text-xs text-red-500 p-4">Gagal memuat preview.</p>';
    });
}

function buildPreviewHtml(data) {
    const inputCls = 'block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 px-3 py-2 text-xs';
    const labelCls = 'block mb-1 text-xs font-medium text-gray-700';
    const reqStar  = '<span class="text-red-500">*</span>';

    const grouped = data.grouped_fields || {
        data_diri_edukasi: (data.main_fields || []).filter(f => ['fullname','student_id','born_date','gender','religion','institution_name','faculty','study_program','email','phone_number','current_status'].includes(f.field_key)),
        pilihan_durasi: (data.main_fields || []).filter(f => ['internship_interest','start_date','end_date','planned_duration','internship_type','wfo_readiness','schedule_shift_readiness','internship_arrangement','has_laptop','owned_tools','internship_reason','design_software','video_software','programming_languages','digital_marketing_type'].includes(f.field_key)),
        berkas_portofolio: (data.main_fields || []).filter(f => ['cv','identity','portfolio','cover_letter','supporting_document','cv_ktp_portofolio_pdf'].includes(f.field_key) || f.field_type === 'file'),
        informasi_tambahan: data.extra_fields || [],
    };

    const gDataDiri = grouped.data_diri_edukasi || [];
    const gPilihan  = grouped.pilihan_durasi || [];
    const gBerkas   = grouped.berkas_portofolio || [];
    const gInfo     = grouped.informasi_tambahan || [];

    let html = `
        <h3 class="text-sm font-semibold text-gray-800 mb-1">Form Pendaftaran Magang (${escHtml(data.year || CURRENT_YEAR)})</h3>
        <p class="text-xs text-gray-500 mb-3">Lengkapi data berikut untuk mendaftar program magang</p>

        {{-- Stepper Progress Bar (5 Steps) --}}
        <div class="mb-4 rounded-lg border border-gray-150 bg-gray-50/80 p-2 text-[10px]">
            <div class="grid grid-cols-5 gap-1 text-center font-semibold text-gray-600">
                <div class="flex items-center gap-1 justify-center text-emerald-800"><span class="h-4 w-4 rounded-full bg-emerald-700 text-white flex items-center justify-center text-[9px] font-bold">1</span><span class="truncate">Data Diri</span></div>
                <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">2</span><span class="truncate">Pilihan</span></div>
                <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">3</span><span class="truncate">Berkas</span></div>
                <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">4</span><span class="truncate">Info</span></div>
                <div class="flex items-center gap-1 justify-center"><span class="h-4 w-4 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-[9px] font-bold">5</span><span class="truncate">Submit</span></div>
            </div>
        </div>

        <div class="space-y-4">`;

    function renderField(f) {
        const hasOpt = /\(opsional\)/i.test(f.label || '');
        const req = f.is_required ? reqStar : (!hasOpt ? ' <span class="text-[11px] text-gray-400 font-normal font-sans">(Opsional)</span>' : '');
        let inner = '';

        if (f.field_key === 'internship_interest') {
            let opts = `<option value="">-- Pilih Divisi --</option>`;
            (data.divisions || []).forEach(d => { opts += `<option>${escHtml(d)}</option>`; });
            inner = `<select class="${inputCls}" disabled>${opts}</select>`;
        } else if (['text', 'email', 'tel', 'number', 'url'].includes(f.field_type)) {
            inner = `<input type="${escHtml(f.field_type)}" placeholder="${escHtml(f.placeholder || '')}" class="${inputCls}" disabled>`;
        } else if (f.field_type === 'date') {
            inner = `<input type="date" class="${inputCls}" disabled>`;
        } else if (f.field_type === 'textarea') {
            inner = `<textarea rows="2" placeholder="${escHtml(f.placeholder || '')}" class="${inputCls} resize-none" disabled></textarea>`;
        } else if (f.field_type === 'select') {
            const placeholder = f.placeholder || '-- Pilih --';
            const opts = (f.options || []).map(o => `<option>${escHtml(o.label)}</option>`).join('');
            inner = `<select class="${inputCls}" disabled><option>${escHtml(placeholder)}</option>${opts}</select>`;
            const hasDesc = (f.options || []).some(o => o.description);
            if (hasDesc) {
                inner += `<div class="mt-1.5 space-y-1 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">`;
                (f.options || []).forEach(o => {
                    if (o.description) {
                        inner += `<p class="text-[10px] text-gray-500 leading-snug">
                            <span class="font-semibold text-gray-700">${escHtml(o.label)}:</span>
                            ${escHtml(o.description)}
                        </p>`;
                    }
                });
                inner += `</div>`;
            }
        } else if (f.field_type === 'radio') {
            const opts = (f.options || []).map(o =>
                `<label class="flex items-center gap-1.5 text-xs text-gray-700">
                    <input type="radio" disabled class="w-3 h-3 text-green-600">
                    ${escHtml(o.label)}
                </label>`
            ).join('');
            inner = `<div class="flex flex-wrap gap-3">${opts}</div>`;
        } else if (f.field_type === 'checkbox') {
            const opts = (f.options || []).map(o =>
                `<label class="flex items-center gap-1.5 px-3 py-1.5 text-xs text-gray-700 border border-gray-100 rounded">
                    <input type="checkbox" disabled class="w-3 h-3 text-green-600">
                    ${escHtml(o.label)}
                </label>`
            ).join('');
            inner = `<div class="border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden">${opts}</div>`;
        } else if (f.field_type === 'file') {
            inner = `<input type="file" class="${inputCls} text-xs" disabled>`;
        }

        const helper = f.helper_text
            ? `<p class="mt-0.5 text-[10px] text-gray-400">${escHtml(f.helper_text)}</p>` : '';

        return `<div>
            <label class="${labelCls}">${escHtml(f.label)} ${req}</label>
            ${inner}${helper}
        </div>`;
    }

    if (gDataDiri.length > 0) {
        html += `<div class="border-b border-gray-100 pb-3">
            <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">1</span>
                Data Diri & Edukasi
            </h4>
            <div class="space-y-2.5">${renderFieldGroup(gDataDiri, renderField)}</div>
        </div>`;
    }

    if (gPilihan.length > 0) {
        html += `<div class="border-b border-gray-100 pb-3">
            <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">2</span>
                Pilihan & Durasi
            </h4>
            <div class="space-y-2.5">${renderFieldGroup(gPilihan, renderField)}</div>
        </div>`;
    }

    if (gBerkas.length > 0) {
        html += `<div class="border-b border-gray-100 pb-3">
            <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">3</span>
                Berkas & Portofolio <span class="text-[10px] font-normal text-gray-400 normal-case">(Opsional)</span>
            </h4>
            <div class="space-y-2.5">${renderFieldGroup(gBerkas, renderField)}</div>
        </div>`;
    }

    if (gInfo.length > 0) {
        html += `<div class="border-b border-gray-100 pb-3">
            <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">4</span>
                Informasi Tambahan <span class="text-[10px] font-normal text-gray-400 normal-case">(Opsional)</span>
            </h4>
            <div class="space-y-2.5">${renderFieldGroup(gInfo, renderField)}</div>
        </div>`;
    }

    html += `<div class="pt-1">
        <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
            <span class="w-4 h-4 rounded-full bg-green-100 text-green-800 text-[10px] flex items-center justify-center font-bold">5</span>
            Finalisasi & Submit
        </h4>
        <div class="p-2.5 bg-amber-50 border border-amber-200 rounded text-[11px] text-amber-800 mb-2">
            Program magang ini bersifat <strong>unpaid / tidak bergaji</strong>.
        </div>
        <button disabled class="w-full py-2 bg-emerald-700 text-white rounded text-xs font-semibold opacity-80 cursor-not-allowed">
            Kirim Pendaftaran (Preview)
        </button>
    </div>`;

    html += `</div>`;
    return html;
}

function renderFieldGroup(fields, renderFn) {
    let html = '';
    let i = 0;
    while (i < fields.length) {
        const f = fields[i];
        if (f.column_span === 2 && i + 1 < fields.length && fields[i + 1].column_span === 2) {
            html += `<div class="grid grid-cols-2 gap-2">
                ${renderFn(f)}
                ${renderFn(fields[i + 1])}
            </div>`;
            i += 2;
        } else {
            html += renderFn(f);
            i++;
        }
    }
    return html;
}

// ================================================================
// Toast notification
// ================================================================
function showToast(message, type = 'success') {
    let toast = document.getElementById('global-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'global-toast';
        toast.className = 'fixed bottom-5 right-5 z-[9999] flex items-center gap-2 rounded-[10px] border px-4 py-3 text-sm font-semibold shadow-lg transition-all';
        document.body.appendChild(toast);
    }
    toast.className = 'fixed bottom-5 right-5 z-[9999] flex items-center gap-2 rounded-[10px] border px-4 py-3 text-sm font-semibold shadow-lg transition-all';
    if (type === 'success') {
        toast.classList.add('border-[#A5D6A7]', 'bg-[#E8F5E9]', 'text-[#1F5F3F]');
    } else {
        toast.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
    }
    toast.innerHTML = `${escHtml(message)} <button onclick="this.parentNode.remove()" class="ml-2 opacity-60 hover:opacity-100">✕</button>`;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => toast?.remove(), 4000);
}

// ================================================================
// Init: Render Live Preview seketika dari INITIAL_PREVIEW_DATA
// ================================================================
const initialContainer = document.getElementById('form-preview-container');
if (initialContainer && typeof INITIAL_PREVIEW_DATA !== 'undefined') {
    initialContainer.innerHTML = buildPreviewHtml(INITIAL_PREVIEW_DATA);
}

document.addEventListener('DOMContentLoaded', function () {
    const bindSortables = () => {
        ['data_diri_edukasi', 'pilihan_durasi', 'berkas_portofolio', 'informasi_tambahan', 'main', 'extra'].forEach(grp => {
            initSortable('field-list-' + grp, grp);
        });
    };

    if (typeof Sortable === 'undefined') {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
        s.onload = bindSortables;
        document.head.appendChild(s);
    } else {
        bindSortables();
    }
});
</script>
@endpush

@endsection
