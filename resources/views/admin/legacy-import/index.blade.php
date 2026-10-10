@extends('layouts.dashboard', ['title' => 'Impor Data Legacy'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Impor Data Legacy</h1>
            <p class="text-sm text-slate-500">Unggah dan integrasikan data historis institusi, guru/dosen pembimbing, dan follow-up dari spreadsheet tanpa risiko duplikasi data.</p>
        </div>
        <div class="flex items-center gap-2">
            <a
                href="{{ route('admin.legacy-import.template') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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

    @if (empty($stagingData))
        <!-- Panel Upload Berkas CSV -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-bold text-slate-800">Unggah Berkas Spreadsheet (CSV)</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Sistem akan memvalidasi dan mendeteksi apakah data institusi atau pembimbing sudah ada sebelumnya untuk mencegah rekaman ganda.
                </p>

                <form action="{{ route('admin.legacy-import.preview') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-4">
                    @csrf

                    <div>
                        <label for="csv_file" class="block text-xs font-semibold text-slate-700">Pilih Berkas CSV (.csv)</label>
                        <input
                            type="file"
                            id="csv_file"
                            name="file"
                            accept=".csv,text/csv,text/plain"
                            required
                            class="mt-1 block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-700 focus:border-slate-400 focus:bg-white focus:outline-none"
                        >
                        @error('file')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-end pt-3">
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-800 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            <span>Unggah &amp; Pratinjau Data</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Petunjuk Kolom -->
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">Format Kolom yang Dikenali</h3>
                <p class="mt-2 text-xs text-slate-500">Nama kolom pada baris pertama CSV fleksibel dan mendukung format berikut:</p>

                <ul class="mt-4 space-y-2.5 text-xs text-slate-600">
                    <li class="flex items-start gap-2">
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-700">nama_institusi</span>
                        <span class="text-slate-500">Wajib. Nama sekolah atau kampus.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-700">nama_guru_dosen</span>
                        <span class="text-slate-500">Nama PIC / guru pembimbing.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-700">nomor_telepon_wa</span>
                        <span class="text-slate-500">Nomor WhatsApp (auto normalisasi 628).</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-700">alamat_institusi</span>
                        <span class="text-slate-500">Alamat lengkap institusi.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-700">tahun_siklus</span>
                        <span class="text-slate-500">Tahun follow up (misal: 2026).</span>
                    </li>
                </ul>
            </div>
        </div>
    @else
        <!-- Panel Hasil Pratinjau Staging -->
        <div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-700">Pratinjau Staging</span>
                        <h2 class="text-base font-bold text-slate-800">{{ $stagingData['file_name'] }}</h2>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Periksa hasil analisis berikut. Data belum disimpan ke database hingga Anda menekan tombol Commit.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <form action="{{ route('admin.legacy-import.cancel') }}" method="POST" class="inline-block">
                        @csrf
                        <button
                            type="submit"
                            class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600 shadow-2xs hover:bg-slate-50 transition"
                        >
                            Batalkan
                        </button>
                    </form>

                    <form action="{{ route('admin.legacy-import.commit') }}" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menyimpan seluruh data yang valid ke database?');">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            <span>Commit &amp; Simpan ke Database</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Kartu Statistik Staging -->
            <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4 border-t border-slate-100 pt-6">
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <span class="text-xs font-semibold text-slate-500">Total Baris CSV</span>
                    <div class="mt-2 text-xl font-bold text-slate-800">{{ number_format($stagingData['stats']['total_rows']) }}</div>
                    <span class="text-[11px] text-slate-400">Baris terbaca</span>
                </div>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <span class="text-xs font-semibold text-slate-500">Institusi Baru</span>
                    <div class="mt-2 text-xl font-bold text-emerald-600">+{{ number_format($stagingData['stats']['new_institutions']) }}</div>
                    <span class="text-[11px] text-slate-400">{{ $stagingData['stats']['existing_institutions'] }} institusi sudah ada</span>
                    @if (!empty($stagingData['stats']['ambiguous_institutions']))
                        <div class="mt-1 text-[11px] font-semibold text-amber-600">{{ $stagingData['stats']['ambiguous_institutions'] }} berpotensi ambigu</div>
                    @endif
                </div>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <span class="text-xs font-semibold text-slate-500">Kontak Pembimbing</span>
                    <div class="mt-2 text-xl font-bold text-blue-600">+{{ number_format($stagingData['stats']['new_contacts']) }}</div>
                    <span class="text-[11px] text-slate-400">{{ $stagingData['stats']['existing_contacts'] }} kontak terhubung</span>
                    @if (!empty($stagingData['stats']['unverified_candidates']))
                        <div class="mt-1 text-[11px] font-semibold text-amber-600">{{ $stagingData['stats']['unverified_candidates'] }} kandidat review HR</div>
                    @endif
                </div>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <span class="text-xs font-semibold text-slate-500">Riwayat Follow-Up</span>
                    <div class="mt-2 text-xl font-bold text-amber-600">{{ number_format($stagingData['stats']['has_followup']) }}</div>
                    <span class="text-[11px] text-slate-400">Siap dicatat ke siklus</span>
                </div>
            </div>
        </div>

        <!-- Tabel Detail Baris Staging -->
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-bold text-slate-800">Detail Pemetaan Data Staging</h3>
            </div>

            <div class="overflow-x-auto max-h-[500px]">
                <table class="w-full text-left text-sm text-slate-600">
                    <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Baris</th>
                            <th class="px-6 py-3">Nama Institusi</th>
                            <th class="px-6 py-3">Aksi Institusi</th>
                            <th class="px-6 py-3">Guru / Dosen Pembimbing</th>
                            <th class="px-6 py-3">No. Telepon / WA</th>
                            <th class="px-6 py-3">Aksi Kontak</th>
                            <th class="px-6 py-3">Follow-Up</th>
                            <th class="px-6 py-3">Status Baris</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($stagingData['rows'] as $index => $row)
                            <tr class="transition hover:bg-slate-50/75">
                                <td class="whitespace-nowrap px-6 py-3.5 font-mono text-xs text-slate-400">
                                    {{ $row['row_number'] ?: $loop->iteration }}
                                </td>
                                <td class="px-6 py-3.5 font-semibold text-slate-800 text-xs">
                                    {{ $row['institution_name'] ?: '-' }}
                                    @if ($row['institution_matched'])
                                        <p class="text-[10px] font-normal text-slate-400">Cocok dengan: {{ $row['institution_matched'] }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5">
                                    @if (!empty($row['is_ambiguous']))
                                        <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 border border-amber-200">
                                            Perlu Klarifikasi (Mirip: {{ $row['ambiguous_candidate'] }})
                                        </span>
                                    @elseif ($row['institution_action'] === 'insert_new')
                                        <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                            Tambah Baru
                                        </span>
                                    @elseif ($row['institution_action'] === 'link_existing')
                                        <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700 border border-blue-200">
                                            Tautkan Existing
                                        </span>
                                    @else
                                        <span class="text-xs text-slate-400">-</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-xs text-slate-700">
                                    {{ $row['contact_name'] ?: '-' }}
                                    @if ($row['contact_position'])
                                        <p class="text-[10px] text-slate-400">{{ $row['contact_position'] }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 font-mono text-xs text-slate-600">
                                    {{ $row['contact_phone'] ?: '-' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5">
                                    @if ($row['contact_action'] === 'insert_new')
                                        <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 border border-amber-200">
                                            Kandidat Baru (Review HR)
                                        </span>
                                    @elseif ($row['contact_action'] === 'link_existing')
                                        <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700 border border-blue-200">
                                            Sudah Terdaftar
                                        </span>
                                    @else
                                        <span class="text-xs text-slate-400">Tidak ada kontak</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-xs">
                                    @if ($row['followup_year'])
                                        <span class="font-semibold text-slate-700">Tahun {{ $row['followup_year'] }}</span>
                                        @if ($row['followup_status'])
                                            <p class="text-[10px] text-slate-400">{{ ucfirst($row['followup_status']) }}</p>
                                        @endif
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-xs">
                                    @if (!empty($row['errors']))
                                        <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 border border-rose-200">
                                            {{ implode(', ', $row['errors']) }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-md bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-600 border border-slate-200">
                                            Valid
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
