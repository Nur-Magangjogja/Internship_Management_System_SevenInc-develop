@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Dashboard & Monitoring</p>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Pengaturan Form Pendaftaran</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Kelola pertanyaan yang tampil pada form pendaftaran pemagang. Perubahan langsung terlihat pada preview di sisi kanan.</p>
        </div>

        {{-- Year Switcher & Publication Controls --}}
        <div class="flex flex-wrap items-center gap-3">
            {{-- Year Selector Buttons --}}
            <div class="inline-flex rounded-[8px] border border-[#DCE7E1] bg-white p-1 shadow-sm">
                @foreach([2026, 2027] as $y)
                <a href="{{ route('admin.form-settings.fields', ['year' => $y]) }}"
                   class="rounded-[6px] px-3 py-1.5 text-xs font-bold transition {{ ($year ?? 2026) == $y ? 'bg-[#2D8659] text-white shadow-xs' : 'text-[#4B5F5A] hover:bg-[#F4F8F6]' }}">
                    Tahun {{ $y }}
                </a>
                @endforeach
            </div>

            {{-- Draft & Published Badges --}}
            <div class="flex items-center gap-2 text-xs">
                <span class="rounded-[6px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 font-medium text-[#1B3A34]">
                    Draft: <strong class="text-[#2D8659]">{{ $currentDraft->version_code }}</strong>
                </span>
                <span class="rounded-[6px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 font-medium text-[#1B3A34]">
                    Publikasi: <strong>{{ $activePublished ? $activePublished->version_code : 'Belum Terbit' }}</strong>
                </span>
            </div>

            {{-- Publish Button --}}
            <form method="POST" action="{{ route('admin.form-settings.fields.publish') }}" onsubmit="return confirm('Apakah Anda yakin ingin mempublikasikan draf formulir {{ $currentDraft->version_code }} untuk tahun {{ $year ?? 2026 }}? Versi publikasi sebelumnya akan diarsipkan secara otomatis.');">
                @csrf
                <input type="hidden" name="year" value="{{ $year ?? 2026 }}">
                <button type="submit"
                    class="inline-flex items-center gap-2 rounded-[8px] bg-[#2D8659] px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-[#1F5F3F] transition">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Publikasikan Formulir
                </button>
            </form>
        </div>
    </div>

    {{-- Alert --}}
    @if(session('success'))
    <div id="alert-success" class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
        <button onclick="document.getElementById('alert-success').remove()" class="ml-auto text-[#2D8659] hover:opacity-70">✕</button>
    </div>
    @endif
    @if(session('error'))
    <div id="alert-error" class="mb-4 flex items-center gap-3 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-[#D32F2F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        {{ session('error') }}
        <button onclick="document.getElementById('alert-error').remove()" class="ml-auto text-red-400 hover:opacity-70">✕</button>
    </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">

        {{-- ===== KIRI: FIELD MANAGER ===== --}}
        <div class="xl:col-span-3 space-y-5">

            {{-- Tambah Field Baru --}}
            <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-4">
                    <div class="flex h-7 w-7 items-center justify-center rounded-[7px] bg-[#E8F5E9]">
                        <svg class="h-4 w-4 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </div>
                    <h2 class="text-[14px] font-bold text-[#1B3A34]">Tambah Pertanyaan Baru (Tahun {{ $year ?? 2026 }})</h2>
                </div>

                <form id="form-add-field" method="POST" action="{{ route('admin.form-settings.fields.store') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year ?? 2026 }}">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Label Pertanyaan <span class="text-red-500">*</span></label>
                            <input type="text" name="label" id="new-label" required placeholder="Contoh: Pengalaman Kerja"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Tipe Input <span class="text-red-500">*</span></label>
                            <select name="field_type" id="new-field-type" required
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                                @foreach($types as $val => $name)
                                <option value="{{ $val }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Grup</label>
                            <select name="group_name"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                                @foreach($groups as $val => $name)
                                <option value="{{ $val }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Lebar Kolom</label>
                            <select name="column_span"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                                <option value="1">Penuh (1 kolom)</option>
                                <option value="2">Setengah (2 kolom)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Placeholder</label>
                            <input type="text" name="placeholder" placeholder="Opsional"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        </div>
                        <div class="col-span-2">
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Teks Bantuan (helper)</label>
                            <input type="text" name="helper_text" placeholder="Teks kecil di bawah field, opsional"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        </div>

                        {{-- Options (tampil jika type = select/radio/checkbox) --}}
                        <div class="col-span-2 hidden" id="new-options-wrap">
                            <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Opsi Pilihan</label>
                            <p class="mb-2 text-[11px] text-[#4B5F5A]">Tambahkan opsi satu per satu. Klik "+" untuk menambah baris baru.</p>
                            <div id="new-options-list" class="space-y-2 mb-2">
                                <div class="option-row flex items-center gap-2">
                                    <input type="text" placeholder="Value (contoh: ya)" data-role="value"
                                        class="flex-1 rounded-[7px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 text-[12px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                                    <input type="text" placeholder="Label (contoh: Ya, saya bisa)" data-role="label"
                                        class="flex-1 rounded-[7px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 text-[12px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
                                    <button type="button" onclick="this.closest('.option-row').remove()" title="Hapus opsi"
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[7px] border border-red-200 bg-red-50 text-red-500 hover:bg-red-500 hover:text-white transition">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            </div>
                            <button type="button" id="btn-add-option"
                                class="flex items-center gap-1.5 text-[12px] font-medium text-[#2D8659] hover:underline">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Tambah Opsi
                            </button>
                            {{-- hidden input untuk JSON options --}}
                            <input type="hidden" name="options" id="new-options-json">
                        </div>

                        <div class="col-span-2 flex items-center gap-2">
                            <label class="flex items-center gap-2 cursor-pointer select-none text-[12.5px] font-medium text-[#1B3A34]">
                                <input type="checkbox" name="is_required" value="1"
                                    class="w-4 h-4 rounded accent-[#2D8659]">
                                Wajib diisi
                            </label>
                        </div>
                    </div>

                    <button type="submit"
                        class="flex w-full items-center justify-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1F5F3F] transition">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Tambah ke Form
                    </button>
                </form>
            </div>

            {{-- Field List: Form Utama --}}
            <x-form-field-group
                title="Form Utama"
                :fields="$mainFields"
                :types="$types"
                group="main" />

            {{-- Field List: Informasi Tambahan --}}
            <x-form-field-group
                title="Informasi Tambahan"
                :fields="$extraFields"
                :types="$types"
                group="informasi_tambahan" />

        </div>

        {{-- ===== KANAN: LIVE PREVIEW ===== --}}
        <div class="xl:col-span-2">
            <div class="sticky top-20">
                <div class="rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between border-b border-[#DCE7E1] px-5 py-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-2 w-2 rounded-full bg-green-500 animate-pulse"></span>
                            <h2 class="text-[13px] font-bold text-[#1B3A34]">Live Preview Form Pemagang</h2>
                        </div>
                        <span class="text-[11px] text-[#4B5F5A]">Tampilan aktual</span>
                    </div>
                    <div class="h-[calc(100vh-180px)] overflow-y-auto">
                        <div id="form-preview-container" class="p-4">
                            {{-- Preview diisi oleh JavaScript --}}
                            <div class="flex items-center justify-center h-40 text-[13px] text-[#4B5F5A]">
                                <div class="text-center">
                                    <svg class="h-8 w-8 mx-auto mb-2 text-[#DCE7E1]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                                    Memuat preview...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

