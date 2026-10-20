@extends('layouts.dashboard')

@php
    use Illuminate\Support\Str;

    // Mode: 'pendaftar' (waiting/accepted/rejected) atau 'pemagang' (active/completed/exited)
    $mode  = $mode  ?? 'all';
    $scope = $scope ?? 'all';

    // Filter tabs berdasarkan mode
    $filterTabs = match($mode) {
        'pendaftar' => [
            'waiting'  => 'Menunggu Review',
            'accepted' => 'Diterima',
            'rejected' => 'Ditolak',
            'trashed'  => 'Arsip Terhapus',
        ],
        'pemagang' => [
            'active'       => 'Aktif',
            'participants' => 'Peserta Administratif',
            'completed'    => 'Selesai',
            'exited'       => 'Keluar',
            'trashed'      => 'Arsip Terhapus',
        ],
        'trashed' => [
            'trashed'   => 'Arsip Terhapus',
        ],
        default => [
            'all'       => 'Semua',
            'waiting'   => 'Menunggu',
            'pending'   => 'Pending',
            'accepted'  => 'Diterima',
            'active'    => 'Aktif',
            'completed' => 'Selesai',
            'rejected'  => 'Ditolak',
            'exited'    => 'Keluar',
            'trashed'   => 'Arsip Terhapus',
        ],
    };

    // Breadcrumb label
    $breadcrumb = match($mode) {
        'pendaftar' => 'Pendaftaran & Status',
        'pemagang'  => 'Pendaftaran & Status',
        default     => 'Data Pemagang',
    };
@endphp

@section('content')

{{-- ===== MODALS ===== --}}
@push('modals')

{{-- Modal: App (Detail & Edit) --}}
<div id="appModal" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" data-modal-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div id="appModalDialog"
             class="w-full max-w-4xl rounded-[16px] bg-white shadow-xl overflow-hidden">
            <div class="flex items-center justify-between border-b border-[#DCE7E1] px-5 py-4">
                <h3 id="appModalTitle" class="text-[15px] font-bold text-[#1B3A34]">Modal</h3>
                <button type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#F4F8F6] text-[#4B5F5A] hover:bg-[#DCE7E1]"
                        data-modal-close>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div id="appModalBody" class="max-h-[75vh] overflow-auto p-5"></div>
        </div>
    </div>
</div>

