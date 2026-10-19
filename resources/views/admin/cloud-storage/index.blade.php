@extends('layouts.dashboard', ['title' => 'Pengaturan Penyimpanan Cloud'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Pusat Pengaturan Penyimpanan Cloud</h1>
            <p class="text-sm text-slate-500">Konfigurasi struktur folder Google Drive untuk seluruh aset sistem, template dokumen, dan hasil generate.</p>
        </div>
        <div class="flex items-center gap-3">
            <form action="{{ route('admin.cloud-storage.test') }}" method="POST">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Uji Koneksi & Izin Folder
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
                Buka Perpustakaan Aset
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
        <!-- Status Koneksi -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status Koneksi</span>
            <div class="mt-2 flex items-center gap-2">
                @if ($settings->connection_status === 'connected')
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        Terhubung
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
                Uji terakhir: {{ $settings->last_tested_at ? $settings->last_tested_at->diffForHumans() : '-' }}
            </p>
        </div>

        <!-- Provider Storage -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Provider Utama</span>
            <div class="mt-2 flex items-center gap-2">
                <svg class="h-5 w-5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
                <p class="text-base font-bold text-slate-800 uppercase">{{ $settings->provider }}</p>
            </div>
            <p class="mt-2 text-xs text-slate-500">Kredensial OAuth2 server (.env)</p>
        </div>

        <!-- Uji Sukses Terakhir -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Uji Sukses Terakhir</span>
            <p class="mt-2 text-base font-bold text-slate-800">
                {{ $settings->last_successful_test_at ? $settings->last_successful_test_at->format('d M Y H:i') : 'Belum pernah' }}
            </p>
            <p class="mt-2 text-xs text-slate-500">Izin baca/tulis terverifikasi</p>
        </div>

        <!-- Sinkronisasi Terakhir -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Sinkronisasi Aset Terakhir</span>
            <p class="mt-2 text-base font-bold text-slate-800">
                {{ $settings->last_synced_at ? $settings->last_synced_at->format('d M Y H:i') : 'Menunggu aksi' }}
            </p>
            <p class="mt-2 text-xs text-slate-500">Status sinkronisasi berkas aktif</p>
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
                    <h3 class="font-bold text-rose-950">Catatan Diagnosa Koneksi</h3>
                    <p class="mt-1 text-xs text-rose-800">{{ $settings->last_error_summary }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Information Box: Separasi Root -->
    <div class="mb-8 rounded-xl border border-sky-200 bg-sky-50/60 p-5 text-sm text-sky-950 shadow-sm">
        <div class="flex items-start gap-3">
            <svg class="h-5 w-5 shrink-0 text-sky-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <div class="space-y-1">
                <p class="font-bold">Ketentuan & Arsitektur Penyimpanan Terpusat:</p>
                <ul class="list-disc pl-4 space-y-1 text-xs text-sky-900">
                    <li><strong>Pemisahan Root:</strong> Root folder aset sistem terpisah dari folder pendaftaran pemagang.</li>
                    <li><strong>Dukungan Input:</strong> Anda dapat memasukkan URL folder Google Drive lengkap (misal: <code>https://drive.google.com/drive/folders/1a2B3c...</code>) atau ID foldernya secara langsung. Sistem akan mengekstrak ID secara otomatis.</li>
                    <li><strong>Keamanan Kredensial:</strong> Client ID, Client Secret, dan Refresh Token tersimpan aman di environment server dan tidak diekspos melalui form ini.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Main Configuration Form -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <form action="{{ route('admin.cloud-storage.update') }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Nama Konfigurasi & Status Aktif -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">Nama Konfigurasi Penyimpanan</label>
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

            <!-- Folders Configuration -->
            <h2 class="text-base font-bold text-slate-800">Pemetaan Folder Google Drive (Root Sistem)</h2>

            <!-- 1. Folder Root System Assets -->
            <div class="space-y-1">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    1. Folder Root Aset Sistem (System Assets)
                </label>
                <p class="text-xs text-slate-500">Tempat penyimpanan logo perusahaan, logo brand, kop surat, tanda tangan pejabat, dan stempel.</p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        type="text"
                        name="system_assets_folder_url"
                        value="{{ old('system_assets_folder_url', $settings->system_assets_folder_url ?: $settings->system_assets_folder_id) }}"
                        class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        placeholder="Masukkan URL Folder Google Drive atau Folder ID"
                    />
                    @if ($settings->system_assets_folder_id)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-xs font-mono text-slate-700">
                            ID: {{ $settings->system_assets_folder_id }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- 2. Folder Root Document Templates -->
            <div class="space-y-1">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    2. Folder Root Template Dokumen (Document Templates)
                </label>
                <p class="text-xs text-slate-500">Folder naskah template MoU, LOA, SKL, dan sertifikat.</p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        type="text"
                        name="document_templates_folder_url"
                        value="{{ old('document_templates_folder_url', $settings->document_templates_folder_url ?: $settings->document_templates_folder_id) }}"
                        class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        placeholder="Masukkan URL Folder Google Drive atau Folder ID"
                    />
                    @if ($settings->document_templates_folder_id)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-xs font-mono text-slate-700">
                            ID: {{ $settings->document_templates_folder_id }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- 3. Folder Root Generated Documents (Non-Pemagang / Institusi / Umum) -->
            <div class="space-y-1">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    3. Folder Root Dokumen Hasil Generate (Generated Documents)
                </label>
                <p class="text-xs text-slate-500">Folder berkas dokumen kemitraan institusi, MoU terbit, dan dokumen umum non-pemagang.</p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        type="text"
                        name="generated_docs_folder_url"
                        value="{{ old('generated_docs_folder_url', $settings->generated_docs_folder_url ?: $settings->generated_docs_folder_id) }}"
                        class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        placeholder="Masukkan URL Folder Google Drive atau Folder ID"
                    />
                    @if ($settings->generated_docs_folder_id)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-xs font-mono text-slate-700">
                            ID: {{ $settings->generated_docs_folder_id }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- 4. Folder Root Archives -->
            <div class="space-y-1">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    4. Folder Root Arsip Tahunan (Archives)
                </label>
                <p class="text-xs text-slate-500">Penyimpanan paket ekspor ZIP arsip administratif tahunan sistem.</p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        type="text"
                        name="archives_folder_url"
                        value="{{ old('archives_folder_url', $settings->archives_folder_url ?: $settings->archives_folder_id) }}"
                        class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        placeholder="Masukkan URL Folder Google Drive atau Folder ID"
                    />
                    @if ($settings->archives_folder_id)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-xs font-mono text-slate-700">
                            ID: {{ $settings->archives_folder_id }}
                        </span>
                    @endif
                </div>
            </div>

            <hr class="border-slate-100" />

            <!-- 5. Referensi Folder Registrasi Pemagang (Read Only) -->
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
            <div class="flex items-center justify-end gap-3 pt-4">
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-blue-700"
                >
                    Simpan Konfigurasi Penyimpanan
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