{{-- ===== MODAL EDIT FIELD ===== --}}
<div id="modal-edit" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
    <div class="w-full max-w-lg rounded-[14px] bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-[#DCE7E1] px-6 py-4">
            <h3 class="text-[15px] font-bold text-[#1B3A34]">Edit Pertanyaan</h3>
            <button type="button" onclick="closeEditModal()"
                class="flex h-8 w-8 items-center justify-center rounded-full text-[#4B5F5A] hover:bg-[#F4F8F6] transition">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form id="form-edit-field" method="POST" class="p-6 space-y-4">
            @csrf @method('PUT')
            <input type="hidden" id="edit-field-id">

            <div>
                <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Label Pertanyaan <span class="text-red-500">*</span></label>
                <input type="text" name="label" id="edit-label" required
                    class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div id="edit-type-wrap">
                    <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Tipe Input</label>
                    <select name="field_type" id="edit-field-type"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        @foreach($types as $val => $name)
                        <option value="{{ $val }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Lebar Kolom</label>
                    <select name="column_span" id="edit-column-span"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                        <option value="1">Penuh (1 kolom)</option>
                        <option value="2">Setengah (2 kolom)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Placeholder</label>
                    <input type="text" name="placeholder" id="edit-placeholder"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                </div>
                <div>
                    <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Teks Bantuan</label>
                    <input type="text" name="helper_text" id="edit-helper-text"
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                </div>
            </div>

            {{-- Options (untuk select/radio/checkbox) --}}
            <div id="edit-options-wrap" class="hidden">
                <label class="block mb-1 text-[12px] font-semibold text-[#1B3A34]">Opsi Pilihan</label>
                <div id="edit-options-list" class="space-y-2 mb-2"></div>
                <button type="button" onclick="addOptionRow('edit-options-list')"
                    class="flex items-center gap-1.5 text-[12px] font-medium text-[#2D8659] hover:underline">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah Opsi
                </button>
                <input type="hidden" name="options" id="edit-options-json">
            </div>

            <div class="flex items-center gap-2">
                <label class="flex items-center gap-2 cursor-pointer select-none text-[12.5px] font-medium text-[#1B3A34]">
                    <input type="checkbox" name="is_required" id="edit-is-required" value="1"
                        class="w-4 h-4 rounded accent-[#2D8659]">
                    Wajib diisi
                </label>
                <label class="flex items-center gap-2 cursor-pointer select-none text-[12.5px] font-medium text-[#1B3A34] ml-4">
                    <input type="checkbox" name="is_active" id="edit-is-active" value="1"
                        class="w-4 h-4 rounded accent-[#2D8659]">
                    Aktif (tampil di form)
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2 border-t border-[#DCE7E1]">
                <button type="button" onclick="closeEditModal()"
                    class="rounded-[8px] border border-[#DCE7E1] px-4 py-2 text-[13px] font-medium text-[#4B5F5A] hover:bg-[#F4F8F6] transition">
                    Batal
                </button>
                <button type="submit"
                    class="rounded-[8px] bg-[#2D8659] px-5 py-2 text-[13px] font-semibold text-white hover:bg-[#1F5F3F] transition">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// ================================================================
// CSRF Token
// ================================================================
const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// ================================================================
// Tipe yg butuh options
// ================================================================
const TYPES_WITH_OPTIONS = ['select', 'radio', 'checkbox'];

// ================================================================
// Toggle opsi wrapper saat tipe berubah (form tambah)
// ================================================================
document.getElementById('new-field-type').addEventListener('change', function () {
    const wrap = document.getElementById('new-options-wrap');
    wrap.classList.toggle('hidden', !TYPES_WITH_OPTIONS.includes(this.value));
});

document.getElementById('edit-field-type')?.addEventListener('change', function () {
    const wrap = document.getElementById('edit-options-wrap');
    wrap.classList.toggle('hidden', !TYPES_WITH_OPTIONS.includes(this.value));
});

// ================================================================
// Tambah baris opsi (dipakai oleh form tambah & edit)
// ================================================================
function addOptionRow(containerId, value = '', label = '') {
    const list = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'option-row flex items-center gap-2';
    row.innerHTML = `
        <input type="text" placeholder="Value (contoh: ya)" data-role="value" value="${escHtml(value)}"
            class="flex-1 rounded-[7px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 text-[12px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
        <input type="text" placeholder="Label (contoh: Ya, saya bisa)" data-role="label" value="${escHtml(label)}"
            class="flex-1 rounded-[7px] border border-[#DCE7E1] bg-white px-2.5 py-1.5 text-[12px] text-[#1B3A34] outline-none focus:border-[#2D8659]">
        <button type="button" onclick="this.closest('.option-row').remove()" title="Hapus"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[7px] border border-red-200 bg-red-50 text-red-500 hover:bg-red-500 hover:text-white transition">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>`;
    list.appendChild(row);
}

document.getElementById('btn-add-option')?.addEventListener('click', () => addOptionRow('new-options-list'));

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// ================================================================
// Kumpulkan options dari DOM list → JSON
// ================================================================
function collectOptions(listId) {
    const list = document.getElementById(listId);
    if (!list) return null;
    const rows = list.querySelectorAll('.option-row');
    const opts = [];
    rows.forEach(row => {
        const v = row.querySelector('[data-role="value"]').value.trim();
        const l = row.querySelector('[data-role="label"]').value.trim();
        if (v || l) opts.push({ value: v || l, label: l || v });
    });
    return opts.length ? JSON.stringify(opts) : null;
}

// ================================================================
// Submit form tambah: inject options JSON sebelum kirim
// ================================================================
document.getElementById('form-add-field').addEventListener('submit', function (e) {
    const type = document.getElementById('new-field-type').value;
    if (TYPES_WITH_OPTIONS.includes(type)) {
        const opts = collectOptions('new-options-list');
        document.getElementById('new-options-json').value = opts || '';
    }
});

// ================================================================
// Modal Edit
// ================================================================
function openEditModal(id) {
    fetch(`/admin/form-settings/fields/${id}/data`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        const f = data.field;

        document.getElementById('edit-field-id').value = f.id;

        // Ubah action form sesuai ID
        const form = document.getElementById('form-edit-field');
        form.action = `/admin/form-settings/fields/${f.id}`;

        document.getElementById('edit-label').value       = f.label ?? '';
        document.getElementById('edit-placeholder').value = f.placeholder ?? '';
        document.getElementById('edit-helper-text').value = f.helper_text ?? '';
        document.getElementById('edit-is-required').checked = !!f.is_required;
        document.getElementById('edit-is-active').checked   = !!f.is_active;

        const typeSelect = document.getElementById('edit-field-type');
        typeSelect.value = f.field_type ?? 'text';

        const colSpan = document.getElementById('edit-column-span');
        colSpan.value = f.column_span ?? 1;

        // Sembunyikan tipe utk field system
        document.getElementById('edit-type-wrap').style.opacity = f.is_system ? '0.5' : '1';
        typeSelect.disabled = !!f.is_system;

        // Options
        const optsWrap = document.getElementById('edit-options-wrap');
        const optsList = document.getElementById('edit-options-list');
        optsList.innerHTML = '';

        if (TYPES_WITH_OPTIONS.includes(f.field_type)) {
            optsWrap.classList.remove('hidden');
            if (Array.isArray(f.options)) {
                f.options.forEach(opt => addOptionRow('edit-options-list', opt.value, opt.label));
            }
        } else {
            optsWrap.classList.add('hidden');
        }

        // Tampilkan modal
        const modal = document.getElementById('modal-edit');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    })
    .catch(err => showToast('Gagal memuat data field: ' + err.message, 'error'));
}

