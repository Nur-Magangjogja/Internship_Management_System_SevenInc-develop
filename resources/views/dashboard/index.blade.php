@extends('layouts.dashboard')

@php
    $statusMeta = [
        'waiting' => [
            'label' => 'Menunggu Review',
            'badge' => 'bg-amber-50 text-amber-700',
            'bar' => 'bg-amber-400',
        ],
        'pending' => [
            'label' => 'Pending',
            'badge' => 'bg-amber-50 text-amber-700',
            'bar' => 'bg-amber-400',
        ],
        'accepted' => [
            'label' => 'Diterima',
            'badge' => 'bg-emerald-50 text-emerald-700',
            'bar' => 'bg-emerald-500',
        ],
        'active' => [
            'label' => 'Aktif',
            'badge' => 'bg-blue-50 text-blue-700',
            'bar' => 'bg-blue-500',
        ],
        'completed' => [
            'label' => 'Selesai',
            'badge' => 'bg-slate-100 text-slate-700',
            'bar' => 'bg-slate-500',
        ],
        'rejected' => [
            'label' => 'Ditolak',
            'badge' => 'bg-red-50 text-red-700',
            'bar' => 'bg-red-500',
        ],
        'exited' => [
            'label' => 'Keluar',
            'badge' => 'bg-red-50 text-red-700',
            'bar' => 'bg-red-500',
        ],
    ];

    $totalInterns = max((int) ($counts['total'] ?? 0), 1);
@endphp

