@extends('layouts.dashboard')

@section('content')

{{-- Modal Konfirmasi Hapus (Soft Delete) --}}
<div id="deleteModal" class="fixed inset-0 z-[110] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden">
            <div class="p-6">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
                    <svg class="h-7 w-7 text-[#D32F2F]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                </div>
                <h3 class="mb-2 text-center text-[15px] font-bold text-[#1B3A34]">Hapus pengguna ke arsip?</h3>
                <p class="mb-4 text-center text-[12.5px] text-[#4B5F5A] leading-relaxed">
                    Akun <strong id="deleteUserName"></strong> akan dinonaktifkan dan dipindahkan ke arsip (soft delete). Data pendaftaran dan dokumen pemagang tetap terjaga.
                </p>
                <form id="deleteForm" method="POST">
                    @csrf @method('DELETE')
                    <div class="mb-4 text-left">
                        <label for="delete_admin_password" class="mb-1 block text-xs font-semibold text-slate-700">
                            Konfirmasi Kata Sandi Admin
                        </label>
                        <input
                            type="password"
                            id="delete_admin_password"
                            name="admin_password"
                            required
                            placeholder="Masukkan kata sandi admin"
                            class="w-full rounded-[9px] border border-slate-300 px-3 py-2 text-xs text-slate-800 focus:border-red-500 focus:outline-none"
                        >
                    </div>
                    <div class="flex justify-end gap-3 border-t border-[#DCE7E1] pt-4">
                        <button type="button" onclick="closeDeleteModal()"
                            class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#1B3A34] hover:bg-[#F4F8F6]">
                            Batal
                        </button>
                        <button type="submit"
                            class="rounded-[9px] bg-[#D32F2F] px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                            Ya, Pindahkan ke Arsip
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Modal Konfirmasi Restore --}}
<div id="restoreModal" class="fixed inset-0 z-[110] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden p-6">
            <h3 class="text-base font-bold text-slate-800">Konfirmasi Pemulihan Akun</h3>
            <p class="mt-2 text-xs text-slate-600">
                Anda akan memulihkan akun <span id="restoreUserName" class="font-bold"></span> dari arsip terhapus agar dapat login dan mengakses dokumen kembali.
            </p>
            <form id="restoreForm" method="POST" class="mt-4">
                @csrf
                <div>
                    <label for="restore_admin_password" class="mb-1 block text-xs font-semibold text-slate-700">
                        Konfirmasi Kata Sandi Admin
                    </label>
                    <input
                        type="password"
                        id="restore_admin_password"
                        name="admin_password"
                        required
                        placeholder="Masukkan kata sandi admin"
                        class="w-full rounded-[9px] border border-slate-300 px-3 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onclick="closeRestoreModal()"
                        class="rounded-[9px] border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                    >
                        Batal
                    </button>
                    <button
                        type="submit"
                        class="rounded-[9px] bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-700"
                    >
                        Pulihkan Akun
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal Konfirmasi Ban --}}
<div id="banModal" class="fixed inset-0 z-[110] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden">
            <div class="p-6">
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-full bg-orange-50">
                        <svg class="h-6 w-6 text-orange-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-[15px] font-bold text-[#1B3A34]">Nonaktifkan akun?</h3>
                        <p class="text-[12px] text-[#4B5F5A]">Akun <strong id="banUserName"></strong> tidak akan bisa login.</p>
                    </div>
                </div>
                <form id="banForm" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                            Alasan Ban <span class="text-[#4B5F5A] font-normal">(opsional)</span>
                        </label>
                        <input type="text" name="ban_reason" id="banReason"
                            placeholder="Misal: Melanggar ketentuan penggunaan"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    </div>
                    <div class="flex justify-end gap-3 border-t border-[#DCE7E1] pt-4">
                        <button type="button" onclick="closeBanModal()"
                            class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#1B3A34] hover:bg-[#F4F8F6]">
                            Batal
                        </button>
                        <button type="submit"
                            class="rounded-[9px] bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600">
                            Nonaktifkan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Modal Tambah Admin Baru --}}
