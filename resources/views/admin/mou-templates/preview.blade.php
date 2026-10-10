@extends('layouts.dashboard', ['title' => 'Pratinjau Template MOU'])

@section('content')
<div class="px-6 py-8">
    <!-- Top Action Bar -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between no-print">
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
                <h1 class="text-xl font-bold text-slate-800">
                    Pratinjau: {{ $mouTemplate->name }}
                    <span class="ml-1 text-sm font-normal text-slate-500 font-mono">(Versi {{ $mouTemplate->version_number }})</span>
                </h1>
                <p class="text-xs text-slate-500">Hasil simulasi penggantian token placeholder dokumen naskah perjanjian kerja sama.</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <!-- Selector Simulasi Institusi Nyata -->
            <form method="GET" action="{{ route('admin.mou-templates.preview', $mouTemplate) }}" class="flex items-center gap-2">
                <select
                    name="institution_id"
                    onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-xs font-medium text-slate-700 focus:border-blue-500 focus:outline-none"
                >
                    <option value="">-- Simulasi: Data Contoh --</option>
                    @foreach ($institutions as $inst)
                        <option value="{{ $inst->id }}" {{ $selectedInstitution?->id === $inst->id ? 'selected' : '' }}>
                            Institusi: {{ $inst->name }}
                        </option>
                    @endforeach
                </select>
            </form>

            <a
                href="{{ route('admin.mou-templates.edit', $mouTemplate) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Ubah Naskah
            </a>

            <button
                type="button"
                onclick="window.print()"
                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <!-- Paper Container Simulation (A4 Look) -->
    <div class="mx-auto max-w-4xl rounded-xl border border-slate-200 bg-white p-10 md:p-14 shadow-lg print:shadow-none print:border-none print:p-0">
        {!! $renderedHtml !!}
    </div>
</div>

<style>
@media print {
    .no-print, header, aside, footer, nav {
        display: none !important;
    }
    body, main {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
    }
}
</style>
@endsection
