@extends('layouts.dashboard', ['title' => 'Review & Pratinjau LOA'])

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">
    <!-- Top Action Bar (No-Print) -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between no-print">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.interns.pendaftar') }}"
               class="inline-flex h-9 w-9 items-center justify-center rounded-[9px] border border-[#DCE7E1] bg-white text-[#4B5F5A] shadow-sm transition hover:bg-[#E8F5E9] hover:text-[#1F5F3F]"
               title="Kembali ke Data Pendaftar">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
            </a>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold tracking-tight text-[#1B3A34]">
                        Review Dokumen LOA
                    </h1>
                    <span class="inline-flex items-center rounded-full bg-[#E8F5E9] px-2.5 py-0.5 text-xs font-semibold text-[#1F5F3F] border border-[#A5D6A7]">
                        {{ $intern->fullname }}
                    </span>
                    <span class="rounded bg-white border border-[#DCE7E1] px-2 py-0.5 text-xs text-[#4B5F5A]">
                        {{ $intern->division?->name ?? $intern->internship_interest ?? 'UI/UX Designer' }}
                    </span>
                </div>
                <p class="mt-0.5 text-xs text-[#4B5F5A]">
                    Tinjau isi surat Letter of Acceptance sebelum mengunduh berkas PDF resmi.
                </p>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap items-center gap-2.5">
            <button type="button"
                    onclick="document.getElementById('loaIframe').contentWindow.print()"
                    class="inline-flex items-center gap-2 rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-xs font-semibold text-[#1B3A34] shadow-sm transition hover:bg-[#F4F8F6]">
                <svg class="h-4 w-4 text-[#4B5F5A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                Cetak / Print
            </button>

            <form action="{{ route('admin.loa.generate') }}" method="POST" class="inline">
                @csrf
                <input type="hidden" name="intern_id" value="{{ $intern->id }}">
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-[#1F5F3F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Download PDF Resmi
                </button>
            </form>
        </div>
    </div>

    <!-- Preview Container (A4 sheet styling) -->
    <div class="flex justify-center">
        <div class="w-full max-w-[215mm] overflow-hidden rounded-[12px] border border-[#DCE7E1] bg-white shadow-lg">
            <iframe id="loaIframe"
                    src="{{ route('admin.loa.raw', $intern->id) }}"
                    class="w-full border-0"
                    style="height: 1160px; min-height: 1120px;"
                    onload="try { const h = this.contentWindow.document.body.scrollHeight; if(h > 400) this.style.height = (h + 30) + 'px'; } catch(e){}"
                    title="Pratinjau LOA">
            </iframe>
        </div>
    </div>
</div>
@endsection
