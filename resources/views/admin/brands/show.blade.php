@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">
    <div class="max-w-5xl mx-auto mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="{{ route('admin.brands.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-[#4B5F5A] hover:text-[#2D8659] transition mb-3">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
                Kembali ke Daftar Brand
            </a>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34]">Detail Brand: {{ $brand->name }}</h1>
            <p class="text-xs text-[#4B5F5A] mt-0.5">Ringkasan profil perusahaan, pejabat penandatangan, dan aset visual aktif.</p>
        </div>
        <a href="{{ route('admin.brands.edit', $brand->id) }}" class="flex items-center gap-2 rounded-xl bg-[#2D8659] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#1F5F3F] transition shadow-xs">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Edit Brand Ini
        </a>
    </div>

    <div class="max-w-5xl mx-auto space-y-6">
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

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Kode Brand</label>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-mono font-bold bg-[#F4F8F6] text-[#1B3A34] border border-[#DCE7E1]">
                        {{ $brand->code }}
                    </span>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Nama Brand / Entitas</label>
                    <p class="text-sm font-bold text-[#1B3A34]">{{ $brand->name }}</p>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Alamat Kantor</label>
                    <p class="text-sm text-[#1B3A34] leading-relaxed">{{ $brand->company_address ?: '-' }}</p>
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
                    <p class="text-xs text-[#4B5F5A]">Pimpinan atau perwakilan resmi pada lembar tanda tangan dokumen.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Nama Pimpinan</label>
                    <p class="text-sm font-semibold text-[#1B3A34]">{{ $brand->signatory_name ?: '-' }}</p>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Email Korespondensi</label>
                    <p class="text-sm text-[#1B3A34]">{{ $brand->signatory_email ?: '-' }}</p>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Jabatan Resmi</label>
                    <p class="text-sm text-[#1B3A34]">{{ $brand->signatory_position ?: '-' }}</p>
                </div>
            </div>
        </div>

        {{-- 3. ASET VISUAL --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs space-y-6">
            <div class="flex items-center gap-3 pb-4 border-b border-[#DCE7E1]">
                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-[#1B3A34]">3. Aset Visual & Dokumen Resmi</h2>
                    <p class="text-xs text-[#4B5F5A]">Berkas visual resmi brand yang aktif pada sistem penerbitan dokumen.</p>
                </div>
            </div>

            {{-- Sub-grup 1: Cap & Identitas Resmi --}}
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-[#2D8659] flex items-center gap-1.5 mb-3">
                    <span class="w-2 h-2 rounded-full bg-[#2D8659]"></span>
                    Bagian A: Identitas & Cap Resmi Perusahaan
                </span>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Logo --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Logo Brand</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]">
                                @if($brand->logo)
                                    <img src="{{ Storage::url($brand->logo) }}" alt="Logo" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada logo</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->logo)
                            <a href="{{ Storage::url($brand->logo) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- Tanda Tangan --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Tanda Tangan Pimpinan</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]">
                                @if($brand->signature)
                                    <img src="{{ Storage::url($brand->signature) }}" alt="Signature" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada tanda tangan</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->signature)
                            <a href="{{ Storage::url($brand->signature) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- Stempel --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Stempel Perusahaan / Cap Resmi</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]">
                                @if($brand->stamp)
                                    <img src="{{ Storage::url($brand->stamp) }}" alt="Stamp" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada stempel</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->stamp)
                            <a href="{{ Storage::url($brand->stamp) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Sub-grup 2: Latar Belakang --}}
            <div class="pt-4 border-t border-[#DCE7E1]/80">
                <span class="text-xs font-bold uppercase tracking-wider text-[#2D8659] flex items-center gap-1.5 mb-3">
                    <span class="w-2 h-2 rounded-full bg-[#2D8659]"></span>
                    Bagian B: Latar Belakang Dokumen & Kartu Digital
                </span>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- BG Magang --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Background Sertifikat Magang</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden">
                                @if($brand->internship_certificate_bg)
                                    <img src="{{ Storage::url($brand->internship_certificate_bg) }}" alt="BG Magang" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada background</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->internship_certificate_bg)
                            <a href="{{ Storage::url($brand->internship_certificate_bg) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- BG Webinar --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Background Sertifikat Webinar</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden">
                                @if($brand->webinar_certificate_bg)
                                    <img src="{{ Storage::url($brand->webinar_certificate_bg) }}" alt="BG Webinar" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada background</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->webinar_certificate_bg)
                            <a href="{{ Storage::url($brand->webinar_certificate_bg) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- Membercard BG --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Background Membercard Digital</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden">
                                @if($brand->membercard_bg)
                                    <img src="{{ Storage::url($brand->membercard_bg) }}" alt="BG Membercard" class="max-h-full max-w-full object-cover rounded shadow-2xs">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Bawaan tema brand</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->membercard_bg)
                            <a href="{{ Storage::url($brand->membercard_bg) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- 4. PREVIEW MEMBERCARD DIGITAL --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs">
            <div class="mb-4">
                <h3 class="text-base font-bold text-[#1B3A34]">4. Pratinjau Membercard Digital Alumni</h3>
                <p class="text-xs text-[#4B5F5A]">Simulasi kartu anggota digital pemagang dengan identitas dan tema warna brand <strong>{{ $brand->name }}</strong>.</p>
            </div>
            <div class="p-6 rounded-xl bg-[#0f172a]/5 border border-[#DCE7E1] flex justify-center">
                <x-membercard :brand="$brand"
                              name="CONTOH NAMA LENGKAP"
                              :code="$brand->code . '24001'"
                              :angkatan="date('Y')"
                              instansi="Universitas / Institut Contoh"
                              divisi="Divisi Magang" />
            </div>
        </div>
    </div>
</div>
@endsection
