@extends('layouts.dashboard', ['title' => 'Preview: ' . $template->name])

@section('content')
<div class="px-6 py-6 max-w-5xl mx-auto">
    <!-- Top Bar -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $template->getTypeBadgeClass($template->type) }}">
                    {{ $template->getTypeLabel($template->type) }}
                </span>
                <span class="text-xs font-mono bg-slate-100 text-slate-600 px-2 py-0.5 rounded">
                    Versi {{ $template->version_number }}
                </span>
            </div>
            <h1 class="text-xl font-bold text-slate-800">Preview: {{ $template->name }}</h1>
            <p class="text-xs text-slate-500">Tampilan hasil render dokumen dengan substitusi data contoh pemagang Seven Inc.</p>
        </div>

        <div class="flex items-center gap-2">
            <a
                href="{{ route('admin.document-templates.edit', $template) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                </svg>
                Kembali ke Editor
            </a>

            <button
                type="button"
                onclick="window.print()"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak Halaman
            </button>

            <a
                href="{{ route('admin.document-templates.preview-pdf', $template) }}"
                target="_blank"
                class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                Unduh / Buka PDF Asli
            </a>
        </div>
    </div>

    <!-- Rendered Document Container -->
    <div class="flex justify-center bg-slate-200/80 p-6 rounded-2xl overflow-x-auto shadow-inner">
        <div class="bg-white shadow-2xl overflow-hidden print:shadow-none">
            {!! $renderedHtml !!}
        </div>
    </div>

    <!-- Mock Data Substitution Legend -->
    <div class="mt-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-3">Data Contoh yang Digunakan dalam Preview Ini:</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
            <div class="rounded bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">pemagang.nama</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['pemagang']['nama'] }}</span>
            </div>
            <div class="rounded bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">pemagang.nim</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['pemagang']['nim'] }}</span>
            </div>
            <div class="rounded bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">pemagang.institusi</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['pemagang']['institusi'] }}</span>
            </div>
            <div class="rounded bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">magang.divisi</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['magang']['divisi'] }}</span>
            </div>
            <div class="rounded bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">dokumen.nomor</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['dokumen']['nomor'] }}</span>
            </div>
            <div class="rounded bg-slate-50 p-2.5 border border-slate-100">
                <span class="font-mono text-blue-600 block text-[11px] font-bold">perusahaan.pimpinan</span>
                <span class="text-slate-800 font-semibold">{{ $mockData['perusahaan']['pimpinan'] }}</span>
            </div>
        </div>
    </div>
</div>
@endsection