<div id="addUserModal" class="fixed inset-0 z-[110] {{ $errors->hasBag('default') && old('_is_add_user') ? '' : 'hidden' }}">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onclick="closeAddUserModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-[16px] bg-white shadow-xl overflow-hidden">
            <div class="flex items-center justify-between border-b border-[#DCE7E1] px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[#E8F5E9] text-[#1F5F3F]">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-[16px] font-bold text-[#1B3A34]">Tambah Admin Baru</h3>
                        <p class="text-[12px] text-[#4B5F5A]">Khusus untuk menambahkan akun dengan hak akses Admin</p>
                    </div>
                </div>
                <button type="button" onclick="closeAddUserModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <form action="{{ route('admin.users.store') }}" method="POST" class="p-6">
                @csrf
                <input type="hidden" name="_is_add_user" value="1">
                <input type="hidden" name="role" value="admin">

                <div class="space-y-4">
                    {{-- Username --}}
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                            Username <span class="text-[#D32F2F]">*</span>
                        </label>
                        <input type="text" name="name" required value="{{ old('name') }}"
                            placeholder="Contoh: admin_utama"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        @error('name')
                            <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Email --}}
                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                            Email <span class="text-[#D32F2F]">*</span>
                        </label>
                        <input type="email" name="email" required value="{{ old('email') }}"
                            placeholder="Contoh: admin@seveninc.com"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        @error('email')
                            <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Password & Konfirmasi --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                                Password <span class="text-[#D32F2F]">*</span>
                            </label>
                            <input type="password" name="password" required placeholder="Min. 8 karakter"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                            @error('password')
                                <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                                Konfirmasi Password <span class="text-[#D32F2F]">*</span>
                            </label>
                            <input type="password" name="password_confirmation" required placeholder="Ulangi password"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3 border-t border-[#DCE7E1] pt-4">
                    <button type="button" onclick="closeAddUserModal()"
                        class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#1B3A34] hover:bg-[#F4F8F6]">
                        Batal
                    </button>
                    <button type="submit"
                        class="rounded-[9px] bg-[#2D8659] px-5 py-2 text-sm font-semibold text-white hover:bg-[#1F5F3F] transition shadow-sm">
                        Simpan Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Toast --}}
<div id="toastStack" class="fixed bottom-5 right-5 z-[200] flex flex-col gap-2"></div>

