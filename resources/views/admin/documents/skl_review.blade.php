@extends('layouts.dashboard', ['title' => 'Review & Pratinjau SKL'])

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">
    <!-- Top Action Bar (No-Print) -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between no-print">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.interns.pemagang') }}"
               class="inline-flex h-9 w-9 items-center justify-center rounded-[9px] border border-[#DCE7E1] bg-white text-[#4B5F5A] shadow-sm transition hover:bg-[#E8F5E9] hover:text-[#1F5F3F]"
               title="Kembali ke Data Pemagang">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
            </a>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold tracking-tight text-[#1B3A34]">
                        Review Dokumen SKL
                    </h1>
                    <span class="inline-flex items-center rounded-full bg-[#E8F5E9] px-2.5 py-0.5 text-xs font-semibold text-[#1F5F3F] border border-[#A5D6A7]">
                        {{ $intern->fullname }}
                    </span>
                    <span class="rounded bg-white border border-[#DCE7E1] px-2 py-0.5 text-xs text-[#4B5F5A]">
                        {{ $intern->division?->name ?? $intern->internship_interest ?? '-' }}
                    </span>
                </div>
                <p class="mt-0.5 text-xs text-[#4B5F5A]">
                    Tinjau isi Surat Keterangan Selesai Magang (SKL) sebelum mengunduh berkas PDF resmi.
                </p>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap items-center gap-2.5">
            <button type="button"
                    onclick="document.getElementById('sklIframe').contentWindow.print()"
                    class="inline-flex items-center gap-2 rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-xs font-semibold text-[#1B3A34] shadow-sm transition hover:bg-[#F4F8F6]">
                <svg class="h-4 w-4 text-[#4B5F5A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                Cetak / Print
            </button>

            <!-- Tombol Perbarui & Render Ulang (Force Re-generate / Revisi) -->
            <button type="button"
                    onclick="openRegenModal()"
                    class="inline-flex items-center gap-2 rounded-[9px] border border-blue-200 bg-blue-50 px-4 py-2 text-xs font-semibold text-blue-700 shadow-sm transition hover:bg-blue-100 hover:text-blue-800">
                <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                </svg>
                Perbarui & Render Ulang
            </button>

            @if($intern->user_id)
            <a href="{{ route('admin.skl.download.for_user', ['user' => $intern->user_id]) }}"
               class="inline-flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-[#1F5F3F]">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Download PDF Resmi
            </a>
            @endif
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <div id="dynamic-alert" class="hidden mb-4 rounded-[10px] p-4 text-sm font-semibold"></div>

    @if(session('success'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        {{ session('error') }}
    </div>
    @endif

    <!-- Preview Container (A4 sheet styling) -->
    <div class="flex justify-center">
        <div class="w-full max-w-[215mm] overflow-hidden rounded-[12px] border border-[#DCE7E1] bg-white shadow-lg">
            <iframe id="sklIframe"
                    src="{{ route('admin.skl.raw', $intern->id) }}"
                    class="w-full border-0"
                    style="height: 1160px; min-height: 1120px;"
                    onload="try { const h = this.contentWindow.document.body.scrollHeight; if(h > 400) this.style.height = (h + 30) + 'px'; } catch(e){}"
                    title="Pratinjau SKL">
            </iframe>
        </div>
    </div>
</div>

<!-- Modal Konfirmasi Render Ulang / Revisi SKL -->
<div id="regenModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs hidden">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl transition-all">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-[#1B3A34]">Perbarui Dokumen SKL</h3>
                    <p class="text-xs text-[#4B5F5A]">Render ulang PDF dengan data profil & aset terbaru</p>
                </div>
            </div>
            <button type="button" onclick="closeRegenModal()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="form-regen" action="{{ route('admin.skl.regenerate', $intern->id) }}" method="POST" class="mt-5 space-y-4">
            @csrf
            <input type="hidden" name="force_refresh" value="1">

            <div class="space-y-2.5 rounded-xl border border-[#DCE7E1] bg-[#F4F8F6] p-3.5">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="radio" name="is_revision" value="0" checked class="mt-0.5 text-[#2D8659] focus:ring-[#2D8659]">
                    <div class="text-xs">
                        <span class="font-bold text-[#1B3A34]">Perbaiki Dokumen (Nomor Tetap)</span>
                        <p class="text-[#4B5F5A] mt-0.5">Merender ulang PDF dengan data profil/aset terkini, menimpa berkas lama di Google Drive tanpa mengubah nomor surat.</p>
                    </div>
                </label>
                <div class="border-t border-[#DCE7E1]"></div>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="radio" name="is_revision" value="1" class="mt-0.5 text-[#2D8659] focus:ring-[#2D8659]">
                    <div class="text-xs">
                        <span class="font-bold text-[#1B3A34]">Terbitkan Revisi Resmi (/REV)</span>
                        <p class="text-[#4B5F5A] mt-0.5">Membuat versi revisi baru resmi (misal: /REV.2), mencatat snapshot audit, dan memperbarui berkas di Drive.</p>
                    </div>
                </label>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-2">
                <button type="button" onclick="closeRegenModal()" class="rounded-[9px] border border-[#DCE7E1] px-4 py-2 text-xs font-semibold text-[#4B5F5A] hover:bg-[#F4F8F6]">
                    Batal
                </button>
                <button id="btn-submit-regen" type="submit" class="inline-flex items-center gap-2 rounded-[9px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700">
                    <span id="btn-regen-text">Render & Sinkronkan</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRegenModal() {
    document.getElementById('regenModal').classList.remove('hidden');
}

function closeRegenModal() {
    document.getElementById('regenModal').classList.add('hidden');
}

document.getElementById('form-regen')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-submit-regen');
    const btnText = document.getElementById('btn-regen-text');
    const origText = btnText.textContent;
    btn.disabled = true;
    btnText.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg> Merender PDF & Google Drive...</span>';

    try {
        const formData = new FormData(this);
        const res = await fetch(this.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const json = await res.json();
        if (json.ok) {
            closeRegenModal();
            // Refresh iframe dengan timestamp agar fresh
            const iframe = document.getElementById('sklIframe');
            iframe.src = "{{ route('admin.skl.raw', $intern->id) }}?t=" + Date.now();

            // Tampilkan alert sukses
            const alertBox = document.getElementById('dynamic-alert');
            alertBox.className = 'mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]';
            alertBox.innerHTML = '<svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>' + (json.message || 'Dokumen SKL berhasil dirender ulang!');
            alertBox.classList.remove('hidden');
        } else {
            alert(json.error || 'Terjadi kesalahan saat memproses dokumen.');
        }
    } catch (err) {
        // Fallback ke submission biasa jika fetch gagal
        this.submit();
        return;
    } finally {
        btn.disabled = false;
        btnText.textContent = origText;
    }
});
</script>
</div>
@endsection
