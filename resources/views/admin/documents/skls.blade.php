@extends('layouts.dashboard')

@section('title', 'Riwayat Unduhan SKL')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    <div class="mb-6">
        <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Dokumen & Sertifikat</p>
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Riwayat Unduhan SKL</h1>
                <p class="mt-1 text-sm text-[#4B5F5A]">Daftar pengguna yang telah mengunduh Surat Keterangan Lulus magang.</p>
            </div>
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
                    @forelse($skls as $key => $skl)
                    <tr class="transition hover:bg-[#F4F8F6]">
                        <td class="px-5 py-4 text-[13px] text-[#4B5F5A]">{{ $skls->firstItem() + $key }}</td>
                        <td class="px-5 py-4">
                            <p class="font-semibold text-[#1B3A34]">{{ $skl->user?->name ?? '-' }}</p>
                            <p class="mt-0.5 text-[11.5px] text-[#4B5F5A]">{{ $skl->user?->email ?? '' }}</p>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-[13px] text-[#4B5F5A]">
                            {{ $skl->downloaded_at?->format('d M Y, H:i') ?? '-' }}
                        </td>
                        <td class="px-5 py-4">
                            @php
                                $pipeStatus = $skl->pipeline_status ?? ($skl->status === 'success' ? 'GENERATED' : 'FAILED');
                            @endphp
                            @if($pipeStatus === 'SYNCED')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-[#E8F5E9] px-2.5 py-1 text-[11px] font-semibold text-[#2E7D32] border border-[#A5D6A7]" title="Tersinkronisasi ke Google Drive">
                                <span class="h-1.5 w-1.5 rounded-full bg-[#2E7D32]"></span>SYNCED
                            </span>
                            @elseif($pipeStatus === 'SYNC_PENDING')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 border border-amber-200" title="Menunggu sinkronisasi Drive">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>SYNC PENDING
                            </span>
                            @elseif($pipeStatus === 'GENERATED' || $skl->status === 'success')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 border border-emerald-200" title="Dokumen fisik PDF tersimpan di server">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>GENERATED
                            </span>
                            @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-[#D32F2F] border border-red-200" title="Penerbitan atau sinkronisasi gagal">
                                <span class="h-1.5 w-1.5 rounded-full bg-[#D32F2F]"></span>FAILED
                            </span>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                @if(!empty($skl->public_uuid))
                                <a href="{{ route('admin.generated-documents.download', $skl->public_uuid) }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-1.5 rounded-[8px] border border-[#DCE7E1] px-3 py-1.5 text-[12.5px] font-semibold text-[#2D8659] transition hover:bg-[#F4F8F6]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                                    Unduh SKL
                                </a>
                                @elseif($skl->file_path)
                                @if($skl->file_path === 'skl_generated' && $skl->user)
                                <a href="{{ route('admin.skl.download.for_user', $skl->user->id) }}"
                                   target="_blank"
                                    class="inline-flex items-center gap-1.5 rounded-[8px] border border-[#DCE7E1] px-3 py-1.5 text-[12.5px] font-semibold text-[#2D8659] transition hover:bg-[#F4F8F6]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                                    Lihat SKL
                                </a>
                                @else
                                @php
                                    $sklRawPath  = $skl->file_path;
                                    $sklFilename = basename(str_replace('\\', '/', $sklRawPath));
                                    if (str_contains($sklRawPath, 'documents/skl') || str_contains($sklRawPath, 'documents\\skl')) {
                                        $sklType = 'skl';
                                    } elseif (str_contains($sklRawPath, 'documents/loa') || str_contains($sklRawPath, 'documents\\loa')) {
                                        $sklType = 'loa';
                                    } else {
                                        $sklType = 'tmp';
                                    }
                                @endphp
                                <a href="{{ route('admin.documents.serve', ['type' => $sklType, 'filename' => $sklFilename]) }}"
                                   target="_blank"
                                    class="inline-flex items-center gap-1.5 rounded-[8px] border border-[#DCE7E1] px-3 py-1.5 text-[12.5px] font-semibold text-[#2D8659] transition hover:bg-[#F4F8F6]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                                    Lihat SKL
                                </a>
                                @endif
                                @else
                                <span class="text-[12.5px] text-[#4B5F5A]">-</span>
                                @endif

                                @if(!empty($skl->drive_web_view_link))
                                <a href="{{ $skl->drive_web_view_link }}" target="_blank"
                                   title="Buka di Google Drive"
                                   class="inline-flex items-center gap-1 rounded-[8px] border border-blue-200 bg-blue-50 px-2 py-1.5 text-[11.5px] font-semibold text-blue-700 hover:bg-blue-100">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                    Drive
                                </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-[#4B5F5A]">
                            Belum ada riwayat unduhan SKL.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-[#DCE7E1] px-5 py-4">
            {{ $skls->links() }}
        </div>
    </div>
</div>
@endsection
