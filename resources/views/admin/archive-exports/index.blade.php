@extends('layouts.dashboard', ['title' => 'Ekspor Arsip Tahunan'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Ekspor &amp; Arsip Data Tahunan</h1>
            <p class="text-sm text-slate-500">Cadangkan dan unduh arsip data operasional, pemagang, guru/dosen pembimbing, follow-up, serta MOU per tahun.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 shadow-sm">
                <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Format Standar CSV UTF-8 (Excel Ready)
            </span>
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

    <!-- Card Generator & Filter Tahun -->
    <div class="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-xl">
                <h2 class="text-base font-bold text-slate-800">Pilih Tahun Operasional</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Sistem akan mengumpulkan dan mengemas seluruh data pemagang, guru/dosen pembimbing, tindak lanjut follow-up, serta dokumen MOU untuk tahun yang Anda tentukan ke dalam 1 paket arsip ZIP.
                </p>
            </div>

            <!-- Form Pemilihan & Generate -->
            <div class="flex flex-wrap items-center gap-3">
                <form action="{{ route('admin.archive-exports.index') }}" method="GET" class="flex items-center gap-2">
                    <label for="filter_year" class="text-xs font-semibold text-slate-600">Tahun:</label>
                    <select
                        id="filter_year"
                        name="year"
                        onchange="this.form.submit()"
                        class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800 focus:border-slate-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-400"
                    >
                        @foreach ($availableYears as $year)
                            <option value="{{ $year }}" {{ $year === $selectedYear ? 'selected' : '' }}>
                                {{ $year }}
                            </option>
                        @endforeach
                    </select>
                </form>

                <form action="{{ route('admin.archive-exports.generate') }}" method="POST">
                    @csrf
                    <input type="hidden" name="year" value="{{ $selectedYear }}">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect width="20" height="5" x="2" y="3" rx="1"/>
                            <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/>
                            <path d="M10 12h4"/>
                        </svg>
                        <span>Generate Arsip {{ $selectedYear }}</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Preview Volume Data Box -->
        <div class="mt-6 border-t border-slate-100 pt-6">
            <div class="mb-3 flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Estimasi Volume Data Tahun {{ $selectedYear }}</span>
                <span class="text-xs font-medium text-slate-500">Total: <strong class="text-slate-800">{{ number_format($previewCounts['total_records']) }}</strong> record data</span>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <!-- Pemagang -->
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500">Data Pemagang</span>
                        <div class="rounded-md bg-white p-1.5 text-blue-600 shadow-2xs">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-xl font-bold text-slate-800">{{ number_format($previewCounts['interns_count']) }}</div>
                    <span class="text-[11px] text-slate-400">Pendaftar &amp; aktif</span>
                </div>

                <!-- Guru / Dosen -->
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500">Guru / Dosen</span>
                        <div class="rounded-md bg-white p-1.5 text-emerald-600 shadow-2xs">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-xl font-bold text-slate-800">{{ number_format($previewCounts['contacts_count']) }}</div>
                    <span class="text-[11px] text-slate-400">Kontak pembimbing</span>
                </div>

                <!-- Follow-Up -->
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500">Siklus Follow-Up</span>
                        <div class="rounded-md bg-white p-1.5 text-amber-600 shadow-2xs">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-xl font-bold text-slate-800">{{ number_format($previewCounts['followups_count']) }}</div>
                    <span class="text-[11px] text-slate-400">Tercatat di tahun {{ $selectedYear }}</span>
                </div>

                <!-- Naskah MOU -->
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500">Dokumen MOU</span>
                        <div class="rounded-md bg-white p-1.5 text-indigo-600 shadow-2xs">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                                <polyline points="10 9 9 9 8 9"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-xl font-bold text-slate-800">{{ number_format($previewCounts['mous_count']) }}</div>
                    <span class="text-[11px] text-slate-400">PKS &amp; kesepakatan aktif</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Riwayat Berkas Arsip -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-6 py-4">
            <h2 class="text-base font-bold text-slate-800">Daftar Paket Arsip Tersedia</h2>
            <p class="text-xs text-slate-500">Paket berkas yang telah digenerate dapat diunduh kapan saja oleh administrator.</p>
        </div>

        @if ($archives->isEmpty())
            <div class="py-12 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect width="20" height="5" x="2" y="3" rx="1"/>
                        <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/>
                        <path d="M10 12h4"/>
                    </svg>
                </div>
                <h3 class="mt-3 text-sm font-semibold text-slate-700">Belum Ada Paket Arsip</h3>
                <p class="mt-1 text-xs text-slate-500">Pilih tahun operasional di atas dan klik tombol "Generate Arsip" untuk membuat paket pertama.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-600">
                    <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-6 py-3">No</th>
                            <th class="px-6 py-3">Tahun</th>
                            <th class="px-6 py-3">Nama Berkas</th>
                            <th class="px-6 py-3">Ukuran</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Digenerate Oleh</th>
                            <th class="px-6 py-3">Waktu Pembuatan</th>
                            <th class="px-6 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($archives as $archive)
                            <tr class="transition hover:bg-slate-50/75">
                                <td class="whitespace-nowrap px-6 py-4 font-mono text-xs text-slate-400">
                                    {{ ($archives->currentPage() - 1) * $archives->perPage() + $loop->iteration }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ $archive->archive_year }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="rounded-md bg-slate-100 p-1 text-slate-600">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                <polyline points="7 10 12 15 17 10"/>
                                                <line x1="12" y1="15" x2="12" y2="3"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="font-mono text-xs font-semibold text-slate-800">{{ $archive->file_name }}</p>
                                            <p class="text-[10px] text-slate-400">UUID: {{ substr($archive->public_uuid, 0, 8) }}...</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 font-mono text-xs text-slate-600">
                                    {{ $archive->formatted_file_size }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $archive->status_badge_class }}">
                                            {{ $archive->status_label }}
                                        </span>
                                        @if ($archive->is_drive_synced)
                                            <a
                                                href="{{ $archive->drive_web_view_link }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="Buka di Cloud Storage"
                                                class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 hover:text-emerald-700 hover:underline"
                                            >
                                                <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                                    <polyline points="15 3 21 3 21 9"/>
                                                    <line x1="10" y1="14" x2="21" y2="3"/>
                                                </svg>
                                                <span>Cloud Drive</span>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-xs text-slate-700">
                                    {{ $archive->generatedBy?->name ?? 'Sistem' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-xs text-slate-500">
                                    {{ $archive->generated_at ? $archive->generated_at->format('d/m/Y H:i') : '-' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Tombol Sync Cloud -->
                                        @if ($archive->status === \App\Models\ArchiveExport::STATUS_COMPLETED)
                                            <form action="{{ route('admin.archive-exports.sync-drive', $archive) }}" method="POST" class="inline-block">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    title="{{ $archive->is_drive_synced ? 'Sinkronkan Ulang ke Cloud' : 'Sinkronkan ke Cloud Storage' }}"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold {{ $archive->is_drive_synced ? 'text-emerald-700 bg-emerald-50/50' : 'text-slate-700' }} shadow-2xs transition hover:bg-slate-50"
                                                >
                                                    <svg class="h-3.5 w-3.5 {{ $archive->is_drive_synced ? 'text-emerald-600' : 'text-slate-500' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
                                                    </svg>
                                                    <span>{{ $archive->is_drive_synced ? 'Re-sync' : 'Sync Cloud' }}</span>
                                                </button>
                                            </form>

                                            <!-- Tombol Unduh -->
                                            <a
                                                href="{{ route('admin.archive-exports.download', $archive) }}"
                                                title="Unduh Paket ZIP"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50 hover:text-slate-900"
                                            >
                                                <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                    <polyline points="7 10 12 15 17 10"/>
                                                    <line x1="12" y1="15" x2="12" y2="3"/>
                                                </svg>
                                                <span>Unduh ZIP</span>
                                            </a>
                                        @endif


                                        <!-- Tombol Hapus -->
                                        <form
                                            action="{{ route('admin.archive-exports.destroy', $archive) }}"
                                            method="POST"
                                            onsubmit="return confirm('Apakah Anda yakin ingin menghapus paket arsip tahun {{ $archive->archive_year }} ini?');"
                                            class="inline-block"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                title="Hapus Berkas"
                                                class="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M3 6h18"/>
                                                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($archives->hasPages())
                <div class="border-t border-slate-100 px-6 py-4">
                    {{ $archives->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
