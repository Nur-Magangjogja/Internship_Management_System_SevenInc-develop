@extends('layouts.dashboard', ['title' => 'Manajemen Template MOU'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Manajemen Template MOU</h1>
            <p class="text-sm text-slate-500">Kelola draf format perjanjian kerja sama resmi (MOU) antara Seven Inc dan Institusi mitra.</p>
        </div>
        <div>
            <a
                href="{{ route('admin.mou-templates.create') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Buat Template Baru
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
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

    <!-- Stat Cards -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Total Versi Template</span>
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-slate-800">{{ $stats['total'] }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Template Aktif Saat Ini</span>
                <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-sm font-bold text-emerald-700 truncate" title="{{ $stats['active_name'] }}">
                    {{ $stats['active_name'] }}
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500">Versi Tertinggi</span>
                <span class="rounded-lg bg-purple-50 p-2 text-purple-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-bold text-slate-800">Versi {{ $stats['latest_version'] }}</div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 font-semibold text-slate-600">
                <tr>
                    <th class="px-5 py-3.5 w-12 text-center">No</th>
                    <th class="px-5 py-3.5">Nama Template & Versi</th>
                    <th class="px-5 py-3.5">Status Penggunaan</th>
                    <th class="px-5 py-3.5">Pihak Kedua (Default)</th>
                    <th class="px-5 py-3.5">Dibuat Oleh</th>
                    <th class="px-5 py-3.5">Terakhir Diperbarui</th>
                    <th class="px-5 py-3.5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($templates as $tmpl)
                    <tr class="transition hover:bg-slate-50/50">
                        <td class="px-5 py-3.5 text-center font-medium text-slate-400">
                            {{ ($templates->currentPage() - 1) * $templates->perPage() + $loop->iteration }}
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-bold text-slate-800">{{ $tmpl->name }}</span>
                            <span class="ml-1.5 inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono font-semibold text-slate-700">
                                v{{ $tmpl->version_number }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            @if ($tmpl->is_active)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                    Aktif (Default)
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 border border-slate-200">
                                    Arsip Versi
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-medium text-slate-800">{{ $tmpl->default_values['company_name'] ?? 'SEVEN INC' }}</span>
                            <p class="text-[11px] text-slate-500">
                                Penandatangan: {{ $tmpl->default_values['company_signatory_name'] ?? '-' }}
                            </p>
                        </td>
                        <td class="px-5 py-3.5 text-slate-600">
                            {{ $tmpl->createdBy->name ?? 'Sistem' }}
                        </td>
                        <td class="px-5 py-3.5 text-slate-500 text-[11px]">
                            <span>{{ $tmpl->updated_at?->diffForHumans() }}</span>
                            <p class="text-[10px] text-slate-400">{{ $tmpl->updated_at?->format('d M Y H:i') }}</p>
                        </td>
                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1.5">
                                <!-- Pratinjau -->
                                <a
                                    href="{{ route('admin.mou-templates.preview', $tmpl) }}"
                                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                                    title="Pratinjau Dokumen"
                                >
                                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    Pratinjau
                                </a>

                                <!-- Ubah -->
                                <a
                                    href="{{ route('admin.mou-templates.edit', $tmpl) }}"
                                    class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50 transition"
                                >
                                    Ubah
                                </a>

                                <!-- Set Active Toggle -->
                                @if (!$tmpl->is_active)
                                    <form method="POST" action="{{ route('admin.mou-templates.toggle-active', $tmpl) }}" class="inline">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="rounded-md border border-emerald-200 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 transition"
                                            title="Jadikan template aktif utama"
                                        >
                                            Aktifkan
                                        </button>
                                    </form>
                                @endif

                                <!-- Hapus (Soft Delete) -->
                                @if ($templates->total() > 1 && !$tmpl->is_active)
                                    <form method="POST" action="{{ route('admin.mou-templates.destroy', $tmpl) }}" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus template ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="rounded-md border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition"
                                        >
                                            Hapus
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-slate-400">
                            Belum ada template MOU yang terdaftar.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($templates->hasPages())
            <div class="border-t border-slate-100 p-4">
                {{ $templates->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