@section('content')
<div class="min-h-screen bg-admin-bg p-4 sm:p-6 lg:p-7">

    <div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
            <p class="mb-1 text-xs font-bold uppercase tracking-[0.08em] text-admin-primary">
                Dashboard
            </p>
            <h1 class="text-2xl font-extrabold tracking-tight text-admin-text-dark sm:text-[28px]">
                Ringkasan & Statistik
            </h1>
            <p class="mt-1 text-sm text-admin-text-mid">
                Pantau pendaftaran dan aktivitas pemagang dalam satu tempat.
            </p>
        </div>

        <a
            href="{{ route('admin.interns.index') }}"
            class="inline-flex items-center justify-center gap-2 rounded-lg bg-admin-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-admin-primary-dark"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            Kelola Pemagang
        </a>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ route('admin.interns.index') }}" class="group rounded-xl border border-admin-border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-admin-secondary text-admin-primary-dark">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M19 8v6M22 11h-6"/>
                    </svg>
                </div>
                <span class="text-xs font-semibold text-admin-primary">Semua data</span>
            </div>
            <p class="text-3xl font-extrabold text-admin-text-dark">{{ $counts['total'] ?? 0 }}</p>
            <p class="mt-1 text-sm font-medium text-admin-text-mid">Total Pendaftar</p>
        </a>

        <a href="{{ route('admin.interns.pending') }}" class="group rounded-xl border border-admin-border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-50 text-amber-700">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="12" cy="12" r="8"/>
                        <path d="M12 7v5l3 2"/>
                    </svg>
                </div>
                <span class="rounded-full bg-amber-50 px-2 py-1 text-[11px] font-bold text-amber-700">Perlu review</span>
            </div>
            <p class="text-3xl font-extrabold text-admin-text-dark">{{ $counts['waiting'] ?? 0 }}</p>
            <p class="mt-1 text-sm font-medium text-admin-text-mid">Menunggu Review</p>
        </a>

        <a href="{{ route('admin.interns.active') }}" class="group rounded-xl border border-admin-border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-700">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M17 8h4M19 6v4"/>
                    </svg>
                </div>
                <span class="rounded-full bg-blue-50 px-2 py-1 text-[11px] font-bold text-blue-700">Berjalan</span>
            </div>
            <p class="text-3xl font-extrabold text-admin-text-dark">{{ $counts['active'] ?? 0 }}</p>
            <p class="mt-1 text-sm font-medium text-admin-text-mid">Pemagang Aktif</p>
        </a>

        <a href="{{ route('admin.interns.rejected') }}" class="group rounded-xl border border-admin-border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-50 text-admin-error">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="12" cy="12" r="8"/>
                        <path d="m9 9 6 6m0-6-6 6"/>
                    </svg>
                </div>
                <span class="rounded-full bg-red-50 px-2 py-1 text-[11px] font-bold text-admin-error">Tidak lolos</span>
            </div>
            <p class="text-3xl font-extrabold text-admin-text-dark">{{ $counts['rejected'] ?? 0 }}</p>
            <p class="mt-1 text-sm font-medium text-admin-text-mid">Pendaftar Ditolak</p>
        </a>
    </div>

    <div class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(320px,0.85fr)]">
        <section class="rounded-xl border border-admin-border bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-5">
                <h2 class="text-base font-bold text-admin-text-dark">Tren Pendaftar</h2>
                <p class="mt-1 text-sm text-admin-text-mid">Jumlah pendaftaran dalam enam bulan terakhir.</p>
            </div>

            <div class="h-72">
                <canvas id="admin-applicants-chart"></canvas>
            </div>
        </section>

        <section class="rounded-xl border border-admin-border bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-5">
                <h2 class="text-base font-bold text-admin-text-dark">Distribusi Status</h2>
                <p class="mt-1 text-sm text-admin-text-mid">Kondisi pendaftar saat ini.</p>
            </div>

            <div class="space-y-4">
                @foreach (['waiting', 'accepted', 'active', 'completed', 'rejected', 'exited'] as $status)
                    @php
                        $value = (int) ($counts[$status] ?? 0);
                        $percent = round(($value / $totalInterns) * 100);
                        $meta = $statusMeta[$status];
                    @endphp

                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-xs">
                            <span class="font-semibold text-admin-text-dark">{{ $meta['label'] }}</span>
                            <span class="text-admin-text-mid">{{ $value }} · {{ $percent }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full {{ $meta['bar'] }}" style="width: {{ $percent }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    {{-- ==========================================
         GRAFIK KEDUA: STATISTIK PEMAGANG DITERIMA
         (Stacked Bar Chart: Pendaftaran SERTIF vs Data Terintegrasi)
         ========================================== --}}
    <section class="mb-6 rounded-xl border border-admin-border bg-white p-5 shadow-sm sm:p-6" id="section-accepted-interns-chart">
        <div class="flex flex-col gap-4 border-b border-admin-border pb-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                    <h2 class="text-base font-bold text-admin-text-dark">Statistik Pemagang Diterima</h2>
                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Diverifikasi</span>
                </div>
                <p class="mt-1 text-sm text-admin-text-mid">
                    Perbandingan pemagang resmi (diterima, aktif, dan alumni) dari <strong>Pendaftaran Langsung SERTIF</strong> dan <strong>Data Terintegrasi</strong>.
                </p>
            </div>

            {{-- Filter Toolbar --}}
            <div class="flex flex-wrap items-center gap-2.5">
                {{-- Granularity Dropdown --}}
                <div class="flex items-center gap-1.5">
                    <label for="filter-period" class="text-xs font-semibold text-admin-text-mid">Periode:</label>
                    <select id="filter-period" class="rounded-lg border border-admin-border bg-white px-3 py-1.5 text-xs font-medium text-admin-text-dark focus:border-admin-primary focus:outline-none focus:ring-1 focus:ring-admin-primary">
                        <option value="monthly" selected>Bulanan</option>
                        <option value="daily">Harian</option>
                        <option value="yearly">Tahunan</option>
                        <option value="custom">Rentang Khusus</option>
                    </select>
                </div>

                {{-- Dynamic Inputs: Daily --}}
                <div id="filter-group-daily" class="hidden flex-wrap items-center gap-1.5">
                    <input type="date" id="filter-daily-date" value="{{ now()->toDateString() }}" class="rounded-lg border border-admin-border bg-white px-2.5 py-1.5 text-xs text-admin-text-dark focus:border-admin-primary focus:outline-none focus:ring-1 focus:ring-admin-primary">
                    <div class="flex items-center gap-1 text-xs text-admin-text-mid">
                        <span>Jam:</span>
                        <select id="filter-daily-start-hour" class="rounded-lg border border-admin-border bg-white px-2 py-1.5 text-xs text-admin-text-dark">
                            @for ($h = 0; $h <= 23; $h++)
                                <option value="{{ $h }}" {{ $h === 0 ? 'selected' : '' }}>{{ sprintf('%02d:00', $h) }}</option>
                            @endfor
                        </select>
                        <span>-</span>
                        <select id="filter-daily-end-hour" class="rounded-lg border border-admin-border bg-white px-2 py-1.5 text-xs text-admin-text-dark">
                            @for ($h = 0; $h <= 23; $h++)
                                <option value="{{ $h }}" {{ $h === 23 ? 'selected' : '' }}>{{ sprintf('%02d:59', $h) }}</option>
                            @endfor
                        </select>
                    </div>
                </div>

                {{-- Dynamic Inputs: Monthly --}}
                <div id="filter-group-monthly" class="flex flex-wrap items-center gap-1.5">
                    <select id="filter-monthly-month" class="rounded-lg border border-admin-border bg-white px-2.5 py-1.5 text-xs text-admin-text-dark">
                        @php
                            $monthNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
                            $currentMonth = (int) now()->format('n');
                            $currentYear = (int) now()->format('Y');
                        @endphp
                        @foreach ($monthNames as $mNum => $mName)
                            <option value="{{ $mNum }}" {{ $mNum === $currentMonth ? 'selected' : '' }}>{{ $mName }}</option>
                        @endforeach
                    </select>
                    <select id="filter-monthly-year" class="rounded-lg border border-admin-border bg-white px-2.5 py-1.5 text-xs text-admin-text-dark">
                        @for ($y = $currentYear + 1; $y >= 2020; $y--)
                            <option value="{{ $y }}" {{ $y === $currentYear ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </div>

                {{-- Dynamic Inputs: Yearly --}}
                <div id="filter-group-yearly" class="hidden flex-wrap items-center gap-1.5">
                    <select id="filter-yearly-year" class="rounded-lg border border-admin-border bg-white px-2.5 py-1.5 text-xs text-admin-text-dark">
                        @for ($y = $currentYear + 1; $y >= 2020; $y--)
                            <option value="{{ $y }}" {{ $y === $currentYear ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </div>

                {{-- Dynamic Inputs: Custom Range --}}
                <div id="filter-group-custom" class="hidden flex-wrap items-center gap-1.5">
                    <input type="datetime-local" id="filter-custom-start" value="{{ now()->subDays(30)->format('Y-m-d\TH:i') }}" class="rounded-lg border border-admin-border bg-white px-2 py-1 text-xs text-admin-text-dark">
                    <span class="text-xs text-admin-text-mid">s/d</span>
                    <input type="datetime-local" id="filter-custom-end" value="{{ now()->format('Y-m-d\TH:i') }}" class="rounded-lg border border-admin-border bg-white px-2 py-1 text-xs text-admin-text-dark">
                </div>

                {{-- Action Buttons --}}
                <div class="flex items-center gap-1.5">
                    <button type="button" id="btn-apply-accepted-filter" class="inline-flex items-center gap-1 rounded-lg bg-admin-primary px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-admin-primary-dark">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        <span>Terapkan</span>
                    </button>
                    <button type="button" id="btn-reset-accepted-filter" class="inline-flex items-center gap-1 rounded-lg border border-admin-border bg-white px-3 py-1.5 text-xs font-medium text-admin-text-mid transition hover:bg-slate-50 hover:text-admin-text-dark">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span>Reset</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- KPI Summary Row for Accepted Interns --}}
        <div class="my-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-admin-border/60 bg-slate-50/60 p-3">
                <p class="text-xs font-medium text-admin-text-mid">Total Diterima</p>
                <p id="kpi-accepted-total" class="mt-1 text-xl font-bold text-admin-text-dark">{{ number_format($acceptedChart['summary']['total_accepted'] ?? 0) }}</p>
                <p class="mt-0.5 text-[11px] text-admin-text-mid">Lolos seleksi & aktif</p>
            </div>
            <div class="rounded-lg border border-emerald-100 bg-emerald-50/40 p-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-emerald-800">Pendaftaran Langsung SERTIF</p>
                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-600"></span>
                </div>
                <p id="kpi-accepted-direct" class="mt-1 text-xl font-bold text-emerald-900">{{ number_format($acceptedChart['summary']['total_direct'] ?? 0) }}</p>
                <p class="mt-0.5 text-[11px] text-emerald-700/80">Web pendaftaran SERTIF</p>
            </div>
            <div class="rounded-lg border border-sky-100 bg-sky-50/40 p-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-sky-800">Data Terintegrasi</p>
                    <span class="inline-block h-2 w-2 rounded-full bg-sky-500"></span>
                </div>
                <p id="kpi-accepted-integrated" class="mt-1 text-xl font-bold text-sky-900">{{ number_format($acceptedChart['summary']['total_integrated'] ?? 0) }}</p>
                <p class="mt-0.5 text-[11px] text-sky-700/80">Sheets / Impor Historis</p>
            </div>
            <div class="rounded-lg border border-admin-border/60 bg-slate-50/60 p-3">
                <p class="text-xs font-medium text-admin-text-mid">Rasio Terintegrasi</p>
                @php
                    $tot = $acceptedChart['summary']['total_accepted'] ?? 0;
                    $int = $acceptedChart['summary']['total_integrated'] ?? 0;
                    $pct = $tot > 0 ? round(($int / $tot) * 100, 1) : 0;
                @endphp
                <p id="kpi-accepted-ratio" class="mt-1 text-xl font-bold text-admin-text-dark">{{ $pct }}%</p>
                <p class="mt-0.5 text-[11px] text-admin-text-mid">Pangsa data terintegrasi</p>
            </div>
        </div>

        {{-- Chart Canvas Container with Overlay Loader & Empty State --}}
        <div class="relative h-80 w-full">
            {{-- Spinner Loader --}}
            <div id="accepted-chart-loader" class="absolute inset-0 z-10 hidden flex-col items-center justify-center rounded-lg bg-white/75 backdrop-blur-[1px]">
                <div class="h-8 w-8 animate-spin rounded-full border-2 border-admin-primary border-t-transparent"></div>
                <p class="mt-2 text-xs font-medium text-admin-text-mid">Memuat statistik pemagang...</p>
            </div>

            {{-- Empty State Note --}}
            <div id="accepted-chart-empty" class="{{ empty($acceptedChart['summary']['has_data']) ? 'flex' : 'hidden' }} absolute inset-0 z-0 flex-col items-center justify-center rounded-lg bg-slate-50/70 p-6 text-center">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
                <p class="mt-2 text-sm font-semibold text-admin-text-dark">Tidak ada data pemagang diterima pada periode ini</p>
                <p class="mt-1 max-w-sm text-xs text-admin-text-mid">Tidak ditemukan pendaftar berstatus lolos/aktif/alumni pada rentang waktu yang dipilih. Coba pilih periode lain atau rentang khusus.</p>
            </div>

            {{-- The Canvas --}}
            <canvas id="admin-accepted-interns-chart"></canvas>
        </div>

        {{-- Footer Note & Provenance Guide --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-admin-border/60 pt-3 text-xs text-admin-text-mid">
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-[#2D8659]"></span>
                    <span>Pendaftaran Langsung SERTIF</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-[#0284C7]"></span>
                    <span>Data Terintegrasi (Sheets / Impor)</span>
                </span>
            </div>
            <div class="text-[11px] text-admin-text-mid">
                Atribusi tanggal: <em>submission_timestamp</em> asli &bull; Status dihitung: <em>accepted</em>, <em>active</em>, <em>completed</em>
            </div>
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-admin-border bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-admin-border px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                <h2 class="text-base font-bold text-admin-text-dark">Pendaftaran Terbaru</h2>
                <p class="mt-1 text-sm text-admin-text-mid">Lima pengajuan magang yang paling baru masuk.</p>
            </div>

            <a href="{{ route('admin.interns.index') }}" class="text-sm font-semibold text-admin-primary hover:text-admin-primary-dark">
                Lihat semua →
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[760px] w-full">
                <thead class="bg-admin-text-dark text-left">
                    <tr>
                        <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Pendaftar</th>
                        <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Divisi</th>
                        <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Institusi</th>
                        <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Tanggal Daftar</th>
                        <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Status</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-admin-border">
                    @forelse ($recentInterns ?? [] as $intern)
                        @php
                            $status = $intern->internship_status ?? 'waiting';
                            $meta = $statusMeta[$status] ?? $statusMeta['waiting'];
                            $initial = strtoupper(substr($intern->fullname ?? 'P', 0, 1));
                        @endphp

                        <tr class="transition hover:bg-admin-bg">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-admin-secondary text-xs font-bold text-admin-primary-dark">
                                        {{ $initial }}
                                    </span>
                                    <div>
                                        <p class="font-semibold text-admin-text-dark">{{ $intern->fullname }}</p>
                                        <p class="mt-0.5 text-xs text-admin-text-mid">{{ $intern->email }}</p>
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-4 text-sm text-admin-text-mid">
                                {{ $intern->internship_interest ?: '-' }}
                            </td>

                            <td class="px-5 py-4 text-sm text-admin-text-mid">
                                {{ $intern->institution_name ?: '-' }}
                            </td>

                            <td class="px-5 py-4 text-sm text-admin-text-mid">
                                {{ optional($intern->created_at)->format('d M Y') }}
                            </td>

                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $meta['badge'] }}">
                                    {{ $meta['label'] }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center">
                                <p class="font-semibold text-admin-text-dark">Belum ada pendaftaran.</p>
                                <p class="mt-1 text-sm text-admin-text-mid">Data pendaftar akan muncul di halaman ini.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- ===========================
         MONITORING KEMITRAAN & MOU (Phase 13)
         =========================== -->
    <div class="mt-8 space-y-6">
        <!-- Header Monitoring Kemitraan -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="mb-0.5 text-xs font-bold uppercase tracking-[0.08em] text-admin-primary">
                    Monitoring Operasional Kemitraan
                </p>
                <h2 class="text-xl font-bold tracking-tight text-admin-text-dark">
                    Tindak Lanjut Institusi & Dokumen MOU
                </h2>
                <p class="text-xs text-admin-text-mid">
                    Pantau kinerja tindak lanjut (follow-up) kemitraan dan masa berlaku dokumen kerja sama institusi.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.follow-up.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-admin-border bg-white px-3 py-1.5 text-xs font-semibold text-admin-text-dark shadow-sm hover:bg-admin-bg transition">
                    Monitoring Follow-Up
                </a>
                <a href="{{ route('admin.mous.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-admin-border bg-white px-3 py-1.5 text-xs font-semibold text-admin-text-dark shadow-sm hover:bg-admin-bg transition">
                    Kelola Dokumen MOU
                </a>
            </div>
        </div>

        <!-- 4 Grid Stat Cards -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Card 1: Beban Tugas Follow-up -->
            <div class="rounded-xl border border-admin-border bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between text-xs font-semibold text-admin-text-mid">
                    <span>Antrean Follow-Up</span>
                    <span class="rounded-md bg-blue-50 p-1.5 text-blue-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-admin-text-dark">
                        {{ number_format($partnershipMetrics['followup_open']) }}
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-[11px] text-admin-text-mid">
                        <span class="text-blue-600 font-semibold">{{ $partnershipMetrics['followup_locked'] }} terkunci</span>
                        <span>·</span>
                        <span>{{ $partnershipMetrics['followup_waiting'] }} tunggu respon</span>
                    </div>
                </div>
            </div>

            <!-- Card 2: Hasil Follow-Up -->
            <div class="rounded-xl border border-admin-border bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between text-xs font-semibold text-admin-text-mid">
                    <span>Hasil Final Follow-Up</span>
                    <span class="rounded-md bg-emerald-50 p-1.5 text-emerald-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-emerald-600">
                        {{ number_format($partnershipMetrics['outcome_willing']) }}
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-[11px] text-admin-text-mid">
                        <span class="text-emerald-700 font-medium">Bersedia Kerjasama</span>
                        <span>·</span>
                        <span>{{ $partnershipMetrics['outcome_declined'] }} menolak</span>
                    </div>
                </div>
            </div>

            <!-- Card 3: Dokumen MOU Aktif -->
            <div class="rounded-xl border border-admin-border bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between text-xs font-semibold text-admin-text-mid">
                    <span>MOU Sah & Aktif</span>
                    <span class="rounded-md bg-purple-50 p-1.5 text-purple-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-purple-700">
                        {{ number_format($partnershipMetrics['mou_active']) }}
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-[11px] text-admin-text-mid">
                        <span>Total: {{ $partnershipMetrics['mou_total'] }} naskah</span>
                        <span>·</span>
                        <span class="text-blue-600">{{ $partnershipMetrics['mou_delivered'] }} tunggu berkas</span>
                    </div>
                </div>
            </div>

            <!-- Card 4: Peringatan Kedaluwarsa MOU -->
            <div class="rounded-xl border border-admin-border bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between text-xs font-semibold text-admin-text-mid">
                    <span>MOU Perlu Perpanjangan</span>
                    <span class="rounded-md bg-amber-50 p-1.5 text-amber-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-amber-600">
                        {{ number_format($partnershipMetrics['mou_expiring_soon']) }}
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-[11px] text-admin-text-mid">
                        <span>Berakhir &lt; 60 hari</span>
                        <span>·</span>
                        <span class="text-rose-600">{{ $partnershipMetrics['mou_expired'] }} expired</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2 Columns: Expiring MOUs & Recent Activity Logs -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left: MOU Mendekati Masa Kedaluwarsa -->
            <div class="rounded-xl border border-admin-border bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-admin-border px-5 py-4">
                    <div>
                        <h3 class="text-sm font-bold text-admin-text-dark">MOU Mendekati Masa Kedaluwarsa</h3>
                        <p class="text-[11px] text-admin-text-mid">Perjanjian yang berakhir dalam 60 hari ke depan.</p>
                    </div>
                    <a href="{{ route('admin.mous.index') }}" class="text-xs font-semibold text-admin-primary hover:text-admin-primary-dark">
                        Semua MOU &rarr;
                    </a>
                </div>

                <div class="divide-y divide-admin-border">
                    @forelse($expiringMous as $expMou)
                        <div class="p-4 hover:bg-admin-bg transition flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.mous.show', $expMou) }}" class="font-bold text-xs text-admin-primary hover:underline block truncate">
                                    {{ $expMou->company_mou_number }}
                                </a>
                                <p class="text-[11px] text-admin-text-dark font-medium truncate mt-0.5">
                                    {{ $expMou->first_party_institution_name }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="inline-flex items-center rounded bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 border border-amber-200">
                                    s/d {{ \Carbon\Carbon::parse($expMou->effective_end)->isoFormat('D MMM Y') }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-admin-text-mid text-xs">
                            Tidak ada dokumen MOU aktif yang akan kedaluwarsa dalam 60 hari ke depan.
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Right: Log Aktivitas Kemitraan Terbaru -->
            <div class="rounded-xl border border-admin-border bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-admin-border px-5 py-4">
                    <div>
                        <h3 class="text-sm font-bold text-admin-text-dark">Log Aktivitas Kemitraan Terbaru</h3>
                        <p class="text-[11px] text-admin-text-mid">Rekam audit mutasi data follow-up dan MOU.</p>
                    </div>
                    <a href="{{ route('admin.activity-logs.index') }}" class="text-xs font-semibold text-admin-primary hover:text-admin-primary-dark">
                        Audit Log &rarr;
                    </a>
                </div>

                <div class="divide-y divide-admin-border">
                    @forelse($recentPartnershipLogs as $log)
                        <div class="p-4 hover:bg-admin-bg transition flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-admin-text-dark line-clamp-1">
                                    {{ $log->description ?? $log->action }}
                                </p>
                                <p class="text-[10px] text-admin-text-mid mt-0.5">
                                    Oleh: <span class="font-medium text-admin-text-dark">{{ $log->actor_name_snapshot }}</span> ({{ $log->actor_role_snapshot }})
                                </p>
                            </div>
                            <span class="text-[10px] text-admin-text-mid shrink-0">
                                {{ $log->created_at->diffForHumans() }}
                            </span>
                        </div>
                    @empty
                        <div class="p-8 text-center text-admin-text-mid text-xs">
                            Belum ada riwayat aktivitas tercatat.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
(() => {
    const canvas = document.getElementById('admin-applicants-chart');

    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: @json($chart['labels'] ?? []),
            datasets: [{
                label: 'Total Pendaftar',
                data: @json($chart['total'] ?? []),
                borderColor: '#2D8659',
                backgroundColor: 'rgba(45, 134, 89, 0.12)',
                fill: true,
                tension: 0.35,
                borderWidth: 2.5,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: '#2D8659',
                pointBorderWidth: 2,
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false,
                },
                tooltip: {
                    backgroundColor: '#1B3A34',
                    padding: 10,
                    displayColors: false,
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                    },
                    ticks: {
                        color: '#4B5F5A',
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        color: '#4B5F5A',
                    },
                    grid: {
                        color: 'rgba(75, 95, 90, 0.12)',
                    }
                }
            }
        }
    });
})();

// ==========================================
// GRAFIK KEDUA: STATISTIK PEMAGANG DITERIMA
// (Chart.js Stacked Bar Chart & AJAX Filter)
// ==========================================
(() => {
    const canvas = document.getElementById('admin-accepted-interns-chart');
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    const loaderEl = document.getElementById('accepted-chart-loader');
    const emptyEl = document.getElementById('accepted-chart-empty');
    const periodSelect = document.getElementById('filter-period');
    const applyBtn = document.getElementById('btn-apply-accepted-filter');
    const resetBtn = document.getElementById('btn-reset-accepted-filter');

    const groupDaily = document.getElementById('filter-group-daily');
    const groupMonthly = document.getElementById('filter-group-monthly');
    const groupYearly = document.getElementById('filter-group-yearly');
    const groupCustom = document.getElementById('filter-group-custom');

    const kpiTotal = document.getElementById('kpi-accepted-total');
    const kpiDirect = document.getElementById('kpi-accepted-direct');
    const kpiIntegrated = document.getElementById('kpi-accepted-integrated');
    const kpiRatio = document.getElementById('kpi-accepted-ratio');

    const initialData = @json($acceptedChart ?? []);

    const chartB = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: initialData.labels || [],
            datasets: (initialData.datasets || []).map(ds => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: ds.backgroundColor,
                borderColor: ds.borderColor,
                borderWidth: ds.borderWidth || 1,
                borderRadius: 4,
                stack: 'accepted',
            }))
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: false,
                },
                tooltip: {
                    backgroundColor: '#1B3A34',
                    padding: 10,
                    callbacks: {
                        footer: function(tooltipItems) {
                            let sum = 0;
                            tooltipItems.forEach(function(tooltipItem) {
                                sum += tooltipItem.parsed.y || 0;
                            });
                            return 'Total Diterima: ' + sum + ' orang';
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { color: '#4B5F5A' }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        color: '#4B5F5A'
                    },
                    grid: {
                        color: 'rgba(75, 95, 90, 0.12)'
                    }
                }
            }
        }
    });

    function togglePeriodGroups(period) {
        if (groupDaily) groupDaily.classList.toggle('hidden', period !== 'daily');
        if (groupMonthly) groupMonthly.classList.toggle('hidden', period !== 'monthly');
        if (groupYearly) groupYearly.classList.toggle('hidden', period !== 'yearly');
        if (groupCustom) groupCustom.classList.toggle('hidden', period !== 'custom');
    }

    if (periodSelect) {
        periodSelect.addEventListener('change', (e) => {
            togglePeriodGroups(e.target.value);
        });
    }

    function buildQueryParams() {
        const period = periodSelect ? periodSelect.value : 'monthly';
        const params = new URLSearchParams();
        params.append('period', period);

        if (period === 'daily') {
            const dateVal = document.getElementById('filter-daily-date')?.value;
            const startH = document.getElementById('filter-daily-start-hour')?.value;
            const endH = document.getElementById('filter-daily-end-hour')?.value;
            if (dateVal) params.append('date', dateVal);
            if (startH !== undefined) params.append('start_hour', startH);
            if (endH !== undefined) params.append('end_hour', endH);
        } else if (period === 'monthly') {
            const m = document.getElementById('filter-monthly-month')?.value;
            const y = document.getElementById('filter-monthly-year')?.value;
            if (m) params.append('month', m);
            if (y) params.append('year', y);
        } else if (period === 'yearly') {
            const y = document.getElementById('filter-yearly-year')?.value;
            if (y) params.append('year', y);
        } else if (period === 'custom') {
            const startDt = document.getElementById('filter-custom-start')?.value;
            const endDt = document.getElementById('filter-custom-end')?.value;
            if (startDt) params.append('start_date', startDt);
            if (endDt) params.append('end_date', endDt);
        }

        return params.toString();
    }

    async function loadChartData() {
        if (!loaderEl) return;
        loaderEl.classList.remove('hidden');
        loaderEl.classList.add('flex');

        try {
            const query = buildQueryParams();
            const response = await fetch(`{{ route('admin.dashboard.accepted-interns-chart') }}?${query}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const json = await response.json();
            if (!json.success || !json.data) {
                throw new Error(json.message || 'Gagal memuat data analitik.');
            }

            const resData = json.data;

            // Update Chart B datasets
            chartB.data.labels = resData.labels || [];
            chartB.data.datasets = (resData.datasets || []).map(ds => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: ds.backgroundColor,
                borderColor: ds.borderColor,
                borderWidth: ds.borderWidth || 1,
                borderRadius: 4,
                stack: 'accepted',
            }));
            chartB.update();

            // Update KPIs
            const summary = resData.summary || {};
            const tot = summary.total_accepted || 0;
            const dir = summary.total_direct || 0;
            const intVal = summary.total_integrated || 0;
            const ratio = tot > 0 ? ((intVal / tot) * 100).toFixed(1) + '%' : '0%';

            if (kpiTotal) kpiTotal.textContent = new Intl.NumberFormat('id-ID').format(tot);
            if (kpiDirect) kpiDirect.textContent = new Intl.NumberFormat('id-ID').format(dir);
            if (kpiIntegrated) kpiIntegrated.textContent = new Intl.NumberFormat('id-ID').format(intVal);
            if (kpiRatio) kpiRatio.textContent = ratio;

            // Update Empty State
            if (emptyEl) {
                if (summary.has_data === false || tot === 0) {
                    emptyEl.classList.remove('hidden');
                    emptyEl.classList.add('flex');
                } else {
                    emptyEl.classList.remove('flex');
                    emptyEl.classList.add('hidden');
                }
            }
        } catch (err) {
            console.error('Error fetching accepted interns chart:', err);
            alert('Gagal memuat data grafik: ' + err.message);
        } finally {
            loaderEl.classList.remove('flex');
            loaderEl.classList.add('hidden');
        }
    }

    if (applyBtn) {
        applyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            loadChartData();
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (periodSelect) {
                periodSelect.value = 'monthly';
                togglePeriodGroups('monthly');
            }
            const now = new Date();
            const curMonth = document.getElementById('filter-monthly-month');
            const curYear = document.getElementById('filter-monthly-year');
            if (curMonth) curMonth.value = String(now.getMonth() + 1);
            if (curYear) curYear.value = String(now.getFullYear());
            loadChartData();
        });
    }
})();
</script>
@endpush