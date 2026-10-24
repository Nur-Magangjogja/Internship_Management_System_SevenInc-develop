@extends('layouts.dashboard', ['title' => 'Pengaturan Penyimpanan Cloud'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Pusat Pengaturan Penyimpanan Asset</h1>
            <p class="text-sm text-slate-500">Konfigurasi penyimpanan Google Drive untuk seluruh aset sistem, template dokumen, dan berkas pendaftaran melalui Google Apps Script Gateway.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <form action="{{ route('admin.cloud-storage.test') }}" method="POST">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                    title="Uji koneksi read-only via drive_ping tanpa memutasi data Google Drive"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Uji Koneksi &amp; Izin Folder (Read-Only)
                </button>
            </form>

            <form action="{{ route('admin.cloud-storage.activate-root') }}" method="POST" onsubmit="return confirm('Otorisasi dan aktifkan folder induk Google Drive ini secara resmi pada Script Properties Google Apps Script Gateway? Peringatan: Seluruh antrean upload yang pending akan dialihkan ke root baru setelah verifikasi.');">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                    title="Otorisasi target root folder resmi pada Google Apps Script Gateway"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    Aktifkan Folder Root (Otorisasi)
                </button>
            </form>

            <a
                href="{{ route('admin.system-assets.index') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect width="20" height="5" x="2" y="3" rx="1"/>
                    <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/>
                    <path d="M10 12h4"/>
                </svg>
                Perpustakaan Aset
            </a>

            <a
                href="{{ route('admin.spreadsheet-connections.index') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
                    <line x1="3" y1="9" x2="21" y2="9"/>
                    <line x1="9" y1="21" x2="9" y2="9"/>
                </svg>
                Integrasi Spreadsheet
            </a>
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

            <!-- Single Canonical Parent Root Configuration -->
            <div>
                <h2 class="text-base font-bold text-slate-800">Folder Induk Tunggal Google Drive (Kanonikal)</h2>
                <p class="text-xs text-slate-500 mt-0.5">Satu-satunya folder induk yang diverifikasi. Seluruh folder berkas (REGISTRASI, SYSTEM-ASSETS, GENERATED-DOCUMENTS) otomatis dibuat secara otomatis (*lazy-create*) di bawah folder ini.</p>
            </div>

            <div class="space-y-2 rounded-xl border-2 {{ $settings->is_verified ? 'border-emerald-300 bg-emerald-50/20' : ($settings->canonical_parent_folder_id ? 'border-amber-300 bg-amber-50/20' : 'border-slate-200 bg-slate-50/50') }} p-4">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Folder Induk Google Drive (URL atau Folder ID) <span class="text-rose-500">*</span>
                </label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        type="text"
                        name="canonical_parent_folder_url"
                        value="{{ old('canonical_parent_folder_url', $settings->canonical_parent_folder_url ?: $settings->canonical_parent_folder_id) }}"
                        class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 font-mono"
                        placeholder="Contoh: https://drive.google.com/drive/folders/1a2B3c... atau 1a2B3c..."
                    />
                    @if ($settings->canonical_parent_folder_id)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-xs font-mono font-bold text-slate-700">
                            ID: {{ $settings->canonical_parent_folder_id }}
                        </span>
                    @endif
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

            <!-- Detail Gateway Google Apps Script -->
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-xs space-y-2">
                <h3 class="font-bold text-slate-800 uppercase tracking-wider">Konfigurasi Gateway Apps Script</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                    <div>
                        <span class="text-slate-500 block">Web App Endpoint URL:</span>
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

            <!-- Referensi Folder Registrasi Pemagang (Read Only) -->
            <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-amber-900">
                            Referensi Folder Registrasi Pemagang (Read-Only)
                        </span>
                        <p class="mt-1 text-xs text-amber-800">
                            Dikonfigurasi melalui modul <strong>Integrasi Spreadsheet (Registration Connection)</strong>. Dokumen individu pemagang (LOA, SKL, sertifikat) otomatis tersimpan ke subfolder pemagang di dalam folder ini.
                        </p>
                    </div>
                    <a
                        href="{{ route('admin.spreadsheet-connections.index') }}"
                        class="text-xs font-bold text-amber-700 underline hover:text-amber-900"
                    >
                        Ubah di Spreadsheet
                    </a>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <span class="rounded bg-amber-100 px-2 py-1 text-xs font-mono font-bold text-amber-900">
                        {{ $registrationRef['folder_id'] ?: 'Belum diatur' }}
                    </span>
                    @if ($registrationRef['folder_url'])
                        <a href="{{ $registrationRef['folder_url'] }}" target="_blank" class="text-xs text-blue-600 hover:underline">
                            Buka di Google Drive &rarr;
                        </a>
                    @endif
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