function closeEditModal() {
    const modal = document.getElementById('modal-edit');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Submit form edit: inject options JSON
document.getElementById('form-edit-field').addEventListener('submit', function (e) {
    const type = document.getElementById('edit-field-type').value;
    if (TYPES_WITH_OPTIONS.includes(type)) {
        const opts = collectOptions('edit-options-list');
        document.getElementById('edit-options-json').value = opts || '';
    }
});

// Tutup modal saat klik overlay
document.getElementById('modal-edit').addEventListener('click', function (e) {
    if (e.target === this) closeEditModal();
});

// ================================================================
// Toggle required via AJAX
// ================================================================
function toggleRequired(id) {
    fetch(`/admin/form-settings/fields/${id}/toggle-required`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const btn   = document.getElementById('btn-req-' + id);
            const label = document.getElementById('req-label-' + id);
            if (data.is_required) {
                btn.className   = btn.className
                    .replace(/border-gray-200 bg-gray-50 text-gray-400 hover:border-\[#2D8659\] hover:text-\[#2D8659\]/g, '')
                    + ' border-red-200 bg-red-50 text-red-600 hover:bg-red-500 hover:text-white';
                label.textContent = '*wajib';
                btn.title = 'Jadikan Opsional';
            } else {
                btn.className   = btn.className
                    .replace(/border-red-200 bg-red-50 text-red-600 hover:bg-red-500 hover:text-white/g, '')
                    + ' border-gray-200 bg-gray-50 text-gray-400 hover:border-[#2D8659] hover:text-[#2D8659]';
                label.textContent = 'opsional';
                btn.title = 'Jadikan Wajib';
            }
            showToast(data.message, 'success');
            refreshPreview();
        } else {
            showToast(data.message || 'Terjadi kesalahan.', 'error');
        }
    });
}

// ================================================================
// Toggle aktif via AJAX
// ================================================================
function toggleField(id) {
    fetch(`/admin/form-settings/fields/${id}/toggle`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Terjadi kesalahan.', 'error');
        }
    });
}

