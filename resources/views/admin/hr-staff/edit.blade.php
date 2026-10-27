@extends('layouts.dashboard', ['title' => 'Ubah Data HR Staff'])

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
        <h1 class="mt-2 text-2xl font-bold text-slate-800">Ubah Data HR Staff</h1>
        <p class="text-sm text-slate-500">Perbarui informasi profil atau atur ulang kata sandi staf HR.</p>
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
        <form method="POST" action="{{ route('admin.hr-staff.update', $hrStaff) }}" class="space-y-5">
            @csrf
            @method('PUT')

            <!-- Nama / Username -->
            <div>
                <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                    Nama / Username <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="{{ old('name', $hrStaff->name) }}"
                    required
                    class="mt-1.5 w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
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
                    value="{{ old('email', $hrStaff->email) }}"
                    required
                    class="mt-1.5 w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <!-- Password Baru (Opsional) -->
            <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                <p class="text-xs font-bold text-slate-700">Atur Ulang Kata Sandi (Opsional)</p>
                <p class="mt-0.5 text-[11px] text-slate-500">Kosongkan jika tidak ingin mengubah kata sandi.</p>

                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="block text-xs font-medium text-slate-700">
                            Kata Sandi Baru
                        </label>
                        <div class="relative mt-1">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                                placeholder="Minimal 8 karakter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 pr-10 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                            <button
                                type="button"
                                onclick="togglePassword('password', this)"
                                class="toggle-hr-password-btn absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600 focus:outline-none"
                                aria-label="Lihat kata sandi"
                            >
                                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-xs font-medium text-slate-700">
                            Ulangi Kata Sandi Baru
                        </label>
                        <div class="relative mt-1">
                            <input
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                                placeholder="Ketik ulang kata sandi baru"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 pr-10 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                            <button
                                type="button"
                                onclick="togglePassword('password_confirmation', this)"
                                class="toggle-hr-password-btn absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600 focus:outline-none"
                                aria-label="Lihat kata sandi"
                            >
                                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
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
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function togglePassword(fieldId, btn) {
    const p1 = document.getElementById('password');
    const p2 = document.getElementById('password_confirmation');
    const isHidden = (p1 || p2)?.type === 'password';
    const newType = isHidden ? 'text' : 'password';
    const newLabel = isHidden ? 'Sembunyikan kata sandi' : 'Lihat kata sandi';
    const newIcon = isHidden
        ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
        : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';

    if (p1) p1.type = newType;
    if (p2) p2.type = newType;

    document.querySelectorAll('.toggle-hr-password-btn').forEach(b => {
        b.setAttribute('aria-label', newLabel);
        const icon = b.querySelector('.eye-icon');
        if (icon) icon.innerHTML = newIcon;
    });
}
</script>
@endpush
@endsection
