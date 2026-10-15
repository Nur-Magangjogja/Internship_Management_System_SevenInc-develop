@extends('layouts.dashboard', ['title' => 'Detail Analisis Kegagalan Sinkronisasi'])

@section('content')
<div class="px-6 py-8">
    <!-- Header & Breadcrumbs -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-sm text-slate-500 mb-1">
                <a href="{{ route('admin.spreadsheet-connections.index') }}" class="hover:text-blue-600 transition">Integrasi Spreadsheet</a>
                <span>&rarr;</span>
                <a href="{{ route('admin.sync-monitoring.index') }}" class="hover:text-blue-600 transition">Monitoring</a>
                <span>&rarr;</span>
                <span>Analisis Detail Kegagalan</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Analisis Detail Sinkronisasi</h1>
            <p class="text-xs text-slate-500 mt-1">
                Identitas record, relasi pendaftaran, status lintas-kategori, diagnosa lease, dan riwayat audit trail.
            </p>
        </div>
        <div class="flex items-center gap-3">
            @can('retry-sync-monitoring')
                @php
                    $isProcessing = false;
                    $canRetry = false;
                    $retryRoute = null;

                    if ($category === 'main_row') {
                        $isProcessing = $record->sync_status === 'processing';
                        $canRetry = in_array($record->sync_status, ['failed', 'retrying'], true) || ($isProcessing && $lease_diagnostics['is_expired']);
                        $retryRoute = route('admin.sync-monitoring.retry-main-row', $record->id);
                    } elseif ($category === 'sidecar') {
                        $isProcessing = $record->sync_status === 'processing';
                        $canRetry = in_array($record->sync_status, ['failed', 'retrying'], true) || ($isProcessing && $lease_diagnostics['is_expired']);
                        $retryRoute = route('admin.sync-monitoring.retry-sidecar', $record->id);
                    } elseif ($category === 'attachment') {
                        $isProcessing = $record->drive_sync_status === 'processing';
                        $canRetry = in_array($record->drive_sync_status, ['failed', 'retrying', 'pending'], true) || ($isProcessing && $lease_diagnostics['is_expired']);
                        $retryRoute = route('admin.sync-monitoring.retry-attachment', $record->id);
                    }
                @endphp

                @if ($canRetry && $retryRoute)
                    <form action="{{ $retryRoute }}" method="POST" onsubmit="return confirm('Picu retry manual untuk record ini sekarang?')">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-700"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="23 4 23 10 17 10"/>
                                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                            </svg>
                            Picu Retry Manual
                        </button>
                    </form>
                @endif
            @endcan

            <a
                href="{{ route('admin.sync-monitoring.index') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                Kembali
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- 1. Record Identity Card -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-800">Identitas Record Sinkronisasi</h2>
                <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider {{ $category === 'main_row' ? 'bg-blue-50 text-blue-700' : ($category === 'sidecar' ? 'bg-purple-50 text-purple-700' : 'bg-emerald-50 text-emerald-700') }}">
                    {{ $category === 'main_row' ? 'Main Row' : ($category === 'sidecar' ? 'Sidecar' : 'Attachment') }}
                </span>
            </div>

            <div class="space-y-2.5 text-xs">
                <div>
                    <span class="text-slate-500 font-medium block">Sync Identifier:</span>
                    <span class="font-mono font-semibold text-slate-800 break-all">
                        {{ $category === 'attachment' ? "ATT-{$record->internship_registration_id}-{$record->id}" : $record->external_sync_id }}
                    </span>
                </div>

                @if (!empty($record->public_uuid))
                    <div>
                        <span class="text-slate-500 font-medium block">Public UUID:</span>
                        <span class="font-mono text-slate-600 break-all text-[11px]">{{ $record->public_uuid }}</span>
                    </div>
                @endif

                <div>
                    <span class="text-slate-500 font-medium block">Status Terkini:</span>
                    @php
                        $st = $category === 'attachment' ? $record->drive_sync_status : $record->sync_status;
                        $statusClass = match ($st) {
                            'synced'     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'pending'    => 'bg-blue-50 text-blue-700 border-blue-200',
                            'processing' => 'bg-purple-50 text-purple-700 border-purple-200',
                            'retrying'   => 'bg-amber-50 text-amber-700 border-amber-200',
                            'failed'     => 'bg-rose-50 text-rose-700 border-rose-200',
                            default      => 'bg-slate-100 text-slate-700 border-slate-200',
                        };
                    @endphp
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold border {{ $statusClass }} mt-1">
                        {{ ucfirst($st) }}
                    </span>
                </div>

                <div>
                    <span class="text-slate-500 font-medium block">Target / Tab Sheet:</span>
                    <span class="font-mono text-slate-700">
                        @if ($category === 'main_row')
                            Tab: [{{ $record->target_sheet_tab }}] (Versi: {{ $record->schema_version ?? '-' }})
                        @elseif ($category === 'sidecar')
                            Tab: [{{ $record->extension_sheet_tab }}] (Versi: {{ $record->form_schema_version ?? '-' }})
                        @else
                            Berkas: {{ $record->original_name }}
                        @endif
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100 text-[11px]">
                    <div>
                        <span class="text-slate-500 block">Dibuat:</span>
                        <span class="font-mono text-slate-700">{{ $record->created_at?->format('d/m/Y H:i') }}</span>
                    </div>
                    <div>
                        <span class="text-slate-500 block">Terakhir Update:</span>
                        <span class="font-mono text-slate-700">{{ $record->updated_at?->format('d/m/Y H:i') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Registration Relationship Card -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-800">Hubungan Entitas Pendaftaran</h2>
                @if ($registration)
                    <span class="font-mono text-[11px] font-bold text-blue-700">#{{ $registration->id }}</span>
                @endif
            </div>

            @if ($registration)
                <div class="space-y-2.5 text-xs">
                    <div>
                        <span class="text-slate-500 font-medium block">Nama Peserta / Pemagang:</span>
                        <span class="font-semibold text-slate-800">{{ $registration->user?->name ?? 'Tidak Ada User' }}</span>
                    </div>

                    <div>
                        <span class="text-slate-500 font-medium block">Email:</span>
                        <span class="font-mono text-slate-700">{{ $registration->user?->email ?? '-' }}</span>
                    </div>

                    <div>
                        <span class="text-slate-500 font-medium block">Institusi / Sekolah / Kampus:</span>
                        <span class="text-slate-700">{{ $registration->institution?->name ?? '-' }}</span>
                    </div>

                    <div>
                        <span class="text-slate-500 font-medium block">Status Penerimaan (Acceptance Gate):</span>
                        <span class="inline-block mt-0.5 px-2 py-0.5 text-[10px] font-semibold rounded {{ $registration->status === 'accepted' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                            {{ strtoupper($registration->status) }}
                        </span>
                    </div>

                    <div class="pt-2 border-t border-slate-100 text-[11px]">
                        <span class="text-slate-500 block">Tanggal Registrasi:</span>
                        <span class="font-mono text-slate-700">{{ $registration->created_at?->format('d M Y H:i:s') }}</span>
                    </div>
                </div>
            @else
                <div class="p-6 text-center text-slate-400 italic text-xs">
                    Entitas pendaftaran tidak terhubung secara langsung.
                </div>
            @endif
        </div>

        <!-- 3. Lease & Fencing Diagnostics Card -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-800">Diagnosa Lease & Locking</h2>
                <span class="px-2 py-0.5 rounded text-[10px] font-semibold {{ $lease_diagnostics['badge_class'] }}">
                    {{ $lease_diagnostics['status_label'] }}
                </span>
            </div>

            <div class="space-y-2.5 text-xs">
                <div>
                    <span class="text-slate-500 font-medium block">Worker Lease Token / Locked By:</span>
                    <span class="font-mono text-slate-700 text-[11px] break-all">
                        {{ $lease_diagnostics['locked_by'] ?? 'None (Tidak Terkunci)' }}
                    </span>
                </div>

                @if (isset($lease_diagnostics['locked_at']))
                    <div>
                        <span class="text-slate-500 font-medium block">Waktu Mulai Terkunci:</span>
                        <span class="font-mono text-slate-700 text-[11px]">
                            {{ $lease_diagnostics['locked_at'] ? $lease_diagnostics['locked_at']->format('d/m/Y H:i:s') : '-' }}
                        </span>
                    </div>
                @endif

                @if (isset($lease_diagnostics['expires_at']))
                    <div>
                        <span class="text-slate-500 font-medium block">Batas Waktu Lease (Expires At):</span>
                        <span class="font-mono text-slate-700 text-[11px]">
                            {{ $lease_diagnostics['expires_at'] ? $lease_diagnostics['expires_at']->format('d/m/Y H:i:s') : '-' }}
                        </span>
                    </div>
                @endif

                <div class="pt-2 border-t border-slate-100 text-[11px] text-slate-500">
                    <p class="leading-relaxed">
                        Lease mencegah eksekusi ganda antar worker. Jika worker gagal merilis lock dan waktu telah melampaui batas, lease dapat diambil alih secara otomatis oleh recovery worker atau admin manual retry.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Diagnostics & Sanitized Analysis Box -->
    <div class="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <h2 class="text-sm font-bold text-slate-800">Diagnosa Kesalahan Sinkronisasi (Sanitized Error)</h2>
            </div>
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold border {{ $error_category['badge_class'] }}">
                {{ $error_category['label'] }}
            </span>
        </div>

        <div class="space-y-3">
            <div class="rounded-lg bg-slate-900 p-4 text-xs font-mono text-emerald-400 break-all leading-relaxed overflow-x-auto">
                {{-- Escaped and sanitized to prevent XSS and prevent token/key leakage --}}
                {!! nl2br(e($sanitized_error)) !!}
            </div>
            <p class="text-xs text-slate-500">
                {{ $error_category['description'] }}
            </p>
        </div>
    </div>

    <!-- Cross-Category Sync Correlation Matrix -->
    <div class="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="border-b border-slate-100 pb-3 mb-4">
            <h2 class="text-sm font-bold text-slate-800">Matriks Sinkronisasi Lintas-Kategori Pendaftaran</h2>
            <p class="text-xs text-slate-500">Korelasi status sinkronisasi baris utama, ekstensi sidecar, dan lampiran berkas Drive untuk pendaftaran ini.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <!-- Main Row -->
            <div class="rounded-lg border border-slate-200 p-4 bg-slate-50/50">
                <span class="font-semibold text-slate-700 block mb-1">1. Baris Utama (Main Row)</span>
                @if ($related_syncs['main_row'])
                    <div class="space-y-1 font-mono text-[11px]">
                        <div>Status: <strong class="text-slate-800">{{ ucfirst($related_syncs['main_row']->sync_status) }}</strong></div>
                        <div class="text-slate-500">Sync ID: {{ $related_syncs['main_row']->external_sync_id }}</div>
                        <div class="text-slate-500">Tab: [{{ $related_syncs['main_row']->target_sheet_tab }}]</div>
                    </div>
                @else
                    <span class="text-slate-400 italic">Belum dibuat / Belum ada baris sinkronisasi</span>
                @endif
            </div>

            <!-- Sidecar -->
            <div class="rounded-lg border border-slate-200 p-4 bg-slate-50/50">
                <span class="font-semibold text-slate-700 block mb-1">2. Ekstensi Sidecar</span>
                @if ($related_syncs['sidecar'])
                    <div class="space-y-1 font-mono text-[11px]">
                        <div>Status: <strong class="text-slate-800">{{ ucfirst($related_syncs['sidecar']->sync_status) }}</strong></div>
                        <div class="text-slate-500">Sync ID: {{ $related_syncs['sidecar']->external_sync_id }}</div>
                        <div class="text-slate-500">Tab: [{{ $related_syncs['sidecar']->extension_sheet_tab }}]</div>
                    </div>
                @else
                    <span class="text-slate-400 italic">Tidak ada antrean sidecar untuk pendaftaran ini</span>
                @endif
            </div>

            <!-- Attachments -->
            <div class="rounded-lg border border-slate-200 p-4 bg-slate-50/50">
                <span class="font-semibold text-slate-700 block mb-1">3. Lampiran Berkas Drive ({{ $related_syncs['attachments']->count() }} berkas)</span>
                @if ($related_syncs['attachments']->isNotEmpty())
                    <ul class="space-y-1 font-mono text-[11px]">
                        @foreach ($related_syncs['attachments'] as $att)
                            <li class="flex items-center justify-between border-b border-slate-100 pb-0.5">
                                <span class="truncate max-w-[140px] text-slate-700">{{ $att->original_name }}</span>
                                <span class="text-[10px] font-semibold {{ $att->drive_sync_status === 'synced' ? 'text-emerald-700' : ($att->drive_sync_status === 'failed' ? 'text-rose-700' : 'text-blue-700') }}">
                                    {{ ucfirst($att->drive_sync_status) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <span class="text-slate-400 italic">Tidak ada berkas lampiran yang diunggah</span>
                @endif
            </div>
        </div>
    </div>

    <!-- Audit Trail History Table -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50/80">
            <h2 class="text-sm font-bold text-slate-800">Riwayat Log Audit Tindakan (Audit Trail)</h2>
            <p class="text-xs text-slate-500">Pencatatan seluruh tindakan manual maupun sistem terhadap record ini dari tabel activity_logs.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3 w-12 text-center">No.</th>
                        <th class="px-4 py-3">Waktu</th>
                        <th class="px-4 py-3">Aktor</th>
                        <th class="px-4 py-3">Aksi</th>
                        <th class="px-4 py-3">Deskripsi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($audit_logs as $log)
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="px-4 py-3 text-center text-slate-400 font-mono">
                                {{ $loop->iteration }}
                            </td>
                            <td class="px-4 py-3 font-mono text-[11px] text-slate-600">
                                {{ $log->created_at->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-semibold text-slate-800">{{ $log->actor_name_snapshot }}</span>
                                <span class="text-[10px] text-slate-400 block font-mono">({{ $log->actor_role_snapshot }})</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-slate-700">
                                <span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 text-slate-800 text-[10px]">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $log->description }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-slate-400 italic">
                                Belum ada riwayat audit trail tercatat untuk record ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
