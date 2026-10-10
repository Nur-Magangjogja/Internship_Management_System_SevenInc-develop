@extends('layouts.dashboard', ['title' => 'Tambah Institusi Baru'])

@section('content')
<div class="px-6 py-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.institutions.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-700">
                    &larr; Kembali ke Master Institusi
                </a>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-slate-800">Tambah Institusi Baru</h1>
            <p class="text-sm text-slate-500">Daftarkan nama kampus atau sekolah mitra baru beserta informasi kontaknya.</p>
        </div>
    </div>

    <!-- Alert Error -->
    @if ($errors->any())
        <div class="mb-6 max-w-2xl rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('admin.institutions.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-xs font-semibold text-slate-700">Nama Institusi / Kampus <span class="text-rose-500">*</span></label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    placeholder="Contoh: Universitas Gadjah Mada"
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="correspondence_unit" class="block text-xs font-semibold text-slate-700">Unit / Bagian Korespondensi</label>
                    <input
                        type="text"
                        id="correspondence_unit"
                        name="correspondence_unit"
                        value="{{ old('correspondence_unit') }}"
                        placeholder="Contoh: Biro Kerjasama / BKK / Humas"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label for="phone" class="block text-xs font-semibold text-slate-700">Nomor Telepon</label>
                    <input
                        type="text"
                        id="phone"
                        name="phone"
                        value="{{ old('phone') }}"
                        placeholder="Contoh: 0274-123456 / 08123456789"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-700">Alamat Email Resmi</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="Contoh: kerjasama@ugm.ac.id"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label for="fax" class="block text-xs font-semibold text-slate-700">Nomor Fax</label>
                    <input
                        type="text"
                        id="fax"
                        name="fax"
                        value="{{ old('fax') }}"
                        placeholder="Contoh: 0274-654321"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>

            <div>
                <label for="address" class="block text-xs font-semibold text-slate-700">Alamat Lengkap</label>
                <textarea
                    id="address"
                    name="address"
                    rows="3"
                    placeholder="Contoh: Jl. Sosio Humaniora No. 1, Bulaksumur, Sleman, DI Yogyakarta"
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >{{ old('address') }}</textarea>
            </div>

            <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-100">
                <a
                    href="{{ route('admin.institutions.index') }}"
                    class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Batal
                </a>
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    Simpan Institusi
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
