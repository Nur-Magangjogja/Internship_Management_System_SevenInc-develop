@extends('layouts.dashboard', ['title' => 'Integrasi Google Drive & Apps Script'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">
                <span>Pengaturan Sistem</span>
                <span class="text-slate-300">&bull;</span>
                <span class="text-slate-600">Integrasi Drive &amp; Apps Script</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Integrasi Google Drive &amp; Apps Script Gateway</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-2xl leading-relaxed">Pusat konfigurasi tunggal terpadu untuk link Folder Induk Google Drive (Penyimpanan Asset &amp; Berkas) dan link Endpoint Web Google Apps Script Gateway untuk seluruh integrasi sistem SertIF.</p>
        </div>

        <!-- Tautan Navigasi Modul Terkait (Kanan Atas) -->
        <div class="flex items-center gap-2 shrink-0">
            <a
                href="{{ route('admin.system-assets.index') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-blue-600 hover:border-slate-300"
                title="Buka Perpustakaan Aset Sistem"
            >
                <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect width="20" height="5" x="2" y="3" rx="1"/>
                    <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/>
                    <path d="M10 12h4"/>
                </svg>
                <span>Perpustakaan Aset</span>
            </a>

            <a
                href="{{ route('admin.spreadsheet-connections.index') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-emerald-600 hover:border-slate-300"
                title="Buka Integrasi Spreadsheet"
            >
                <svg class="h-4 w-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
                    <line x1="3" y1="9" x2="21" y2="9"/>
                    <line x1="9" y1="21" x2="9" y2="9"/>
                </svg>
                <span>Integrasi Spreadsheet</span>
            </a>
        </div>
    </div>

    <!-- Toolbar Aksi Operasional Drive -->
    <div class="mb-8 rounded-xl border border-slate-200 bg-slate-50/80 p-4 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Aksi Diagnostik &amp; Otorisasi Drive</h3>
                    <p class="text-xs text-slate-500">Uji akses folder secara read-only atau otorisasi folder root resmi pada Google Apps Script Gateway.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                <form action="{{ route('admin.cloud-storage.test') }}" method="POST" class="inline-block m-0">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 active:translate-y-px"
                        title="Uji koneksi read-only via drive_ping tanpa memutasi data Google Drive"
                    >
                        <svg class="h-4 w-4 shrink-0 text-emerald-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        <span>Uji Koneksi &amp; Izin Folder</span>
                        <span class="ml-1 rounded bg-emerald-700/80 px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-emerald-100">Read-Only</span>
                    </button>
                </form>

                <form action="{{ route('admin.cloud-storage.activate-root') }}" method="POST" class="inline-block m-0" onsubmit="return confirm('Otorisasi dan aktifkan folder induk Google Drive ini secara resmi pada Script Properties Google Apps Script Gateway? Peringatan: Seluruh antrean upload yang pending akan dialihkan ke root baru setelah verifikasi.');">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 active:translate-y-px"
                        title="Otorisasi target root folder resmi pada Google Apps Script Gateway"
                    >
                        <svg class="h-4 w-4 shrink-0 text-indigo-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        <span>Aktifkan Folder Root</span>
                        <span class="ml-1 rounded bg-indigo-700/80 px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-indigo-100">Otorisasi</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Alert Notifications -->
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

    <!-- Status Cards Grid -->
    <div class="mb-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <!-- 1. Status Koneksi -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status Koneksi</span>
            <div class="mt-2 flex items-center gap-2">
                @if ($settings->connection_status === 'connected' && $settings->is_verified)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        Terhubung &amp; Terverifikasi
                    </span>
                @elseif ($settings->connection_status === 'error')
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-2.5 py-1 text-xs font-bold text-rose-800">
                        <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                        Gagal / Terkendala
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                        <span class="h-2 w-2 rounded-full bg-slate-400"></span>
                        Belum Diuji
                    </span>
                @endif
            </div>
            <p class="mt-2 text-xs text-slate-500">
                Uji terakhir: {{ $settings->last_tested_at ? $settings->last_tested_at->diffForHumans() : 'Belum pernah' }}
            </p>
        </div>

        <!-- 2. Provider Aktif -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Provider Aktif</span>
            <div class="mt-2 flex items-center gap-2">
                <svg class="h-5 w-5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
                <p class="text-sm font-bold text-slate-800">
                    @if ($activeProvider === 'apps_script_drive')
                        Apps Script Drive
                    @else
                        Local Fallback
                    @endif
                </p>
            </div>
            <p class="mt-2 text-xs {{ $settings->connection_status === 'connected' ? 'text-emerald-600 font-semibold' : 'text-slate-500' }}">
                @if ($activeProvider === 'apps_script_drive')
                    ✓ Utama (Google Drive via Apps Script)
                @else
                    Simulasi Lokal
                @endif
            </p>
        </div>

        <!-- 3. Status Antrean & Sinkronisasi -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Antrean Dokumen</span>
            <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                <div class="rounded bg-slate-50 p-1.5">
                    <span class="text-slate-500">Antrean:</span>
                    <span class="font-bold text-amber-600 ml-1">{{ $queueStats['pending'] }}</span>
                </div>
                <div class="rounded bg-slate-50 p-1.5">
                    <span class="text-slate-500">Retry:</span>
                    <span class="font-bold text-indigo-600 ml-1">{{ $queueStats['retrying'] }}</span>
                </div>
                <div class="rounded bg-slate-50 p-1.5">
                    <span class="text-slate-500">Gagal:</span>
                    <span class="font-bold text-rose-600 ml-1">{{ $queueStats['failed'] }}</span>
                </div>
                <div class="rounded bg-slate-50 p-1.5">
                    <span class="text-slate-500">Sukses:</span>
                    <span class="font-bold text-emerald-600 ml-1">{{ $queueStats['synced'] }}</span>
                </div>
            </div>
            <p class="mt-2 text-[11px] text-slate-500">Dipulihkan otomatis di background</p>
        </div>

        <!-- 4. Kapasitas & Gateway -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Kapasitas &amp; Gateway</span>
            <div class="mt-2 flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full {{ $isGasConfigured ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                <span class="text-xs font-bold text-slate-800">
                    {{ $isGasConfigured ? 'Gateway Terpasang' : 'Gateway Belum Lengkap' }}
                </span>
            </div>
            <p class="mt-2 text-xs text-slate-500">
                Penyimpanan Google Drive Pribadi/Workspace via Apps Script Web App
            </p>
        </div>
    </div>

    <!-- Error Summary jika ada -->
    @if ($settings->last_error_summary)
        <div class="mb-8 rounded-xl border border-rose-300 bg-rose-50 p-5 text-sm text-rose-900 shadow-sm">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 shrink-0 text-rose-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>
                    <h3 class="font-bold text-rose-950">Catatan Diagnosa Terakhir</h3>
                    <p class="mt-1 text-xs text-rose-800 font-mono">{{ $settings->last_error_summary }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Conflict & Mismatch Warnings -->
    @if ($hasEnvConflict)
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm">
            <div class="flex items-start gap-2.5">
                <span class="text-base">⚠️</span>
                <div>
                    <h4 class="font-bold text-amber-950">Perbedaan Konfigurasi Root (UI ≠ ENV)</h4>
                    <p class="text-xs text-amber-800 mt-0.5">
                        Folder ID di database (<code>{{ $settings->canonical_parent_folder_id }}</code>) berbeda dengan variabel di <code>.env</code>. Sistem memprioritaskan konfigurasi database UI secara konsisten.
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if ($hasSpreadsheetMismatch)
        <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 shadow-sm">
            <div class="flex items-start gap-2.5">
                <span class="text-base">ℹ️</span>
                <div>
                    <h4 class="font-bold text-sky-950">Struktur Terpisah: Folder Pendaftaran Pemagang</h4>
                    <p class="text-xs text-sky-800 mt-0.5">
                        Folder registrasi di integrasi Spreadsheet (<code>{{ $registrationRef['folder_id'] }}</code>) terpisah dari Folder Induk Sistem (<code>{{ $settings->canonical_parent_folder_id }}</code>). Berkas pendaftaran pemagang tetap tersimpan aman di jalurnya masing-masing.
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if ($settings->connection_status === 'connected' && $settings->is_verified)
        <!-- Status Terhubung Sukses -->
        <div class="mb-8 rounded-xl border border-emerald-200 bg-emerald-50/70 p-5 shadow-sm">
            <div class="flex items-start gap-3">
                <div class="rounded-lg bg-emerald-100 p-2 text-emerald-700">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div class="space-y-1 text-sm text-emerald-950">
                    <h3 class="font-bold text-base">Google Drive Berhasil Terhubung</h3>
                    <p class="text-xs text-emerald-800">
                        Folder Google Drive sistem (<strong>{{ $settings->canonical_parent_folder_id }}</strong>) telah berhasil diverifikasi secara aktif melalui <strong>Google Apps Script Gateway</strong>. Anda tidak perlu membuat project di Google Cloud Console. Seluruh sinkronisasi data dan penyimpanan dokumen pemagang, MOU, serta aset berjalan aman.
                    </p>
                </div>
            </div>
        </div>
    @else
        <!-- Panduan Integrasi Apps Script Drive -->
        <div class="mb-8 rounded-xl border border-blue-200 bg-blue-50/70 p-5 shadow-sm">
            <div class="flex items-start gap-3">
                <div class="rounded-lg bg-blue-100 p-2 text-blue-700">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="16" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                </div>
                <div class="space-y-2 text-sm text-blue-950">
                    <h3 class="font-bold text-base">Arsitektur Penyimpanan Google Apps Script Drive</h3>
                    <p class="text-xs text-blue-800">
                        Penyimpanan cloud SERTIF menggunakan <strong>Google Apps Script Drive Gateway</strong>. Anda cukup memasukkan URL/ID Folder Induk Google Drive di bawah dan menekan tombol <strong>Uji Koneksi &amp; Izin Folder</strong>. Tidak diperlukan OAuth2 Client ID, Client Secret, atau Refresh Token.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <!-- Main Configuration Form -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <form action="{{ route('admin.cloud-storage.update') }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Provider Selection & General Info -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">Gateway Penyimpanan Remote</label>
                    <input type="hidden" name="provider" value="apps_script_drive">
                    <div class="mt-1 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 shadow-sm">
                        <svg class="h-4 w-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                        <span>Google Apps Script DriveApp (Drive Pribadi)</span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">Arsitektur tunggal tanpa OAuth2 direct Laravel.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">Nama Konfigurasi</label>
                    <input
                        type="text"
                        name="name"
                        value="{{ old('name', $settings->name) }}"
                        required
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        placeholder="Contoh: Penyimpanan Google Drive Sistem SERTIF"
                    />
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">Status Aktif</label>
                    <label class="mt-3 flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="is_enabled"
                            value="1"
                            {{ old('is_enabled', $settings->is_enabled) ? 'checked' : '' }}
                            class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span class="text-sm font-medium text-slate-700">Aktifkan Sinkronisasi Cloud</span>
                    </label>
                </div>
            </div>

            <hr class="border-slate-100" />

            <!-- Single Canonical Integration Links (Drive & Apps Script Endpoint) -->
            <div>
                <h2 class="text-base font-bold text-slate-800">Link Integrasi Sistem (Google Drive &amp; Apps Script Gateway)</h2>
                <p class="text-xs text-slate-500 mt-0.5">Konfigurasi 1 akun Google untuk seluruh sistem. Link Endpoint Web Apps Script bertindak sebagai gateway API terpadu, dan Link Folder Google Drive bertindak sebagai pusat penyimpanan aset dan berkas.</p>
            </div>

            <!-- 1. Endpoint Web Apps Script Gateway -->
            <div class="space-y-2 rounded-xl border-2 {{ $settings->apps_script_endpoint_url ? 'border-emerald-300 bg-emerald-50/20' : 'border-slate-200 bg-slate-50/50' }} p-4">
                <div class="flex items-center justify-between">
                    <label for="apps_script_endpoint_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Link Endpoint Web Google Apps Script (Gateway Terpadu) <span class="text-rose-500">*</span>
                    </label>
                    @if ($settings->apps_script_endpoint_url)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Terpasang (1 Akun Terpadu)
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                            Belum Dikonfigurasi
                        </span>
                    @endif
                </div>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        type="url"
                        name="apps_script_endpoint_url"
                        id="apps_script_endpoint_url"
                        value="{{ old('apps_script_endpoint_url', $settings->apps_script_endpoint_url ?: $appsScriptEndpoint) }}"
                        class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 font-mono"
                        placeholder="Contoh: https://script.google.com/macros/s/AKfycb.../exec"
                    />
                </div>

                <p class="text-[11px] text-slate-500">
                    Satu-satunya endpoint Web App Google Apps Script (/exec) untuk mengeksekusi operasi Google Drive (penyimpanan aset &amp; dokumen) serta integrasi Google Spreadsheet multi-brand.
                </p>
            </div>

            <!-- 2. Folder Induk Google Drive (Penyimpanan Asset) -->
            @php
                $canonicalDriveHref = $settings->canonical_parent_folder_url;
                if (empty($canonicalDriveHref) && !empty($settings->canonical_parent_folder_id)) {
                    $canonicalDriveHref = 'https://drive.google.com/drive/folders/' . $settings->canonical_parent_folder_id;
                } elseif (!empty($canonicalDriveHref) && !str_starts_with($canonicalDriveHref, 'http')) {
                    $canonicalDriveHref = 'https://drive.google.com/drive/folders/' . $canonicalDriveHref;
                }
            @endphp
            <div class="space-y-2 rounded-xl border-2 {{ $settings->is_verified ? 'border-emerald-300 bg-emerald-50/20' : ($settings->canonical_parent_folder_id ? 'border-amber-300 bg-amber-50/20' : 'border-slate-200 bg-slate-50/50') }} p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label for="canonical_parent_folder_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Link Folder Induk Google Drive (Penyimpanan Asset &amp; Berkas) <span class="text-rose-500">*</span>
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($settings->canonical_parent_folder_id)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-0.5 text-xs font-mono font-bold text-slate-700">
                                ID: {{ $settings->canonical_parent_folder_id }}
                            </span>
                        @endif
                        @if (!empty($canonicalDriveHref))
                            <a
                                href="{{ $canonicalDriveHref }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-100 hover:text-blue-900"
                                title="Buka Folder Induk di Google Drive pada Tab Baru"
                            >
                                <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                                <span>Buka Folder di Drive</span>
                            </a>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <div class="relative flex-1">
                        <input
                            type="text"
                            name="canonical_parent_folder_url"
                            id="canonical_parent_folder_url"
                            value="{{ old('canonical_parent_folder_url', $settings->canonical_parent_folder_url ?: $settings->canonical_parent_folder_id) }}"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 font-mono {{ !empty($canonicalDriveHref) ? 'pr-20' : '' }}"
                            placeholder="Contoh: https://drive.google.com/drive/folders/1a2B3c... atau 1a2B3c..."
                        />
                        @if (!empty($canonicalDriveHref))
                            <a
                                href="{{ $canonicalDriveHref }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="absolute inset-y-1 right-1 inline-flex items-center gap-1 rounded-md px-2.5 text-xs font-semibold text-blue-600 hover:bg-blue-50 hover:text-blue-800 transition"
                                title="Buka Folder di Google Drive"
                            >
                                <span>Buka</span>
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                            </a>
                        @endif
                    </div>
                </div>

                <!-- Status Verifikasi Folder Induk -->
                <div class="flex flex-wrap items-center gap-2 pt-1 text-xs">
                    @if ($settings->is_verified)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 font-bold text-emerald-800">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            Terverifikasi Aktif
                        </span>
                        <span class="text-slate-500">Terakhir diverifikasi: {{ $settings->verified_at ? $settings->verified_at->format('d M Y H:i') : '-' }}</span>
                    @elseif ($settings->canonical_parent_folder_id)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 font-bold text-amber-800">
                            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                            Menunggu Uji Verifikasi
                        </span>
                        <span class="text-slate-500">Klik tombol "Uji Koneksi &amp; Izin Folder (Read-Only)" di atas untuk memvalidasi akses.</span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-600">
                            Belum Dikonfigurasi
                        </span>
                    @endif
                </div>

                @if ($settings->validation_error)
                    <div class="mt-2 rounded-lg bg-rose-50 border border-rose-200 p-2.5 text-xs text-rose-700 font-medium">
                        <strong>Kendala Validasi:</strong> {{ $settings->validation_error }}
                    </div>
                @endif
            </div>

            <!-- Detail Gateway Google Apps Script & Kredensial HMAC -->
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-xs space-y-2">
                <h3 class="font-bold text-slate-800 uppercase tracking-wider">Status Keamanan Gateway Apps Script</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                    <div>
                        <span class="text-slate-500 block">Web App Endpoint Aktif:</span>
                        <span class="font-mono text-slate-800 break-all font-semibold">{{ $maskedEndpoint ?: 'Belum diatur' }}</span>
                    </div>
                    <div>
                        <span class="text-slate-500 block">Status Kredensial HMAC:</span>
                        <span class="font-semibold {{ $hasSharedSecret ? 'text-emerald-700' : 'text-amber-700' }}">
                            {{ $hasSharedSecret ? '✓ Terpasang di Server (HMAC-SHA256 Aktif)' : '⚠️ Belum dikonfigurasi di .env' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Legacy Folders (Read-Only / Transisi) -->
            <details class="rounded-lg border border-slate-200 bg-slate-50/50 p-3 text-xs text-slate-600">
                <summary class="cursor-pointer font-bold uppercase tracking-wider text-slate-700 hover:text-slate-900">
                    Konfigurasi Folder Legacy / Transisi (Opsional / Read-Only)
                </summary>
                <p class="mt-2 text-slate-500">Folder legacy di bawah ini dipertahankan sebagai referensi riwayat audit. Operasi baru sepenuhnya menggunakan Folder Induk Tunggal di atas.</p>
                <div class="mt-3 space-y-3">
                    <div>
                        <span class="font-semibold text-slate-700">1. System Assets:</span>
                        <span class="font-mono text-slate-600">{{ $settings->system_assets_folder_id ?: 'Belum diatur' }}</span>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-700">2. Document Templates:</span>
                        <span class="font-mono text-slate-600">{{ $settings->document_templates_folder_id ?: 'Tidak diperlukan untuk operasi baru (Database-versioned)' }}</span>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-700">3. Generated Documents:</span>
                        <span class="font-mono text-slate-600">{{ $settings->generated_docs_folder_id ?: 'Lazy-created di Folder Induk' }}</span>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-700">4. Archives:</span>
                        <span class="font-mono text-slate-600">{{ $settings->archives_folder_id ?: 'Tidak diperlukan untuk operasi baru' }}</span>
                    </div>
                </div>
            </details>

            <hr class="border-slate-100" />

            <!-- Referensi Folder Registrasi Pemagang Multi-Brand -->
            <div
                x-data="{
                    isOpen: false,
                    page: 1,
                    perPage: 5,
                    total: {{ count($brandFolderItems ?? []) }},
                    get totalPages() {
                        return Math.ceil(this.total / this.perPage) || 1;
                    },
                    get itemsOnCurrentPage() {
                        if (this.total === 0) return 0;
                        return Math.min(this.perPage, this.total - (this.page - 1) * this.perPage);
                    },
                    get emptyRows() {
                        if (this.total === 0) return [];
                        const count = Math.max(0, this.perPage - this.itemsOnCurrentPage);
                        return Array.from({ length: count }, (_, i) => i + 1);
                    },
                    nextPage() {
                        if (this.page < this.totalPages) this.page++;
                    },
                    prevPage() {
                        if (this.page > 1) this.page--;
                    },
                    goToPage(p) {
                        this.page = p;
                    }
                }"
                class="rounded-xl border border-amber-200/90 bg-white p-5 shadow-sm space-y-4"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-slate-800">Referensi Folder Registrasi Pemagang (Multi-Brand)</h3>
                                <span class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-900 border border-amber-300/60">Read-Only</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-600 max-w-2xl leading-relaxed">
                                Dokumen pendaftaran pemagang (LOA, SKL, sertifikat, berkas) disimpan per brand sesuai konfigurasi pada modul <strong class="text-slate-800">Integrasi Spreadsheet &amp; Drive</strong>.
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 shrink-0 self-start">
                        <!-- Tombol Buka / Tutup Daftar -->
                        <button
                            type="button"
                            @click="isOpen = !isOpen"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 hover:text-slate-900"
                        >
                            <span x-text="isOpen ? 'Sembunyikan Daftar Folder' : 'Lihat Daftar Folder ({{ count($brandFolderItems ?? []) }})'">Lihat Daftar Folder ({{ count($brandFolderItems ?? []) }})</span>
                            <svg :class="isOpen ? 'rotate-180' : ''" class="h-3.5 w-3.5 text-slate-500 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>

                        <a
                            href="{{ route('admin.spreadsheet-connections.index') }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50/80 px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-xs transition hover:bg-amber-100 hover:border-amber-400"
                        >
                            <svg class="h-3.5 w-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            <span>Kelola di Spreadsheet</span>
                        </a>
                    </div>
                </div>

                <!-- Ringkasan Statistik Status Folder Multi-Brand -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 pt-1">
                    <div class="rounded-lg bg-slate-50 border border-slate-200/80 p-2.5 text-center">
                        <span class="block text-[10px] uppercase font-bold text-slate-500">Total Brand</span>
                        <span class="text-sm font-extrabold text-slate-800">{{ $folderStats['total_brands'] ?? count($brandFolderItems ?? []) }}</span>
                    </div>
                    <div class="rounded-lg bg-emerald-50 border border-emerald-200/80 p-2.5 text-center">
                        <span class="block text-[10px] uppercase font-bold text-emerald-700">Folder Terpasang</span>
                        <span class="text-sm font-extrabold text-emerald-800">{{ $folderStats['configured_count'] ?? 0 }}</span>
                    </div>
                    <div class="rounded-lg bg-amber-50 border border-amber-200/80 p-2.5 text-center">
                        <span class="block text-[10px] uppercase font-bold text-amber-700">Belum Diatur</span>
                        <span class="text-sm font-extrabold text-amber-800">{{ $folderStats['unconfigured_count'] ?? 0 }}</span>
                    </div>
                    <div class="rounded-lg bg-blue-50 border border-blue-200/80 p-2.5 text-center">
                        <span class="block text-[10px] uppercase font-bold text-blue-700">Folder Bersama (Shared)</span>
                        <span class="text-sm font-extrabold text-blue-800">{{ $folderStats['shared_count'] ?? 0 }}</span>
                    </div>
                </div>

                <!-- Panel Daftar yang Dapat Dibuka / Ditutup -->
                <div x-show="isOpen" x-transition.opacity.duration.200ms class="space-y-4 pt-2 border-t border-slate-100" style="display: none;">
                    <!-- Tabel Ringkas Folder Per Brand (5 per halaman) -->
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600 border-b border-slate-200">
                                    <tr>
                                        <th class="py-2.5 px-3.5">Brand</th>
                                        <th class="py-2.5 px-3.5">Status Folder</th>
                                        <th class="py-2.5 px-3.5">Folder Google Drive (ID)</th>
                                        <th class="py-2.5 px-3.5 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse (($brandFolderItems ?? []) as $index => $item)
                                        <tr
                                            x-show="page === (Math.floor({{ $index }} / perPage) + 1)"
                                            class="h-[46px] hover:bg-slate-50/70 transition"
                                        >
                                            <td class="py-2.5 px-3.5">
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex h-6 w-8 shrink-0 items-center justify-center rounded bg-slate-100 text-[10px] font-bold font-mono text-slate-700 border border-slate-200">
                                                        {{ $item['brand_code'] }}
                                                    </span>
                                                    <div>
                                                        <span class="font-bold text-slate-800">{{ $item['brand_name'] }}</span>
                                                        @if ($item['is_default'])
                                                            <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.2 text-[9px] font-bold text-emerald-800 border border-emerald-200">Default</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2.5 px-3.5">
                                                @if ($item['is_drive_connected'])
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                        Terpasang
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 border border-slate-200">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                                        Belum Diatur
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="py-2.5 px-3.5">
                                                @if ($item['is_drive_connected'])
                                                    <div class="flex flex-wrap items-center gap-1.5" x-data="{ copied: false }">
                                                        <span class="font-mono text-[11px] font-semibold text-slate-800 bg-slate-100 px-2 py-0.5 rounded border border-slate-200 max-w-[200px] truncate" title="{{ $item['drive_folder_id'] }}">
                                                            {{ $item['drive_folder_id'] }}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            @click="navigator.clipboard.writeText('{{ $item['drive_folder_id'] }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                            class="p-1 rounded text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                                                            :title="copied ? 'Tersalin!' : 'Salin Folder ID'"
                                                        >
                                                            <svg x-show="!copied" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                                <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                                                                <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                                                            </svg>
                                                            <svg x-show="copied" class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                                                <polyline points="20 6 9 17 4 12"></polyline>
                                                            </svg>
                                                        </button>
                                                        @if (!empty($item['drive_folder_url']))
                                                            <a
                                                                href="{{ $item['drive_folder_url'] }}"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                class="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 hover:text-blue-800 hover:underline ml-1"
                                                                title="Buka Folder di Google Drive (Tab Baru)"
                                                            >
                                                                <span>Buka</span>
                                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                                                    <polyline points="15 3 21 3 21 9"></polyline>
                                                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                                                </svg>
                                                            </a>
                                                        @endif
                                                        @if ($item['is_shared'])
                                                            <span class="inline-flex items-center gap-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 border border-blue-200" title="Folder ini digunakan bersama oleh {{ $item['shared_count'] }} brand">
                                                                <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                                                </svg>
                                                                Shared ({{ $item['shared_count'] }} Brand)
                                                            </span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-slate-400 italic text-[11px]">Belum dikonfigurasi</span>
                                                @endif
                                            </td>
                                            <td class="py-2.5 px-3.5 text-right whitespace-nowrap">
                                                <a
                                                    href="{{ $item['edit_url'] }}"
                                                    class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-800 hover:text-amber-950 hover:underline"
                                                >
                                                    <span>{{ $item['is_drive_connected'] ? 'Kelola' : 'Atur Folder' }}</span>
                                                    <span>&rarr;</span>
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-4 text-center text-slate-500 italic">
                                                Belum ada data brand terdaftar.
                                            </td>
                                        </tr>
                                    @endforelse

                                    <!-- Slot Kosong Pelengkap (Selalu Mengisi 5 Baris agar Tinggi Tabel Tetap Stabil saat Navigasi Halaman) -->
                                    <template x-for="i in emptyRows" :key="'placeholder-' + page + '-' + i">
                                        <tr class="h-[46px] select-none pointer-events-none bg-slate-50/20">
                                            <td class="py-2.5 px-3.5">
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex h-6 w-8 shrink-0 items-center justify-center rounded border border-dashed border-slate-200 bg-slate-50 text-[10px] font-mono text-slate-300">—</span>
                                                    <span class="text-[11px] text-slate-300 italic">—</span>
                                                </div>
                                            </td>
                                            <td class="py-2.5 px-3.5">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100/50 px-2 py-0.5 text-[11px] text-slate-300 border border-dashed border-slate-200">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-slate-200"></span>
                                                    —
                                                </span>
                                            </td>
                                            <td class="py-2.5 px-3.5">
                                                <span class="text-[11px] text-slate-300 font-mono">—</span>
                                            </td>
                                            <td class="py-2.5 px-3.5 text-right">
                                                <span class="text-[11px] text-slate-300 font-mono">—</span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bar Pagination Per 5 Brand -->
                        <template x-if="totalPages > 1">
                            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 bg-slate-50/70 border-t border-slate-200 text-xs">
                                <div class="text-slate-500">
                                    Menampilkan <span class="font-semibold text-slate-800" x-text="(page - 1) * perPage + 1"></span> s/d <span class="font-semibold text-slate-800" x-text="Math.min(page * perPage, total)"></span> dari <span class="font-semibold text-slate-800" x-text="total"></span> brand
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        @click="prevPage()"
                                        :disabled="page === 1"
                                        class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                    >
                                        &larr; Sebelumnya
                                    </button>

                                    <div class="flex items-center gap-1">
                                        <template x-for="p in totalPages" :key="p">
                                            <button
                                                type="button"
                                                @click="goToPage(p)"
                                                :class="page === p ? 'bg-amber-600 text-white font-bold border-amber-600 shadow-2xs' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-100'"
                                                class="min-w-[28px] h-7 rounded-lg border text-xs font-semibold transition flex items-center justify-center px-1.5"
                                                x-text="p"
                                            ></button>
                                        </template>
                                    </div>

                                    <button
                                        type="button"
                                        @click="nextPage()"
                                        :disabled="page === totalPages"
                                        class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                    >
                                        Selanjutnya &rarr;
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Info Sinkronisasi Folder Bersama (Shared Folder) -->
                    <p class="text-[11px] text-slate-500 flex items-start gap-1.5">
                        <svg class="h-3.5 w-3.5 text-slate-400 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <span><strong>Catatan Sinkronisasi:</strong> Folder penyimpanan dapat diatur unik per brand atau digunakan bersama (*Shared*). Jika beberapa brand menggunakan Folder ID yang sama, dokumen dari masing-masing brand tersebut otomatis tersimpan di folder Google Drive yang sama.</span>
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-100">
                <div class="text-xs text-slate-500">
                    Target Root Aktif: <strong class="font-mono text-slate-700">{{ $settings->canonical_parent_folder_id ?: 'Belum diotorisasi' }}</strong>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="rounded-lg bg-blue-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-blue-700"
                    >
                        Simpan Konfigurasi Penyimpanan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
