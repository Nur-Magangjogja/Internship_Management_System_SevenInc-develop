@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">
    <div class="max-w-5xl mx-auto mb-6">
        <a href="{{ route('admin.brands.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-[#4B5F5A] hover:text-[#2D8659] transition mb-3">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
            Kembali ke Daftar Brand
        </a>
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34]">Tambah Brand Baru</h1>
                <p class="text-xs text-[#4B5F5A] mt-0.5">Lengkapi profil perusahaan, pejabat penandatangan, serta kelengkapan aset visual resmi.</p>
            </div>
        </div>
    </div>

    <div class="max-w-5xl mx-auto">
        <form action="{{ route('admin.brands.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
            @csrf

            {{-- 1. INFORMASI UTAMA BRAND --}}
            <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs">
                <div class="flex items-center gap-3 pb-4 mb-5 border-b border-[#DCE7E1]">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-[#1B3A34]">1. Informasi Dasar Perusahaan</h2>
                        <p class="text-xs text-[#4B5F5A]">Identitas brand perusahaan penyelenggara program magang dan webinar.</p>
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block mb-1.5 text-xs font-bold uppercase tracking-wider text-[#1B3A34]">
                                Kode Brand <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="code" value="{{ old('code') }}" required placeholder="Contoh: svn, alb"
                                class="w-full rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] px-4 py-2.5 text-sm text-[#1B3A34] outline-none focus:border-[#2D8659] focus:bg-white focus:ring-1 focus:ring-[#2D8659] transition">
                            <p class="mt-1 text-[11px] text-[#4B5F5A]">Kode unik singkatan brand (huruf kecil tanpa spasi).</p>
                            @error('code') <p class="mt-1 text-xs text-red-500 font-medium">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block mb-1.5 text-xs font-bold uppercase tracking-wider text-[#1B3A34]">
                                Nama Brand / Entitas <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="name" value="{{ old('name') }}" required placeholder="Contoh: Seven Inc, Alona"
                                class="w-full rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] px-4 py-2.5 text-sm text-[#1B3A34] outline-none focus:border-[#2D8659] focus:bg-white focus:ring-1 focus:ring-[#2D8659] transition">
                            <p class="mt-1 text-[11px] text-[#4B5F5A]">Nama resmi brand yang ditampilkan pada naskah dokumen.</p>
                            @error('name') <p class="mt-1 text-xs text-red-500 font-medium">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block mb-1.5 text-xs font-bold uppercase tracking-wider text-[#1B3A34]">
                            Alamat Kantor Perusahaan
                        </label>
                        <textarea name="company_address" rows="3" placeholder="Contoh: Jl. Janti Gg. Arjuna No. 59, Karangjambe, Banguntapan, Bantul, D.I. Yogyakarta 55198"
                            class="w-full rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] px-4 py-2.5 text-sm text-[#1B3A34] outline-none focus:border-[#2D8659] focus:bg-white focus:ring-1 focus:ring-[#2D8659] transition resize-none">{{ old('company_address') }}</textarea>
                        <p class="mt-1 text-[11px] text-[#4B5F5A]">Alamat resmi untuk kop surat, korespondensi, dan klausul naskah perjanjian.</p>
                        @error('company_address') <p class="mt-1 text-xs text-red-500 font-medium">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- 2. PENANDATANGAN DOKUMEN --}}
            <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs">
                <div class="flex items-center gap-3 pb-4 mb-5 border-b border-[#DCE7E1]">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-[#1B3A34]">2. Pejabat Penandatangan Dokumen</h2>
                        <p class="text-xs text-[#4B5F5A]">Pimpinan atau perwakilan resmi yang namanya tertera pada lembar tanda tangan dokumen.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div>
                        <label class="block mb-1.5 text-xs font-bold uppercase tracking-wider text-[#1B3A34]">Nama Pimpinan</label>
                        <input type="text" name="signatory_name" value="{{ old('signatory_name') }}" placeholder="Contoh: Rekario Danny Sanjaya, S.Kom."
                            class="w-full rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] px-4 py-2.5 text-sm text-[#1B3A34] outline-none focus:border-[#2D8659] focus:bg-white focus:ring-1 focus:ring-[#2D8659] transition">
                    </div>
                    <div>
                        <label class="block mb-1.5 text-xs font-bold uppercase tracking-wider text-[#1B3A34]">Email Korespondensi</label>
                        <input type="email" name="signatory_email" value="{{ old('signatory_email') }}" placeholder="Contoh: hrd@seveninc.id"
                            class="w-full rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] px-4 py-2.5 text-sm text-[#1B3A34] outline-none focus:border-[#2D8659] focus:bg-white focus:ring-1 focus:ring-[#2D8659] transition">
                    </div>
                    <div>
                        <label class="block mb-1.5 text-xs font-bold uppercase tracking-wider text-[#1B3A34]">Jabatan Resmi</label>
                        <input type="text" name="signatory_position" value="{{ old('signatory_position') }}" placeholder="Contoh: Direktur Utama / Pimpinan"
                            class="w-full rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] px-4 py-2.5 text-sm text-[#1B3A34] outline-none focus:border-[#2D8659] focus:bg-white focus:ring-1 focus:ring-[#2D8659] transition">
                    </div>
                </div>
            </div>

            {{-- 3. ASET VISUAL & DOKUMEN RESMI --}}
            <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-[#DCE7E1]">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-[#1B3A34]">3. Aset Visual & Dokumen Resmi</h2>
                            <p class="text-xs text-[#4B5F5A]">Lengkapi berkas visual brand untuk otomatisasi penerbitan dokumen resmi.</p>
                        </div>
                    </div>
                </div>

                {{-- Sub-grup 1: Identitas & Cap Resmi --}}
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-[#2D8659] flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-[#2D8659]"></span>
                            Bagian A: Identitas & Cap Resmi Perusahaan
                        </span>
                        <span class="text-[11px] font-medium text-[#4B5F5A] bg-[#F4F8F6] px-2.5 py-0.5 rounded-full border border-[#DCE7E1]">
                            Disarankan format PNG transparan
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        @include('admin.brands._image_upload_field', [
                            'name'                   => 'logo',
                            'label'                  => 'Logo Brand',
                            'icon'                   => 'building',
                            'badge'                  => 'Format PNG / JPG / WEBP • Maks 2MB',
                            'hint'                   => 'Digunakan di kop surat LOA, SKL, Penilaian, Rekomendasi, & Sertifikat.',
                            'current'                => null,
                            'maxMb'                  => 2,
                            'isTransparentSuggested' => true,
                        ])

                        @include('admin.brands._image_upload_field', [
                            'name'                   => 'signature',
                            'label'                  => 'Tanda Tangan Pimpinan',
                            'icon'                   => 'signature',
                            'badge'                  => 'Format PNG Transparan • Maks 2MB',
                            'hint'                   => 'Tanda tangan resmi pimpinan untuk lembar pengesahan & penerbitan dokumen.',
                            'current'                => null,
                            'maxMb'                  => 2,
                            'isTransparentSuggested' => true,
                        ])

                        @include('admin.brands._image_upload_field', [
                            'name'                   => 'stamp',
                            'label'                  => 'Stempel Perusahaan / Cap Resmi',
                            'icon'                   => 'stamp',
                            'badge'                  => 'Format PNG Transparan Disarankan • Maks 2MB',
                            'hint'                   => 'Cap stempel resmi perusahaan untuk LOA, SKL, Penilaian, & Sertifikat.',
                            'current'                => null,
                            'maxMb'                  => 2,
                            'isTransparentSuggested' => true,
                        ])
                    </div>
                </div>

                {{-- Sub-grup 2: Latar Belakang Sertifikat & Kartu --}}
                <div class="pt-4 border-t border-[#DCE7E1]/80">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-[#2D8659] flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-[#2D8659]"></span>
                            Bagian B: Latar Belakang Dokumen & Kartu Digital
                        </span>
                        <span class="text-[11px] font-medium text-[#4B5F5A] bg-[#F4F8F6] px-2.5 py-0.5 rounded-full border border-[#DCE7E1]">
                            Format kanvas cetak A4 & kartu
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        @include('admin.brands._image_upload_field', [
                            'name'                   => 'internship_certificate_bg',
                            'label'                  => 'Background Sertifikat Magang',
                            'icon'                   => 'certificate',
                            'badge'                  => 'A4 Landscape (297 × 210 mm) • Maks 2MB',
                            'hint'                   => 'Latar sertifikat kelulusan magang pemagang. Kosongkan jika ingin menggunakan Border Emas Klasik bawaan sistem.',
                            'current'                => null,
                            'maxMb'                  => 2,
                            'isTransparentSuggested' => false,
                        ])

                        @include('admin.brands._image_upload_field', [
                            'name'                   => 'webinar_certificate_bg',
                            'label'                  => 'Background Sertifikat Webinar',
                            'icon'                   => 'webinar',
                            'badge'                  => 'A4 Landscape (297 × 210 mm) • Maks 2MB',
                            'hint'                   => 'Latar sertifikat kegiatan webinar yang diadakan brand. Kosongkan jika ingin menggunakan Border Emas Klasik bawaan sistem.',
                            'current'                => null,
                            'maxMb'                  => 2,
                            'isTransparentSuggested' => false,
                        ])

                        @include('admin.brands._image_upload_field', [
                            'name'                   => 'membercard_bg',
                            'label'                  => 'Background Membercard Digital',
                            'icon'                   => 'card',
                            'badge'                  => 'Rasio 85.6 × 54 mm (~1.58:1) • Maks 3MB',
                            'hint'                   => 'Rekomendasi rasio kartu anggota (contoh: 1011 × 638 px).',
                            'current'                => null,
                            'maxMb'                  => 3,
                            'isTransparentSuggested' => false,
                        ])
                    </div>
                </div>
            </div>

            {{-- Tombol Submit --}}
            <div class="flex items-center justify-end gap-3 pt-4">
                <a href="{{ route('admin.brands.index') }}" class="rounded-xl border border-[#DCE7E1] bg-white px-5 py-2.5 text-sm font-semibold text-[#4B5F5A] hover:bg-[#F4F8F6] transition">Batal</a>
                <button type="submit" class="rounded-xl bg-[#2D8659] px-7 py-2.5 text-sm font-bold text-white hover:bg-[#1F5F3F] transition shadow-xs flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Simpan Brand
                </button>
            </div>
        </form>
    </div>
</div>

@include('admin.brands._image_upload_script')
@endsection
