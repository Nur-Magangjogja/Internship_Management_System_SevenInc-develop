@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.users.index') }}"
            class="flex h-9 w-9 items-center justify-center rounded-[9px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F]">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Manajemen Pengguna</p>
            <h1 class="text-xl font-extrabold tracking-tight text-[#1B3A34]">Tambah Admin Baru</h1>
        </div>
    </div>

    <div class="max-w-lg">
        <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-6 shadow-sm">

            <div class="mb-6 flex items-center gap-3 border-b border-[#DCE7E1] pb-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#E8F5E9] text-[#1F5F3F]">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-[15px] font-bold text-[#1B3A34]">Form Tambah Admin Baru</h2>
                    <p class="text-[12px] text-[#4B5F5A]">Khusus untuk menambahkan akun dengan hak akses Admin</p>
                </div>
            </div>

            @if($errors->any())
            <div class="mb-5 rounded-[9px] border border-red-200 bg-red-50 px-4 py-3 text-sm text-[#D32F2F]">
                <ul class="list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form action="{{ route('admin.users.store') }}" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="role" value="admin">

                {{-- Username --}}
                <div>
                    <label for="name" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Username <span class="text-[#D32F2F]">*</span>
                    </label>
                    <input type="text" id="name" name="name" required value="{{ old('name') }}"
                        placeholder="Contoh: admin_baru"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]">
                    @error('name')
                        <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Email --}}
                <div>
                    <label for="email" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Email <span class="text-[#D32F2F]">*</span>
                    </label>
                    <input type="email" id="email" name="email" required value="{{ old('email') }}"
                        placeholder="Contoh: admin@example.com"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]">
                    @error('email')
                        <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Password & Konfirmasi --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="password" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                            Password <span class="text-[#D32F2F]">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" id="password" name="password" required placeholder="Min. 8 karakter"
                                autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 pr-10 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]">
                            <button type="button" class="toggle-admin-user-password-btn absolute right-3 top-1/2 -translate-y-1/2 text-[#4B5F5A] transition hover:text-[#1B3A34] focus:outline-none"
                                onclick="toggleAdminUserPasswords()" aria-label="Lihat password">
                                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                            Konfirmasi <span class="text-[#D32F2F]">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" id="password_confirmation" name="password_confirmation" required placeholder="Ulangi password"
                                autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 pr-10 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]">
                            <button type="button" class="toggle-admin-user-password-btn absolute right-3 top-1/2 -translate-y-1/2 text-[#4B5F5A] transition hover:text-[#1B3A34] focus:outline-none"
                                onclick="toggleAdminUserPasswords()" aria-label="Lihat password">
                                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3 border-t border-[#DCE7E1] pt-4">
                    <button type="submit"
                        class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1F5F3F] shadow-sm">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Simpan Admin
                    </button>
                    <a href="{{ route('admin.users.index') }}"
                        class="rounded-[9px] border border-[#DCE7E1] bg-white px-5 py-2.5 text-sm font-semibold text-[#4B5F5A] transition hover:bg-[#F4F8F6]">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function toggleAdminUserPasswords() {
    const p1 = document.getElementById('password');
    const p2 = document.getElementById('password_confirmation');
    const isHidden = (p1 || p2)?.type === 'password';
    const newType = isHidden ? 'text' : 'password';
    const newLabel = isHidden ? 'Sembunyikan password' : 'Lihat password';
    const newIcon = isHidden
        ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
        : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';

    if (p1) p1.type = newType;
    if (p2) p2.type = newType;

    document.querySelectorAll('.toggle-admin-user-password-btn').forEach(b => {
        b.setAttribute('aria-label', newLabel);
        const icon = b.querySelector('.eye-icon');
        if (icon) icon.innerHTML = newIcon;
    });
}
</script>
@endpush
@endsection
