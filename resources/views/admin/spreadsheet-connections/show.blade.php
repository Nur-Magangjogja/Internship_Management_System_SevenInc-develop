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
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Target Google Spreadsheet</span>
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
                    <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Folder Google Drive (Berkas Pemagang)</span>
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
    </div>

    <!-- Read-Only Universal Spreadsheet Scanner Card (Fase 1) -->
    <div class="mb-8 rounded-xl border border-blue-200 bg-white p-6 shadow-sm" id="scanner-section">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-100 pb-4 mb-5">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded bg-blue-100 text-blue-800 border border-blue-200">
                        Fase 1: Read-Only Discovery
                    </span>
                    <h2 class="text-lg font-bold text-slate-800">Inspeksi Universal Spreadsheet</h2>
                </div>
                <p class="text-xs text-slate-500 mt-1">
                    Pemindaian otomatis seluruh tab, header (Baris 12 / Baris 1), inferensi tipe data, sampel tersensor (PII Masked), dan draft proposal mapping.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    id="btn-fetch-tabs"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 text-xs font-semibold transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                    </svg>
                    Pindai Daftar Tab
                </button>
            </div>
        </div>

        <!-- Guardrail Notice -->
        <div class="mb-5 rounded-lg bg-slate-50 border border-slate-200 p-3.5 text-xs text-slate-600 flex items-start gap-2.5">
            <svg class="h-4 w-4 text-emerald-600 mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <div>
                <strong class="text-slate-800">Jaminan Keamanan Read-Only:</strong>
                Endpoint scanner ini menggunakan otentikasi signed HMAC-SHA256, strictly allowlist-checked, dan tidak pernah melakukan mutasi/penulisan ke spreadsheet. Form Builder tetap menjadi pemegang otoritas tunggal struktur pendaftaran.
            </div>
        </div>

        <!-- Scanner Controls -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 mb-5 items-end">
            <div class="md:col-span-4">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Pilih Tab Spreadsheet</label>
                <select id="scanner-tab-select" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    <option value="">-- Tekan 'Pindai Daftar Tab' terlebih dahulu --</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Baris Header (Opsional)</label>
                <input
                    type="number"
                    id="scanner-header-row"
                    placeholder="Auto (12/1)"
                    min="1"
                    max="100"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                />
            </div>
            <div class="md:col-span-6 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    id="btn-inspect-schema"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                    disabled
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    Inspeksi Schema & Header
                </button>
                <button
                    type="button"
                    id="btn-preview-rows"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-700 text-white hover:bg-slate-800 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                    disabled
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="8" y1="6" x2="21" y2="6"/>
                        <line x1="8" y1="12" x2="21" y2="12"/>
                        <line x1="8" y1="18" x2="21" y2="18"/>
                        <line x1="3" y1="6" x2="3.01" y2="6"/>
                        <line x1="3" y1="12" x2="3.01" y2="12"/>
                        <line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                    Pratinjau Baris (Page 1)
                </button>
                <button
                    type="button"
                    id="btn-draft-mapping"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                    disabled
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                        <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
                    </svg>
                    Draft Usulan Pemetaan
                </button>
                <button
                    type="button"
                    id="btn-scan-inbound"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-xs font-semibold shadow-sm transition disabled:opacity-50"
                    disabled
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Pindai Inbound Staging (Fase 4)
                </button>
            </div>
        </div>

        <!-- Loading State Indicator -->
        <div id="scanner-loading" class="hidden py-8 text-center text-slate-500 text-xs">
            <svg class="animate-spin h-6 w-6 text-blue-600 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span id="scanner-loading-text">Sedang memproses inspeksi read-only...</span>
        </div>

        <!-- Dynamic Results Container -->
        <div id="scanner-results" class="hidden space-y-4">
            <!-- Header Summary Bar -->
            <div id="scanner-summary-bar" class="flex flex-wrap items-center justify-between gap-3 p-3 rounded-lg bg-slate-50 border border-slate-200 text-xs">
                <div class="flex items-center gap-3">
                    <span id="stat-tab-badge" class="font-bold text-slate-800 font-mono"></span>
                    <span id="stat-header-row-badge" class="px-2 py-0.5 rounded bg-blue-100 text-blue-800 font-semibold"></span>
                    <span id="stat-col-count-badge" class="px-2 py-0.5 rounded bg-slate-200 text-slate-700"></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-slate-400 font-mono text-[11px]">Schema Hash:</span>
                    <span id="stat-schema-hash" class="font-mono text-[10px] bg-white border border-slate-200 px-2 py-0.5 rounded text-slate-700 truncate max-w-xs" title=""></span>
                </div>
            </div>

            <!-- Content Container -->
            <div id="scanner-content" class="overflow-x-auto rounded-lg border border-slate-200">
                <!-- Dynamically populated via JS -->
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
                                    Belum ada profil tab eksplisit. Sistem menggunakan deteksi default (Tab tahun/TEMPLATE_TAHUN = Baris 12/13, tab non-tahunan/Petunjuk = Baris 1/2).
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
                                            <span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-rose-100 text-rose-800 border border-rose-200" title="{{ $item->collision_detail }}">
                                                DUPLIKAT
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
                                                        {{ $ff->label ?? $ff->field_label }} ({{ $ff->field_key }}) [{{ $ff->field_type }}]
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
                                        <div id="field-config-none-{{ $item->id }}" class="{{ in_array($item->action, ['external_only', 'ignore', 'system_protected']) ? '' : 'hidden' }} text-slate-400 italic text-[11px]">
                                            @if ($item->action === 'system_protected')
                                                <span class="text-purple-700 font-mono font-medium">Field Identitas Sistem (Terkunci)</span>
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

    <!-- Card: Controlled Inbound Row Staging & Change Reconciliation (Fase 4) -->
    <div class="mt-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4 mb-4">
            <div>
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                    <span class="p-1 rounded bg-emerald-50 text-emerald-600 border border-emerald-200">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                    </span>
                    Perubahan Inbound & Rekonsiliasi Staging (Fase 4)
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Perubahan data dari Google Spreadsheet ditampung di staging terlebih dahulu. Database lokal tetap menjadi sumber kebenaran data administratif.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    onclick="window.location.reload()"
                    class="px-2.5 py-1.5 rounded-lg border border-slate-300 bg-slate-50 hover:bg-slate-100 text-[11px] font-semibold text-slate-700 transition"
                >
                    Refresh Staging
                </button>
            </div>
        </div>

        @php
            $stagedInboundChanges = \App\Models\SheetInboundChangeStaging::where('spreadsheet_connection_id', $connection->id)
                ->with(['registration', 'reviewer'])
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();
        @endphp

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-slate-600 font-semibold text-[11px]">
                        <th class="px-3 py-2.5">Waktu / Tab</th>
                        <th class="px-3 py-2.5">Sync ID & Pendaftar</th>
                        <th class="px-3 py-2.5">Header & Klasifikasi</th>
                        <th class="px-3 py-2.5">Nilai Remote vs DB</th>
                        <th class="px-3 py-2.5">Status Konflik</th>
                        <th class="px-3 py-2.5">Status Staging</th>
                        <th class="px-3 py-2.5 text-center">Aksi Admin</th>
                    </tr>
                </thead>
                <tbody id="inbound-staging-tbody" class="divide-y divide-slate-100 text-slate-700">
                    @forelse($stagedInboundChanges as $stg)
                        <tr class="hover:bg-slate-50/70 transition" id="staging-row-{{ $stg->id }}">
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-slate-800">{{ $stg->tab_name }}</span>
                                <div class="text-[10px] text-slate-400 font-mono">{{ $stg->created_at?->format('d M H:i') }}</div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="font-mono text-[11px] font-semibold text-slate-800">{{ $stg->sync_id }}</span>
                                <div class="text-[11px] text-slate-600 truncate max-w-[150px]">{{ $stg->registration?->fullname ?? '-' }}</div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-slate-800">{{ $stg->header_name }}</span>
                                <div>
                                    @php
                                        $clsBadge = match($stg->field_classification) {
                                            'system_protected'   => 'bg-rose-50 text-rose-700 border-rose-200',
                                            'core_administrative' => 'bg-amber-50 text-amber-700 border-amber-200',
                                            'custom_answer'      => 'bg-blue-50 text-blue-700 border-blue-200',
                                            default              => 'bg-slate-100 text-slate-600 border-slate-200',
                                        };
                                    @endphp
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium border {{ $clsBadge }}">
                                        {{ str_replace('_', ' ', ucfirst($stg->field_classification)) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-[11px]">
                                @php
                                    $maskedRemote = app(\App\Services\SpreadsheetInboundSyncService::class)->maskPiiValue($stg->header_name, (string)$stg->normalized_remote_value);
                                    $maskedDb = app(\App\Services\SpreadsheetInboundSyncService::class)->maskPiiValue($stg->header_name, (string)$stg->current_db_value);
                                @endphp
                                <div class="text-emerald-700 font-medium">Sheet: <span class="font-mono">{{ $maskedRemote ?: '(kosong)' }}</span></div>
                                <div class="text-slate-500">DB: <span class="font-mono">{{ $maskedDb ?: '(kosong)' }}</span></div>
                            </td>
                            <td class="px-3 py-2.5">
                                @php
                                    $cnfBadge = match($stg->conflict_status) {
                                        'clean_inbound' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'conflict_divergent' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'conflict_core_field_requires_approval' => 'bg-purple-50 text-purple-700 border-purple-200',
                                        'rejected_protected_field' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'conflict_duplicate_row_id' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        default => 'bg-slate-100 text-slate-600 border-slate-200',
                                    };
                                @endphp
                                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold border {{ $cnfBadge }}">
                                    {{ str_replace('_', ' ', ucfirst($stg->conflict_status)) }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5">
                                @php
                                    $stgBadge = match($stg->staging_status) {
                                        'applied'  => 'bg-emerald-100 text-emerald-800 font-semibold',
                                        'approved' => 'bg-blue-100 text-blue-800 font-semibold',
                                        'rejected' => 'bg-slate-200 text-slate-600',
                                        default    => 'bg-amber-100 text-amber-800 font-semibold',
                                    };
                                @endphp
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] {{ $stgBadge }}">
                                    {{ ucfirst($stg->staging_status) }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-center">
                                @if($stg->staging_status === 'staged')
                                    @if($stg->isProtected())
                                        <span class="text-[10px] text-rose-600 font-semibold">Tolak Mutlak</span>
                                    @else
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button
                                                type="button"
                                                onclick="approveInboundStaging({{ $stg->id }})"
                                                class="px-2 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-semibold transition"
                                            >
                                                Setujui
                                            </button>
                                            <button
                                                type="button"
                                                onclick="rejectInboundStaging({{ $stg->id }})"
                                                class="px-2 py-1 rounded bg-rose-600 hover:bg-rose-700 text-white text-[10px] font-semibold transition"
                                            >
                                                Tolak
                                            </button>
                                        </div>
                                    @endif
                                @else
                                    <span class="text-[10px] text-slate-400">Selesai</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-slate-400 italic">
                                Belum ada perubahan inbound yang tertampung di staging. Tekan tombol 'Pindai Inbound Staging' di atas untuk memindai tab Spreadsheet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Explicit Tab Profile Editor -->
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
                            placeholder="Contoh: 0, 123456789"
                            class="w-full rounded border border-slate-300 p-2 text-slate-800 font-mono focus:border-indigo-500 focus:outline-none"
                        />
                        <span class="text-[10px] text-slate-400 mt-0.5 block">Diperoleh dari parameter gid=... di URL Google Sheets atau pindaian tab.</span>
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Nama Tab Spreadsheet</label>
                        <input
                            type="text"
                            id="input-tab-name"
                            required
                            placeholder="Contoh: 2026, Pendaftaran 2026"
                            class="w-full rounded border border-slate-300 p-2 text-slate-800 font-mono focus:border-indigo-500 focus:outline-none"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Baris Header</label>
                            <input
                                type="number"
                                id="input-header-row"
                                required
                                min="1"
                                max="100"
                                value="1"
                                class="w-full rounded border border-slate-300 p-2 text-slate-800 font-mono focus:border-indigo-500 focus:outline-none"
                            />
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Baris Mulai Data</label>
                            <input
                                type="number"
                                id="input-data-start-row"
                                required
                                min="2"
                                max="200"
                                value="2"
                                class="w-full rounded border border-slate-300 p-2 text-slate-800 font-mono focus:border-indigo-500 focus:outline-none"
                            />
                        </div>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        onclick="closeTabProfileModal()"
                        class="px-3 py-1.5 rounded border border-slate-300 text-slate-700 hover:bg-slate-50 text-xs font-semibold"
                    >
                        Batal
                    </button>
                    <button
                        type="submit"
                        class="px-4 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm"
                    >
                        Simpan Profil Tab
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Schema Diff Preview (Non-Destructive) -->
    <div id="modal-schema-diff" class="fixed inset-0 z-50 hidden bg-slate-900/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full p-6 border border-slate-200 max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Pratinjau Dampak Perubahan Skema (Schema Diff)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Analisis non-destruktif: tidak ada kolom yang dihapus dari Form Builder.</p>
                </div>
                <button type="button" onclick="closeSchemaDiffModal()" class="text-slate-400 hover:text-slate-600 text-lg">&times;</button>
            </div>

            <!-- Summary Badges -->
            <div id="diff-summary-badges" class="flex flex-wrap gap-2 mb-4 p-3 bg-slate-50 rounded-lg border border-slate-200 text-xs">
                <!-- Dynamically populated -->
            </div>

            <!-- Diff Items List -->
            <div id="diff-items-container" class="overflow-y-auto flex-1 space-y-2 pr-1 text-xs">
                <!-- Dynamically populated -->
            </div>

            <div class="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between">
                <div class="text-[11px] text-emerald-700 font-semibold flex items-center gap-1.5">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    0 Kolom Dihapus (Non-Destructive Safety Guaranteed)
                </div>
                <button
                    type="button"
                    onclick="closeSchemaDiffModal()"
                    class="px-4 py-1.5 rounded bg-slate-800 hover:bg-slate-900 text-white text-xs font-semibold"
                >
                    Tutup Pratinjau
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Safe Column Deletion Verification & Execution (Fase 3) -->
    <div id="modal-safe-column-deletion" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-xl w-full p-6 border border-slate-200 flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <div class="flex items-center gap-2">
                    <span class="p-1.5 rounded-lg bg-rose-50 text-rose-600 border border-rose-200">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            <line x1="10" y1="11" x2="10" y2="17"/>
                            <line x1="14" y1="11" x2="14" y2="17"/>
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Verifikasi Kelayakan Penghapusan Header Kosong</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Uji keamanan multi-lapis: data, formula, snapshot, dan lifecycle.</p>
                    </div>
                </div>
                <button type="button" onclick="closeColumnDeletionModal()" class="text-slate-400 hover:text-slate-600 text-lg">&times;</button>
            </div>

            <!-- Target Summary -->
            <div class="p-3 bg-slate-50 rounded-lg border border-slate-200 text-xs mb-4">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-slate-500">Tab Target:</span>
                        <strong id="del-modal-tab-name" class="text-slate-800 font-mono block"></strong>
                    </div>
                    <div>
                        <span class="text-slate-500">Identitas Kolom:</span>
                        <strong id="del-modal-col-identity" class="text-indigo-700 font-mono block"></strong>
                    </div>
                    <div>
                        <span class="text-slate-500">Label Header Terbaca:</span>
                        <strong id="del-modal-header-name" class="text-slate-800 block truncate"></strong>
                    </div>
                    <div>
                        <span class="text-slate-500">Posisi Fisik:</span>
                        <strong id="del-modal-col-pos" class="text-slate-800 font-mono block"></strong>
                    </div>
                </div>
            </div>

            <!-- Evaluation Loading State -->
            <div id="del-modal-loading" class="py-8 text-center text-xs text-slate-500">
                <svg class="animate-spin h-6 w-6 text-indigo-600 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Sedang memindai seluruh baris data dan memeriksa 9 gate keamanan...</span>
            </div>

            <!-- Evaluation Result Container -->
            <div id="del-modal-result" class="hidden space-y-3">
                <div id="del-modal-status-badge" class="p-3 rounded-lg border text-xs"></div>
                
                <div class="border border-slate-200 rounded-lg p-3 bg-white max-h-48 overflow-y-auto text-xs space-y-1.5" id="del-modal-reasons-list">
                    <!-- Populated dynamically -->
                </div>

                <div id="del-modal-action-box" class="pt-2 border-t border-slate-100 flex items-center justify-between">
                    <button
                        type="button"
                        onclick="closeColumnDeletionModal()"
                        class="px-3 py-1.5 rounded border border-slate-300 text-slate-700 hover:bg-slate-50 text-xs font-semibold"
                    >
                        Tutup
                    </button>
                    <button
                        type="button"
                        id="btn-confirm-delete-column"
                        onclick="executeColumnDeletionAjax()"
                        class="hidden px-4 py-1.5 rounded bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-sm transition"
                    >
                        Hapus Header Kosong Sekarang
                    </button>
                </div>
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
                                    @if ($sync->canRetry() || $sync->isSynced())
                                        <form action="{{ route('admin.spreadsheet-connections.retry-row', [$connection, $sync]) }}" method="POST" class="inline-block">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold rounded bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                                                title="{{ $sync->isSynced() ? 'Sinkronkan ulang baris ini' : 'Retry baris ini' }}"
                                            >
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="23 4 23 10 17 10"/>
                                                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                                                </svg>
                                                {{ $sync->isSynced() ? 'Sync Ulang' : 'Retry' }}
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const urlTabs = @json(route('admin.spreadsheet-connections.tabs', $connection));
    const urlInspect = @json(route('admin.spreadsheet-connections.inspect-schema', $connection));
    const urlPreview = @json(route('admin.spreadsheet-connections.preview-rows', $connection));
    const urlProposals = @json(route('admin.spreadsheet-connections.draft-mapping-proposals', $connection));

    const btnFetchTabs = document.getElementById('btn-fetch-tabs');
    const tabSelect = document.getElementById('scanner-tab-select');
    const headerRowInput = document.getElementById('scanner-header-row');
    const btnInspectSchema = document.getElementById('btn-inspect-schema');
    const btnPreviewRows = document.getElementById('btn-preview-rows');
    const btnDraftMapping = document.getElementById('btn-draft-mapping');
    const btnScanInbound = document.getElementById('btn-scan-inbound');

    const loadingEl = document.getElementById('scanner-loading');
    const loadingText = document.getElementById('scanner-loading-text');
    const resultsContainer = document.getElementById('scanner-results');
    const contentEl = document.getElementById('scanner-content');
    const statTabBadge = document.getElementById('stat-tab-badge');
    const statHeaderRowBadge = document.getElementById('stat-header-row-badge');
    const statColCountBadge = document.getElementById('stat-col-count-badge');
    const statSchemaHash = document.getElementById('stat-schema-hash');

    let currentPreviewPage = 1;

    function showLoading(text) {
        loadingText.textContent = text;
        loadingEl.classList.remove('hidden');
        resultsContainer.classList.add('hidden');
    }

    function hideLoading() {
        loadingEl.classList.add('hidden');
        resultsContainer.classList.remove('hidden');
    }

    function getSelectedTab() {
        return tabSelect.value.trim();
    }

    function getHeaderRow() {
        return headerRowInput.value.trim();
    }

    function getSelectedSheetId() {
        const selected = tabSelect.options[tabSelect.selectedIndex];
        if (selected && selected.dataset.sheetId !== undefined && selected.dataset.sheetId !== '') {
            return parseInt(selected.dataset.sheetId);
        }
        return null;
    }

    // 1. Fetch Tabs List
    btnFetchTabs.addEventListener('click', async function () {
        showLoading('Mengambil daftar seluruh tab dari Google Spreadsheet...');
        try {
            const resp = await fetch(urlTabs);
            const data = await resp.json();

            if (!data.success) {
                alert('Gagal mengambil daftar tab: ' + (data.message || 'Error tidak diketahui'));
                loadingEl.classList.add('hidden');
                return;
            }

            tabSelect.innerHTML = '';
            if (!data.tabs || data.tabs.length === 0) {
                tabSelect.innerHTML = '<option value="">(Tidak ada tab ditemukan)</option>';
                btnInspectSchema.disabled = true;
                btnPreviewRows.disabled = true;
                btnDraftMapping.disabled = true;
                if (btnScanInbound) btnScanInbound.disabled = true;
                loadingEl.classList.add('hidden');
                return;
            }

            data.tabs.forEach(tab => {
                const opt = document.createElement('option');
                opt.value = tab.name;
                opt.dataset.sheetId = (tab.id !== undefined && tab.id !== null) ? tab.id : '';
                opt.dataset.headerRow = tab.header_row || 1;
                opt.dataset.dataStartRow = tab.data_start_row || (parseInt(tab.header_row || 1) + 1);
                opt.textContent = `${tab.name} (${tab.last_row || tab.row_count} baris, default header: baris ${tab.header_row})`;
                tabSelect.appendChild(opt);
            });

            // Set default header row from first tab
            const firstOpt = tabSelect.options[0];
            headerRowInput.value = firstOpt.dataset.headerRow;

            btnInspectSchema.disabled = false;
            btnPreviewRows.disabled = false;
            btnDraftMapping.disabled = false;
            if (btnScanInbound) btnScanInbound.disabled = false;

            hideLoading();
            resultsContainer.classList.add('hidden');
        } catch (e) {
            alert('Terjadi kesalahan jaringan saat memindai tab: ' + e.message);
            loadingEl.classList.add('hidden');
        }
    });

    tabSelect.addEventListener('change', function () {
        const selected = tabSelect.options[tabSelect.selectedIndex];
        if (selected && selected.dataset.headerRow) {
            headerRowInput.value = selected.dataset.headerRow;
        }
    });

    // 2. Inspect Schema
    btnInspectSchema.addEventListener('click', async function () {
        const tab = getSelectedTab();
        if (!tab) return alert('Silakan pilih tab terlebih dahulu.');

        const headerRow = getHeaderRow();
        const sheetId = getSelectedSheetId();
        let query = `?tab_name=${encodeURIComponent(tab)}`;
        if (headerRow) query += `&header_row=${encodeURIComponent(headerRow)}`;
        if (sheetId !== null) query += `&sheet_id=${encodeURIComponent(sheetId)}`;

        showLoading(`Menginspeksi schema tab '${tab}' (Baris ${headerRow || 'Auto'})...`);
        try {
            const resp = await fetch(urlInspect + query);
            const data = await resp.json();

            if (!data.success) {
                alert('Gagal menginspeksi schema: ' + (data.message || 'Error'));
                loadingEl.classList.add('hidden');
                return;
            }

            statTabBadge.textContent = `Tab: [${data.tab_name}]`;
            statHeaderRowBadge.textContent = `Header: Baris ${data.header_row}`;
            statColCountBadge.textContent = `${data.columns_count} Kolom Ditemukan`;
            statSchemaHash.textContent = data.schema_hash || '-';
            statSchemaHash.title = data.schema_hash || '';

            let html = `
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-100 text-slate-700 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 w-16 text-center">Kolom</th>
                            <th class="px-4 py-3">Nama Header Spreadsheet</th>
                            <th class="px-4 py-3 w-28 text-center">Tipe Inferensi</th>
                            <th class="px-4 py-3 w-24 text-center">Formula?</th>
                            <th class="px-4 py-3">Contoh Sampel Tersensor (PII Masked)</th>
                            <th class="px-4 py-3 text-right w-28">Aksi Keamanan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-sans">
            `;

            (data.columns || []).forEach(col => {
                const samplesHtml = (col.samples || []).map(s =>
                    `<span class="inline-block px-2 py-0.5 mr-1 mb-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-[11px] text-slate-600">${escapeHtml(s)}</span>`
                ).join('') || '<span class="text-slate-400 italic">(kosong)</span>';

                const formulaBadge = col.has_formula
                    ? '<span class="px-2 py-0.5 text-[10px] font-bold rounded bg-amber-100 text-amber-800">Formula</span>'
                    : '<span class="text-slate-400">-</span>';

                html += `
                    <tr class="hover:bg-slate-50/70 transition">
                        <td class="px-4 py-2 text-center font-mono font-bold text-blue-700 bg-slate-50/50">
                            ${escapeHtml(col.col_letter)} (${col.col_index})
                        </td>
                        <td class="px-4 py-2 font-medium text-slate-800">
                            ${col.header_name ? escapeHtml(col.header_name) : '<span class="text-slate-400 italic">(Header Kosong)</span>'}
                        </td>
                        <td class="px-4 py-2 text-center">
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono font-semibold bg-slate-100 text-slate-700">
                                ${escapeHtml(col.inferred_type)}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-center">${formulaBadge}</td>
                        <td class="px-4 py-2">${samplesHtml}</td>
                        <td class="px-4 py-2 text-right">
                            <button
                                type="button"
                                onclick="openColumnDeletionModal('${escapeHtml(col.col_letter)}', '${escapeHtml(col.header_name || '')}', '${escapeHtml(data.tab_name)}', '${escapeHtml(data.schema_hash || '')}', ${data.sheet_id || 0})"
                                class="px-2 py-1 rounded bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-700 font-semibold text-[10px] border border-slate-200 hover:border-rose-300 transition"
                            >
                                Cek Hapus
                            </button>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table>`;
            contentEl.innerHTML = html;

            hideLoading();
        } catch (e) {
            alert('Kesalahan saat menginspeksi schema: ' + e.message);
            loadingEl.classList.add('hidden');
        }
    });

    // 3. Preview Rows
    btnPreviewRows.addEventListener('click', function () {
        currentPreviewPage = 1;
        loadPreviewPage(1);
    });

    async function loadPreviewPage(page) {
        const tab = getSelectedTab();
        if (!tab) return alert('Silakan pilih tab terlebih dahulu.');

        const headerRow = getHeaderRow();
        const sheetId = getSelectedSheetId();
        let query = `?tab_name=${encodeURIComponent(tab)}&page=${page}&page_size=15`;
        if (headerRow) query += `&header_row=${encodeURIComponent(headerRow)}`;
        if (sheetId !== null) query += `&sheet_id=${encodeURIComponent(sheetId)}`;

        showLoading(`Membaca baris data tab '${tab}' (Halaman ${page})...`);
        try {
            const resp = await fetch(urlPreview + query);
            const data = await resp.json();

            if (!data.success) {
                alert('Gagal membaca baris: ' + (data.message || 'Error'));
                loadingEl.classList.add('hidden');
                return;
            }

            statTabBadge.textContent = `Tab: [${data.tab_name}]`;
            statHeaderRowBadge.textContent = `Header: Baris ${data.header_row}`;
            statColCountBadge.textContent = `${data.total_rows} Total Baris Data (Hal ${data.page} dari ${data.total_pages || 1})`;
            statSchemaHash.textContent = 'Read-Only Data Preview';

            if (!data.rows || data.rows.length === 0) {
                contentEl.innerHTML = `<div class="p-8 text-center text-slate-400 italic">Tidak ada baris data di halaman ini.</div>`;
                hideLoading();
                return;
            }

            // Extract all headers from first row
            const firstRow = data.rows[0];
            const keys = Object.keys(firstRow);

            let html = `
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs whitespace-nowrap">
                        <thead class="bg-slate-100 text-slate-700 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                            <tr>
            `;

            keys.forEach(k => {
                html += `<th class="px-4 py-3">${escapeHtml(k)}</th>`;
            });

            html += `</tr></thead><tbody class="divide-y divide-slate-100 font-sans">`;

            data.rows.forEach(r => {
                html += `<tr class="hover:bg-slate-50/70 transition">`;
                keys.forEach(k => {
                    const val = r[k] !== null && r[k] !== undefined ? String(r[k]) : '';
                    if (k === '_row_number') {
                        html += `<td class="px-4 py-2 font-mono font-bold text-slate-500 bg-slate-50 text-center">#${escapeHtml(val)}</td>`;
                    } else {
                        html += `<td class="px-4 py-2 text-slate-700 font-mono text-[11px]">${escapeHtml(val) || '<span class="text-slate-300">-</span>'}</td>`;
                    }
                });
                html += `</tr>`;
            });

            html += `</tbody></table></div>`;

            // Pagination Controls Bar
            html += `
                <div class="p-3 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-xs">
                    <div class="text-slate-500">
                        Menampilkan ${data.rows.length} baris (Halaman ${data.page} / ${data.total_pages || 1})
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            id="preview-prev-btn"
                            class="px-2.5 py-1 rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 font-semibold disabled:opacity-40"
                            ${data.page <= 1 ? 'disabled' : ''}
                        >
                            &larr; Sebelumnya
                        </button>
                        <button
                            type="button"
                            id="preview-next-btn"
                            class="px-2.5 py-1 rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 font-semibold disabled:opacity-40"
                            ${!data.has_more ? 'disabled' : ''}
                        >
                            Berikutnya &rarr;
                        </button>
                    </div>
                </div>
            `;

            contentEl.innerHTML = html;

            const prevBtn = document.getElementById('preview-prev-btn');
            const nextBtn = document.getElementById('preview-next-btn');

            if (prevBtn) {
                prevBtn.addEventListener('click', () => {
                    if (data.page > 1) loadPreviewPage(data.page - 1);
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', () => {
                    if (data.has_more) loadPreviewPage(data.page + 1);
                });
            }

            hideLoading();
        } catch (e) {
            alert('Kesalahan saat memuat baris: ' + e.message);
            loadingEl.classList.add('hidden');
        }
    }

    // 4. Draft Mapping Proposals
    btnDraftMapping.addEventListener('click', async function () {
        const tab = getSelectedTab();
        if (!tab) return alert('Silakan pilih tab terlebih dahulu.');

        const headerRow = getHeaderRow();
        const sheetId = getSelectedSheetId();
        let query = `?tab_name=${encodeURIComponent(tab)}`;
        if (headerRow) query += `&header_row=${encodeURIComponent(headerRow)}`;
        if (sheetId !== null) query += `&sheet_id=${encodeURIComponent(sheetId)}`;

        showLoading(`Menganalisis draft rekomendasi pemetaan kolom tab '${tab}' ke Form Builder...`);
        try {
            const resp = await fetch(urlProposals + query);
            const data = await resp.json();

            if (!data.success) {
                alert('Gagal menyusun rekomendasi pemetaan: ' + (data.message || 'Error'));
                loadingEl.classList.add('hidden');
                return;
            }

            statTabBadge.textContent = `Tab: [${data.tab_name}]`;
            statHeaderRowBadge.textContent = `Header: Baris ${data.header_row}`;
            statColCountBadge.textContent = `${data.matched_count} Cocok / ${data.total_scanned} Total Kolom`;
            statSchemaHash.textContent = data.schema_hash || '-';

            let html = `
                <div class="p-3 bg-indigo-50/60 border-b border-indigo-100 text-xs text-indigo-900 flex items-center justify-between">
                    <div>
                        <span class="font-bold">Analisis Rekomendasi Mapping:</span>
                        Ditemukan <strong>${data.matched_count}</strong> kolom yang memiliki kandidat field Form Builder, dan <strong>${data.unmatched_count}</strong> kolom belum terpetakan.
                    </div>
                    <span class="px-2 py-0.5 rounded bg-indigo-100 text-indigo-800 font-semibold text-[11px]">Mode Analisis (Non-Destruktif)</span>
                </div>
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-100 text-slate-700 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 w-16 text-center">Kolom</th>
                            <th class="px-4 py-3">Header Terpindai di Spreadsheet</th>
                            <th class="px-4 py-3">Usulan Field Form Builder</th>
                            <th class="px-4 py-3 w-28 text-center">Tingkat Keyakinan</th>
                            <th class="px-4 py-3">Rasionalisasi Pencocokan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-sans">
            `;

            (data.proposals || []).forEach(p => {
                let badgeClass = 'bg-slate-100 text-slate-600';
                if (p.confidence_level === 'EXACT') badgeClass = 'bg-emerald-100 text-emerald-800 border border-emerald-200';
                else if (p.confidence_level === 'HIGH') badgeClass = 'bg-blue-100 text-blue-800 border border-blue-200';
                else if (p.confidence_level === 'MEDIUM') badgeClass = 'bg-amber-100 text-amber-800 border border-amber-200';
                else if (p.confidence_level === 'UNMATCHED') badgeClass = 'bg-rose-100 text-rose-800 border border-rose-200';

                const fieldInfo = p.suggested_field_key ? `
                    <div class="font-semibold text-slate-800">${escapeHtml(p.suggested_field_label || p.suggested_field_key)}</div>
                    <div class="font-mono text-[10px] text-slate-500">
                        key: ${escapeHtml(p.suggested_field_key)}
                        <span class="ml-1 px-1 rounded bg-slate-100">${escapeHtml(p.suggested_field_type || 'text')}</span>
                        ${p.is_system ? '<span class="ml-1 text-blue-600 font-semibold">(System)</span>' : ''}
                    </div>
                ` : `<span class="text-slate-400 italic">Belum ada field cocok</span>`;

                html += `
                    <tr class="hover:bg-slate-50/70 transition">
                        <td class="px-4 py-2.5 text-center font-mono font-bold text-indigo-700 bg-slate-50/50">
                            ${escapeHtml(p.col_letter)} (${p.col_index})
                        </td>
                        <td class="px-4 py-2.5 font-medium text-slate-800">
                            ${p.scanned_header ? escapeHtml(p.scanned_header) : '<span class="text-slate-400 italic">(Header Kosong)</span>'}
                        </td>
                        <td class="px-4 py-2.5">${fieldInfo}</td>
                        <td class="px-4 py-2.5 text-center">
                            <span class="inline-block px-2.5 py-0.5 rounded text-[10px] font-bold ${badgeClass}">
                                ${escapeHtml(p.confidence_level)}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600 text-[11px]">
                            ${escapeHtml(p.match_reason)}
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table>`;
            contentEl.innerHTML = html;

            hideLoading();
        } catch (e) {
            alert('Kesalahan saat menyusun usulan mapping: ' + e.message);
            loadingEl.classList.add('hidden');
        }
    });

    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    // ==========================================
    // Fase 2 JavaScript Functions
    // ==========================================
    const tabProfileSaveUrl = @json(route('admin.spreadsheet-connections.tab-profile.save', $connection));
    const proposalsStoreUrl = @json(route('admin.spreadsheet-connections.proposals.store', $connection));
    const diffUrlTemplate = '{!! route('admin.spreadsheet-connections.proposals.diff', [$connection, 999999]) !!}';
    const applyUrlTemplate = '{!! route('admin.spreadsheet-connections.proposals.apply', [$connection, 999999]) !!}';
    const itemUpdateUrlTemplate = '{!! route('admin.spreadsheet-connections.proposals.items.update', [$connection, 999999, 888888]) !!}';
    const csrfToken = '{{ csrf_token() }}';

    // Tab Profile Modal
    window.openTabProfileModal = function () {
        const sheetId = getSelectedSheetId();
        document.getElementById('input-sheet-id').value = (sheetId !== null) ? sheetId : '';
        document.getElementById('input-tab-name').value = getSelectedTab() || '';
        document.getElementById('input-header-row').value = getHeaderRow() || '1';
        document.getElementById('input-data-start-row').value = (parseInt(getHeaderRow() || '1') + 1);
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
            sheet_id: parseInt(document.getElementById('input-sheet-id').value),
            tab_name: document.getElementById('input-tab-name').value.trim(),
            header_row: parseInt(document.getElementById('input-header-row').value),
            data_start_row: parseInt(document.getElementById('input-data-start-row').value)
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

            if (resp.ok && data.success) {
                alert(data.message || 'Profil tab berhasil disimpan.');
                window.location.reload();
            } else {
                alert('Gagal menyimpan profil tab: ' + (data.message || 'Kesalahan validasi'));
            }
        } catch (err) {
            alert('Kesalahan jaringan: ' + err.message);
        }
    };

    // Create Proposal from Active Tab
    const btnCreateProposal = document.getElementById('btn-create-proposal-from-active');
    if (btnCreateProposal) {
        btnCreateProposal.addEventListener('click', async function () {
            const tabName = getSelectedTab() || prompt('Masukkan nama tab spreadsheet target (misal: 2026):', '2026');
            if (!tabName) return;

            const headerRow = getHeaderRow() || null;
            btnCreateProposal.disabled = true;
            btnCreateProposal.innerHTML = 'Membuat usulan...';

            const sheetId = getSelectedSheetId();
            try {
                const resp = await fetch(proposalsStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        tab_name: tabName,
                        sheet_id: sheetId,
                        header_row: headerRow ? parseInt(headerRow) : null,
                        year: tabName.match(/\d{4}/) ? parseInt(tabName.match(/\d{4}/)[0]) : 2026
                    })
                });
                const data = await resp.json();

                if (resp.ok && data.success) {
                    alert(data.message || 'Usulan pemetaan berhasil dibuat!');
                    window.location.reload();
                } else {
                    alert('Gagal membuat usulan pemetaan: ' + (data.message || 'Terjadi kesalahan'));
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

    // Interactive Decision Table Actions
    window.onActionChange = function (itemId) {
        const action = document.getElementById(`action-select-${itemId}`).value;
        const linkEl = document.getElementById(`field-config-link-${itemId}`);
        const createEl = document.getElementById(`field-config-create-${itemId}`);
        const noneEl = document.getElementById(`field-config-none-${itemId}`);

        if (action === 'link_existing') {
            linkEl.classList.remove('hidden');
            createEl.classList.add('hidden');
            noneEl.classList.add('hidden');
        } else if (action === 'create_draft_field') {
            linkEl.classList.add('hidden');
            createEl.classList.remove('hidden');
            noneEl.classList.add('hidden');
        } else {
            linkEl.classList.add('hidden');
            createEl.classList.add('hidden');
            noneEl.classList.remove('hidden');
        }
    };

    window.saveProposalItemAjax = async function (proposalId, itemId) {
        const action = document.getElementById(`action-select-${itemId}`).value;
        const fieldKey = document.getElementById(`field-key-select-${itemId}`) ? document.getElementById(`field-key-select-${itemId}`).value : null;
        const suggestedLabel = document.getElementById(`suggested-label-${itemId}`) ? document.getElementById(`suggested-label-${itemId}`).value : null;
        const suggestedType = document.getElementById(`suggested-type-${itemId}`) ? document.getElementById(`suggested-type-${itemId}`).value : 'text';

        const btn = document.getElementById(`btn-save-item-${itemId}`);
        if (btn) {
            btn.disabled = true;
            btn.textContent = '...';
        }

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
                    field_key: action === 'link_existing' ? fieldKey : null,
                    suggested_label: action === 'create_draft_field' ? suggestedLabel : null,
                    suggested_field_type: action === 'create_draft_field' ? suggestedType : null
                })
            });
            const data = await resp.json();

            if (resp.ok && data.success) {
                if (btn) {
                    btn.textContent = 'Tersimpan';
                    btn.classList.remove('bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
                    btn.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.textContent = 'Simpan';
                        btn.classList.remove('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
                        btn.classList.add('bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
                    }, 2000);
                }
            } else {
                alert('Gagal menyimpan pilihan: ' + (data.message || 'Kesalahan validasi'));
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Simpan';
                }
            }
        } catch (err) {
            alert('Kesalahan jaringan: ' + err.message);
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Simpan';
            }
        }
    };

    // Schema Diff Modal
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
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await resp.json();

            if (resp.ok && data.success && data.diff) {
                const diff = data.diff;
                const summary = diff.summary || {};

                badgesContainer.innerHTML = `
                    <span class="px-2.5 py-1 rounded-full font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                        ADD: ${summary.add || 0}
                    </span>
                    <span class="px-2.5 py-1 rounded-full font-bold bg-blue-100 text-blue-800 border border-blue-200">
                        LINK: ${summary.link || 0}
                    </span>
                    <span class="px-2.5 py-1 rounded-full font-bold bg-indigo-100 text-indigo-800 border border-indigo-200">
                        RENAME: ${summary.rename || 0}
                    </span>
                    <span class="px-2.5 py-1 rounded-full font-bold bg-amber-100 text-amber-800 border border-amber-200">
                        ARCHIVE: ${summary.archive || 0}
                    </span>
                    <span class="px-2.5 py-1 rounded-full font-bold ${summary.conflict > 0 ? 'bg-rose-100 text-rose-800 border-rose-300' : 'bg-slate-100 text-slate-700 border-slate-200'}">
                        CONFLICT: ${summary.conflict || 0}
                    </span>
                    <span class="px-2.5 py-1 rounded-full font-bold bg-slate-100 text-slate-600 border border-slate-200">
                        NOOP: ${summary.noop || 0}
                    </span>
                `;

                let itemsHtml = '';
                (diff.items || []).forEach(item => {
                    let badgeColor = 'bg-slate-100 text-slate-700';
                    if (item.action_type === 'ADD_COLUMN') badgeColor = 'bg-emerald-100 text-emerald-800';
                    else if (item.action_type === 'LINK_EXISTING') badgeColor = 'bg-blue-100 text-blue-800';
                    else if (item.action_type === 'RENAME_DISPLAY') badgeColor = 'bg-indigo-100 text-indigo-800';
                    else if (item.action_type === 'ARCHIVE') badgeColor = 'bg-amber-100 text-amber-800';
                    else if (item.action_type === 'CONFLICT') badgeColor = 'bg-rose-100 text-rose-800';

                    itemsHtml += `
                        <div class="p-2.5 rounded border border-slate-200 bg-white flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${badgeColor}">
                                    ${escapeHtml(item.action_type)}
                                </span>
                                <span class="font-mono font-semibold text-slate-800">${escapeHtml(item.field_key)}</span>
                            </div>
                            <div class="text-right text-slate-600 text-[11px]">
                                ${escapeHtml(item.reason || '')}
                            </div>
                        </div>
                    `;
                });

                itemsContainer.innerHTML = itemsHtml || '<div class="text-slate-400 italic p-4 text-center">Tidak ada perubahan skema yang perlu dilakukan.</div>';
            } else {
                badgesContainer.innerHTML = `<span class="text-rose-600 font-semibold">${escapeHtml(data.message || 'Gagal menganalisis diff')}</span>`;
            }
        } catch (err) {
            badgesContainer.innerHTML = `<span class="text-rose-600 font-semibold">Kesalahan jaringan: ${escapeHtml(err.message)}</span>`;
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

            if (resp.ok && data.success) {
                alert(data.message || 'Usulan pemetaan berhasil diaplikasikan ke draf!');
                window.location.reload();
            } else {
                alert('Gagal mengaplikasikan usulan pemetaan: ' + (data.message || 'Terjadi konflik'));
            }
        } catch (err) {
            alert('Kesalahan jaringan: ' + err.message);
        }
    };

    // ==========================================
    // Fase 3 JavaScript Functions (Header Safety)
    // ==========================================
    let activeDeletionCandidate = null;

    window.openColumnDeletionModal = async function (colLetter, headerName, tabName, schemaHash, sheetId) {
        activeDeletionCandidate = {
            colLetter: colLetter,
            headerName: headerName,
            tabName: tabName,
            schemaHash: schemaHash,
            sheetId: sheetId || 0
        };

        document.getElementById('del-modal-tab-name').textContent = tabName;
        document.getElementById('del-modal-col-identity').textContent = colLetter;
        document.getElementById('del-modal-header-name').textContent = headerName || '(Header Kosong)';
        document.getElementById('del-modal-col-pos').textContent = `Kolom ${colLetter}`;

        document.getElementById('del-modal-loading').classList.remove('hidden');
        document.getElementById('del-modal-result').classList.add('hidden');
        document.getElementById('btn-confirm-delete-column').classList.add('hidden');

        document.getElementById('modal-safe-column-deletion').classList.remove('hidden');

        try {
            const encodedIdentity = encodeURIComponent(headerName || colLetter);
            const query = `?tab_name=${encodeURIComponent(tabName)}`;
            const url = `{{ url('admin/spreadsheet-connections/' . $connection->id . '/tabs') }}/${activeDeletionCandidate.sheetId}/columns/${encodedIdentity}/deletion-eligibility${query}`;

            const resp = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            const data = await resp.json();

            document.getElementById('del-modal-loading').classList.add('hidden');
            document.getElementById('del-modal-result').classList.remove('hidden');

            const statusBox = document.getElementById('del-modal-status-badge');
            const reasonsList = document.getElementById('del-modal-reasons-list');
            const confirmBtn = document.getElementById('btn-confirm-delete-column');

            if (data.success && data.eligibility) {
                const el = data.eligibility;
                activeDeletionCandidate.currentSchemaHash = el.current_schema_hash;

                if (el.eligible) {
                    statusBox.className = 'p-3 rounded-lg border bg-emerald-50 text-emerald-800 border-emerald-200';
                    statusBox.innerHTML = `<strong>KOLOM LAYAK DIHAPUS (ELIGIBLE):</strong> Kolom ini terbukti baru, belum pernah dipublikasikan, tidak memiliki jawaban/snapshot, dan seluruh baris remote 100% kosong (${el.inspected_rows_count} baris diperiksa).`;
                    confirmBtn.classList.remove('hidden');
                } else {
                    statusBox.className = 'p-3 rounded-lg border bg-rose-50 text-rose-800 border-rose-200';
                    statusBox.innerHTML = `<strong>PENGHAPUSAN DITOLAK (FAIL-CLOSED):</strong> Kolom ini tidak memenuhi syarat keselamatan data (${el.classification}).`;
                    confirmBtn.classList.add('hidden');
                }

                reasonsList.innerHTML = (el.reasons || []).map(r => `<div class="flex items-start gap-1.5"><span class="text-slate-400 mt-0.5">&bull;</span><span>${escapeHtml(r)}</span></div>`).join('');
            } else {
                statusBox.className = 'p-3 rounded-lg border bg-rose-50 text-rose-800 border-rose-200';
                statusBox.innerHTML = `<strong>ERROR:</strong> ${escapeHtml(data.message || 'Gagal mengevaluasi kelayakan kolom.')}`;
                reasonsList.innerHTML = '';
            }
        } catch (err) {
            document.getElementById('del-modal-loading').classList.add('hidden');
            document.getElementById('del-modal-result').classList.remove('hidden');
            document.getElementById('del-modal-status-badge').className = 'p-3 rounded-lg border bg-rose-50 text-rose-800 border-rose-200';
            document.getElementById('del-modal-status-badge').textContent = 'Kesalahan jaringan: ' + err.message;
        }
    };

    window.closeColumnDeletionModal = function () {
        document.getElementById('modal-safe-column-deletion').classList.add('hidden');
        activeDeletionCandidate = null;
    };

    window.executeColumnDeletionAjax = async function () {
        if (!activeDeletionCandidate) return;

        if (!confirm(`Yakin ingin menghapus kolom '${activeDeletionCandidate.headerName || activeDeletionCandidate.colLetter}' dari Spreadsheet?\n\nOperasi ini bersifat permanen pada kolom kosong tersebut.`)) {
            return;
        }

        const confirmBtn = document.getElementById('btn-confirm-delete-column');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Menghapus kolom...';

        try {
            const encodedIdentity = encodeURIComponent(activeDeletionCandidate.headerName || activeDeletionCandidate.colLetter);
            const url = `{{ url('admin/spreadsheet-connections/' . $connection->id . '/tabs') }}/${activeDeletionCandidate.sheetId}/columns/${encodedIdentity}/delete-empty-column`;

            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    tab_name: activeDeletionCandidate.tabName,
                    expected_schema_hash: activeDeletionCandidate.currentSchemaHash || activeDeletionCandidate.schemaHash
                })
            });

            const data = await resp.json();

            if (resp.ok && data.success) {
                alert(data.message || 'Kolom kosong berhasil dihapus.');
                closeColumnDeletionModal();
                window.location.reload();
            } else {
                alert('Gagal menghapus kolom: ' + (data.message || 'Error'));
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Hapus Header Kosong Sekarang';
            }
        } catch (err) {
            alert('Kesalahan jaringan: ' + err.message);
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Hapus Header Kosong Sekarang';
        }
    };

    if (btnScanInbound) {
        btnScanInbound.addEventListener('click', async function () {
            const tab = getSelectedTab();
            if (!tab) {
                alert('Pilih tab terlebih dahulu.');
                return;
            }

            const opt = tabSelect.options[tabSelect.selectedIndex];
            const sheetId = opt ? (opt.dataset.sheetId || 0) : 0;

            showLoading(`Sedang memindai perubahan inbound pada tab '${tab}'...`);

            try {
                const res = await fetch(`{{ url('admin/spreadsheet-connections/' . $connection->id . '/tabs') }}/${sheetId}/inbound-scan`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        tab_name: tab,
                        expected_schema_hash: (window.__currentInspectedHash || '')
                    })
                });

                const data = await res.json();
                hideLoading();

                if (data.success) {
                    alert(`Pemindaian Inbound Selesai!\n\nBaris dipindai: ${data.scanned_rows_count}\nPerubahan ditampung: ${data.staged_changes_count}\nInbound bersih: ${data.clean_inbound_count}\nKonflik/Butuh Review: ${data.conflicts_count}\nField Terlindungi ditolak: ${data.protected_rejected_count}\nEcho dilewati: ${data.echo_skipped_count}`);
                    window.location.reload();
                } else {
                    alert(`Gagal memindai inbound: ${data.message || 'Error'}`);
                }
            } catch (e) {
                hideLoading();
                alert(`Kesalahan jaringan: ${e.message}`);
            }
        });
    }

    window.approveInboundStaging = async function (stagingId) {
        if (!confirm('Apakah Anda yakin ingin menyetujui dan menerapkan perubahan ini ke database?')) return;

        try {
            const res = await fetch(`{{ url('admin/spreadsheet-connections/' . $connection->id . '/inbound-staging') }}/${stagingId}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });

            const data = await res.json();
            if (data.success) {
                alert(data.message || 'Perubahan berhasil diterapkan ke database.');
                window.location.reload();
            } else {
                alert('Gagal menerapkan perubahan: ' + (data.message || 'Error'));
            }
        } catch (e) {
            alert('Kesalahan jaringan: ' + e.message);
        }
    };

    window.rejectInboundStaging = async function (stagingId) {
        const reason = prompt('Masukkan alasan penolakan perubahan ini:', 'Ditolak oleh Admin.');
        if (reason === null) return;

        try {
            const res = await fetch(`{{ url('admin/spreadsheet-connections/' . $connection->id . '/inbound-staging') }}/${stagingId}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ reason: reason })
            });

            const data = await res.json();
            if (data.success) {
                alert(data.message || 'Perubahan ditolak.');
                window.location.reload();
            } else {
                alert('Gagal menolak perubahan: ' + (data.message || 'Error'));
            }
        } catch (e) {
            alert('Kesalahan jaringan: ' + e.message);
        }
    };
});
</script>
@endsection
