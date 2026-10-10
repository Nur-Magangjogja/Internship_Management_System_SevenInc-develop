@extends('layouts.dashboard', ['title' => 'Dashboard HR Staff'])

@section('content')
<div class="px-6 py-8 max-w-7xl mx-auto">
    <!-- Header & Quick Actions -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-2xl font-bold text-slate-800">Dashboard Operasional HR</h1>
                <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 border border-emerald-200">
                    Role: HR Staff
                </span>
            </div>
            <p class="text-sm text-slate-500 mt-1">Selamat datang, {{ $user->name ?? $user->email }}. Kelola antrean tindak lanjut kemitraan dan siklus dokumen MOU.</p>
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
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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

    <!-- Section 1: Metrik Tindak Lanjut (Follow-Up) -->
    <div class="mb-6">
        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">
            Antrean Tugas Follow-Up Institusi Mitra
        </h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">Tugas Terbuka (Open)</span>
                    <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-slate-800">{{ number_format($followUpMetrics['open']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Siklus aktif butuh tindakan</p>
                </div>
            </div>

            <div class="rounded-xl border border-blue-200 bg-blue-50/40 p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-blue-800">Sedang Saya Kerjakan</span>
                    <span class="rounded-lg bg-blue-100 p-2 text-blue-700">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-blue-700">{{ number_format($followUpMetrics['my_claimed']) }}</div>
                    <p class="text-[11px] text-blue-600 mt-0.5">Terkunci atas nama Anda</p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">Menunggu Respons</span>
                    <span class="rounded-lg bg-amber-50 p-2 text-amber-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-amber-600">{{ number_format($followUpMetrics['waiting']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Menunggu konfirmasi PIC</p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">Selesai Periode {{ date('Y') }}</span>
                    <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-emerald-600">{{ number_format($followUpMetrics['completed']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Hasil final tercatat</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Metrik Siklus Hidup Dokumen MOU -->
    <div class="mb-8">
        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">
            Siklus Dokumen Kerja Sama (MOU & PKS)
        </h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-purple-200 bg-purple-50/40 p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-purple-800">Siap Dibuatkan MOU</span>
                    <span class="rounded-lg bg-purple-100 p-2 text-purple-700">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-purple-700">{{ number_format($mouMetrics['ready_to_generate']) }}</div>
                    <p class="text-[11px] text-purple-600 mt-0.5">Mitra bersedia kerja sama</p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">Naskah Dibuat (Generated)</span>
                    <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-blue-600">{{ number_format($mouMetrics['generated']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Menunggu serah terima mitra</p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500">Menunggu Naskah TTD</span>
                    <span class="rounded-lg bg-amber-50 p-2 text-amber-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <line x1="12" y1="18" x2="12" y2="12"/>
                            <line x1="9" y1="15" x2="15" y2="15"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-bold text-amber-600">{{ number_format($mouMetrics['delivered']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Terkirim, belum diunggah sah</p>
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
                    <div class="text-2xl font-bold text-emerald-600">{{ number_format($mouMetrics['active']) }}</div>
                    <p class="text-[11px] text-slate-400 mt-0.5">Kerja sama sah & aktif</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Operational Grid: 2 Columns -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- Left Column: Tugas Saya & Siap MOU -->
        <div class="space-y-6">
            <!-- Tugas Yang Sedang Saya Kunci -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                        <h3 class="text-sm font-bold text-slate-800">Tugas Yang Sedang Saya Kunci</h3>
                    </div>
                    <a href="{{ route('hr.follow-up.index') }}" class="text-xs font-semibold text-blue-600 hover:underline">
                        Lihat Antrean &rarr;
                    </a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($myActiveTasks as $task)
                        <div class="p-4 hover:bg-slate-50/50 transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('hr.follow-up.show', $task) }}" class="font-bold text-xs text-slate-900 hover:text-blue-600 block truncate">
                                    {{ $task->institution?->name ?? 'Institusi' }}
                                </a>
                                <p class="text-[11px] text-slate-500 mt-0.5">
                                    PIC: {{ $task->contact?->name ?? '-' }} ({{ $task->contact?->position_title ?? 'Kontak' }})
                                </p>
                                <span class="inline-flex items-center gap-1 text-[10px] text-amber-600 mt-1">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                    Kunci berakhir dalam {{ $task->lockExpiresInMinutes() }} menit
                                </span>
                            </div>
                            <a
                                href="{{ route('hr.follow-up.show', $task) }}"
                                class="inline-flex shrink-0 items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 transition"
                            >
                                Lanjutkan
                            </a>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            Tidak ada tugas yang sedang Anda kunci saat ini.
                            <div class="mt-2">
                                <a href="{{ route('hr.follow-up.index') }}" class="text-blue-600 hover:underline font-semibold">
                                    Ambil tugas dari antrean &rarr;
                                </a>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Institusi Bersedia Kerja Sama (Siap Buat MOU) -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-purple-600"></span>
                        <h3 class="text-sm font-bold text-slate-800">Institusi Bersedia Bekerja Sama (Siap MOU)</h3>
                    </div>
                    <a href="{{ route('hr.mous.create') }}" class="text-xs font-semibold text-blue-600 hover:underline">
                        Buat Baru &rarr;
                    </a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($readyMouPartners as $partner)
                        <div class="p-4 hover:bg-slate-50/50 transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <span class="font-bold text-xs text-slate-900 block truncate">
                                    {{ $partner->institution?->name ?? 'Institusi' }}
                                </span>
                                <p class="text-[11px] text-slate-500 mt-0.5">
                                    Kontak: {{ $partner->contact?->name ?? '-' }} ({{ $partner->contact?->phone ?? '-' }})
                                </p>
                                <span class="inline-flex items-center gap-1 text-[10px] text-emerald-600 mt-1">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    Follow-up Selesai: Bersedia Kerja Sama
                                </span>
                            </div>
                            <a
                                href="{{ route('hr.mous.create', ['institution_id' => $partner->institution_id, 'cycle_id' => $partner->id]) }}"
                                class="inline-flex shrink-0 items-center gap-1 rounded-md bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-purple-700 transition"
                            >
                                Generate MOU
                            </a>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            Belum ada institusi baru dengan hasil follow-up bersedia yang belum dibuatkan MOU.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Right Column: Antrean Follow-Up & Dokumen MOU Terbaru -->
        <div class="space-y-6">
            <!-- Antrean Follow-Up Terbaru -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-800">Antrean Follow-Up Institusi</h3>
                    <a href="{{ route('hr.follow-up.index') }}" class="text-xs font-semibold text-blue-600 hover:underline">
                        Semua Tugas &rarr;
                    </a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($pendingFollowUps as $cycle)
                        <div class="p-4 hover:bg-slate-50/50 transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('hr.follow-up.show', $cycle) }}" class="font-bold text-xs text-slate-900 hover:text-blue-600 block truncate">
                                    {{ $cycle->institution?->name ?? 'Institusi' }}
                                </a>
                                <p class="text-[11px] text-slate-500 mt-0.5">
                                    {{ $cycle->contact?->name ?? 'Pembimbing' }} | Percobaan: {{ $cycle->current_attempt_count }}/3
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                @if($cycle->isLocked())
                                    <span class="inline-flex items-center gap-1 rounded bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 border border-amber-200">
                                        {{ $cycle->locked_by === $user->id ? 'Saya' : $cycle->lockedByUser?->name }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                        Tersedia
                                    </span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            Tidak ada siklus follow-up aktif saat ini.
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Dokumen MOU Terbaru -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-800">Dokumen Kerja Sama (MOU) Terbaru</h3>
                    <a href="{{ route('hr.mous.index') }}" class="text-xs font-semibold text-blue-600 hover:underline">
                        Semua Dokumen &rarr;
                    </a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse($recentMous as $mou)
                        <div class="p-4 hover:bg-slate-50/50 transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('hr.mous.show', $mou) }}" class="font-bold text-xs text-blue-600 hover:underline block truncate">
                                    {{ $mou->company_mou_number }}
                                </a>
                                <p class="text-[11px] text-slate-700 font-medium truncate mt-0.5">
                                    {{ $mou->first_party_institution_name }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold border {{ $mou->status_badge_classes }}">
                                    {{ $mou->status_label }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            Belum ada dokumen MOU yang dibuat.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