// ================================================================
// Hapus field via AJAX
// ================================================================
function deleteField(id, label) {
    if (!confirm(`Hapus field "${label}"?\nAksi ini tidak dapat dibatalkan.`)) return;
    fetch(`/admin/form-settings/fields/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showToast(data.message, 'success');
            document.getElementById('field-row-' + id)?.remove();
            refreshPreview();
        } else {
            showToast(data.message || 'Field tidak bisa dihapus.', 'error');
        }
    });
}

// ================================================================
// Drag & Drop Reorder
// ================================================================
function initSortable(listId, group) {
    const el = document.getElementById(listId);
    if (!el || typeof Sortable === 'undefined') return;

    Sortable.create(el, {
        animation: 150,
        handle: '.drag-handle',
        ghostClass: 'opacity-30',
        onEnd: function () {
            const ids = Array.from(el.querySelectorAll('[data-field-id]'))
                .map(r => parseInt(r.dataset.fieldId));

            fetch('/admin/form-settings/fields/reorder', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': CSRF,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ ids, group })
            })
            .then(r => r.json())
            .then(() => refreshPreview());
        }
    });
}

// ================================================================
// Live Preview
// ================================================================
const CURRENT_YEAR = {{ $year ?? 2026 }};

function refreshPreview() {
    const container = document.getElementById('form-preview-container');
    if (!container) return;

    fetch(`/admin/form-settings/fields/preview-data?year=${CURRENT_YEAR}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        container.innerHTML = buildPreviewHtml(data);
    })
    .catch(() => {
        container.innerHTML = '<p class="text-xs text-red-500 p-4">Gagal memuat preview.</p>';
    });
}

