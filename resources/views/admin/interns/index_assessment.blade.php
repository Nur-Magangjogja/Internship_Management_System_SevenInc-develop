@extends('layouts.dashboard')

@section('content')

{{-- Modal konfirmasi hapus --}}
<div id="deleteModal" class="fixed inset-0 z-[110] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden">
            <div class="p-6 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
                    <svg class="h-7 w-7 text-[#D32F2F]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                </div>
                <h3 class="mb-2 text-[15px] font-bold text-[#1B3A34]">Hapus data penilaian?</h3>
                <p class="text-[12.5px] text-[#4B5F5A] leading-relaxed">
                    Penilaian <strong id="deleteAssessName"></strong> akan dihapus permanen.
                </p>
            </div>
            <div class="flex justify-center gap-3 border-t border-[#DCE7E1] px-5 py-4">
                <button type="button" onclick="closeDeleteModal()"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#1B3A34] hover:bg-[#F4F8F6]">
                    Batal
                </button>
                <form id="deleteForm" method="POST">
                    @csrf @method('DELETE')
                    <button type="submit"
                        class="rounded-[9px] bg-[#D32F2F] px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        Ya, Hapus
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Dokumen & Sertifikat</p>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Surat Penilaian</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Kelola data penilaian magang dan cetak surat penilaian.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.document-templates.index', ['type' => 'assessment']) }}"
               class="inline-flex items-center gap-1.5 rounded-[9px] border border-[#DCE7E1] bg-white px-3.5 py-2.5 text-[13px] font-semibold text-[#1B3A34] shadow-xs transition hover:bg-[#F4F8F6]">
                <svg class="h-4 w-4 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M3 9h18M9 21V9"/>
                </svg>
                Kelola Template Penilaian
            </a>
            <a href="{{ route('admin.document-templates.create', ['type' => 'assessment']) }}"
               class="inline-flex items-center gap-1.5 rounded-[9px] border border-[#DCE7E1] bg-white px-3.5 py-2.5 text-[13px] font-semibold text-[#1B3A34] shadow-xs transition hover:bg-[#F4F8F6]">
                <svg class="h-4 w-4 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Buat Template Penilaian
            </a>
            <form action="{{ route('interns.assessment.create') }}" method="GET" id="form-tambah-penilaian">
                <input type="hidden" name="intern_ids" id="intern_ids_input">
                <button type="button" id="btn-tambah-penilaian" onclick="submitTambahPenilaian()" disabled
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2.5 text-[13px] font-semibold text-white transition hover:bg-[#1F5F3F] disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah Penilaian
                </button>
            </form>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        {{ session('error') }}
    </div>
    @endif

    {{-- Filter & Search --}}
    <div class="mb-4 rounded-[12px] border border-[#DCE7E1] bg-white p-4 shadow-sm">
        <form method="GET" action="{{ route('interns.assessment.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div>
                <label class="mb-1 block text-[11px] font-bold text-[#4B5F5A]">Pencarian Nama / Sekolah</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari nama atau asal sekolah..."
                    class="w-full rounded-[8px] border border-[#DCE7E1] px-3 py-2 text-[13px] text-[#1B3A34] focus:border-[#2D8659] focus:outline-none focus:ring-1 focus:ring-[#2D8659]">
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-bold text-[#4B5F5A]">Brand</label>
                <select name="brand" class="w-full rounded-[8px] border border-[#DCE7E1] px-3 py-2 text-[13px] text-[#1B3A34] focus:border-[#2D8659] focus:outline-none focus:ring-1 focus:ring-[#2D8659]">
                    <option value="">Semua Brand</option>
                    @foreach($brands as $brand)
                    <option value="{{ $brand }}" {{ request('brand') == $brand ? 'selected' : '' }}>{{ $brand }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-bold text-[#4B5F5A]">Status Penilaian</label>
                <select name="status_penilaian" class="w-full rounded-[8px] border border-[#DCE7E1] px-3 py-2 text-[13px] text-[#1B3A34] focus:border-[#2D8659] focus:outline-none focus:ring-1 focus:ring-[#2D8659]">
                    <option value="">Semua Status</option>
                    <option value="sudah" {{ request('status_penilaian') == 'sudah' ? 'selected' : '' }}>Sudah Dinilai</option>
                    <option value="belum" {{ request('status_penilaian') == 'belum' ? 'selected' : '' }}>Belum Dinilai</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <label class="mb-1 block text-[11px] font-bold text-[#4B5F5A]">Divisi</label>
                    <select name="divisi" class="w-full rounded-[8px] border border-[#DCE7E1] px-3 py-2 text-[13px] text-[#1B3A34] focus:border-[#2D8659] focus:outline-none focus:ring-1 focus:ring-[#2D8659]">
                        <option value="">Semua Divisi</option>
                        @foreach($divisions as $div)
                        <option value="{{ $div }}" {{ request('divisi') == $div ? 'selected' : '' }}>{{ $div }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-[8px] bg-[#1B3A34] px-4 py-2 text-[13px] font-semibold text-white transition hover:bg-[#0F2420]">
                    Filter
                </button>
            </div>
        </form>
    </div>

    <div class="overflow-hidden rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr>
                        <th class="bg-[#1B3A34] px-4 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white w-10">
                            <input type="checkbox" id="checkAll" class="rounded border-gray-300 text-[#2D8659] focus:ring-[#2D8659]" onchange="toggleAllCheckboxes()">
                        </th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Nama / NIM</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Sekolah/Kampus</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Brand & Divisi</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white text-center">Status Pemagang</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white text-center">Nilai (Status)</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#DCE7E1]">
                    @forelse($data as $row)
                    <tr class="transition hover:bg-[#F4F8F6]">
                        <td class="px-4 py-4 text-center">
                            @if(!$row->assessment)
                                <input type="checkbox" class="intern-checkbox rounded border-gray-300 text-[#2D8659] focus:ring-[#2D8659]" value="{{ $row->id }}" onchange="updateBtnTambahPenilaian()">
                            @else
                                <span class="text-[10px] text-gray-400">✓</span>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <p class="font-semibold text-[#1B3A34]">{{ $row->fullname }}</p>
                            <p class="text-[12px] text-[#4B5F5A]">{{ $row->student_id ?? '-' }}</p>
                        </td>
                        <td class="px-5 py-4">
                            <p class="text-[13px] text-[#1B3A34]">{{ $row->institution_name ?: '-' }}</p>
                            <p class="text-[12px] text-[#4B5F5A]">{{ $row->study_program ?: '-' }}</p>
                        </td>
                        <td class="px-5 py-4">
                            <p class="text-[13px] font-medium text-[#1B3A34]">{{ $row->brandRel?->name ?? $row->brand ?? '-' }}</p>
                            @if($row->internship_interest)
                            <span class="mt-1 inline-flex items-center rounded-full bg-[#E8F5E9] px-2.5 py-0.5 text-[10px] font-semibold text-[#1F5F3F] border border-[#A5D6A7]">
                                {{ $row->internship_interest }}
                            </span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-center">
                            @if($row->internship_status == 'completed')
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 border border-blue-200">
                                    Selesai
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 border border-amber-200">
                                    Aktif
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-center">
                            @if($row->assessment)
                                @php
                                    $avg = $row->assessment->rata_rata ?? 0;
                                    $cls = $avg >= 81 ? 'text-[#388E3C]' : ($avg >= 65 ? 'text-blue-700' : ($avg >= 50 ? 'text-amber-700' : 'text-[#D32F2F]'));
                                @endphp
                                <span class="text-[14px] font-bold {{ $cls }}">{{ number_format($avg, 2) }}</span>
                                <div class="text-[10px] text-green-600 mt-1">Sudah Dinilai</div>
                            @else
                                <span class="text-[13px] text-gray-400">-</span>
                                <div class="text-[10px] text-red-500 mt-1">Belum Dinilai</div>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-1.5">
                                @if($row->assessment)

                                    
                                    @if($row->internship_status == 'completed')
                                        {{-- Preview --}}
                                        <a href="{{ route('interns.assessment.preview', $row->assessment->id) }}" target="_blank" title="Preview PDF"
                                            class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F]">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        {{-- Download PDF --}}
                                        <a href="{{ route('interns.assessment.pdf', $row->assessment->id) }}" title="Download PDF"
                                            class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F]">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        </a>
                                    @else
                                        {{-- Disabled actions for incomplete status --}}
                                        <button disabled title="Dokumen hanya untuk pemagang selesai"
                                            class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-gray-200 bg-gray-50 text-gray-400 cursor-not-allowed">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        <button disabled title="Dokumen hanya untuk pemagang selesai"
                                            class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-gray-200 bg-gray-50 text-gray-400 cursor-not-allowed">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        </button>
                                    @endif

                                    {{-- Hapus --}}
                                    <button type="button" title="Hapus"
                                        onclick="openDeleteModal('{{ route('interns.assessment.destroy', $row->assessment->id) }}', '{{ addslashes($row->fullname) }}')"
                                        class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-red-200 bg-red-50 text-[#D32F2F] transition hover:bg-red-100">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                @else
                                    <a href="{{ route('interns.assessment.create') }}?intern_ids[]={{ $row->id }}" title="Tambah Penilaian"
                                        class="flex items-center gap-1 rounded-[8px] border border-[#2D8659] px-3 py-1.5 text-[12px] font-semibold text-[#2D8659] transition hover:bg-[#2D8659] hover:text-white">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        Beri Nilai
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-sm text-[#4B5F5A]">
                            Tidak ada data pemagang yang sesuai dengan pencarian.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($data->hasPages())
        <div class="border-t border-[#DCE7E1] bg-white px-5 py-4">
            {{ $data->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>

<script>
function openDeleteModal(action, name) {
    document.getElementById('deleteAssessName').textContent = name;
    document.getElementById('deleteForm').action = action;
    document.getElementById('deleteModal').classList.remove('hidden');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

function toggleAllCheckboxes() {
    const checkAll = document.getElementById('checkAll');
    const checkboxes = document.querySelectorAll('.intern-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkAll.checked;
    });
    updateBtnTambahPenilaian();
}

function updateBtnTambahPenilaian() {
    const checkboxes = document.querySelectorAll('.intern-checkbox:checked');
    const btn = document.getElementById('btn-tambah-penilaian');
    btn.disabled = checkboxes.length === 0;
}

function submitTambahPenilaian() {
    const checkboxes = document.querySelectorAll('.intern-checkbox:checked');
    if (checkboxes.length === 0) return;
    
    // Clear and build the form inputs for intern_ids
    const form = document.getElementById('form-tambah-penilaian');
    // Remove old hidden inputs if any
    form.querySelectorAll('input[name="intern_ids[]"]').forEach(el => el.remove());
    
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'intern_ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    form.submit();
}
</script>

@endsection
