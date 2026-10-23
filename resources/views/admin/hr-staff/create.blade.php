@extends('layouts.dashboard', ['title' => 'Tambah HR Staff Baru'])

@section('content')
<div class="px-6 py-8">
    <div class="mb-6">
        <a
            href="{{ route('admin.hr-staff.index') }}"
            class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-700"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            Kembali ke Daftar HR Staff
        </a>
        <h1 class="mt-2 text-2xl font-bold text-slate-800">Tambah Akun HR Staff</h1>
        <p class="text-sm text-slate-500">Buat akun staf HR baru untuk mengelola kerja sama dan follow-up institusi.</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-xs font-bold text-red-800">Terdapat kesalahan pengisian data:</p>
            <ul class="mt-1 list-inside list-disc text-xs text-red-700">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('admin.hr-staff.store') }}" class="space-y-5">
            @csrf

            <!-- Nama / Username -->
            <div>
                <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Nama / Username <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    placeholder="Contoh: hr_ahmad atau Ahmad Dahlan"
                    class="mt-1.5 w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
                <p class="mt-1 text-[11px] text-slate-400">Digunakan untuk login (username) dan snapshot identitas audit.</p>
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Alamat Email <span class="text-red-500">*</span>
                </label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    placeholder="nama@seveninc.id"
                    class="mt-1.5 w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <!-- Password -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Kata Sandi <span class="text-red-500">*</span>
                    </label>
                    <div class="relative mt-1.5">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            placeholder="Minimal 8 karakter"
                            class="w-full rounded-lg border border-slate-300 px-3.5 py-2 pr-10 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                        <button
                            type="button"
                            onclick="togglePassword('password', this)"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600 focus:outline-none"
                            aria-label="Lihat atau sembunyikan kata sandi"
                        >
                            <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div>
                    <label for="password_confirmation" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Ulangi Kata Sandi <span class="text-red-500">*</span>
                    </label>
                    <div class="relative mt-1.5">
                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            required
                            placeholder="Ketik ulang kata sandi"
                            class="w-full rounded-lg border border-slate-300 px-3.5 py-2 pr-10 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                        >
                        <button
                            type="button"
                            onclick="togglePassword('password_confirmation', this)"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600 focus:outline-none"
                            aria-label="Lihat atau sembunyikan kata sandi"
                        >
                            <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
                <a
                    href="{{ route('admin.hr-staff.index') }}"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Batal
                </a>
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    Simpan Akun HR Staff
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function togglePassword(fieldId, btn) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    const icon = btn.querySelector('.eye-icon');
    if (!icon) return;
    if (isHidden) {
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';
    }
}
</script>
@endpush
@endsection
