@extends('layouts.dashboard', ['title' => 'Kelola HR Staff'])

@section('content')
<div class="px-6 py-8">
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Kelola Akun HR Staff</h1>
            <p class="text-sm text-slate-500">Manajemen staf HR operasional untuk pengelolaan institusi, kontak pembimbing, dan MOU.</p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.hr-staff.create') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Tambah HR Staff
            </a>
        </div>
    </div>

    <!-- Alert Success / Error -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <!-- Filter Tabs & Search -->
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <!-- Tabs -->
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2">
            <a
                href="{{ route('admin.hr-staff.index', ['status' => 'active', 'search' => $search]) }}"
                class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'active' ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Aktif
                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] text-blue-800">{{ $countActive }}</span>
            </a>
            <a
                href="{{ route('admin.hr-staff.index', ['status' => 'banned', 'search' => $search]) }}"
                class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'banned' ? 'bg-amber-50 text-amber-700' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Dinonaktifkan
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800">{{ $countBanned }}</span>
            </a>
            <a
                href="{{ route('admin.hr-staff.index', ['status' => 'trashed', 'search' => $search]) }}"
                class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition {{ $status === 'trashed' ? 'bg-rose-50 text-rose-700' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Arsip Terhapus
                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] text-rose-800">{{ $countTrashed }}</span>
            </a>
        </div>

        <!-- Search Form -->
        <form method="GET" action="{{ route('admin.hr-staff.index') }}" class="flex items-center gap-2">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="relative">
                <input
                    type="text"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Cari nama atau email..."
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
                    href="{{ route('admin.hr-staff.index', ['status' => $status]) }}"
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
                    <th class="px-5 py-3.5">Nama Staff</th>
                    <th class="px-5 py-3.5">Email</th>
                    <th class="px-5 py-3.5">Status</th>
                    <th class="px-5 py-3.5 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($hrStaffMembers as $staff)
                    <tr class="transition hover:bg-slate-50/50">
                        <td class="px-5 py-3.5 text-center font-medium text-slate-400">
                            {{ ($hrStaffMembers->currentPage() - 1) * $hrStaffMembers->perPage() + $loop->iteration }}
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="font-bold text-slate-800">{{ $staff->name }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-slate-600">
                            {{ $staff->email }}
                        </td>
                        <td class="px-5 py-3.5">
                            @if ($staff->trashed())
                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-700 border border-rose-200">
                                    Arsip Terhapus
                                </span>
                            @elseif ($staff->is_banned)
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 border border-amber-200">
                                    Dinonaktifkan
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                    Aktif
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($staff->trashed())
                                    <!-- Tombol Restore dengan Modal Konfirmasi Password -->
                                    <button
                                        type="button"
                                        onclick="openRestoreModal('{{ route('admin.hr-staff.restore', $staff->id) }}', '{{ $staff->name }}')"
                                        class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-blue-600 hover:bg-blue-50"
                                    >
                                        Pulihkan
                                    </button>
                                @else
                                    <!-- Edit -->
                                    <a
                                        href="{{ route('admin.hr-staff.edit', $staff) }}"
                                        class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        Ubah
                                    </a>

                                    <!-- Toggle Status Aktif/Nonaktif -->
                                    <form action="{{ route('admin.hr-staff.toggle-status', $staff) }}" method="POST" class="inline">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold {{ $staff->is_banned ? 'text-emerald-700 hover:bg-emerald-50' : 'text-amber-700 hover:bg-amber-50' }}"
                                        >
                                            {{ $staff->is_banned ? 'Aktifkan' : 'Nonaktifkan' }}
                                        </button>
                                    </form>

                                    <!-- Soft Delete dengan Modal Password -->
                                    <button
                                        type="button"
                                        onclick="openDeleteModal('{{ route('admin.hr-staff.destroy', $staff) }}', '{{ $staff->name }}')"
                                        class="rounded-md border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50"
                                    >
                                        Hapus
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-slate-400">
                            Tidak ada data akun HR Staff yang sesuai filter.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        @if ($hrStaffMembers->hasPages())
            <div class="border-t border-slate-100 p-4">
                {{ $hrStaffMembers->links() }}
            </div>
        @endif
    </div>
</div>

<!-- Modal Konfirmasi Hapus (Re-autentikasi Password) -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h3 class="text-base font-bold text-slate-800">Konfirmasi Penghapusan Akun HR</h3>
        <p class="mt-2 text-xs text-slate-600">
            Anda akan menghapus (soft delete) akun <span id="deleteStaffName" class="font-bold"></span>. Akun ini tidak akan dapat login kembali, namun riwayat pekerjaan dan audit tetap tersimpan.
        </p>
        <form id="deleteForm" method="POST" class="mt-4">
            @csrf
            @method('DELETE')
            <div>
                <label for="delete_admin_password" class="block text-xs font-semibold text-slate-700">
                    Masukkan Kata Sandi Admin Anda
                </label>
                <input
                    type="password"
                    id="delete_admin_password"
                    name="admin_password"
                    required
                    placeholder="Kata sandi admin yang sedang login"
                    class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-800 focus:border-red-500 focus:outline-none"
                >
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button
                    type="button"
                    onclick="closeDeleteModal()"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Batal
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-rose-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-rose-700"
                >
                    Hapus Akun
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Konfirmasi Restore (Re-autentikasi Password) -->
<div id="restoreModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h3 class="text-base font-bold text-slate-800">Konfirmasi Pemulihan Akun HR</h3>
        <p class="mt-2 text-xs text-slate-600">
            Anda akan memulihkan akun <span id="restoreStaffName" class="font-bold"></span> dari arsip terhapus.
        </p>
        <form id="restoreForm" method="POST" class="mt-4">
            @csrf
            <div>
                <label for="restore_admin_password" class="block text-xs font-semibold text-slate-700">
                    Masukkan Kata Sandi Admin Anda
                </label>
                <input
                    type="password"
                    id="restore_admin_password"
                    name="admin_password"
                    required
                    placeholder="Kata sandi admin yang sedang login"
                    class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button
                    type="button"
                    onclick="closeRestoreModal()"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Batal
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-700"
                >
                    Pulihkan Akun
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(actionUrl, staffName) {
        document.getElementById('deleteForm').action = actionUrl;
        document.getElementById('deleteStaffName').innerText = staffName;
        document.getElementById('delete_admin_password').value = '';
        document.getElementById('deleteModal').classList.remove('hidden');
        document.getElementById('deleteModal').classList.add('flex');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.add('hidden');
        document.getElementById('deleteModal').classList.remove('flex');
    }

    function openRestoreModal(actionUrl, staffName) {
        document.getElementById('restoreForm').action = actionUrl;
        document.getElementById('restoreStaffName').innerText = staffName;
        document.getElementById('restore_admin_password').value = '';
        document.getElementById('restoreModal').classList.remove('hidden');
        document.getElementById('restoreModal').classList.add('flex');
    }

    function closeRestoreModal() {
        document.getElementById('restoreModal').classList.add('hidden');
        document.getElementById('restoreModal').classList.remove('flex');
    }
</script>
@endsection
