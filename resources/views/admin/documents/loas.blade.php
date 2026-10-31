@extends('layouts.dashboard')

@section('title', 'Riwayat Unduhan LOA')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Dokumen & Sertifikat</p>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Riwayat Unduhan LOA</h1>
            <p class="mt-1 text-sm text-[#4B5F5A]">Daftar dokumen Letter of Acceptance (LOA) untuk pemagang yang telah diterima.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.document-templates.index', ['type' => 'loa']) }}"
               class="inline-flex items-center gap-1.5 rounded-[9px] border border-[#DCE7E1] bg-white px-3.5 py-2 text-xs font-semibold text-[#1B3A34] shadow-xs transition hover:bg-[#F4F8F6]">
                <svg class="h-3.5 w-3.5 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M3 9h18M9 21V9"/>
                </svg>
                Kelola Template LoA
            </a>
            <a href="{{ route('admin.document-templates.create', ['type' => 'loa']) }}"
               class="inline-flex items-center gap-1.5 rounded-[9px] bg-[#2D8659] px-3.5 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-[#1F5F3F]">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Buat Template LoA
            </a>
            @if(!empty($activeLoaTemplate))
            <a href="{{ route('admin.document-templates.edit', $activeLoaTemplate) }}"
               class="inline-flex items-center gap-1.5 rounded-[9px] border border-blue-200 bg-blue-50 px-3.5 py-2 text-xs font-semibold text-blue-700 shadow-xs transition hover:bg-blue-100">
                <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Edit Template Aktif (v{{ $activeLoaTemplate->version_number }})
            </a>
            @endif
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
    </div>
    @endif

    <div class="overflow-hidden rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[600px] text-left text-sm">
                <thead>
                    <tr>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">No.</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Nama Pengguna</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Tanggal Unduhan</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Status</th>
                        <th class="bg-[#1B3A34] px-5 py-3 text-[11px] font-bold uppercase tracking-[0.06em] text-white">Berkas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#DCE7E1]">
                    @forelse($loas as $key => $loa)
                    <tr class="transition hover:bg-[#F4F8F6]">
                        <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">{{ $loas->firstItem() + $key }}</td>
                        <td class="px-5 py-4">
                            <p class="font-semibold text-[#1B3A34]">{{ $loa->fullname ?? $loa->user?->name ?? '-' }}</p>
                            <p class="mt-0.5 text-[11.5px] text-[#4B5F5A]">{{ $loa->user?->email ?? '-' }}</p>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-[13px] text-[#4B5F5A]">
                            {{ $loa->downloaded_at?->format('d M Y, H:i') ?? '-' }}
                        </td>
                        <td class="px-5 py-4">
                            @if($loa->status === 'issued' || ($loa->is_issued ?? false) || ($loa->status === 'success' && !empty($loa->file_path)))
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-[#E8F5E9] px-2.5 py-1 text-[11px] font-semibold text-[#388E3C] border border-[#A5D6A7]">
                                <span class="h-1.5 w-1.5 rounded-full bg-[#388E3C]"></span>Terbit
                            </span>
                            @elseif($loa->status === 'eligible')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 border border-blue-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-blue-600"></span>Siap Dibuat
                            </span>
                            @else
                            <span class="text-[12px] text-[#4B5F5A] font-medium">Belum Memenuhi Syarat</span>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('admin.loa.review', $loa->intern_id) }}"
                                   class="inline-flex items-center gap-1.5 rounded-[8px] border border-blue-200 bg-blue-50 px-3 py-1.5 text-[12.5px] font-semibold text-blue-700 transition hover:bg-blue-100">
                                    <svg class="h-3.5 w-3.5 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                    Review & Perbarui
                                </a>
                            @if(!empty($loa->file_path))
                                @if($loa->file_path === 'loa_generated')
                                <a href="{{ route('admin.loa.download', $loa->intern_id) }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-1.5 rounded-[8px] border border-[#DCE7E1] px-3 py-1.5 text-[12.5px] font-semibold text-[#2D8659] transition hover:bg-[#F4F8F6]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                                    Lihat LOA
                                </a>
                                @else
                                @php
                                    // Parse filename dari file_path: "documents/loa/LOA-xxx.pdf" → type=loa, filename=LOA-xxx.pdf
                                    $loaParts    = explode('/', str_replace('\\', '/', $loa->file_path));
                                    $loaFilename = end($loaParts);
                                    $loaType     = count($loaParts) >= 2 ? $loaParts[count($loaParts)-2] : 'loa';
                                @endphp
                                <a href="{{ route('admin.documents.serve', ['type' => $loaType, 'filename' => $loaFilename]) }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-1.5 rounded-[8px] border border-[#DCE7E1] px-3 py-1.5 text-[12.5px] font-semibold text-[#2D8659] transition hover:bg-[#F4F8F6]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                                    Lihat LOA
                                </a>
                                @endif
                            @elseif(!empty($loa->file_url))
                            <a href="{{ $loa->file_url }}" target="_blank"
                                class="inline-flex items-center gap-1.5 rounded-[8px] border border-[#DCE7E1] px-3 py-1.5 text-[12.5px] font-semibold text-[#2D8659] transition hover:bg-[#F4F8F6]">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                                Lihat LOA
                            </a>
                            @else
                            <span class="text-[12px] text-slate-400 italic">Belum Diterbitkan</span>
                            @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-[#4B5F5A]">
                            Belum ada data pemagang yang diterima atau riwayat unduhan LOA.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-[#DCE7E1] px-5 py-4">
            {{ $loas->links() }}
        </div>
    </div>
</div>
@endsection
