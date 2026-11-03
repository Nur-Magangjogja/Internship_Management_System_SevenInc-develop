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
    @if(session('error'))
    <div class="mb-4 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ session('error') }}</div>
    @endif
    
    <div class="rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[680px] text-left text-sm">
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
                            <div class="flex flex-wrap items-center gap-1.5 mt-0.5 text-[11px] font-mono text-slate-500">
                                <span>{{ $brand->registration_host ?: 'Host belum diatur' }}</span>
                                <span class="text-slate-300">•</span>
                                @if($brand->division_mode === \App\Models\Brand::DIVISION_MODE_GLOBAL || ($brand->division_mode === null && $brand->is_default))
                                    <span class="text-[#2D8659] font-sans font-semibold">Global</span>
                                @else
                                    <span class="text-[#2D8659] font-sans font-semibold">{{ $brand->divisions->count() }} Divisi</span>
                                @endif
                            </div>
                            @php($opBadge = $brand->operational_status_badge)
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold {{ $opBadge['class'] }}">{{ $opBadge['label'] }}</span>
                                @if($brand->registration_host)
                                    <span class="text-[10px] {{ $brand->domain_verified_at ? 'text-emerald-700' : 'text-amber-700' }}">{{ $brand->domain_verified_at ? 'Domain verified' : 'Domain belum verified' }}</span>
                                @endif
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

                                {{-- Aksi Status Aktif / Nonaktif --}}
                                @if($brand->is_default)
                                    <span class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-slate-200 bg-slate-100 text-slate-400 cursor-not-allowed" title="Brand default sistem terkunci (selalu aktif)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                        </svg>
                                    </span>
                                @elseif($brand->lifecycle_status === \App\Models\Brand::STATUS_ACTIVE)
                                    <form action="{{ route('admin.brands.suspend', $brand->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Nonaktifkan brand {{ addslashes($brand->name) }}? Host pendaftaran akan dikeluarkan sementara dari sistem.');">
                                        @csrf
                                        <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 hover:border-amber-300 transition" title="Nonaktifkan Brand (Suspend)">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10"/>
                                                <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                                            </svg>
                                        </button>
                                    </form>
                                @elseif($brand->lifecycle_status === \App\Models\Brand::STATUS_SUSPENDED)
                                    <form action="{{ route('admin.brands.unsuspend', $brand->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Aktifkan kembali brand {{ addslashes($brand->name) }}? Host pendaftaran akan kembali terhubung.');">
                                        @csrf
                                        <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#A5D6A7] bg-[#E8F5E9] text-[#1F5F3F] hover:bg-[#2D8659] hover:text-white transition" title="Aktifkan Kembali Brand (Reaktivasi)">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                                <polyline points="22 4 12 14.01 9 11.01"/>
                                            </svg>
                                        </button>
                                    </form>
                                @elseif($brand->lifecycle_status === \App\Models\Brand::STATUS_READY)
                                    <form action="{{ route('admin.brands.activate', $brand->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Aktifkan brand {{ addslashes($brand->name) }} sekarang?');">
                                        @csrf
                                        <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white transition" title="Aktifkan Brand">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polygon points="5 3 19 12 5 21 5 3"/>
                                            </svg>
                                        </button>
                                    </form>
                                @else
                                    <a href="{{ route('admin.brands.show', $brand->id) }}" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-slate-200 bg-slate-50 text-slate-400 hover:border-slate-300 hover:bg-slate-100 hover:text-slate-600 transition" title="Konfigurasi Belum Lengkap (Klik untuk periksa Domain/Spreadsheet)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="3"/>
                                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                                        </svg>
                                    </a>
                                @endif

                                <a href="{{ route('admin.brands.show', $brand->id) }}" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] text-[#1B3A34] hover:border-[#1B3A34] hover:bg-[#F4F8F6] transition" title="Lihat">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                                <a href="{{ route('admin.brands.edit', $brand->id) }}" class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#DCE7E1] text-[#2D8659] hover:border-[#2D8659] hover:bg-[#F4F8F6] transition" title="Edit">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                </a>

                                {{-- Tombol Hapus memicu Modal Konfirmasi --}}
                                <button type="button"
                                    onclick="openDeleteBrandModal('{{ $brand->id }}', '{{ addslashes($brand->name) }}', '{{ $brand->code }}', '{{ route('admin.brands.destroy', $brand->id) }}', {{ $brand->is_default ? 'true' : 'false' }})"
                                    class="flex h-8 w-8 items-center justify-center rounded-[8px] border border-red-200 text-[#D32F2F] hover:bg-red-50 hover:border-red-300 transition"
                                    title="Hapus">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
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

@push('modals')
{{-- Modal Konfirmasi Hapus Brand --}}
<div id="deleteBrandModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 backdrop-blur-[2px] p-4">
    <div class="w-full max-w-md rounded-[16px] bg-white shadow-xl overflow-hidden border border-[#DCE7E1]">
        <div class="p-6 text-center">
            {{-- Ikon Peringatan SVG Standar (Tanpa emotikon) --}}
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-[#D32F2F]">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>

            <h3 class="mb-2 text-base font-bold text-[#1B3A34]">Konfirmasi Hapus Brand</h3>
            
            <p class="mb-4 text-xs text-[#4B5F5A] leading-relaxed">
                Apakah Anda yakin ingin menghapus brand <strong id="deleteBrandName" class="text-[#1B3A34]"></strong> (<span id="deleteBrandCode" class="font-mono font-bold text-[#2D8659]"></span>)?
            </p>

            {{-- Kotak Catatan Peringatan --}}
            <div class="mb-5 rounded-[10px] border border-amber-200 bg-amber-50 p-3 text-left text-[11.5px] leading-relaxed text-amber-900">
                <div class="flex items-start gap-2">
                    <svg class="h-4 w-4 shrink-0 text-amber-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <div>
                        <p class="font-bold text-amber-950 mb-0.5">Peringatan Penting:</p>
                        <ul class="list-disc list-inside space-y-0.5 text-amber-800">
                            <li>Brand yang sudah terhubung dengan data pemagang, webinar, surat MoU, atau spreadsheet tidak dapat dihapus.</li>
                            <li>Brand default sistem tidak dapat dihapus.</li>
                            <li>Tindakan ini permanen apabila brand belum memiliki riwayat data terkait.</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Form Penghapusan & Tombol Aksi --}}
            <form id="deleteBrandForm" method="POST" action="">
                @csrf
                @method('DELETE')
                <div class="flex items-center justify-end gap-2.5 border-t border-[#DCE7E1] pt-4">
                    <button type="button" onclick="closeDeleteBrandModal()" class="rounded-[8px] border border-[#DCE7E1] bg-white px-4 py-2 text-xs font-semibold text-[#4B5F5A] hover:bg-[#F4F8F6] transition">
                        Batal
                    </button>
                    <button type="submit" id="deleteBrandSubmitBtn" class="flex items-center gap-1.5 rounded-[8px] bg-[#D32F2F] px-4 py-2 text-xs font-bold text-white hover:bg-red-700 transition shadow-xs">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Ya, Hapus Brand
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    function openDeleteBrandModal(brandId, brandName, brandCode, destroyUrl, isDefault) {
        if (isDefault) {
            alert('Brand default sistem tidak dapat dihapus.');
            return;
        }

        const modal = document.getElementById('deleteBrandModal');
        const nameEl = document.getElementById('deleteBrandName');
        const codeEl = document.getElementById('deleteBrandCode');
        const form = document.getElementById('deleteBrandForm');

        if (nameEl) nameEl.textContent = brandName;
        if (codeEl) codeEl.textContent = brandCode;
        if (form) form.action = destroyUrl;

        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    }

    function closeDeleteBrandModal() {
        const modal = document.getElementById('deleteBrandModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeDeleteBrandModal();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('deleteBrandModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeDeleteBrandModal();
                }
            });
        }
    });
</script>
@endpush
@endsection
