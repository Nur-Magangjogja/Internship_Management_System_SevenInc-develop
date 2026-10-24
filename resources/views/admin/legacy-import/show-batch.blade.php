@extends('layouts.dashboard', ['title' => 'Review Batch Impor #' . $batch->id])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.legacy-import.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                    &larr; Kembali ke Wizard Impor
                </a>
                <span class="text-xs text-slate-300">/</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                    Batch #{{ $batch->id }}
                </span>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-slate-800">Review & Verifikasi Batch Staging</h1>
            <p class="text-sm text-slate-500">
                Sumber: <span class="font-semibold text-slate-700">{{ $batch->source_filename }}</span> ({{ strtoupper($batch->source_type) }}) &bull; Target Tahun: <span class="font-semibold text-slate-700">{{ $batch->target_year ?: 'Otomatis via Timestamp' }}</span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if ($batch->status !== \App\Models\HistoricalImportBatch::STATUS_COMMITTED)
                <form action="{{ route('admin.legacy-import.cancel') }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan batch staging ini?');">
                    @csrf
                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                    <button type="submit" class="rounded-lg border border-rose-200 bg-white px-4 py-2 text-xs font-semibold text-rose-600 shadow-sm transition hover:bg-rose-50">
                        Batalkan Batch
                    </button>
                </form>
                <form action="{{ route('admin.legacy-import.commit') }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin melakukan komit ke database operasional?');">
                    @csrf
                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        <span>Komit & Simpan Data ({{ $stats['valid'] + $stats['warning'] }} Baris)</span>
                    </button>
                </form>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3.5 py-2 text-xs font-semibold text-emerald-700 border border-emerald-200">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span>Sudah Dikomit ({{ $batch->committed_at?->format('d M Y H:i') }})</span>
                </span>
            @endif
        </div>
    </div>

    <!-- Alert Notifikasi -->
    @if (session('success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
            {{ session('error') }}
        </div>
    @endif

    <!-- Kartu Statistik Validasi -->
    <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-5">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <span class="text-xs font-semibold text-slate-500">Total Baris</span>
            <p class="mt-1 text-2xl font-bold text-slate-800">{{ $stats['total'] }}</p>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
            <span class="text-xs font-semibold text-emerald-700">Valid</span>
            <p class="mt-1 text-2xl font-bold text-emerald-700">{{ $stats['valid'] }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
            <span class="text-xs font-semibold text-amber-700">Peringatan</span>
            <p class="mt-1 text-2xl font-bold text-amber-700">{{ $stats['warning'] }}</p>
        </div>
        <div class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm">
            <span class="text-xs font-semibold text-blue-700">Duplikat Peristiwa</span>
            <p class="mt-1 text-2xl font-bold text-blue-700">{{ $stats['duplicate'] }}</p>
        </div>
        <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-4 shadow-sm">
            <span class="text-xs font-semibold text-rose-700">Error Kritis</span>
            <p class="mt-1 text-2xl font-bold text-rose-700">{{ $stats['error'] }}</p>
        </div>
    </div>

    <!-- Tabel Data Staging -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-base font-bold text-slate-800">Baris Data Staging</h2>
            <span class="text-xs text-slate-500">Halaman {{ $rows->currentPage() }} dari {{ $rows->lastPage() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3"># Baris</th>
                        <th class="px-4 py-3">Timestamp / Tahun</th>
                        <th class="px-4 py-3">Nama Lengkap</th>
                        <th class="px-4 py-3">Email / Kontak</th>
                        <th class="px-4 py-3">Institusi</th>
                        <th class="px-4 py-3">Status Magang</th>
                        <th class="px-4 py-3">Status Validasi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-normal">
                    @forelse ($rows as $row)
                        @php
                            $mapped = $row->mapped_data ?? [];
                        @endphp
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                #{{ $row->row_index }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="font-medium text-slate-800">{{ $row->submission_timestamp?->format('d/m/Y H:i') ?: '-' }}</span>
                                <span class="block text-slate-400">Tahun {{ $row->target_year ?: '-' }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-800">
                                {{ $mapped['fullname'] ?? '-' }}
                                @if ($row->is_duplicate_event)
                                    <span class="ml-1 inline-flex items-center rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-800">Duplikat</span>
                                @endif
                                @if ($row->is_existing_applicant)
                                    <span class="ml-1 inline-flex items-center rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-semibold text-purple-800">Pendaftar Lama</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="text-slate-700">{{ $mapped['email'] ?? '-' }}</span>
                                <span class="block text-slate-400 font-mono">{{ $mapped['phone_number'] ?? '-' }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600">
                                {{ $mapped['raw_institution_name'] ?? ($mapped['institution_name'] ?? '-') }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold
                                    @if ($row->lifecycle_status === 'active') bg-emerald-100 text-emerald-800
                                    @elseif ($row->lifecycle_status === 'accepted') bg-blue-100 text-blue-800
                                    @elseif ($row->lifecycle_status === 'completed') bg-teal-100 text-teal-800
                                    @elseif ($row->lifecycle_status === 'rejected') bg-rose-100 text-rose-800
                                    @elseif ($row->lifecycle_status === 'exited') bg-orange-100 text-orange-800
                                    @else bg-slate-100 text-slate-800 @endif
                                ">
                                    {{ ucfirst($row->lifecycle_status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($row->validation_status === 'valid')
                                    <span class="text-emerald-600 font-semibold">&check; Valid</span>
                                @elseif ($row->validation_status === 'warning')
                                    <span class="text-amber-600 font-semibold">&excl; Warning</span>
                                @else
                                    <span class="text-rose-600 font-semibold">&cross; Error</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-slate-400">
                                Tidak ada data staging pada batch ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $rows->links() }}
        </div>
    </div>
</div>
@endsection
