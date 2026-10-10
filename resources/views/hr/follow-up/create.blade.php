@extends('layouts.dashboard', ['title' => 'Buka Siklus Follow-Up Baru'])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.follow-up' : 'admin.follow-up';
@endphp

@section('content')
<div class="px-6 py-8">
    <div class="mb-6 flex items-center gap-3">
        <a
            href="{{ route($routePrefix . '.index') }}"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:bg-slate-50"
            title="Kembali ke Daftar Siklus"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Buka Siklus Follow-Up Baru</h1>
            <p class="text-sm text-slate-500">Pilih kontak pembimbing/PIC dari institusi mitra untuk menginisiasi siklus penjangkauan tahunan.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route($routePrefix . '.store') }}" class="space-y-6">
            @csrf

            <!-- Pilih Institusi -->
            <div>
                <label for="institution_select" class="block text-xs font-semibold text-slate-700">
                    Pilih Institusi Mitra <span class="text-rose-500">*</span>
                </label>
                <select
                    id="institution_select"
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    onchange="filterContactsByInstitution(this.value)"
                >
                    <option value="">-- Pilih Institusi Kampus / Sekolah --</option>
                    @foreach ($institutions as $inst)
                        <option value="{{ $inst->id }}" data-contacts-count="{{ $inst->contacts->count() }}">
                            {{ $inst->name }} ({{ $inst->contacts->count() }} Kontak Terverifikasi)
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] text-slate-400">Pilih institusi terlebih dahulu untuk memunculkan daftar kontak pembimbing terverifikasi.</p>
            </div>

            <!-- Pilih Kontak Pembimbing -->
            <div>
                <label for="institution_contact_id" class="block text-xs font-semibold text-slate-700">
                    Kontak Guru / Dosen Pembimbing (PIC) Terverifikasi <span class="text-rose-500">*</span>
                </label>
                <select
                    id="institution_contact_id"
                    name="institution_contact_id"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
                    <option value="">-- Pilih Kontak Pembimbing --</option>
                    @foreach ($institutions as $inst)
                        @foreach ($inst->contacts as $contact)
                            <option
                                value="{{ $contact->id }}"
                                data-institution="{{ $inst->id }}"
                                {{ old('institution_contact_id') == $contact->id ? 'selected' : '' }}
                            >
                                {{ $contact->name }} - {{ $contact->position ?? 'Pembimbing' }} ({{ $contact->phone ?? 'Tanpa HP' }}) &mdash; {{ $inst->name }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
                <p id="noContactWarning" class="mt-1.5 hidden text-[11px] text-amber-600">
                    Institusi ini belum memiliki kontak pembimbing yang terverifikasi. Kontak baru pendaftar harus diverifikasi terlebih dahulu di antrean Verifikasi Kontak.
                </p>
            </div>

            <!-- Tahun Siklus -->
            <div>
                <label for="cycle_year" class="block text-xs font-semibold text-slate-700">
                    Tahun Siklus Follow-Up <span class="text-rose-500">*</span>
                </label>
                <input
                    type="number"
                    id="cycle_year"
                    name="cycle_year"
                    min="2020"
                    max="2050"
                    value="{{ old('cycle_year', date('Y')) }}"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                >
                <p class="mt-1 text-[11px] text-slate-400">Setiap kontak pembimbing memiliki 1 siklus follow-up per tahun berjalan.</p>
            </div>

            <!-- Buttons -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <a
                    href="{{ route($routePrefix . '.index') }}"
                    class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                >
                    Batal
                </a>
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Buka Siklus Sekarang
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function filterContactsByInstitution(selectedInstId) {
        const contactSelect = document.getElementById('institution_contact_id');
        const options = contactSelect.querySelectorAll('option');
        const warning = document.getElementById('noContactWarning');
        let visibleCount = 0;

        options.forEach(opt => {
            if (!opt.value) {
                opt.hidden = false;
                return;
            }
            const instId = opt.getAttribute('data-institution');
            if (!selectedInstId || instId === selectedInstId) {
                opt.hidden = false;
                visibleCount++;
            } else {
                opt.hidden = true;
            }
        });

        if (selectedInstId && visibleCount === 0) {
            warning.classList.remove('hidden');
        } else {
            warning.classList.add('hidden');
        }

        // Reset selection if current selected option is hidden
        const selectedOption = contactSelect.options[contactSelect.selectedIndex];
        if (selectedOption && selectedOption.hidden) {
            contactSelect.value = '';
        }
    }

    // Auto trigger on load if pre-filled
    document.addEventListener('DOMContentLoaded', function () {
        const instSelect = document.getElementById('institution_select');
        const contactSelect = document.getElementById('institution_contact_id');
        const currentContact = contactSelect.options[contactSelect.selectedIndex];
        if (currentContact && currentContact.value) {
            const instId = currentContact.getAttribute('data-institution');
            if (instId) {
                instSelect.value = instId;
                filterContactsByInstitution(instId);
            }
        }
    });
</script>
@endsection
