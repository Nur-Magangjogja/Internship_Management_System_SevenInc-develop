@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.dashboard.index') }}"
            class="flex h-9 w-9 items-center justify-center rounded-[9px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F]">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Akun Saya</p>
            <h1 class="text-xl font-extrabold tracking-tight text-[#1B3A34]">Edit Profil</h1>
        </div>
    </div>

    <div class="max-w-lg space-y-5">

        {{-- Flash message --}}
        @if(session('success'))
        <div class="flex items-center gap-3 rounded-[10px] border border-green-200 bg-green-50 px-4 py-3 text-sm text-[#1F5F3F]">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            {{ session('success') }}
        </div>
        @endif

        @if($errors->any())
        <div class="rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm text-[#D32F2F]">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <form action="{{ route('admin.profile.update') }}" method="POST">
            @csrf @method('PUT')

            {{-- ── Identitas ── --}}
            <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-6 shadow-sm">

                {{-- Avatar --}}
                <div class="mb-6 flex items-center gap-4">
                    @php
                        $initials = collect(explode(' ', $user->name ?? 'A'))->take(2)->map(fn($w)=>strtoupper($w[0]??''))->implode('');
                    @endphp
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#E8F5E9] text-lg font-bold text-[#1F5F3F]">
                        {{ $initials ?: 'A' }}
                    </div>
                    <div>
                        <p class="font-semibold text-[#1B3A34]">{{ $user->name ?? 'Admin' }}</p>
                        <p class="text-[12px] text-[#4B5F5A]">{{ $user->email }}</p>
                        <span class="mt-1 inline-block rounded-full bg-[#E8F5E9] px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-[#1F5F3F]">
                            {{ $user->role }}
                        </span>
                    </div>
                </div>

                <p class="mb-4 text-[12px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Informasi Akun</p>

                {{-- Username --}}
                <div class="mb-4">
                    <label for="username" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Username <span class="text-[#D32F2F]">*</span>
                    </label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        required
                        value="{{ old('username', $user->attributes['name'] ?? $user->name) }}"
                        placeholder="Masukkan username Anda"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]"
                    >
                    @error('username')
                        <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Email --}}
                <div>
                    <label for="email" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Email <span class="text-[#D32F2F]">*</span>
                    </label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        value="{{ old('email', $user->email) }}"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]"
                    >
                    @error('email')
                        <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- ── Ganti Password ── --}}
            <div class="mt-5 rounded-[12px] border border-[#DCE7E1] bg-white p-6 shadow-sm">
                <p class="mb-1 text-[12px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Keamanan</p>
                <p class="mb-5 text-[12px] text-[#4B5F5A]">Kosongkan semua field di bawah jika tidak ingin mengganti password.</p>

                {{-- Password saat ini --}}
                <div class="mb-4">
                    <label for="current_password" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Password Saat Ini
                    </label>
                    <div class="relative">
                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            autocomplete="current-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                            placeholder="Masukkan password saat ini"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 pr-10 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]"
                        >
                        <button type="button" onclick="togglePassword('current_password', this)" aria-label="Lihat password"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-[#4B5F5A] transition hover:text-[#1B3A34]">
                            <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    @error('current_password')
                        <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Password baru --}}
                <div class="mb-4">
                    <label for="password" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Password Baru
                    </label>
                    <div class="relative">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                            placeholder="Min. 8 karakter"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 pr-10 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]"
                        >
                        <button type="button" class="toggle-new-password-btn" onclick="togglePassword('password', this)" aria-label="Lihat password"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-[#4B5F5A] transition hover:text-[#1B3A34]">
                            <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    @error('password')
                        <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Konfirmasi password --}}
                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Konfirmasi Password Baru
                    </label>
                    <div class="relative">
                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                            placeholder="Ulangi password baru"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-white px-3 py-2.5 pr-10 text-[13px] text-[#1B3A34] outline-none transition focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]"
                        >
                        <button type="button" class="toggle-new-password-btn" onclick="togglePassword('password_confirmation', this)" aria-label="Lihat password"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-[#4B5F5A] transition hover:text-[#1B3A34]">
                            <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>

                    {{-- Indikator kekuatan password --}}
                    <div id="strength-bar-wrap" class="mt-2 hidden">
                        <div class="flex gap-1">
                            <div class="h-1 flex-1 rounded-full bg-[#DCE7E1]" id="s1"></div>
                            <div class="h-1 flex-1 rounded-full bg-[#DCE7E1]" id="s2"></div>
                            <div class="h-1 flex-1 rounded-full bg-[#DCE7E1]" id="s3"></div>
                            <div class="h-1 flex-1 rounded-full bg-[#DCE7E1]" id="s4"></div>
                        </div>
                        <p id="strength-label" class="mt-1 text-[11px] text-[#4B5F5A]"></p>
                    </div>
                </div>
            </div>

            {{-- Tombol Aksi --}}
            <div class="mt-5 flex items-center gap-3">
                <button type="submit"
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1F5F3F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    Simpan Perubahan
                </button>
                <a href="{{ route('admin.dashboard.index') }}"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-5 py-2.5 text-sm font-semibold text-[#4B5F5A] transition hover:bg-[#F4F8F6]">
                    Batal
                </a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function togglePassword(fieldId, btn) {
    if (fieldId === 'password' || fieldId === 'password_confirmation') {
        const pw = document.getElementById('password');
        const pwConf = document.getElementById('password_confirmation');
        const isHidden = (pw || pwConf)?.type === 'password';
        const newType = isHidden ? 'text' : 'password';
        const newLabel = isHidden ? 'Sembunyikan password' : 'Lihat password';
        const newIcon = isHidden
            ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
            : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';

        if (pw) pw.type = newType;
        if (pwConf) pwConf.type = newType;

        document.querySelectorAll('.toggle-new-password-btn').forEach(b => {
            b.setAttribute('aria-label', newLabel);
            const icon = b.querySelector('.eye-icon');
            if (icon) icon.innerHTML = newIcon;
        });
        return;
    }

    const input = document.getElementById(fieldId);
    if (!input) return;
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Lihat password');
    const icon = btn.querySelector('.eye-icon');
    if (icon) {
        icon.innerHTML = isHidden
            ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
            : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const pwInput = document.getElementById('password');
    const wrap    = document.getElementById('strength-bar-wrap');
    const label   = document.getElementById('strength-label');
    const bars    = [document.getElementById('s1'), document.getElementById('s2'),
                     document.getElementById('s3'), document.getElementById('s4')];

    const colors  = ['bg-red-400', 'bg-orange-400', 'bg-yellow-400', 'bg-[#2D8659]'];
    const labels  = ['Sangat lemah', 'Lemah', 'Cukup kuat', 'Kuat'];

    function score(pw) {
        let s = 0;
        if (pw.length >= 8)              s++;
        if (/[A-Z]/.test(pw))            s++;
        if (/[0-9]/.test(pw))            s++;
        if (/[^A-Za-z0-9]/.test(pw))     s++;
        return s;
    }

    pwInput.addEventListener('input', function () {
        const val = this.value;
        if (!val) { wrap.classList.add('hidden'); return; }
        wrap.classList.remove('hidden');

        const s = score(val);
        bars.forEach((b, i) => {
            b.className = 'h-1 flex-1 rounded-full ' + (i < s ? colors[s - 1] : 'bg-[#DCE7E1]');
        });
        label.textContent = labels[s - 1] ?? '';
        label.style.color = s <= 1 ? '#ef4444' : s === 2 ? '#f97316' : s === 3 ? '#ca8a04' : '#1F5F3F';
    });
});
</script>
@endpush
@endsection
