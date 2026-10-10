@extends('layouts.dashboard', ['title' => 'Log Aktivitas Sistem'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Log Aktivitas Sistem</h1>
            <p class="text-sm text-slate-500">Pantau seluruh rekam jejak aktivitas, perubahan data, dan otentikasi pengguna secara terpusat dan aman.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600">
                <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Audit Trail Aktif (Append-Only)
            </span>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Aktivitas Hari Ini</span>
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-slate-800">{{ $stats['total_today'] }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Aktivitas Minggu Ini</span>
                <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-emerald-600">{{ $stats['total_week'] }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Total Seluruh Log</span>
                <span class="rounded-lg bg-purple-50 p-2 text-purple-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-slate-800">{{ $stats['total_all'] }}</div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 items-end">
            <!-- Filter Action -->
            <div>
                <label for="action" class="block text-xs font-semibold text-slate-700 mb-1">Jenis Aksi</label>
                <select
                    id="action"
                    name="action"
                    class="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
                    <option value="all">Semua Jenis Aksi</option>
                    @foreach ($distinctActions as $act)
                        <option value="{{ $act }}" {{ $action === $act ? 'selected' : '' }}>
                            {{ $act }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Filter Actor Role -->
            <div>
                <label for="actor_role" class="block text-xs font-semibold text-slate-700 mb-1">Peran Aktor</label>
                <select
                    id="actor_role"
                    name="actor_role"
                    class="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
                    <option value="all">Semua Peran</option>
                    <option value="admin" {{ $actorRole === 'admin' ? 'selected' : '' }}>Administrator</option>
                    <option value="hr_staff" {{ $actorRole === 'hr_staff' ? 'selected' : '' }}>HR Staff</option>
                    <option value="pemagang" {{ $actorRole === 'pemagang' ? 'selected' : '' }}>Pemagang</option>
                    <option value="system" {{ $actorRole === 'system' ? 'selected' : '' }}>Sistem</option>
                </select>
            </div>

            <!-- Date Range -->
            <div>
                <label for="date_from" class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Mulai</label>
                <input
                    type="date"
                    id="date_from"
                    name="date_from"
                    value="{{ $dateFrom }}"
                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <div>
                <label for="date_to" class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Sampai</label>
                <input
                    type="date"
                    id="date_to"
                    name="date_to"
                    value="{{ $dateTo }}"
                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <!-- Search and Submit -->
            <div class="flex items-center gap-2">
                <div class="relative flex-1">
                    <input
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Cari teks / aktor..."
                        class="w-full rounded-lg border border-slate-300 py-2 pl-8 pr-3 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                        <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                    </div>
                </div>
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
                >
                    Filter
                </button>
                @if (!empty($action) || !empty($actorRole) || !empty($dateFrom) || !empty($dateTo) || !empty($search))
                    <a
                        href="{{ route('admin.activity-logs.index') }}"
                        class="rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200 transition"
                        title="Reset Filter"
                    >
                        Reset
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 font-semibold text-slate-600">
                <tr>
                    <th class="px-5 py-3.5 w-12 text-center">No</th>
                    <th class="px-5 py-3.5">Waktu</th>
                    <th class="px-5 py-3.5">Aktor</th>
                    <th class="px-5 py-3.5">Aksi & Modul</th>
                    <th class="px-5 py-3.5">Deskripsi Aktivitas</th>
                    <th class="px-5 py-3.5 text-right">Snapshot Data</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($logs as $log)
                    <tr class="transition hover:bg-slate-50/50">
                        <td class="px-5 py-3.5 text-center font-medium text-slate-400">
                            {{ ($logs->currentPage() - 1) * $logs->perPage() + $loop->iteration }}
                        </td>
                        <td class="px-5 py-3.5 whitespace-nowrap">
                            <span class="font-bold text-slate-800">{{ $log->created_at?->format('d M Y, H:i:s') }}</span>
                            <div class="text-[11px] text-slate-400">
                                {{ $log->created_at?->diffForHumans() }}
                                @if ($log->ip_address)
                                    &bull; <span class="font-mono text-[10px] text-slate-500">{{ $log->ip_address }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-1.5">
                                <span class="font-semibold text-slate-800">{{ $log->actor_name_snapshot }}</span>
                                @php
                                    $roleBadge = match ($log->actor_role_snapshot) {
                                        'admin'    => 'bg-purple-50 text-purple-700 border-purple-200',
                                        'hr_staff' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'pemagang' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        default    => 'bg-slate-100 text-slate-700 border-slate-200',
                                    };
                                @endphp
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold border {{ $roleBadge }}">
                                    {{ $log->actor_role_snapshot ?? 'system' }}
                                </span>
                            </div>
                            @if ($log->actor_email_snapshot)
                                <p class="text-[11px] text-slate-400">{{ $log->actor_email_snapshot }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-mono font-medium text-slate-800 border border-slate-200">
                                {{ $log->action }}
                            </span>
                            @if ($log->subject_type)
                                <p class="mt-0.5 text-[11px] text-slate-500">
                                    Subjek: <span class="font-semibold text-slate-700">{{ class_basename($log->subject_type) }}</span>
                                </p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-slate-700 max-w-md">
                            <p class="text-xs leading-relaxed">{{ $log->description ?? '-' }}</p>
                            @if ($log->subject_public_ref)
                                <p class="mt-0.5 text-[10px] text-slate-400 font-mono">Ref: {{ $log->subject_public_ref }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                            @if (!empty($log->before_data) || !empty($log->after_data) || !empty($log->metadata))
                                <button
                                    type="button"
                                    onclick="openLogDetailModal({{ json_encode([
                                        'action' => $log->action,
                                        'actor'  => $log->actor_name_snapshot . ' (' . ($log->actor_role_snapshot ?? 'system') . ')',
                                        'time'   => $log->created_at?->format('d M Y, H:i:s'),
                                        'ip'     => $log->ip_address ?? '-',
                                        'ua'     => $log->user_agent ?? '-',
                                        'desc'   => $log->description ?? '-',
                                        'before' => $log->before_data,
                                        'after'  => $log->after_data,
                                        'meta'   => $log->metadata,
                                    ]) }})"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                                >
                                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    Lihat Data
                                </button>
                            @else
                                <span class="text-slate-400 italic text-[11px]">Tanpa payload</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                </svg>
                            </div>
                            <p class="mt-3 text-xs font-semibold text-slate-700">Belum ada catatan aktivitas yang sesuai dengan filter.</p>
                            <p class="mt-1 text-[11px] text-slate-400">Semua aksi administratif dan operasional akan terekam otomatis di sini.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        @if ($logs->hasPages())
            <div class="border-t border-slate-100 p-4">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>

<!-- Modal Detail Snapshot Log -->
<div id="logDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-3xl rounded-xl bg-white shadow-xl max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
            <div>
                <h3 class="text-base font-bold text-slate-900">Inspeksi Snapshot Data Aktivitas</h3>
                <p id="modalActionSubtitle" class="text-xs font-mono text-slate-500 mt-0.5"></p>
            </div>
            <button
                type="button"
                onclick="closeLogDetailModal()"
                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div class="overflow-y-auto p-6 space-y-4 text-xs">
            <!-- Meta Bar -->
            <div class="grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-4">
                <div>
                    <span class="text-[11px] font-medium text-slate-400 block">Aktor:</span>
                    <span id="modalActor" class="font-semibold text-slate-800"></span>
                </div>
                <div>
                    <span class="text-[11px] font-medium text-slate-400 block">Waktu:</span>
                    <span id="modalTime" class="font-semibold text-slate-800"></span>
                </div>
                <div>
                    <span class="text-[11px] font-medium text-slate-400 block">IP Address:</span>
                    <span id="modalIp" class="font-mono text-slate-700"></span>
                </div>
                <div>
                    <span class="text-[11px] font-medium text-slate-400 block">Deskripsi:</span>
                    <span id="modalDesc" class="text-slate-700 truncate block"></span>
                </div>
            </div>

            <!-- Before & After Comparison -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Before Data -->
                <div>
                    <span class="text-xs font-bold text-slate-700 mb-1.5 block">Data Sebelum (Before):</span>
                    <pre id="modalBefore" class="rounded-lg border border-slate-200 bg-slate-900 p-3 text-[11px] font-mono text-emerald-400 overflow-x-auto max-h-60"></pre>
                </div>

                <!-- After Data -->
                <div>
                    <span class="text-xs font-bold text-slate-700 mb-1.5 block">Data Sesudah (After):</span>
                    <pre id="modalAfter" class="rounded-lg border border-slate-200 bg-slate-900 p-3 text-[11px] font-mono text-sky-400 overflow-x-auto max-h-60"></pre>
                </div>
            </div>

            <!-- Metadata (Optional) -->
            <div id="modalMetaContainer" class="hidden">
                <span class="text-xs font-bold text-slate-700 mb-1.5 block">Metadata Tambahan:</span>
                <pre id="modalMeta" class="rounded-lg border border-slate-200 bg-slate-900 p-3 text-[11px] font-mono text-amber-300 overflow-x-auto max-h-40"></pre>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="border-t border-slate-100 px-6 py-3.5 flex justify-end">
            <button
                type="button"
                onclick="closeLogDetailModal()"
                class="rounded-lg border border-slate-200 bg-white px-4 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
            >
                Tutup
            </button>
        </div>
    </div>
</div>

<script>
    function openLogDetailModal(data) {
        document.getElementById('modalActionSubtitle').textContent = 'Aksi: ' + data.action;
        document.getElementById('modalActor').textContent = data.actor;
        document.getElementById('modalTime').textContent = data.time;
        document.getElementById('modalIp').textContent = data.ip;
        document.getElementById('modalDesc').textContent = data.desc;

        document.getElementById('modalBefore').textContent = data.before ? JSON.stringify(data.before, null, 2) : 'Tidak ada data sebelum (aksi pembuatan baru)';
        document.getElementById('modalAfter').textContent = data.after ? JSON.stringify(data.after, null, 2) : 'Tidak ada data sesudah';

        const metaContainer = document.getElementById('modalMetaContainer');
        if (data.meta && Object.keys(data.meta).length > 0) {
            metaContainer.classList.remove('hidden');
            document.getElementById('modalMeta').textContent = JSON.stringify(data.meta, null, 2);
        } else {
            metaContainer.classList.add('hidden');
        }

        const modal = document.getElementById('logDetailModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeLogDetailModal() {
        const modal = document.getElementById('logDetailModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
</script>
@endsection
