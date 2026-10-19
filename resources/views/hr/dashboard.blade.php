@extends('layouts.dashboard', ['title' => 'Dashboard HR Staff'])

@section('content')
<div class="px-6 py-8 max-w-7xl mx-auto space-y-8">
    <!-- Header & Quick Actions -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-2xl font-bold text-slate-800">Dashboard Operasional HR</h1>
                <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 border border-emerald-200">
                    Role: HR Staff
                </span>
            </div>
            <p class="text-sm text-slate-500 mt-1">Selamat datang, {{ $user->name ?? $user->email }}. Pantau hubungan kemitraan sekolah/kampus, tindak lanjut guru/dosen, dan penerbitan dokumen MOU.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ route('hr.follow-up.create') }}"
                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Buka Follow-Up Baru
            </a>
            <a
                href="{{ route('hr.mous.create') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-purple-200 bg-purple-50 px-3.5 py-2 text-xs font-semibold text-purple-700 shadow-sm transition hover:bg-purple-100"
            >
                <svg class="h-3.5 w-3.5 text-purple-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                Buat Dokumen MOU
            </a>
            <a
                href="{{ route('hr.institutions.index') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>
                </svg>
                Master Institusi
            </a>
        </div>
    </div>

    <!-- Quick Search Institusi & Kontak Cepat -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <form method="GET" action="{{ route('hr.dashboard') }}" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
            <div class="relative flex-1">
                <input
                    type="text"
                    name="search"
                    value="{{ $searchQuery ?? '' }}"
                    placeholder="Pencarian Cepat: Ketik nama sekolah, kampus, atau nama guru/dosen PIC..."
                    class="w-full rounded-lg border border-slate-300 py-2 pl-10 pr-4 text-xs text-slate-800 placeholder-slate-400 focus:border-blue-500 focus:outline-none"
                >
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="submit"
                    class="rounded-lg bg-slate-800 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-900"
                >
                    Cari Institusi
                </button>
                @if(!empty($searchQuery))
                    <a
                        href="{{ route('hr.dashboard') }}"
                        class="rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-200"
                    >
                        Reset
                    </a>
                @endif
            </div>
        </form>

        @if(isset($searchedInstitutions) && $searchedInstitutions->isNotEmpty())
            <div class="mt-4 border-t border-slate-100 pt-3">
                <h3 class="text-xs font-bold text-slate-700 mb-2">Hasil Pencarian Cepat ({{ $searchedInstitutions->count() }} Ditemukan):</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($searchedInstitutions as $inst)
                        <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-3 flex flex-col justify-between">
                            <div>
                                <a href="{{ route('hr.institutions.show', $inst) }}" class="font-bold text-xs text-slate-800 hover:text-blue-600 block">
                                    {{ $inst->name }}
                                </a>
                                <p class="text-[11px] text-slate-500 mt-0.5">{{ $inst->correspondence_unit ?? $inst->phone ?? 'Kontak institusi' }}</p>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @if($inst->activeMou)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 border border-emerald-200">
                                            MOU Aktif
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                            Belum Ada MOU Aktif
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-between border-t border-slate-200/60 pt-2 text-xs">
                                <a href="{{ route('hr.institutions.show', $inst) }}" class="text-slate-600 hover:underline text-[11px]">
                                    Lihat Kontak &rarr;
                                </a>
                                <a href="{{ route('hr.follow-up.create', ['institution_id' => $inst->id]) }}" class="font-semibold text-blue-600 hover:underline text-[11px]">
                                    + Buka Follow-Up
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif(isset($searchQuery) && !empty($searchQuery))
            <p class="mt-3 text-xs text-slate-500 italic">Tidak ditemukan institusi atau kontak yang cocok dengan kata kunci "{{ $searchQuery }}".</p>
        @endif
    </div>

    <!-- Section 1: 5 Status Alur Kerja Follow-Up Terpadu -->
    <div>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-600">
                Alur Follow-Up Institusi &amp; Kemitraan (Tahun {{ date('Y') }})
            </h2>
            <a href="{{ route('hr.follow-up.index') }}" class="text-xs font-semibold text-blue-600 hover:underline">
                Buka Seluruh Siklus &rarr;
            </a>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <!-- 1. Belum Dihubungi -->
            <a
                href="{{ route('hr.follow-up.index', ['status' => 'not_contacted']) }}"
                class="rounded-xl border border-blue-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:shadow"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">1. Belum Dihubungi</span>
                    <span class="rounded-lg bg-blue-50 p-1.5 text-blue-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-slate-800">{{ number_format($followUpMetrics['not_contacted']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Siklus siap dimulai</p>
                </div>
            </a>

            <!-- 2. Menunggu Respons -->
            <a
                href="{{ route('hr.follow-up.index', ['status' => 'waiting_response']) }}"
                class="rounded-xl border border-indigo-200 bg-white p-4 shadow-sm transition hover:border-indigo-300 hover:shadow"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">2. Menunggu Respons</span>
                    <span class="rounded-lg bg-indigo-50 p-1.5 text-indigo-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-indigo-600">{{ number_format($followUpMetrics['waiting_response']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Menunggu respon PIC</p>
                </div>
            </a>

            <!-- 3. Perlu Tindak Lanjut (Overdue Highlighted) -->
            <a
                href="{{ route('hr.follow-up.index', ['status' => 'needs_follow_up']) }}"
                class="rounded-xl border border-rose-200 bg-rose-50/30 p-4 shadow-sm transition hover:border-rose-300 hover:shadow"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-rose-700 font-bold">3. Perlu Tindak Lanjut</span>
                    <span class="rounded-lg bg-rose-100 p-1.5 text-rose-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-rose-700">{{ number_format($followUpMetrics['needs_follow_up']) }}</span>
                        @if($followUpMetrics['overdue'] > 0)
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-700">
                                {{ $followUpMetrics['overdue'] }} Overdue
                            </span>
                        @endif
                    </div>
                    <p class="text-[11px] text-rose-600/80 mt-0.5">Jatuh tempo / butuh aksi</p>
                </div>
            </a>

            <!-- 4. Bersedia Bekerja Sama (Siap MOU) -->
            <a
                href="{{ route('hr.follow-up.index', ['status' => 'willing']) }}"
                class="rounded-xl border border-purple-200 bg-purple-50/30 p-4 shadow-sm transition hover:border-purple-300 hover:shadow"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-purple-800 font-bold">4. Bersedia Bekerja Sama</span>
                    <span class="rounded-lg bg-purple-100 p-1.5 text-purple-700">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-purple-700">{{ number_format($followUpMetrics['willing']) }}</div>
                    <p class="text-[11px] text-purple-600 mt-0.5">Siap dibuatkan MOU</p>
                </div>
            </a>

            <!-- 5. Selesai / Arsip -->
            <a
                href="{{ route('hr.follow-up.index', ['status' => 'completed']) }}"
                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:shadow"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">5. Selesai</span>
                    <span class="rounded-lg bg-emerald-50 p-1.5 text-emerald-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-emerald-600">{{ number_format($followUpMetrics['completed']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Siklus rampung</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Section 2: Antrean Tugas Mendesak & Tindakan Nyata -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Kolom Kiri: Follow-Up Mendesak & Jatuh Tempo (Overdue) -->
        <div class="space-y-6">
            <div class="rounded-xl border border-rose-200 bg-white shadow-sm overflow-hidden">
                <div class="p-5 border-b border-rose-100 bg-rose-50/40 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="flex h-2.5 w-2.5 relative">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-600"></span>
                        </span>
                        <h3 class="text-sm font-bold text-rose-900">Perlu Ditindaklanjuti &amp; Lewat Jadwal</h3>
                    </div>
                    <a href="{{ route('hr.follow-up.index', ['status' => 'overdue']) }}" class="text-xs font-bold text-rose-600 hover:underline">
                        Lihat Semua Overdue &rarr;
                    </a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($urgentFollowUps as $urgent)
                        @php
                            $workflow = $urgent->workflow_info;
                        @endphp
                        <div class="p-4 hover:bg-slate-50 transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('hr.follow-up.show', $urgent) }}" class="font-bold text-xs text-slate-900 hover:text-blue-600 truncate">
                                        {{ $urgent->institution?->name ?? 'Institusi' }}
                                    </a>
                                    @if($workflow['is_overdue'])
                                        <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.2 text-[10px] font-bold text-rose-700">
                                            {{ $workflow['due_label'] }}
                                        </span>
                                    @elseif($workflow['is_due_today'])
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.2 text-[10px] font-bold text-amber-800">
                                            Hari Ini
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-[11px] text-slate-600">
                                    <span class="font-medium text-slate-800">{{ $urgent->contact?->name ?? 'PIC Pembimbing' }}</span>
                                    @if($urgent->contact?->phone)
                                        <span>&bull;</span>
                                        <a href="https://wa.me/{{ preg_replace('/[^\d]/', '', $urgent->contact->phone) }}" target="_blank" class="text-emerald-600 hover:underline inline-flex items-center gap-0.5 font-medium">
                                            WA: {{ $urgent->contact->phone }}
                                        </a>
                                    @endif
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-[10px] text-slate-400">
                                    <span>Percobaan: {{ $urgent->current_attempt_count }}/3</span>
                                    <span>&bull;</span>
                                    <span>{{ $urgent->last_activity_at ? 'Terakhir: ' . $urgent->last_activity_at->diffForHumans() : 'Belum pernah dihubungi' }}</span>
                                </div>
                            </div>
                            <div class="shrink-0 flex items-center gap-2">
                                <a
                                    href="{{ route('hr.follow-up.show', $urgent) }}"
                                    class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 shadow-sm transition"
                                >
                                    Follow-Up
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            <svg class="h-8 w-8 mx-auto text-emerald-400 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                            Hebat! Tidak ada tugas follow-up yang terlambat atau melewati jadwal saat ini.
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Tugas yang Sedang Saya Kunci -->
            @if($myActiveTasks->isNotEmpty())
                <div class="rounded-xl border border-blue-200 bg-blue-50/30 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-blue-100 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                            <h3 class="text-xs font-bold text-blue-900">Tugas Yang Sedang Anda Kunci (Task Lock)</h3>
                        </div>
                        <span class="text-[11px] font-semibold text-blue-700">{{ $myActiveTasks->count() }} Tugas</span>
                    </div>
                    <div class="divide-y divide-blue-100/60">
                        @foreach($myActiveTasks as $task)
                            <div class="p-3.5 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('hr.follow-up.show', $task) }}" class="font-bold text-xs text-slate-800 hover:text-blue-600 block truncate">
                                        {{ $task->institution?->name ?? 'Institusi' }}
                                    </a>
                                    <p class="text-[11px] text-slate-500">PIC: {{ $task->contact?->name ?? '-' }} (Sisa kunci: {{ $task->lockExpiresInMinutes() }} menit)</p>
                                </div>
                                <a
                                    href="{{ route('hr.follow-up.show', $task) }}"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-md bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-blue-700 transition"
                                >
                                    Lanjutkan
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Kolom Kanan: Mitra Bersedia Kerjasama (Siap MOU) & Dokumen MOU Butuh Aksi -->
        <div class="space-y-6">
            <!-- Mitra Bersedia Kerjasama (Siap Dibuatkan MOU) -->
            <div class="rounded-xl border border-purple-200 bg-white shadow-sm overflow-hidden">
                <div class="p-5 border-b border-purple-100 bg-purple-50/40 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-purple-600"></span>
                        <h3 class="text-sm font-bold text-purple-900">Mitra Bersedia Bekerja Sama (Siap MOU)</h3>
                    </div>
                    <span class="rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-bold text-purple-700">
                        {{ $readyMouPartners->count() }} Institusi
                    </span>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($readyMouPartners as $partner)
                        <div class="p-4 hover:bg-slate-50 transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <span class="font-bold text-xs text-slate-900 block truncate">
                                    {{ $partner->institution?->name ?? 'Institusi' }}
                                </span>
                                <div class="mt-1 flex items-center gap-1.5 text-[11px] text-slate-500">
                                    <span>PIC: {{ $partner->contact?->name ?? 'Pejabat Mitra' }}</span>
                                    <span>&bull;</span>
                                    <span>{{ $partner->contact?->phone ?? '-' }}</span>
                                </div>
                                <span class="inline-flex items-center gap-1 text-[10px] text-emerald-600 mt-1">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    Follow-up Selesai: Kesediaan Terkonfirmasi
                                </span>
                            </div>
                            <a
                                href="{{ route('hr.mous.create', ['institution_id' => $partner->institution_id, 'cycle_id' => $partner->id]) }}"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-purple-700 shadow-sm transition"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                </svg>
                                Buat Draft MOU
                            </a>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            Belum ada mitra baru bersedia kerja sama yang belum diterbitkan dokumen MOU-nya.
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Dokumen MOU yang Butuh Tindak Lanjut (Generated / Delivered) -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                        <h3 class="text-sm font-bold text-slate-800">Dokumen MOU Menunggu Tindak Lanjut</h3>
                    </div>
                    <a href="{{ route('hr.mous.index') }}" class="text-xs font-semibold text-blue-600 hover:underline">
                        Daftar MOU &rarr;
                    </a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($pendingMous as $pMou)
                        <div class="p-4 hover:bg-slate-50 transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('hr.mous.show', $pMou) }}" class="font-bold text-xs text-blue-600 hover:underline block truncate">
                                    {{ $pMou->company_mou_number }}
                                </a>
                                <p class="text-[11px] text-slate-700 font-medium truncate mt-0.5">
                                    {{ $pMou->first_party_institution_name }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold border {{ $pMou->status_badge_classes }}">
                                    {{ $pMou->status_label }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            Tidak ada dokumen MOU yang sedang pending.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
