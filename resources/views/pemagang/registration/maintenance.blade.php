@extends('pemagang.layouts.app')

@section('title', 'Pemeliharaan Sistem Pendaftaran')

@section('content')
<div class="max-w-3xl mx-auto py-12 px-4 sm:px-6">
    <div class="rounded-2xl border border-[#DCE7E1] bg-white p-8 sm:p-10 text-center shadow-xs">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 border border-amber-200 mb-5">
            <i class="fa-solid fa-screwdriver-wrench text-2xl"></i>
        </div>

        <p class="text-xs font-bold uppercase tracking-wider text-[#2D8659] mb-1">
            {{ $detectedBrand->name ?? 'Brand Pendaftaran' }}
        </p>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1B3A34] mb-3">
            Sistem Pendaftaran Sedang Dalam Pemeliharaan
        </h1>
        <p class="text-sm sm:text-base text-[#4B5F5A] max-w-xl mx-auto leading-relaxed mb-6">
            Pendaftaran magang untuk brand <strong class="text-[#1B3A34]">{{ $brandName }}</strong> saat ini sedang dalam tahap pemeliharaan sistem dan akan aktif bila sudah terhubung. Silakan hubungi admin atau coba beberapa saat lagi.
        </p>

        @if(isset($lockedBrandNotice) && $lockedBrandNotice)
            <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50/60 p-4 text-xs text-blue-800 text-left max-w-lg mx-auto flex items-start gap-3">
                <i class="fa-solid fa-circle-info text-blue-500 mt-0.5 text-sm shrink-0"></i>
                <div>
                    <span class="font-bold">Informasi Akun:</span>
                    Akun Anda terdaftar pada program magang <span class="font-bold underline">{{ $lockedBrandNotice }}</span>.
                </div>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-center gap-3 pt-2">
            <a href="{{ route('pemagang.dashboard') }}"
                class="inline-flex items-center gap-2 rounded-xl bg-[#2D8659] px-6 py-2.5 text-sm font-bold text-white transition hover:bg-[#1F5F3F] shadow-xs">
                <i class="fa-solid fa-arrow-left text-xs"></i>
                Kembali ke Dashboard
            </a>
            @if(isset($detectedBrand->signatory_email) && $detectedBrand->signatory_email)
                <a href="mailto:{{ $detectedBrand->signatory_email }}"
                    class="inline-flex items-center gap-2 rounded-xl border border-[#DCE7E1] bg-white px-5 py-2.5 text-sm font-semibold text-[#4B5F5A] transition hover:bg-[#F4F8F6]">
                    <i class="fa-solid fa-envelope text-xs"></i>
                    Hubungi Admin
                </a>
            @endif
        </div>
    </div>
</div>
@endsection