<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Manajemen Pengguna</p>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Semua Pengguna</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Kelola akun pengguna, role, dan status aktivitas dalam sistem.</p>
        </div>
        <button type="button" onclick="openAddUserModal()"
            class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-[13px] font-semibold text-white hover:bg-[#1F5F3F] transition shadow-sm">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Admin Baru
        </button>
    </div>

    @if(session('success'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-[#D32F2F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        {{ session('error') }}
    </div>
    @endif

    {{-- Card --}}
    <div class="overflow-hidden rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm">

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('admin.users.index') }}">
            <div class="flex flex-wrap items-center gap-2 border-b border-[#DCE7E1] px-4 py-4 sm:px-6">

                {{-- Search nama --}}
                <label class="flex items-center gap-2 rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2">
                    <svg class="h-4 w-4 shrink-0 text-[#4B5F5A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="name" value="{{ request('name') }}" placeholder="Cari nama..."
                        class="w-36 border-0 bg-transparent text-[13px] text-[#1B3A34] outline-none placeholder:text-[#4B5F5A]">
                </label>

                {{-- Search email --}}
                <label class="flex items-center gap-2 rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2">
                    <svg class="h-4 w-4 shrink-0 text-[#4B5F5A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <input type="text" name="email" value="{{ request('email') }}" placeholder="Cari email..."
                        class="w-40 border-0 bg-transparent text-[13px] text-[#1B3A34] outline-none placeholder:text-[#4B5F5A]">
                </label>

                {{-- Filter role --}}
                <select name="role"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                    <option value=""         {{ request('role','') === ''         ? 'selected':'' }}>Semua Role</option>
                    <option value="admin"    {{ request('role','') === 'admin'    ? 'selected':'' }}>Admin</option>
                    <option value="hr_staff" {{ request('role','') === 'hr_staff' ? 'selected':'' }}>HR Staff</option>
                    <option value="pemagang" {{ request('role','') === 'pemagang' ? 'selected':'' }}>Pemagang</option>
                    <option value="user"     {{ request('role','') === 'user'     ? 'selected':'' }}>User</option>
                </select>

                {{-- Filter status --}}
                <select name="status"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                    <option value=""       {{ request('status','') === ''       ? 'selected':'' }}>Semua Status</option>
                    <option value="active" {{ request('status','') === 'active' ? 'selected':'' }}>Aktif</option>
                    <option value="banned" {{ request('status','') === 'banned' ? 'selected':'' }}>Nonaktif</option>
                    <option value="trashed" {{ request('status','') === 'trashed' ? 'selected':'' }}>Arsip Terhapus</option>
                </select>

                {{-- Tombol Filter --}}
                <button type="submit"
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-[13px] font-semibold text-white transition hover:bg-[#1F5F3F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Filter
                </button>

                @if(request()->hasAny(['name','email','role','sort','status']))
                <a href="{{ route('admin.users.index') }}"
                    class="flex items-center gap-1.5 rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2 text-[13px] font-semibold text-[#4B5F5A] transition hover:text-[#D32F2F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Reset
                </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] text-left text-sm">
                <thead>
                    <tr>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Pengguna</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Email</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Role</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Status Akun</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#DCE7E1]">
                    @forelse($users as $user)
                    @php
                        $role = strtolower($user->role ?? 'user');
                        $roleMeta = match($role) {
                            'admin'    => ['label'=>'Admin',    'cls'=>'bg-purple-50 text-purple-700 border border-purple-200'],
                            'hr_staff' => ['label'=>'HR Staff', 'cls'=>'bg-amber-50 text-amber-700 border border-amber-200'],
                            'pemagang' => ['label'=>'Pemagang', 'cls'=>'bg-[#E8F5E9] text-[#1F5F3F] border border-[#A5D6A7]'],
                            default    => ['label'=>'User',     'cls'=>'bg-blue-50 text-blue-700 border border-blue-200'],
                        };
                        $displayName = $user->display_name ?? $user->name;
                        $initials = collect(explode(' ', $displayName))->take(2)->map(fn($w)=>strtoupper($w[0]??''))->implode('');
                        $isBanned  = (bool) $user->is_banned;
                        $isTrashed = method_exists($user, 'trashed') && $user->trashed();
                    @endphp
                    <tr class="transition hover:bg-[#F4F8F6] {{ $isBanned || $isTrashed ? 'opacity-75' : '' }}">

                        {{-- Pengguna --}}
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold
                                    {{ $isTrashed ? 'bg-rose-100 text-rose-500' : ($isBanned ? 'bg-gray-100 text-gray-400' : 'bg-[#E8F5E9] text-[#1F5F3F]') }}">
                                    {{ $initials }}
                                    @if(!$isBanned && !$isTrashed && $user->is_online)
                                    <span class="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full border-2 border-white bg-[#388E3C]"></span>
                                    @endif
                                    @if($isBanned && !$isTrashed)
                                    <span class="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full border-2 border-white bg-orange-500">
                                        <svg class="h-2 w-2 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </span>
                                    @endif
                                </div>
                                <div>
                                    <p class="font-semibold {{ $isBanned || $isTrashed ? 'text-[#4B5F5A]' : 'text-[#1B3A34]' }}">{{ $displayName }}</p>
                                    <p class="mt-0.5 text-[11.5px] text-[#4B5F5A]">
                                        @if($displayName !== $user->name)
                                            <span class="font-mono text-[11px] text-[#2D8659]">{{ $user->name }} &bull;</span>
                                        @endif
                                        {{ $user->email }}
                                    </p>
                                </div>
                            </div>
                        </td>

                        {{-- Email --}}
                        <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">{{ $user->email }}</td>

                        {{-- Role --}}
                        <td class="px-5 py-4">
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $roleMeta['cls'] }}">
                                {{ $roleMeta['label'] }}
                            </span>
                        </td>

                        {{-- Status Akun --}}
                        <td class="px-5 py-4">
                            @if($isTrashed)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-rose-700 border border-rose-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                    Arsip Terhapus
                                </span>
                            @elseif($isBanned)
                                <div>
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-50 px-2.5 py-1 text-[11px] font-semibold text-orange-700 border border-orange-200">
                                        <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span>
                                        Nonaktif
                                    </span>
                                    @if($user->ban_reason)
                                    <p class="mt-1 text-[11px] text-[#4B5F5A] max-w-[160px] truncate" title="{{ $user->ban_reason }}">
                                        {{ $user->ban_reason }}
                                    </p>
                                    @endif
                                </div>
                            @elseif($user->is_online)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-[#E8F5E9] px-2.5 py-1 text-[11px] font-semibold text-[#388E3C] border border-[#A5D6A7]">
                                    <span class="h-1.5 w-1.5 rounded-full bg-[#388E3C] animate-pulse"></span>
                                    Online
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-[#F4F8F6] px-2.5 py-1 text-[11px] font-semibold text-[#4B5F5A] border border-[#DCE7E1]">
                                    <span class="h-1.5 w-1.5 rounded-full bg-[#4B5F5A]"></span>
                                    Aktif
                                </span>
                            @endif
                        </td>

                        {{-- Aksi --}}
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-1.5">
                                @if($isTrashed)
                                    {{-- Tombol Pulihkan Akun --}}
                                    <button type="button" title="Pulihkan Akun"
                                        onclick="openRestoreModal('{{ route('admin.users.restore', $user->id) }}', '{{ addslashes($user->name) }}')"
                                        class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-blue-200 bg-blue-50 text-blue-600 transition hover:bg-blue-600 hover:text-white">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                                        </svg>
                                    </button>
                                @else
                                    {{-- Lihat Detail --}}
                                    <a href="{{ route('admin.users.show', $user->id) }}" title="Lihat Detail"
                                        class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F]">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </a>

                                    @if($user->role === 'hr_staff')
                                        {{-- Kelola di Manajemen HR Staff --}}
                                        <a href="{{ route('admin.hr-staff.edit', $user->id) }}" title="Kelola di Manajemen HR Staff"
                                            class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-blue-200 bg-blue-50 text-blue-600 transition hover:bg-blue-600 hover:text-white">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                                        </a>
                                    @else
                                        {{-- Edit --}}
                                        <a href="{{ route('admin.users.edit', $user->id) }}" title="Edit Pengguna"
                                            class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-amber-400 hover:text-amber-600">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>

                                        @if($user->role !== 'admin')
                                            @if($isBanned)
                                                {{-- Tombol Aktifkan Kembali --}}
                                                <form method="POST" action="{{ route('admin.users.unban', $user->id) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" title="Aktifkan Kembali"
                                                        class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#A5D6A7] bg-[#E8F5E9] text-[#1F5F3F] transition hover:bg-[#2D8659] hover:text-white">
                                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                                    </button>
                                                </form>
                                            @else
                                                {{-- Tombol Nonaktifkan (Ban) --}}
                                                <button type="button" title="Nonaktifkan Akun"
                                                    onclick="openBanModal('{{ route('admin.users.ban', $user->id) }}', '{{ addslashes($user->name) }}')"
                                                    class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-orange-200 bg-orange-50 text-orange-600 transition hover:bg-orange-500 hover:text-white">
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                                </button>
                                            @endif

                                            {{-- Hapus (Pindahkan ke Arsip) --}}
                                            <button type="button" title="Pindahkan ke Arsip"
                                                onclick="openDeleteModal('{{ route('admin.users.destroy', $user->id) }}', '{{ addslashes($user->name) }}')"
                                                class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-red-200 bg-red-50 text-[#D32F2F] transition hover:bg-red-100">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                            </button>
                                        @endif
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-[#4B5F5A]">
                            Tidak ada pengguna yang sesuai filter.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="flex items-center justify-between border-t border-[#DCE7E1] px-5 py-4 text-[12.5px] text-[#4B5F5A]">
            <span>Menampilkan <strong class="text-[#1B3A34]">{{ $users->firstItem() ?? 0 }}</strong>–<strong class="text-[#1B3A34]">{{ $users->lastItem() ?? 0 }}</strong> dari <strong class="text-[#1B3A34]">{{ $users->total() }}</strong> pengguna</span>
            <div>{{ $users->appends(request()->query())->links() }}</div>
        </div>
    </div>
</div>

<script>
// ── Add User modal ────────────────────────────────────────────────────────────
function openAddUserModal() {
    document.getElementById('addUserModal').classList.remove('hidden');
}
function closeAddUserModal() {
    document.getElementById('addUserModal').classList.add('hidden');
}

// ── Delete modal ──────────────────────────────────────────────────────────────
function openDeleteModal(action, name) {
    document.getElementById('deleteUserName').textContent = name;
    document.getElementById('deleteForm').action = action;
    const pwd = document.getElementById('delete_admin_password');
    if (pwd) pwd.value = '';
    document.getElementById('deleteModal').classList.remove('hidden');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// ── Restore modal ─────────────────────────────────────────────────────────────
function openRestoreModal(action, name) {
    document.getElementById('restoreUserName').textContent = name;
    document.getElementById('restoreForm').action = action;
    const pwd = document.getElementById('restore_admin_password');
    if (pwd) pwd.value = '';
    document.getElementById('restoreModal').classList.remove('hidden');
}
function closeRestoreModal() {
    document.getElementById('restoreModal').classList.add('hidden');
}
document.getElementById('restoreModal').addEventListener('click', function(e) {
    if (e.target === this) closeRestoreModal();
});

// ── Ban modal ─────────────────────────────────────────────────────────────────
function openBanModal(action, name) {
    document.getElementById('banUserName').textContent = name;
    document.getElementById('banForm').action = action;
    document.getElementById('banReason').value = '';
    document.getElementById('banModal').classList.remove('hidden');
}
function closeBanModal() {
    document.getElementById('banModal').classList.add('hidden');
}
document.getElementById('banModal').addEventListener('click', function(e) {
    if (e.target === this) closeBanModal();
});
</script>

@endsection
