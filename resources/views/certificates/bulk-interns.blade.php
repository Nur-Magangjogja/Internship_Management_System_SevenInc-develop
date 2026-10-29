@extends('layouts.dashboard')

@section('title', 'Penerbitan Sertifikat Pemagang')

@section('content')
<div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-[#1B3A34]">Penerbitan Sertifikat Pemagang</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Pilih pemagang berstatus selesai untuk menerbitkan sertifikat. Nomor sertifikat menggunakan kode resmi brand pemagang.</p>
        </div>
        <a href="{{ route('admin.certificate.index') }}" class="inline-flex items-center rounded-lg border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#1B3A34] hover:bg-[#F4F8F6]">
            Kembali ke Sertifikat
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.certificate.bulk.interns.store') }}" class="space-y-4">
        @csrf
        <div class="overflow-hidden rounded-xl border border-[#DCE7E1] bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] text-left text-sm">
                    <thead class="bg-[#1B3A34] text-white">
                        <tr>
                            <th class="px-4 py-3"><input id="selectAllInterns" type="checkbox" aria-label="Pilih semua"></th>
                            <th class="px-4 py-3">Nama Pemagang</th>
                            <th class="px-4 py-3">Brand</th>
                            <th class="px-4 py-3">Divisi</th>
                            <th class="px-4 py-3">Tanggal Selesai</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#DCE7E1]">
                        @forelse($interns as $intern)
                            <tr class="hover:bg-[#F8FAF9]">
                                <td class="px-4 py-3">
                                    <input class="intern-checkbox rounded border-[#DCE7E1] text-[#2D8659] focus:ring-[#2D8659]" type="checkbox" name="intern_ids[]" value="{{ $intern->id }}" @checked(in_array($intern->id, old('intern_ids', [])))>
                                </td>
                                <td class="px-4 py-3 font-medium text-[#1B3A34]">{{ $intern->fullname ?? $intern->user?->name ?? '-' }}</td>
                                <td class="px-4 py-3 text-[#4B5F5A]">
                                    @php
                                        $internBrand = $intern->brandRel ?? ($intern->brand_id ? \App\Models\Brand::find($intern->brand_id) : null) ?? \App\Models\Brand::getDefaultBrand();
                                    @endphp
                                    <div class="flex items-center gap-2">
                                        <span>{{ $internBrand?->name ?? $intern->brand ?? 'Belum ditentukan' }}</span>
                                        @if($internBrand && !$internBrand->isProfileCompleteForDocuments())
                                            <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-800" title="Data penandatangan brand belum lengkap">
                                                <i class="fa-solid fa-triangle-exclamation text-amber-600 text-[9px]"></i> Profil Belum Lengkap
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-[#4B5F5A]">{{ $intern->division?->name ?? $intern->internship_interest ?? '-' }}</td>
                                <td class="px-4 py-3 text-[#4B5F5A]">
                                    {{ $intern->end_date ? \Illuminate\Support\Carbon::parse($intern->end_date)->format('d M Y') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center text-[#4B5F5A]">Belum ada pemagang berstatus selesai.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-[#4B5F5A]">Periksa kembali brand dan divisi sebelum menerbitkan sertifikat.</p>
            <button type="submit" class="inline-flex items-center rounded-lg bg-[#2D8659] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#1F5F3F]">
                Terbitkan Sertifikat Terpilih
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('selectAllInterns')?.addEventListener('change', function () {
    document.querySelectorAll('.intern-checkbox').forEach((checkbox) => {
        checkbox.checked = this.checked;
    });
});
</script>
@endsection
