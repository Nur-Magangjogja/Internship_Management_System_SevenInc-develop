@extends('layouts.dashboard', ['title' => 'Detail Institusi'])

@section('content')
<div class="px-6 py-8">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ request()->routeIs('hr.*') ? route('hr.institutions.index') : route('admin.institutions.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-700">
                    &larr; Kembali ke Master Institusi
                </a>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-slate-800">{{ $institution->name }}</h1>
            <p class="text-xs text-slate-500">Kode Referensi: <span class="font-mono text-slate-700">{{ $institution->public_uuid }}</span></p>
        </div>

        @if (auth()->user()->role === 'admin')
            <div class="flex items-center gap-3">
                <a
                    href="{{ route('admin.institutions.edit', $institution) }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                    </svg>
                    Ubah Data Institusi
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

    <!-- Info Cards Grid -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Profil Kontak Institusi -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
            <h2 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-3">Informasi Institusi</h2>

            <dl class="mt-4 space-y-3.5 text-xs">
                <div>
                    <dt class="font-semibold text-slate-500">Status Kerjasama</dt>
                    <dd class="mt-1">
                        @if ($institution->is_active)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                Aktif Menerima Magang
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 border border-slate-200">
                                Nonaktif
                            </span>
                            @if ($institution->inactive_reason)
                                <p class="mt-1.5 rounded-lg bg-amber-50 p-2.5 text-[11px] text-amber-800 border border-amber-200">
                                    <span class="font-semibold">Alasan:</span> {{ $institution->inactive_reason }}
                                </p>
                            @endif
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="font-semibold text-slate-500">Unit Korespondensi</dt>
                    <dd class="mt-1 text-slate-800">{{ $institution->correspondence_unit ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="font-semibold text-slate-500">Nomor Telepon</dt>
                    <dd class="mt-1 text-slate-800">{{ $institution->phone ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="font-semibold text-slate-500">Email Resmi</dt>
                    <dd class="mt-1 text-slate-800">{{ $institution->email ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="font-semibold text-slate-500">Nomor Fax</dt>
                    <dd class="mt-1 text-slate-800">{{ $institution->fax ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="font-semibold text-slate-500">Alamat</dt>
                    <dd class="mt-1 text-slate-800 leading-relaxed">{{ $institution->address ?? '-' }}</dd>
                </div>
            </dl>
        </div>

        <!-- Master Guru / Dosen / Kontak Pembimbing -->
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <div>
                    <h2 class="text-sm font-bold text-slate-800">
                        Kontak Pembimbing / Guru / Dosen
                        <span class="ml-2 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">{{ $contacts->count() }} orang</span>
                    </h2>
                    <p class="text-[11px] text-slate-500">PIC atau pembimbing institusi untuk koordinasi magang dan tindak lanjut kemitraan.</p>
                </div>
                <button
                    type="button"
                    onclick="openAddContactModal()"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Tambah Pembimbing
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-slate-100 bg-slate-50 font-semibold text-slate-600">
                        <tr>
                            <th class="px-5 py-3 w-10 text-center">No</th>
                            <th class="px-5 py-3">Nama Pembimbing</th>
                            <th class="px-5 py-3">Jabatan / Peran</th>
                            <th class="px-5 py-3">Kontak</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        @forelse ($contacts as $contact)
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-5 py-3 text-center text-slate-400">
                                    {{ $loop->iteration }}
                                </td>
                                <td class="px-5 py-3">
                                    <span class="font-semibold text-slate-800">{{ $contact->name }}</span>
                                    @if ($contact->notes)
                                        <p class="text-[11px] text-slate-400 truncate max-w-xs">{{ $contact->notes }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ $contact->position_title ?? '-' }}
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    @if ($contact->phone)
                                        <div class="flex items-center gap-1">
                                            <svg class="h-3 w-3 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                                            </svg>
                                            <span>{{ $contact->phone }}</span>
                                        </div>
                                    @endif
                                    @if ($contact->email)
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <svg class="h-3 w-3 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                                <polyline points="22,6 12,13 2,6"/>
                                            </svg>
                                            <span>{{ $contact->email }}</span>
                                        </div>
                                    @endif
                                    @if (!$contact->phone && !$contact->email)
                                        <span class="text-slate-400 italic">-</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-col gap-1 items-start">
                                        @if ($contact->is_verified)
                                            <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 border border-emerald-200">
                                                Terverifikasi
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 border border-amber-200">
                                                Menunggu Verifikasi
                                            </span>
                                        @endif

                                        @if ($contact->is_active)
                                            <span class="inline-flex rounded-full bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600 border border-slate-200">
                                                Aktif
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 border border-slate-200">
                                                Nonaktif
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-right">
                                        @if (!$contact->is_verified)
                                            @php
                                                $verifyUrl = request()->routeIs('hr.*')
                                                    ? route('hr.institution-contacts.verify', $contact)
                                                    : route('admin.institution-contacts.verify', $contact);
                                            @endphp
                                            <form action="{{ $verifyUrl }}" method="POST" class="inline">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    class="rounded border border-emerald-300 bg-emerald-50 px-2 py-1 text-emerald-700 hover:bg-emerald-100 text-[11px] font-semibold"
                                                    title="Verifikasi kontak resmi"
                                                >
                                                    Verifikasi
                                                </button>
                                            </form>
                                        @endif

                                        <!-- Follow-Up Link -->
                                        @php
                                            $followUpUrl = (request()->routeIs('hr.*') ? route('hr.follow-up.index') : route('admin.follow-up.index')) . '?search=' . urlencode($contact->name);
                                        @endphp
                                        <a
                                            href="{{ $followUpUrl }}"
                                            class="rounded border border-slate-200 bg-white px-2 py-1 text-blue-600 hover:bg-blue-50 text-[11px] font-semibold"
                                            title="Lihat atau buka riwayat follow-up"
                                        >
                                            Follow-Up
                                        </a>

                                        <!-- Edit Contact Button -->
                                        @php
                                            $updateUrl = request()->routeIs('hr.*')
                                                ? route('hr.institution-contacts.update', $contact)
                                                : route('admin.institution-contacts.update', $contact);
                                            $toggleUrl = request()->routeIs('hr.*')
                                                ? route('hr.institution-contacts.toggle-status', $contact)
                                                : route('admin.institution-contacts.toggle-status', $contact);
                                            $deleteUrl = request()->routeIs('hr.*')
                                                ? route('hr.institution-contacts.destroy', $contact)
                                                : route('admin.institution-contacts.destroy', $contact);
                                        @endphp
                                        <button
                                            type="button"
                                            onclick="openEditContactModal('{{ $updateUrl }}', '{{ addslashes($contact->name) }}', '{{ addslashes($contact->position_title ?? '') }}', '{{ addslashes($contact->phone ?? '') }}', '{{ addslashes($contact->email ?? '') }}', '{{ addslashes($contact->notes ?? '') }}')"
                                            class="rounded border border-slate-200 bg-white px-2 py-1 text-slate-700 hover:bg-slate-50"
                                            title="Ubah data"
                                        >
                                            Ubah
                                        </button>

                                        <!-- Toggle Status -->
                                        <form action="{{ $toggleUrl }}" method="POST" class="inline">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="rounded border border-slate-200 bg-white px-2 py-1 {{ $contact->is_active ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50' }}"
                                            >
                                                {{ $contact->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                            </button>
                                        </form>

                                        <!-- Hapus (Soft Delete) -->
                                        <form action="{{ $deleteUrl }}" method="POST" class="inline" onsubmit="return confirm('Yakin ingin menghapus kontak pembimbing ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="rounded border border-rose-200 bg-white px-2 py-1 text-rose-600 hover:bg-rose-50"
                                            >
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-slate-400">
                                    Belum ada data kontak pembimbing institusi yang terdaftar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Riwayat Pemagang Terkait -->
    <div class="mt-8 rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
            <div>
                <h2 class="text-sm font-bold text-slate-800">
                    Daftar Pemagang Terkait
                    <span class="ml-2 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">{{ $interns->total() }} total</span>
                </h2>
                <p class="text-[11px] text-slate-500">Seluruh pemagang aktif maupun alumni yang terafiliasi dengan institusi ini.</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-slate-100 bg-slate-50 font-semibold text-slate-600">
                    <tr>
                        <th class="px-5 py-3 w-12 text-center">No</th>
                        <th class="px-5 py-3">Nama Pemagang</th>
                        <th class="px-5 py-3">Divisi</th>
                        <th class="px-5 py-3">Periode</th>
                        <th class="px-5 py-3">Pembimbing Tertaut</th>
                        <th class="px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @forelse ($interns as $intern)
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-5 py-3 text-center text-slate-400">
                                {{ ($interns->currentPage() - 1) * $interns->perPage() + $loop->iteration }}
                            </td>
                            <td class="px-5 py-3">
                                <span class="font-semibold text-slate-800">{{ $intern->name ?? $intern->user?->name ?? 'Pemagang' }}</span>
                                <p class="text-[11px] text-slate-400">{{ $intern->user?->email ?? '-' }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600">
                                {{ $intern->division?->name ?? '-' }}
                            </td>
                            <td class="px-5 py-3 text-slate-600">
                                @if ($intern->start_date && $intern->end_date)
                                    {{ \Carbon\Carbon::parse($intern->start_date)->format('d M Y') }} - {{ \Carbon\Carbon::parse($intern->end_date)->format('d M Y') }}
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">
                                @php
                                    $linkedContacts = $intern->institutionContacts;
                                @endphp
                                @if ($linkedContacts->isNotEmpty())
                                    <div class="space-y-1">
                                        @foreach ($linkedContacts as $lc)
                                            <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 border border-blue-200">
                                                {{ $lc->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @elseif ($intern->supervisor_name)
                                    <span class="text-slate-700">{{ $intern->supervisor_name }}</span>
                                    @if ($intern->supervisor_contact)
                                        <p class="text-[11px] text-slate-400">{{ $intern->supervisor_contact }}</p>
                                    @endif
                                @else
                                    <span class="text-slate-400 italic">Belum ada</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @php
                                    $badgeStyles = [
                                        'new'       => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'accepted'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'active'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'completed' => 'bg-purple-50 text-purple-700 border-purple-200',
                                        'rejected'  => 'bg-rose-50 text-rose-700 border-rose-200',
                                    ];
                                    $style = $badgeStyles[$intern->internship_status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
                                @endphp
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold border {{ $style }}">
                                    {{ ucfirst($intern->internship_status ?? 'waiting') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-8 text-center text-slate-400">
                                Belum ada pemagang yang terdaftar dari institusi ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($interns->hasPages())
            <div class="border-t border-slate-100 p-4">
                {{ $interns->links() }}
            </div>
        @endif
    </div>
</div>

<!-- Modal Tambah Kontak Pembimbing -->
<div id="addContactModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h3 class="text-base font-bold text-slate-900">Tambah Kontak Pembimbing</h3>
        <p class="mt-1 text-xs text-slate-500">Daftarkan nama guru pembimbing, dosen, atau PIC untuk institusi ini.</p>

        @php
            $storeContactUrl = request()->routeIs('hr.*')
                ? route('hr.institutions.contacts.store', $institution)
                : route('admin.institutions.contacts.store', $institution);
        @endphp

        <form method="POST" action="{{ $storeContactUrl }}" class="mt-4 space-y-3">
            @csrf
            <div>
                <label for="contact_name" class="block text-xs font-semibold text-slate-700">Nama Lengkap & Gelar <span class="text-rose-500">*</span></label>
                <input
                    type="text"
                    id="contact_name"
                    name="name"
                    required
                    placeholder="Contoh: Drs. Bambang Sutrisno, M.Kom"
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <div>
                <label for="contact_position_title" class="block text-xs font-semibold text-slate-700">Jabatan / Peran</label>
                <input
                    type="text"
                    id="contact_position_title"
                    name="position_title"
                    placeholder="Contoh: Guru Pembimbing / DPL / Kaprodi"
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="contact_phone" class="block text-xs font-semibold text-slate-700">No. WhatsApp / Telepon</label>
                    <input
                        type="text"
                        id="contact_phone"
                        name="phone"
                        placeholder="Contoh: 08123456789"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
                <div>
                    <label for="contact_email" class="block text-xs font-semibold text-slate-700">Email</label>
                    <input
                        type="email"
                        id="contact_email"
                        name="email"
                        placeholder="Contoh: dosen@kampus.ac.id"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>

            <div>
                <label for="contact_notes" class="block text-xs font-semibold text-slate-700">Catatan Khusus</label>
                <textarea
                    id="contact_notes"
                    name="notes"
                    rows="2"
                    placeholder="Catatan tambahan (opsional)..."
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                ></textarea>
            </div>

            <div class="mt-4 flex items-center justify-end gap-3 pt-2 border-t border-slate-100">
                <button
                    type="button"
                    onclick="closeAddContactModal()"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Batal
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    Simpan Pembimbing
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ubah Kontak Pembimbing -->
<div id="editContactModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h3 class="text-base font-bold text-slate-900">Ubah Kontak Pembimbing</h3>

        <form id="editContactForm" method="POST" action="" class="mt-4 space-y-3">
            @csrf
            @method('PUT')

            <div>
                <label for="edit_contact_name" class="block text-xs font-semibold text-slate-700">Nama Lengkap & Gelar <span class="text-rose-500">*</span></label>
                <input
                    type="text"
                    id="edit_contact_name"
                    name="name"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <div>
                <label for="edit_contact_position" class="block text-xs font-semibold text-slate-700">Jabatan / Peran</label>
                <input
                    type="text"
                    id="edit_contact_position"
                    name="position_title"
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="edit_contact_phone" class="block text-xs font-semibold text-slate-700">No. WhatsApp / Telepon</label>
                    <input
                        type="text"
                        id="edit_contact_phone"
                        name="phone"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
                <div>
                    <label for="edit_contact_email" class="block text-xs font-semibold text-slate-700">Email</label>
                    <input
                        type="email"
                        id="edit_contact_email"
                        name="email"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>

            <div>
                <label for="edit_contact_notes" class="block text-xs font-semibold text-slate-700">Catatan Khusus</label>
                <textarea
                    id="edit_contact_notes"
                    name="notes"
                    rows="2"
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                ></textarea>
            </div>

            <div class="mt-4 flex items-center justify-end gap-3 pt-2 border-t border-slate-100">
                <button
                    type="button"
                    onclick="closeEditContactModal()"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Batal
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddContactModal() {
        document.getElementById('addContactModal').classList.remove('hidden');
        document.getElementById('addContactModal').classList.add('flex');
    }

    function closeAddContactModal() {
        document.getElementById('addContactModal').classList.add('hidden');
        document.getElementById('addContactModal').classList.remove('flex');
    }

    function openEditContactModal(actionUrl, name, position, phone, email, notes) {
        document.getElementById('editContactForm').action = actionUrl;
        document.getElementById('edit_contact_name').value = name;
        document.getElementById('edit_contact_position').value = position;
        document.getElementById('edit_contact_phone').value = phone;
        document.getElementById('edit_contact_email').value = email;
        document.getElementById('edit_contact_notes').value = notes;

        document.getElementById('editContactModal').classList.remove('hidden');
        document.getElementById('editContactModal').classList.add('flex');
    }

    function closeEditContactModal() {
        document.getElementById('editContactModal').classList.add('hidden');
        document.getElementById('editContactModal').classList.remove('flex');
    }
</script>
@endsection
