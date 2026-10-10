@extends('layouts.dashboard', ['title' => 'Buat Dokumen MOU Baru'])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.mous' : 'admin.mous';
@endphp

@section('content')
<div class="px-6 py-8 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route($routePrefix . '.index') }}" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                    &larr; Kembali ke Daftar Dokumen
                </a>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Buat Dokumen MOU & PKS Baru</h1>
            <p class="text-sm text-slate-500">Isi parameter perjanjian kerja sama institusi untuk menghasilkan naskah dokumen resmi.</p>
        </div>
        <div class="text-right">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm">
                <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                Template: {{ $activeTemplate ? $activeTemplate->name . ' (v' . $activeTemplate->version_number . ')' : 'Standar' }}
            </span>
        </div>
    </div>

    <!-- Error Alert -->
    @if($errors->any())
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <div class="font-semibold mb-1">Terdapat kesalahan pengisian data:</div>
            <ul class="list-disc pl-5 space-y-1 text-xs">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Warning jika institusi sudah memiliki MOU Aktif -->
    @if($activeMou)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>
                    <h3 class="font-bold text-amber-800">Institusi Ini Telah Memiliki MOU Aktif</h3>
                    <p class="text-xs text-amber-700 mt-1">
                        Institusi <strong>{{ $selectedInstitution->name }}</strong> saat ini memiliki dokumen MOU aktif dengan nomor <strong>{{ $activeMou->company_mou_number }}</strong> (berlaku s/d {{ \Carbon\Carbon::parse($activeMou->effective_end)->isoFormat('D MMMM Y') }}).
                    </p>
                    <p class="text-xs text-amber-700 mt-1">
                        Jika naskah baru ini dibuat untuk memperpanjang masa berlaku atau menggantikan perjanjian lama, Anda dapat menetapkan dokumen lama sebagai <em>superseded</em> (terganti) saat dokumen baru disahkan.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route($routePrefix . '.store') }}" class="space-y-6">
        @csrf

        @if($activeTemplate)
            <input type="hidden" name="mou_template_id" value="{{ $activeTemplate->id }}">
        @endif
        @if($activeMou)
            <input type="hidden" name="supersedes_mou_id" value="{{ $activeMou->id }}">
        @endif

        <!-- Card 1: Pilihan Institusi & Nomor Dokumen -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-700 mb-4 pb-2 border-b border-slate-100">
                1. Registrasi Dokumen & Institusi Mitra
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Pilih Institusi Mitra <span class="text-rose-500">*</span>
                    </label>
                    <select
                        name="institution_id"
                        id="institutionSelect"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                        onchange="handleInstitutionChange(this.value)"
                    >
                        <option value="">-- Pilih Institusi Pendidikan --</option>
                        @foreach($institutions as $inst)
                            <option
                                value="{{ $inst->id }}"
                                {{ old('institution_id', $selectedInstitution?->id) == $inst->id ? 'selected' : '' }}
                            >
                                {{ $inst->name }} ({{ $inst->city?->name ?? 'Indonesia' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                @if($selectedInstitution)
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">
                            Siklus Follow-up Acuan (Hasil Bersedia) <span class="text-rose-500">*</span>
                        </label>
                        @if($eligibleCycles->isEmpty())
                            <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
                                <div class="font-semibold mb-0.5">Tidak Ditemukan Siklus Follow-up Bersedia (Willing)</div>
                                <p class="text-slate-600">
                                    Institusi ini belum memiliki siklus follow-up yang berstatus 'Selesai' dengan hasil bersedia kerja sama ('willing'). Sesuai aturan bisnis, dokumen MOU hanya dapat diterbitkan setelah institusi menyatakan kesediaan kerja sama melalui siklus follow-up resmi.
                                </p>
                            </div>
                        @elseif($eligibleCycles->count() === 1)
                            @php $singleCycle = $eligibleCycles->first(); @endphp
                            <div class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs text-emerald-800">
                                <div>
                                    <span class="font-bold">Periode Siklus: Tahun {{ $singleCycle->cycle_year }}</span>
                                    <span class="mx-2 text-emerald-300">|</span>
                                    <span>Status: Selesai (Bersedia / Willing)</span>
                                    @if($singleCycle->contact)
                                        <span class="mx-2 text-emerald-300">|</span>
                                        <span>PIC Narahubung: {{ $singleCycle->contact->name }} ({{ $singleCycle->contact->position_title }})</span>
                                    @endif
                                </div>
                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700">
                                    Prasyarat Terpenuhi
                                </span>
                            </div>
                            <input type="hidden" name="followup_cycle_id" value="{{ $singleCycle->id }}">
                        @else
                            <select
                                name="followup_cycle_id"
                                required
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                            >
                                <option value="">-- Pilih Siklus Follow-up Selesai --</option>
                                @foreach($eligibleCycles as $cycleOption)
                                    <option
                                        value="{{ $cycleOption->id }}"
                                        {{ old('followup_cycle_id', $selectedCycle?->id) == $cycleOption->id ? 'selected' : '' }}
                                    >
                                        Periode Tahun {{ $cycleOption->cycle_year }} &bull; PIC: {{ $cycleOption->contact?->name ?? 'Narahubung' }} ({{ $cycleOption->contact?->position_title ?? 'Institusi' }})
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Nomor MOU Seven Inc (Pihak Kedua) <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="company_mou_number"
                        value="{{ old('company_mou_number', $suggestedNumber) }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono focus:border-blue-500 focus:outline-none"
                    >
                    <p class="text-[11px] text-slate-400 mt-1">Harus unik dan terdaftar di buku penomoran surat keluar Seven Inc.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Nomor MOU Institusi (Pihak Pertama) <span class="text-slate-400 font-normal">(Opsional)</span>
                    </label>
                    <input
                        type="text"
                        name="institution_mou_number"
                        value="{{ old('institution_mou_number') }}"
                        placeholder="Contoh: 045/UNIV/MOU/2026"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono focus:border-blue-500 focus:outline-none"
                    >
                    <p class="text-[11px] text-slate-400 mt-1">Dapat dikosongkan atau dilengkapi saat naskah bertandatangan diunggah.</p>
                </div>
            </div>
        </div>

        <!-- Card 2: Pihak Pertama (Institusi Mitra) -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-700 mb-4 pb-2 border-b border-slate-100">
                2. Data Pihak Pertama (Institusi Mitra)
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Nama Resmi Institusi <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="first_party_institution_name"
                        id="firstPartyName"
                        value="{{ old('first_party_institution_name', $selectedInstitution?->name) }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Unit Korespondensi / Fakultas / Jurusan
                    </label>
                    <input
                        type="text"
                        name="first_party_correspondence_unit"
                        id="firstPartyUnit"
                        value="{{ old('first_party_correspondence_unit', $selectedInstitution?->correspondence_unit) }}"
                        placeholder="Contoh: Fakultas Ilmu Komputer"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Nama Pejabat Penandatangan Institusi <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="first_party_signatory_name"
                        id="firstPartySignatory"
                        value="{{ old('first_party_signatory_name', $selectedInstitution?->contacts->first()?->name ?? '') }}"
                        required
                        placeholder="Contoh: Prof. Dr. Ir. Sutrisno, M.T."
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Jabatan Penandatangan <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="first_party_signatory_title"
                        id="firstPartyTitle"
                        value="{{ old('first_party_signatory_title', $selectedInstitution?->contacts->first()?->position_title ?? 'Dekan / Kepala Sekolah') }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Alamat Lengkap Institusi
                    </label>
                    <textarea
                        name="first_party_address"
                        id="firstPartyAddress"
                        rows="2"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >{{ old('first_party_address', $selectedInstitution?->address) }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Nomor Telepon Institusi</label>
                    <input
                        type="text"
                        name="first_party_phone"
                        id="firstPartyPhone"
                        value="{{ old('first_party_phone', $selectedInstitution?->phone) }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Email Resmi Institusi</label>
                    <input
                        type="email"
                        name="first_party_email"
                        id="firstPartyEmail"
                        value="{{ old('first_party_email', $selectedInstitution?->email) }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>
        </div>

        <!-- Card 3: Pihak Kedua (Seven Inc) -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-700 mb-4 pb-2 border-b border-slate-100">
                3. Data Pihak Kedua (Seven Inc)
            </h2>

            @php
                $tplDefaults = $activeTemplate?->default_values ?? [];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Nama Perusahaan <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="second_party_company_name"
                        value="{{ old('second_party_company_name', $tplDefaults['company_name'] ?? 'SEVEN INC (PT Tujuh Kreasi Digital)') }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Nama Pimpinan Penandatangan <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="second_party_signatory_name"
                        value="{{ old('second_party_signatory_name', $tplDefaults['company_signatory_name'] ?? 'Rekso Pamungkas') }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Jabatan Pimpinan <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="second_party_signatory_title"
                        value="{{ old('second_party_signatory_title', $tplDefaults['company_signatory_title'] ?? 'Direktur Utama') }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Email Perusahaan</label>
                    <input
                        type="email"
                        name="second_party_email"
                        value="{{ old('second_party_email', $tplDefaults['company_email'] ?? 'hr@seveninc.com') }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Alamat Kantor Seven Inc</label>
                    <input
                        type="text"
                        name="second_party_address"
                        value="{{ old('second_party_address', $tplDefaults['company_address'] ?? 'Yogyakarta, Indonesia') }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>
            </div>
        </div>

        <!-- Card 4: Tempat, Tanggal, & Masa Berlaku -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-700 mb-4 pb-2 border-b border-slate-100">
                4. Tempat, Waktu, & Masa Berlaku Perjanjian
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Tempat Penandatanganan <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="agreement_place"
                        value="{{ old('agreement_place', $tplDefaults['agreement_place'] ?? 'Yogyakarta') }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Tanggal Penandatanganan <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="date"
                        name="agreement_date"
                        value="{{ old('agreement_date', date('Y-m-d')) }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Tanggal Mulai Berlaku <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="date"
                        name="effective_start"
                        value="{{ old('effective_start', date('Y-m-d')) }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Tanggal Selesai Berlaku <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="date"
                        name="effective_end"
                        value="{{ old('effective_end', date('Y-m-d', strtotime('+2 years'))) }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div class="md:col-span-4">
                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                        Catatan Internal / Klausul Tambahan
                    </label>
                    <textarea
                        name="notes"
                        rows="2"
                        placeholder="Catatan khusus kesepakatan atau hasil follow-up..."
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                    >{{ old('notes') }}</textarea>
                </div>

                @if($activeMou)
                    <div class="md:col-span-4 bg-slate-50 border border-slate-200 rounded-lg p-3">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Alasan Pergantian MOU Lama (Supersede Reason)</label>
                        <input
                            type="text"
                            name="supersede_reason"
                            value="{{ old('supersede_reason', 'Perpanjangan masa berlaku kerja sama kemitraan periode baru.') }}"
                            placeholder="Alasan pergantian dokumen..."
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                @endif
            </div>
        </div>

        <!-- Konfirmasi Kesediaan Kerjasama -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start gap-3">
                <input
                    type="checkbox"
                    name="cooperation_confirmed"
                    id="cooperationConfirmed"
                    value="1"
                    {{ old('cooperation_confirmed', '1') == '1' ? 'checked' : '' }}
                    class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 mt-0.5"
                >
                <div>
                    <label for="cooperationConfirmed" class="text-xs font-bold text-slate-800 cursor-pointer">
                        Konfirmasi Kesediaan Kerjasama (Cooperation Confirmation)
                    </label>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Saya mengonfirmasi bahwa mitra institusi pendidikan ini telah menyatakan kesediaan untuk bekerja sama menyelenggarakan program magang dan parameter dokumen di atas telah sesuai dengan hasil koordinasi.
                    </p>
                </div>
            </div>
        </div>

        <!-- Submit & Action Buttons -->
        <div class="flex items-center justify-end gap-3 pt-4">
            <a
                href="{{ route($routePrefix . '.index') }}"
                class="rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                Batal
            </a>
            @php $isBlocked = $selectedInstitution && $eligibleCycles->isEmpty(); @endphp
            <button
                type="submit"
                @if($isBlocked) disabled @endif
                class="inline-flex items-center gap-2 rounded-lg {{ $isBlocked ? 'bg-slate-400 cursor-not-allowed text-white' : 'bg-blue-600 hover:bg-blue-700 text-white' }} px-6 py-2.5 text-xs font-semibold shadow-sm transition"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Generate Dokumen MOU
            </button>
        </div>
    </form>
</div>

<script>
function handleInstitutionChange(institutionId) {
    if (!institutionId) return;
    const url = new URL(window.location.href);
    url.searchParams.set('institution_id', institutionId);
    window.location.href = url.toString();
}
</script>
@endsection