function buildPreviewHtml(data) {
    const inputCls = 'block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 px-3 py-2 text-xs';
    const labelCls = 'block mb-1 text-xs font-medium text-gray-700';
    const reqStar  = '<span class="text-red-500">*</span>';

    let html = `
        <h3 class="text-sm font-semibold text-gray-800 mb-1">Form Pendaftaran Magang</h3>
        <p class="text-xs text-gray-500 mb-4">Lengkapi data berikut untuk mendaftar program magang</p>
        <div class="space-y-3">`;

    function renderField(f) {
        const req = f.is_required ? reqStar : '';
        let inner = '';

        if (f.field_key === 'internship_interest') {
            // Khusus divisi — pakai data divisions dari response
            let opts = `<option value="">-- Pilih Divisi --</option>`;
            (data.divisions || []).forEach(d => { opts += `<option>${escHtml(d)}</option>`; });
            inner = `<select class="${inputCls}" disabled>${opts}</select>`;
        } else if (f.field_type === 'text' || f.field_type === 'email' || f.field_type === 'tel') {
            inner = `<input type="${f.field_type}" placeholder="${escHtml(f.placeholder || '')}" class="${inputCls}" disabled>`;
        } else if (f.field_type === 'date') {
            inner = `<input type="date" class="${inputCls}" disabled>`;
        } else if (f.field_type === 'textarea') {
            inner = `<textarea rows="2" placeholder="${escHtml(f.placeholder || '')}" class="${inputCls} resize-none" disabled></textarea>`;
        } else if (f.field_type === 'select') {
            const placeholder = f.placeholder || '-- Pilih --';
            const opts = (f.options || []).map(o => `<option>${escHtml(o.label)}</option>`).join('');
            inner = `<select class="${inputCls}" disabled><option>${escHtml(placeholder)}</option>${opts}</select>`;
            // Jika ada opsi dengan description, tampilkan info box di bawah
            const hasDesc = (f.options || []).some(o => o.description);
            if (hasDesc) {
                inner += `<div class="mt-1.5 space-y-1 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">`;
                (f.options || []).forEach(o => {
                    if (o.description) {
                        inner += `<p class="text-[10px] text-gray-500 leading-snug">
                            <span class="font-semibold text-gray-700">${escHtml(o.label)}:</span>
                            ${escHtml(o.description)}
                        </p>`;
                    }
                });
                inner += `</div>`;
            }
        } else if (f.field_type === 'radio') {
            const opts = (f.options || []).map(o =>
                `<label class="flex items-center gap-1.5 text-xs text-gray-700">
                    <input type="radio" disabled class="w-3 h-3 text-green-600">
                    ${escHtml(o.label)}
                </label>`
            ).join('');
            inner = `<div class="flex flex-wrap gap-3">${opts}</div>`;
        } else if (f.field_type === 'checkbox') {
            const opts = (f.options || []).map(o =>
                `<label class="flex items-center gap-1.5 px-3 py-1.5 text-xs text-gray-700 border border-gray-100 rounded">
                    <input type="checkbox" disabled class="w-3 h-3 text-green-600">
                    ${escHtml(o.label)}
                </label>`
            ).join('');
            inner = `<div class="border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden">${opts}</div>`;
        } else if (f.field_type === 'file') {
            inner = `<input type="file" class="${inputCls} text-xs" disabled>`;
        }

        const helper = f.helper_text
            ? `<p class="mt-0.5 text-[10px] text-gray-400">${escHtml(f.helper_text)}</p>` : '';

        return `<div>
            <label class="${labelCls}">${escHtml(f.label)} ${req}</label>
            ${inner}${helper}
        </div>`;
    }

    // Render main fields, handle column_span=2 (pair them in grid)
    const mainFields  = data.main_fields  || [];
    const extraFields = data.extra_fields || [];

    html += renderFieldGroup(mainFields, renderField);

    if (extraFields.length > 0) {
        html += `<div class="border-t border-gray-100 pt-3">
            <h4 class="text-xs font-semibold text-gray-700 mb-3">Informasi Tambahan</h4>
            <div class="space-y-3">`;
        html += renderFieldGroup(extraFields, renderField);
        html += `</div></div>`;
    }

    html += `</div>`;
    return html;
}

