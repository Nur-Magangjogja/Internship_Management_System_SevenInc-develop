@extends('layouts.dashboard', ['title' => 'Review Kontak Pembimbing Institusi'])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.contact-review' : 'admin.contact-review';
@endphp

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Antrean Review Kontak Pembimbing</h1>
            <p class="text-sm text-slate-500">Tinjau data pembimbing (guru/dosen) yang dimasukkan oleh pendaftar magang. Tautkan ke kontak resmi terverifikasi atau buat kontak baru kanonikal.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3.5 py-2 text-xs font-semibold text-amber-800">
                <svg class="h-4 w-4 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                {{ $registrations->total() }} Menunggu Verifikasi
            </span>
        </div>
    </div>

    <!-- Alert Success / Error -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Filter & Pencarian -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <form method="GET" action="{{ route($routePrefix . '.index') }}" class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="Cari nama pemagang, nama pembimbing, atau nomor kontak..."
                    class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
                <svg class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </div>
            <div class="flex gap-2">
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    Cari
                </button>
                @if (request('search') || request('institution_id'))
                    <a
                        href="{{ route($routePrefix . '.index') }}"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Reset
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Tabel Antrean Review -->
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-5 py-3.5 text-center w-12">No</th>
                        <th class="px-5 py-3.5">Pendaftar & Institusi</th>
                        <th class="px-5 py-3.5">Data Input Pendaftar</th>
                        <th class="px-5 py-3.5">Opsi Tindakan HR / Admin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($registrations as $reg)
                        @php
                            $contacts = $canonicalContactsByInstitution->get($reg->institution_id, collect());
                        @endphp
                        <tr class="hover:bg-slate-50/70 transition">
                            <td class="px-5 py-4 text-center font-medium text-slate-400">
                                {{ $registrations->firstItem() + $loop->index }}
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-semibold text-slate-900">{{ $reg->fullname ?? 'Tanpa Nama' }}</p>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    {{ $reg->institution->name ?? 'Institusi Belum Terdaftar' }}
                                </p>
                                @if ($reg->division)
                                    <span class="inline-block mt-1 text-[11px] font-medium text-blue-600 bg-blue-50 px-2 py-0.5 rounded">
                                        {{ $reg->division->name }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-medium text-slate-500 w-20">Pembimbing:</span>
                                        <span class="text-xs font-bold text-slate-800">{{ $reg->supervisor_name ?: '-' }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-medium text-slate-500 w-20">No. Kontak:</span>
                                        <span class="text-xs font-mono text-slate-700">{{ $reg->supervisor_contact ?: '-' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-col gap-2.5 max-w-md">
                                    <!-- Form Tautkan Kontak yang Sudah Ada -->
                                    @if ($contacts->isNotEmpty())
                                        <form method="POST" action="{{ route($routePrefix . '.link', $reg) }}" class="flex items-center gap-2">
                                            @csrf
                                            <select
                                                name="institution_contact_id"
                                                class="flex-1 rounded-lg border border-slate-300 py-1.5 px-2.5 text-xs focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                                                required
                                            >
                                                <option value="">-- Pilih Kontak Resmi --</option>
                                                @foreach ($contacts as $contact)
                                                    <option value="{{ $contact->id }}">
                                                        {{ $contact->name }} ({{ $contact->position_title ?: 'Staf/Dosen' }}) - {{ $contact->phone ?: 'No Phone' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <button
                                                type="submit"
                                                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                                            >
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                                                </svg>
                                                Tautkan
                                            </button>
                                        </form>
                                    @else
                                        <p class="text-xs italic text-slate-400">Belum ada kontak resmi terverifikasi di institusi ini.</p>
                                    @endif

                                    <!-- Tombol Buat & Verifikasi Kontak Baru -->
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onclick="openCreateModal('{{ $reg->public_uuid ?? $reg->id }}', '{{ addslashes($reg->supervisor_name ?? '') }}', '{{ addslashes($reg->supervisor_contact ?? '') }}', '{{ route($routePrefix . '.create-and-verify', $reg) }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-blue-600 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 transition hover:bg-blue-100"
                                        >
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                                <circle cx="9" cy="7" r="4"/>
                                                <line x1="19" y1="8" x2="19" y2="14"/>
                                                <line x1="22" y1="11" x2="16" y2="11"/>
                                            </svg>
                                            Buat & Verifikasi Baru
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center text-slate-400">
                                <svg class="mx-auto h-10 w-10 text-slate-300 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                </svg>
                                <p class="text-sm font-semibold text-slate-600">Semua Data Kontak Telah Ditinjau</p>
                                <p class="text-xs text-slate-400 mt-1">Tidak ada pendaftaran yang membutuhkan verifikasi pembimbing saat ini.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($registrations->hasPages())
            <div class="border-t border-slate-200 px-5 py-4">
                {{ $registrations->links() }}
            </div>
        @endif
    </div>
</div>

<!-- Modal Buat Kontak Kanonikal Baru & Verifikasi -->
<div id="createContactModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/50 backdrop-blur-sm">
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl transition-all">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <h3 class="text-base font-bold text-slate-800">Buat Kontak Pembimbing Resmi</h3>
                <button type="button" onclick="closeCreateModal()" class="text-slate-400 hover:text-slate-600">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>

            <form id="createContactForm" method="POST" action="">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Nama Lengkap & Gelar Resmi <span class="text-rose-500">*</span></label>
                        <input
                            type="text"
                            name="name"
                            id="modal_name"
                            class="w-full rounded-lg border border-slate-300 py-2 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                            required
                        />
                        <p class="text-[11px] text-slate-400 mt-0.5">Koreksi ejaan nama resmi dan gelar kanonikal.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Jabatan / Posisi Resmi</label>
                        <input
                            type="text"
                            name="position_title"
                            id="modal_position_title"
                            placeholder="Contoh: Dosen Pembimbing Lapangan, Kaprodi, Guru BK"
                            class="w-full rounded-lg border border-slate-300 py-2 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        />
                        <p class="text-[11px] text-slate-400 mt-0.5">Ditetapkan oleh HR/Admin sesuai jabatan riil.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Nomor Telepon / WhatsApp</label>
                        <input
                            type="text"
                            name="phone"
                            id="modal_phone"
                            class="w-full rounded-lg border border-slate-300 py-2 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Email Institusi / Pribadi</label>
                        <input
                            type="email"
                            name="email"
                            id="modal_email"
                            class="w-full rounded-lg border border-slate-300 py-2 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Catatan Verifikasi</label>
                        <textarea
                            name="notes"
                            id="modal_notes"
                            rows="2"
                            class="w-full rounded-lg border border-slate-300 py-2 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                            placeholder="Catatan verifikasi kontak..."
                        ></textarea>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                    <button
                        type="button"
                        onclick="closeCreateModal()"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Batal
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                    >
                        Simpan, Verifikasi & Tautkan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openCreateModal(uuid, rawName, rawPhone, actionUrl) {
    document.getElementById('createContactForm').action = actionUrl;
    document.getElementById('modal_name').value = rawName || '';
    document.getElementById('modal_phone').value = rawPhone || '';
    document.getElementById('modal_position_title').value = '';
    document.getElementById('modal_email').value = '';
    document.getElementById('modal_notes').value = 'Dibuat & diverifikasi melalui Review Kontak Pembimbing';
    document.getElementById('createContactModal').classList.remove('hidden');
}

function closeCreateModal() {
    document.getElementById('createContactModal').classList.add('hidden');
}
</script>
@endsection
