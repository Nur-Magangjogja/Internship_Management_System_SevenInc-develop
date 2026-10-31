@extends('layouts.dashboard', ['title' => 'Monitoring Sinkronisasi & Pemulihan'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-sm text-slate-500 mb-1">
                <a href="{{ route('admin.spreadsheet-connections.index') }}" class="hover:text-blue-600 transition">Integrasi Spreadsheet</a>
                <span>&rarr;</span>
                <span>Monitoring & Pemulihan Sinkronisasi</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Monitoring & Pemulihan Sinkronisasi</h1>
            <p class="text-xs text-slate-500 mt-1">
                Pemantauan operasional terpadu status antrean Google Spreadsheet utama, ekstensi sidecar, dan berkas lampiran Google Drive.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.spreadsheet-connections.index') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                    <path d="M3 9h18M3 15h18M9 3v18"/>
                </svg>
                Kelola Koneksi
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-xs font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-xs font-medium text-rose-800">
            {{ session('error') }}
        </div>
    @endif
    @if (session('info'))
        <div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 p-4 text-xs font-medium text-sky-800">
            {{ session('info') }}
        </div>
    @endif

    <!-- Aggregated Notice Box -->
    <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50/50 p-4 text-xs text-blue-900 shadow-sm">
        <div class="flex items-start gap-3">
            <svg class="h-4 w-4 shrink-0 text-blue-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <div>
                <span class="font-semibold block mb-0.5">Catatan Agregasi Operasional</span>
                <p class="leading-relaxed">{{ $stats['aggregated']['notice'] }}</p>
            </div>
        </div>
    </div>

    <!-- Summary Statistics Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- 1. Main Spreadsheet -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                    <h2 class="font-bold text-slate-800 text-sm">Spreadsheet Baris Utama</h2>
                </div>
                <span class="text-xs font-mono font-semibold text-slate-500">Total: {{ number_format($stats['main_row']['total']) }}</span>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-lg bg-slate-50 p-2 border border-slate-100">
                    <span class="text-[10px] text-slate-500 block uppercase font-medium">Pending</span>
                    <span class="font-bold text-blue-700 text-sm">{{ number_format($stats['main_row']['pending']) }}</span>
                </div>
                <div class="rounded-lg bg-amber-50/60 p-2 border border-amber-100">
                    <span class="text-[10px] text-amber-700 block uppercase font-medium">Retrying</span>
                    <span class="font-bold text-amber-700 text-sm">{{ number_format($stats['main_row']['retrying']) }}</span>
                </div>
                <div class="rounded-lg bg-rose-50/60 p-2 border border-rose-100">
                    <span class="text-[10px] text-rose-700 block uppercase font-medium">Failed</span>
                    <span class="font-bold text-rose-700 text-sm">{{ number_format($stats['main_row']['failed']) }}</span>
                </div>
            </div>
            <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                <span>Tersinkron: <strong class="text-emerald-700">{{ number_format($stats['main_row']['synced']) }}</strong></span>
                <span>Processing: <strong class="text-purple-700">{{ number_format($stats['main_row']['processing']) }}</strong></span>
            </div>
        </div>

        <!-- 2. Sidecar Custom Fields -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-purple-600"></span>
                    <h2 class="font-bold text-slate-800 text-sm">Ekstensi Sidecar</h2>
                </div>
                <span class="text-xs font-mono font-semibold text-slate-500">Total: {{ number_format($stats['sidecar']['total']) }}</span>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-lg bg-slate-50 p-2 border border-slate-100">
                    <span class="text-[10px] text-slate-500 block uppercase font-medium">Pending</span>
                    <span class="font-bold text-blue-700 text-sm">{{ number_format($stats['sidecar']['pending']) }}</span>
                </div>
                <div class="rounded-lg bg-amber-50/60 p-2 border border-amber-100">
                    <span class="text-[10px] text-amber-700 block uppercase font-medium">Retrying</span>
                    <span class="font-bold text-amber-700 text-sm">{{ number_format($stats['sidecar']['retrying']) }}</span>
                </div>
                <div class="rounded-lg bg-rose-50/60 p-2 border border-rose-100">
                    <span class="text-[10px] text-rose-700 block uppercase font-medium">Failed</span>
                    <span class="font-bold text-rose-700 text-sm">{{ number_format($stats['sidecar']['failed']) }}</span>
                </div>
            </div>
            <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                <span>Tersinkron: <strong class="text-emerald-700">{{ number_format($stats['sidecar']['synced']) }}</strong></span>
                <span>Processing: <strong class="text-purple-700">{{ number_format($stats['sidecar']['processing']) }}</strong></span>
            </div>
        </div>

        <!-- 3. Drive Attachments -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-emerald-600"></span>
                    <h2 class="font-bold text-slate-800 text-sm">Lampiran Google Drive</h2>
                </div>
                <span class="text-xs font-mono font-semibold text-slate-500">Total: {{ number_format($stats['attachment']['total']) }}</span>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-lg bg-slate-50 p-2 border border-slate-100">
                    <span class="text-[10px] text-slate-500 block uppercase font-medium">Pending</span>
                    <span class="font-bold text-blue-700 text-sm">{{ number_format($stats['attachment']['pending']) }}</span>
                </div>
                <div class="rounded-lg bg-amber-50/60 p-2 border border-amber-100">
                    <span class="text-[10px] text-amber-700 block uppercase font-medium">Retrying</span>
                    <span class="font-bold text-amber-700 text-sm">{{ number_format($stats['attachment']['retrying']) }}</span>
                </div>
                <div class="rounded-lg bg-rose-50/60 p-2 border border-rose-100">
                    <span class="text-[10px] text-rose-700 block uppercase font-medium">Failed</span>
                    <span class="font-bold text-rose-700 text-sm">{{ number_format($stats['attachment']['failed']) }}</span>
                </div>
            </div>
            <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                <span>Tersinkron: <strong class="text-emerald-700">{{ number_format($stats['attachment']['synced']) }}</strong></span>
                <span>Processing: <strong class="text-purple-700">{{ number_format($stats['attachment']['processing']) }}</strong></span>
            </div>
        </div>
    </div>

    <!-- Filter Form Bar -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <form action="{{ route('admin.sync-monitoring.index') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 text-xs">
            <div>
                <label for="filter-category" class="font-semibold text-slate-600 block mb-1">Kategori</label>
                <select id="filter-category" name="category" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-blue-500 focus:outline-none">
                    <option value="all" {{ ($filters['category'] ?? 'all') === 'all' ? 'selected' : '' }}>Semua Kategori</option>
                    <option value="main_row" {{ ($filters['category'] ?? '') === 'main_row' ? 'selected' : '' }}>Spreadsheet Utama</option>
                    <option value="sidecar" {{ ($filters['category'] ?? '') === 'sidecar' ? 'selected' : '' }}>Ekstensi Sidecar</option>
                    <option value="attachment" {{ ($filters['category'] ?? '') === 'attachment' ? 'selected' : '' }}>Lampiran Drive</option>
                </select>
            </div>

            <div>
                <label for="filter-status" class="font-semibold text-slate-600 block mb-1">Status</label>
                <select id="filter-status" name="status" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-blue-500 focus:outline-none">
                    <option value="all" {{ ($filters['status'] ?? 'all') === 'all' ? 'selected' : '' }}>Semua Status</option>
                    <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="processing" {{ ($filters['status'] ?? '') === 'processing' ? 'selected' : '' }}>Processing</option>
                    <option value="retrying" {{ ($filters['status'] ?? '') === 'retrying' ? 'selected' : '' }}>Retrying</option>
                    <option value="failed" {{ ($filters['status'] ?? '') === 'failed' ? 'selected' : '' }}>Failed</option>
                    <option value="synced" {{ ($filters['status'] ?? '') === 'synced' ? 'selected' : '' }}>Synced</option>
                </select>
            </div>

            <div>
                <label for="filter-connection" class="font-semibold text-slate-600 block mb-1">Koneksi</label>
                <select id="filter-connection" name="connection_id" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-blue-500 focus:outline-none">
                    <option value="all" {{ ($filters['connection_id'] ?? 'all') === 'all' ? 'selected' : '' }}>Semua Koneksi</option>
                    @foreach ($connections as $conn)
                        <option value="{{ $conn->id }}" {{ (string) ($filters['connection_id'] ?? '') === (string) $conn->id ? 'selected' : '' }}>
                            {{ $conn->display_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="filter-year" class="font-semibold text-slate-600 block mb-1">Tahun Tab</label>
                <select id="filter-year" name="year" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-blue-500 focus:outline-none">
                    <option value="all" {{ ($filters['year'] ?? 'all') === 'all' ? 'selected' : '' }}>Semua Tahun</option>
                    @foreach ($years as $yr)
                        <option value="{{ $yr }}" {{ (string) ($filters['year'] ?? '') === (string) $yr ? 'selected' : '' }}>
                            {{ $yr }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-2 flex items-end gap-2">
                <div class="flex-1">
                    <label for="filter-search" class="font-semibold text-slate-600 block mb-1">Cari Sync ID / Nama</label>
                    <input
                        id="filter-search"
                        type="text"
                        name="search"
                        value="{{ $filters['search'] ?? '' }}"
                        placeholder="ID Sync, Berkas, atau Reg ID..."
                        class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 placeholder-slate-400 focus:border-blue-500 focus:outline-none"
                    />
                </div>
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    Filter
                </button>
                <a
                    href="{{ route('admin.sync-monitoring.index') }}"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                    title="Reset Filter"
                >
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Batch Retry Bar (Hanya tampil jika ada filter kategori dan terdapat record yang gagal/retrying) -->
    @can('retry-sync-monitoring')
        @if (in_array($filters['category'] ?? '', ['main_row', 'sidecar', 'attachment'], true))
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50/40 p-4 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-amber-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 4 23 10 17 10"/>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                    <div>
                        <span class="font-semibold text-amber-900 text-xs">Batch Retry {{ $filters['category'] === 'main_row' ? 'Spreadsheet Utama' : ($filters['category'] === 'sidecar' ? 'Sidecar Extension' : 'Drive Attachments') }}</span>
                        <p class="text-[11px] text-amber-700">Jadwalkan ulang record yang gagal/retrying secara asinkron (maksimal 50 record per operasi).</p>
                    </div>
                </div>
                <form action="{{ route('admin.sync-monitoring.batch-retry') }}" method="POST" onsubmit="return confirm('Jalankan batch retry untuk kategori ini? Hanya record yang eligible yang akan diproses.')">
                    @csrf
                    <input type="hidden" name="category" value="{{ $filters['category'] }}">
                    @if (!empty($filters['connection_id']) && $filters['connection_id'] !== 'all')
                        <input type="hidden" name="connection_id" value="{{ $filters['connection_id'] }}">
                    @endif
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-700"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"/>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                        </svg>
                        Jalankan Batch Retry
                    </button>
                </form>
            </div>
        @endif
    @endcan

    <!-- Monitoring Table -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3 w-12 text-center">No.</th>
                        <th class="px-4 py-3">Sync ID / Identitas</th>
                        <th class="px-4 py-3">Kategori</th>
                        <th class="px-4 py-3">Target Koneksi & Tab</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-center">Percobaan</th>
                        <th class="px-4 py-3">Jadwal Retry / Terakhir</th>
                        <th class="px-4 py-3">Ringkasan Kesalahan</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($records as $item)
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="px-4 py-3 text-center text-slate-400 font-mono">
                                {{ $loop->iteration + ($records->currentPage() - 1) * $records->perPage() }}
                            </td>
                            <td class="px-4 py-3 font-mono font-semibold text-slate-800">
                                <div>{{ $item->sync_identifier }}</div>
                                @if (!empty($item->registration_id))
                                    <span class="text-[10px] font-sans font-normal text-emerald-700">Pendaftaran ({{ $item->brand_name ?? 'SEVEN INC' }})</span>
                                @elseif (str_starts_with($item->sync_identifier, 'FWU-'))
                                    <span class="text-[10px] font-sans font-normal text-purple-600">Follow-Up Guru/Dosen</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $catBadge = match ($item->category) {
                                        'main_row'   => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'sidecar'    => 'bg-purple-50 text-purple-700 border-purple-200',
                                        'attachment' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        default      => 'bg-slate-100 text-slate-700 border-slate-200',
                                    };
                                @endphp
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold border {{ $catBadge }}">
                                    {{ $item->category_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-700 truncate max-w-[180px]">{{ $item->connection_name }}</div>
                                @if ($item->target_tab)
                                    <span class="font-mono text-[10px] text-slate-500">[{{ $item->target_tab }}]</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $statusClass = match ($item->status) {
                                        'synced'     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'pending'    => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'processing' => 'bg-purple-50 text-purple-700 border-purple-200',
                                        'retrying'   => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'failed'     => 'bg-rose-50 text-rose-700 border-rose-200',
                                        default      => 'bg-slate-100 text-slate-700 border-slate-200',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold border {{ $statusClass }}">
                                    {{ ucfirst($item->status) }}
                                </span>
                                @if ($item->is_locked)
                                    <span class="block text-[9px] text-purple-600 font-mono mt-0.5">Lease Aktif</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center font-mono text-slate-600">
                                {{ $item->attempt_count }} / {{ $item->max_attempts }}
                            </td>
                            <td class="px-4 py-3 font-mono text-[11px] text-slate-600">
                                @if ($item->status === 'retrying' && $item->next_retry_at)
                                    <span class="text-amber-700 block">Retry: {{ $item->next_retry_at->format('d/m H:i') }}</span>
                                @elseif ($item->last_synced_at)
                                    <span class="text-emerald-700 block">Sync: {{ $item->last_synced_at->format('d/m H:i') }}</span>
                                @elseif ($item->last_attempt_at)
                                    <span class="text-slate-500 block">Coba: {{ $item->last_attempt_at->format('d/m H:i') }}</span>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 max-w-xs truncate" title="{{ $item->error_message }}">
                                {{ $item->error_message ? Str::limit($item->error_message, 45) : '-' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <a
                                        href="{{ route('admin.sync-monitoring.show', [$item->category, $item->uuid]) }}"
                                        class="rounded border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 transition"
                                    >
                                        Detail
                                    </a>

                                    @can('retry-sync-monitoring')
                                        @if ($item->is_eligible_for_retry)
                                            @php
                                                $retryRoute = match ($item->category) {
                                                    'main_row'   => route('admin.sync-monitoring.retry-main-row', $item->id),
                                                    'sidecar'    => route('admin.sync-monitoring.retry-sidecar', $item->id),
                                                    'attachment' => route('admin.sync-monitoring.retry-attachment', $item->id),
                                                    default      => null,
                                                };
                                            @endphp

                                            @if ($retryRoute)
                                                <form action="{{ $retryRoute }}" method="POST" class="inline" onsubmit="return confirm('Picu retry manual untuk record ini?')">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="rounded bg-amber-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-amber-700 transition"
                                                        title="Jadwalkan Retry Sekarang"
                                                    >
                                                        Retry
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-slate-400 italic">
                                Tidak ada data pemantauan sinkronisasi yang cocok dengan filter yang dipilih.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($records->hasPages())
            <div class="p-4 border-t border-slate-200 bg-slate-50">
                {{ $records->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
