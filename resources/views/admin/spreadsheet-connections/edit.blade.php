@extends('layouts.dashboard', ['title' => 'Edit Konfigurasi Spreadsheet'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 text-sm text-slate-500 mb-1">
                <a href="{{ route('admin.spreadsheet-connections.index') }}" class="hover:text-blue-600 transition">Integrasi Spreadsheet</a>
                <span>&rarr;</span>
                <span>Edit Konfigurasi</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Edit Konfigurasi {{ $connection->display_name }}</h1>
        </div>
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

    <!-- Alert Errors -->
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
    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (!empty($pausedQueuesSummary['total_count']) && $pausedQueuesSummary['total_count'] > 0)
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h4 class="font-bold text-amber-950 flex items-center gap-2">
                    <svg class="h-4 w-4 text-amber-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="10" y1="15" x2="10" y2="9"/>
                        <line x1="14" y1="15" x2="14" y2="9"/>
                    </svg>
                    <span>Antrean Sinkronisasi Dibekukan (paused_target_rebind)</span>
                </h4>
                <p class="text-xs text-amber-800 mt-0.5">
                    Terdapat <strong>{{ $pausedQueuesSummary['total_count'] }}</strong> antrean sinkronisasi yang dibekukan karena pergantian target Spreadsheet.
                </p>
            </div>
            <a href="{{ route('admin.spreadsheet-connections.show', $connection) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition">
                <span>Rekonsiliasi Antrean</span>
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </a>
        </div>
    @endif

    <!-- Form Card -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm max-w-2xl">
        <form action="{{ route('admin.spreadsheet-connections.update', $connection) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="space-y-6">
                <!-- Connection Key (Read-only) -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">
                        Domain Identity Key
                    </label>
                    <input
                        type="text"
                        disabled
                        value="{{ $connection->connection_key }}"
                        class="w-full rounded-lg border border-slate-200 bg-slate-100 px-3.5 py-2 text-sm font-mono text-slate-600 cursor-not-allowed"
                    />
                    <p class="mt-1 text-xs text-slate-400">Identitas unik koneksi ditentukan secara statis oleh sistem.</p>
                </div>

                <!-- Display Name -->
                <div>
                    <label for="display_name" class="block text-sm font-semibold text-slate-700 mb-1">
                        Nama Tampilan <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="display_name"
                        id="display_name"
                        required
                        value="{{ old('display_name', $connection->display_name) }}"
                        placeholder="Contoh: Spreadsheet Pendaftaran Magang"
                        class="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                </div>

                @if ($connection->connection_key === 'registration')
                    <!-- Brand Attachment (Multi-Brand Registration) - Dedicated per Brand -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">
                            Brand Terkait (Multi-Brand Registration)
                        </label>
                        @if ($connection->brand)
                            <div class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 border border-slate-200">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200 shadow-xs">
                                    {{ $connection->brand->name }} ({{ $connection->brand->code }})
                                </span>
                                @if ($connection->brand->registration_host)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-md bg-sky-50 text-sky-700 border border-sky-200 shadow-2xs" title="Host Pendaftaran: {{ $connection->brand->registration_host }}">
                                        <svg class="h-3.5 w-3.5 text-sky-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="2" y1="12" x2="22" y2="12"/>
                                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                                        </svg>
                                        <span>{{ $connection->brand->registration_host }}</span>
                                    </span>
                                @endif
                            </div>
                            <input type="hidden" name="brand_id" value="{{ $connection->brand_id }}">
                            <p class="mt-1 text-xs text-slate-500">
                                Koneksi ini terikat khusus untuk calon pemagang yang mendaftar melalui brand <strong>{{ $connection->brand->name }}</strong> ({{ $connection->brand->registration_host }}).
                            </p>
                        @else
                            <div class="flex items-center gap-2 p-3 rounded-lg bg-slate-50 border border-slate-200">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                    Default / Fallback Global (Semua Brand)
                                </span>
                            </div>
                            <input type="hidden" name="brand_id" value="">
                            <p class="mt-1 text-xs text-slate-500">
                                Koneksi cadangan global jika pendaftar belum memiliki spreadsheet khusus brand tersendiri.
                            </p>
                        @endif
                    </div>
                @endif

                <!-- Spreadsheet URL -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="spreadsheet_url" class="block text-sm font-semibold text-slate-700">
                            URL Dokumen Google Spreadsheet
                        </label>
                        @if ($connection->isSpreadsheetConnected())
                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Terhubung
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700 bg-rose-50 px-2 py-0.5 rounded border border-rose-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> Belum Terhubung
                            </span>
                        @endif
                    </div>
                    <input
                        type="url"
                        name="spreadsheet_url"
                        id="spreadsheet_url"
                        value="{{ old('spreadsheet_url', $connection->spreadsheet_url) }}"
                        placeholder="https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit"
                        class="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-sm text-slate-800 font-mono focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                    <p class="mt-1 text-xs text-slate-500">
                        ID Spreadsheet akan diekstrak secara otomatis dari URL yang diberikan.
                    </p>
                </div>

                <!-- Apps Script Gateway (Terpusat 1 Akun) -->
                <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600">
                            Apps Script Gateway (Endpoint Terpadu)
                        </label>
                        @php
                            $effectiveEndpoint = $connection->apps_script_endpoint_url ?: ($centralEndpoint ?? null);
                        @endphp
                        @if ($effectiveEndpoint)
                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> 1 Akun Terpadu
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Belum Dikonfigurasi
                            </span>
                        @endif
                    </div>
                    <div class="text-xs font-mono text-slate-700 break-all bg-white px-3 py-2 rounded border border-slate-200">
                        {{ $effectiveEndpoint ?: 'Belum diatur pada menu Integrasi Drive & Apps Script' }}
                    </div>
                    <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                        <span>Pemasukan link endpoint web telah dipusatkan menggunakan 1 akun Google.</span>
                        <a href="{{ route('admin.cloud-storage.index') }}" class="font-semibold text-blue-600 hover:underline inline-flex items-center gap-1">
                            <span>Ubah di Integrasi Drive &amp; Apps Script</span> &rarr;
                        </a>
                    </div>
                </div>

                @if ($connection->connection_key === 'registration')
                    <!-- Google Drive Folder URL / ID for Uploaded Intern Files -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="drive_folder_url" class="block text-sm font-semibold text-slate-700">
                                URL / ID Folder Google Drive (Penyimpanan Berkas Upload Pemagang)
                            </label>
                            @if ($connection->isDriveConnected())
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Terhubung
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700 bg-rose-50 px-2 py-0.5 rounded border border-rose-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> Belum Terhubung
                                </span>
                            @endif
                        </div>
                        <input
                            type="text"
                            name="drive_folder_url"
                            id="drive_folder_url"
                            value="{{ old('drive_folder_url', $connection->drive_folder_url) }}"
                            placeholder="https://drive.google.com/drive/folders/1QRk_vowPYJfx2SmNkbg8-W2yy5o0nPRB"
                            class="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-sm text-slate-800 font-mono focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        />
                        @if ($connection->drive_folder_id)
                            <p class="mt-1 text-xs text-emerald-700 font-mono">
                                Folder ID Terdeteksi: <strong>{{ $connection->drive_folder_id }}</strong>
                            </p>
                        @endif
                        <p class="mt-1 text-xs text-slate-500">
                            Masukkan URL Folder Google Drive (atau ID Folder langsung) tempat seluruh berkas yang di-upload pemagang (CV, KTM/KTP, Portofolio, Surat Pengantar, Proposal, Pasfoto) disimpan terpusat (1 folder per pemagang &amp; 1 link pada Spreadsheet).
                        </p>
                    </div>
                @endif

                <!-- Security Notice -->
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                    <p class="font-semibold text-slate-700 mb-1">Keamanan Kredensial:</p>
                    <p>
                        Konfigurasi ini hanya menyimpan metadata endpoint, target spreadsheet, dan folder Google Drive. Kredensial rahasia (OAuth client, refresh token, service account) tetap dikelola secara aman pada berkas server <code class="bg-white px-1 py-0.5 rounded border text-slate-800">.env</code>.
                    </p>
                </div>

                <!-- Submit Buttons -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <a
                        href="{{ route('admin.spreadsheet-connections.index') }}"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition"
                    >
                        Batal
                    </a>
                    <button
                        type="submit"
                        class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition"
                    >
                        Simpan Konfigurasi
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Otorisasi & Pengujian Target Card -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm max-w-2xl mt-6">
        <h3 class="text-base font-bold text-slate-800 mb-1">Pengujian &amp; Otorisasi Target</h3>
        <p class="text-xs text-slate-500 mb-4">
            Uji koneksi read-only atau otorisasi target secara resmi pada Google Apps Script Gateway.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <form action="{{ route('admin.spreadsheet-connections.test', $connection) }}" method="POST">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50 px-4 py-2 text-xs font-semibold text-blue-800 shadow-sm transition hover:bg-blue-100"
                >
                    <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    Uji Koneksi (Read-Only)
                </button>
            </form>

            <form action="{{ route('admin.spreadsheet-connections.activate-target', $connection) }}" method="POST" onsubmit="return confirm('Otorisasi dan aktifkan target Spreadsheet \'{{ $connection->spreadsheet_id }}\' pada Google Apps Script Gateway? Perhatian: Seluruh pekerjaan pending yang belum selesai akan dibekukan.');">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    Aktifkan Target (Otorisasi)
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
