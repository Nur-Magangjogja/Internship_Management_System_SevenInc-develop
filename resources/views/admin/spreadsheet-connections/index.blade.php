@extends('layouts.dashboard', ['title' => 'Integrasi Google Spreadsheet'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    @php
        $unconnectedConnections = $connections->filter(fn($c) => $c->hasUnconnectedLinks());
        $hasAnyUnconnected = $unconnectedConnections->isNotEmpty();
    @endphp

    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold text-slate-800">Integrasi Google Spreadsheet</h1>
                @if ($hasAnyUnconnected)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-sm animate-pulse">
                        <svg class="h-3.5 w-3.5 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        Tanda: Link Belum Terhubung ke SertIF
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-900 border border-emerald-300 shadow-sm">
                        <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        Semua Link Terhubung ke SertIF
                    </span>
                @endif
            </div>
            <p class="text-sm text-slate-500 mt-1">Konfigurasi koneksi mirror operasional satu arah dari database SertIF ke Google Spreadsheet.</p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.legacy-import.index') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Impor Spreadsheet / XLSX Lama
            </a>
            <a
                href="{{ route('admin.sync-monitoring.index') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                Monitoring & Pemulihan
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('info'))
        <div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm font-medium text-sky-800">
            {{ session('info') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            {{ session('error') }}
        </div>
    @endif

    <!-- Information Box -->
    <div class="mb-8 rounded-xl border border-blue-200 bg-blue-50/50 p-5 text-sm text-blue-900 shadow-sm">
        <div class="flex items-start gap-3">
            <svg class="h-5 w-5 shrink-0 text-blue-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <div class="space-y-1">
                <p class="font-semibold text-blue-950">Prinsip Integrasi Spreadsheet (Source of Truth)</p>
                <ul class="list-disc pl-4 space-y-1 text-xs text-blue-800">
                    <li>Database SertIF adalah satu-satunya sumber kebenaran data (Source of Truth).</li>
                    <li>Google Spreadsheet berfungsi sebagai mirror operasional satu arah (SertIF &rarr; Spreadsheet).</li>
                    <li>Perubahan manual pada sel Google Spreadsheet tidak akan menimpa data di database SertIF.</li>
                    <li>Kredensial rahasia (OAuth/Service Account) disimpan pada konfigurasi server (.env), tidak disimpan dalam basis data.</li>
                </ul>
            </div>
        </div>
    </div>

    @if ($hasAnyUnconnected)
        <!-- Peringatan Global Link Penghubung Belum Terhubung -->
        <div class="mb-8 rounded-xl border-2 border-amber-300 bg-amber-50 p-5 text-amber-900 shadow-sm">
            <div class="flex items-start gap-3.5">
                <div class="p-2.5 rounded-lg bg-amber-200 text-amber-800 shrink-0 mt-0.5">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-bold text-amber-950 flex items-center gap-2">
                        <span>Peringatan Integrasi: Link Penghubung Google Drive / Spreadsheet Belum Terhubung ke SertIF</span>
                    </h3>
                    <p class="text-xs text-amber-800 mt-1 leading-relaxed">
                        Terdapat <strong>{{ $unconnectedConnections->count() }} koneksi</strong> yang link penghubung Google Spreadsheet atau Google Drive-nya belum terhubung ke database SertIF. Pastikan URL Google Spreadsheet dan Folder Google Drive sudah diatur serta pengujian koneksi berhasil (*Connected*) agar sinkronisasi data pendaftar dan berkas upload berjalan otomatis.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        @foreach ($unconnectedConnections as $uc)
                            <a href="{{ route('admin.spreadsheet-connections.edit', $uc) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-amber-300 text-amber-900 font-semibold hover:bg-amber-100 shadow-xs transition">
                                <span>{{ $uc->display_name }}</span>
                                <span class="text-amber-600 font-bold">&rarr; Edit Konfigurasi</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Connection Cards -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @foreach ($connections as $conn)
            <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div>
                    <!-- Card Top Header -->
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div>
                            <span class="inline-block px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider rounded-md {{ $conn->connection_key === 'registration' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-purple-50 text-purple-700 border border-purple-200' }}">
                                {{ $conn->connection_key }}
                            </span>
                            <h2 class="mt-2 text-lg font-bold text-slate-800">{{ $conn->display_name }}</h2>
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- Status Active Badge -->
                            @if ($conn->is_enabled)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    Aktif
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                    Nonaktif
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Details List -->
                    <div class="space-y-3 text-xs border-t border-slate-100 pt-4">
                        <!-- Tanda Status Link Penghubung SertIF -->
                        @if ($conn->hasUnconnectedLinks())
                            <div class="rounded-lg border border-amber-300 bg-amber-50/90 p-3 text-xs text-amber-900 shadow-xs">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2 w-2 rounded-full bg-amber-500 animate-pulse shrink-0"></span>
                                        <span class="font-bold text-amber-950">⚠️ Tanda: Link Penghubung Belum Terhubung ke SertIF</span>
                                    </div>
                                    <a href="{{ route('admin.spreadsheet-connections.edit', $conn) }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-800 hover:text-amber-950 underline shrink-0">
                                        Hubungkan &rarr;
                                    </a>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1.5 text-[11px]">
                                    @if (!$conn->isSpreadsheetConnected())
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-rose-100 text-rose-800 border border-rose-200 font-medium">
                                            ✕ Link Spreadsheet Belum Diisi
                                        </span>
                                    @endif
                                    @if ($conn->connection_key === \App\Models\SpreadsheetConnection::KEY_REGISTRATION && !$conn->isDriveConnected())
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-rose-100 text-rose-800 border border-rose-200 font-medium">
                                            ✕ Link Folder Drive Belum Diisi
                                        </span>
                                    @endif
                                    @if ($conn->connection_status !== \App\Models\SpreadsheetConnection::STATUS_CONNECTED)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200 font-medium">
                                            ! Status: {{ ucfirst(str_replace('_', ' ', $conn->connection_status ?? 'untested')) }} (Perlu Uji Koneksi)
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50/80 px-3 py-2 text-xs text-emerald-900 flex items-center justify-between shadow-xs">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>
                                    <span class="font-medium">Seluruh link Spreadsheet &amp; Google Drive terhubung ke database SertIF.</span>
                                </div>
                                <span class="text-[10px] font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded border border-emerald-300">Terhubung</span>
                            </div>
                        @endif

                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-medium text-slate-500">URL Spreadsheet:</span>
                                @if ($conn->isSpreadsheetConnected())
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="h-1 w-1 rounded-full bg-emerald-500"></span> Terhubung
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                        <span class="h-1 w-1 rounded-full bg-rose-500"></span> Belum Terhubung
                                    </span>
                                @endif
                            </div>
                            @if ($conn->spreadsheet_url)
                                <a href="{{ $conn->spreadsheet_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline break-all font-mono block">
                                    {{ $conn->spreadsheet_url }}
                                </a>
                            @else
                                <div class="flex items-center justify-between text-slate-400 italic">
                                    <span>Belum dikonfigurasi</span>
                                    <a href="{{ route('admin.spreadsheet-connections.edit', $conn) }}" class="not-italic text-xs text-blue-600 hover:underline font-normal font-sans">
                                        + Atur Link Spreadsheet
                                    </a>
                                </div>
                            @endif
                        </div>

                        <div>
                            <span class="font-medium text-slate-500 block">Spreadsheet ID:</span>
                            <span class="font-mono text-slate-700 break-all">{{ $conn->spreadsheet_id ?? '-' }}</span>
                        </div>

                        <div>
                            <span class="font-medium text-slate-500 block">Target Aktif Terotorisasi:</span>
                            @if ($conn->active_spreadsheet_id)
                                <span class="font-mono text-emerald-700 font-semibold break-all">{{ $conn->active_spreadsheet_id }}</span>
                                @if ($conn->hasTargetChanged())
                                    <span class="inline-block ml-1 px-1.5 py-0.5 text-[10px] font-bold rounded bg-amber-100 text-amber-800 border border-amber-300">Target Berubah</span>
                                @else
                                    <span class="inline-block ml-1 px-1.5 py-0.5 text-[10px] font-bold rounded bg-emerald-100 text-emerald-800 border border-emerald-200">Aktif</span>
                                @endif
                            @else
                                <span class="text-slate-400 italic">Belum Diaktifkan (Perlu Otorisasi Admin)</span>
                            @endif
                        </div>

                        @php
                            $pausedCount = \App\Models\SpreadsheetRowSync::where('spreadsheet_connection_id', $conn->id)
                                ->where('sync_status', \App\Models\SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND)
                                ->count();
                        @endphp

                        @if ($conn->hasTargetChanged())
                            <div class="rounded-lg p-2.5 border border-amber-200 bg-amber-50 text-amber-900 text-xs">
                                <span class="font-bold">⚠️ Perubahan Target:</span> ID Spreadsheet diubah. Klik <strong>Aktifkan Target</strong> untuk memperbarui Script Properties pada Apps Script Gateway.
                            </div>
                        @endif

                        @if ($pausedCount > 0)
                            <div class="rounded-lg p-2.5 border border-amber-300 bg-amber-50 text-amber-900 text-xs flex items-center justify-between">
                                <div>
                                    <span class="font-bold">⏸️ Antrean Dibekukan:</span> Terdapat {{ $pausedCount }} baris sinkronisasi dibekukan karena pergantian target.
                                </div>
                                <a href="{{ route('admin.spreadsheet-connections.show', $conn) }}" class="underline font-bold text-amber-800 hover:text-amber-950">
                                    Rekonsiliasi &rarr;
                                </a>
                            </div>
                        @endif

                        <div>
                            <span class="font-medium text-slate-500 block">Apps Script Endpoint URL:</span>
                            @if ($conn->apps_script_endpoint_url)
                                <span class="font-mono text-slate-700 break-all">{{ $conn->apps_script_endpoint_url }}</span>
                            @else
                                <span class="text-slate-400 italic">Belum dikonfigurasi (Opsional / Fase S9E)</span>
                            @endif
                        </div>

                        @if ($conn->connection_key === 'registration')
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-medium text-slate-500">Folder Penyimpanan Berkas Pemagang (Google Drive):</span>
                                    @if ($conn->isDriveConnected())
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span> Terhubung
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                            <span class="h-1 w-1 rounded-full bg-rose-500"></span> Belum Terhubung
                                        </span>
                                    @endif
                                </div>
                                @if ($conn->drive_folder_url)
                                    <a href="{{ $conn->drive_folder_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline break-all font-mono block">
                                        {{ $conn->drive_folder_url }}
                                    </a>
                                    @if ($conn->drive_folder_id)
                                        <span class="block text-[11px] font-mono text-slate-500 mt-0.5">Folder ID: {{ $conn->drive_folder_id }}</span>
                                    @endif
                                @else
                                    <div class="flex items-center justify-between text-slate-400 italic">
                                        <span>Belum dikonfigurasi</span>
                                        <a href="{{ route('admin.spreadsheet-connections.edit', $conn) }}" class="not-italic text-xs text-blue-600 hover:underline font-normal font-sans">
                                            + Atur Folder Drive
                                        </a>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div>
                            <span class="font-medium text-slate-500 block mb-1">Status Koneksi:</span>
                            @php
                                $badgeConfig = match($conn->connection_status) {
                                    'connected' => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-emerald-500', 'label' => 'Terhubung (Connected)'],
                                    'pending_transport' => ['bg' => 'bg-amber-50 text-amber-700 border-amber-200', 'dot' => 'bg-amber-500', 'label' => 'Pending Transport'],
                                    'error' => ['bg' => 'bg-rose-50 text-rose-700 border-rose-200', 'dot' => 'bg-rose-500', 'label' => 'Koneksi Error'],
                                    'not_configured' => ['bg' => 'bg-slate-100 text-slate-600 border-slate-200', 'dot' => 'bg-slate-400', 'label' => 'Belum Dikonfigurasi'],
                                    default => ['bg' => 'bg-slate-100 text-slate-600 border-slate-200', 'dot' => 'bg-slate-400', 'label' => 'Belum Diuji'],
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold border {{ $badgeConfig['bg'] }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $badgeConfig['dot'] }}"></span>
                                {{ $badgeConfig['label'] }}
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px]">
                            @if ($conn->last_tested_at)
                                <div>
                                    <span class="font-medium text-slate-500 block">Terakhir Diuji:</span>
                                    <span class="text-slate-700 font-mono">{{ $conn->last_tested_at->format('d M Y H:i:s') }}</span>
                                </div>
                            @endif

                            @if ($conn->last_successful_test_at)
                                <div>
                                    <span class="font-medium text-emerald-600 block">Terakhir Berhasil Terhubung:</span>
                                    <span class="text-emerald-700 font-mono">{{ $conn->last_successful_test_at->format('d M Y H:i:s') }}</span>
                                </div>
                            @endif
                        </div>

                        @if ($conn->last_error_summary)
                            @php
                                $isError = $conn->connection_status === 'error';
                                $isConnected = $conn->connection_status === 'connected';
                                $boxClass = $isError
                                    ? 'bg-rose-50 border-rose-200 text-rose-800'
                                    : ($isConnected ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-slate-50 border-slate-200 text-slate-700');
                            @endphp
                            <div class="rounded-lg p-2.5 border text-xs {{ $boxClass }}">
                                <span class="font-semibold block mb-0.5">{{ $isError ? 'Catatan Kesalahan Koneksi:' : 'Catatan Hasil Pengujian:' }}</span>
                                <p class="leading-relaxed">{{ $conn->last_error_summary }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Card Bottom Actions -->
                <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                    <a
                        href="{{ route('admin.spreadsheet-connections.show', $conn) }}"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                    >
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect width="18" height="18" x="3" y="3" rx="2"/>
                            <path d="M3 9h18M3 15h18M9 3v18"/>
                        </svg>
                        Lihat Kolom
                    </a>

                    <a
                        href="{{ route('admin.spreadsheet-connections.edit', $conn) }}"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                    >
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"/>
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                        </svg>
                        Edit Konfigurasi
                    </a>

                    <form action="{{ route('admin.spreadsheet-connections.toggle', $conn) }}" method="POST" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold shadow-sm transition {{ $conn->is_enabled ? 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100' : 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="9"/>
                                <path d="M12 3v9"/>
                            </svg>
                            {{ $conn->is_enabled ? 'Nonaktifkan' : 'Aktifkan' }}
                        </button>
                    </form>

                    <form action="{{ route('admin.spreadsheet-connections.test', $conn) }}" method="POST" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-blue-300 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-800 shadow-sm transition hover:bg-blue-100"
                        >
                            <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                            Uji Koneksi
                        </button>
                    </form>

                    <form action="{{ route('admin.spreadsheet-connections.activate-target', $conn) }}" method="POST" class="inline" onsubmit="return confirm('Otorisasi dan aktifkan target Spreadsheet \'{{ $conn->display_name }}\' ({{ $conn->spreadsheet_id }}) pada Google Apps Script Gateway? Perhatian: Seluruh pekerjaan pending/processing yang belum selesai akan dibekukan untuk proses rekonsiliasi.');">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-800 shadow-sm transition hover:bg-indigo-100"
                            title="Otorisasi target resmi pada Script Properties Google Apps Script"
                        >
                            <svg class="h-3.5 w-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            </svg>
                            Aktifkan Target
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
