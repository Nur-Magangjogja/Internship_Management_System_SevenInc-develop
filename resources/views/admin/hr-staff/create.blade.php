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
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        placeholder="Minimal 8 karakter"
                        class="mt-1.5 w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
                <div>
                    <label for="password_confirmation" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Ulangi Kata Sandi <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        required
                        placeholder="Ketik ulang kata sandi"
                        class="mt-1.5 w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
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
@endsection
