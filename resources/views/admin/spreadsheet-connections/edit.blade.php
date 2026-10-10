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

                <!-- Spreadsheet URL -->
                <div>
                    <label for="spreadsheet_url" class="block text-sm font-semibold text-slate-700 mb-1">
                        URL Dokumen Google Spreadsheet
                    </label>
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

                <!-- Apps Script Endpoint URL -->
                <div>
                    <label for="apps_script_endpoint_url" class="block text-sm font-semibold text-slate-700 mb-1">
                        Apps Script Web App Endpoint URL <span class="text-xs font-normal text-slate-400">(Opsional / Fase S9E)</span>
                    </label>
                    <input
                        type="url"
                        name="apps_script_endpoint_url"
                        id="apps_script_endpoint_url"
                        value="{{ old('apps_script_endpoint_url', $connection->apps_script_endpoint_url) }}"
                        placeholder="https://script.google.com/macros/s/AKfycbx.../exec"
                        class="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-sm text-slate-800 font-mono focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                    <p class="mt-1 text-xs text-slate-500">
                        Endpoint Web App Google Apps Script. <strong>Perhatian:</strong> Dilarang menyematkan token atau kunci rahasia pada parameter URL.
                    </p>
                </div>

                <!-- Security Notice -->
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                    <p class="font-semibold text-slate-700 mb-1">Keamanan Kredensial:</p>
                    <p>
                        Konfigurasi ini hanya menyimpan metadata endpoint dan target spreadsheet. Kredensial rahasia (OAuth client, refresh token, service account) tetap dikelola secara aman pada berkas server <code class="bg-white px-1 py-0.5 rounded border text-slate-800">.env</code>.
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
</div>
@endsection
