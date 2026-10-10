@extends('layouts.dashboard', ['title' => 'Siklus Follow-Up Institusi'])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.follow-up' : 'admin.follow-up';
@endphp

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Siklus Follow-Up Institusi</h1>
            <p class="text-sm text-slate-500">Kelola antrean dan rekam jejak tindak lanjut (follow-up) tahunan kepada Guru/Dosen pembimbing institusi mitra.</p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route($routePrefix . '.create') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Buka Siklus Follow-Up
            </a>
        </div>
    </div>

    <!-- Alert Success / Error -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Stat Cards -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Total Siklus (Tahun {{ $year ?? date('Y') }})</span>
                <span class="rounded-lg bg-slate-100 p-2 text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-slate-800">{{ $stats['total'] }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Sedang Berjalan</span>
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-blue-600">{{ $stats['open'] }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Selesai / Terkonfirmasi</span>
                <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-emerald-600">{{ $stats['completed'] }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Tanpa Respons / Ditutup</span>
                <span class="rounded-lg bg-slate-100 p-2 text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-slate-600">{{ $stats['no_response'] }}</div>
            </div>
        </div>
    </div>

    <!-- Filters & Search -->
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <!-- Status Filter Tabs -->
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2">
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'all'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ empty($status) || $status === 'all' ? 'bg-slate-200 text-slate-800' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Semua Status
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'open'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'open' ? 'bg-blue-50 text-blue-700 font-bold' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Sedang Berjalan
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'completed'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'completed' ? 'bg-emerald-50 text-emerald-700 font-bold' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Selesai
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'closed'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'closed' ? 'bg-slate-100 text-slate-700 font-bold' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Ditutup
            </a>
        </div>

        <!-- Year & Search Form -->
        <form method="GET" action="{{ route($routePrefix . '.index') }}" class="flex flex-wrap items-center gap-2">
            @if (!empty($status))
                <input type="hidden" name="status" value="{{ $status }}">
            @endif

            <!-- Year Dropdown -->
            <select
                name="year"
                onchange="this.form.submit()"
                class="rounded-lg border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-xs font-semibold text-slate-700 focus:border-blue-500 focus:outline-none"
            >
                <option value="all" {{ ($year === 'all') ? 'selected' : '' }}>Semua Tahun</option>
                @for ($y = (int) date('Y') + 1; $y >= 2023; $y--)
                    <option value="{{ $y }}" {{ ((int) $year === $y) ? 'selected' : '' }}>Tahun {{ $y }}</option>
                @endfor
            </select>

            <!-- Search Field -->
            <div class="relative">
                <input
                    type="text"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Cari institusi / nama kontak..."
                    class="w-56 rounded-lg border border-slate-300 py-1.5 pl-9 pr-3 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                </div>
            </div>

            <button
                type="submit"
                class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            >
                Cari
            </button>

            @if (!empty($search) || !empty($status) || $year != date('Y'))
                <a
                    href="{{ route($routePrefix . '.index') }}"
                    class="rounded-lg border border-slate-200 bg-slate-100 px-2 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-200"
                >
                    Reset
                </a>
            @endif
        </form>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 font-semibold text-slate-600">
                <tr>
                    <th class="px-5 py-3.5 w-12 text-center">No</th>
                    <th class="px-5 py-3.5">Institusi Mitra</th>
                    <th class="px-5 py-3.5">Kontak Pembimbing / PIC</th>
                    <th class="px-5 py-3.5 text-center">Tahun</th>
                    <th class="px-5 py-3.5 text-center">Percobaan (Attempt)</th>
                    <th class="px-5 py-3.5">Status Siklus</th>
                    <th class="px-5 py-3.5">Aktivitas Terakhir</th>
                    <th class="px-5 py-3.5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($cycles as $cycle)
                    <tr class="transition hover:bg-slate-50/50">
                        <td class="px-5 py-3.5 text-center font-medium text-slate-400">
                            {{ ($cycles->currentPage() - 1) * $cycles->perPage() + $loop->iteration }}
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-bold text-slate-800">{{ $cycle->institution->name ?? '-' }}</span>
                            @if ($cycle->institution?->correspondence_unit)
                                <p class="text-[11px] text-slate-500">{{ $cycle->institution->correspondence_unit }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-semibold text-slate-800">{{ $cycle->contact->name ?? '-' }}</span>
                            <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-slate-500">
                                @if ($cycle->contact?->position)
                                    <span>{{ $cycle->contact->position }}</span>
                                    <span>&bull;</span>
                                @endif
                                <span>{{ $cycle->contact->phone ?? '-' }}</span>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-center font-semibold text-slate-800">
                            {{ $cycle->cycle_year }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <div class="inline-flex items-center gap-1">
                                @for ($i = 1; $i <= 3; $i++)
                                    @if ($i <= $cycle->current_attempt_count)
                                        <span class="inline-block h-2 w-5 rounded-full bg-blue-600" title="Attempt {{ $i }} Selesai"></span>
                                    @else
                                        <span class="inline-block h-2 w-5 rounded-full bg-slate-200" title="Attempt {{ $i }} Belum"></span>
                                    @endif
                                @endfor
                            </div>
                            <div class="mt-1 text-[11px] font-semibold text-slate-600">
                                {{ $cycle->current_attempt_count }}/3 Selesai
                            </div>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold border {{ $cycle->statusBadgeClass() }}">
                                {{ $cycle->statusLabel() }}
                            </span>
                            @if ($cycle->final_result)
                                <p class="mt-0.5 text-[10px] font-medium text-slate-500">
                                    Hasil: <span class="font-semibold text-slate-700">{{ $cycle->finalResultLabel() }}</span>
                                </p>
                            @endif
                            @if ($cycle->isLocked())
                                <div class="mt-1 inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 border border-amber-200" title="Terkunci hingga {{ $cycle->lock_expires_at?->format('H:i') }}">
                                    <svg class="h-2.5 w-2.5 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                    <span>Dikunci: {{ $cycle->lockedByUser->name ?? 'Staf' }}</span>
                                </div>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-slate-500 text-[11px]">
                            @if ($cycle->last_activity_at)
                                <span>{{ $cycle->last_activity_at->diffForHumans() }}</span>
                                <p class="text-[10px] text-slate-400">{{ $cycle->last_activity_at->format('d M Y H:i') }}</p>
                            @else
                                <span class="italic text-slate-400">Belum ada aktivitas</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <a
                                href="{{ route($routePrefix . '.show', $cycle) }}"
                                class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                            >
                                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                Detail & Catat
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-12 text-center">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                            </div>
                            <p class="mt-3 text-xs font-semibold text-slate-700">Belum ada siklus follow-up yang sesuai.</p>
                            <p class="mt-1 text-[11px] text-slate-400">Silakan buka siklus follow-up baru untuk memulai pendataan hubungan institusi mitra.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        @if ($cycles->hasPages())
            <div class="border-t border-slate-100 p-4">
                {{ $cycles->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