{{-- Modal: Pilih Brand (saat acc/terima) --}}
<div id="brandModal" class="fixed inset-0 z-[115] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" id="brandModalOverlay"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden">
            {{-- Header --}}
            <div class="flex items-center gap-3 border-b border-[#DCE7E1] px-5 py-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[9px] bg-[#E8F5E9]">
                    <svg class="h-5 w-5 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <div>
                    <h3 class="text-[15px] font-bold text-[#1B3A34]">Pilih Brand</h3>
                    <p id="brandModalSubtitle" class="text-[11.5px] text-[#4B5F5A]">Pilih brand untuk pemagang yang diterima</p>
                </div>
            </div>
            {{-- Body --}}
            <div class="p-5 space-y-3">
                <p class="text-[12.5px] text-[#4B5F5A]">
                    Sebelum menerima pendaftar ini, tentukan brand mana yang akan ditempati.
                    <span class="font-semibold text-[#1B3A34]">Brand wajib dipilih.</span>
                </p>
                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Brand <span class="text-red-500">*</span></label>
                    <select id="brandSelect"
                        class="w-full rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]">
                        <option value="">— Pilih Brand —</option>
                        @foreach($brands ?? [] as $brand)
                            <option value="{{ $brand->name }}">{{ $brand->name }}</option>
                        @endforeach
                    </select>
                    <p id="brandSelectError" class="mt-1 hidden text-[11.5px] text-red-600">Silakan pilih brand terlebih dahulu.</p>
                </div>
            </div>
            {{-- Footer --}}
            <div class="flex items-center justify-end gap-3 border-t border-[#DCE7E1] px-5 py-4">
                <button id="brandModalCancel"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#4B5F5A] hover:bg-[#F4F8F6]">
                    Batal
                </button>
                <button id="brandModalConfirm"
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1F5F3F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Terima & Simpan
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Ubah Status Follow-Up --}}
<div id="followupModal" class="fixed inset-0 z-[115] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" id="followupModalOverlay"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-[16px] bg-white shadow-xl overflow-hidden">
            {{-- Header --}}
            <div class="flex items-center gap-3 border-b border-[#DCE7E1] px-5 py-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[9px] bg-[#E0F2FE]">
                    <svg class="h-5 w-5 text-[#0284C7]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-[15px] font-bold text-[#1B3A34]">Status Follow-Up</h3>
                    <p id="followupModalSubtitle" class="text-[11.5px] text-[#4B5F5A]">Perbarui status tindak lanjut pendaftar</p>
                </div>
            </div>
            {{-- Body --}}
            <div class="p-5 space-y-4">
                <div id="followupModalAlert" class="hidden rounded-[8px] border border-red-200 bg-red-50 p-3 text-xs text-red-700"></div>

                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Status Tindak Lanjut <span class="text-red-500">*</span>
                    </label>
                    <select id="followupStatusSelect"
                        class="w-full rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2.5 text-[12.5px] text-[#1B3A34] outline-none focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]">
                        <option value="">— Pilih Status Follow-Up —</option>
                        @foreach(\App\Models\InternshipRegistration::FOLLOWUP_STATUS_LABELS as $fKey => $fLabel)
                            <option value="{{ $fKey }}">{{ $fLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">
                        Catatan / Alasan (Opsional)
                    </label>
                    <textarea id="followupReasonInput" rows="2" placeholder="Catatan tambahan perubahan status..."
                        class="w-full rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2 text-[12.5px] text-[#1B3A34] outline-none focus:border-[#2D8659] focus:ring-1 focus:ring-[#2D8659]"></textarea>
                </div>
            </div>
            {{-- Footer --}}
            <div class="flex items-center justify-end gap-3 border-t border-[#DCE7E1] px-5 py-4">
                <button id="followupModalCancel" type="button"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#4B5F5A] hover:bg-[#F4F8F6]">
                    Batal
                </button>
                <button id="followupModalConfirm" type="button"
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1F5F3F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Simpan Perubahan
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Konfirmasi Hapus (Soft Delete ke Arsip) --}}
<div id="confirmModal" class="fixed inset-0 z-[110] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" data-confirm-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden">
            <div class="p-6">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
                    <svg class="h-7 w-7 text-[#D32F2F]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <h3 class="mb-2 text-center text-[15px] font-bold text-[#1B3A34]">Pindahkan pendaftaran ke arsip?</h3>
                <p id="confirmBody" class="mb-4 text-center text-[12.5px] text-[#4B5F5A] leading-relaxed">
                    Data pendaftaran pemagang akan dipindahkan ke arsip (soft delete). Dokumen dan riwayat historis tetap terjaga.
                </p>
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
                    <button class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#1B3A34] hover:bg-[#F4F8F6]" data-confirm-close>Batal</button>
                    <button id="confirmYes" class="rounded-[9px] bg-[#D32F2F] px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Ya, Pindahkan</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Konfirmasi Pemulihan (Restore dari Arsip) --}}
<div id="restoreModal" class="fixed inset-0 z-[110] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" data-restore-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden p-6">
            <h3 class="text-base font-bold text-slate-800">Konfirmasi Pemulihan Pendaftaran</h3>
            <p id="restoreBody" class="mt-2 text-xs text-slate-600">
                Anda akan memulihkan data pendaftaran ini dari arsip terhapus.
            </p>
            <div class="mt-4">
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
            <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4">
                <button
                    type="button"
                    class="rounded-[9px] border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                    data-restore-close
                >
                    Batal
                </button>
                <button
                    type="button"
                    id="restoreYes"
                    class="rounded-[9px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-blue-700"
                >
                    Pulihkan Pendaftaran
                </button>
            </div>
        </div>
    </div>
</div>

{{-- ===================================================
     MODAL GENERATE DOKUMEN — 1 modal, 4 jenis surat
     Dibuka via openGenerateModal(internId, jenisSurat)
     Jenis: 'loa' | 'skl' | 'sertifikat' | 'penilaian'
     =================================================== --}}
<div id="generateModal" class="fixed inset-0 z-[120] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onclick="closeGenerateModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-[16px] bg-white shadow-xl overflow-hidden">

            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-[#DCE7E1] px-5 py-4">
                <div class="flex items-center gap-3">
                    <div id="genModalIcon" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[9px] bg-[#E8F5E9]">
                        <svg class="h-5 w-5 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                    </div>
                    <div>
                        <h3 id="genModalTitle" class="text-[15px] font-bold text-[#1B3A34]">Generate Dokumen</h3>
                        <p id="genModalSubtitle" class="text-[11.5px] text-[#4B5F5A]">Buat dan kirim dokumen ke pemagang</p>
                    </div>
                </div>
                <button onclick="closeGenerateModal()"
                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#F4F8F6] text-[#4B5F5A] hover:bg-[#DCE7E1]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-5 space-y-4">

                {{-- Preview data pemagang --}}
                <div id="genInternPreview" class="rounded-[10px] bg-[#F4F8F6] px-4 py-3">
                    <div class="flex items-center gap-3">
                        <img id="genInternAvatarImg" class="hidden h-10 w-10 shrink-0 rounded-full object-cover border border-gray-200">
                        <div id="genInternAvatar" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#E8F5E9] text-sm font-bold text-[#1F5F3F]">—</div>
                        <div>
                            <p id="genInternName" class="font-semibold text-[#1B3A34]">—</p>
                            <p id="genInternMeta" class="text-[11.5px] text-[#4B5F5A]">—</p>
                        </div>
                        <span id="genInternBadge" class="ml-auto inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold bg-[#F4F8F6] text-[#4B5F5A] border border-[#DCE7E1]">—</span>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-[12.5px]">
                        <div><span class="font-semibold text-[#4B5F5A]">Institusi:</span> <span id="genInternInstitution" class="text-[#1B3A34]">—</span></div>
                        <div><span class="font-semibold text-[#4B5F5A]">Divisi:</span> <span id="genInternDivision" class="text-[#1B3A34]">—</span></div>
                        <div><span class="font-semibold text-[#4B5F5A]">Mulai:</span> <span id="genInternStart" class="text-[#1B3A34]">—</span></div>
                        <div><span class="font-semibold text-[#4B5F5A]">Selesai:</span> <span id="genInternEnd" class="text-[#1B3A34]">—</span></div>
                    </div>
                </div>

                {{-- Pilih jenis surat --}}
                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Jenis Dokumen</label>
                    <div id="genDocOptions" class="grid grid-cols-2 gap-2"></div>
                </div>

                {{-- Info --}}
                <div id="genDocInfo" class="hidden rounded-[9px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[12.5px] text-[#4B5F5A]"></div>

            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-end gap-3 border-t border-[#DCE7E1] px-5 py-4">
                <button type="button" onclick="closeGenerateModal()"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#4B5F5A] hover:bg-[#F4F8F6]">
                    Batal
                </button>
                <button id="genModalBtn" type="button" disabled
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#1F5F3F] disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Generate PDF
                </button>
            </div>
        </div>
    </div>
</div>

@endpush

{{-- ===== TOAST ===== --}}
<div id="toastStack" class="fixed bottom-5 right-5 z-[200] flex flex-col gap-2"></div>

{{-- Flash session → toast --}}
@if(session('success'))
<script>
document.addEventListener('DOMContentLoaded', function() {
    const el = document.createElement('div');
    el.className = 'flex items-center gap-2 rounded-[10px] border bg-[#1B3A34] border-[#2D8659] text-white px-4 py-3 text-[13px] font-semibold shadow-lg';
    el.innerHTML = `<div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#2D8659]"><svg class="h-3 w-3 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div><span>{!! addslashes(session('success')) !!}</span>`;
    document.getElementById('toastStack').appendChild(el);
    setTimeout(() => el.remove(), 6000);
});
</script>
@endif
@if(session('error'))
<script>
document.addEventListener('DOMContentLoaded', function() {
    const el = document.createElement('div');
    el.className = 'flex items-center gap-2 rounded-[10px] border bg-red-50 border-red-200 text-red-800 px-4 py-3 text-[13px] font-semibold shadow-lg';
    el.innerHTML = `<span>{{ addslashes(session('error')) }}</span>`;
    document.getElementById('toastStack').appendChild(el);
    setTimeout(() => el.remove(), 5000);
});
</script>
@endif

{{-- ===== KONTEN UTAMA ===== --}}
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6">
        <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">{{ $breadcrumb }}</p>
        <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">
            {{ $title ?? 'Data Pemagang' }}
        </h1>
        <p class="mt-1 text-sm text-[#4B5F5A]">
            @if($mode === 'pendaftar')
                Kelola status pendaftar magang — Menunggu Review, Diterima, atau Ditolak.
            @elseif($mode === 'pemagang')
                Pantau pemagang aktif, yang sudah selesai, dan yang keluar.
            @else
                Pantau daftar pemagang, status, dan tindakan admin dalam satu tampilan.
            @endif
        </p>
    </div>

    {{-- Card utama --}}
    <div class="overflow-hidden rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm">

        {{-- Toolbar: filter tab + search --}}
        <div class="flex flex-col gap-3 border-b border-[#DCE7E1] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">

            {{-- Filter tabs — sesuai mode --}}
            <div id="filterTabs" class="flex flex-wrap gap-1.5 rounded-[9px] border border-[#DCE7E1] bg-[#F4F8F6] p-1">
                @foreach($filterTabs as $key => $label)
                <button type="button"
                    data-filter="{{ $key }}"
                    class="filter-tab rounded-[6px] px-3 py-1.5 text-[12.5px] font-semibold transition
                        {{ $scope === $key ? 'bg-white text-[#1F5F3F] shadow-sm' : 'text-[#4B5F5A] hover:text-[#1B3A34]' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>

            {{-- Search & Follow-up Filter --}}
            <div class="flex flex-wrap items-center gap-2">
                <select id="followup-filter" class="rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2 text-[12.5px] font-semibold text-[#1B3A34] outline-none focus:border-[#2D8659]">
                    <option value="">Semua Status Follow-Up</option>
                    @foreach(\App\Models\InternshipRegistration::FOLLOWUP_STATUS_LABELS as $fKey => $fLabel)
                        <option value="{{ $fKey }}">{{ $fLabel }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 rounded-[9px] border border-[#DCE7E1] bg-white px-3 py-2">
                    <svg class="h-4 w-4 text-[#4B5F5A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input id="intern-search" type="search" placeholder="Cari nama / universitas..."
                        class="w-48 border-0 bg-transparent text-[13px] text-[#1B3A34] outline-none placeholder:text-[#4B5F5A] sm:w-56" />
                </label>
            </div>
        </div>

        {{-- Tabel --}}
        <div id="tableWrap" class="overflow-x-auto" data-base="{{ url('/admin/interns') }}">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white rounded-tl-none first:rounded-tl-lg">Nama Pendaftar</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Divisi</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Universitas</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Tgl Daftar</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Status</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white text-right rounded-tr-none last:rounded-tr-lg">Aksi</th>
                    </tr>
                </thead>
                <tbody id="rows" class="divide-y divide-[#DCE7E1] bg-white">
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-sm text-[#4B5F5A]">
                            Memuat data pemagang...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Pending bar (bulk status change) --}}
        <div id="pendingBar" class="hidden border-t border-[#DCE7E1] bg-amber-50 px-5 py-3">
            <div class="flex flex-wrap items-center justify-end gap-2">
                <span class="text-sm text-amber-800">
                    <strong id="pendingCount">0</strong> perubahan belum disimpan
                </span>
                <button id="discardAll"
                    class="rounded-[9px] border border-amber-300 px-3 py-1.5 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">
                    Batalkan
                </button>
                <button id="saveAll" disabled
                    class="rounded-[9px] bg-[#2D8659] px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-[#1F5F3F] disabled:cursor-not-allowed disabled:opacity-60">
                    Simpan
                </button>
            </div>
        </div>

        {{-- Pagination --}}
        <div id="pager" class="border-t border-[#DCE7E1] px-5 py-4"></div>
    </div>
</div>

<script>
window.rowData = window.rowData || new Map();

function debounce(fn, ms = 400) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

document.addEventListener('DOMContentLoaded', () => {

    const ADMIN_INTERNS_BASE = @json(url('/admin/interns'));
    const API_URL            = @json(route('admin.interns.api'));
    const SCOPE              = @json($scope ?? 'all');
    const MODE               = @json($mode  ?? 'all');
    const GENERATE_URL       = @json(route('admin.interns.generate.doc'));
    const csrf               = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const rowsEl       = document.getElementById('rows');
    const pagerEl      = document.getElementById('pager');
    const searchInput  = document.getElementById('intern-search');
    const pendingBar   = document.getElementById('pendingBar');
    const pendingCount = document.getElementById('pendingCount');
    const saveAllBtn   = document.getElementById('saveAll');
    const discardBtn   = document.getElementById('discardAll');
    const toastStack   = document.getElementById('toastStack');
    const pending      = new Map();

    // ── Status meta ────────────────────────────────────────────────────────────
    const STATUS_META = {
        waiting:   { label: 'Menunggu Review', cls: 'bg-amber-50 text-amber-700 border border-amber-200' },
        pending:   { label: 'Pending',         cls: 'bg-amber-100 text-amber-800 border border-amber-300' },
        accepted:  { label: 'Diterima',        cls: 'bg-[#E8F5E9] text-[#1F5F3F] border border-[#A5D6A7]' },
        active:    { label: 'Magang Aktif',    cls: 'bg-blue-50 text-blue-700 border border-blue-200' },
        completed: { label: 'Selesai',         cls: 'bg-slate-100 text-slate-700 border border-slate-300' },
        rejected:  { label: 'Ditolak',         cls: 'bg-red-50 text-red-700 border border-red-200' },
        exited:    { label: 'Keluar',          cls: 'bg-red-50 text-red-700 border border-red-200' },
    };

    // ── Toast ──────────────────────────────────────────────────────────────────
    function pushToast(message, type = 'success') {
        const isOk = type === 'success';
        const el = document.createElement('div');
        el.className = `flex items-center gap-2 rounded-[10px] border px-4 py-3 text-[13px] font-semibold shadow-lg
            ${isOk
                ? 'bg-[#1B3A34] border-[#2D8659] text-white'
                : 'bg-red-50 border-red-200 text-red-800'}`;
        el.innerHTML = `
            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full ${isOk ? 'bg-[#2D8659]' : 'bg-red-500'}">
                <svg class="h-3 w-3 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                    ${isOk ? '<polyline points="20 6 9 17 4 12"/>' : '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'}
                </svg>
            </div>
            <span>${message}</span>`;
        toastStack.appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    // ── Pending bar ─────────────────────────────────────────────────────────────
    function updatePendingBar() {
        const n = pending.size;
        pendingCount.textContent = n;
        pendingBar.classList.toggle('hidden', n === 0);
        saveAllBtn.disabled = n === 0;
    }

    function markSelect(sel, active) {
        sel.classList.toggle('ring-2', active);
        sel.classList.toggle('ring-amber-400', active);
        sel.classList.toggle('bg-amber-50', active);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────
    const fmtStr  = (s) => (s && String(s).trim() !== '' ? String(s) : '-');
    const fmtDate = (s) => {
        if (!s) return '-';
        const d = new Date(s);
        if (isNaN(d)) return String(s);
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    function initials(name) {
        return (name || 'P').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]?.toUpperCase() || '').join('') || 'P';
    }

    // ── Filter tabs ─────────────────────────────────────────────────────────────
    let activeFilter = SCOPE || 'all';

    document.querySelectorAll('.filter-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            activeFilter = btn.dataset.filter;
            document.querySelectorAll('.filter-tab').forEach(b => {
                const isActive = b.dataset.filter === activeFilter;
                b.classList.toggle('bg-white', isActive);
                b.classList.toggle('text-[#1F5F3F]', isActive);
                b.classList.toggle('shadow-sm', isActive);
                b.classList.toggle('text-[#4B5F5A]', !isActive);
            });
            loadPage(1);
        });
    });

    // ── Search ──────────────────────────────────────────────────────────────────
    const doSearch = debounce(() => loadPage(1), 400);
    if (searchInput) searchInput.addEventListener('input', doSearch);

    // ── Build badge HTML ────────────────────────────────────────────────────────
    function badgeHtml(status, id) {
        const m = STATUS_META[status] || { label: status, cls: 'bg-gray-100 text-gray-700 border border-gray-300' };
        return `<span id="badge-${id}" class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ${m.cls}">${m.label}</span>`;
    }

    // ── Build action cell ───────────────────────────────────────────────────────
    function buildActionCell(it) {
        const status = String(it.internship_status || 'waiting').toLowerCase();

        // Tombol generate berdasarkan status & mode
        let extraButtons = '';

        if (MODE === 'pendaftar' && status === 'accepted') {
            // Tombol LOA
            extraButtons += `
            <a href="{{ url('admin/loa/download') }}/${it.id}" target="_blank" title="Unduh LOA"
                class="flex items-center gap-1.5 rounded-[8px] border border-[#2D8659] bg-[#E8F5E9] px-2.5 py-1.5 text-[11px] font-semibold text-[#1F5F3F] transition hover:bg-[#2D8659] hover:text-white">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                LOA
            </a>`;
            // Tombol Aktifkan → pindah ke Data Pemagang
            extraButtons += `
            <button type="button" title="Aktifkan sebagai Pemagang"
                class="flex items-center gap-1.5 rounded-[8px] border border-blue-400 bg-blue-50 px-2.5 py-1.5 text-[11px] font-semibold text-blue-700 transition hover:bg-blue-600 hover:text-white js-activate"
                data-id="${it.id}"
                data-url="${it.status_update_url || `${ADMIN_INTERNS_BASE}/${it.id}/status`}">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Aktifkan
            </button>`;
        }

        if (MODE === 'pemagang' && (status === 'active' || status === 'accepted')) {
            extraButtons += `
            <a href="{{ url('admin/loa/download') }}/${it.id}" target="_blank" title="Unduh LOA"
                class="flex items-center gap-1.5 rounded-[8px] border border-[#2D8659] bg-[#E8F5E9] px-2.5 py-1.5 text-[11px] font-semibold text-[#1F5F3F] transition hover:bg-[#2D8659] hover:text-white">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                LOA
            </a>`;
        }

        if (MODE === 'pemagang' && status === 'completed') {
            extraButtons = `
            <a href="{{ url('admin/loa/download') }}/${it.id}" target="_blank" title="Unduh LOA"
                class="flex items-center gap-1.5 rounded-[8px] border border-[#2D8659] bg-[#E8F5E9] px-2.5 py-1.5 text-[11px] font-semibold text-[#1F5F3F] transition hover:bg-[#2D8659] hover:text-white">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                LOA
            </a>
            <button type="button" title="Generate Dokumen Selesai"
                class="flex items-center gap-1.5 rounded-[8px] border border-[#2D8659] bg-[#E8F5E9] px-2.5 py-1.5 text-[11px] font-semibold text-[#1F5F3F] transition hover:bg-[#2D8659] hover:text-white js-gen-doc"
                data-id="${it.id}" data-jenis="skl">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                Dokumen
            </button>`;
        }

        if (it.is_trashed || activeFilter === 'trashed') {
            return `
            <div class="flex items-center justify-end gap-1.5 flex-wrap">
                <button type="button" title="Lihat Detail"
                    class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F] js-detail"
                    data-id="${it.id}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <button type="button" title="Pulihkan Pendaftaran"
                    class="flex h-8 px-2.5 items-center justify-center rounded-[8px] border border-blue-200 bg-blue-50 text-blue-600 text-xs font-semibold transition hover:bg-blue-100 js-restore"
                    data-id="${it.id}">
                    Pulihkan
                </button>
            </div>`;
        }

        return `
        <div class="flex items-center justify-end gap-1.5 flex-wrap">
            ${extraButtons}
            <button type="button" title="Lihat Detail"
                class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F] js-detail"
                data-id="${it.id}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
            <button type="button" title="Edit Data"
                class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-amber-400 hover:text-amber-600 js-edit"
                data-id="${it.id}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
            <button type="button" title="Hapus"
                class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-red-200 bg-red-50 text-[#D32F2F] transition hover:bg-red-100 js-delete"
                data-id="${it.id}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
        </div>`;
    }

    // ── Build status cell (badge + select) ──────────────────────────────────────
    // Filter opsi dropdown sesuai mode
    function allowedStatuses() {
        if (MODE === 'pendaftar') return ['waiting', 'accepted', 'rejected'];
        if (MODE === 'pemagang')  return ['accepted', 'active', 'completed', 'exited'];
        return Object.keys(STATUS_META);
    }

    function buildStatusCell(it) {
        const cur = it.internship_status || 'waiting';
        const allowed = allowedStatuses();
        const statusOptions = allowed.map(val => {
            const m = STATUS_META[val] || { label: val };
            return `<option value="${val}" ${val === cur ? 'selected' : ''}>${m.label}</option>`;
        }).join('');

        let followupHtml = '';
        if (it.followup_status_label) {
            followupHtml = `
            <div class="mt-1 flex items-center gap-1.5 flex-wrap">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-medium ${it.followup_status_class || 'bg-slate-100 text-slate-700'}">
                    ${it.followup_status_label}
                </span>
                <button type="button" class="js-edit-followup text-[#4B5F5A] hover:text-[#1F5F3F] transition p-0.5 rounded" data-id="${it.id}" title="Ubah status follow-up">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
            </div>`;
        } else if (cur === 'accepted') {
            followupHtml = `
            <div class="mt-1 flex items-center gap-1.5 flex-wrap">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-medium bg-amber-50 text-amber-700 border border-amber-200">
                    Belum di-follow-up
                </span>
                <button type="button" class="js-edit-followup text-[#4B5F5A] hover:text-[#1F5F3F] transition p-0.5 rounded" data-id="${it.id}" title="Atur status follow-up">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
            </div>`;
        }

        let conditionHtml = '';
        if (it.current_condition_case === 'B') {
            conditionHtml = `
            <div class="mt-0.5">
                <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium bg-amber-50 text-amber-800 border border-amber-200" title="Kondisi Pelaksanaan">
                    ${it.current_condition_label || 'Keluar setelah mulai magang (belum selesai)'}
                </span>
            </div>`;
        } else if (it.current_condition_case === 'D') {
            conditionHtml = `
            <div class="mt-0.5">
                <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium bg-purple-50 text-purple-800 border border-purple-200" title="Verifikasi Riwayat Diperlukan">
                    ${it.current_condition_label || 'Keluar — riwayat mulai perlu diverifikasi'}
                </span>
            </div>`;
        }

        return `
        <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
                ${badgeHtml(cur, it.id)}
                <select
                    class="js-status-select rounded-[8px] border border-[#DCE7E1] bg-white px-2 py-1.5 text-[11px] font-semibold text-[#1B3A34] outline-none focus:border-[#2D8659]"
                    data-url="${it.status_update_url || `${ADMIN_INTERNS_BASE}/${it.id}/status`}"
                    data-id="${it.id}" data-current="${cur}" data-intern-id="${it.id}">
                    ${statusOptions}
                </select>
            </div>
            ${followupHtml}
            ${conditionHtml}
        </div>`;
    }

    // ── Apply badge setelah update ───────────────────────────────────────────────
    function applyBadge(badgeEl, newVal) {
        const m = STATUS_META[newVal] || { label: newVal, cls: 'bg-gray-100 text-gray-700 border border-gray-300' };
        badgeEl.className = `inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ${m.cls}`;
        badgeEl.textContent = m.label;
    }

    // ── Render rows ──────────────────────────────────────────────────────────────
    function renderRows(payload) {
        const { data, meta } = payload;

        if (!data || data.length === 0) {
            rowsEl.innerHTML = `
                <tr>
                    <td colspan="6" class="px-5 py-12 text-center text-sm text-[#4B5F5A]">
                        Belum ada data pemagang untuk ditampilkan.
                    </td>
                </tr>`;
            pagerEl.innerHTML = '';
            return;
        }

        rowsEl.innerHTML = data.map(it => {
            window.rowData.set(it.id, it);

            const status       = String(it.internship_status || 'waiting').toLowerCase();
            const ini          = initials(it.fullname);
            const institution  = fmtStr(it.institution_name || '-');
            const division     = fmtStr(it.internship_interest || '-');
            const createdAt    = fmtDate(it.created_at);
            const searchText   = `${it.fullname || ''} ${it.email || ''} ${institution} ${division}`.toLowerCase();

            return `
            <tr data-row-id="${it.id}" data-status="${status}"
                data-search-text="${searchText.replace(/"/g, '&quot;')}"
                class="transition hover:bg-[#F4F8F6]">

                {{-- Nama Pendaftar --}}
                <td class="px-5 py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#E8F5E9] text-sm font-bold text-[#1F5F3F]">
                            ${ini}
                        </div>
                        <div>
                            <p class="font-semibold text-[#1B3A34]">${fmtStr(it.fullname || 'Tanpa nama')}</p>
                            <p class="mt-0.5 text-[11.5px] text-[#4B5F5A]">${fmtStr(it.email || '-')}</p>
                            ${it.brand ? `<span class="mt-1 inline-flex items-center rounded-full bg-indigo-50 border border-indigo-200 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">${it.brand}</span>` : ''}
                        </div>
                    </div>
                </td>

                {{-- Divisi --}}
                <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">${division}</td>

                {{-- Universitas --}}
                <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">${institution}</td>

                {{-- Tgl Daftar --}}
                <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">${createdAt}</td>

                {{-- Status --}}
                <td class="px-5 py-4">${buildStatusCell(it)}</td>

                {{-- Aksi --}}
                <td class="px-5 py-4">${buildActionCell(it)}</td>
            </tr>`;
        }).join('');

        bindStatusListeners();
        bindRowActions();
        buildPager(meta);
    }

    // ── Brand Modal ──────────────────────────────────────────────────────────────
    // Saat admin pilih "accepted" di mode pendaftar, muncul modal pilih brand
    // Pending item disimpan dulu dengan brand = null, lalu setelah konfirmasi brand diisi

    const brandModal        = document.getElementById('brandModal');
    const brandSelect       = document.getElementById('brandSelect');
    const brandSelectError  = document.getElementById('brandSelectError');
    const brandModalCancel  = document.getElementById('brandModalCancel');
    const brandModalConfirm = document.getElementById('brandModalConfirm');
    const brandModalOverlay = document.getElementById('brandModalOverlay');
    let   _brandPendingId   = null; // id intern yang sedang menunggu konfirmasi brand

    function openBrandModal(internId, internName) {
        _brandPendingId = internId;
        document.getElementById('brandModalSubtitle').textContent =
            `Menerima: ${internName || 'pemagang ini'}`;
        brandSelect.value = '';
        brandSelectError.classList.add('hidden');
        brandModal.classList.remove('hidden');
        brandSelect.focus();
    }

    function closeBrandModal(revertSelect = false) {
        brandModal.classList.add('hidden');
        if (revertSelect && _brandPendingId !== null) {
            // Kembalikan select ke nilai lama
            const item = pending.get(_brandPendingId);
            if (item?.select) {
                item.select.value = item.from;
                markSelect(item.select, false);
            }
            pending.delete(_brandPendingId);
            updatePendingBar();
        }
        _brandPendingId = null;
    }

    brandModalOverlay?.addEventListener('click', () => closeBrandModal(true));
    brandModalCancel?.addEventListener('click',  () => closeBrandModal(true));

    brandModalConfirm?.addEventListener('click', () => {
        const brand = brandSelect.value.trim();
        if (!brand) {
            brandSelectError.classList.remove('hidden');
            brandSelect.focus();
            return;
        }
        brandSelectError.classList.add('hidden');

        // Simpan id sebelum closeBrandModal men-null-kan _brandPendingId
        const itemId = _brandPendingId;

        // Simpan brand ke pending item
        if (itemId !== null && pending.has(itemId)) {
            pending.get(itemId).brand = brand;
        }

        closeBrandModal(false);

        // Ambil item dan simpan langsung
        const savedItem = pending.get(itemId);
        if (savedItem) {
            saveOneItem(savedItem);
        }
    });

    // Simpan satu item pending (accept + brand)
    async function saveOneItem(item) {
        try {
            const fd = new FormData();
            fd.append('_method', 'PATCH');
            fd.append('internship_status', item.to);
            if (item.brand) fd.append('brand', item.brand);

            const res = await fetch(item.url, {
                method: 'POST', body: fd,
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            item.select.dataset.current = item.to;
            markSelect(item.select, false);
            applyBadge(item.badge, item.to);
            pending.delete(item.id);
            updatePendingBar();

            // Update rowData brand & status
            const rowObj = window.rowData.get(item.id);
            if (rowObj) {
                rowObj.brand = item.brand;
                rowObj.internship_status = item.to;
            }

            // Refresh tombol di baris ini
            const row = item.select.closest('tr[data-row-id]');
            if (row) refreshGenerateButtons(row, item.id, item.to);

            pushToast(`${item.name} diterima${item.brand ? ` untuk brand ${item.brand}` : ''}.`, 'success');
        } catch(e) {
            pushToast(`Gagal menyimpan: ${e.message}`, 'error');
            // Kembalikan select ke nilai lama
            item.select.value = item.from;
            markSelect(item.select, false);
            pending.delete(item.id);
            updatePendingBar();
        }
    }

    // ── Bind status select ───────────────────────────────────────────────────────
    function bindStatusListeners() {
        document.querySelectorAll('.js-status-select').forEach(sel => {
            sel.onchange = function () {
                const url  = this.dataset.url;
                const id   = Number(this.dataset.id);
                const name = (window.rowData.get(id)?.fullname) || 'pemagang';
                const from = this.dataset.current;
                const to   = this.value;

                if (to === from) {
                    if (pending.has(id)) {
                        pending.delete(id);
                        markSelect(this, false);
                        updatePendingBar();
                    }
                    return;
                }

                // Simpan ke pending dulu
                pending.set(id, {
                    id, name, from, to, url,
                    brand: null,
                    select: this,
                    badge: document.getElementById(`badge-${id}`)
                });
                markSelect(this, true);
                updatePendingBar();

                // Kalau status → accepted di mode pendaftar, wajib pilih brand dulu (jika belum ada)
                if (MODE === 'pendaftar' && to === 'accepted') {
                    const internData = window.rowData.get(id);
                    if (internData && internData.brand) {
                        const item = pending.get(id);
                        if (item) {
                            item.brand = internData.brand;
                            saveOneItem(item);
                        }
                        return; // jangan refresh tombol dulu — dilakukan setelah save
                    } else {
                        openBrandModal(id, name);
                        return; // jangan refresh tombol dulu — dilakukan setelah konfirmasi
                    }
                }
            };
        });
    }

    // Refresh tombol generate di baris setelah status berubah
    function refreshGenerateButtons(row, id, newStatus) {
        const actionsCell = row.querySelector('td:last-child');
        if (!actionsCell) return;

        // Hapus tombol dinamis lama (LOA, Dokumen, Aktifkan)
        actionsCell.querySelectorAll('.js-gen-doc, .js-activate').forEach(b => b.remove());

        // Tambah tombol baru sesuai status baru
        const wrapper = actionsCell.querySelector('div');
        if (!wrapper) return;

        if (MODE === 'pendaftar' && newStatus === 'accepted') {
            // Tombol Aktifkan
            const internData = window.rowData.get(id);
            const statusUrl  = internData?.status_update_url || `${ADMIN_INTERNS_BASE}/${id}/status`;
            const btnAktif = document.createElement('button');
            btnAktif.type = 'button';
            btnAktif.title = 'Aktifkan sebagai Pemagang';
            btnAktif.className = 'flex items-center gap-1.5 rounded-[8px] border border-blue-400 bg-blue-50 px-2.5 py-1.5 text-[11px] font-semibold text-blue-700 transition hover:bg-blue-600 hover:text-white js-activate';
            btnAktif.dataset.id  = id;
            btnAktif.dataset.url = statusUrl;
            btnAktif.innerHTML = `<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Aktifkan`;
            btnAktif.addEventListener('click', async () => {
                const nm = (window.rowData.get(id)?.fullname) || 'pemagang ini';
                if (!confirm(`Aktifkan "${nm}" sebagai pemagang aktif?\nStatus berubah ke Aktif dan data pindah ke Data Pemagang.`)) return;
                btnAktif.disabled = true;
                btnAktif.textContent = 'Memproses...';
                try {
                    const res = await fetch(statusUrl, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: JSON.stringify({ internship_status: 'active' }),
                    });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    pushToast(`${nm} berhasil diaktifkan!`, 'success');
                    const row = btnAktif.closest('tr[data-row-id]');
                    if (row) { row.style.transition = 'opacity 0.4s'; row.style.opacity = '0'; setTimeout(() => row.remove(), 400); }
                } catch(err) {
                    pushToast(`Gagal: ${err.message}`, 'error');
                    btnAktif.disabled = false;
                    btnAktif.textContent = 'Aktifkan';
                }
            });

            // Tombol LOA
            const btnLoa = document.createElement('button');
            btnLoa.type = 'button';
            btnLoa.title = 'Generate LOA';
            btnLoa.className = 'flex items-center gap-1.5 rounded-[8px] border border-[#2D8659] bg-[#E8F5E9] px-2.5 py-1.5 text-[11px] font-semibold text-[#1F5F3F] transition hover:bg-[#2D8659] hover:text-white js-gen-doc';
            btnLoa.dataset.id    = id;
            btnLoa.dataset.jenis = 'loa';
            btnLoa.innerHTML = `<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg> LOA`;
            btnLoa.addEventListener('click', () => openGenerateModal(id, 'loa'));

            // Urutan: LOA di depan, lalu Aktifkan, lalu tombol bawaan (Detail, Edit, Hapus)
            wrapper.prepend(btnAktif);
            wrapper.prepend(btnLoa);
        }

        if (MODE === 'pemagang' && newStatus === 'completed') {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.title = 'Generate Dokumen Selesai';
            btn.className = 'flex items-center gap-1.5 rounded-[8px] border border-[#2D8659] bg-[#E8F5E9] px-2.5 py-1.5 text-[11px] font-semibold text-[#1F5F3F] transition hover:bg-[#2D8659] hover:text-white js-gen-doc';
            btn.dataset.id    = id;
            btn.dataset.jenis = 'skl';
            btn.innerHTML = `<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg> Dokumen`;
            wrapper.prepend(btn);
            btn.addEventListener('click', () => openGenerateModal(id, 'skl'));
        }
    }

    // ── Bind aksi baris (detail / edit / hapus) ──────────────────────────────────
    function bindRowActions() {
        // Detail
        document.querySelectorAll('.js-detail').forEach(btn => {
            btn.addEventListener('click', () => {
                const id   = Number(btn.dataset.id);
                const data = window.rowData.get(id);
                if (!data) return;
                openDetailModal(data);
            });
        });

        // Edit
        document.querySelectorAll('.js-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const id   = Number(btn.dataset.id);
                const data = window.rowData.get(id);
                if (!data) return;
                openEditModal(data);
            });
        });

        // Hapus
        document.querySelectorAll('.js-delete').forEach(btn => {
            btn.addEventListener('click', () => {
                const id   = Number(btn.dataset.id);
                const data = window.rowData.get(id);
                openConfirmDelete(id, data?.fullname || 'pemagang ini');
            });
        });

        // Pulihkan (Restore)
        document.querySelectorAll('.js-restore').forEach(btn => {
            btn.addEventListener('click', () => {
                const id   = Number(btn.dataset.id);
                const data = window.rowData.get(id);
                openConfirmRestore(id, data?.fullname || 'pemagang ini');
            });
        });

        // Generate dokumen
        document.querySelectorAll('.js-gen-doc').forEach(btn => {
            btn.addEventListener('click', () => {
                openGenerateModal(Number(btn.dataset.id), btn.dataset.jenis);
            });
        });

        // Aktifkan pemagang — set status accepted → active langsung
        document.querySelectorAll('.js-activate').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id   = Number(btn.dataset.id);
                const url  = btn.dataset.url;
                const data = window.rowData.get(id);
                const name = data?.fullname || 'pemagang ini';

                if (!confirm(`Aktifkan "${name}" sebagai pemagang aktif?\nStatus akan berubah dari Diterima → Aktif dan data akan pindah ke halaman Data Pemagang.`)) return;

                btn.disabled = true;
                btn.textContent = 'Memproses...';

                try {
                    const res = await fetch(url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ internship_status: 'active' }),
                    });

                    if (!res.ok) throw new Error(`HTTP ${res.status}`);

                    pushToast(`${name} berhasil diaktifkan sebagai pemagang aktif!`, 'success');

                    // Hilangkan baris dari tampilan (karena sudah pindah ke Data Pemagang)
                    const row = btn.closest('tr[data-row-id]');
                    if (row) {
                        row.style.transition = 'opacity 0.4s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 400);
                    }
                } catch (err) {
                    pushToast(`Gagal mengaktifkan: ${err.message}`, 'error');
                    btn.disabled = false;
                    btn.textContent = 'Aktifkan';
                }
            });
        });

        // Edit Follow-Up Status (S17.18C)
        document.querySelectorAll('.js-edit-followup').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = Number(btn.dataset.id);
                const data = window.rowData.get(id);
                if (!data) return;
                openFollowupModal(data);
            });
        });

    }  // end bindRowActions

    // ── Follow-Up Modal Controller (S17.18C) ────────────────────────────────────
    const followupModal = document.getElementById('followupModal');
    const followupStatusSelect = document.getElementById('followupStatusSelect');
    const followupReasonInput = document.getElementById('followupReasonInput');
    const followupModalAlert = document.getElementById('followupModalAlert');
    const followupModalCancel = document.getElementById('followupModalCancel');
    const followupModalConfirm = document.getElementById('followupModalConfirm');
    const followupModalOverlay = document.getElementById('followupModalOverlay');
    let _followupPendingData = null;

    function openFollowupModal(data) {
        _followupPendingData = data;
        const sub = document.getElementById('followupModalSubtitle');
        if (sub) sub.textContent = `Pendaftar: ${data.fullname || 'Tanpa nama'}`;
        if (followupStatusSelect) followupStatusSelect.value = data.followup_status || '';
        if (followupReasonInput) followupReasonInput.value = '';
        if (followupModalAlert) {
            followupModalAlert.classList.add('hidden');
            followupModalAlert.textContent = '';
        }
        if (followupModal) followupModal.classList.remove('hidden');
    }

    function closeFollowupModal() {
        if (followupModal) followupModal.classList.add('hidden');
        _followupPendingData = null;
    }

    if (followupModalCancel) followupModalCancel.addEventListener('click', closeFollowupModal);
    if (followupModalOverlay) followupModalOverlay.addEventListener('click', closeFollowupModal);

    if (followupModalConfirm) {
        followupModalConfirm.addEventListener('click', async () => {
            if (!_followupPendingData) return;
            const statusVal = followupStatusSelect.value;
            const reasonVal = followupReasonInput.value.trim();

            if (!statusVal) {
                if (followupModalAlert) {
                    followupModalAlert.textContent = 'Silakan pilih status follow-up.';
                    followupModalAlert.classList.remove('hidden');
                }
                return;
            }

            followupModalConfirm.disabled = true;
            if (followupModalAlert) followupModalAlert.classList.add('hidden');

            try {
                const url = _followupPendingData.followup_update_url || `${ADMIN_INTERNS_BASE}/${_followupPendingData.id}/followup-status`;
                const res = await fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        followup_status: statusVal,
                        reason: reasonVal || null,
                    }),
                });

                const json = await res.json();
                if (!res.ok) {
                    throw new Error(json.message || `Gagal menyimpan (HTTP ${res.status})`);
                }

                pushToast('Status follow-up berhasil diperbarui!', 'success');
                closeFollowupModal();
                loadPage(window.__CURRENT_PAGE || 1);
            } catch (err) {
                if (followupModalAlert) {
                    followupModalAlert.textContent = err.message || 'Terjadi kesalahan saat menyimpan.';
                    followupModalAlert.classList.remove('hidden');
                }
            } finally {
                followupModalConfirm.disabled = false;
            }
        });
    }

    // ── ============================================================
    //    MODAL GENERATE DOKUMEN — reusable untuk 4 jenis surat
    // ── ============================================================

    const GEN_CONFIG = {
        loa: {
            title:    'Generate LOA',
            subtitle: 'Letter of Acceptance — surat penerimaan magang',
            info:     'Anda akan diarahkan ke form LOA. Data pemagang sudah terisi otomatis, Anda bisa review sebelum generate.',
            url:      (id) => `{{ url('admin/loa/generate') }}/${id}`,
            method:   'REDIRECT',
        },
        skl: {
            title:    'Generate SKL',
            subtitle: 'Surat Keterangan Selesai Magang',
            info:     'Anda akan diarahkan ke form SKL. Data pemagang sudah terisi otomatis, Anda bisa review dan ubah sebelum generate PDF.',
            url:      (id) => `{{ url('admin/skl/generate') }}/${id}`,
            method:   'REDIRECT',
        },
        sertifikat: {
            title:    'Generate Sertifikat',
            subtitle: 'Sertifikat Magang',
            info:     'Anda akan diarahkan ke halaman buat sertifikat dengan data pemagang sudah terisi otomatis.',
            url:      (id) => `{{ route('admin.certificate.create') }}?intern_id=${id}`,
            method:   'REDIRECT',
        },
        penilaian: {
            title:    'Buat Surat Penilaian',
            subtitle: 'Surat Penilaian Magang',
            info:     'Anda akan diarahkan ke halaman buat penilaian dengan data pemagang sudah terisi otomatis.',
            url:      (id) => `{{ route('interns.assessment.create') }}?intern_id=${id}`,
            method:   'REDIRECT',
        },
        alumni: {
            title:    'Informasi Alumni',
            subtitle: 'Akses Eksklusif & Grup Alumni',
            info:     'Anda akan diarahkan ke halaman Informasi Alumni untuk mengatur link grup alumni, surat rekomendasi, dan info kerja pemagang yang sudah selesai.',
            url:      (id) => `{{ url('admin/intern-extras') }}/${id}/edit`,
            method:   'REDIRECT',
        },
    };

    // Opsi yang tersedia per mode
    const GEN_OPTIONS_BY_MODE = {
        pendaftar: ['loa'],
        pemagang:  ['loa', 'skl', 'sertifikat', 'penilaian', 'alumni'],
        all:       ['loa', 'skl', 'sertifikat', 'penilaian', 'alumni'],
    };

    let _genInternId  = null;
    let _genJenis     = null;

    function openGenerateModal(internId, defaultJenis = null) {
        _genInternId = internId;
        _genJenis    = defaultJenis;

        const data = window.rowData.get(internId);
        if (!data) { pushToast('Data pemagang tidak ditemukan.', 'error'); return; }

        // Isi preview pemagang
        const ini = initials(data.fullname);
        const avatarImg = document.getElementById('genInternAvatarImg');
        const avatarFallback = document.getElementById('genInternAvatar');

        if (data.profile_photo || (data.user && data.user.profile_picture)) {
            const photoPath = data.profile_photo || data.user.profile_picture;
            avatarImg.src = '/storage/' + photoPath;
            avatarImg.classList.remove('hidden');
            avatarFallback.classList.add('hidden');
        } else {
            avatarFallback.textContent = ini;
            avatarFallback.classList.remove('hidden');
            avatarImg.classList.add('hidden');
        }
        document.getElementById('genInternName').textContent   = data.fullname || '-';
        document.getElementById('genInternMeta').textContent   = data.email || '-';
        document.getElementById('genInternInstitution').textContent = data.institution_name || '-';
        document.getElementById('genInternDivision').textContent    = data.internship_interest || '-';
        document.getElementById('genInternStart').textContent  = data.start_date || '-';
        document.getElementById('genInternEnd').textContent    = data.end_date || '-';

        // Badge status
        const status = String(data.internship_status || 'waiting').toLowerCase();
        const sm = STATUS_META[status] || { label: status, cls: 'bg-gray-100 text-gray-700' };
        const badge = document.getElementById('genInternBadge');
        badge.textContent  = sm.label;
        badge.className    = `ml-auto inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ${sm.cls}`;

        // Render opsi jenis surat
        const optContainer = document.getElementById('genDocOptions');
        const modeKey = MODE in GEN_OPTIONS_BY_MODE ? MODE : 'all';
        // Opsi 'alumni' hanya tersedia jika status pemagang sudah 'completed'
        const allowed = GEN_OPTIONS_BY_MODE[modeKey].filter(jenis =>
            jenis !== 'alumni' || status === 'completed'
        );

        optContainer.innerHTML = allowed.map(jenis => {
            const cfg     = GEN_CONFIG[jenis];
            const isActive = jenis === (defaultJenis || allowed[0]);
            // Ikon berbeda untuk alumni
            const icon = jenis === 'alumni'
                ? `<svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`
                : `<svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>`;
            return `
            <button type="button" data-jenis="${jenis}"
                class="gen-opt-btn flex items-center gap-2 rounded-[9px] border px-3 py-2.5 text-left text-[12.5px] font-semibold transition
                    ${isActive
                        ? 'border-[#2D8659] bg-[#E8F5E9] text-[#1F5F3F]'
                        : 'border-[#DCE7E1] bg-white text-[#4B5F5A] hover:border-[#2D8659] hover:text-[#1F5F3F]'}">
                ${icon}
                ${cfg.title}
            </button>`;
        }).join('');

        // Bind pilih opsi
        optContainer.querySelectorAll('.gen-opt-btn').forEach(b => {
            b.addEventListener('click', () => {
                _genJenis = b.dataset.jenis;
                optContainer.querySelectorAll('.gen-opt-btn').forEach(x => {
                    x.classList.toggle('border-[#2D8659]',  x === b);
                    x.classList.toggle('bg-[#E8F5E9]',      x === b);
                    x.classList.toggle('text-[#1F5F3F]',    x === b);
                    x.classList.toggle('border-[#DCE7E1]',  x !== b);
                    x.classList.toggle('bg-white',           x !== b);
                    x.classList.toggle('text-[#4B5F5A]',    x !== b);
                });
                updateGenModalInfo();
            });
        });

        if (!_genJenis && allowed.length) _genJenis = allowed[0];
        updateGenModalInfo();

        document.getElementById('generateModal').classList.remove('hidden');
    }

    function updateGenModalInfo() {
        if (!_genJenis) return;
        const cfg = GEN_CONFIG[_genJenis];
        if (!cfg) return;

        document.getElementById('genModalTitle').textContent    = cfg.title;
        document.getElementById('genModalSubtitle').textContent = cfg.subtitle;

        const infoEl = document.getElementById('genDocInfo');
        infoEl.textContent = cfg.info;
        infoEl.classList.remove('hidden');

        const btn = document.getElementById('genModalBtn');
        btn.disabled = false;

        if (cfg.method === 'REDIRECT') {
            btn.innerHTML = `<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Buka Halaman`;
        } else {
            btn.innerHTML = `<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Generate PDF`;
        }
    }

    function closeGenerateModal() {
        document.getElementById('generateModal').classList.add('hidden');
        _genInternId = null;
        _genJenis    = null;
    }
    // Expose ke global scope agar bisa dipanggil dari onclick di HTML
    window.closeGenerateModal = closeGenerateModal;

    document.getElementById('genModalBtn').addEventListener('click', async () => {
        if (!_genInternId || !_genJenis) return;
        const cfg  = GEN_CONFIG[_genJenis];
        const data = window.rowData.get(_genInternId);
        const name = data?.fullname || 'pemagang';

        if (cfg.method === 'REDIRECT') {
            const url = cfg.url(_genInternId);
            if (!url) {
                pushToast('Tidak dapat menentukan URL — pastikan data pemagang terhubung ke akun user.', 'error');
                return;
            }
            closeGenerateModal();
            window.location.href = url;
            return;
        }

        if (cfg.method === 'GET') {
            closeGenerateModal();
            window.open(cfg.url(_genInternId), '_blank');
            return;
        }

        // POST
        const btn = document.getElementById('genModalBtn');
        btn.disabled = true;
        btn.textContent = 'Memproses...';

        try {
            const body = cfg.body ? cfg.body(_genInternId) : { intern_id: _genInternId };
            const fd   = new FormData();
            fd.append('_token', csrf);
            Object.entries(body).forEach(([k, v]) => fd.append(k, v));

            const res = await fetch(cfg.url(_genInternId), {
                method: 'POST',
                body: fd,
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            closeGenerateModal();
            pushToast(`${cfg.title} untuk ${name} berhasil di-generate.`);
            loadPage(window.__CURRENT_PAGE || 1);
        } catch (e) {
            pushToast(`Gagal generate: ${e.message}`, 'error');
            updateGenModalInfo(); // restore button
        }
    });

    // ── Modal helpers ─────────────────────────────────────────────────────────────
    const appModal    = document.getElementById('appModal');
    const confirmModal = document.getElementById('confirmModal');

    function openModal(modalEl)  { modalEl.classList.remove('hidden'); }
    function closeModal(modalEl) { modalEl.classList.add('hidden'); }

    appModal.querySelectorAll('[data-modal-close]').forEach(el =>
        el.addEventListener('click', () => closeModal(appModal))
    );
    confirmModal.querySelectorAll('[data-confirm-close]').forEach(el =>
        el.addEventListener('click', () => closeModal(confirmModal))
    );

    // ── Modal: Detail ────────────────────────────────────────────────────────────

    // ── Helper: Escape HTML & Format Nilai Detail ────────────────────────────────
    const escapeHtml = (str) => {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const SECTION_ORDER = [
        'Data Pribadi',
        'Data Akademik',
        'Informasi Magang',
        'Keahlian & Alat',
        'Berkas & Dokumen',
        'Informasi Tambahan',
    ];

    const SYSTEM_SECTION_MAP = {
        fullname:            'Data Pribadi',
        birth_place:         'Data Pribadi',
        born_date:           'Data Pribadi',
        religion:            'Data Pribadi',
        student_id:          'Data Pribadi',
        gender:              'Data Pribadi',
        email:               'Data Pribadi',
        phone_number:        'Data Pribadi',
        current_city:        'Data Pribadi',

        institution_name:    'Data Akademik',
        study_program:       'Data Akademik',
        faculty:             'Data Akademik',

        internship_type:          'Informasi Magang',
        internship_arrangement:   'Informasi Magang',
        internship_interest:      'Informasi Magang',
        internship_reason:        'Informasi Magang',
        current_status:           'Informasi Magang',
        english_book_ability:     'Informasi Magang',
        start_date:               'Informasi Magang',
        end_date:                 'Informasi Magang',
        planned_duration:         'Informasi Magang',
        wfo_readiness:            'Informasi Magang',
        schedule_shift_readiness: 'Informasi Magang',

        has_laptop:            'Keahlian & Alat',
        owned_tools:           'Keahlian & Alat',
        design_software:       'Keahlian & Alat',
        video_software:        'Keahlian & Alat',
        programming_languages: 'Keahlian & Alat',
        digital_marketing_type:'Keahlian & Alat',

        cv:                  'Berkas & Dokumen',
        identity:            'Berkas & Dokumen',
        portfolio:           'Berkas & Dokumen',
        cover_letter:        'Berkas & Dokumen',
        proposal:            'Berkas & Dokumen',
        supporting_document: 'Berkas & Dokumen',
        profile_photo:       'Berkas & Dokumen',

        supervisor_name:         'Informasi Tambahan',
        supervisor_contact:      'Informasi Tambahan',
        boarding_info:           'Informasi Tambahan',
        social_media_instagram:  'Informasi Tambahan',
        internship_info_sources: 'Informasi Tambahan',
        current_activities:      'Informasi Tambahan',
    };

    function getFieldSection(f) {
        if (f.group_name && f.group_name.trim() !== '' && f.group_name !== 'main') {
            if (f.group_name === 'informasi_tambahan') return 'Informasi Tambahan';
            if (f.group_name === 'berkas' || f.group_name === 'dokumen' || f.group_name === 'berkas_dokumen') return 'Berkas & Dokumen';
            if (f.group_name === 'data_pribadi') return 'Data Pribadi';
            if (f.group_name === 'data_akademik') return 'Data Akademik';
            if (f.group_name === 'informasi_magang') return 'Informasi Magang';
            if (f.group_name === 'keahlian_alat') return 'Keahlian & Alat';
            return f.group_name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        if (SYSTEM_SECTION_MAP[f.field_key]) {
            return SYSTEM_SECTION_MAP[f.field_key];
        }
        if (f.field_type === 'file') {
            return 'Berkas & Dokumen';
        }
        return 'Informasi Tambahan';
    }

    /**
     * Mapping field_key → nilai dari objek data intern.
     */
    function getFieldValue(it, fieldKey) {
        if (!it) return '-';

        // Cek lampiran berkas pendaftaran jika ada
        if (it.attachments && it.attachments[fieldKey]) {
            return it.attachments[fieldKey];
        }

        let v = it[fieldKey];

        if (fieldKey === 'internship_interest') {
            v = it.division_name || it.internship_interest || v;
        } else if (fieldKey === 'brand') {
            v = it.brand_name || it.brand || v;
        } else if (fieldKey === 'has_laptop') {
            if (v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'ya' || String(v).toLowerCase() === 'true' || String(v).toLowerCase() === 'punya') {
                return 'Ya, Punya Sendiri';
            }
            if (v === false || v === 0 || v === '0' || String(v).toLowerCase() === 'tidak' || String(v).toLowerCase() === 'false') {
                return 'Tidak';
            }
            return '-';
        }

        if (v === undefined || v === null || v === '') {
            return '-';
        }

        if (typeof v === 'object' && v !== null && (v.url || v.name)) {
            return v;
        }

        if (Array.isArray(v)) {
            return v.join(', ');
        }

        return v;
    }

    function renderDetailValue(val) {
        if (val === null || val === undefined || val === '' || val === '-') {
            return '<span class="text-gray-400 font-normal">-</span>';
        }
        if (typeof val === 'object' && val !== null && val.url) {
            return `<a href="${val.url}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#2D8659] hover:text-[#1F5F3F] bg-[#E8F5E9] hover:bg-[#d4edd9] px-2.5 py-1 rounded-[6px] transition max-w-full">
                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span class="truncate">${escapeHtml(val.name || 'Lihat Berkas')}</span>
                <svg class="h-3 w-3 shrink-0 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>`;
        }
        if (typeof val === 'string' && (val.startsWith('http://') || val.startsWith('https://'))) {
            return `<a href="${val}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#2D8659] hover:underline bg-[#E8F5E9] px-2.5 py-1 rounded-[6px] transition">
                <span>Buka Tautan</span>
                <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>`;
        }
        return escapeHtml(String(val));
    }

    function detailRow(label, val) {
        return `
            <div class="py-1.5">
                <p class="text-[11px] font-semibold text-[#4B5F5A]">${escapeHtml(label)}</p>
                <div class="text-[13px] font-medium text-[#1B3A34] mt-0.5">${renderDetailValue(val)}</div>
            </div>`;
    }

    function openDetailModal(it) {
        document.getElementById('appModalTitle').textContent = 'Detail Pemagang';
        const ini    = initials(it.fullname);
        const status = String(it.internship_status || 'waiting').toLowerCase();
        const m      = STATUS_META[status] || { label: status, cls: 'bg-gray-100 text-gray-700' };

        const sectionHtml = (title) =>
            `<p class="col-span-2 mt-2 text-[10.5px] font-bold uppercase tracking-[0.08em] text-[#2D8659] border-b border-[#DCE7E1] pb-1">${title}</p>`;

        // Render field dinamis mengikuti form template (__formFields)
        let fieldsHtml = '';
        if (__formFields.length > 0) {
            // Deduplikasi defensif berdasarkan field_key
            const seenDetailKeys = new Set();
            const uniqueFields = __formFields.filter(f => {
                if (!f.field_key || seenDetailKeys.has(f.field_key)) return false;
                seenDetailKeys.add(f.field_key);
                return true;
            });

            const sectionGroups = {};
            SECTION_ORDER.forEach(s => sectionGroups[s] = []);

            uniqueFields.forEach(f => {
                const sName = getFieldSection(f);
                if (!sectionGroups[sName]) sectionGroups[sName] = [];
                sectionGroups[sName].push(f);
            });

            SECTION_ORDER.forEach(sName => {
                const fields = sectionGroups[sName] || [];
                if (fields.length > 0) {
                    fieldsHtml += sectionHtml(sName);
                    fields.forEach(f => {
                        const val = getFieldValue(it, f.field_key);
                        const spanClass = (f.column_span === 1 || f.field_type === 'textarea' || f.field_type === 'file') ? 'col-span-2' : '';
                        fieldsHtml += `<div class="${spanClass}">` + detailRow(f.label, val) + '</div>';
                    });
                    if (sName === 'Informasi Magang' && (it.brand || it.brand_name)) {
                        fieldsHtml += detailRow('Brand', it.brand_name || it.brand);
                    }
                }
            });

            // Seksi kustom lainnya yang tidak ada di SECTION_ORDER
            Object.keys(sectionGroups).forEach(sName => {
                if (SECTION_ORDER.includes(sName)) return;
                const fields = sectionGroups[sName] || [];
                if (fields.length > 0) {
                    fieldsHtml += sectionHtml(sName);
                    fields.forEach(f => {
                        const val = getFieldValue(it, f.field_key);
                        const spanClass = (f.column_span === 1 || f.field_type === 'textarea' || f.field_type === 'file') ? 'col-span-2' : '';
                        fieldsHtml += `<div class="${spanClass}">` + detailRow(f.label, val) + '</div>';
                    });
                }
            });

            // Tgl daftar selalu di akhir
            fieldsHtml += `<div class="col-span-2">` + detailRow('Tgl Daftar', fmtDate(it.created_at)) + `</div>`;

        } else {
            // Fallback: hardcoded field list (bila form_fields belum ter-load)
            const hasBirthPlace = {{ \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'birth_place') ? 'true' : 'false' }};
            const hasReligion = {{ \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'religion') ? 'true' : 'false' }};
            fieldsHtml = `
                ${sectionHtml('Data Pribadi')}
                ${detailRow('Nama Lengkap', it.fullname)}
                ${hasBirthPlace ? detailRow('Tempat Lahir', it.birth_place) : ''}
                ${detailRow('Tahun Lahir', it.born_date)}
                ${detailRow('NIM / NIS', it.student_id)}
                ${detailRow('Jenis Kelamin', it.gender)}
                ${hasReligion ? detailRow('Agama', it.religion) : ''}
                ${detailRow('Email', it.email)}
                ${detailRow('No. HP / WA', it.phone_number)}
                ${detailRow('Kota Tinggal', it.current_city)}

                ${sectionHtml('Data Akademik')}
                ${detailRow('Asal Sekolah / Kampus', it.institution_name)}
                ${detailRow('Program Studi', it.study_program)}
                ${detailRow('Fakultas', it.faculty)}

                ${sectionHtml('Informasi Magang')}
                ${detailRow('Jenis Magang', it.internship_type)}
                ${detailRow('Sistem Kerja', it.internship_arrangement)}
                ${detailRow('Minat Program', it.internship_interest)}
                ${detailRow('Brand', it.brand || '-')}
                ${detailRow('Alasan Magang', it.internship_reason)}
                ${detailRow('Status Saat Ini', it.current_status)}
                ${detailRow('Bisa Bahasa Inggris', it.english_book_ability)}
                ${detailRow('Nama Pembimbing', it.supervisor_name)}
                ${detailRow('No. WA Pembimbing', it.supervisor_contact)}
                ${detailRow('Tgl Mulai', it.start_date)}
                ${detailRow('Tgl Selesai', it.end_date)}

                ${sectionHtml('Keahlian & Alat')}
                ${detailRow('Software Desain', it.design_software)}
                ${detailRow('Software Video', it.video_software)}
                ${detailRow('Bahasa Pemrograman', it.programming_languages)}
                ${detailRow('Materi Digital Marketing', it.digital_marketing_type)}
                ${detailRow('Memiliki Laptop', (it.has_laptop === true || it.has_laptop === 1 || it.has_laptop === '1' || String(it.has_laptop).toLowerCase() === 'ya') ? 'Ya, Punya Sendiri' : ((it.has_laptop === false || it.has_laptop === 0 || it.has_laptop === '0' || String(it.has_laptop).toLowerCase() === 'tidak') ? 'Tidak' : '-'))}
                ${detailRow('Alat yang Dimiliki', it.owned_tools)}

                ${sectionHtml('Informasi Tambahan')}
                ${detailRow('Kegiatan Lain', it.current_activities)}
                ${detailRow('Butuh Info Kost', it.boarding_info)}

                ${detailRow('Instagram', it.social_media_instagram)}
                ${detailRow('Info Magang Dari', it.internship_info_sources)}
                ${detailRow('Tgl Daftar', fmtDate(it.created_at))}
            `;
        }

        document.getElementById('appModalBody').innerHTML = `
        <div class="space-y-4">
            <div class="flex items-center gap-4 rounded-[10px] bg-[#F4F8F6] px-4 py-3">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#E8F5E9] text-lg font-bold text-[#1F5F3F]">
                    ${ini}
                </div>
                <div class="flex-1 min-w-0">
                    <h4 class="text-[15px] font-bold text-[#1B3A34] truncate">${fmtStr(it.fullname)}</h4>
                    <p class="text-[12px] text-[#4B5F5A]">${fmtStr(it.institution_name || '-')}</p>
                </div>
                <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ${m.cls}">${m.label}</span>
            </div>

            <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                ${fieldsHtml}
            </div>

            ${(it.cv_ktp_portofolio_pdf || it.portofolio_visual) ? `
            <div class="border-t border-[#DCE7E1] pt-4">
                <p class="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-[#4B5F5A]">Berkas Unggahan</p>
                <div class="flex flex-wrap gap-2">
                    ${it.cv_ktp_portofolio_pdf ? `
                    <a href="{{ asset('storage') }}/${it.cv_ktp_portofolio_pdf}" target="_blank"
                        class="inline-flex items-center gap-2 rounded-[8px] border border-[#DCE7E1] px-3 py-2 text-[13px] font-semibold text-[#2D8659] hover:bg-[#F4F8F6]">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                        CV / KTP / Portofolio (PDF)
                    </a>` : ''}
                    ${it.portofolio_visual ? `
                    <a href="{{ asset('storage') }}/${it.portofolio_visual}" target="_blank"
                        class="inline-flex items-center gap-2 rounded-[8px] border border-[#DCE7E1] px-3 py-2 text-[13px] font-semibold text-[#2D8659] hover:bg-[#F4F8F6]">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 9 3 3-3 3"/><path d="m15 15-3-3 3-3"/></svg>
                        Portofolio Visual
                    </a>` : ''}
                </div>
            </div>` : ''}
        </div>`;
        openModal(appModal);
    }

    function detailRow(label, val) {
        return `<div>
            <p class="text-[10.5px] font-semibold uppercase tracking-wide text-[#4B5F5A] mb-0.5">${label}</p>
            <p class="text-[13px] font-medium text-[#1B3A34] break-words">${fmtStr(val)}</p>
        </div>`;
    }

    // ── Modal: Edit ──────────────────────────────────────────────────────────────
    function openEditModal(it) {
        document.getElementById('appModalTitle').textContent = 'Edit Data Pemagang';

        const statusOpts = Object.entries(STATUS_META).map(([val, m]) =>
            `<option value="${val}" ${val === it.internship_status ? 'selected' : ''}>${m.label}</option>`
        ).join('');

        const section = (title) =>
            `<div class="col-span-2 mt-1 border-b border-[#DCE7E1] pb-1">
                <p class="text-[10.5px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">${title}</p>
            </div>`;

        const editField = (label, name, val, type = 'text') => {
            const v = (fmtStr(val) === '-') ? '' : fmtStr(val);
            return `<div>
                <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${label}</label>
                <input type="${type}" name="${name}" value="${v}"
                    class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
            </div>`;
        };

        // Helper: render satu edit field berdasarkan definisi FormField
        function renderEditField(f, it) {
            const rawVal  = it[f.field_key] !== undefined ? it[f.field_key] : '';
            const v       = (fmtStr(rawVal) === '-') ? '' : fmtStr(rawVal);
            const spanClass = (f.column_span === 1) ? 'sm:col-span-2' : '';
            const reqMark = f.is_required ? '<span class="text-red-500 ml-0.5">*</span>' : '';
            const inputClass = 'w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition';

            // Jangan render input file di modal edit teks profil pemagang
            if (f.field_type === 'file') {
                return '';
            }

            // Penanganan khusus untuk Divisi / Minat Magang
            if (f.field_key === 'internship_interest') {
                const divisions = @json($divisions ?? []);
                const curDivId = it.division_id ? String(it.division_id) : '';
                const curDivName = (it.division_name || it.internship_interest || '').toLowerCase().trim();
                const opts = divisions.map(d => {
                    const isSel = (curDivId && String(d.id) === curDivId) ||
                                  (curDivName && (d.name.toLowerCase() === curDivName || (d.slug && d.slug.toLowerCase() === curDivName)));
                    return `<option value="${d.name}" ${isSel ? 'selected' : ''}>${d.name}</option>`;
                }).join('');
                return `<div class="${spanClass}">
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${f.label}${reqMark}</label>
                    <select name="${f.field_key}" class="${inputClass}">
                        <option value="">-- Pilih Divisi --</option>${opts}
                    </select>
                </div>`;
            }

            switch (f.field_type) {
                case 'select': {
                    let hasMatch = false;
                    const isSelectMatch = (rVal, oVal, oLbl) => {
                        if (rVal === undefined || rVal === null || rVal === '') return false;
                        const sR = String(rVal).toLowerCase().trim();
                        const sO = String(oVal).toLowerCase().trim();
                        const sL = String(oLbl || '').toLowerCase().trim();
                        if (sR === sO || sR === sL) return true;

                        // Fuzzy match known legacy values
                        if (sR.includes('sanggup') && !sR.includes('tidak') && (sO === 'sanggup' || sL === 'sanggup')) return true;
                        if (sR.includes('tidak sanggup') && (sO.includes('tidak') || sL.includes('tidak'))) return true;

                        if ((sR.startsWith('ya') || sR.includes('kost') || sR.includes('rekomendasi')) && (sO === 'ya' || sL === 'ya')) return true;
                        if (sR.startsWith('tidak') && (sO === 'tidak' || sL === 'tidak')) return true;

                        if (sR.includes('mandiri') && (sO.includes('inisiatif') || sO.includes('mandiri'))) return true;
                        if ((sR.includes('kampus') || sR.includes('reguler')) && (sO.includes('reguler') || sO.includes('mitra'))) return true;

                        return false;
                    };

                    const opts = (f.options || []).map(o => {
                        const optVal = (typeof o === 'object') ? (o.value ?? o) : o;
                        const optLbl = (typeof o === 'object') ? (o.label ?? o.value ?? o) : o;
                        const sel = (!hasMatch && isSelectMatch(rawVal, optVal, optLbl)) ? 'selected' : '';
                        if (sel) hasMatch = true;
                        return `<option value="${optVal}" ${sel}>${optLbl}</option>`;
                    }).join('');

                    // Safety preservation: Jika nilai lama ada tapi tidak cocok dengan opsi mana pun, buat opsi pilihan otomatis agar data tidak terhapus
                    let extraOpt = '';
                    if (rawVal !== undefined && rawVal !== null && String(rawVal).trim() !== '' && fmtStr(rawVal) !== '-' && !hasMatch) {
                        extraOpt = `<option value="${rawVal}" selected>${rawVal} (Data Tersimpan)</option>`;
                    }

                    return `<div class="${spanClass}">
                        <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${f.label}${reqMark}</label>
                        <select name="${f.field_key}" class="${inputClass}">
                            <option value="">-- Pilih --</option>
                            ${extraOpt}
                            ${opts}
                        </select>
                    </div>`;
                }
                case 'radio': {
                    const isChecked = (rVal, oVal, oLbl) => {
                        if (rVal === undefined || rVal === null || rVal === '') return false;
                        const sR = String(rVal).toLowerCase().trim();
                        const sO = String(oVal).toLowerCase().trim();
                        const sL = String(oLbl || '').toLowerCase().trim();
                        if (sR === sO || sR === sL) return true;

                        const truthy = ['1', 'true', 'ya', 'yes', 'y', 'punya'];
                        const falsy  = ['0', 'false', 'tidak', 'no', 'n'];

                        const rTruthy = truthy.includes(sR) || sR.startsWith('ya') || sR.includes('punya');
                        const rFalsy  = falsy.includes(sR) || sR.startsWith('tidak');

                        const oTruthy = truthy.includes(sO) || sL.startsWith('ya') || sL.includes('punya');
                        const oFalsy  = falsy.includes(sO) || sL.startsWith('tidak');

                        if (rTruthy && oTruthy) return true;
                        if (rFalsy && oFalsy) return true;

                        return false;
                    };

                    const opts = (f.options || []).map(o => {
                        const optVal = (typeof o === 'object') ? (o.value ?? o) : o;
                        const optLbl = (typeof o === 'object') ? (o.label ?? o.value ?? o) : o;
                        const chk = isChecked(rawVal, optVal, optLbl) ? 'checked' : '';
                        return `<label class="inline-flex items-center gap-1.5 text-[12.5px] cursor-pointer text-[#1B3A34]">
                            <input type="radio" name="${f.field_key}" value="${optVal}" ${chk} class="accent-[#2D8659]"> ${optLbl}
                        </label>`;
                    }).join('');
                    return `<div class="${spanClass}">
                        <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${f.label}${reqMark}</label>
                        <div class="flex flex-wrap gap-3 mt-1.5">${opts}</div>
                    </div>`;
                }
                case 'checkbox': {
                    const curVals = Array.isArray(rawVal)
                        ? rawVal.map(x => String(x).toLowerCase().trim())
                        : String(rawVal || '').split(',').map(s => s.trim().toLowerCase());
                    const opts = (f.options || []).map(o => {
                        const optVal = (typeof o === 'object') ? (o.value ?? o) : o;
                        const optLbl = (typeof o === 'object') ? (o.label ?? o.value ?? o) : o;
                        const sOpt = String(optVal).toLowerCase().trim();
                        const sLbl = String(optLbl).toLowerCase().trim();
                        const chk = (curVals.includes(sOpt) || curVals.includes(sLbl)) ? 'checked' : '';
                        return `<label class="inline-flex items-center gap-1.5 text-[12.5px] cursor-pointer text-[#1B3A34]">
                            <input type="checkbox" name="${f.field_key}[]" value="${optVal}" ${chk} class="rounded border-[#DCE7E1] text-[#2D8659] focus:ring-0"> ${optLbl}
                        </label>`;
                    }).join('');
                    return `<div class="${spanClass}">
                        <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${f.label}${reqMark}</label>
                        <div class="flex flex-wrap gap-3 mt-1.5">${opts}</div>
                    </div>`;
                }
                case 'textarea':
                    return `<div class="${spanClass}">
                        <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${f.label}${reqMark}</label>
                        <textarea name="${f.field_key}" rows="2" class="${inputClass} resize-none">${v}</textarea>
                    </div>`;
                case 'date': {
                    const rawDateVal = it[f.field_key + '_raw'] !== undefined ? it[f.field_key + '_raw'] : rawVal;
                    const dateV = (fmtStr(rawDateVal) === '-') ? '' : fmtStr(rawDateVal);
                    return `<div class="${spanClass}">
                        <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${f.label}${reqMark}</label>
                        <input type="date" name="${f.field_key}" value="${dateV}" class="${inputClass}">
                    </div>`;
                }
                case 'file': {
                    let fileInfo = '';
                    const attObj = (it.attachments && it.attachments[f.field_key]) ? it.attachments[f.field_key] : null;
                    const fileUrl = attObj?.url || (typeof rawVal === 'string' && (rawVal.startsWith('http') || rawVal.includes('.')) ? rawVal : null);
                    const fileName = attObj?.name || 'Berkas Terlampir';

                    if (fileUrl) {
                        fileInfo = `<div class="mb-1.5 flex items-center gap-2">
                            <a href="${fileUrl}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#2D8659] hover:underline bg-[#E8F5E9] px-2.5 py-1 rounded-[6px]">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <span>File Saat Ini: ${escapeHtml(fileName)}</span>
                            </a>
                            <span class="text-[11px] text-[#4B5F5A]">(Unggah baru untuk mengganti)</span>
                        </div>`;
                    }

                    return `<div class="${spanClass}">
                        <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${escapeHtml(f.label)}${reqMark}</label>
                        ${fileInfo}
                        <input type="file" name="${f.field_key}" class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-[6px] file:border-0 file:text-xs file:font-semibold file:bg-[#E8F5E9] file:text-[#1F5F3F] hover:file:bg-[#d4edd9] border border-[#DCE7E1] rounded-[8px] p-1 bg-[#F4F8F6]">
                        ${f.helper_text ? `<p class="mt-1 text-[11px] text-[#4B5F5A]">${escapeHtml(f.helper_text)}</p>` : ''}
                    </div>`;
                }
                default: {
                    let datalistHtml = '';
                    let listAttr = '';
                    if (f.field_key === 'current_city') {
                        listAttr = 'list="cities-list"';
                        datalistHtml = `<datalist id="cities-list">${(@json($cities ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>`;
                    } else if (f.field_key === 'institution_name') {
                        listAttr = 'list="institutions-list"';
                        datalistHtml = `<datalist id="institutions-list">${(@json($institutions ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>`;
                    } else if (f.field_key === 'study_program') {
                        listAttr = 'list="study-programs-list"';
                        datalistHtml = `<datalist id="study-programs-list">${(@json($studyPrograms ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>`;
                    } else if (f.field_key === 'faculty') {
                        listAttr = 'list="faculties-list"';
                        datalistHtml = `<datalist id="faculties-list">${(@json($faculties ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>`;
                    }
                    return `<div class="${spanClass}">
                        <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${escapeHtml(f.label)}${reqMark}</label>
                        <input type="${f.field_type || 'text'}" ${listAttr} name="${f.field_key}" value="${v}" class="${inputClass}">
                        ${datalistHtml}
                    </div>`;
                }
            }
        }

        // Render semua field dari form_fields atau fallback ke hardcoded
        let dynamicFieldsHtml = '';

        if (__formFields.length > 0) {
            // Deduplikasi defensif berdasarkan field_key
            const seenEditKeys = new Set();
            const uniqueFields = __formFields.filter(f => {
                if (!f.field_key || seenEditKeys.has(f.field_key)) return false;
                seenEditKeys.add(f.field_key);
                return true;
            });

            const sectionGroups = {};
            SECTION_ORDER.forEach(s => sectionGroups[s] = []);

            uniqueFields.forEach(f => {
                const sName = getFieldSection(f);
                if (!sectionGroups[sName]) sectionGroups[sName] = [];
                sectionGroups[sName].push(f);
            });

            SECTION_ORDER.forEach(sName => {
                const fields = sectionGroups[sName] || [];
                if (fields.length > 0) {
                    dynamicFieldsHtml += section(sName);
                    fields.forEach(f => {
                        dynamicFieldsHtml += renderEditField(f, it);
                    });
                }
            });

            // Seksi kustom lainnya yang tidak ada di SECTION_ORDER
            Object.keys(sectionGroups).forEach(sName => {
                if (SECTION_ORDER.includes(sName)) return;
                const fields = sectionGroups[sName] || [];
                if (fields.length > 0) {
                    dynamicFieldsHtml += section(sName);
                    fields.forEach(f => {
                        dynamicFieldsHtml += renderEditField(f, it);
                    });
                }
            });

        } else {
            // Fallback hardcoded
            const genderOpts = [
                ['Laki-laki','Laki-laki'],['Perempuan','Perempuan'],
            ].map(([v,l]) => `<option value="${v}" ${it.gender===v?'selected':''}>${l}</option>`).join('');

            const religionOpts = [
                'Islam','Kristen','Katolik','Hindu','Buddha','Khonghucu'
            ].map(r => `<option value="${r}" ${it.religion===r?'selected':''}>${r}</option>`).join('');

            const typeOpts = [
                ['Magang Mandiri','Magang Mandiri'],['Magang Kampus','Magang Kampus / Reguler'],
                ['PKL','PKL'],['Kampus Merdeka','Kampus Merdeka'],
            ].map(([v,l]) => `<option value="${v}" ${it.internship_type===v?'selected':''}>${l}</option>`).join('');

            const arrangementOpts = [
                ['Onsite','WFO (Work From Office)'],['Hibrida','Hybrid'],['Remote','WFH (Work From Home)'],
            ].map(([v,l]) => `<option value="${v}" ${it.internship_arrangement===v?'selected':''}>${l}</option>`).join('');

            const divisions = @json($divisions ?? []);
            const curDivId = it.division_id ? String(it.division_id) : '';
            const curDivName = (it.division_name || it.internship_interest || '').toLowerCase().trim();
            const interestOpts = divisions.map(d => {
                const isSel = (curDivId && String(d.id) === curDivId) ||
                              (curDivName && (d.name.toLowerCase() === curDivName || (d.slug && d.slug.toLowerCase() === curDivName)));
                return `<option value="${d.name}" ${isSel ? 'selected' : ''}>${d.name}</option>`;
            }).join('');

            const hasBirthPlace = {{ \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'birth_place') ? 'true' : 'false' }};
            const hasReligion = {{ \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'religion') ? 'true' : 'false' }};

            dynamicFieldsHtml = `
                ${section('Data Pribadi')}
                ${editField('Nama Lengkap', 'fullname', it.fullname)}
                ${hasBirthPlace ? editField('Tempat Lahir', 'birth_place', it.birth_place) : ''}
                ${editField('Tahun Lahir (Atau Tanggal)', 'born_date', it.born_date_raw, 'date')}
                ${editField('NIM / NIS', 'student_id', it.student_id)}
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Jenis Kelamin</label>
                    <select name="gender" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        <option value="">-- Pilih --</option>${genderOpts}
                    </select>
                </div>
                ${hasReligion ? `<div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Agama</label>
                    <select name="religion" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        <option value="">-- Pilih Agama (Opsional) --</option>
                        ${['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'].map(rel => `<option value="${rel}" ${it.religion === rel ? 'selected' : ''}>${rel}</option>`).join('')}
                    </select>
                </div>` : ''}
                ${editField('Email', 'email', it.email, 'email')}
                ${editField('No. HP / WA', 'phone_number', it.phone_number)}
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Kota Tinggal</label>
                    <input type="text" list="fb-cities-list" name="current_city" value="${fmtStr(it.current_city)==='-'?'':fmtStr(it.current_city)}" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    <datalist id="fb-cities-list">${(@json($cities ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>
                </div>

                ${section('Data Akademik')}
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Asal Sekolah / Kampus</label>
                    <input type="text" list="fb-institutions-list" name="institution_name" value="${fmtStr(it.institution_name)==='-'?'':fmtStr(it.institution_name)}" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    <datalist id="fb-institutions-list">${(@json($institutions ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Program Studi</label>
                    <input type="text" list="fb-study-programs-list" name="study_program" value="${fmtStr(it.study_program)==='-'?'':fmtStr(it.study_program)}" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    <datalist id="fb-study-programs-list">${(@json($studyPrograms ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Fakultas</label>
                    <input type="text" list="fb-faculties-list" name="faculty" value="${fmtStr(it.faculty)==='-'?'':fmtStr(it.faculty)}" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    <datalist id="fb-faculties-list">${(@json($faculties ?? [])).map(c => `<option value="${c.name}">`).join('')}</datalist>
                </div>

                ${section('Informasi Magang')}
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Jenis Magang</label>
                    <select name="internship_type" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        <option value="">-- Pilih --</option>${typeOpts}
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Sistem Kerja</label>
                    <select name="internship_arrangement" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        <option value="">-- Pilih --</option>${arrangementOpts}
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Minat Program Magang</label>
                    <select name="internship_interest" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        <option value="">-- Pilih --</option>${interestOpts}
                    </select>
                </div>
                ${editField('Tgl Mulai', 'start_date', it.start_date_raw, 'date')}
                ${editField('Tgl Selesai', 'end_date', it.end_date_raw, 'date')}
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Alasan Magang</label>
                    <textarea name="internship_reason" rows="2"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] resize-none">${fmtStr(it.internship_reason)==='- '?'':fmtStr(it.internship_reason)}</textarea>
                </div>
                ${editField('Nama Pembimbing', 'supervisor_name', it.supervisor_name)}
                ${editField('No. WA Pembimbing', 'supervisor_contact', it.supervisor_contact)}

                ${section('Keahlian & Alat')}
                ${editField('Software Desain', 'design_software', it.design_software)}
                ${editField('Software Video', 'video_software', it.video_software)}
                ${editField('Bahasa Pemrograman', 'programming_languages', it.programming_languages)}
                ${editField('Digital Marketing', 'digital_marketing_type', it.digital_marketing_type)}
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Apakah Memiliki Alat Kerja (Laptop) Sendiri?<span class="text-red-500 ml-0.5">*</span></label>
                    <div class="flex flex-wrap gap-4 mt-1.5">
                        <label class="inline-flex items-center gap-1.5 text-[12.5px] cursor-pointer text-[#1B3A34]">
                            <input type="radio" name="has_laptop" value="1" ${(it.has_laptop === true || it.has_laptop === 1 || it.has_laptop === '1' || String(it.has_laptop).toLowerCase() === 'ya') ? 'checked' : ''} class="accent-[#2D8659]"> Ya, Punya Sendiri
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-[12.5px] cursor-pointer text-[#1B3A34]">
                            <input type="radio" name="has_laptop" value="0" ${(it.has_laptop === false || it.has_laptop === 0 || it.has_laptop === '0' || String(it.has_laptop).toLowerCase() === 'tidak') ? 'checked' : ''} class="accent-[#2D8659]"> Tidak
                        </label>
                    </div>
                </div>
                ${editField('Peralatan (Laptop, Kamera, dll)', 'owned_tools', it.owned_tools)}

                ${section('Informasi Tambahan')}
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Info Tempat Tinggal</label>
                    <select name="boarding_info" class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        <option value="">-- Pilih --</option>
                        <option value="Ya" ${it.boarding_info === 'Ya' ? 'selected' : ''}>Ya</option>
                        <option value="Tidak" ${it.boarding_info === 'Tidak' ? 'selected' : ''}>Tidak</option>
                    </select>
                </div>

                ${editField('Instagram', 'social_media_instagram', it.social_media_instagram)}
                ${editField('Sumber Info Magang', 'internship_info_sources', it.internship_info_sources)}
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Kegiatan Lain Selain Magang</label>
                    <textarea name="current_activities" rows="2"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] resize-none">${fmtStr(it.current_activities)==='-'?'':fmtStr(it.current_activities)}</textarea>
                </div>
            `;
        }

        document.getElementById('appModalBody').innerHTML = `
        <form id="editForm" class="space-y-1">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                ${dynamicFieldsHtml}

                <div class="col-span-2 mt-1 border-b border-[#DCE7E1] pb-1">
                    <p class="text-[10.5px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Status Pendaftaran</p>
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Status Magang</label>
                    <select name="internship_status"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        ${statusOpts}
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Brand</label>
                    <select name="brand"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                        <option value="">— Belum dipilih —</option>
                        ${(@json($brands ?? [])).map(b => {
                            const isSel = (it.brand_id && String(it.brand_id) === String(b.id)) ||
                                          (it.brand && (it.brand === b.name || it.brand === b.code)) ||
                                          (it.brand_name && it.brand_name === b.name);
                            return `<option value="${b.name}" ${isSel ? 'selected' : ''}>${b.name}</option>`;
                        }).join('')}
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">Catatan / Alasan Perubahan (Opsional)</label>
                    <input type="text" name="reason" placeholder="Contoh: Koreksi data berkas atau penyesuaian status"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-[#DCE7E1] pt-4 mt-4">
                <button type="button" onclick="document.getElementById('appModal').classList.add('hidden')"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#1B3A34] hover:bg-[#F4F8F6]">
                    Batal
                </button>
                <button type="submit"
                    class="rounded-[9px] bg-[#2D8659] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1F5F3F]">
                    Simpan Perubahan
                </button>
            </div>
        </form>`;

        document.getElementById('editForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd  = new FormData(e.target);
            const url = `${ADMIN_INTERNS_BASE}/${it.id}`;
            fd.append('_method', 'PATCH');
            try {
                const res = await fetch(url, {
                    method: 'POST', body: fd,
                    headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                });
                if (!res.ok) {
                    if (res.status === 422) {
                        const errData = await res.json();
                        let errMsg = 'Validasi gagal: ';
                        if (errData.errors) {
                            errMsg += Object.values(errData.errors).map(e => e.join(', ')).join(' | ');
                        }
                        throw new Error(errMsg);
                    }
                    throw new Error(`HTTP ${res.status}`);
                }
                closeModal(appModal);
                pushToast('Data berhasil disimpan.');
                loadPage(window.__CURRENT_PAGE || 1);
            } catch (err) {
                pushToast(err.message, 'error');
            }
        });

        openModal(appModal);
    }

    function editField(label, name, val, type = 'text') {
        const v = (fmtStr(val) === '-') ? '' : fmtStr(val);
        return `<div>
            <label class="mb-1 block text-[12px] font-semibold text-[#1B3A34]">${label}</label>
            <input type="${type}" name="${name}" value="${v}"
                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
        </div>`;
    }

    // ── Modal: Konfirmasi Hapus (Soft Delete ke Arsip) ──────────────────────────
    let deleteId = null;

    function openConfirmDelete(id, name) {
        deleteId = id;
        const pwdInput = document.getElementById('delete_admin_password');
        if (pwdInput) pwdInput.value = '';
        document.getElementById('confirmBody').innerHTML =
            `Data <strong>${name}</strong> akan dipindahkan ke arsip (soft delete). Dokumen dan riwayat tetap terjaga.`;
        openModal(confirmModal);
    }

    document.getElementById('confirmYes').addEventListener('click', async () => {
        if (!deleteId) return;
        const passwordInput = document.getElementById('delete_admin_password');
        const adminPassword = (passwordInput?.value || '').trim();
        if (!adminPassword) {
            pushToast('Konfirmasi kata sandi admin wajib diisi.', 'error');
            passwordInput?.focus();
            return;
        }

        const url = `${ADMIN_INTERNS_BASE}/${deleteId}`;
        try {
            const fd = new FormData();
            fd.append('_method', 'DELETE');
            fd.append('admin_password', adminPassword);
            const res = await fetch(url, {
                method: 'POST', body: fd,
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
            closeModal(confirmModal);
            pushToast(data.message || 'Data pendaftaran berhasil dipindahkan ke arsip.');
            loadPage(window.__CURRENT_PAGE || 1);
        } catch (err) {
            pushToast('Gagal memindahkan ke arsip: ' + err.message, 'error');
        } finally {
            deleteId = null;
        }
    });

    // ── Modal: Konfirmasi Pemulihan (Restore) ──────────────────────────────────
    let restoreId = null;
    const restoreModal = document.getElementById('restoreModal');

    function openConfirmRestore(id, name) {
        restoreId = id;
        const pwdInput = document.getElementById('restore_admin_password');
        if (pwdInput) pwdInput.value = '';
        document.getElementById('restoreBody').innerHTML =
            `Anda akan memulihkan data pendaftaran <strong>${name}</strong> dari arsip terhapus.`;
        openModal(restoreModal);
    }

    document.querySelectorAll('[data-restore-close]').forEach(el => {
        el.addEventListener('click', () => closeModal(restoreModal));
    });

    document.getElementById('restoreYes')?.addEventListener('click', async () => {
        if (!restoreId) return;
        const passwordInput = document.getElementById('restore_admin_password');
        const adminPassword = (passwordInput?.value || '').trim();
        if (!adminPassword) {
            pushToast('Konfirmasi kata sandi admin wajib diisi.', 'error');
            passwordInput?.focus();
            return;
        }

        const url = `${ADMIN_INTERNS_BASE}/${restoreId}/restore`;
        try {
            const fd = new FormData();
            fd.append('admin_password', adminPassword);
            const res = await fetch(url, {
                method: 'POST', body: fd,
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
            closeModal(restoreModal);
            pushToast(data.message || 'Data pendaftaran berhasil dipulihkan dari arsip.');
            loadPage(window.__CURRENT_PAGE || 1);
        } catch (err) {
            pushToast('Gagal memulihkan data: ' + err.message, 'error');
        } finally {
            restoreId = null;
        }
    });

    // ── Pagination ───────────────────────────────────────────────────────────────
    function buildPager(meta) {
        const { current_page, last_page, total, per_page } = meta;
        const from = (current_page - 1) * per_page + 1;
        const to   = Math.min(current_page * per_page, total);
        const prev = current_page > 1 ? current_page - 1 : null;
        const next = current_page < last_page ? current_page + 1 : null;

        pagerEl.innerHTML = `
        <div class="flex items-center justify-between text-[12.5px] text-[#4B5F5A]">
            <span>Menampilkan <strong class="text-[#1B3A34]">${from}–${to}</strong> dari <strong class="text-[#1B3A34]">${total}</strong> pemagang</span>
            <div class="flex gap-1.5">
                <button ${!prev ? 'disabled' : ''} data-goto="${prev || ''}"
                    class="rounded-[7px] border border-[#DCE7E1] bg-white px-3 py-1.5 text-[12.5px] font-semibold text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#2D8659] disabled:cursor-not-allowed disabled:opacity-40">
                    ← Sebelumnya
                </button>
                ${Array.from({ length: Math.min(5, last_page) }, (_, i) => {
                    let p;
                    if (last_page <= 5) {
                        p = i + 1;
                    } else if (current_page <= 3) {
                        p = i + 1;
                    } else if (current_page >= last_page - 2) {
                        p = last_page - 4 + i;
                    } else {
                        p = current_page - 2 + i;
                    }
                    return `<button data-goto="${p}"
                        class="h-8 w-8 rounded-[7px] border text-[12.5px] font-semibold transition
                            ${p === current_page
                                ? 'border-[#2D8659] bg-[#2D8659] text-white'
                                : 'border-[#DCE7E1] bg-white text-[#4B5F5A] hover:border-[#2D8659] hover:text-[#2D8659]'}">
                        ${p}
                    </button>`;
                }).join('')}
                <button ${!next ? 'disabled' : ''} data-goto="${next || ''}"
                    class="rounded-[7px] border border-[#DCE7E1] bg-white px-3 py-1.5 text-[12.5px] font-semibold text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#2D8659] disabled:cursor-not-allowed disabled:opacity-40">
                    Berikutnya →
                </button>
            </div>
        </div>`;

        pagerEl.querySelectorAll('button[data-goto]').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = Number(btn.dataset.goto);
                if (p) loadPage(p);
            });
        });
    }

    // ── Pending: Batalkan ────────────────────────────────────────────────────────
    discardBtn?.addEventListener('click', () => {
        if (pending.size === 0) return;
        for (const { select, from } of pending.values()) {
            select.value = from;
            markSelect(select, false);
        }
        pending.clear();
        updatePendingBar();
        pushToast('Semua perubahan dibatalkan.');
    });

    // ── Pending: Simpan semua ────────────────────────────────────────────────────
    async function runWithConcurrency(tasks, limit = 4) {
        const results = [];
        let i = 0;
        const workers = Array.from({ length: Math.min(limit, tasks.length) }, async () => {
            while (i < tasks.length) {
                const cur = i++;
                try { results[cur] = await tasks[cur](); }
                catch (e) { results[cur] = e; }
            }
        });
        await Promise.all(workers);
        return results;
    }

    saveAllBtn?.addEventListener('click', async () => {
        if (pending.size === 0) return;

        // Cek apakah ada pending 'accepted' yang belum punya brand (di mode pendaftar)
        if (MODE === 'pendaftar') {
            const needBrand = Array.from(pending.values()).find(x => x.to === 'accepted' && !x.brand);
            if (needBrand) {
                openBrandModal(needBrand.id, needBrand.name);
                return;
            }
        }

        const items = Array.from(pending.values());
        saveAllBtn.disabled = true;
        discardBtn.disabled = true;

        const tasks = items.map(item => async () => {
            const fd = new FormData();
            fd.append('_method', 'PATCH');
            fd.append('internship_status', item.to);
            if (item.brand) fd.append('brand', item.brand);
            const res = await fetch(item.url, {
                method: 'POST', body: fd,
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return item;
        });

        try {
            const results = await runWithConcurrency(tasks, 4);
            let ok = 0, fail = 0;

            // Tentukan status yang boleh tampil di mode ini
            const allowedInCurrentMode = allowedStatuses();

            results.forEach((res) => {
                if (res instanceof Error) { fail++; return; }
                ok++;
                res.select.dataset.current = res.to;
                markSelect(res.select, false);
                applyBadge(res.badge, res.to);
                pending.delete(res.id);

                const rowObj = window.rowData.get(res.id);
                if (rowObj) {
                    rowObj.internship_status = res.to;
                    if (res.brand) rowObj.brand = res.brand;
                }

                const row = res.select.closest('tr[data-row-id]');
                if (row) refreshGenerateButtons(row, res.id, res.to);

                // Jika status baru tidak ada di scope mode ini → hilangkan baris
                if (!allowedInCurrentMode.includes(res.to)) {
                    if (row) {
                        row.style.transition = 'opacity 0.5s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 500);
                    }
                }
            });

            updatePendingBar();
            if (ok)   pushToast(`${ok} perubahan berhasil disimpan.`);
            if (fail) pushToast(`${fail} perubahan gagal disimpan. Coba lagi.`, 'error');
        } catch (e) {
            pushToast('Gagal menyimpan perubahan.', 'error');
        } finally {
            saveAllBtn.disabled = pending.size === 0;
            discardBtn.disabled = false;
        }
    });

    // ── Load page (fetch API) ────────────────────────────────────────────────────
    let __formFields = @json($formFields ?? []); // cache form_fields dari server / API

    async function loadPage(page = 1) {
        const searchQuery = (searchInput?.value || '').trim();
        const followupVal = (document.getElementById('followup-filter')?.value || '').trim();
        const params = new URLSearchParams({
            scope:    activeFilter,
            page:     String(page),
            per_page: '10',
        });

        // Filter status follow-up
        if (followupVal) {
            params.set('followup_status', followupVal);
        }

        // Hanya tambah q jika ada isi
        if (searchQuery) {
            params.set('q', searchQuery);
            params.set('per_page', '1000');
        }

        rowsEl.innerHTML = `
            <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-[#4B5F5A]">
                Memuat data pemagang...
            </td></tr>`;

        try {
            const res = await fetch(`${API_URL}?${params.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            // Cache form_fields dari API (hanya update saat pertama atau berubah)
            if (json.form_fields && json.form_fields.length > 0) {
                const seenApiKeys = new Set();
                __formFields = json.form_fields.filter(f => {
                    if (!f.field_key || seenApiKeys.has(f.field_key)) return false;
                    seenApiKeys.add(f.field_key);
                    return true;
                });
            }
            renderRows(json);
            window.__CURRENT_PAGE = page;
            if (searchQuery) pagerEl.innerHTML = '';
        } catch (e) {
            console.error('Error loading interns:', e);
            rowsEl.innerHTML = `
                <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-[#D32F2F]">
                    Gagal memuat data. Silakan muat ulang halaman.
                </td></tr>`;
        }
    }

    // ── Export / global reload ───────────────────────────────────────────────────
    window.__CURRENT_PAGE  = 1;
    window.reloadInterns   = (p) => loadPage(p || window.__CURRENT_PAGE || 1);

    // ── Init ─────────────────────────────────────────────────────────────────────
    const followupFilterEl = document.getElementById('followup-filter');
    if (followupFilterEl) {
        followupFilterEl.addEventListener('change', () => loadPage(1));
    }

    loadPage(Number(new URLSearchParams(location.search).get('page') || 1));

    window.addEventListener('beforeunload', (e) => {
        if (pending.size > 0) { e.preventDefault(); e.returnValue = ''; }
    });
});
</script>

@endsection
