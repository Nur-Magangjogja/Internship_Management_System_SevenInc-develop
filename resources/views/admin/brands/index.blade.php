@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Dashboard & Monitoring</p>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Manajemen Brand</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Kelola data brand untuk sertifikat dan surat menyurat.</p>
        </div>
        <a href="{{ route('admin.brands.create') }}" class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-[13px] font-semibold text-white hover:bg-[#1F5F3F] transition shadow-sm">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Brand
        </a>
    </div>

    {{-- Alert --}}
    @if(session('success'))
    <div id="alert-success" class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
        <button onclick="document.getElementById('alert-success').remove()" class="ml-auto text-[#2D8659] hover:opacity-70">✕</button>
    </div>
    @endif
    
    <div class="rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[600px] text-left text-sm">
                <thead>
                    <tr>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Kode</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Nama Brand</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Logo</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#DCE7E1]">
                    @forelse($brands as $brand)
                    <tr class="transition hover:bg-[#F4F8F6]">
                        <td class="px-5 py-4 text-[13px] font-semibold text-[#1B3A34]">{{ $brand->code }}</td>
                        <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-[#1B3A34]">{{ $brand->name }}</span>
                                @if($brand->is_default)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 text-[#1F5F3F] border border-emerald-300 text-[10px] font-bold px-2 py-0.5">
                                        <i class="fa-solid fa-star text-amber-500 text-[9px]"></i> Default
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1.5 mt-0.5 text-[11px] font-mono text-slate-500">
                                <span>{{ $brand->registration_host }}</span>
                                <span class="text-slate-300">•</span>
                                <span class="text-[#2D8659] font-sans font-semibold">{{ $brand->divisions->count() }} Divisi</span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">
                            @if($brand->logo)
                                <img src="{{ Storage::url($brand->logo) }}" alt="Logo {{ $brand->name }}" class="h-10 object-contain">
                            @else
                                <span class="text-xs text-gray-400">Tidak ada logo</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-[13px] text-center">
                            <div class="flex items-center justify-center gap-2">
                                @if(!$brand->is_default)
                                    <form action="{{ route('admin.brands.set-default', $brand->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Jadikan brand {{ addslashes($brand->name) }} sebagai Brand Default sistem SertIF?');">
                                        @csrf
                                        <button type="submit" class="flex h-8 items-center gap-1.5 px-2.5 rounded-[8px] border border-[#DCE7E1] bg-white text-[#2D8659] text-[11px] font-semibold hover:border-[#2D8659] hover:bg-[#F4F8F6] transition shadow-2xs" title="Jadikan Brand Default">
                                            <i class="fa-regular fa-star text-amber-500 text-xs"></i> Set Default
                                        </button>
                                    </form>
                                @endif
                                <a href="{{ route('admin.brands.show', $brand->id) }}" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] text-[#1B3A34] hover:border-[#1B3A34] hover:bg-[#F4F8F6] transition" title="Lihat">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                                <a href="{{ route('admin.brands.edit', $brand->id) }}" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] text-[#2D8659] hover:border-[#2D8659] hover:bg-[#F4F8F6] transition" title="Edit">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                </a>
                                <form action="{{ route('admin.brands.destroy', $brand->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus brand ini?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-red-200 text-[#D32F2F] hover:bg-red-50 hover:border-red-300 transition" title="Hapus">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-5 py-8 text-center text-[#4B5F5A]">Belum ada data brand.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
