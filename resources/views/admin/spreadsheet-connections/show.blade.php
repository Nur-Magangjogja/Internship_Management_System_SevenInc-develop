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
            <div class="{{ $connection->connection_key === 'registration' ? '' : 'md:col-span-2' }}">
                <div class="flex items-center justify-between">
                    <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Target Google Spreadsheet</span>
                    @if ($connection->isSpreadsheetConnected())
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.2 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span> Terhubung
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.2 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                            <span class="h-1 w-1 rounded-full bg-rose-500"></span> Belum Terhubung
                        </span>
                    @endif
                </div>
                @if ($connection->spreadsheet_url)
                    <a href="{{ $connection->spreadsheet_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline font-mono truncate block mt-1">
                        {{ $connection->spreadsheet_url }}
                    </a>
                @else
                    <span class="text-slate-400 italic mt-1 block">Belum dikonfigurasi</span>
                @endif
            </div>
            @if ($connection->connection_key === 'registration')
                <div>
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Folder Google Drive (Berkas Pemagang)</span>
                        @if ($connection->isDriveConnected())
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.2 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="h-1 w-1 rounded-full bg-emerald-500"></span> Terhubung
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.2 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                <span class="h-1 w-1 rounded-full bg-rose-500"></span> Belum Terhubung
                            </span>
                        @endif
                    </div>
                    @if ($connection->drive_folder_url)
                        <a href="{{ $connection->drive_folder_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline font-mono truncate block mt-1">
                            {{ $connection->drive_folder_url }}
                        </a>
                        @if ($connection->drive_folder_id)
                            <span class="text-[10px] font-mono text-slate-500 block mt-0.5">ID: {{ $connection->drive_folder_id }}</span>
                        @endif
                    @else
                        <span class="text-slate-400 italic mt-1 block">Belum dikonfigurasi</span>
                    @endif
                </div>
            @endif
        </div>

        @if ($connection->hasUnconnectedLinks())
            <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between gap-3 text-xs text-amber-900 bg-amber-50/80 -mx-6 -mb-6 p-4 rounded-b-xl border border-amber-200">
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-amber-500 animate-pulse shrink-0"></span>
                    <span><strong>Tanda Peringatan:</strong> Link penghubung Google Drive atau Google Spreadsheet belum terhubung penuh ke database SertIF.</span>
                </div>
                <a href="{{ route('admin.spreadsheet-connections.edit', $connection) }}" class="font-bold text-amber-800 hover:text-amber-950 underline shrink-0">
                    Edit Konfigurasi &rarr;
                </a>
            </div>
        @endif
    </div>


    <!-- Read-Only Universal Spreadsheet Scanner Card (Fase 1) -->
    <!-- Workspace Integrasi & Filterisasi Spreadsheet Universal (Upgrade Universal) -->
    <div class="mb-8 rounded-xl border border-teal-200 bg-white shadow-sm overflow-hidden" id="workspace-section">
        <!-- Top Workspace Bar -->
        <div class="p-6 bg-gradient-to-r from-teal-50/60 via-white to-sky-50/40 border-b border-slate-200 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded bg-teal-100 text-teal-800 border border-teal-200">
                        Universal Spreadsheet Identity Workspace
                    </span>
                    <h2 class="text-lg font-bold text-slate-800">Workspace Integrasi &amp; Rekonsiliasi Universal</h2>
                </div>
                <p class="text-xs text-slate-500 mt-1">
                    Eksplorasi tab, kenali struktur kolom bebas tanpa formula baku, atur status via palet warna atau kolom status, dan sinkronkan data pemagang secara terkontrol.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    id="btn-fetch-tabs"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-50 text-teal-700 hover:bg-teal-100 border border-teal-200 text-xs font-semibold shadow-xs transition"
                >
                    <i class="fas fa-sync-alt text-xs"></i>
                    Pindai Daftar Tab
                </button>
                <button
                    type="button"
                    id="btn-open-composite-modal"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 text-sky-700 hover:bg-sky-100 border border-sky-200 text-xs font-semibold shadow-xs transition"
                    title="Uji coba parser pemisah data kolom gabungan"
                >
                    <i class="fas fa-columns text-xs"></i>
                    Uji Parser Kolom
                </button>
                @if($connection->is_yearly_cloning_enabled)
                    <button
                        type="button"
                        id="btn-open-duplicate-modal"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-cyan-700 text-white hover:bg-cyan-800 text-xs font-semibold shadow-xs transition"
                        title="Duplikasi tab template untuk tahun/batch baru"
                    >
                        <i class="fas fa-clone text-xs"></i>
                        Buat / Clone Tab
                    </button>
                @else
                    <button
                        type="button"
                        disabled
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-200 text-slate-400 text-xs font-semibold cursor-not-allowed shadow-none"
                        title="Mode kloning tab tahunan dinonaktifkan untuk brand ini."
                    >
                        <i class="fas fa-clone text-xs"></i>
                        Buat / Clone Tab (Nonaktif)
                    </button>
                @endif
            </div>
        </div>

        <!-- Guardrail Notice -->
        <div class="px-6 py-3 bg-teal-50/40 border-b border-teal-100 text-xs text-teal-900 flex items-start gap-2.5">
            <i class="fas fa-user-shield text-teal-600 mt-0.5 text-sm shrink-0"></i>
            <div>
                <strong class="text-teal-950">Mekanisme Terkendali (Semi-Otomatis &amp; Universal):</strong>
                Alur integrasi 2 langkah:
                <strong>Langkah 1:</strong> Identifikasi kolom, cek kesiapan kolom Header ID (Write-Ready) &amp; petakan form brand.
                &bull;
                <strong>Langkah 2:</strong> Pindai baris data, tetapkan status pemagang (via warna latar sel, kolom status, atau partisi rentang), jalankan simulasi audit pra-tulis (Dry-Run), dan masukkan data ke SertIF.
            </div>
        </div>

        <!-- 2-Column Responsive Workspace Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 min-h-[600px]">
            <!-- LEFT PANEL: Source Navigation Panel (#ws-source-panel) -->
            <div class="lg:col-span-3 border-r border-slate-200 bg-slate-50/60 p-4 space-y-3" id="ws-source-panel">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                        <i class="fas fa-layer-group text-teal-600"></i>
                        Daftar Tab Sumber
                    </span>
                    <span id="ws-tabs-count-badge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">0 Tab</span>
                </div>

                <!-- Tab Search Input -->
                <div class="relative">
                    <input
                        type="text"
                        id="ws-tab-search"
                        placeholder="Cari tab sheet..."
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:border-teal-500 focus:outline-none"
                    />
                </div>

                <!-- Tabs Category Filter Pills -->
                <div class="flex items-center gap-1 text-[11px]" id="ws-tab-category-filter">
                    <button type="button" class="px-2 py-0.5 rounded font-semibold bg-slate-800 text-white" data-category="all">Semua</button>
                    <button type="button" class="px-2 py-0.5 rounded font-semibold bg-white text-slate-600 border border-slate-200 hover:bg-slate-100" data-category="reg">Pendaftaran</button>
                    <button type="button" class="px-2 py-0.5 rounded font-semibold bg-white text-slate-600 border border-slate-200 hover:bg-slate-100" data-category="other">Lainnya</button>
                </div>

                <!-- Sidebar Tab Items List -->
                <div id="ws-sidebar-tabs-list" class="space-y-1.5 max-h-[520px] overflow-y-auto pr-1">
                    <div class="py-8 text-center text-xs text-slate-400 italic">
                        <i class="fas fa-spinner fa-spin text-teal-600 text-lg mb-1 block"></i>
                        Memuat daftar tab...
                    </div>
                </div>

                <div class="pt-2 border-t border-slate-200 text-[11px] text-slate-500">
                    <i class="fas fa-info-circle text-teal-600 mr-1"></i> Klik kartu tab di atas untuk memilih tab secara cepat.
                </div>
            </div>

            <!-- RIGHT PANEL: Steps 1 & 2 Execution Area -->
            <div class="lg:col-span-9 p-5 space-y-6 bg-white overflow-y-auto">
                <!-- LANGKAH 1: Struktur Kolom Spreadsheet & Injeksi Header ID Sistem -->
                <div class="rounded-xl border border-sky-200 bg-sky-50/30 p-4 space-y-4" id="card-step-columns">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-sky-100 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded bg-sky-600 text-white">
                                Langkah 1
                            </span>
                            <h3 class="text-sm font-bold text-slate-800">
                                Identifikasi Kolom, Header ID Sistem &amp; Penyesuaian Form Brand
                            </h3>
                        </div>
                        <span class="text-[11px] text-slate-500">
                            Memastikan kolom Header ID Sistem SertIF tersedia dan menyesuaikan form brand.
                        </span>
                    </div>

                    <!-- Controls: Tab select, Header row input, Button Inspect -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-12 gap-3 items-end">
                        <div class="md:col-span-4">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Pilih Tab Spreadsheet</label>
                            <select id="scanner-tab-select" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-800 focus:border-sky-500 focus:outline-none">
                                <option value="">-- Sedang memuat daftar tab... --</option>
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Baris Header (Kolom)</label>
                            <input
                                type="number"
                                id="ws-header-row"
                                value="10"
                                min="1"
                                max="1000"
                                placeholder="misal: 10"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-800 focus:border-sky-500 focus:outline-none font-mono"
                                title="Nomor baris tempat nama kolom diletakkan di spreadsheet (misal: 10)"
                            />
                        </div>
                        <div class="md:col-span-5 flex items-center gap-2">
                            <button
                                type="button"
                                id="btn-inspect-columns"
                                class="w-full inline-flex justify-center items-center gap-2 px-3 py-2 rounded-lg bg-sky-700 text-white hover:bg-sky-800 text-xs font-semibold shadow-sm transition"
                            >
                                <i class="fas fa-columns text-xs"></i>
                                Pindai Struktur Kolom Spreadsheet
                            </button>
                        </div>
                    </div>

                    <!-- Loading State for Columns -->
                    <div id="ws-col-loading" class="hidden py-4 text-center text-slate-500 text-xs">
                        <i class="fas fa-spinner fa-spin text-xl text-sky-600 mb-1"></i>
                        <p id="ws-col-loading-text">Sedang memindai kolom dan mengecek Header ID...</p>
                    </div>

                    <!-- Result Card for Columns & Header ID -->
                    <div id="ws-col-result" class="hidden space-y-3.5 pt-2">
                        <!-- Header ID Alert Banner -->
                        <div id="ws-header-id-alert" class="p-3 rounded-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
                            <!-- Populated dynamically via JS -->
                        </div>

                        <!-- Counts & Info summary -->
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600 bg-white p-2.5 rounded-lg border border-slate-200">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-800">Total: <span id="ws-total-cols" class="text-sky-700">0</span> Kolom</span>
                                <span>&bull;</span>
                                <span class="text-emerald-700 font-semibold"><span id="ws-count-standard">0</span> Standar Identitas</span>
                                <span>&bull;</span>
                                <span class="text-purple-700 font-semibold"><span id="ws-count-custom">0</span> Pertanyaan Khusus Brand</span>
                                <span>&bull;</span>
                                <span class="text-slate-600 font-semibold"><span id="ws-count-system">0</span> Kolom Sistem SertIF</span>
                            </div>
                            <div class="text-[11px] text-slate-500 italic">
                                Tab: <strong id="ws-inspected-tab" class="font-mono text-slate-700">-</strong> | Baris Header: <strong id="ws-inspected-header-row" class="font-mono text-slate-700">-</strong>
                            </div>
                        </div>

                        <!-- Detected Columns Table -->
                        <div class="overflow-x-auto rounded-lg border border-slate-200 max-h-60 overflow-y-auto bg-white">
                            <table class="w-full text-left text-xs min-w-[600px]">
                                <thead class="sticky top-0 z-10 bg-slate-100 border-b border-slate-200 text-slate-700 font-semibold text-[11px]">
                                    <tr>
                                        <th class="px-3 py-2 w-16 text-center">Kolom</th>
                                        <th class="px-3 py-2">Nama Kolom (Header Spreadsheet)</th>
                                        <th class="px-3 py-2 w-44">Kategori Kolom</th>
                                        <th class="px-3 py-2 w-36">Tipe Input Form</th>
                                    </tr>
                                </thead>
                                <tbody id="ws-col-tbody" class="divide-y divide-slate-100">
                                    <!-- Populated dynamically via JS -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Action Footer: Save & Apply to Brand Form -->
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-2">
                            <p class="text-[11px] text-slate-500">
                                Klik tombol di samping untuk menerapkan susunan kolom ini ke formulir pendaftaran dan edit data pemagang khusus brand ini.
                            </p>
                            <button
                                type="button"
                                id="btn-save-brand-columns"
                                class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold shadow-sm transition shrink-0"
                            >
                                <i class="fas fa-check-circle text-xs"></i>
                                Simpan &amp; Terapkan ke Form Brand Ini
                            </button>
                        </div>
                    </div>
                </div>

                <!-- LANGKAH 2: Pindai Baris Data, Filter Warna & Ingestion ke SertIF -->
                <div class="rounded-xl border border-teal-200 bg-white p-4 space-y-4" id="card-step-rows">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-slate-100 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded bg-teal-600 text-white">
                                Langkah 2
                            </span>
                            <h3 class="text-sm font-bold text-slate-800">
                                Pindai Baris Data, Filter Palet Warna &amp; Masukkan ke SertIF
                            </h3>
                        </div>
                        <span class="text-[11px] text-slate-500">
                            Status awal netral (NULL) &bull; Konfigurasi Sumber Status &bull; Simulasi Pra-Tulis &bull; Ingestion Aman
                        </span>
                    </div>

                    <!-- Controls: Start row, End row, Status Source Type, Button Scan -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-12 gap-3 items-end">
                        <div class="md:col-span-2">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Baris Awal Data</label>
                            <input
                                type="number"
                                id="ws-start-row"
                                value="11"
                                min="1"
                                max="1000"
                                placeholder="misal: 11"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-800 focus:border-teal-500 focus:outline-none font-mono"
                                title="Nomor baris tempat data pemagang pertama mulai diisi (misal: 11)"
                            />
                        </div>
                        <div class="md:col-span-2">
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-semibold text-slate-700">Baris Akhir</label>
                                <span id="ws-tab-limit-badge" class="text-[10px] text-teal-700 font-mono font-bold" title="Batas maksimal baris riil pada tab aktif">Maks: -</span>
                            </div>
                            <input
                                type="number"
                                id="ws-end-row"
                                min="1"
                                max="5000"
                                placeholder="misal: 150"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-800 focus:border-teal-500 focus:outline-none font-mono"
                                title="Batasi sampai baris tertentu jika ingin memproses sebagian data (misal: 150)"
                            />
                        </div>
                        <div class="md:col-span-4">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Sumber Status Pemagang</label>
                            <select id="ws-status-source-type" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-800 focus:border-teal-500 focus:outline-none">
                                <option value="color" selected>Warna Latar Sel (Row Background Color)</option>
                                <option value="column">Nilai Kolom Tertentu (Column Value)</option>
                                <option value="range">Partisi Rentang Baris (Manual Range Partition)</option>
                            </select>
                        </div>
                        <div class="md:col-span-4 flex items-center gap-2">
                            <button
                                type="button"
                                id="btn-scan-workspace"
                                class="flex-1 inline-flex justify-center items-center gap-2 px-3 py-2 rounded-lg bg-teal-600 text-white hover:bg-teal-700 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                            >
                                <i class="fas fa-palette text-xs"></i>
                                Pindai Data &amp; Status Spreadsheet
                            </button>
                            <button
                                type="button"
                                id="btn-stop-scan"
                                class="hidden inline-flex justify-center items-center gap-1.5 px-3 py-2 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-xs font-semibold shadow-sm transition whitespace-nowrap"
                                title="Hentikan pemindaian data sekarang"
                            >
                                <i class="fas fa-stop-circle text-xs"></i>
                                Hentikan
                            </button>
                        </div>
                    </div>
                    <!-- Safeguard Controls: Deteksi Status Saja & Skip ID (Cegah Looping Tak Terhenti) -->
                    <div class="mt-2.5 grid grid-cols-1 sm:grid-cols-2 gap-2 p-2.5 rounded-lg bg-teal-50/80 border border-teal-200 text-xs">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                id="ws-detect-status-only"
                                class="rounded border-slate-300 text-teal-600 focus:ring-teal-500 h-4 w-4"
                            />
                            <span class="text-teal-950 font-semibold flex items-center gap-1.5">
                                <i class="fas fa-bullseye text-teal-600"></i>
                                <span>Hanya deteksi sumber status (Sampel aman dibatasi baris tab)</span>
                            </span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                id="ws-skip-synced-scan"
                                class="rounded border-slate-300 text-teal-600 focus:ring-teal-500 h-4 w-4"
                                checked
                            />
                            <span class="text-teal-950 font-semibold flex items-center gap-1.5">
                                <i class="fas fa-filter text-teal-600"></i>
                                <span>Lewati baris yang sudah punya ID (Cegah duplikasi &amp; looping)</span>
                            </span>
                        </label>
                    </div>
                    <!-- Preset & Fast Range Navigation Helper -->
                    <div class="mt-2.5 flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-100 text-[11px]">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="font-bold text-slate-600 flex items-center gap-1">
                                <i class="fas fa-bolt text-teal-600"></i> Blok Cepat:
                            </span>
                            <button type="button" class="btn-range-preset px-2.5 py-1 rounded bg-teal-50 hover:bg-teal-100 text-teal-800 font-bold border border-teal-300 transition" data-range="100">+100 Baris (Sangat Cepat)</button>
                            <button type="button" class="btn-range-preset px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold border border-slate-200 transition" data-range="200">+200 Baris</button>
                            <button type="button" class="btn-range-preset px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold border border-slate-200 transition" data-range="300">+300 Baris</button>
                            <button type="button" class="btn-range-preset px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold border border-slate-200 transition" data-range="500">+500 Baris (Maksimal)</button>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="btn-prev-block" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-semibold text-[11px] shadow-2xs transition" title="Kembali ke blok baris sebelumnya">
                                <i class="fas fa-arrow-left text-[10px]"></i>
                                <span>&laquo; 100 Baris Sebelumnya</span>
                            </button>
                            <button type="button" id="btn-next-block" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-slate-800 hover:bg-slate-900 text-white font-semibold text-[11px] shadow-2xs transition" title="Lanjut ke blok 100 baris berikutnya">
                                <span>100 Baris Berikutnya &raquo;</span>
                                <i class="fas fa-arrow-right text-[10px]"></i>
                            </button>
                        </div>
                    </div>
                    <div class="p-2 rounded-lg bg-teal-50/70 border border-teal-100 text-[11px] text-teal-900 flex items-center gap-2">
                        <i class="fas fa-info-circle text-teal-600 shrink-0"></i>
                        <span>
                            <strong>Rekomendasi untuk 5.000+ Baris:</strong> Pindai 100 baris pertama untuk mendeteksi palet warna/kolom status (memuat dalam &lt;1 detik). Setelah aturan status terpetakan, klik tombol <strong>"⚡ Terapkan Status &amp; Sinkronkan Semua Tanpa ID"</strong> di bawah untuk memproses seluruh baris otomatis tanpa membuka ribuan baris di tabel.
                        </span>
                    </div>

                    <!-- Column status selector (Conditional if status source is column) -->
                    <div id="ws-status-col-wrapper" class="hidden p-3 rounded-lg bg-sky-50 border border-sky-200 text-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-columns text-sky-600"></i>
                            <span class="font-medium text-sky-900">Pilih kolom spreadsheet yang berisi teks status magang (misal: KET, STATUS, dsb):</span>
                        </div>
                        <div class="w-full sm:w-64">
                            <select id="ws-status-col-select" class="w-full rounded-lg border border-sky-300 bg-white px-3 py-1.5 text-xs text-slate-800 focus:outline-none">
                                <option value="">-- Pilih Kolom Status --</option>
                            </select>
                        </div>
                    </div>

                    <!-- Loading State Indicator -->
                    <div id="ws-loading" class="hidden py-8 text-center text-slate-500 text-xs">
                        <i class="fas fa-spinner fa-spin text-2xl text-teal-600 mb-2"></i>
                        <p id="ws-loading-text">Sedang memindai data dan warna background spreadsheet...</p>
                    </div>

                    <!-- Interactive Rule & Filter Builder (Muncul Pasca Scan) -->
                    <div id="ws-rules-card" class="hidden mb-6 p-4 rounded-xl border border-slate-200 bg-slate-50/70 space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                            <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fas fa-filter text-teal-600"></i>
                                Aturan Pemetaan Status &amp; Pengurutan Data
                            </h3>
                            <span class="text-[11px] text-slate-500">Atur pemetaan warna atau partisi baris lalu klik 'Terapkan Aturan'</span>
                        </div>

                        <!-- Grid: Status Source Rules (Color Palette / Column Values / Range) & Sorting -->
                        <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                            <!-- 1. Color Palette Mapping (Active when Status Source = Color) -->
                            <div id="ws-palette-section" class="md:col-span-6 space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-semibold text-slate-700">
                                        1. Pemetaan Warna Baris (Palet Terdeteksi):
                                    </label>
                                    <span id="ws-palette-count-badge" class="text-[10px] px-2 py-0.5 rounded bg-teal-100 text-teal-800 font-bold">0 Warna Terdeteksi</span>
                                </div>
                                <div id="ws-palette-container" class="space-y-2 max-h-48 overflow-y-auto pr-1">
                                    <p class="text-xs text-slate-400 italic">Pindai sheet untuk membaca palet warna.</p>
                                </div>
                            </div>

                            <!-- 2. Column Distinct Values Mapping (Active when Status Source = Column) -->
                            <div id="ws-column-values-section" class="hidden md:col-span-6 space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-semibold text-slate-700">
                                        1. Pemetaan Varian Nilai Kolom Status:
                                    </label>
                                    <span id="ws-column-values-count-badge" class="text-[10px] px-2 py-0.5 rounded bg-teal-100 text-teal-800 font-bold">0 Varian Terdeteksi</span>
                                </div>
                                <div id="ws-column-values-container" class="space-y-2 max-h-48 overflow-y-auto pr-1">
                                    <p class="text-xs text-slate-400 italic">Pilih kolom status di atas dan pindai untuk membaca varian nilai.</p>
                                </div>
                            </div>

                            <!-- 3. Manual Range Partitioning (Active when Status Source = Range) -->
                            <div id="ws-ranges-section" class="hidden md:col-span-6 space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-semibold text-slate-700">
                                        1. Partisi Rentang Baris Manual:
                                    </label>
                                    <button type="button" id="btn-add-range-rule" class="text-[11px] font-semibold text-teal-700 hover:text-teal-900">
                                        + Tambah Rentang
                                    </button>
                                </div>
                                <div id="ws-ranges-container" class="space-y-2 max-h-36 overflow-y-auto pr-1">
                                    <!-- Dynamic range rows -->
                                </div>
                            </div>

                            <!-- Sorting Controls -->
                            <div class="md:col-span-6 space-y-2">
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">
                                    2. Pengurutan Data Pratinjau:
                                </label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <select id="ws-sort-by" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-teal-500 focus:outline-none">
                                            <option value="_row_number">Nomor Baris Asli (Sheet)</option>
                                            <option value="display_name">Nama Lengkap Pemagang</option>
                                            <option value="display_contact">Kontak / Email</option>
                                            <option value="status">Status Magang</option>
                                        </select>
                                    </div>
                                    <div>
                                        <select id="ws-sort-dir" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-teal-500 focus:outline-none">
                                            <option value="asc">Menaik (A-Z / 1-9)</option>
                                            <option value="desc">Menurun (Z-A / 9-1)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Apply Rules Button -->
                        <div class="pt-3 border-t border-slate-200 flex items-center justify-end gap-2">
                            <button
                                type="button"
                                id="btn-apply-workspace-rules"
                                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-slate-800 text-white hover:bg-slate-900 text-xs font-semibold shadow-sm transition"
                            >
                                <i class="fas fa-check-double text-xs"></i>
                                Terapkan Aturan &amp; Urutkan Pratinjau
                            </button>
                        </div>
                    </div>

                    <!-- Table Pratinjau Terpadu -->
                    <div id="ws-table-card" class="hidden space-y-3">
                        <!-- Filter Pills & Search Bar -->
                        <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-xs space-y-2.5">
                            <!-- Baris 1: Filter Status Integrasi ID & Search Bar -->
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="flex items-center gap-1.5 flex-wrap" id="ws-source-filter-pills">
                                    <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider mr-1">
                                        <i class="fas fa-id-badge text-teal-600 mr-1"></i>Filter ID:
                                    </span>
                                    <button type="button" class="px-2.5 py-1 rounded-md text-[11px] font-bold bg-teal-700 text-white shadow-xs" data-source-filter="no_id">
                                        ⚡ Hanya Tanpa ID (<span id="count-source-no-id">0</span>)
                                    </button>
                                    <button type="button" class="px-2.5 py-1 rounded-md text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-source-filter="all">
                                        Semua Baris (<span id="count-source-all">0</span>)
                                    </button>
                                    <button type="button" class="px-2.5 py-1 rounded-md text-[11px] font-semibold bg-amber-50 text-amber-800 hover:bg-amber-100 border border-amber-300" data-source-filter="missing_local" title="Baris ber-ID di spreadsheet namun hilang/belum ada di SertIF">
                                        ⚠️ Ber-ID (Hilang di DB) (<span id="count-source-missing-local">0</span>)
                                    </button>
                                    <button type="button" class="px-2.5 py-1 rounded-md text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-source-filter="synced_intact" title="Baris ber-ID dan terhubung aktif di SertIF">
                                        ✅ Ber-ID (Sinkron di DB) (<span id="count-source-synced-intact">0</span>)
                                    </button>
                                    <button type="button" class="px-2.5 py-1 rounded-md text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-source-filter="synced">
                                        Semua Ber-ID (<span id="count-source-synced">0</span>)
                                    </button>
                                </div>
                                <div class="w-full sm:w-64">
                                    <input
                                        type="text"
                                        id="ws-search-input"
                                        placeholder="Cari nama / kontak pemagang..."
                                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-teal-500 focus:outline-none"
                                    />
                                </div>
                            </div>
                            <!-- Baris 2: Filter Status Magang -->
                            <div class="flex items-center gap-1.5 flex-wrap pt-2 border-t border-slate-200/60" id="ws-status-filter-pills">
                                <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider mr-1">
                                    <i class="fas fa-tag text-teal-600 mr-1"></i>Status Magang:
                                </span>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-800 text-white" data-filter="all">
                                    Semua (<span id="count-all">0</span>)
                                </button>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-800 hover:bg-amber-100 border border-amber-300" data-filter="unassigned">
                                    <i class="fas fa-question-circle text-[10px]"></i> Belum Ditentukan (<span id="count-unassigned">0</span>)
                                </button>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-filter="accepted">
                                    Diterima (<span id="count-accepted">0</span>)
                                </button>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-filter="active">
                                    Aktif (<span id="count-active">0</span>)
                                </button>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-filter="waiting">
                                    Calon (<span id="count-waiting">0</span>)
                                </button>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-filter="completed">
                                    Selesai (<span id="count-completed">0</span>)
                                </button>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-filter="rejected">
                                    Ditolak (<span id="count-rejected">0</span>)
                                </button>
                                <button type="button" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200" data-filter="exited">
                                    Dikeluarkan (<span id="count-exited">0</span>)
                                </button>
                            </div>
                        </div>

                        <!-- Table Container -->
                        <div class="overflow-x-auto rounded-lg border border-slate-200 max-h-[500px] overflow-y-auto">
                            <table class="w-full text-left text-xs min-w-[700px]">
                                <thead class="sticky top-0 z-10 bg-slate-100 border-b border-slate-200 text-slate-700 font-semibold shadow-xs">
                                    <tr>
                                        <th class="px-3 py-2.5 w-10 text-center">
                                            <input type="checkbox" id="ws-check-all" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500 h-4 w-4" />
                                        </th>
                                        <th class="px-3 py-2.5 w-24">Baris Sheet</th>
                                        <th class="px-3 py-2.5">Nama Pemagang</th>
                                        <th class="px-3 py-2.5">Email / Kontak</th>
                                        <th class="px-3 py-2.5 w-44">Status Magang</th>
                                        <th class="px-3 py-2.5 w-32">Status Integrasi</th>
                                    </tr>
                                </thead>
                                <tbody id="ws-table-tbody" class="divide-y divide-slate-100 bg-white">
                                    <!-- Populated via JS -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Sticky Action Bar / Batch Summary -->
                        <div id="ws-action-bar" class="p-4 rounded-xl border border-teal-200 bg-teal-50/90 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-xs">
                            <div>
                                <div class="text-xs font-bold text-teal-950 flex items-center gap-2">
                                    <i class="fas fa-check-circle text-teal-600"></i>
                                    <span>Terpilih: <strong id="ws-selected-count" class="text-teal-700 text-sm">0</strong> baris data</span>
                                </div>
                                <p class="text-[11px] text-teal-800 mt-0.5" id="ws-selected-breakdown">
                                    Belum ada baris yang dipilih. Centang baris untuk dimasukkan ke SertIF.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    id="btn-select-all-filtered"
                                    class="px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 text-xs font-semibold shadow-xs transition"
                                >
                                    Pilih Semua Ditampilkan
                                </button>
                                <button
                                    type="button"
                                    id="btn-dry-run-reconcile"
                                    class="px-4 py-2 rounded-lg bg-sky-700 hover:bg-sky-800 text-white text-xs font-bold shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5"
                                    disabled
                                    title="Lakukan audit simulasi pra-tulis tanpa mengubah database atau remote spreadsheet"
                                >
                                    <i class="fas fa-flask text-xs"></i>
                                    Simulasi Pra-Tulis (Dry-Run)
                                </button>
                                <button
                                    type="button"
                                    id="btn-bulk-recover-missing"
                                    class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5"
                                    title="Tarik dan pulihkan seluruh baris yang sudah punya ID di spreadsheet tapi datanya hilang/belum ada di SertIF"
                                >
                                    <i class="fas fa-file-import text-xs"></i>
                                    ⚡ Tarik &amp; Pulihkan Semua yang Hilang di SertIF
                                </button>
                                <button
                                    type="button"
                                    id="btn-bulk-sync-without-id"
                                    class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5"
                                    title="Sinkronkan seluruh baris tanpa ID yang cocok dengan aturan status secara massal"
                                >
                                    <i class="fas fa-bolt text-xs"></i>
                                    ⚡ Terapkan Status &amp; Sinkronkan Semua Tanpa ID
                                </button>
                                <button
                                    type="button"
                                    id="btn-execute-batch"
                                    class="px-5 py-2 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5"
                                    disabled
                                >
                                    <i class="fas fa-cloud-upload-alt text-xs"></i>
                                    Masukkan Data Terpilih ke SertIF
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>


    <!-- Fase 2: Explicit Tab Profiles & Stable Mapping Lifecycle Card -->
    <div class="mb-8 rounded-xl border border-indigo-200 bg-white p-6 shadow-sm" id="fase2-lifecycle-section">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-100 pb-4 mb-5">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded bg-indigo-100 text-indigo-800 border border-indigo-200">
                        Fase 2: Stable Mapping & Lifecycle
                    </span>
                    <h2 class="text-lg font-bold text-slate-800">Profil Header Tab & Usulan Pemetaan Interaktif</h2>
                </div>
                <p class="text-xs text-slate-500 mt-1">
                    Konfigurasi posisi header berbasis sheet_id numerik stabil, seleksi interaktif pemetaan kolom, perlindungan field historis, schema diff non-destruktif, dan decoupled remote sync.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    onclick="openTabProfileModal()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200 text-xs font-semibold transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Atur Profil Tab (Eksplisit)
                </button>
                <button
                    type="button"
                    id="btn-create-proposal-from-active"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 text-xs font-semibold shadow-sm transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
                    </svg>
                    Buat Snapshot & Usulan Pemetaan
                </button>
            </div>
        </div>

        <!-- 1. Explicit Tab Profiles Table -->
        <div class="mb-6">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-2 flex items-center gap-2">
                <span>Daftar Profil Tab Tersimpan (Stabil Berdasarkan sheet_id)</span>
                <span class="text-[10px] font-normal text-slate-400 font-mono">(Kebal terhadap rename nama tab)</span>
            </h3>
            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-600 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-2.5">Sheet ID (Numerik)</th>
                            <th class="px-4 py-2.5">Nama Tab Terdaftar</th>
                            <th class="px-4 py-2.5 text-center">Baris Header</th>
                            <th class="px-4 py-2.5 text-center">Baris Mulai Data</th>
                            <th class="px-4 py-2.5 text-center">Status</th>
                            <th class="px-4 py-2.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($tabProfiles as $profile)
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="px-4 py-2 font-mono font-semibold text-slate-700">{{ $profile->sheet_id }}</td>
                                <td class="px-4 py-2 font-medium text-slate-800">
                                    <span class="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-[11px] border border-slate-200">
                                        [{{ $profile->tab_name }}]
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-center font-mono font-bold text-blue-700">Baris {{ $profile->header_row }}</td>
                                <td class="px-4 py-2 text-center font-mono text-slate-600">Baris {{ $profile->data_start_row }}</td>
                                <td class="px-4 py-2 text-center">
                                    @if ($profile->is_active)
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-emerald-50 text-emerald-700 border border-emerald-200">Aktif</span>
                                    @else
                                        <span class="px-2 py-0.5 text-[10px] font-medium rounded bg-slate-100 text-slate-500">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button
                                        type="button"
                                        onclick="editTabProfile({{ $profile->sheet_id }}, '{{ addslashes($profile->tab_name) }}', {{ $profile->header_row }}, {{ $profile->data_start_row }})"
                                        class="text-indigo-600 hover:text-indigo-800 font-semibold text-[11px]"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-4 text-center text-slate-400 italic">
                                    Belum ada profil tab eksplisit. Sistem menggunakan deteksi default (Tab 2026 = Baris 12, tab lain = Baris 1).
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. Interactive Mapping Proposals & Decisions -->
        @if ($recentProposals->isNotEmpty())
            @php
                $activeProposal = $recentProposals->first();
                $hasCollision = $activeProposal->items->contains('has_collision', true);
            @endphp
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-5 mb-6" id="proposal-card-container">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-3 mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-800 text-sm">Usulan Pemetaan Aktif: Tab [{{ $activeProposal->snapshot->tab_name ?? 'Tab' }}]</span>
                            <span class="font-mono text-[10px] bg-white border border-slate-200 px-2 py-0.5 rounded text-slate-600">
                                UUID: {{ substr($activeProposal->public_uuid, 0, 8) }}...
                            </span>
                            @php
                                $statusBadge = match($activeProposal->status) {
                                    'draft' => 'bg-amber-100 text-amber-800 border-amber-200',
                                    'approved_to_draft' => 'bg-blue-100 text-blue-800 border-blue-200',
                                    'applied' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                    'conflict' => 'bg-rose-100 text-rose-800 border-rose-200',
                                    default => 'bg-slate-100 text-slate-600 border-slate-200',
                                };
                            @endphp
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold border {{ $statusBadge }}">
                                {{ strtoupper($activeProposal->status) }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Target Formulir: <strong>Tahun {{ $activeProposal->year ?? '2026' }}</strong> &bull; Total Kolom Terusul: <strong>{{ $activeProposal->items->count() }}</strong>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            onclick="previewDiffAjax({{ $activeProposal->id }})"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white text-indigo-700 hover:bg-indigo-50 border border-indigo-200 text-xs font-semibold shadow-sm transition"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            Pratinjau Dampak (Schema Diff)
                        </button>
                        <button
                            type="button"
                            onclick="applyProposalAjax({{ $activeProposal->id }})"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-xs font-semibold shadow-sm transition"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            Terapkan ke Draf (Apply to Draft)
                        </button>
                    </div>
                </div>

                @if ($hasCollision)
                    <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 p-3 text-xs text-rose-800 flex items-start gap-2.5">
                        <svg class="h-4 w-4 text-rose-600 mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <div>
                            <strong>Peringatan Tabrakan Header (Collision Detected):</strong>
                            Ditemukan header kembar/duplikat pada tab ini. Sistem sengaja tidak melakukan automatch untuk mencegah data tertukar secara keliru. Tentukan target pemetaan secara eksplisit di bawah ini.
                        </div>
                    </div>
                @endif

                <!-- Decision Table -->
                <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-100 text-slate-700 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                            <tr>
                                <th class="px-3 py-2.5 w-14 text-center">Kolom</th>
                                <th class="px-3 py-2.5">Header di Spreadsheet</th>
                                <th class="px-3 py-2.5 w-44">Keputusan Aksi</th>
                                <th class="px-3 py-2.5">Target Field Form Builder</th>
                                <th class="px-3 py-2.5 text-center">Keyakinan</th>
                                <th class="px-3 py-2.5 text-right w-36">Aksi & Keamanan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-sans">
                            @foreach ($activeProposal->items as $item)
                                <tr class="hover:bg-slate-50/70 transition {{ $item->has_collision ? 'bg-rose-50/30' : '' }}" id="proposal-row-{{ $item->id }}">
                                    <td class="px-3 py-2 text-center font-mono font-bold text-indigo-700 bg-slate-50/40">
                                        {{ $item->col_letter }}
                                        <div class="text-[9px] text-slate-400 font-normal">#{{ $item->physical_index }}</div>
                                    </td>
                                    <td class="px-3 py-2 font-medium text-slate-800">
                                        <span class="font-mono bg-slate-50 px-1.5 py-0.5 rounded text-[11px] border border-slate-200">
                                            {{ $item->observed_label }}
                                        </span>
                                        @if ($item->has_collision)
                                            <span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-bold {{ $item->collision_type === 'target_field' ? 'bg-rose-100 text-rose-800 border-rose-200' : 'bg-amber-100 text-amber-800 border-amber-200' }} border" title="{{ $item->collision_detail }}">
                                                {{ $item->collision_type === 'target_field' ? 'KONFLIK TARGET' : 'HEADER KEMBAR (' . $item->col_letter . ')' }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <select
                                            id="action-select-{{ $item->id }}"
                                            onchange="onActionChange({{ $item->id }})"
                                            class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 focus:border-indigo-500 focus:outline-none"
                                            {{ $item->action === 'system_protected' ? 'disabled' : '' }}
                                        >
                                            <option value="link_existing" {{ $item->action === 'link_existing' ? 'selected' : '' }}>Hubungkan ke Field Ada</option>
                                            <option value="create_draft_field" {{ $item->action === 'create_draft_field' ? 'selected' : '' }}>Buat Field Draf Baru</option>
                                            <option value="system_derived" {{ $item->action === 'system_derived' ? 'selected' : '' }}>Status Sistem (Derived: KET)</option>
                                            <option value="attachment_adapter" {{ $item->action === 'attachment_adapter' ? 'selected' : '' }}>Adapter Berkas (Drive)</option>
                                            <option value="external_only" {{ $item->action === 'external_only' ? 'selected' : '' }}>Eksternal Saja (Simpan di Sheet)</option>
                                            <option value="ignore" {{ $item->action === 'ignore' ? 'selected' : '' }}>Abaikan Kolom Ini</option>
                                            @if ($item->action === 'system_protected')
                                                <option value="system_protected" selected>Dilindungi Sistem</option>
                                            @endif
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div id="field-config-link-{{ $item->id }}" class="{{ $item->action === 'link_existing' ? '' : 'hidden' }}">
                                            <select
                                                id="field-key-select-{{ $item->id }}"
                                                class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 focus:border-indigo-500 focus:outline-none font-mono"
                                            >
                                                <option value="">-- Pilih Field Form Builder --</option>
                                                @foreach ($formFields as $ff)
                                                    <option value="{{ $ff->field_key }}" {{ $item->field_key === $ff->field_key ? 'selected' : '' }}>
                                                        {{ $ff->field_label }} ({{ $ff->field_key }}) [{{ $ff->field_type }}]
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div id="field-config-create-{{ $item->id }}" class="{{ $item->action === 'create_draft_field' ? '' : 'hidden' }} flex items-center gap-2">
                                            <input
                                                type="text"
                                                id="suggested-label-{{ $item->id }}"
                                                value="{{ $item->suggested_label ?? $item->observed_label }}"
                                                placeholder="Label Field Baru"
                                                class="w-2/3 rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 focus:border-indigo-500 focus:outline-none"
                                            />
                                            <select
                                                id="suggested-type-{{ $item->id }}"
                                                class="w-1/3 rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 focus:border-indigo-500 focus:outline-none font-mono"
                                            >
                                                @foreach (['text', 'number', 'email', 'phone', 'date', 'select', 'file', 'textarea'] as $ft)
                                                    <option value="{{ $ft }}" {{ ($item->suggested_field_type ?? 'text') === $ft ? 'selected' : '' }}>{{ $ft }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div id="field-config-none-{{ $item->id }}" class="{{ in_array($item->action, ['external_only', 'ignore', 'system_protected', 'system_derived', 'attachment_adapter']) ? '' : 'hidden' }} text-slate-400 italic text-[11px]">
                                            @if ($item->action === 'system_protected')
                                                <span class="text-purple-700 font-mono font-medium">Field Identitas Sistem (Terkunci)</span>
                                            @elseif ($item->action === 'system_derived')
                                                <span class="text-blue-700 font-mono font-medium">Status Sistem (Derived / Display-Only)</span>
                                            @elseif ($item->action === 'attachment_adapter')
                                                <span class="text-emerald-700 font-medium">Adapter Berkas (Disimpan ke Google Drive Pendaftar)</span>
                                            @elseif ($item->action === 'external_only')
                                                <span>Tidak masuk formulir pemagang, tetap dibaca/disimpan dari spreadsheet</span>
                                            @else
                                                <span>Kolom ini diabaikan dari form & sinkronisasi</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        @php
                                            $cBadge = match($item->confidence_level) {
                                                'EXACT' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                                'HIGH' => 'bg-blue-100 text-blue-800 border-blue-200',
                                                'MEDIUM' => 'bg-amber-100 text-amber-800 border-amber-200',
                                                default => 'bg-slate-100 text-slate-600 border-slate-200',
                                            };
                                        @endphp
                                        <span class="inline-block px-2 py-0.5 rounded text-[9px] font-bold border {{ $cBadge }}">
                                            {{ $item->confidence_level }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        @if ($item->action !== 'system_protected')
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button
                                                    type="button"
                                                    onclick="saveProposalItemAjax({{ $activeProposal->id }}, {{ $item->id }})"
                                                    id="btn-save-item-{{ $item->id }}"
                                                    class="px-2 py-1 rounded bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold text-[11px] border border-indigo-200 transition"
                                                    title="Simpan keputusan pemetaan"
                                                >
                                                    Simpan
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick="openColumnDeletionModal('{{ $item->col_letter }}', '{{ addslashes($item->observed_label) }}', '{{ addslashes($activeProposal->snapshot->tab_name ?? 'Tab') }}', '{{ $activeProposal->snapshot->schema_hash ?? '' }}', {{ $activeProposal->snapshot->sheet_id ?? 0 }})"
                                                    class="px-2 py-1 rounded bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-700 font-semibold text-[11px] border border-slate-200 hover:border-rose-300 transition"
                                                    title="Verifikasi keamanan & hapus kolom kosong"
                                                >
                                                    Hapus Kosong
                                                </button>
                                            </div>
                                        @else
                                            <span class="text-purple-600 font-mono text-[10px] bg-purple-50 px-1.5 py-0.5 rounded border border-purple-200">Sistem</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <!-- 3. Integration Outbox Events (Decoupled Publish & Remote Sync Status F2-14, F2-15) -->
        <div>
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-600 flex items-center gap-2">
                    <span>Log Antrean Sinkronisasi Outbox (Decoupled DB Publish vs Remote Sync)</span>
                </h3>
                <span class="text-[11px] text-slate-500">
                    DB Publish berhasil seketika &bull; Remote sync diproses terpisah secara aman
                </span>
            </div>
            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-600 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-2.5">Event Tipe</th>
                            <th class="px-4 py-2.5">Idempotency Key</th>
                            <th class="px-4 py-2.5 text-center">Percobaan</th>
                            <th class="px-4 py-2.5 text-center">Status Remote</th>
                            <th class="px-4 py-2.5">Waktu Dibuat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($outboxEvents as $event)
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="px-4 py-2 font-mono font-medium text-slate-800">
                                    {{ $event->event_type }}
                                </td>
                                <td class="px-4 py-2 font-mono text-slate-600 text-[11px] truncate max-w-xs" title="{{ $event->idempotency_key }}">
                                    {{ $event->idempotency_key }}
                                </td>
                                <td class="px-4 py-2 text-center font-mono">
                                    {{ $event->attempt_count }} / {{ $event->max_attempts }}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    @php
                                        $evBadge = match($event->status) {
                                            'verified' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                            'pending' => 'bg-blue-50 text-blue-700 border-blue-200',
                                            'processing' => 'bg-purple-50 text-purple-700 border-purple-200',
                                            'failed' => 'bg-rose-50 text-rose-700 border-rose-200',
                                            default => 'bg-slate-100 text-slate-600 border-slate-200',
                                        };
                                    @endphp
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $evBadge }}">
                                        {{ ucfirst($event->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 font-mono text-slate-500 text-[11px]">
                                    {{ $event->created_at ? $event->created_at->format('d M Y H:i:s') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-4 text-center text-slate-400 italic">
                                    Belum ada antrean event outbox. Event dibuat otomatis saat Form Builder dipublikasikan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>



    <!-- Schema Versions & Column Mappings -->
    <div class="mt-12 space-y-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-3">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-bold text-slate-800">Versi Schema & Pemetaan Kolom Tab Aktif</h2>
                    <span class="px-2 py-0.5 text-[11px] font-semibold rounded bg-emerald-100 text-emerald-800">
                        Versi Terbaru
                    </span>
                </div>
                <p class="text-xs text-slate-500">Struktur header dan kontrak kolom outbound aktif untuk tab tahun pendaftaran saat ini.</p>
            </div>
            @if (($totalSchemaCount ?? 0) > 1)
                <a href="{{ route('admin.spreadsheet-connections.schema-history', $connection) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-blue-600 hover:border-blue-300">
                    <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span>Lihat Log & Riwayat Versi ({{ $totalSchemaCount }} Versi) &rarr;</span>
                </a>
            @endif
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
                <div class="flex items-center gap-2.5">
                    <h2 class="text-lg font-bold text-slate-800">Pemantauan Antrean & Status Sinkronisasi Baris</h2>
                    <span id="sync-live-badge" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200" title="Auto-refresh aktif setiap 5 detik">
                        <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        Live Update 5s
                    </span>
                    <button
                        type="button"
                        id="btn-manual-sync-refresh"
                        class="p-1 rounded text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
                        title="Perbarui data sekarang"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"/>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                        </svg>
                    </button>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">Pelacakan status outbound outbox, identitas stabil SERTIF_SYNC_ID, dan riwayat retry.</p>
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
        <div class="grid grid-cols-2 sm:grid-cols-6 gap-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Total Baris</span>
                <p id="stat-total-rows" class="text-xl font-bold text-slate-800 mt-1">{{ number_format($stats['total'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Tersinkronisasi</span>
                <p id="stat-synced-rows" class="text-xl font-bold text-emerald-700 mt-1">{{ number_format($stats['synced'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Antrean Pending</span>
                <p id="stat-pending-rows" class="text-xl font-bold text-blue-700 mt-1">{{ number_format($stats['pending'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-amber-700">Dalam Retry</span>
                <p id="stat-retrying-rows" class="text-xl font-bold text-amber-700 mt-1">{{ number_format($stats['retrying'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-rose-700">Gagal Permanen</span>
                <p id="stat-failed-rows" class="text-xl font-bold text-rose-700 mt-1">{{ number_format($stats['failed'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-amber-300 bg-amber-50/70 p-4 shadow-sm">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Antrean Dibekukan</span>
                <p id="stat-paused-rows" class="text-xl font-bold text-amber-800 mt-1">{{ number_format($pausedQueuesSummary['total_count'] ?? 0) }}</p>
            </div>
        </div>

        @if (!empty($pausedQueuesSummary['total_count']) && $pausedQueuesSummary['total_count'] > 0)
            <!-- Panel Rekonsiliasi Target & Antrean Dibekukan (HOTFIX 1) -->
            <div class="rounded-xl border border-amber-300 bg-amber-50/80 p-5 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-bold text-amber-950 flex items-center gap-2">
                            <svg class="h-4 w-4 text-amber-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="10" y1="15" x2="10" y2="9"/>
                                <line x1="14" y1="15" x2="14" y2="9"/>
                            </svg>
                            <span>Antrean Sinkronisasi Dibekukan (paused_target_rebind)</span>
                        </h3>
                        <p class="text-xs text-amber-800 mt-1">
                            Terdapat <strong>{{ $pausedQueuesSummary['total_count'] }}</strong> baris sinkronisasi yang dibekukan karena pergantian target Spreadsheet. Data pendaftar di database tetap aman dan tidak terhapus.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Opsi 1: Pindahkan ke Target Baru -->
                        <form action="{{ route('admin.spreadsheet-connections.reconcile-queue', $connection) }}" method="POST" onsubmit="return confirm('Pindahkan seluruh antrean yang dibekukan ke target Spreadsheet baru? Sistem akan melakukan remote deduplication check.');">
                            @csrf
                            <input type="hidden" name="decision" value="rebind_new_target">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="9 18 15 12 9 6"/>
                                </svg>
                                Pindahkan Semua ke Target Baru
                            </button>
                        </form>

                        <!-- Opsi 2: Batalkan Antrean -->
                        <form action="{{ route('admin.spreadsheet-connections.reconcile-queue', $connection) }}" method="POST" onsubmit="return confirm('Batalkan seluruh antrean yang dibekukan? Data pendaftaran TIDAK akan dihapus dari sistem.');">
                            @csrf
                            <input type="hidden" name="decision" value="cancel_sync">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50 transition"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                                Batalkan Semua Antrean
                            </button>
                        </form>
                    </div>
                </div>

                @if (!empty($pausedQueuesSummary['by_tab']))
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="font-semibold text-amber-900">Distribusi Tab:</span>
                        @foreach ($pausedQueuesSummary['by_tab'] as $tab => $count)
                            <span class="rounded bg-white/80 border border-amber-200 px-2 py-0.5 text-amber-900 font-mono text-[11px]">
                                {{ $tab }}: <strong>{{ $count }}</strong>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

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
                    <tbody id="sync-rows-tbody" class="divide-y divide-slate-100 bg-white">
                        @include('admin.spreadsheet-connections.partials.sync-rows', ['rowSyncs' => $rowSyncs, 'connection' => $connection])
                    </tbody>
                </table>
            </div>

            <div id="sync-pagination-container">
                @include('admin.spreadsheet-connections.partials.sync-pagination', ['rowSyncs' => $rowSyncs])
            </div>
        </div>
    </div>
</div>



    <!-- Modal: Atur Profil Header Tab Eksplisit -->
    <div id="modal-tab-profile" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 border border-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <h3 class="text-sm font-bold text-slate-800" id="modal-profile-title">Atur Profil Header Tab Eksplisit</h3>
                <button type="button" onclick="closeTabProfileModal()" class="text-slate-400 hover:text-slate-600 text-lg">&times;</button>
            </div>
            <form id="form-tab-profile" onsubmit="submitTabProfile(event)">
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Sheet ID Google (Numerik Stabil)</label>
                        <input
                            type="number"
                            id="input-sheet-id"
                            required
                            placeholder="misal: 0 atau 12345678"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-800 focus:border-indigo-500 focus:outline-none font-mono"
                        />
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Nama Tab Spreadsheet</label>
                        <input
                            type="text"
                            id="input-tab-name"
                            required
                            placeholder="misal: 2026 atau Pendaftaran"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-800 focus:border-indigo-500 focus:outline-none"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Baris Header</label>
                            <input
                                type="number"
                                id="input-header-row"
                                min="1"
                                max="1000"
                                value="10"
                                required
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-800 focus:border-indigo-500 focus:outline-none font-mono"
                            />
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Baris Mulai Data</label>
                            <input
                                type="number"
                                id="input-data-start-row"
                                min="2"
                                max="1001"
                                value="11"
                                required
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-800 focus:border-indigo-500 focus:outline-none font-mono"
                            />
                        </div>
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeTabProfileModal()" class="px-3 py-1.5 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 text-xs font-semibold">
                        Batal
                    </button>
                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 text-xs font-semibold shadow-sm">
                        Simpan Profil Tab
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Pratinjau Dampak Perubahan Skema (Schema Diff) -->
    <div id="modal-schema-diff" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full p-6 border border-slate-200 max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Pratinjau Dampak Perubahan Skema (Schema Diff)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Analisis non-destruktif: tidak ada kolom yang dihapus dari Form Builder.</p>
                </div>
                <button type="button" onclick="closeSchemaDiffModal()" class="text-slate-400 hover:text-slate-600 text-lg">&times;</button>
            </div>
            <div id="diff-summary-badges" class="flex flex-wrap gap-2 mb-4 text-xs">
                <!-- Badges populated via AJAX -->
            </div>
            <div class="overflow-y-auto flex-1 border border-slate-200 rounded-lg p-3 bg-slate-50/50 text-xs space-y-2" id="diff-items-container">
                <!-- Items populated via AJAX -->
            </div>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-end">
                <button type="button" onclick="closeSchemaDiffModal()" class="px-4 py-2 rounded-lg bg-slate-800 text-white hover:bg-slate-900 text-xs font-semibold">
                    Tutup Pratinjau
                </button>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ----------------------------------------------------
    // Endpoints & Configurations
    // ----------------------------------------------------
    const urlTabs = @json(route('admin.spreadsheet-connections.tabs', $connection));
    const urlInspectColumns = @json(route('admin.spreadsheet-connections.inspect-columns', $connection));
    const urlInjectHeaderIds = @json(route('admin.spreadsheet-connections.inject-header-ids', $connection));
    const urlSaveBrandColumns = @json(route('admin.spreadsheet-connections.save-brand-columns', $connection));
    const urlWorkspaceRows = @json(route('admin.spreadsheet-connections.workspace-rows', $connection));
    const urlDryRunReconcile = @json(route('admin.spreadsheet-connections.dry-run-reconcile', $connection));
    const urlBatchReconcile = @json(route('admin.spreadsheet-connections.batch-reconcile', $connection));
    const urlBatchRecover = @json(route('admin.spreadsheet-connections.batch-recover', $connection));
    const urlRecoverRow = @json(route('admin.spreadsheet-connections.recover-row', $connection));
    const urlTestCompositeParser = @json(route('admin.spreadsheet-connections.test-composite-parser', $connection));
    const urlDuplicateTab = @json(route('admin.spreadsheet-connections.duplicate-tab', $connection));
    const csrfToken = '{{ csrf_token() }}';

    // ----------------------------------------------------
    // State Variables
    // ----------------------------------------------------
    let allDiscoveredTabs = @json($preloadedTabs ?? []);
    const tabColumnCache = {};
    let activeTabCategory = 'all';
    let currentInspectedColumns = [];
    let wsAllRows = [];
    let wsSelectedIndices = new Set();
    let wsColorPaletteMap = {};
    let wsRangeRules = [];
    let wsCurrentFilter = 'all';
    let wsCurrentSourceFilter = 'no_id';

    // ----------------------------------------------------
    // DOM Elements - Langkah 1 (Columns & Headers)
    // ----------------------------------------------------
    const tabSelect = document.getElementById('scanner-tab-select');
    const headerRowInput = document.getElementById('ws-header-row');
    const btnInspectColumns = document.getElementById('btn-inspect-columns');
    const wsColLoading = document.getElementById('ws-col-loading');
    const wsColLoadingText = document.getElementById('ws-col-loading-text');
    const wsColResult = document.getElementById('ws-col-result');
    const wsHeaderIdAlert = document.getElementById('ws-header-id-alert');
    const wsTotalCols = document.getElementById('ws-total-cols');
    const wsCountStandard = document.getElementById('ws-count-standard');
    const wsCountCustom = document.getElementById('ws-count-custom');
    const wsCountSystem = document.getElementById('ws-count-system');
    const wsInspectedTab = document.getElementById('ws-inspected-tab');
    const wsInspectedHeaderRow = document.getElementById('ws-inspected-header-row');
    const wsColTbody = document.getElementById('ws-col-tbody');
    const btnSaveBrandColumns = document.getElementById('btn-save-brand-columns');

    // Sidebar & Navigation
    const wsSidebarTabsList = document.getElementById('ws-sidebar-tabs-list');
    const wsTabsCountBadge = document.getElementById('ws-tabs-count-badge');
    const wsTabSearch = document.getElementById('ws-tab-search');
    const wsTabCategoryFilter = document.getElementById('ws-tab-category-filter');
    const btnFetchTabs = document.getElementById('btn-fetch-tabs');

    // ----------------------------------------------------
    // DOM Elements - Langkah 2 (Rows, Colors & Reconcile)
    // ----------------------------------------------------
    const wsStartRowInput = document.getElementById('ws-start-row');
    const wsEndRowInput = document.getElementById('ws-end-row');
    const wsStatusSourceType = document.getElementById('ws-status-source-type');
    const wsStatusColWrapper = document.getElementById('ws-status-col-wrapper');
    const wsStatusColSelect = document.getElementById('ws-status-col-select');
    const btnScanWorkspace = document.getElementById('btn-scan-workspace');
    const btnStopScan = document.getElementById('btn-stop-scan');
    const wsDetectStatusOnly = document.getElementById('ws-detect-status-only');
    const wsTabLimitBadge = document.getElementById('ws-tab-limit-badge');
    let currentScanAbortController = null;
    const wsLoading = document.getElementById('ws-loading');
    const wsLoadingText = document.getElementById('ws-loading-text');
    const wsRulesCard = document.getElementById('ws-rules-card');
    const wsPaletteSection = document.getElementById('ws-palette-section');
    const wsPaletteContainer = document.getElementById('ws-palette-container');
    const wsPaletteCountBadge = document.getElementById('ws-palette-count-badge');
    const wsColumnValuesSection = document.getElementById('ws-column-values-section');
    const wsColumnValuesContainer = document.getElementById('ws-column-values-container');
    const wsColumnValuesCountBadge = document.getElementById('ws-column-values-count-badge');
    const wsRangesSection = document.getElementById('ws-ranges-section');
    const wsRangesContainer = document.getElementById('ws-ranges-container');
    const btnAddRangeRule = document.getElementById('btn-add-range-rule');
    const wsSortBy = document.getElementById('ws-sort-by');
    const wsSortDir = document.getElementById('ws-sort-dir');
    const btnApplyWorkspaceRules = document.getElementById('btn-apply-workspace-rules');
    let wsColumnValueMap = {};
    let wsRawColumnDistinct = {};
    const wsTableCard = document.getElementById('ws-table-card');
    const wsTableTbody = document.getElementById('ws-table-tbody');
    const wsSearchInput = document.getElementById('ws-search-input');
    const wsCheckAll = document.getElementById('ws-check-all');
    const wsSelectedCount = document.getElementById('ws-selected-count');
    const wsSelectedBreakdown = document.getElementById('ws-selected-breakdown');
    const btnSelectAllFiltered = document.getElementById('btn-select-all-filtered');
    const btnDryRunReconcile = document.getElementById('btn-dry-run-reconcile');
    const btnExecuteBatch = document.getElementById('btn-execute-batch');
    const btnBulkSyncWithoutId = document.getElementById('btn-bulk-sync-without-id');
    const btnBulkRecoverMissing = document.getElementById('btn-bulk-recover-missing');

    // ----------------------------------------------------
    // 1. POPULATE & FETCH TABS (Instant 0ms + Background Sync)
    // ----------------------------------------------------
    function populateTabsUi(autoSelectFirst = true) {
        if (!tabSelect) return false;
        const prevValue = tabSelect.value;
        tabSelect.innerHTML = '';

        if (!allDiscoveredTabs || allDiscoveredTabs.length === 0) {
            tabSelect.innerHTML = '<option value="">-- Tekan \'Pindai Daftar Tab\' terlebih dahulu --</option>';
            if (wsTabsCountBadge) wsTabsCountBadge.textContent = '0 Tab';
            if (wsSidebarTabsList) {
                wsSidebarTabsList.innerHTML = '<div class="py-6 text-center text-xs text-slate-400 italic">Belum ada tab ditemukan.</div>';
            }
            return false;
        }

        const filterQuery = (wsTabSearch ? wsTabSearch.value : '').toLowerCase().trim();
        const filteredTabs = allDiscoveredTabs.filter(tab => {
            const nameLower = (tab.name || '').toLowerCase();
            const matchesQuery = !filterQuery || nameLower.includes(filterQuery);
            if (!matchesQuery) return false;
            if (activeTabCategory === 'reg') {
                return nameLower.includes('daftar') || nameLower.includes('form') || nameLower.includes('respon') || nameLower.includes('regist');
            } else if (activeTabCategory === 'other') {
                return !nameLower.includes('daftar') && !nameLower.includes('form') && !nameLower.includes('respon') && !nameLower.includes('regist');
            }
            return true;
        });

        if (wsTabsCountBadge) {
            wsTabsCountBadge.textContent = `${allDiscoveredTabs.length} Tab`;
        }

        // Render Dropdown options
        filteredTabs.forEach((tab, idx) => {
            const opt = document.createElement('option');
            opt.value = tab.name;
            opt.textContent = `${tab.name} (${tab.row_count || 0} baris)`;
            if (tab.header_row) opt.dataset.headerRow = tab.header_row;
            if (tab.data_start_row) opt.dataset.dataStartRow = tab.data_start_row;
            if (tab.last_row) opt.dataset.lastRow = tab.last_row;
            if (tab.row_count) opt.dataset.rowCount = tab.row_count;
            tabSelect.appendChild(opt);
        });

        // Retain previous selection or auto-select first tab
        let selectedTab = null;
        if (prevValue && Array.from(tabSelect.options).some(o => o.value === prevValue)) {
            tabSelect.value = prevValue;
            selectedTab = filteredTabs.find(t => t.name === prevValue);
        } else if (tabSelect.options.length > 0) {
            tabSelect.selectedIndex = 0;
            selectedTab = filteredTabs[0];
        }

        if (selectedTab) {
            if (headerRowInput && selectedTab.header_row) headerRowInput.value = selectedTab.header_row;
            if (wsStartRowInput && selectedTab.data_start_row) wsStartRowInput.value = selectedTab.data_start_row;
        }

        // Render Sidebar Cards
        if (wsSidebarTabsList) {
            wsSidebarTabsList.innerHTML = '';
            if (filteredTabs.length === 0) {
                wsSidebarTabsList.innerHTML = '<div class="py-6 text-center text-xs text-slate-400 italic">Tidak ada tab yang sesuai filter.</div>';
            } else {
                filteredTabs.forEach((tab) => {
                    const isSelected = selectedTab && tab.name === selectedTab.name;
                    const card = document.createElement('div');
                    card.className = `p-2.5 rounded-lg border bg-white hover:border-teal-400 hover:bg-teal-50/40 cursor-pointer transition flex items-center justify-between text-xs group ${isSelected ? 'border-teal-500 ring-2 ring-teal-500 bg-teal-50/20' : 'border-slate-200'}`;
                    card.innerHTML = `
                        <div class="truncate mr-2">
                            <div class="font-semibold text-slate-800 group-hover:text-teal-900 truncate">${escapeHtml(tab.name)}</div>
                            <div class="text-[10px] text-slate-400 font-mono">${tab.row_count || 0} baris &bull; Hdr: ${tab.header_row || 10}</div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-teal-600 text-xs"></i>
                    `;
                    card.addEventListener('click', () => {
                        tabSelect.value = tab.name;
                        if (headerRowInput && tab.header_row) {
                            headerRowInput.value = tab.header_row;
                        }
                        if (wsStartRowInput && tab.data_start_row) {
                            wsStartRowInput.value = tab.data_start_row;
                        }
                        // Visual active state
                        wsSidebarTabsList.querySelectorAll('div').forEach(el => el.classList.remove('ring-2', 'ring-teal-500', 'border-teal-500', 'bg-teal-50/20'));
                        card.classList.add('ring-2', 'ring-teal-500', 'border-teal-500', 'bg-teal-50/20');
                        updateTabBoundariesUi();
                    });
                    wsSidebarTabsList.appendChild(card);
                });
            }
        }
        updateTabBoundariesUi();
        return true;
    }

    function getActiveTabInfo() {
        const tabName = tabSelect ? tabSelect.value : '';
        if (!tabName || !allDiscoveredTabs) return null;
        return allDiscoveredTabs.find(t => t.name === tabName) || null;
    }

    function updateTabBoundariesUi() {
        const activeTab = getActiveTabInfo();
        if (!activeTab) {
            if (wsTabLimitBadge) wsTabLimitBadge.textContent = 'Maks: -';
            return;
        }

        const startRow = activeTab.data_start_row || 11;
        const maxRow = activeTab.last_row || activeTab.row_count || 5000;

        if (wsStartRowInput) {
            wsStartRowInput.min = startRow;
            wsStartRowInput.max = maxRow;
            const curStart = parseInt(wsStartRowInput.value, 10);
            if (!curStart || curStart < startRow) {
                wsStartRowInput.value = startRow;
            }
        }

        const isStatusOnly = wsDetectStatusOnly && wsDetectStatusOnly.checked;
        if (wsEndRowInput) {
            wsEndRowInput.min = startRow;
            wsEndRowInput.max = maxRow;
            if (isStatusOnly) {
                wsEndRowInput.value = Math.min(startRow + 49, maxRow);
            } else {
                const curEnd = parseInt(wsEndRowInput.value, 10);
                if (!curEnd || curEnd > maxRow || curEnd < startRow) {
                    wsEndRowInput.value = Math.min(startRow + 99, maxRow);
                }
            }
        }

        if (wsTabLimitBadge) {
            wsTabLimitBadge.textContent = `Maks: #${maxRow}`;
            wsTabLimitBadge.title = `Tab "${activeTab.name}" memiliki ${maxRow} baris riil di spreadsheet.`;
        }

        // Safeguard tombol 100 Baris Berikutnya agar tidak looping tak terhenti
        const curStart = parseInt(wsStartRowInput ? wsStartRowInput.value : startRow, 10) || startRow;
        if (btnNextBlock) {
            const hasNext = curStart + 100 <= maxRow;
            btnNextBlock.disabled = !hasNext;
            btnNextBlock.classList.toggle('opacity-40', !hasNext);
            btnNextBlock.classList.toggle('cursor-not-allowed', !hasNext);
            btnNextBlock.title = hasNext ? 'Lanjut ke blok 100 baris berikutnya' : 'Sudah mencapai baris akhir spreadsheet';
        }
    }

    async function fetchTabsList(forceRefresh = false) {
        if (!tabSelect) return;
        
        // If we already have tabs, don't show full blocking spinner, only a subtle sync icon
        const isPreloaded = allDiscoveredTabs && allDiscoveredTabs.length > 0;
        if (!isPreloaded) {
            tabSelect.innerHTML = '<option value="">-- Sedang memuat daftar tab... --</option>';
            if (wsSidebarTabsList) {
                wsSidebarTabsList.innerHTML = '<div class="py-8 text-center text-xs text-slate-400 italic"><i class="fas fa-spinner fa-spin text-teal-600 text-lg mb-1 block"></i>Memuat daftar tab...</div>';
            }
        }

        const iconFetch = btnFetchTabs ? btnFetchTabs.querySelector('i') : null;
        if (iconFetch) iconFetch.classList.add('fa-spin');

        try {
            const url = forceRefresh ? `${urlTabs}?refresh=1` : urlTabs;
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (data.success && Array.isArray(data.tabs)) {
                allDiscoveredTabs = data.tabs;
                populateTabsUi(false);
            } else if (!isPreloaded) {
                tabSelect.innerHTML = '<option value="">Gagal memuat tab spreadsheet</option>';
            }
        } catch (err) {
            console.error('Fetch tabs error:', err);
            if (!isPreloaded) {
                tabSelect.innerHTML = '<option value="">Koneksi timeout / gagal memuat tab</option>';
            }
        } finally {
            if (iconFetch) iconFetch.classList.remove('fa-spin');
        }
    }

    // Tab dropdown change listener
    if (tabSelect) {
        tabSelect.addEventListener('change', () => {
            const selectedOpt = tabSelect.options[tabSelect.selectedIndex];
            if (selectedOpt) {
                if (headerRowInput && selectedOpt.dataset.headerRow) {
                    headerRowInput.value = selectedOpt.dataset.headerRow;
                }
                if (wsStartRowInput && selectedOpt.dataset.dataStartRow) {
                    wsStartRowInput.value = selectedOpt.dataset.dataStartRow;
                }
                updateTabBoundariesUi();
            }
        });
    }

    // Tab Filter listeners
    if (wsTabSearch) {
        wsTabSearch.addEventListener('input', () => populateTabsUi(false));
    }
    if (wsTabCategoryFilter) {
        wsTabCategoryFilter.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                wsTabCategoryFilter.querySelectorAll('button').forEach(b => {
                    b.className = 'px-2 py-0.5 rounded font-semibold bg-white text-slate-600 border border-slate-200 hover:bg-slate-100';
                });
                btn.className = 'px-2 py-0.5 rounded font-semibold bg-slate-800 text-white';
                activeTabCategory = btn.dataset.category;
                populateTabsUi(false);
            });
        });
    }
    if (btnFetchTabs) {
        btnFetchTabs.addEventListener('click', () => fetchTabsList(true));
    }

    // ----------------------------------------------------
    // 2. LANGKAH 1: INSPEKSI STRUKTUR KOLOM & HEADER ID
    // ----------------------------------------------------
    function renderInspectedColumnsResult(data, tab, headerRow) {
        currentInspectedColumns = data.columns || [];

        // Populate status column select in Step 2 if user chooses column mode
        if (wsStatusColSelect) {
            wsStatusColSelect.innerHTML = '<option value="">-- Pilih Kolom Status --</option>';
            currentInspectedColumns.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.header_name;
                opt.textContent = `Kolom ${c.col_letter}: ${c.header_name}`;
                if ((c.header_name || '').toLowerCase().includes('status') || (c.header_name || '').toLowerCase().includes('ket')) {
                    opt.selected = true;
                }
                wsStatusColSelect.appendChild(opt);
            });
        }

        if (wsInspectedTab) wsInspectedTab.textContent = tab;
        if (wsInspectedHeaderRow) wsInspectedHeaderRow.textContent = headerRow;

        const total = currentInspectedColumns.length;
        const countStd = currentInspectedColumns.filter(c => c.category === 'standard').length;
        const countCustom = currentInspectedColumns.filter(c => c.category === 'custom').length;
        const countSys = currentInspectedColumns.filter(c => c.category === 'system').length;

        if (wsTotalCols) wsTotalCols.textContent = total;
        if (wsCountStandard) wsCountStandard.textContent = countStd;
        if (wsCountCustom) wsCountCustom.textContent = countCustom;
        if (wsCountSystem) wsCountSystem.textContent = countSys;

        // Check if System Header IDs exist
        const hasSyncId = currentInspectedColumns.some(c => (c.header_name || '').toUpperCase() === 'SERTIF_SYNC_ID');
        const hasRegId = currentInspectedColumns.some(c => (c.header_name || '').toUpperCase() === 'SERTIF_REGISTRATION_ID');
        const isWriteReady = hasSyncId && hasRegId;

        if (wsHeaderIdAlert) {
            if (isWriteReady) {
                wsHeaderIdAlert.className = 'p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs';
                wsHeaderIdAlert.innerHTML = `
                    <div class="flex items-center gap-2">
                        <i class="fas fa-check-circle text-emerald-600 text-base"></i>
                        <div>
                            <strong>Header ID Sistem Siap (Write-Ready):</strong>
                            Kolom <code>SERTIF_SYNC_ID</code> dan <code>SERTIF_REGISTRATION_ID</code> sudah tersedia di Baris ${headerRow}. Sistem dapat menuliskan ID secara langsung.
                        </div>
                    </div>
                    <span class="px-2.5 py-1 rounded bg-emerald-100 text-emerald-800 font-bold uppercase text-[10px]">Siap Sinkronisasi</span>
                `;
            } else {
                wsHeaderIdAlert.className = 'p-3 rounded-lg bg-amber-50 border border-amber-300 text-amber-900 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs';
                wsHeaderIdAlert.innerHTML = `
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-amber-600 text-base"></i>
                        <div>
                            <strong>Kolom Header ID Sistem Belum Lengkap:</strong>
                            Spreadsheet ini belum memiliki kolom <code>SERTIF_SYNC_ID</code> / <code>SERTIF_REGISTRATION_ID</code> di Baris ${headerRow}. Klik tombol di samping untuk menyuntikkan kolom otomatis tanpa merusak data pendaftar.
                        </div>
                    </div>
                    <button type="button" id="btn-inject-header-ids" class="px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs shadow-xs transition flex items-center gap-1.5 shrink-0">
                        <i class="fas fa-syringe text-xs"></i>
                        Suntikkan Header ID Sistem
                    </button>
                `;
                const btnInject = document.getElementById('btn-inject-header-ids');
                if (btnInject) {
                    btnInject.addEventListener('click', () => injectHeaderIds(tab, headerRow));
                }
            }
        }

        // Render Table Body
        if (wsColTbody) {
            wsColTbody.innerHTML = '';
            currentInspectedColumns.forEach((c) => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-50 border-b border-slate-100';

                let badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
                let catLabel = 'Kustom';
                if (c.col_letter === 'AL' && (c.header_name || '').toUpperCase() === 'KET') {
                    badgeClass = 'bg-amber-50 text-amber-800 border-amber-200';
                    catLabel = 'Catatan Eksternal (Manual)';
                } else if (c.col_letter === 'AM' && (c.header_name || '').toUpperCase() === 'KET') {
                    badgeClass = 'bg-blue-50 text-blue-800 border-blue-200';
                    catLabel = 'Status Sistem & Follow-up';
                } else if (c.category === 'external') {
                    badgeClass = 'bg-amber-50 text-amber-800 border-amber-200';
                    catLabel = 'Catatan Eksternal';
                } else if (c.category === 'standard') {
                    badgeClass = 'bg-emerald-50 text-emerald-800 border-emerald-200';
                    catLabel = 'Identitas Standar';
                } else if (c.category === 'system') {
                    badgeClass = 'bg-purple-50 text-purple-800 border-purple-200';
                    catLabel = 'ID Sistem SertIF';
                }

                tr.innerHTML = `
                    <td class="px-3 py-2 text-center font-mono font-bold text-slate-700">${c.col_letter}</td>
                    <td class="px-3 py-2 font-semibold text-slate-800">${escapeHtml(c.header_name)}</td>
                    <td class="px-3 py-2">
                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold border ${badgeClass}">
                            ${catLabel}
                        </span>
                    </td>
                    <td class="px-3 py-2 font-mono text-[11px] text-slate-500">${c.inferred_type || 'text'}</td>
                `;
                wsColTbody.appendChild(tr);
            });
        }

        if (wsColResult) wsColResult.classList.remove('hidden');
    }

    async function inspectColumns() {
        const tab = tabSelect ? tabSelect.value : '';
        const headerRow = headerRowInput ? parseInt(headerRowInput.value, 10) : 10;

        if (!tab) {
            alert('Pilih tab spreadsheet terlebih dahulu!');
            return;
        }

        const cacheKey = `${tab}_${headerRow}`;
        if (tabColumnCache[cacheKey]) {
            renderInspectedColumnsResult(tabColumnCache[cacheKey], tab, headerRow);
            return;
        }

        if (wsColLoading) wsColLoading.classList.remove('hidden');
        if (wsColResult) wsColResult.classList.add('hidden');
        if (btnInspectColumns) btnInspectColumns.disabled = true;

        try {
            const url = `${urlInspectColumns}?tab_name=${encodeURIComponent(tab)}&header_row=${headerRow}`;
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (data.success) {
                tabColumnCache[cacheKey] = data;
                renderInspectedColumnsResult(data, tab, headerRow);
            } else {
                alert('Gagal mengidentifikasi kolom: ' + (data.message || 'Terjadi kesalahan'));
            }
        } catch (e) {
            console.error('Inspect columns error:', e);
            alert('Koneksi timeout saat membaca kolom spreadsheet.');
        } finally {
            if (wsColLoading) wsColLoading.classList.add('hidden');
            if (btnInspectColumns) btnInspectColumns.disabled = false;
        }
    }

    async function injectHeaderIds(tab, headerRow) {
        if (!confirm(`Suntikkan kolom SERTIF_SYNC_ID dan SERTIF_REGISTRATION_ID ke baris ${headerRow} pada tab "${tab}"?`)) {
            return;
        }

        const btnInject = document.getElementById('btn-inject-header-ids');
        if (btnInject) btnInject.disabled = true;

        try {
            const res = await fetch(urlInjectHeaderIds, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ tab_name: tab, header_row: headerRow })
            });
            const data = await res.json();
            if (data.success) {
                alert('Kolom Header ID Sistem berhasil disuntikkan ke spreadsheet!');
                delete tabColumnCache[`${tab}_${headerRow}`];
                inspectColumns();
            } else {
                alert('Gagal menyuntikkan Header ID: ' + (data.message || 'Terjadi kesalahan'));
            }
        } catch (err) {
            console.error('Inject Header error:', err);
            alert('Terjadi kesalahan jaringan saat menyuntikkan Header ID.');
        } finally {
            if (btnInject) btnInject.disabled = false;
        }
    }

    async function saveBrandColumns() {
        const tab = tabSelect ? tabSelect.value : '';
        const headerRow = headerRowInput ? parseInt(headerRowInput.value, 10) : 10;

        if (!currentInspectedColumns || currentInspectedColumns.length === 0) {
            alert('Lakukan inspeksi struktur kolom terlebih dahulu!');
            return;
        }

        if (btnSaveBrandColumns) btnSaveBrandColumns.disabled = true;

        try {
            const res = await fetch(urlSaveBrandColumns, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    tab_name: tab,
                    header_row: headerRow,
                    columns: currentInspectedColumns
                })
            });
            const data = await res.json();
            if (data.success) {
                alert('Struktur kolom berhasil disimpan dan diterapkan ke Formulir Pendaftaran Brand ini!');
            } else {
                alert('Gagal menyimpan formulir brand: ' + (data.message || 'Terjadi kesalahan'));
            }
        } catch (e) {
            console.error('Save brand columns error:', e);
            alert('Terjadi kesalahan jaringan saat menyimpan formulir.');
        } finally {
            if (btnSaveBrandColumns) btnSaveBrandColumns.disabled = false;
        }
    }

    if (btnInspectColumns) btnInspectColumns.addEventListener('click', inspectColumns);
    if (btnSaveBrandColumns) btnSaveBrandColumns.addEventListener('click', saveBrandColumns);

    // ----------------------------------------------------
    // 3. LANGKAH 2: PINDAI BARIS DATA, PALET WARNA & MASUKKAN SERTIF
    // ----------------------------------------------------
    // Status source type toggle
    function updateStatusSourceUi() {
        const srcType = wsStatusSourceType ? wsStatusSourceType.value : 'color';

        if (wsStatusColWrapper) {
            if (srcType === 'column') {
                wsStatusColWrapper.classList.remove('hidden');
            } else {
                wsStatusColWrapper.classList.add('hidden');
            }
        }

        if (wsPaletteSection) {
            if (srcType === 'color') wsPaletteSection.classList.remove('hidden');
            else wsPaletteSection.classList.add('hidden');
        }

        if (wsColumnValuesSection) {
            if (srcType === 'column') {
                wsColumnValuesSection.classList.remove('hidden');
                renderColumnValuesMapUi();
            } else {
                wsColumnValuesSection.classList.add('hidden');
            }
        }

        if (wsRangesSection) {
            if (srcType === 'range') {
                wsRangesSection.classList.remove('hidden');
                if (wsRangeRules.length === 0) {
                    const startVal = parseInt(wsStartRowInput ? wsStartRowInput.value : 11, 10) || 11;
                    wsRangeRules.push({ start: startVal, end: startVal + 49, status: 'waiting' });
                    renderRangeRulesUi();
                }
            } else {
                wsRangesSection.classList.add('hidden');
            }
        }

        if (wsAllRows && wsAllRows.length > 0) {
            applyWorkspaceRules();
        }
    }

    if (wsStatusSourceType) {
        wsStatusSourceType.addEventListener('change', updateStatusSourceUi);
    }

    if (wsStatusColSelect) {
        wsStatusColSelect.addEventListener('change', function () {
            renderColumnValuesMapUi();
            if (wsAllRows && wsAllRows.length > 0) {
                applyWorkspaceRules();
            }
        });
    }

    // Preset Jangkauan Baris Cepat (+100, +200, +300, +500)
    document.querySelectorAll('.btn-range-preset').forEach(btn => {
        btn.addEventListener('click', function () {
            const range = parseInt(this.dataset.range, 10);
            const activeTab = getActiveTabInfo();
            const maxRow = activeTab ? (activeTab.last_row || activeTab.row_count || 10000) : 10000;
            const startVal = wsStartRowInput ? (parseInt(wsStartRowInput.value, 10) || 11) : 11;
            if (wsEndRowInput) {
                wsEndRowInput.value = Math.min(startVal + range - 1, maxRow);
            }
            document.querySelectorAll('.btn-range-preset').forEach(b => {
                b.className = 'btn-range-preset px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold border border-slate-200 transition';
            });
            this.className = 'btn-range-preset px-2.5 py-1 rounded bg-teal-50 hover:bg-teal-100 text-teal-800 font-bold border border-teal-300 transition';
            updateTabBoundariesUi();
        });
    });

    // Navigasi Blok « 100 Sebelumnya / 100 Berikutnya » (Terkunci Sesuai Baris Tab Spreadsheet)
    const btnPrevBlock = document.getElementById('btn-prev-block');
    const btnNextBlock = document.getElementById('btn-next-block');

    if (btnPrevBlock) {
        btnPrevBlock.addEventListener('click', function () {
            const activeTab = getActiveTabInfo();
            const minStart = activeTab ? (activeTab.data_start_row || 11) : 11;
            const maxRow = activeTab ? (activeTab.last_row || activeTab.row_count || 10000) : 10000;
            const startVal = wsStartRowInput ? (parseInt(wsStartRowInput.value, 10) || minStart) : minStart;
            const newStart = Math.max(minStart, startVal - 100);
            if (wsStartRowInput) wsStartRowInput.value = newStart;
            if (wsEndRowInput) wsEndRowInput.value = Math.min(newStart + 99, maxRow);
            updateTabBoundariesUi();
            if (btnScanWorkspace) btnScanWorkspace.click();
        });
    }

    if (btnNextBlock) {
        btnNextBlock.addEventListener('click', function () {
            const activeTab = getActiveTabInfo();
            const maxRow = activeTab ? (activeTab.last_row || activeTab.row_count || 10000) : 10000;
            const startVal = wsStartRowInput ? (parseInt(wsStartRowInput.value, 10) || 11) : 11;
            const newStart = startVal + 100;
            if (newStart > maxRow) {
                alert(`Pencarian dihentikan: Sudah mencapai batas baris data terakhir di tab ini (Baris #${maxRow}). Tidak ada baris data berikutnya.`);
                updateTabBoundariesUi();
                return;
            }
            if (wsStartRowInput) wsStartRowInput.value = newStart;
            if (wsEndRowInput) wsEndRowInput.value = Math.min(newStart + 99, maxRow);
            updateTabBoundariesUi();
            if (btnScanWorkspace) btnScanWorkspace.click();
        });
    }

    if (wsDetectStatusOnly) {
        wsDetectStatusOnly.addEventListener('change', function () {
            if (this.checked) {
                const activeTab = getActiveTabInfo();
                const maxRow = activeTab ? (activeTab.last_row || activeTab.row_count || 10000) : 10000;
                const minStart = activeTab ? (activeTab.data_start_row || 11) : 11;
                const startVal = wsStartRowInput ? (parseInt(wsStartRowInput.value, 10) || minStart) : minStart;
                if (wsEndRowInput) {
                    wsEndRowInput.value = Math.min(startVal + 49, maxRow);
                }
            }
            updateTabBoundariesUi();
        });
    }
    if (wsStartRowInput) {
        wsStartRowInput.addEventListener('input', updateTabBoundariesUi);
    }
    if (btnStopScan) {
        btnStopScan.addEventListener('click', function () {
            if (currentScanAbortController) {
                currentScanAbortController.abort();
                currentScanAbortController = null;
            }
            if (wsLoading) wsLoading.classList.add('hidden');
            if (btnStopScan) btnStopScan.classList.add('hidden');
            if (btnScanWorkspace) btnScanWorkspace.disabled = false;
        });
    }

    async function scanWorkspaceRows() {
        const tab = tabSelect ? tabSelect.value : '';
        const activeTab = getActiveTabInfo();
        const headerRow = headerRowInput ? parseInt(headerRowInput.value, 10) : (activeTab?.header_row || 10);
        const maxRow = activeTab ? (activeTab.last_row || activeTab.row_count || 10000) : 10000;
        const minStart = activeTab ? (activeTab.data_start_row || 11) : 11;
        const startRow = wsStartRowInput ? parseInt(wsStartRowInput.value, 10) : minStart;
        const endRow = (wsEndRowInput && wsEndRowInput.value) ? parseInt(wsEndRowInput.value, 10) : null;
        const statusSource = wsStatusSourceType ? wsStatusSourceType.value : 'color';
        const fetchColors = (statusSource === 'color') ? 1 : 0;
        const wsSkipSyncedScan = document.getElementById('ws-skip-synced-scan');
        let skipSynced = wsSkipSyncedScan ? (wsSkipSyncedScan.checked ? 1 : 0) : 1;

        if (!tab) {
            alert('Pilih tab spreadsheet terlebih dahulu!');
            return;
        }

        if (startRow > maxRow) {
            alert(`Pencarian dihentikan: Baris mulai (#${startRow}) sudah melebihi batas akhir data spreadsheet (#${maxRow}).`);
            return;
        }

        let clampedEndRow = endRow ? Math.min(endRow, maxRow) : Math.min(startRow + 99, maxRow);
        if (wsDetectStatusOnly && wsDetectStatusOnly.checked) {
            // Mode Deteksi Status Saja: Sampel aman dibatasi maks 50 baris riil
            clampedEndRow = Math.min(startRow + 49, maxRow);
        }

        let pageSize = Math.min(500, Math.max(10, clampedEndRow - startRow + 1));

        if (currentScanAbortController) {
            currentScanAbortController.abort();
        }
        currentScanAbortController = new AbortController();

        if (wsLoading) wsLoading.classList.remove('hidden');
        if (wsRulesCard) wsRulesCard.classList.add('hidden');
        if (wsTableCard) wsTableCard.classList.add('hidden');
        if (btnScanWorkspace) btnScanWorkspace.disabled = true;
        if (btnStopScan) btnStopScan.classList.remove('hidden');

        try {
            const url = `${urlWorkspaceRows}?tab_name=${encodeURIComponent(tab)}&start_row=${startRow}&end_row=${clampedEndRow}&header_row=${headerRow}&page_size=${pageSize}&fetch_colors=${fetchColors}&skip_synced=${skipSynced}`;
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: currentScanAbortController.signal
            });
            const data = await res.json();

            if (!res.ok) {
                const errMsg = data?.message || (data?.errors ? Object.values(data.errors).flat().join(', ') : `HTTP Error ${res.status}`);
                alert('Gagal memindai baris: ' + errMsg);
                return;
            }

            const rows = Array.isArray(data.rows) ? data.rows : (Array.isArray(data.data?.rows) ? data.data.rows : []);

            if (data.success && rows.length >= 0 && (Array.isArray(data.rows) || Array.isArray(data.data?.rows))) {
                wsAllRows = rows.map(r => {
                    const rowBg = (r.row_background || r.bg_color || r.raw_background || '#ffffff').toUpperCase();
                    const rowStatus = r.status || r.inferred_status || 'unassigned';
                    const rawData = r.data || r.raw_data || {};
                    const syncId = r.sync_id || r.external_sync_id || null;
                    const matchedId = r.matched_local_id || null;
                    const isMissing = !!(r.is_missing_local !== undefined ? r.is_missing_local : (syncId && !matchedId));
                    return {
                        ...r,
                        bg_color: rowBg,
                        row_background: rowBg,
                        inferred_status: rowStatus,
                        status: rowStatus,
                        raw_data: rawData,
                        data: rawData,
                        external_sync_id: syncId,
                        sync_id: syncId,
                        matched_local_id: matchedId,
                        is_missing_local: isMissing,
                        remote_registration_id: r.remote_registration_id || null,
                    };
                });
                wsSelectedIndices.clear();

                // 1. Process server palette (every unique color 1 time)
                if (data.palette && Array.isArray(data.palette) && data.palette.length > 0) {
                    updatePaletteMapFromServer(data.palette);
                } else {
                    buildPaletteMapFromRows(wsAllRows);
                }
                renderPaletteMapUi();

                // 2. Process server distinct column values (every unique text value 1 time)
                if (data.column_distinct_values && typeof data.column_distinct_values === 'object') {
                    wsRawColumnDistinct = data.column_distinct_values;
                    renderColumnValuesMapUi();
                }

                // 3. Ensure UI sections match current status source type
                updateStatusSourceUi();

                // 4. Apply rules to candidate rows
                applyWorkspaceRules();

                if (wsRulesCard) wsRulesCard.classList.remove('hidden');
                if (wsTableCard) wsTableCard.classList.remove('hidden');
            } else {
                const errMsg = data.message || (data.data && data.data.message) || (data.errors ? Object.values(data.errors).flat().join(', ') : 'Format respons data tidak valid dari server');
                alert('Gagal memindai baris: ' + errMsg);
            }
        } catch (err) {
            if (err.name === 'AbortError') {
                console.log('Pencarian spreadsheet dihentikan oleh pengguna.');
                return;
            }
            console.error('Scan workspace error:', err);
            alert('Gagal memindai data spreadsheet: ' + (err.message || 'Periksa koneksi jaringan.'));
        } finally {
            if (wsLoading) wsLoading.classList.add('hidden');
            if (btnStopScan) btnStopScan.classList.add('hidden');
            if (btnScanWorkspace) btnScanWorkspace.disabled = false;
            currentScanAbortController = null;
        }
    }

    function renderRangeRulesUi() {
        if (!wsRangesContainer) return;
        wsRangesContainer.innerHTML = '';
        if (wsRangeRules.length === 0) {
            wsRangesContainer.innerHTML = '<p class="text-[11px] text-slate-400 italic">Belum ada partisi rentang baris.</p>';
            return;
        }

        wsRangeRules.forEach((rule, idx) => {
            const div = document.createElement('div');
            div.className = 'flex items-center gap-1.5 p-1.5 rounded-lg border border-slate-200 bg-white text-xs';
            div.innerHTML = `
                <span class="text-slate-500 font-mono text-[10px]">Baris:</span>
                <input type="number" class="w-14 rounded border border-slate-300 px-1 py-0.5 text-xs font-mono rule-start" value="${rule.start}" min="1">
                <span class="text-slate-400">-</span>
                <input type="number" class="w-14 rounded border border-slate-300 px-1 py-0.5 text-xs font-mono rule-end" value="${rule.end}" min="1">
                <select class="rounded border border-slate-300 px-1.5 py-0.5 text-xs rule-status flex-1">
                    <option value="waiting" ${rule.status === 'waiting' ? 'selected' : ''}>Calon (waiting)</option>
                    <option value="accepted" ${rule.status === 'accepted' ? 'selected' : ''}>Diterima (accepted)</option>
                    <option value="active" ${rule.status === 'active' ? 'selected' : ''}>Aktif (active)</option>
                    <option value="completed" ${rule.status === 'completed' ? 'selected' : ''}>Selesai (completed)</option>
                    <option value="rejected" ${rule.status === 'rejected' ? 'selected' : ''}>Ditolak (rejected)</option>
                    <option value="exited" ${rule.status === 'exited' ? 'selected' : ''}>Dikeluarkan (exited)</option>
                </select>
                <button type="button" class="text-rose-500 hover:text-rose-700 px-1 font-bold rule-remove" title="Hapus rentang">&times;</button>
            `;
            div.querySelector('.rule-start').addEventListener('change', function() { rule.start = parseInt(this.value, 10) || 1; applyWorkspaceRules(); });
            div.querySelector('.rule-end').addEventListener('change', function() { rule.end = parseInt(this.value, 10) || 1; applyWorkspaceRules(); });
            div.querySelector('.rule-status').addEventListener('change', function() { rule.status = this.value; applyWorkspaceRules(); });
            div.querySelector('.rule-remove').addEventListener('click', function() {
                wsRangeRules.splice(idx, 1);
                renderRangeRulesUi();
                applyWorkspaceRules();
            });
            wsRangesContainer.appendChild(div);
        });
    }

    if (btnAddRangeRule) {
        btnAddRangeRule.addEventListener('click', function() {
            const startVal = wsRangeRules.length > 0
                ? (wsRangeRules[wsRangeRules.length - 1].end + 1)
                : (parseInt(wsStartRowInput ? wsStartRowInput.value : 11, 10) || 11);
            wsRangeRules.push({ start: startVal, end: startVal + 49, status: 'waiting' });
            renderRangeRulesUi();
            applyWorkspaceRules();
        });
    }

    function updatePaletteMapFromServer(paletteList) {
        if (!Array.isArray(paletteList)) return;
        paletteList.forEach(p => {
            let hex = (p.hex || '#ffffff').toUpperCase();
            if (hex === 'TRANSPARENT') hex = '#FFFFFF';

            const existing = wsColorPaletteMap[hex];
            let status = (existing && existing.status !== 'unassigned') ? existing.status : 'unassigned';

            if (status === 'unassigned') {
                if (hex === '#00FF00' || hex === '#D9EAD3' || hex === '#B6D7A8' || hex === '#6AA84F') status = 'accepted';
                else if (hex === '#FFFF00' || hex === '#FFF2CC' || hex === '#FFE599' || hex === '#F1C232') status = 'waiting';
                else if (hex === '#FF0000' || hex === '#F4CCCC' || hex === '#E06666' || hex === '#CC0000') status = 'rejected';
                else if (hex === '#00FFFF' || hex === '#C9DAF8' || hex === '#A4C2F4' || hex === '#4A86E8') status = 'active';
            }

            wsColorPaletteMap[hex] = {
                hex: hex,
                name: p.name || hex,
                count: p.count || 0,
                status: status,
                is_transparent: !!p.is_transparent,
                is_white: !!p.is_white,
            };
        });
    }

    function buildPaletteMapFromRows(rows) {
        wsColorPaletteMap = {};
        rows.forEach(r => {
            let hex = (r.bg_color || r.row_background || '#ffffff').toUpperCase();
            if (hex === 'TRANSPARENT') hex = '#FFFFFF';
            if (!wsColorPaletteMap[hex]) {
                // Auto guess status based on common colors
                let guessedStatus = 'unassigned';
                if (hex === '#00FF00' || hex === '#D9EAD3' || hex === '#B6D7A8' || hex === '#6AA84F') guessedStatus = 'accepted';
                else if (hex === '#FFFF00' || hex === '#FFF2CC' || hex === '#FFE599' || hex === '#F1C232') guessedStatus = 'waiting';
                else if (hex === '#FF0000' || hex === '#F4CCCC' || hex === '#E06666' || hex === '#CC0000') guessedStatus = 'rejected';
                else if (hex === '#00FFFF' || hex === '#C9DAF8' || hex === '#A4C2F4' || hex === '#4A86E8') guessedStatus = 'active';

                wsColorPaletteMap[hex] = {
                    hex: hex,
                    count: 0,
                    status: guessedStatus
                };
            }
            wsColorPaletteMap[hex].count++;
        });
    }

    function renderPaletteMapUi() {
        if (!wsPaletteContainer) return;
        wsPaletteContainer.innerHTML = '';

        const keys = Object.keys(wsColorPaletteMap);
        if (wsPaletteCountBadge) {
            wsPaletteCountBadge.textContent = `${keys.length} Warna Terdeteksi`;
        }

        if (keys.length === 0) {
            wsPaletteContainer.innerHTML = '<p class="text-xs text-slate-400 italic">Tidak ada warna latar terdeteksi.</p>';
            return;
        }

        keys.forEach(hex => {
            const p = wsColorPaletteMap[hex];
            const div = document.createElement('div');
            div.className = 'flex items-center justify-between gap-2 p-2 rounded-lg border border-slate-200 bg-white text-xs';
            div.innerHTML = `
                <div class="flex items-center gap-2 truncate">
                    <span class="h-5 w-5 rounded border border-slate-300 shadow-2xs shrink-0" style="background-color: ${hex};"></span>
                    <span class="font-mono text-slate-700 text-[11px]">${hex}</span>
                    <span class="text-[10px] text-slate-400 shrink-0">(${p.count} baris)</span>
                </div>
                <select class="ws-palette-select rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 focus:outline-none" data-hex="${hex}">
                    <option value="unassigned" ${p.status === 'unassigned' ? 'selected' : ''}>-- Belum Ditentukan --</option>
                    <option value="waiting" ${p.status === 'waiting' ? 'selected' : ''}>Calon (waiting)</option>
                    <option value="accepted" ${p.status === 'accepted' ? 'selected' : ''}>Diterima (accepted)</option>
                    <option value="active" ${p.status === 'active' ? 'selected' : ''}>Aktif (active)</option>
                    <option value="completed" ${p.status === 'completed' ? 'selected' : ''}>Selesai (completed)</option>
                    <option value="rejected" ${p.status === 'rejected' ? 'selected' : ''}>Ditolak (rejected)</option>
                    <option value="exited" ${p.status === 'exited' ? 'selected' : ''}>Dikeluarkan (exited)</option>
                </select>
            `;
            div.querySelector('.ws-palette-select').addEventListener('change', function () {
                wsColorPaletteMap[hex].status = this.value;
                applyWorkspaceRules();
            });
            wsPaletteContainer.appendChild(div);
        });
    }

    function renderColumnValuesMapUi() {
        if (!wsColumnValuesContainer) return;
        wsColumnValuesContainer.innerHTML = '';

        const colKey = wsStatusColSelect ? wsStatusColSelect.value : '';
        if (!colKey || !wsRawColumnDistinct[colKey]) {
            wsColumnValuesContainer.innerHTML = '<p class="text-xs text-slate-400 italic">Pilih kolom status di atas untuk memetakan varian nilai kolom.</p>';
            if (wsColumnValuesCountBadge) wsColumnValuesCountBadge.textContent = '0 Varian Terdeteksi';
            return;
        }

        const distinctObj = wsRawColumnDistinct[colKey];
        const uniqueValues = Object.keys(distinctObj);

        if (wsColumnValuesCountBadge) {
            wsColumnValuesCountBadge.textContent = `${uniqueValues.length} Varian Terdeteksi`;
        }

        if (uniqueValues.length === 0) {
            wsColumnValuesContainer.innerHTML = '<p class="text-xs text-slate-400 italic">Tidak ada varian teks terdeteksi pada kolom ini.</p>';
            return;
        }

        if (!wsColumnValueMap[colKey]) {
            wsColumnValueMap[colKey] = {};
        }

        uniqueValues.forEach(valText => {
            const count = distinctObj[valText] || 0;
            const existing = wsColumnValueMap[colKey][valText];
            let status = (existing && existing.status !== 'unassigned') ? existing.status : 'unassigned';

            if (status === 'unassigned') {
                const lower = valText.toLowerCase();
                if (lower.includes('terima') || lower.includes('diterima') || lower.includes('accept') || lower.includes('acc') || lower.includes('lolos')) status = 'accepted';
                else if (lower.includes('calon') || lower.includes('wait') || lower.includes('pending') || lower.includes('tunda') || lower.includes('proses')) status = 'waiting';
                else if (lower.includes('aktif') || lower.includes('active') || lower.includes('masuk')) status = 'active';
                else if (lower.includes('tolak') || lower.includes('reject') || lower.includes('gagal')) status = 'rejected';
                else if (lower.includes('selesai') || lower.includes('graduat') || lower.includes('lulus') || lower.includes('alumni')) status = 'completed';
                else if (lower.includes('keluar') || lower.includes('exit')) status = 'exited';
            }

            wsColumnValueMap[colKey][valText] = {
                text: valText,
                count: count,
                status: status
            };

            const div = document.createElement('div');
            div.className = 'flex items-center justify-between gap-2 p-2 rounded-lg border border-slate-200 bg-white text-xs';
            div.innerHTML = `
                <div class="flex items-center gap-2 truncate">
                    <span class="inline-flex items-center justify-center h-5 w-5 rounded bg-teal-50 border border-teal-200 text-teal-700 shrink-0">
                        <i class="fas fa-font text-[10px]"></i>
                    </span>
                    <span class="font-bold text-slate-800 text-xs truncate max-w-[140px]" title="${escapeHtml(valText)}">${escapeHtml(valText)}</span>
                    <span class="text-[10px] text-slate-400 shrink-0">(${count} baris)</span>
                </div>
                <select class="ws-col-val-select rounded border border-slate-300 bg-white px-2 py-1 text-xs text-slate-800 focus:outline-none" data-col="${escapeHtml(colKey)}" data-val="${escapeHtml(valText)}">
                    <option value="unassigned" ${status === 'unassigned' ? 'selected' : ''}>-- Belum Ditentukan --</option>
                    <option value="waiting" ${status === 'waiting' ? 'selected' : ''}>Calon (waiting)</option>
                    <option value="accepted" ${status === 'accepted' ? 'selected' : ''}>Diterima (accepted)</option>
                    <option value="active" ${status === 'active' ? 'selected' : ''}>Aktif (active)</option>
                    <option value="completed" ${status === 'completed' ? 'selected' : ''}>Selesai (completed)</option>
                    <option value="rejected" ${status === 'rejected' ? 'selected' : ''}>Ditolak (rejected)</option>
                    <option value="exited" ${status === 'exited' ? 'selected' : ''}>Dikeluarkan (exited)</option>
                </select>
            `;
            div.querySelector('.ws-col-val-select').addEventListener('change', function () {
                wsColumnValueMap[colKey][valText].status = this.value;
                applyWorkspaceRules();
            });
            wsColumnValuesContainer.appendChild(div);
        });
    }

    function applyWorkspaceRules() {
        const statusSource = wsStatusSourceType ? wsStatusSourceType.value : 'color';

        if (statusSource === 'color') {
            // Apply color mapping to rows
            wsAllRows.forEach(r => {
                let hex = (r.bg_color || r.row_background || '#ffffff').toUpperCase();
                if (hex === 'TRANSPARENT') hex = '#FFFFFF';
                if (wsColorPaletteMap[hex] && wsColorPaletteMap[hex].status !== 'unassigned') {
                    r.inferred_status = wsColorPaletteMap[hex].status;
                    r.status = wsColorPaletteMap[hex].status;
                }
            });
        } else if (statusSource === 'column' && wsStatusColSelect && wsStatusColSelect.value) {
            // Apply column value mapping
            const colKey = wsStatusColSelect.value;
            const ruleMap = wsColumnValueMap[colKey] || {};
            wsAllRows.forEach(r => {
                const raw = r.data || r.raw_data || {};
                const cellVal = (raw[colKey] !== undefined && raw[colKey] !== null) ? raw[colKey].toString().trim() : '';
                if (ruleMap[cellVal] && ruleMap[cellVal].status && ruleMap[cellVal].status !== 'unassigned') {
                    r.inferred_status = ruleMap[cellVal].status;
                    r.status = ruleMap[cellVal].status;
                } else {
                    const lower = cellVal.toLowerCase();
                    if (lower.includes('terima') || lower.includes('diterima') || lower.includes('accept') || lower.includes('lolos') || lower.includes('acc')) r.inferred_status = 'accepted';
                    else if (lower.includes('calon') || lower.includes('wait') || lower.includes('pending') || lower.includes('proses')) r.inferred_status = 'waiting';
                    else if (lower.includes('aktif') || lower.includes('active') || lower.includes('masuk')) r.inferred_status = 'active';
                    else if (lower.includes('tolak') || lower.includes('reject') || lower.includes('gagal')) r.inferred_status = 'rejected';
                    else if (lower.includes('selesai') || lower.includes('graduat') || lower.includes('lulus') || lower.includes('alumni')) r.inferred_status = 'completed';
                    else if (lower.includes('keluar') || lower.includes('exit')) r.inferred_status = 'exited';
                    r.status = r.inferred_status;
                }
            });
        } else if (statusSource === 'range' && wsRangeRules.length > 0) {
            // Apply range partition rules
            wsAllRows.forEach(r => {
                const rowNo = parseInt(r.row_number, 10);
                const matched = wsRangeRules.find(rule => rowNo >= rule.start && rowNo <= rule.end);
                if (matched && matched.status !== 'unassigned') {
                    r.inferred_status = matched.status;
                    r.status = matched.status;
                }
            });
        }

        filterAndSortVisibleRows();
    }

    function filterAndSortVisibleRows() {
        const query = (wsSearchInput ? wsSearchInput.value : '').toLowerCase().trim();

        let filtered = wsAllRows.filter(r => {
            // Text search
            if (query) {
                const name = (r.display_name || '').toLowerCase();
                const contact = (r.display_contact || '').toLowerCase();
                const rowNo = (r.row_number || '').toString();
                if (!name.includes(query) && !contact.includes(query) && !rowNo.includes(query)) {
                    return false;
                }
            }

            // Status filter pill
            const st = r.inferred_status || 'unassigned';
            if (wsCurrentFilter !== 'all' && st !== wsCurrentFilter) {
                return false;
            }

            // Source/ID filter pill
            if (wsCurrentSourceFilter === 'no_id') {
                if (r.external_sync_id) return false;
            } else if (wsCurrentSourceFilter === 'missing_local') {
                if (!r.external_sync_id || r.matched_local_id) return false;
            } else if (wsCurrentSourceFilter === 'synced_intact') {
                if (!r.external_sync_id || !r.matched_local_id) return false;
            } else if (wsCurrentSourceFilter === 'synced') {
                if (!r.external_sync_id) return false;
            }

            return true;
        });

        // Sorting
        const sortBy = wsSortBy ? wsSortBy.value : '_row_number';
        const sortDir = wsSortDir ? wsSortDir.value : 'asc';

        filtered.sort((a, b) => {
            let valA = a[sortBy] ?? (a.raw_data && a.raw_data[sortBy]) ?? '';
            let valB = b[sortBy] ?? (b.raw_data && b.raw_data[sortBy]) ?? '';

            if (sortBy === '_row_number' || sortBy === 'row_number') {
                valA = parseInt(a.row_number, 10) || 0;
                valB = parseInt(b.row_number, 10) || 0;
            } else {
                valA = valA.toString().toLowerCase();
                valB = valB.toString().toLowerCase();
            }

            if (valA < valB) return sortDir === 'asc' ? -1 : 1;
            if (valA > valB) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        renderWorkspaceTable(filtered);
        updateFilterCounts();
    }

    function renderWorkspaceTable(rows) {
        if (!wsTableTbody) return;
        wsTableTbody.innerHTML = '';

        if (rows.length === 0) {
            wsTableTbody.innerHTML = `
                <tr>
                    <td colspan="6" class="py-8 text-center text-xs text-slate-400 italic">
                        Tidak ada baris yang sesuai kriteria pencarian / filter.
                    </td>
                </tr>
            `;
            return;
        }

        rows.forEach((r, idx) => {
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-slate-50 border-b border-slate-100 transition';

            const isChecked = wsSelectedIndices.has(r.row_number);
            const hex = (r.bg_color || '#ffffff').toUpperCase();
            const hasId = !!r.external_sync_id;
            const isMissingInDb = !!(r.is_missing_local || (hasId && !r.matched_local_id));

            tr.innerHTML = `
                <td class="px-3 py-2 text-center">
                    <input type="checkbox" class="ws-row-check rounded border-slate-300 text-teal-600 focus:ring-teal-500 h-4 w-4" data-row="${r.row_number}" ${isChecked ? 'checked' : ''} />
                </td>
                <td class="px-3 py-2 font-mono text-xs text-slate-700 flex items-center gap-1.5">
                    <span class="h-3 w-3 rounded-full border border-slate-300 shrink-0" style="background-color: ${hex};" title="Warna: ${hex}"></span>
                    <span>#${r.row_number}</span>
                </td>
                <td class="px-3 py-2">
                    <div class="font-bold text-slate-800 text-xs">${escapeHtml(r.display_name || '-')}</div>
                    ${r.external_sync_id ? `<div class="font-mono text-[10px] text-teal-700 mt-0.5">${escapeHtml(r.external_sync_id)}</div>` : ''}
                </td>
                <td class="px-3 py-2 text-xs text-slate-600">${escapeHtml(r.display_contact || '-')}</td>
                <td class="px-3 py-2">
                    <select class="ws-row-status-select text-xs rounded border border-slate-300 bg-white px-2 py-1 text-slate-800 focus:outline-none" data-row="${r.row_number}">
                        <option value="unassigned" ${(r.inferred_status || 'unassigned') === 'unassigned' ? 'selected' : ''}>-- Belum Ditentukan --</option>
                        <option value="waiting" ${r.inferred_status === 'waiting' ? 'selected' : ''}>Calon (waiting)</option>
                        <option value="accepted" ${r.inferred_status === 'accepted' ? 'selected' : ''}>Diterima (accepted)</option>
                        <option value="active" ${r.inferred_status === 'active' ? 'selected' : ''}>Aktif (active)</option>
                        <option value="completed" ${r.inferred_status === 'completed' ? 'selected' : ''}>Selesai (completed)</option>
                        <option value="rejected" ${r.inferred_status === 'rejected' ? 'selected' : ''}>Ditolak (rejected)</option>
                        <option value="exited" ${r.inferred_status === 'exited' ? 'selected' : ''}>Dikeluarkan (exited)</option>
                    </select>
                </td>
                <td class="px-3 py-2">
                    ${isMissingInDb
                        ? `<div class="flex flex-col gap-1 items-start">
                             <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300">
                                 <i class="fas fa-exclamation-triangle text-amber-600 text-[9px]"></i> Ber-ID (Hilang di DB)
                             </span>
                             <button type="button" class="btn-recover-single text-[10px] font-bold text-teal-700 hover:text-teal-900 underline flex items-center gap-1" data-row="${r.row_number}">
                                 <i class="fas fa-download text-[9px]"></i> Pulihkan ke SertIF
                             </button>
                           </div>`
                        : (hasId
                            ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200" title="Terhubung ke pendaftaran #${r.matched_local_id}">
                                 <i class="fas fa-check-circle text-[9px]"></i> Terhubung (#${r.matched_local_id || 'ID'})
                               </span>`
                            : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200"><i class="fas fa-plus text-[9px]"></i> Baris Baru</span>'
                        )
                    }
                </td>
            `;

            // Row checkbox listener
            tr.querySelector('.ws-row-check').addEventListener('change', function () {
                if (this.checked) wsSelectedIndices.add(r.row_number);
                else wsSelectedIndices.delete(r.row_number);
                updateSelectionStats();
            });

            // Status select listener
            tr.querySelector('.ws-row-status-select').addEventListener('change', function () {
                r.inferred_status = this.value;
                updateFilterCounts();
            });

            // Single row recover listener
            const btnSingleRecover = tr.querySelector('.btn-recover-single');
            if (btnSingleRecover) {
                btnSingleRecover.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    const rowNo = parseInt(this.dataset.row, 10);
                    const targetRow = wsAllRows.find(x => x.row_number === rowNo);
                    if (!targetRow) return;

                    if (!confirm(`Pulihkan dan tarik data baris #${rowNo} (${targetRow.display_name || targetRow.sync_id}) ke database SertIF dengan mempertahankan ID yang sudah ada?`)) {
                        return;
                    }

                    this.disabled = true;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin text-[9px]"></i> Memulihkan...';
                    try {
                        const tab = tabSelect ? tabSelect.value : '';
                        const res = await fetch(urlRecoverRow, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({
                                tab_name: tab,
                                row_index: targetRow.row_number,
                                sync_id: targetRow.external_sync_id || targetRow.sync_id,
                                registration_id: targetRow.remote_registration_id || null,
                                internship_status: targetRow.inferred_status !== 'unassigned' ? targetRow.inferred_status : null,
                                row_data: targetRow.raw_data || targetRow.data || {},
                                expected_checksum: targetRow.checksum || targetRow.content_checksum || null
                            })
                        });
                        const data = await res.json();
                        if (data.success) {
                            alert(data.message || `Baris #${rowNo} berhasil dipulihkan ke SertIF!`);
                            scanWorkspaceRows();
                        } else {
                            alert('Gagal memulihkan baris: ' + (data.message || 'Terjadi kesalahan'));
                            this.disabled = false;
                            this.innerHTML = '<i class="fas fa-download text-[9px]"></i> Pulihkan ke SertIF';
                        }
                    } catch (err) {
                        alert('Terjadi kesalahan jaringan saat memulihkan baris: ' + err.message);
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-download text-[9px]"></i> Pulihkan ke SertIF';
                    }
                });
            }

            wsTableTbody.appendChild(tr);
        });

        updateSelectionStats();
    }

    function updateSelectionStats() {
        if (!wsSelectedCount || !wsSelectedBreakdown) return;
        const count = wsSelectedIndices.size;
        wsSelectedCount.textContent = count;

        if (count === 0) {
            wsSelectedBreakdown.textContent = 'Belum ada baris yang dipilih. Centang baris untuk dimasukkan ke SertIF.';
            if (btnDryRunReconcile) btnDryRunReconcile.disabled = true;
            if (btnExecuteBatch) btnExecuteBatch.disabled = true;
        } else {
            wsSelectedBreakdown.textContent = `${count} baris siap diproses (simulasi pra-tulis, pendaftaran, atau pemulihan ID).`;
            if (btnDryRunReconcile) btnDryRunReconcile.disabled = false;
            if (btnExecuteBatch) btnExecuteBatch.disabled = false;
        }
    }

    function updateFilterCounts() {
        const counts = { all: wsAllRows.length, unassigned: 0, accepted: 0, active: 0, waiting: 0, completed: 0, rejected: 0, exited: 0 };
        let countNoId = 0;
        let countSynced = 0;
        let countMissingLocal = 0;
        let countSyncedIntact = 0;

        wsAllRows.forEach(r => {
            const st = r.inferred_status || 'unassigned';
            if (counts[st] !== undefined) counts[st]++;
            else counts.unassigned++;

            if (r.external_sync_id) {
                countSynced++;
                if (r.matched_local_id) {
                    countSyncedIntact++;
                } else {
                    countMissingLocal++;
                }
            } else {
                countNoId++;
            }
        });

        for (const [k, v] of Object.entries(counts)) {
            const el = document.getElementById(`count-${k}`);
            if (el) el.textContent = v;
        }

        const elNoId = document.getElementById('count-source-no-id');
        if (elNoId) elNoId.textContent = countNoId;
        const elSynced = document.getElementById('count-source-synced');
        if (elSynced) elSynced.textContent = countSynced;
        const elMissingLocal = document.getElementById('count-source-missing-local');
        if (elMissingLocal) elMissingLocal.textContent = countMissingLocal;
        const elSyncedIntact = document.getElementById('count-source-synced-intact');
        if (elSyncedIntact) elSyncedIntact.textContent = countSyncedIntact;
        const elSourceAll = document.getElementById('count-source-all');
        if (elSourceAll) elSourceAll.textContent = wsAllRows.length;
    }

    // Source/ID Filter buttons (⚡ Hanya Tanpa ID / Semua Baris / Sudah Punya ID)
    const sourceFilterPills = document.getElementById('ws-source-filter-pills');
    if (sourceFilterPills) {
        sourceFilterPills.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', function () {
                sourceFilterPills.querySelectorAll('button').forEach(b => {
                    b.className = 'px-2.5 py-1 rounded-md text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200';
                });
                this.className = 'px-2.5 py-1 rounded-md text-[11px] font-bold bg-teal-700 text-white shadow-xs';
                wsCurrentSourceFilter = this.dataset.sourceFilter || 'all';
                filterAndSortVisibleRows();
            });
        });
    }

    // Status filter buttons
    const statusPills = document.getElementById('ws-status-filter-pills');
    if (statusPills) {
        statusPills.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', function () {
                statusPills.querySelectorAll('button').forEach(b => {
                    b.className = 'px-2.5 py-1 rounded-md text-[11px] font-semibold bg-white text-slate-600 hover:bg-slate-100 border border-slate-200';
                });
                this.className = 'px-2.5 py-1 rounded-md text-[11px] font-semibold bg-slate-800 text-white';
                wsCurrentFilter = this.dataset.filter || 'all';
                filterAndSortVisibleRows();
            });
        });
    }

    // Check All Checkbox
    if (wsCheckAll) {
        wsCheckAll.addEventListener('change', function () {
            const checkboxes = wsTableTbody ? wsTableTbody.querySelectorAll('.ws-row-check') : [];
            checkboxes.forEach(cb => {
                cb.checked = wsCheckAll.checked;
                const rowNo = parseInt(cb.dataset.row, 10);
                if (wsCheckAll.checked) wsSelectedIndices.add(rowNo);
                else wsSelectedIndices.delete(rowNo);
            });
            updateSelectionStats();
        });
    }

    if (btnSelectAllFiltered) {
        btnSelectAllFiltered.addEventListener('click', function () {
            const checkboxes = wsTableTbody ? wsTableTbody.querySelectorAll('.ws-row-check') : [];
            checkboxes.forEach(cb => {
                cb.checked = true;
                wsSelectedIndices.add(parseInt(cb.dataset.row, 10));
            });
            updateSelectionStats();
        });
    }

    // Search and Sort listeners
    if (wsSearchInput) wsSearchInput.addEventListener('input', filterAndSortVisibleRows);
    if (btnApplyWorkspaceRules) btnApplyWorkspaceRules.addEventListener('click', applyWorkspaceRules);
    if (btnScanWorkspace) btnScanWorkspace.addEventListener('click', scanWorkspaceRows);

    // ----------------------------------------------------
    // 4. PEMBERIAN ID & REKONSILIASI (Batch Reconcile & Dry Run)
    // ----------------------------------------------------
    async function executeReconcile(isDryRun = false) {
        const tab = tabSelect ? tabSelect.value : '';
        const headerRow = headerRowInput ? parseInt(headerRowInput.value, 10) : 10;

        if (wsSelectedIndices.size === 0) {
            alert('Pilih setidaknya 1 baris untuk direkonsiliasi!');
            return;
        }

        const selectedRows = wsAllRows.filter(r => wsSelectedIndices.has(r.row_number));
        const items = selectedRows.map(r => ({
            row_index: r.row_number,
            status: r.inferred_status || 'waiting',
            row_data: r.raw_data || {},
            expected_checksum: r.checksum || null,
            registration_id: r.matched_local_id || r.local_registration_id || null,
            sync_id: r.external_sync_id || r.sync_id || null,
        }));

        const endpoint = isDryRun ? urlDryRunReconcile : urlBatchReconcile;
        const btn = isDryRun ? btnDryRunReconcile : btnExecuteBatch;
        if (btn) btn.disabled = true;

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    tab_name: tab,
                    header_row: headerRow,
                    items: items
                })
            });
            const data = await res.json();

            if (isDryRun) {
                if (data.success && data.report) {
                    alert(`Simulasi Pra-Tulis Selesai!\nTotal Dievaluasi: ${data.report.total_evaluated}\nSiap Tulis: ${data.report.can_write_count}\nKonflik/Kendala: ${data.report.conflict_count || 0}`);
                } else {
                    alert('Simulasi gagal: ' + (data.message || 'Terjadi kesalahan'));
                }
            } else {
                if (data.success) {
                    alert(`Berhasil memasukkan ${data.reconciled_count || data.created_count || items.length} data pemagang ke SertIF!`);
                    // Refresh data baris
                    scanWorkspaceRows();
                } else {
                    alert('Gagal memasukkan data: ' + (data.message || 'Terjadi kesalahan'));
                }
            }
        } catch (e) {
            console.error('Reconcile error:', e);
            alert('Terjadi kesalahan jaringan saat rekonsiliasi data.');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    if (btnDryRunReconcile) btnDryRunReconcile.addEventListener('click', () => executeReconcile(true));
    if (btnExecuteBatch) btnExecuteBatch.addEventListener('click', () => executeReconcile(false));

    // Mass Pemberian ID tanpa ID (Bulk Sync Without ID)
    if (btnBulkSyncWithoutId) {
        btnBulkSyncWithoutId.addEventListener('click', async function () {
            const unassignedRows = wsAllRows.filter(r => !r.external_sync_id && r.inferred_status && r.inferred_status !== 'unassigned');
            if (unassignedRows.length === 0) {
                alert('Tidak ada baris tanpa ID yang memiliki status terpetakan. Pastikan aturan warna/status sudah diterapkan.');
                return;
            }

            if (!confirm(`Terapkan status dan masukkan ${unassignedRows.length} baris tanpa ID ke database SertIF secara massal? Sistem akan membuat ID pendaftaran dan menuliskannya ke spreadsheet.`)) {
                return;
            }

            btnBulkSyncWithoutId.disabled = true;
            try {
                const tab = tabSelect ? tabSelect.value : '';
                const headerRow = headerRowInput ? parseInt(headerRowInput.value, 10) : 10;
                const items = unassignedRows.map(r => ({
                    row_index: r.row_number,
                    status: r.inferred_status,
                    row_data: r.raw_data || {},
                    expected_checksum: r.checksum || null,
                    registration_id: null,
                }));

                const res = await fetch(urlBatchReconcile, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ tab_name: tab, header_row: headerRow, items: items })
                });
                const data = await res.json();
                if (data.success) {
                    alert(`Pemberian ID & Sinkronisasi Massal Berhasil! ${data.reconciled_count || items.length} pemagang telah didaftarkan.`);
                    scanWorkspaceRows();
                } else {
                    alert('Gagal: ' + (data.message || 'Terjadi kesalahan'));
                }
            } catch (err) {
                console.error('Bulk sync error:', err);
                alert('Terjadi kesalahan jaringan.');
            } finally {
                btnBulkSyncWithoutId.disabled = false;
            }
        });
    }

    // Mass Pemulihan Baris Ber-ID yang Hilang di DB (Bulk Recover Missing Rows)
    if (btnBulkRecoverMissing) {
        btnBulkRecoverMissing.addEventListener('click', async function () {
            const missingRows = wsAllRows.filter(r => r.external_sync_id && (!r.matched_local_id || r.is_missing_local));
            if (missingRows.length === 0) {
                alert('Tidak ada baris ber-ID yang hilang di SertIF pada tampilan data saat ini.');
                return;
            }

            if (!confirm(`Tarik dan pulihkan ${missingRows.length} baris ber-ID yang hilang ke database SertIF? Sistem akan mempertahankan SERTIF_SYNC_ID asli tanpa mengubah ID di spreadsheet.`)) {
                return;
            }

            btnBulkRecoverMissing.disabled = true;
            try {
                const tab = tabSelect ? tabSelect.value : '';
                const items = missingRows.map(r => ({
                    row_index: r.row_number,
                    sync_id: r.external_sync_id || r.sync_id,
                    registration_id: r.remote_registration_id || null,
                    status: r.inferred_status !== 'unassigned' ? r.inferred_status : null,
                    row_data: r.raw_data || r.data || {},
                    expected_checksum: r.checksum || r.content_checksum || null
                }));

                const res = await fetch(urlBatchRecover, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ tab_name: tab, items: items })
                });
                const data = await res.json();
                if (data.success) {
                    alert(data.message || `Berhasil memulihkan ${data.data?.recovered_count || items.length} data ke database SertIF!`);
                    scanWorkspaceRows();
                } else {
                    alert('Gagal: ' + (data.message || 'Terjadi kesalahan'));
                }
            } catch (err) {
                alert('Terjadi kesalahan jaringan saat memulihkan data: ' + err.message);
            } finally {
                btnBulkRecoverMissing.disabled = false;
            }
        });
    }

    // ----------------------------------------------------
    // 5. INITIAL RUN (Instant Render from Preloaded)
    // ----------------------------------------------------
    const hasPreloaded = populateTabsUi(true);
    if (!hasPreloaded) {
        fetchTabsList(false);
    } else {
        // Silent background fetch to check for newly added tabs
        fetchTabsList(false);
    }

    // Auto-inspect first tab if configured
    if (tabSelect && tabSelect.value) {
        inspectColumns();
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }

    // ==========================================
    // Fase 2: Penyesuaian Sinkronisasi Form JS
    // ==========================================
    const tabProfileSaveUrl = "{{ route('admin.spreadsheet-connections.tab-profile.save', $connection) }}";
    const proposalsStoreUrl = "{{ route('admin.spreadsheet-connections.proposals.store', $connection) }}";
    const diffUrlTemplate = "{{ route('admin.spreadsheet-connections.proposals.diff', [$connection, 999999]) }}";
    const applyUrlTemplate = "{{ route('admin.spreadsheet-connections.proposals.apply', [$connection, 999999]) }}";
    const itemUpdateUrlTemplate = "{{ route('admin.spreadsheet-connections.proposals.items.update', [$connection, 999999, 888888]) }}";

    window.openTabProfileModal = function () {
        document.getElementById('input-sheet-id').value = '';
        document.getElementById('input-tab-name').value = (tabSelect && tabSelect.value) ? tabSelect.value : '';
        document.getElementById('input-header-row').value = headerRowInput ? headerRowInput.value : 10;
        document.getElementById('input-data-start-row').value = wsStartRowInput ? wsStartRowInput.value : 11;
        document.getElementById('modal-tab-profile').classList.remove('hidden');
    };

    window.closeTabProfileModal = function () {
        document.getElementById('modal-tab-profile').classList.add('hidden');
    };

    window.editTabProfile = function (sheetId, tabName, headerRow, dataStartRow) {
        document.getElementById('input-sheet-id').value = sheetId;
        document.getElementById('input-tab-name').value = tabName;
        document.getElementById('input-header-row').value = headerRow;
        document.getElementById('input-data-start-row').value = dataStartRow;
        document.getElementById('modal-tab-profile').classList.remove('hidden');
    };

    window.submitTabProfile = async function (e) {
        e.preventDefault();
        const payload = {
            sheet_id: parseInt(document.getElementById('input-sheet-id').value, 10),
            tab_name: document.getElementById('input-tab-name').value.trim(),
            header_row: parseInt(document.getElementById('input-header-row').value, 10),
            data_start_row: parseInt(document.getElementById('input-data-start-row').value, 10)
        };

        try {
            const resp = await fetch(tabProfileSaveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const data = await resp.json();
            if (data.success) {
                alert('Profil tab berhasil disimpan!');
                closeTabProfileModal();
                window.location.reload();
            } else {
                alert('Gagal: ' + (data.message || 'Error'));
            }
        } catch (err) {
            alert('Kesalahan jaringan: ' + err.message);
        }
    };

    // Create Proposal from Active Tab
    const btnCreateProposal = document.getElementById('btn-create-proposal-from-active');
    if (btnCreateProposal) {
        btnCreateProposal.addEventListener('click', async function () {
            const tabName = (tabSelect && tabSelect.value) ? tabSelect.value : prompt('Masukkan nama tab spreadsheet target (misal: 2026):', '2026');
            if (!tabName) return;

            const headerRow = headerRowInput ? parseInt(headerRowInput.value, 10) : 10;
            btnCreateProposal.disabled = true;
            btnCreateProposal.innerHTML = 'Membuat usulan...';

            try {
                const resp = await fetch(proposalsStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ tab_name: tabName, header_row: headerRow })
                });
                const data = await resp.json();
                if (data.success) {
                    alert('Proposal usulan pemetaan berhasil dibuat!');
                    window.location.reload();
                } else {
                    alert('Gagal: ' + (data.message || 'Error'));
                    btnCreateProposal.disabled = false;
                    btnCreateProposal.innerHTML = 'Buat Snapshot & Usulan Pemetaan';
                }
            } catch (err) {
                alert('Kesalahan jaringan: ' + err.message);
                btnCreateProposal.disabled = false;
                btnCreateProposal.innerHTML = 'Buat Snapshot & Usulan Pemetaan';
            }
        });
    }

    window.currentProposalVersion = {{ $activeProposal->version ?? 1 }};

    // Save Proposal Item Ajax
    window.saveProposalItemAjax = async function (proposalId, itemId) {
        const actionEl = document.getElementById(`action-select-${itemId}`);
        const action = actionEl ? actionEl.value : 'map_to_field';
        const fieldKeyEl = document.getElementById(`field-key-select-${itemId}`);
        const fieldKey = fieldKeyEl ? fieldKeyEl.value : null;
        const labelEl = document.getElementById(`suggested-label-${itemId}`);
        const suggestedLabel = labelEl ? labelEl.value : null;
        const typeEl = document.getElementById(`suggested-type-${itemId}`);
        const suggestedType = typeEl ? typeEl.value : 'text';

        const url = itemUpdateUrlTemplate.replace('999999', proposalId).replace('888888', itemId);

        try {
            const resp = await fetch(url, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    action: action,
                    field_key: fieldKey,
                    suggested_label: suggestedLabel,
                    suggested_field_type: suggestedType,
                    expected_version: window.currentProposalVersion || {{ $activeProposal->version ?? 1 }}
                })
            });
            const data = await resp.json();
            if (data.success) {
                if (data.proposal_version) {
                    window.currentProposalVersion = data.proposal_version;
                }
                const rowEl = document.getElementById(`proposal-row-${itemId}`);
                if (rowEl) {
                    const badge = rowEl.querySelector('.bg-amber-100, .bg-rose-100');
                    if (badge && (action === 'system_derived' || action === 'attachment_adapter' || action === 'ignore' || action === 'external_only' || (fieldKey && (action === 'link_existing' || action === 'create_draft_field')))) {
                        badge.classList.add('hidden');
                    }
                }
                alert('Item pemetaan diperbarui!');
            } else {
                alert('Gagal memperbarui item: ' + (data.message || 'Error'));
            }
        } catch (err) {
            alert('Kesalahan jaringan: ' + err.message);
        }
    };

    // Preview Diff Ajax
    window.previewDiffAjax = async function (proposalId) {
        const url = diffUrlTemplate.replace('999999', proposalId);
        const modal = document.getElementById('modal-schema-diff');
        const badgesContainer = document.getElementById('diff-summary-badges');
        const itemsContainer = document.getElementById('diff-items-container');

        badgesContainer.innerHTML = '<span class="text-slate-500 italic">Menganalisis perbedaan skema...</span>';
        itemsContainer.innerHTML = '';
        modal.classList.remove('hidden');

        try {
            const resp = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();

            if (data.success) {
                const diff = data.diff;
                badgesContainer.innerHTML = `
                    <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-semibold">${diff.identical_count || 0} Identik</span>
                    <span class="px-2 py-0.5 rounded bg-blue-100 text-blue-800 font-semibold">${diff.new_columns_count || 0} Kolom Baru</span>
                    <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-semibold">${diff.type_changes_count || 0} Tipe Berubah</span>
                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-semibold">${diff.missing_in_target_count || 0} Diabaikan</span>
                `;

                if (diff.details && diff.details.length > 0) {
                    diff.details.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'p-2 bg-white rounded border border-slate-200 flex items-center justify-between text-xs';
                        div.innerHTML = `
                            <div>
                                <span class="font-bold text-slate-800">${item.label || item.header}</span>
                                <span class="text-slate-400 font-mono text-[10px]">(${item.field_key})</span>
                            </div>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold ${item.status === 'NEW' ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'}">${item.status}</span>
                        `;
                        itemsContainer.appendChild(div);
                    });
                } else {
                    itemsContainer.innerHTML = '<div class="text-center py-4 text-slate-400 italic">Tidak ada perubahan terdeteksi.</div>';
                }
            } else {
                badgesContainer.innerHTML = `<span class="text-rose-600 font-semibold">Gagal: ${data.message}</span>`;
            }
        } catch (err) {
            badgesContainer.innerHTML = `<span class="text-rose-600 font-semibold">Kesalahan jaringan: ${err.message}</span>`;
        }
    };

    window.closeSchemaDiffModal = function () {
        document.getElementById('modal-schema-diff').classList.add('hidden');
    };

    // Apply Proposal to Draft Form
    window.applyProposalAjax = async function (proposalId) {
        if (!confirm('Terapkan usulan pemetaan ini ke draf Form Builder? Perubahan bersifat non-destruktif dan akan tersimpan sebagai versi draf baru.')) {
            return;
        }

        const url = applyUrlTemplate.replace('999999', proposalId);

        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });
            const data = await resp.json();
            if (data.success) {
                alert('Usulan pemetaan berhasil diterapkan ke Form Schema!');
                window.location.reload();
            } else {
                alert('Gagal menerapkan usulan: ' + (data.message || 'Error'));
            }
        } catch (err) {
            alert('Kesalahan jaringan: ' + err.message);
        }
    };
});
</script>
@endsection
