@extends('layouts.dashboard', ['title' => 'Siklus Follow-Up Institusi'])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.follow-up' : 'admin.follow-up';
@endphp

@section('content')
<div class="px-6 py-8 space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
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

    <!-- Alert Success / Info / Error -->
    @if (session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('info'))
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm font-medium text-blue-800">
            {{ session('info') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Stat Cards (5 Status Alur Kerja Terpadu) -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <!-- 1. Belum Dihubungi -->
        <a href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'not_contacted'])) }}" class="rounded-xl border border-blue-200 bg-white p-4 shadow-sm hover:border-blue-400 transition">
            <span class="text-xs font-semibold text-slate-500 block">Belum Dihubungi</span>
            <div class="mt-2 text-2xl font-bold text-slate-800">{{ $stats['not_contacted'] ?? 0 }}</div>
            <p class="text-[10px] text-slate-400 mt-0.5">Attempt 0/3</p>
        </a>

        <!-- 2. Menunggu Respons -->
        <a href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'waiting_response'])) }}" class="rounded-xl border border-indigo-200 bg-white p-4 shadow-sm hover:border-indigo-400 transition">
            <span class="text-xs font-semibold text-slate-500 block">Menunggu Respons</span>
            <div class="mt-2 text-2xl font-bold text-indigo-600">{{ $stats['waiting_response'] ?? 0 }}</div>
            <p class="text-[10px] text-slate-400 mt-0.5">Menunggu konfirmasi PIC</p>
        </a>

        <!-- 3. Perlu Tindak Lanjut (Overdue) -->
        <a href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'needs_follow_up'])) }}" class="rounded-xl border border-rose-200 bg-rose-50/20 p-4 shadow-sm hover:border-rose-400 transition">
            <span class="text-xs font-bold text-rose-700 block">Perlu Tindak Lanjut</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold text-rose-700">{{ $stats['needs_follow_up'] ?? 0 }}</span>
                @if(($stats['overdue'] ?? 0) > 0)
                    <span class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800">
                        {{ $stats['overdue'] }} Overdue
                    </span>
                @endif
            </div>
            <p class="text-[10px] text-rose-600/80 mt-0.5">Jatuh tempo / butuh aksi</p>
        </a>

        <!-- 4. Bersedia Bekerja Sama (Siap MOU) -->
        <a href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'willing'])) }}" class="rounded-xl border border-purple-200 bg-purple-50/20 p-4 shadow-sm hover:border-purple-400 transition">
            <span class="text-xs font-bold text-purple-800 block">Bersedia Bekerja Sama</span>
            <div class="mt-2 text-2xl font-bold text-purple-700">{{ $stats['willing'] ?? 0 }}</div>
            <p class="text-[10px] text-purple-600 mt-0.5">Siap dibuatkan MOU</p>
        </a>

        <!-- 5. Selesai / Arsip -->
        <a href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'completed'])) }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-slate-400 transition">
            <span class="text-xs font-semibold text-slate-500 block">Selesai</span>
            <div class="mt-2 text-2xl font-bold text-emerald-600">{{ $stats['completed'] ?? 0 }}</div>
            <p class="text-[10px] text-slate-400 mt-0.5">Hasil final tercatat</p>
        </a>
    </div>

    <!-- Filters & Search -->
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <!-- Status Filter Tabs -->
        <div class="flex flex-wrap items-center gap-1.5 border-b border-slate-200 pb-2">
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'all'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ empty($status) || $status === 'all' ? 'bg-slate-200 text-slate-800' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Semua Status
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'overdue'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-bold transition {{ $status === 'overdue' ? 'bg-rose-600 text-white' : 'text-rose-700 bg-rose-50 hover:bg-rose-100' }}"
            >
                <span class="h-1.5 w-1.5 rounded-full {{ $status === 'overdue' ? 'bg-white' : 'bg-rose-500' }}"></span>
                Jatuh Tempo (Overdue)
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'not_contacted'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'not_contacted' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Belum Dihubungi
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'waiting_response'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'waiting_response' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Menunggu Respons
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'needs_follow_up'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'needs_follow_up' ? 'bg-amber-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Perlu Tindak Lanjut
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'willing'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'willing' ? 'bg-purple-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Bersedia Bekerja Sama
            </a>
            <a
                href="{{ route($routePrefix . '.index', array_merge(request()->except('status', 'page'), ['status' => 'completed'])) }}"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'completed' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Selesai
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

            @if (!empty($search) || (!empty($status) && $status !== 'all') || $year != date('Y') || !empty($institutionId))
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
                    <th class="px-5 py-3.5">Institusi Mitra & MoU</th>
                    <th class="px-5 py-3.5">Kontak Pembimbing / PIC</th>
                    <th class="px-5 py-3.5">Status Alur Kerja</th>
                    <th class="px-5 py-3.5">Jadwal & Riwayat</th>
                    <th class="px-5 py-3.5 text-center">Percobaan</th>
                    <th class="px-5 py-3.5 text-right">Tindakan Berikutnya</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($cycles as $cycle)
                    @php
                        $workflow = $cycle->workflow_info;
                    @endphp
                    <tr class="transition hover:bg-slate-50/50 {{ $workflow['is_overdue'] ? 'bg-rose-50/20' : '' }}">
                        <td class="px-5 py-3.5 text-center font-medium text-slate-400">
                            {{ ($cycles->currentPage() - 1) * $cycles->perPage() + $loop->iteration }}
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-bold text-slate-800">{{ $cycle->institution->name ?? '-' }}</span>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if ($cycle->institution?->activeMou)
                                    <span class="inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 border border-emerald-200">
                                        MOU Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">
                                        Tanpa MOU Aktif
                                    </span>
                                @endif
                                <span class="text-[10px] text-slate-400">Tahun {{ $cycle->cycle_year }}</span>
                            </div>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-semibold text-slate-800">{{ $cycle->contact->name ?? '-' }}</span>
                            <div class="mt-0.5 flex items-center gap-1 text-[11px] text-slate-500">
                                <span>{{ $cycle->contact?->position_title ?? 'Pembimbing' }}</span>
                                <span>&bull;</span>
                                @if($cycle->contact?->phone)
                                    <a href="https://wa.me/{{ preg_replace('/[^\d]/', '', $cycle->contact->phone) }}" target="_blank" class="text-emerald-600 hover:underline">
                                        {{ $cycle->contact->phone }}
                                    </a>
                                @else
                                    <span>-</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold border {{ $workflow['badge_class'] }}">
                                {{ $workflow['label'] }}
                            </span>
                            @if ($cycle->final_result && $cycle->isClosed())
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
                        <td class="px-5 py-3.5 text-slate-600 text-[11px]">
                            @if ($workflow['is_overdue'])
                                <span class="inline-flex items-center rounded bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-700">
                                    {{ $workflow['due_label'] }}
                                </span>
                            @elseif ($workflow['is_due_today'])
                                <span class="inline-flex items-center rounded bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">
                                    Hari Ini
                                </span>
                            @elseif ($workflow['due_label'])
                                <span class="text-slate-600 font-medium">{{ $workflow['due_label'] }}</span>
                            @else
                                <span class="text-slate-400 italic">-</span>
                            @endif
                            <div class="mt-1 text-[10px] text-slate-400">
                                {{ $cycle->last_activity_at ? 'Aktivitas: ' . $cycle->last_activity_at->diffForHumans() : 'Belum ada aktivitas' }}
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <div class="inline-flex items-center gap-1">
                                @for ($i = 1; $i <= 3; $i++)
                                    @if ($i <= $cycle->current_attempt_count)
                                        <span class="inline-block h-2 w-4 rounded-full bg-blue-600" title="Attempt {{ $i }} Selesai"></span>
                                    @else
                                        <span class="inline-block h-2 w-4 rounded-full bg-slate-200" title="Attempt {{ $i }} Belum"></span>
                                    @endif
                                @endfor
                            </div>
                            <div class="mt-1 text-[10px] font-medium text-slate-500">
                                {{ $cycle->current_attempt_count }}/3 Selesai
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                @if ($workflow['next_action_type'] === 'create_mou')
                                    <a
                                        href="{{ $workflow['next_action_url'] }}"
                                        class="inline-flex items-center gap-1 rounded-md bg-purple-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm transition hover:bg-purple-700"
                                        title="Buat Naskah Perjanjian MOU"
                                    >
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                            <polyline points="14 2 14 8 20 8"/>
                                        </svg>
                                        Draft MOU
                                    </a>
                                @endif
                                <a
                                    href="{{ route($routePrefix . '.show', $cycle) }}"
                                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                                >
                                    Detail & Catat
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center">
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
