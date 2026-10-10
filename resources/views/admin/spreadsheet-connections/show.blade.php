@extends('layouts.dashboard', ['title' => 'Detail & Pemetaan Kolom Spreadsheet'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 text-sm text-slate-500 mb-1">
                <a href="{{ route('admin.spreadsheet-connections.index') }}" class="hover:text-blue-600 transition">Integrasi Spreadsheet</a>
                <span>&rarr;</span>
                <span>Detail & Pemetaan Kolom</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">{{ $connection->display_name }}</h1>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.spreadsheet-connections.edit', $connection) }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                </svg>
                Edit Konfigurasi
            </a>
            <a
                href="{{ route('admin.spreadsheet-connections.index') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                Kembali
            </a>
        </div>
    </div>

    <!-- Connection Info Summary Card -->
    <div class="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 text-xs">
            <div>
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Domain Identity</span>
                <span class="inline-block mt-1 px-2.5 py-0.5 font-mono font-semibold rounded bg-slate-100 text-slate-700">
                    {{ $connection->connection_key }}
                </span>
            </div>
            <div>
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Status Integrasi</span>
                <div class="mt-1 flex items-center gap-2">
                    @if ($connection->is_enabled)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Aktif
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs font-medium rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                            Nonaktif
                        </span>
                    @endif
                    <span class="text-slate-500 font-mono">({{ $connection->connection_status }})</span>
                </div>
            </div>
            <div class="md:col-span-2">
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Target Google Spreadsheet</span>
                @if ($connection->spreadsheet_url)
                    <a href="{{ $connection->spreadsheet_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline font-mono truncate block mt-1">
                        {{ $connection->spreadsheet_url }}
                    </a>
                @else
                    <span class="text-slate-400 italic mt-1 block">Belum dikonfigurasi</span>
                @endif
            </div>
        </div>
    </div>

    <!-- Schema Versions & Column Mappings -->
    <div class="space-y-8">
        <div class="flex items-center justify-between border-b border-slate-200 pb-3">
            <div>
                <h2 class="text-lg font-bold text-slate-800">Versi Schema & Pemetaan Kolom Tab Tahunan</h2>
                <p class="text-xs text-slate-500">Struktur header dan kontrak kolom outbound per tab tahun pendaftaran.</p>
            </div>
        </div>

        @forelse ($schemaVersions as $schema)
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <!-- Schema Header Bar -->
                <div class="bg-slate-50/80 px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 text-blue-800 font-bold text-sm">
                            {{ $schema->year }}
                        </span>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-bold text-slate-800 text-sm">Tab: [{{ $schema->sheet_tab_name }}]</h3>
                                <span class="px-2 py-0.5 text-[11px] font-mono rounded bg-slate-200 text-slate-700">
                                    {{ $schema->schema_version }}
                                </span>
                                @if ($schema->is_active)
                                    <span class="px-2 py-0.5 text-[11px] font-semibold rounded bg-emerald-100 text-emerald-800">
                                        Aktif
                                    </span>
                                @endif
                            </div>
                            @if ($schema->notes)
                                <p class="text-xs text-slate-500 mt-0.5">{{ $schema->notes }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="text-xs text-slate-500">
                        Total Kolom: <span class="font-bold text-slate-800">{{ $schema->columnMappings->count() }}</span>
                    </div>
                </div>

                <!-- Column Mappings Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-100/50 text-slate-600 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 w-12 text-center">#</th>
                                <th class="px-4 py-3">Nama Header Spreadsheet (Teks Persis)</th>
                                <th class="px-4 py-3">Sumber Entitas / Field DB</th>
                                <th class="px-4 py-3">Tipe Data</th>
                                <th class="px-4 py-3">Arah</th>
                                <th class="px-4 py-3">Transformer</th>
                                <th class="px-4 py-3 text-center">Wajib</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700 font-sans">
                            @forelse ($schema->columnMappings as $mapping)
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="px-4 py-2.5 text-center font-mono font-medium text-slate-400">
                                        {{ $mapping->column_order }}
                                    </td>
                                    <td class="px-4 py-2.5 font-medium text-slate-900">
                                        <code class="bg-slate-100 text-slate-800 px-1.5 py-0.5 rounded font-mono text-[11px] border border-slate-200">
                                            {{ $mapping->sheet_header }}
                                        </code>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="text-slate-500">{{ $mapping->target_entity }}</span> &rarr;
                                        <span class="font-mono font-semibold text-slate-800">{{ $mapping->target_field ?? '-' }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono text-slate-600">
                                        {{ $mapping->data_type }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @php
                                            $dirBadge = match($mapping->direction) {
                                                'system' => 'bg-purple-50 text-purple-700 border-purple-200',
                                                'display_only' => 'bg-amber-50 text-amber-700 border-amber-200',
                                                default => 'bg-blue-50 text-blue-700 border-blue-200',
                                            };
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold border {{ $dirBadge }}">
                                            {{ $mapping->direction }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono text-slate-500">
                                        {{ $mapping->transform_key ?? '-' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        @if ($mapping->is_required)
                                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500" title="Wajib Diisi"></span>
                                        @else
                                            <span class="inline-block h-2 w-2 rounded-full bg-slate-300" title="Opsional"></span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-6 text-center text-slate-400 italic">
                                        Belum ada pemetaan kolom untuk versi schema ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-12 text-center">
                <svg class="mx-auto h-8 w-8 text-slate-400 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                    <path d="M3 9h18M3 15h18M9 3v18"/>
                </svg>
                <p class="font-medium text-slate-700 text-sm">Belum Ada Versi Schema</p>
                <p class="text-xs text-slate-500 mt-1">Jalankan seeder atau konfigurasi versi schema untuk tahun pendaftaran terkait.</p>
            </div>
        @endforelse
    </div>

    <!-- Sync Monitoring & Outbox Tracking (S9D) -->
    <div class="mt-12 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-200 pb-3">
            <div>
                <h2 class="text-lg font-bold text-slate-800">Pemantauan Antrean & Status Sinkronisasi Baris</h2>
                <p class="text-xs text-slate-500">Pelacakan status outbound outbox, identitas stabil SERTIF_SYNC_ID, dan riwayat retry.</p>
            </div>
            @if (($stats['failed'] ?? 0) > 0 || ($stats['retrying'] ?? 0) > 0)
                <form action="{{ route('admin.spreadsheet-connections.retry-failed', $connection) }}" method="POST">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-700"
                        onclick="return confirm('Jadwalkan ulang retry untuk semua baris yang gagal pada koneksi ini?')"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"/>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                        </svg>
                        Retry Semua yang Gagal ({{ ($stats['failed'] ?? 0) + ($stats['retrying'] ?? 0) }})
                    </button>
                </form>
            @endif
        </div>

        <!-- Sync Statistics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Total Baris</span>
                <p class="text-xl font-bold text-slate-800 mt-1">{{ number_format($stats['total'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Tersinkronisasi</span>
                <p class="text-xl font-bold text-emerald-700 mt-1">{{ number_format($stats['synced'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Antrean Pending</span>
                <p class="text-xl font-bold text-blue-700 mt-1">{{ number_format($stats['pending'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-amber-700">Dalam Retry</span>
                <p class="text-xl font-bold text-amber-700 mt-1">{{ number_format($stats['retrying'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-rose-700">Gagal Permanen</span>
                <p class="text-xl font-bold text-rose-700 mt-1">{{ number_format($stats['failed'] ?? 0) }}</p>
            </div>
        </div>

        <!-- Sync Rows Table -->
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3 w-12 text-center">No.</th>
                            <th class="px-4 py-3">Stable Sync ID</th>
                            <th class="px-4 py-3">Target Tab</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Percobaan</th>
                            <th class="px-4 py-3">Sync Terakhir</th>
                            <th class="px-4 py-3">Pesan / Kesalahan</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($rowSyncs as $sync)
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="px-4 py-3 text-center text-slate-400 font-mono">
                                    {{ $loop->iteration + ($rowSyncs->currentPage() - 1) * $rowSyncs->perPage() }}
                                </td>
                                <td class="px-4 py-3 font-mono font-semibold text-slate-800">
                                    {{ $sync->external_sync_id }}
                                </td>
                                <td class="px-4 py-3 font-mono text-slate-600">
                                    [{{ $sync->target_sheet_tab }}]
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $statusClass = match($sync->sync_status) {
                                            'synced'     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                            'pending'    => 'bg-blue-50 text-blue-700 border-blue-200',
                                            'processing' => 'bg-purple-50 text-purple-700 border-purple-200',
                                            'retrying'   => 'bg-amber-50 text-amber-700 border-amber-200',
                                            'failed'     => 'bg-rose-50 text-rose-700 border-rose-200',
                                            default      => 'bg-slate-100 text-slate-700 border-slate-200',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium border {{ $statusClass }}">
                                        {{ ucfirst($sync->sync_status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-slate-600">
                                    {{ $sync->attempt_count }} / {{ $sync->max_attempts }}
                                </td>
                                <td class="px-4 py-3 text-slate-600 font-mono text-[11px]">
                                    {{ $sync->last_synced_at ? $sync->last_synced_at->format('d M Y H:i:s') : ($sync->last_attempt_at ? $sync->last_attempt_at->format('d M Y H:i:s') : '-') }}
                                </td>
                                <td class="px-4 py-3 text-slate-600 max-w-xs truncate" title="{{ $sync->error_message }}">
                                    {{ $sync->error_message ?: '-' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($sync->canRetry())
                                        <form action="{{ route('admin.spreadsheet-connections.retry-row', [$connection, $sync]) }}" method="POST" class="inline-block">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold rounded bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                                                title="Retry baris ini"
                                            >
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="23 4 23 10 17 10"/>
                                                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                                                </svg>
                                                Retry
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-slate-300">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-slate-400 italic">
                                    Belum ada antrean sinkronisasi baris untuk koneksi ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rowSyncs->hasPages())
                <div class="p-4 border-t border-slate-200 bg-slate-50">
                    {{ $rowSyncs->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