function renderFieldGroup(fields, renderFn) {
    let html = '';
    let i = 0;
    while (i < fields.length) {
        const f = fields[i];
        if (f.column_span === 2 && i + 1 < fields.length && fields[i + 1].column_span === 2) {
            // Pasangkan dua field setengah dalam satu grid row
            html += `<div class="grid grid-cols-2 gap-2">
                ${renderFn(f)}
                ${renderFn(fields[i + 1])}
            </div>`;
            i += 2;
        } else {
            html += renderFn(f);
            i++;
        }
    }
    return html;
}

function escHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// ================================================================
// Toast notification
// ================================================================
function showToast(message, type = 'success') {
    let toast = document.getElementById('global-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'global-toast';
        toast.className = 'fixed bottom-5 right-5 z-[9999] flex items-center gap-2 rounded-[10px] border px-4 py-3 text-sm font-semibold shadow-lg transition-all';
        document.body.appendChild(toast);
    }
    if (type === 'success') {
        toast.className = toast.className.replace(/border-\S+ bg-\S+ text-\S+/g, '');
        toast.classList.add('border-[#A5D6A7]', 'bg-[#E8F5E9]', 'text-[#1F5F3F]');
    } else {
        toast.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
    }
    toast.innerHTML = `${escHtml(message)} <button onclick="this.parentNode.remove()" class="ml-2 opacity-60 hover:opacity-100">✕</button>`;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => toast?.remove(), 4000);
}

// ================================================================
// Init
// ================================================================
document.addEventListener('DOMContentLoaded', function () {
    // Load SortableJS CDN jika belum ada
    if (typeof Sortable === 'undefined') {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
        s.onload = function () {
            initSortable('field-list-main', 'main');
            initSortable('field-list-extra', 'informasi_tambahan');
        };
        document.head.appendChild(s);
    } else {
        initSortable('field-list-main', 'main');
        initSortable('field-list-extra', 'informasi_tambahan');
    }

    // Muat preview awal
    refreshPreview();
});
</script>
@endpush

@endsection
