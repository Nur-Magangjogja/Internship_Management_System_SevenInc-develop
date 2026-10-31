@extends('pemagang.layouts.app')

@section('title', 'Pendaftaran Divisi Ditutup')

@section('content')
<div class="max-w-3xl mx-auto py-12 px-4 sm:px-6">
    <div class="rounded-2xl border border-amber-200 bg-white p-8 sm:p-10 text-center shadow-xs">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 border border-amber-200 mb-5">
            <i class="fa-solid fa-lock text-2xl"></i>
        </div>
        <p class="text-xs font-bold uppercase tracking-wider text-[#2D8659] mb-1">
            {{ $detectedBrand->name ?? 'Brand Pendaftaran' }}
        </p>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1B3A34] mb-3">
            Pilihan Divisi Sedang Ditutup
        </h1>
        <p class="text-sm sm:text-base text-[#4B5F5A] max-w-xl mx-auto leading-relaxed mb-6">
            Saat ini belum ada divisi aktif yang dibuka untuk pendaftaran melalui brand ini. Sistem tidak akan menampilkan divisi dari brand lain sebagai pengganti. Silakan hubungi Admin untuk informasi lebih lanjut.
        </p>
        <div class="flex flex-wrap items-center justify-center gap-3 pt-2">
            <a href="{{ route('pemagang.dashboard') }}" class="inline-flex items-center gap-2 rounded-xl bg-[#2D8659] px-6 py-2.5 text-sm font-bold text-white transition hover:bg-[#1F5F3F] shadow-xs">
                <i class="fa-solid fa-arrow-left text-xs"></i>
                Kembali ke Dashboard
            </a>
            @if(!empty($detectedBrand?->signatory_email))
                <a href="mailto:{{ $detectedBrand->signatory_email }}" class="inline-flex items-center gap-2 rounded-xl border border-[#DCE7E1] bg-white px-5 py-2.5 text-sm font-semibold text-[#4B5F5A] transition hover:bg-[#F4F8F6]">
                    <i class="fa-solid fa-envelope text-xs"></i>
                    Hubungi Admin
                </a>
            @endif
        </div>
    </div>
</div>
@endsection
