@extends('layouts.dashboard', ['title' => 'Dokumen Kerja Sama (MOU & PKS)'])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.mous' : 'admin.mous';
@endphp

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Dokumen Kerja Sama (MOU & PKS)</h1>
            <p class="text-sm text-slate-500">Kelola siklus hidup perjanjian kerja sama institusi dari pembuatan naskah, serah terima, hingga pengesahan bertandatangan.</p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route($routePrefix . '.create') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Buat Dokumen MOU Baru
            </a>
        </div>
    </div>

    <!-- Alert Success / Error -->
    @if(session('success'))
        <div class="mb-6 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Statistic Cards -->
    <div class="mb-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Total Naskah MOU</span>
                <span class="rounded-lg bg-slate-100 p-2 text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-slate-800">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">MOU Aktif Berlaku</span>
                <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-emerald-600">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Menunggu Serah Terima</span>
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['generated']) }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Menunggu Berkas TTD</span>
                <span class="rounded-lg bg-amber-50 p-2 text-amber-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-amber-600">{{ number_format($stats['delivered']) }}</div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <form method="GET" action="{{ route($routePrefix . '.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Cari Dokumen / Institusi</label>
                <div class="relative">
                    <input
                        type="text"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Nomor MOU atau nama institusi..."
                        class="w-full rounded-lg border border-slate-200 py-2 pl-9 pr-3 text-xs focus:border-blue-500 focus:outline-none"
                    >
                    <svg class="absolute left-3 top-2.5 h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Status Dokumen</label>
                <select
                    name="status"
                    class="w-full rounded-lg border border-slate-200 py-2 px-3 text-xs focus:border-blue-500 focus:outline-none"
                >
                    <option value="">Semua Status</option>
                    <option value="generated" {{ request('status') === 'generated' ? 'selected' : '' }}>Generated (Naskah Dibuat)</option>
                    <option value="delivered" {{ request('status') === 'delivered' ? 'selected' : '' }}>Delivered (Terkirim ke Mitra)</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active (Aktif & Sah)</option>
                    <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expired (Masa Berlaku Berakhir)</option>
                    <option value="superseded" {{ request('status') === 'superseded' ? 'selected' : '' }}>Superseded (Digantikan Baru)</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled (Dibatalkan)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Filter Institusi</label>
                <select
                    name="institution_id"
                    class="w-full rounded-lg border border-slate-200 py-2 px-3 text-xs focus:border-blue-500 focus:outline-none"
                >
                    <option value="">Semua Institusi</option>
                    @foreach($institutions as $inst)
                        <option value="{{ $inst->id }}" {{ request('institution_id') == $inst->id ? 'selected' : '' }}>
                            {{ $inst->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-slate-800 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-700"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                    </svg>
                    Terapkan Filter
                </button>
                @if(request()->anyFilled(['search', 'status', 'institution_id']))
                    <a
                        href="{{ route($routePrefix . '.index') }}"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                        title="Reset Filter"
                    >
                        Reset
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 font-semibold text-slate-600">
                <tr>
                    <th class="px-5 py-3.5 w-12 text-center">No</th>
                    <th class="px-5 py-3.5">Nomor Registrasi MOU</th>
                    <th class="px-5 py-3.5">Institusi Mitra & Penandatangan</th>
                    <th class="px-5 py-3.5">Masa Berlaku</th>
                    <th class="px-5 py-3.5">Status Lifecycle</th>
                    <th class="px-5 py-3.5">Berkas TTD</th>
                    <th class="px-5 py-3.5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse($mous as $mou)
                    <tr class="transition hover:bg-slate-50/50">
                        <td class="px-5 py-3.5 text-center font-medium text-slate-400">
                            {{ ($mous->currentPage() - 1) * $mous->perPage() + $loop->iteration }}
                        </td>
                        <td class="px-5 py-3.5">
                            <a href="{{ route($routePrefix . '.show', $mou) }}" class="font-bold text-blue-600 hover:underline">
                                {{ $mou->company_mou_number }}
                            </a>
                            @if($mou->institution_mou_number)
                                <p class="text-[11px] text-slate-500 font-mono">Mitra: {{ $mou->institution_mou_number }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-semibold text-slate-900">{{ $mou->first_party_institution_name }}</span>
                            <p class="text-[11px] text-slate-500">
                                Diwakili: {{ $mou->first_party_signatory_name }} ({{ $mou->first_party_signatory_title }})
                            </p>
                        </td>
                        <td class="px-5 py-3.5">
                            @if($mou->effective_start && $mou->effective_end)
                                <span class="font-medium text-slate-800">
                                    {{ \Carbon\Carbon::parse($mou->effective_start)->isoFormat('D MMM Y') }} s/d {{ \Carbon\Carbon::parse($mou->effective_end)->isoFormat('D MMM Y') }}
                                </span>
                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                            <p class="text-[10px] text-slate-400">Ttd: {{ \Carbon\Carbon::parse($mou->agreement_date)->isoFormat('D MMM Y') }}</p>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold border {{ $mou->status_badge_classes }}">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                {{ $mou->status_label }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            @if($mou->has_signed_file)
                                <a
                                    href="{{ route($routePrefix . '.download-signed', $mou) }}"
                                    class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 hover:text-emerald-700"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="7 10 12 15 17 10"/>
                                        <line x1="12" y1="15" x2="12" y2="3"/>
                                    </svg>
                                    Unduh PDF
                                </a>
                            @else
                                <span class="text-[11px] text-slate-400 italic">Belum diunggah</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1.5">
                                <a
                                    href="{{ route($routePrefix . '.show', $mou) }}"
                                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                                >
                                    Detail
                                </a>
                                <a
                                    href="{{ route($routePrefix . '.print', $mou) }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                                    title="Cetak Naskah Dokumen"
                                >
                                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="6 9 6 2 18 2 18 9"/>
                                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                        <rect x="6" y="14" width="12" height="8"/>
                                    </svg>
                                    Cetak
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-slate-400">
                            Tidak ada dokumen MOU yang ditemukan.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($mous->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">
                {{ $mous->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
