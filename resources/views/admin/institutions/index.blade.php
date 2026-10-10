@extends('layouts.dashboard', ['title' => 'Master Institusi'])

@section('content')
<div class="px-6 py-8">
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Master Institusi & Kampus</h1>
            <p class="text-sm text-slate-500">Kelola data universitas, politeknik, dan sekolah mitra untuk keperluan operasional magang dan MOU.</p>
        </div>
        @if (auth()->user()->role === 'admin')
            <div class="flex items-center gap-3">
                <a
                    href="{{ route('admin.institutions.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Tambah Institusi
                </a>
            </div>
        @endif
    </div>

    <!-- Alert Success / Error -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Filter Tabs & Search -->
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <!-- Tabs -->
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2">
            <a
                href="{{ request()->routeIs('hr.*') ? route('hr.institutions.index', ['search' => $search]) : route('admin.institutions.index', ['search' => $search]) }}"
                class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ empty($status) ? 'bg-slate-200 text-slate-800' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Semua
                <span class="rounded-full bg-slate-300 px-2 py-0.5 text-[11px] text-slate-800">{{ $stats['total'] }}</span>
            </a>
            <a
                href="{{ request()->routeIs('hr.*') ? route('hr.institutions.index', ['status' => 'active', 'search' => $search]) : route('admin.institutions.index', ['status' => 'active', 'search' => $search]) }}"
                class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Aktif
                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-800">{{ $stats['active'] }}</span>
            </a>
            <a
                href="{{ request()->routeIs('hr.*') ? route('hr.institutions.index', ['status' => 'inactive', 'search' => $search]) : route('admin.institutions.index', ['status' => 'inactive', 'search' => $search]) }}"
                class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'inactive' ? 'bg-slate-100 text-slate-700' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Nonaktif
                <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] text-slate-700">{{ $stats['inactive'] }}</span>
            </a>
        </div>

        <!-- Search Form -->
        <form method="GET" action="{{ request()->routeIs('hr.*') ? route('hr.institutions.index') : route('admin.institutions.index') }}" class="flex items-center gap-2">
            @if (!empty($status))
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <div class="relative">
                <input
                    type="text"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Cari nama, email, telepon..."
                    class="w-64 rounded-lg border border-slate-300 py-1.5 pl-9 pr-3 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                </div>
            </div>
            <button
                type="submit"
                class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            >
                Cari
            </button>
            @if (!empty($search))
                <a
                    href="{{ request()->routeIs('hr.*') ? route('hr.institutions.index', ['status' => $status]) : route('admin.institutions.index', ['status' => $status]) }}"
                    class="rounded-lg border border-slate-200 bg-slate-100 px-2 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-200"
                >
                    Reset
                </a>
            @endif
        </form>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 font-semibold text-slate-600">
                <tr>
                    <th class="px-5 py-3.5 w-12 text-center">No</th>
                    <th class="px-5 py-3.5">Nama Institusi</th>
                    <th class="px-5 py-3.5">Unit Korespondensi</th>
                    <th class="px-5 py-3.5">Kontak</th>
                    <th class="px-5 py-3.5 text-center">Total Pemagang</th>
                    <th class="px-5 py-3.5">Status</th>
                    <th class="px-5 py-3.5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($institutions as $inst)
                    <tr class="transition hover:bg-slate-50/50">
                        <td class="px-5 py-3.5 text-center font-medium text-slate-400">
                            {{ ($institutions->currentPage() - 1) * $institutions->perPage() + $loop->iteration }}
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-bold text-slate-800">{{ $inst->name }}</span>
                            @if ($inst->address)
                                <p class="text-[11px] text-slate-500 truncate max-w-xs">{{ $inst->address }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-slate-600">
                            {{ $inst->correspondence_unit ?? '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-slate-600">
                            @if ($inst->email)
                                <div class="flex items-center gap-1.5">
                                    <svg class="h-3 w-3 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                        <polyline points="22,6 12,13 2,6"/>
                                    </svg>
                                    <span>{{ $inst->email }}</span>
                                </div>
                            @endif
                            @if ($inst->phone)
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <svg class="h-3 w-3 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                                    </svg>
                                    <span>{{ $inst->phone }}</span>
                                </div>
                            @endif
                            @if (!$inst->email && !$inst->phone)
                                <span class="text-slate-400 italic">Belum ada kontak</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold text-slate-700">
                                {{ $inst->internship_registrations_count }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            @if ($inst->is_active)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                    Aktif
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 border border-slate-200" title="{{ $inst->inactive_reason }}">
                                    Nonaktif
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <!-- Detail -->
                                <a
                                    href="{{ request()->routeIs('hr.*') ? route('hr.institutions.show', $inst) : route('admin.institutions.show', $inst) }}"
                                    class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    Detail
                                </a>

                                @if (auth()->user()->role === 'admin')
                                    <!-- Edit -->
                                    <a
                                        href="{{ route('admin.institutions.edit', $inst) }}"
                                        class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        Ubah
                                    </a>

                                    <!-- Toggle Status Button -->
                                    @if ($inst->is_active)
                                        <button
                                            type="button"
                                            onclick="openDeactivateModal('{{ route('admin.institutions.toggle-status', $inst) }}', '{{ addslashes($inst->name) }}')"
                                            class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50"
                                        >
                                            Nonaktifkan
                                        </button>
                                    @else
                                        <form action="{{ route('admin.institutions.toggle-status', $inst) }}" method="POST" class="inline">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
                                            >
                                                Aktifkan
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-slate-400">
                            Tidak ada data institusi yang sesuai filter.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        @if ($institutions->hasPages())
            <div class="border-t border-slate-100 p-4">
                {{ $institutions->links() }}
            </div>
        @endif
    </div>
</div>

<!-- Modal Nonaktifkan Institusi -->
<div id="deactivateModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h3 class="text-base font-bold text-slate-900">Nonaktifkan Institusi</h3>
        <p class="mt-1 text-xs text-slate-500">
            Institusi <span id="deactivateInstitutionName" class="font-semibold text-slate-800"></span> tidak akan dapat dipilih pada formulir pendaftaran magang baru. Riwayat pemagang lama tetap tersimpan aman.
        </p>

        <form id="deactivateForm" method="POST" action="" class="mt-4">
            @csrf
            <div>
                <label for="inactive_reason" class="block text-xs font-semibold text-slate-700">Alasan Penonaktifan <span class="text-rose-500">*</span></label>
                <textarea
                    id="inactive_reason"
                    name="inactive_reason"
                    rows="3"
                    required
                    placeholder="Contoh: Kerja sama periode ini telah selesai / Institusi merger..."
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-amber-500 focus:outline-none"
                ></textarea>
            </div>

            <div class="mt-5 flex items-center justify-end gap-3">
                <button
                    type="button"
                    onclick="closeDeactivateModal()"
                    class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Batal
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-amber-600 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-700"
                >
                    Konfirmasi Nonaktifkan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeactivateModal(actionUrl, institutionName) {
        document.getElementById('deactivateForm').action = actionUrl;
        document.getElementById('deactivateInstitutionName').textContent = institutionName;
        document.getElementById('deactivateModal').classList.remove('hidden');
        document.getElementById('deactivateModal').classList.add('flex');
    }

    function closeDeactivateModal() {
        document.getElementById('deactivateModal').classList.add('hidden');
        document.getElementById('deactivateModal').classList.remove('flex');
    }
</script>
@endsection
