@extends('layouts.dashboard', ['title' => 'Histori Versi: ' . $template->name])

@section('content')
<div class="px-6 py-8 max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <a href="{{ route('admin.document-templates.edit', $template) }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-blue-600 transition mb-2">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                Kembali ke Editor Template
            </a>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $template->getTypeBadgeClass($template->type) }}">
                    {{ $template->getTypeLabel($template->type) }}
                </span>
                <span class="text-xs font-mono bg-slate-100 text-slate-600 px-2 py-0.5 rounded">
                    Versi Saat Ini: v{{ $template->version_number }}
                </span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Histori Versi & Audit Trail</h1>
            <p class="text-sm text-slate-500">Lihat rekam jejak perubahan template {{ $template->name }} dan pulihkan revisi sebelumnya kapan pun diperlukan.</p>
        </div>

        <div>
            <a
                href="{{ route('admin.document-templates.edit', $template) }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                Buka Editor
            </a>
        </div>
    </div>

    <!-- Flash message -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <!-- Version Timeline -->
    <div class="space-y-4">
        @foreach ($versions as $ver)
            @php
                $isCurrent = ($ver->version_number === $template->version_number);
            @endphp
            <div class="relative flex items-start gap-4 rounded-xl border {{ $isCurrent ? 'border-blue-400 bg-blue-50/20 ring-2 ring-blue-100' : 'border-slate-200 bg-white' }} p-5 shadow-sm">
                <!-- Version Badge / Circle -->
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $isCurrent ? 'bg-blue-600 text-white font-bold' : 'bg-slate-100 text-slate-700 font-semibold' }} text-xs">
                    v{{ $ver->version_number }}
                </div>

                <!-- Info & Details -->
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-slate-800">
                                {{ $ver->name ?: $template->name }}
                            </h3>
                            @if ($isCurrent)
                                <span class="rounded bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-800 uppercase tracking-wider">
                                    Versi Aktif
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-400">
                            {{ $ver->created_at->format('d M Y, H:i') }} WIB ({{ $ver->created_at->diffForHumans() }})
                        </div>
                    </div>

                    <!-- Change Note -->
                    <p class="mt-2 text-xs font-medium text-slate-600 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                        <strong class="text-slate-700">Catatan Perubahan:</strong> {{ $ver->change_summary ?: 'Tidak ada keterangan perubahan.' }}
                    </p>

                    <!-- Meta Author -->
                    <div class="mt-3 flex items-center gap-4 text-[11px] text-slate-400">
                        <span class="inline-flex items-center gap-1">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            Oleh: <strong class="text-slate-600">{{ $ver->creator?->name ?? 'Admin Sistem' }}</strong>
                        </span>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="shrink-0 self-center">
                    @if (!$isCurrent)
                        <form
                            method="POST"
                            action="{{ route('admin.document-templates.versions.restore', [$template, $ver]) }}"
                            onsubmit="return confirm('Apakah Anda yakin ingin memulihkan template ke Versi {{ $ver->version_number }}? Perubahan saat ini akan tetap tersimpan sebagai riwayat versi baru.');"
                        >
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-blue-600"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                    <path d="M3 3v5h5"/>
                                </svg>
                                Pulihkan ke Versi Ini
                            </button>
                        </form>
                    @else
                        <span class="text-xs font-semibold text-emerald-600 inline-flex items-center gap-1">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            Sedang Digunakan
                        </span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
