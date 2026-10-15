@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Dashboard & Monitoring</p>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Pengaturan Form Pendaftaran</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Kelola skema pertanyaan dan formulir pendaftaran pemagang.</p>
        </div>
    </div>

    {{-- State Unavailable Card --}}
    <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-8 text-center shadow-sm max-w-2xl mx-auto">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#E8F5E9] text-[#2D8659] mb-4">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        </div>
        <h2 class="text-lg font-bold text-[#1B3A34] mb-2">Layanan Pengaturan Formulir Belum Tersedia</h2>
        <p class="text-sm text-[#4B5F5A] leading-relaxed mb-6">
            {{ $message ?? 'Tabel skema formulir pendaftaran dinamis belum termigrasi pada basis data sistem ini. Silakan hubungi administrator teknis untuk menyelesaikan antrean migrasi skema database.' }}
        </p>
        <div class="flex items-center justify-center gap-3">
            <a href="{{ route('admin.dashboard.index') }}"
                class="inline-flex items-center gap-2 rounded-[8px] bg-[#2D8659] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#1F5F3F] transition">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                Kembali ke Dashboard
            </a>
        </div>
    </div>
</div>
@endsection
