@extends('layouts.dashboard', ['title' => 'Impor Data Historis & Pendaftar Lama'])

@section('content')
<div class="px-6 py-8">
    <!-- Header Halaman -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-800">SERTIF V1.3</span>
                <span class="text-xs text-slate-400">&bull;</span>
                <span class="text-xs text-slate-500">Pipeline Impor Universal</span>
            </div>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Impor Data Pendaftaran Historis</h1>
            <p class="text-sm text-slate-500">
                Unggah berkas XLSX/CSV atau hubungkan Google Spreadsheet lama secara read-only. Sistem mendukung pemetaan field fleksibel, penambahan field historis nonaktif, filter tanggal pengajuan, dan perutean tab tahun otomatis tanpa duplikasi data.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a
                href="{{ route('admin.legacy-import.template') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span>Unduh Template CSV</span>
            </a>
        </div>
    </div>

    <!-- Alert Notifikasi -->
    @if (session('success'))
        <div class="mb-6 flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">
            <div class="flex items-center gap-3">
                <svg class="h-5 w-5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <p class="font-medium">{{ session('success') }}</p>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 flex items-center justify-between rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
            <div class="flex items-center gap-3">
                <svg class="h-5 w-5 shrink-0 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <p class="font-medium">{{ session('error') }}</p>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    @endif

    <!-- Wizard Tahap 1: Pemilihan Sumber Data -->
    <div class="mb-8 rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 bg-slate-50/75 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-800">Langkah 1: Tentukan Sumber Data</h2>
                    <p class="text-xs text-slate-500">Pilih metode input data pendaftar historis.</p>
                </div>
                <!-- Tab Tombol -->
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-100 p-1">
                    <button
                        type="button"
                        id="tab-btn-file"
                        onclick="switchSourceTab('file')"
                        class="rounded-md bg-white px-4 py-1.5 text-xs font-semibold text-slate-800 shadow-xs transition"
                    >
                        Unggah Berkas (XLSX / CSV)
                    </button>
                    <button
                        type="button"
                        id="tab-btn-gsheet"
                        onclick="switchSourceTab('gsheet')"
                        class="rounded-md px-4 py-1.5 text-xs font-semibold text-slate-600 transition hover:text-slate-800"
                    >
                        Google Sheets Lama (Read-Only)
                    </button>
                </div>
            </div>
        </div>

        <div class="p-6">
            <!-- Form Sumber 1: Unggah Berkas Fisik -->
            <div id="source-panel-file">
                <form id="file-upload-form" onsubmit="handleFilePreview(event)" class="space-y-4">
                    @csrf
                    <div>
                        <label for="import_file" class="block text-xs font-semibold text-slate-700">
                            Pilih Berkas Spreadsheet (.xlsx, .csv)
                        </label>
                        <div class="mt-1 flex items-center justify-center rounded-lg border-2 border-dashed border-slate-300 px-6 py-8 hover:border-slate-400 transition bg-slate-50/50">
                            <div class="text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="12" y1="18" x2="12" y2="12"/>
                                    <line x1="9" y1="15" x2="12" y2="12"/>
                                    <line x1="15" y1="15" x2="12" y2="12"/>
                                </svg>
                                <div class="mt-3 flex text-xs text-slate-600 justify-center">
                                    <label for="import_file" class="relative cursor-pointer rounded-md font-semibold text-blue-600 hover:text-blue-500 focus-within:outline-none">
                                        <span>Pilih berkas</span>
                                        <input id="import_file" name="import_file" type="file" accept=".xlsx,.csv,.txt" class="sr-only" required onchange="handleFileSelected(this)">
                                    </label>
                                    <p class="pl-1 text-slate-500">atau seret dan lepas di sini</p>
                                </div>
                                <p id="selected-file-name" class="mt-1 text-xs font-medium text-slate-700"></p>
                                <p class="text-[11px] text-slate-400">Maksimum ukuran 15 MB. Format: XLSX (Excel), CSV (Comma/Semicolon Separated).</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <span class="text-xs text-slate-500">
                            Berkas akan dianalisis strukturnya tanpa mengubah database.
                        </span>
                        <button
                            type="submit"
                            id="btn-analyze-file"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-800 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-700 disabled:opacity-50"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <span>Pratinjau &amp; Analisis Berkas</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Form Sumber 2: Google Sheets Read-Only -->
            <div id="source-panel-gsheet" class="hidden">
                <form id="gsheet-inspect-form" onsubmit="handleGSheetInspect(event)" class="space-y-4">
                    @csrf
                    <div>
                        <label for="spreadsheet_input" class="block text-xs font-semibold text-slate-700">
                            URL Lengkap atau ID Google Spreadsheet Lama
                        </label>
                        <input
                            type="text"
                            id="spreadsheet_input"
                            name="spreadsheet_input"
                            placeholder="https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit... atau masukkan ID Spreadsheet"
                            required
                            class="mt-1 block w-full rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-blue-500 focus:bg-white focus:outline-none"
                        >
                        <p class="mt-1 text-[11px] text-slate-500">
                            Contoh ID: <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-[11px] text-slate-700">1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms</code>. Pastikan Apps Script memiliki izin baca pada file ini.
                        </p>
                    </div>

                    <div id="gsheet-tab-select-group" class="hidden">
                        <label for="gsheet_tab_name" class="block text-xs font-semibold text-slate-700">
                            Pilih Tab / Lembar Kerja
                        </label>
                        <select
                            id="gsheet_tab_name"
                            name="tab_name"
                            class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                            <!-- Diisi dinamis lewat API -->
                        </select>
                    </div>

                    <div class="rounded-lg border border-blue-100 bg-blue-50/60 p-3.5 text-xs text-blue-900 flex items-start gap-2.5">
                        <svg class="h-4 w-4 shrink-0 text-blue-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <div>
                            <span class="font-bold">Keamanan Integrasi Read-Only:</span>
                            Data dibaca secara strictly read-only melalui gateway <code class="font-mono text-blue-800 font-semibold">SertifGoogleIntegration</code>. Akses tidak akan menulis, mengubah struktur header, atau menghapus baris pada sheet sumber.
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <span class="text-xs text-slate-500">
                            Analisis data lembar kerja untuk memetakan kolom.
                        </span>
                        <button
                            type="submit"
                            id="btn-analyze-gsheet"
                            class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-600 disabled:opacity-50"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <span id="btn-analyze-gsheet-text">Periksa Lembar Kerja Google</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Wizard Tahap 2: Pemetaan Kolom, Filter Waktu, dan Validasi (Muncul setelah analisis berhasil) -->
    <div id="mapping-container" class="hidden mb-8 space-y-6">
        <!-- Kartu Ringkasan Sumber -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-100">
                <div>
                    <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 border border-emerald-200">
                        Sumber Berhasil Dianalisis
                    </span>
                    <h2 id="summary-source-name" class="mt-2 text-lg font-bold text-slate-800">-</h2>
                    <p class="text-xs text-slate-500">
                        Tipe: <span id="summary-source-type" class="font-semibold text-slate-700">-</span> &bull;
                        Total Baris Terdeteksi: <span id="summary-total-rows" class="font-bold text-blue-600">-</span> &bull;
                        Kolom Header: <span id="summary-total-cols" class="font-semibold text-slate-700">-</span>
                    </p>
                </div>
                <div>
                    <button
                        type="button"
                        onclick="resetMapping()"
                        class="rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-600 shadow-2xs hover:bg-slate-50 transition"
                    >
                        Ganti Sumber Berkas
                    </button>
                </div>
            </div>

            <!-- Pengaturan Filter Tanggal & Status Magang Bawaan -->
            <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Status Magang Default -->
                <div>
                    <label for="default_status" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                        Status Magang Bawaan
                    </label>
                    <p class="text-[11px] text-slate-400 mb-1.5">Untuk baris yang kolom statusnya kosong atau belum terpetakan.</p>
                    <select
                        id="default_status"
                        class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
                    >
                        <option value="waiting" selected>Waiting (Menunggu Review / Belum Diverifikasi)</option>
                        <option value="accepted">Accepted (Diterima Magang)</option>
                        <option value="active">Active (Sedang Aktif Magang)</option>
                        <option value="completed">Completed (Selesai Magang / Alumni)</option>
                        <option value="exited">Exited (Keluar / Mengundurkan Diri)</option>
                        <option value="rejected">Rejected (Ditolak)</option>
                    </select>
                </div>

                <!-- Filter Tanggal Mulai -->
                <div>
                    <label for="start_date_filter" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                        Filter Tanggal Mulai (Opsional)
                    </label>
                    <p class="text-[11px] text-slate-400 mb-1.5">Hanya impor data pengajuan pada atau setelah tanggal ini.</p>
                    <input
                        type="date"
                        id="start_date_filter"
                        class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
                    >
                </div>

                <!-- Filter Tanggal Selesai -->
                <div>
                    <label for="end_date_filter" class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                        Filter Tanggal Selesai (Opsional)
                    </label>
                    <p class="text-[11px] text-slate-400 mb-1.5">Hanya impor data pengajuan hingga tanggal ini.</p>
                    <input
                        type="date"
                        id="end_date_filter"
                        class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
                    >
                </div>
            </div>
        </div>

        <!-- Tabel Konfigurasi Pemetaan Header Kolom -->
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-slate-100 bg-slate-50/50 px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Langkah 2: Pemetaan Kolom Sumber</h3>
                    <p class="text-xs text-slate-500">
                        Tentukan tindakan untuk setiap kolom yang ditemukan pada sumber data.
                    </p>
                </div>
                <div class="text-xs text-slate-500">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span> Petakan ke Field SERTIF
                    </span>
                    <span class="inline-flex items-center gap-1.5 ml-3">
                        <span class="h-2 w-2 rounded-full bg-amber-500"></span> Simpan sebagai Field Historis Nonaktif
                    </span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="px-5 py-3 w-12">#</th>
                            <th class="px-5 py-3 w-1/4">Header Sumber &amp; Sampel Nilai</th>
                            <th class="px-5 py-3 w-1/4">Pilihan Aksi Pemetaan</th>
                            <th class="px-5 py-3">Konfigurasi Target Field / Field Historis</th>
                        </tr>
                    </thead>
                    <tbody id="mapping-table-body" class="divide-y divide-slate-100 font-normal">
                        <!-- Diisi dinamis oleh JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Footer Aksi Muat ke Staging -->
            <div class="border-t border-slate-200 bg-slate-50/75 px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="text-xs text-slate-500">
                    <p class="font-medium text-slate-700">&bull; Data akan divalidasi ke tabel Staging terlebih dahulu.</p>
                    <p class="text-[11px]">Anda dapat meninjau duplikasi peristiwa, kontak outsider, dan status sebelum melakukan commit permanen ke database.</p>
                </div>
                <div>
                    <button
                        type="button"
                        id="btn-submit-staging"
                        onclick="submitStaging()"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-6 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-500 disabled:opacity-50"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <span id="btn-submit-staging-text">Muat ke Staging &amp; Verifikasi Validasi</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Riwayat Sesi Batch Impor Terkini -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-100 px-6 py-4 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-800">Riwayat Sesi Impor Historis</h3>
                <p class="text-xs text-slate-500">Daftar batch yang pernah dimuat atau dikomit ke database.</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3">Batch ID</th>
                        <th class="px-5 py-3">Sumber Data</th>
                        <th class="px-5 py-3">Nama Berkas / Target Tab</th>
                        <th class="px-5 py-3">Waktu Pembuatan</th>
                        <th class="px-5 py-3">Statistik Baris</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($recentBatches as $batch)
                        <tr class="hover:bg-slate-50/75 transition">
                            <td class="px-5 py-3.5 font-mono text-xs font-semibold text-slate-800">
                                #{{ $batch->id }}
                            </td>
                            <td class="px-5 py-3.5">
                                @if ($batch->source_type === 'xlsx')
                                    <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700 border border-emerald-200">
                                        XLSX (Excel)
                                    </span>
                                @elseif ($batch->source_type === 'csv')
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700 border border-slate-200">
                                        CSV
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-bold text-blue-700 border border-blue-200">
                                        Google Sheets (Read-Only)
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-xs font-medium text-slate-800 max-w-xs truncate" title="{{ $batch->source_filename }}">
                                {{ $batch->source_filename }}
                                @if ($batch->source_tab_name)
                                    <span class="block text-[11px] font-normal text-slate-400">Tab: {{ $batch->source_tab_name }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-500">
                                {{ $batch->created_at?->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-5 py-3.5 text-xs">
                                <span class="font-semibold text-slate-800">{{ $batch->total_rows_detected }}</span> terdeteksi
                                @if ($batch->total_rows_imported > 0)
                                    <span class="text-emerald-600 font-semibold">&bull; +{{ $batch->total_rows_imported }} tersimpan</span>
                                @endif
                                @if ($batch->total_rows_skipped > 0)
                                    <span class="text-blue-600 font-medium">&bull; {{ $batch->total_rows_skipped }} duplikat</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                @if ($batch->status === 'committed')
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                        &check; Committed
                                    </span>
                                @elseif ($batch->status === 'staged')
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                                        Staged (Menunggu Review)
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800">
                                        Gagal
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-right text-xs">
                                <a
                                    href="{{ route('admin.legacy-import.batch', $batch->id) }}"
                                    class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-800"
                                >
                                    <span>{{ $batch->status === 'committed' ? 'Lihat Detail' : 'Review &amp; Komit &rarr;' }}</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-xs text-slate-400">
                                Belum ada riwayat sesi impor data historis.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JavaScript Interaktif Wizard Impor -->
<script>
    // State global sesi wizard
    let currentSourceState = {
        source_type: 'xlsx',
        temp_path: null,
        source_filename: null,
        spreadsheet_id: null,
        tab_name: null,
        headers: [],
        sample_rows: [],
        total_rows: 0
    };

    // Daftar field core SERTIF yang dikenali
    const CORE_SERTIF_FIELDS = [
        { key: 'fullname', label: 'Nama Lengkap (fullname)' },
        { key: 'email', label: 'Email Pendaftar (email)' },
        { key: 'student_id', label: 'NIM / NIS (student_id)' },
        { key: 'phone_number', label: 'No. HP / WhatsApp (phone_number)' },
        { key: 'institution_name', label: 'Asal Kampus / Sekolah (institution_name)' },
        { key: 'faculty', label: 'Fakultas (faculty)' },
        { key: 'study_program', label: 'Program Studi / Jurusan (study_program)' },
        { key: 'gender', label: 'Jenis Kelamin (gender)' },
        { key: 'internship_type', label: 'Jenis Magang (internship_type)' },
        { key: 'current_status', label: 'Status Saat Ini (current_status)' },
        { key: 'wfo_readiness', label: 'Kesiapan WFO (wfo_readiness)' },
        { key: 'schedule_shift_readiness', label: 'Kesiapan Shifting (schedule_shift_readiness)' },
        { key: 'created_at', label: 'Timestamp Pengajuan (created_at / timestamp)' },
        { key: 'status', label: 'Status Magang Terverifikasi (status)' }
    ];

    function switchSourceTab(type) {
        const btnFile = document.getElementById('tab-btn-file');
        const btnGSheet = document.getElementById('tab-btn-gsheet');
        const panelFile = document.getElementById('source-panel-file');
        const panelGSheet = document.getElementById('source-panel-gsheet');

        if (type === 'file') {
            btnFile.className = 'rounded-md bg-white px-4 py-1.5 text-xs font-semibold text-slate-800 shadow-xs transition';
            btnGSheet.className = 'rounded-md px-4 py-1.5 text-xs font-semibold text-slate-600 transition hover:text-slate-800';
            panelFile.classList.remove('hidden');
            panelGSheet.classList.add('hidden');
        } else {
            btnGSheet.className = 'rounded-md bg-white px-4 py-1.5 text-xs font-semibold text-slate-800 shadow-xs transition';
            btnFile.className = 'rounded-md px-4 py-1.5 text-xs font-semibold text-slate-600 transition hover:text-slate-800';
            panelGSheet.classList.remove('hidden');
            panelFile.classList.add('hidden');
        }
    }

    function handleFileSelected(input) {
        const label = document.getElementById('selected-file-name');
        if (input.files && input.files[0]) {
            label.textContent = 'Berkas dipilih: ' + input.files[0].name + ' (' + (input.files[0].size / 1024 / 1024).toFixed(2) + ' MB)';
        } else {
            label.textContent = '';
        }
    }

    // Handle AJAX Preview Unggah Berkas
    async function handleFilePreview(e) {
        e.preventDefault();
        const fileInput = document.getElementById('import_file');
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Silakan pilih berkas terlebih dahulu.');
            return;
        }

        const btn = document.getElementById('btn-analyze-file');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span>Menganalisis berkas...</span>';

        const formData = new FormData();
        formData.append('import_file', fileInput.files[0]);
        formData.append('_token', '{{ csrf_token() }}');

        try {
            const resp = await fetch('{{ route("admin.legacy-import.preview") }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.message || 'Gagal menganalisis berkas.');
            }

            currentSourceState.source_type = data.source_type;
            currentSourceState.temp_path = data.temp_path;
            currentSourceState.source_filename = data.source_filename;
            currentSourceState.headers = data.headers || [];
            currentSourceState.sample_rows = data.sample_rows || [];
            currentSourceState.total_rows = data.total_rows || 0;

            renderMappingInterface();
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // Handle AJAX Inspeksi Google Sheets
    async function handleGSheetInspect(e) {
        e.preventDefault();
        const rawInput = document.getElementById('spreadsheet_input').value.trim();
        const tabSelect = document.getElementById('gsheet_tab_name');
        const tabGroup = document.getElementById('gsheet-tab-select-group');
        const btn = document.getElementById('btn-analyze-gsheet');
        const btnText = document.getElementById('btn-analyze-gsheet-text');

        if (!rawInput) {
            alert('Masukkan tautan atau ID Google Spreadsheet.');
            return;
        }

        btn.disabled = true;
        btnText.textContent = 'Memeriksa Google Sheets...';

        const payload = {
            spreadsheet_input: rawInput,
            tab_name: tabSelect.value || null,
            _token: '{{ csrf_token() }}'
        };

        try {
            const resp = await fetch('{{ route("admin.legacy-import.preview-google-sheet") }}', {
                method: 'POST',
                body: JSON.stringify(payload),
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.message || 'Gagal memeriksa Google Sheets.');
            }

            // Jika perlu memilih tab terlebih dahulu
            if (data.needs_tab_select && data.tabs) {
                tabSelect.innerHTML = '';
                data.tabs.forEach(tab => {
                    const opt = document.createElement('option');
                    opt.value = tab.name;
                    opt.textContent = tab.name + ' (' + tab.last_row + ' baris)';
                    tabSelect.appendChild(opt);
                });
                tabGroup.classList.remove('hidden');
                btnText.textContent = 'Lanjutkan Analisis Tab Terpilih';
                btn.disabled = false;
                return;
            }

            currentSourceState.source_type = 'google_sheets_readonly';
            currentSourceState.spreadsheet_id = data.spreadsheet_id;
            currentSourceState.tab_name = data.tab_name || tabSelect.value;
            currentSourceState.source_filename = (data.spreadsheet_name || data.spreadsheet_id) + ' [' + currentSourceState.tab_name + ']';
            currentSourceState.headers = data.headers || [];
            currentSourceState.sample_rows = data.sample_rows || [];
            currentSourceState.total_rows = data.total_rows || 0;

            renderMappingInterface();
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            btn.disabled = false;
            btnText.textContent = 'Periksa Lembar Kerja Google';
        }
    }

    // Render antarmuka pemetaan kolom dinamis
    function renderMappingInterface() {
        document.getElementById('summary-source-name').textContent = currentSourceState.source_filename;
        document.getElementById('summary-source-type').textContent = currentSourceState.source_type.toUpperCase();
        document.getElementById('summary-total-rows').textContent = Number(currentSourceState.total_rows).toLocaleString();
        document.getElementById('summary-total-cols').textContent = currentSourceState.headers.length + ' kolom';

        const tbody = document.getElementById('mapping-table-body');
        tbody.innerHTML = '';

        currentSourceState.headers.forEach((header, idx) => {
            if (!header) return;

            const tr = document.createElement('tr');
            tr.className = 'hover:bg-slate-50/75 transition';

            // Sampel baris 1-3
            const samples = currentSourceState.sample_rows
                .map(r => r[idx])
                .filter(val => val !== null && val !== undefined && val !== '')
                .slice(0, 3);
            const sampleText = samples.length > 0 ? samples.join(' | ') : 'Tidak ada sampel nilai';

            // Auto-detect kecocokan field SERTIF
            const detectedField = guessCoreField(header);

            tr.innerHTML = `
                <td class="px-5 py-3 font-mono text-xs text-slate-400">${idx + 1}</td>
                <td class="px-5 py-3 text-xs">
                    <span class="font-bold text-slate-800">${escapeHtml(header)}</span>
                    <p class="text-[11px] text-slate-400 font-mono mt-0.5 truncate max-w-xs" title="${escapeHtml(sampleText)}">
                        Sampel: ${escapeHtml(sampleText)}
                    </p>
                </td>
                <td class="px-5 py-3 text-xs">
                    <div class="space-y-1.5">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="action_${idx}" value="ignore" ${!detectedField ? 'checked' : ''} onchange="toggleMappingRow(${idx}, 'ignore')">
                            <span class="text-slate-600">Abaikan Kolom</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="action_${idx}" value="map_existing" ${detectedField ? 'checked' : ''} onchange="toggleMappingRow(${idx}, 'map_existing')">
                            <span class="text-emerald-700 font-medium">Petakan ke Field SERTIF</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="action_${idx}" value="historical_only" onchange="toggleMappingRow(${idx}, 'historical_only')">
                            <span class="text-amber-700 font-medium">Field Historis Nonaktif</span>
                        </label>
                    </div>
                </td>
                <td class="px-5 py-3 text-xs" id="target_cell_${idx}">
                    <!-- Dinamis via toggleMappingRow -->
                </td>
            `;

            tbody.appendChild(tr);

            // Inisialisasi tampilan target awal
            if (detectedField) {
                toggleMappingRow(idx, 'map_existing', detectedField);
            } else {
                toggleMappingRow(idx, 'ignore');
            }
        });

        document.getElementById('mapping-container').classList.remove('hidden');
        document.getElementById('mapping-container').scrollIntoView({ behavior: 'smooth' });
    }

    // Ubah tampilan kontrol konfigurasi pada kolom
    function toggleMappingRow(idx, action, defaultTarget = null) {
        const cell = document.getElementById(`target_cell_${idx}`);
        const header = currentSourceState.headers[idx];

        if (action === 'ignore') {
            cell.innerHTML = '<span class="text-slate-400 italic">Kolom ini akan dilewati dan tidak disimpan.</span>';
        } else if (action === 'map_existing') {
            let optionsHtml = CORE_SERTIF_FIELDS.map(f => {
                const selected = (defaultTarget && defaultTarget === f.key) ? 'selected' : '';
                return `<option value="${f.key}" ${selected}>${f.label}</option>`;
            }).join('');

            cell.innerHTML = `
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Target Field Inti SERTIF:</label>
                    <select id="target_field_${idx}" class="block w-full rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-emerald-500 focus:outline-none">
                        ${optionsHtml}
                    </select>
                </div>
            `;
        } else if (action === 'historical_only') {
            const slug = generateFieldKey(header);
            cell.innerHTML = `
                <div class="space-y-2 bg-amber-50/50 p-3 rounded-lg border border-amber-200">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[10px] font-bold text-amber-900 uppercase">Label Kanonikal:</label>
                            <input type="text" id="hist_label_${idx}" value="${escapeHtml(header)}" class="mt-0.5 block w-full rounded border border-amber-300 bg-white px-2 py-1 text-xs">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-amber-900 uppercase">Tipe Data:</label>
                            <select id="hist_type_${idx}" class="mt-0.5 block w-full rounded border border-amber-300 bg-white px-2 py-1 text-xs">
                                <option value="string" selected>Teks Singkat (string)</option>
                                <option value="text">Teks Panjang (text)</option>
                                <option value="number">Angka (number)</option>
                                <option value="date">Tanggal (date)</option>
                                <option value="boolean">Ya/Tidak (boolean)</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-amber-900 uppercase">Field Key Sistem (Permanen):</label>
                        <input type="text" id="hist_key_${idx}" value="${slug}" class="mt-0.5 block w-full rounded border border-amber-300 bg-amber-100/50 px-2 py-1 font-mono text-[11px] text-amber-900" readonly>
                        <p class="text-[10px] text-amber-700 mt-0.5">Field ini akan tersimpan di database dan sinkron ke spreadsheet tanpa mengubah form publik.</p>
                    </div>
                </div>
            `;
        }
    }

    // Heuristik pencocokan field core
    function guessCoreField(header) {
        const h = header.toLowerCase().replace(/[^a-z0-9]/g, '');
        if (h.includes('nama') || h.includes('fullname')) return 'fullname';
        if (h.includes('email')) return 'email';
        if (h.includes('nim') || h.includes('nis') || h.includes('nomorinduk')) return 'student_id';
        if (h.includes('wa') || h.includes('hp') || h.includes('telepon') || h.includes('phone') || h.includes('whatsapp')) return 'phone_number';
        if (h.includes('kampus') || h.includes('sekolah') || h.includes('universitas') || h.includes('institusi')) return 'institution_name';
        if (h.includes('prodi') || h.includes('jurusan') || h.includes('programstudi')) return 'study_program';
        if (h.includes('fakultas')) return 'faculty';
        if (h.includes('gender') || h.includes('jeniskelamin')) return 'gender';
        if (h.includes('jenismagang')) return 'internship_type';
        if (h.includes('statussaatini')) return 'current_status';
        if (h.includes('wfo')) return 'wfo_readiness';
        if (h.includes('shift') || h.includes('shifting')) return 'schedule_shift_readiness';
        if (h.includes('timestamp') || h.includes('waktu') || h.includes('tanggaldaftar')) return 'created_at';
        if (h.includes('statusmagang')) return 'status';
        return null;
    }

    function generateFieldKey(header) {
        const clean = header.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        return 'hist_' + (clean || 'field') + '_' + Math.random().toString(36).substring(2, 6);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function resetMapping() {
        document.getElementById('mapping-container').classList.add('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Submit konfigurasi ke endpoint staging
    async function submitStaging() {
        const btn = document.getElementById('btn-submit-staging');
        const btnText = document.getElementById('btn-submit-staging-text');
        btn.disabled = true;
        btnText.textContent = 'Memproses ke staging & memvalidasi...';

        const mappingConfig = [];
        let hasCoreContact = false;

        currentSourceState.headers.forEach((header, idx) => {
            const actionInput = document.querySelector(`input[name="action_${idx}"]:checked`);
            const action = actionInput ? actionInput.value : 'ignore';

            if (action === 'ignore') {
                mappingConfig.push({ header: header, action: 'ignore' });
            } else if (action === 'map_existing') {
                const target = document.getElementById(`target_field_${idx}`).value;
                mappingConfig.push({
                    header: header,
                    action: 'map_existing',
                    target_field: target
                });
                if (target === 'email' || target === 'phone_number' || target === 'fullname') {
                    hasCoreContact = true;
                }
            } else if (action === 'historical_only') {
                const label = document.getElementById(`hist_label_${idx}`).value;
                const type = document.getElementById(`hist_type_${idx}`).value;
                const key = document.getElementById(`hist_key_${idx}`).value;
                mappingConfig.push({
                    header: header,
                    action: 'historical_only',
                    canonical_label: label,
                    data_type: type,
                    field_key: key
                });
            }
        });

        if (!hasCoreContact) {
            if (!confirm('Peringatan: Belum ada kolom nama lengkap atau kontak (Email/No. HP) yang dipetakan ke field SERTIF. Tetap lanjutkan?')) {
                btn.disabled = false;
                btnText.textContent = 'Muat ke Staging & Verifikasi Validasi';
                return;
            }
        }

        const payload = {
            source_type: currentSourceState.source_type,
            column_mapping_config: mappingConfig,
            default_status: document.getElementById('default_status').value,
            start_date_filter: document.getElementById('start_date_filter').value || null,
            end_date_filter: document.getElementById('end_date_filter').value || null,
            _token: '{{ csrf_token() }}'
        };

        if (currentSourceState.source_type === 'google_sheets_readonly') {
            payload.spreadsheet_id = currentSourceState.spreadsheet_id;
            payload.tab_name = currentSourceState.tab_name;
        } else {
            payload.temp_path = currentSourceState.temp_path;
            payload.source_filename = currentSourceState.source_filename;
        }

        try {
            const resp = await fetch('{{ route("admin.legacy-import.stage") }}', {
                method: 'POST',
                body: JSON.stringify(payload),
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.message || 'Gagal memproses batch staging.');
            }

            // Redirect ke halaman detail review batch
            window.location.href = data.redirect;
        } catch (err) {
            alert('Gagal: ' + err.message);
            btn.disabled = false;
            btnText.textContent = 'Muat ke Staging & Verifikasi Validasi';
        }
    }
</script>
@endsection
