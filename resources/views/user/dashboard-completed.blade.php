@extends('layouts.dashboard')

@section('title', 'Riwayat Magang')

@section('content')

@php
  /** @var \App\Models\User $user */
  $user = auth()->user();

  $reg = $user->internshipRegistration ?? null; // ✅ Define $reg first

  $canDownload = ($user->role === 'pemagang'
      && $reg
      && strtolower((string)$reg->internship_status) === 'completed');

  $intern = $reg;
  $download = null;

  if ($canDownload && $intern) {
      $download = \App\Models\AlumniMembercard::where('intern_id', $intern->id)->first();
  }

  $internships = $internships
      ?? (method_exists($user, 'internshipRegistrations')
          ? $user->internshipRegistrations()->latest('id')->paginate(10)
          : collect());
@endphp


<div class="min-h-[90vh] py-12 bg-gradient-to-b from-primary-200 to-primary-100">
  <div class="max-w-6xl mx-auto bg-white shadow-xl rounded-2xl p-8 border border-primary-100">

    {{-- TOAST MESSAGES --}}
    @if(session('success'))
      <div id="toast-msg" class="mb-6 flex justify-center">
        <div class="flex items-center p-4 text-gray-700 bg-white rounded-xl shadow-md ring-1 ring-primary-100">
          <svg class="w-5 h-5 mr-2 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
            <path d="M16.707 5.293a1 1 0 0 0-1.414-1.414L8 11.172 4.707 7.879A1 1 0 0 0 3.293 9.293l4 4a1 1 0 0 0 1.414 0l8-8Z"/>
          </svg>
          <div class="text-sm font-medium">{{ session('success') }}</div>
          <button type="button" class="ml-3 text-gray-500 hover:text-primary-700" data-dismiss-target="#toast-msg">✕</button>
        </div>
      </div>
    @endif

    {{-- TOAST FILES --}}
    @if(session('loa_url') || session('skl_url'))
      <div id="toast-file" class="mb-6 flex justify-center">
        <div class="flex items-center p-4 bg-white rounded-xl shadow-md ring-1 ring-primary-100">
          <svg class="w-5 h-5 text-primary-600 mr-3" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
            <path d="M5 4a2 2 0 0 1 2-2h6l6 6v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4z"/><path d="M13 2v4a2 2 0 0 0 2 2h4"/>
          </svg>
          <div class="text-sm">
            @if(session('skl_url'))
              <a href="{{ session('skl_url') }}" target="_blank" class="font-semibold text-primary-700 hover:underline">Lihat SKL</a>
            @endif
            @if(session('skl_url') && session('loa_url')) <span class="mx-2 text-gray-400">•</span> @endif
            @if(session('loa_url'))
              <a href="{{ session('loa_url') }}" target="_blank" class="font-semibold text-primary-700 hover:underline">Lihat LOA</a>
            @endif
          </div>
          <button type="button" class="ml-3 text-gray-500 hover:text-primary-700" data-dismiss-target="#toast-file">✕</button>
        </div>
      </div>
    @endif

    {{-- SECTION STATUS COMPLETED --}}
    @if($user->role === 'pemagang' && $reg && strtolower((string)$reg->internship_status) === 'completed')
      <div class="bg-gradient-to-r from-primary-50 to-primary-100 border border-primary-200 rounded-2xl p-8 shadow-inner">
        <h2 class="text-2xl font-bold text-primary-800 mb-3">🎉 Magang Selesai</h2>
        <p class="text-gray-700 mb-6">Selamat! Masa magangmu telah <strong>berakhir dengan sukses</strong>. Kamu dapat mengunduh SKL dan membuat LOA sebagai bukti kegiatan magang.</p>

        {{-- RINGKASAN --}}
        <div class="bg-white rounded-xl border border-primary-200 p-6 shadow-sm mb-8">
          <h3 class="text-lg font-semibold text-primary-900 mb-4">📋 Ringkasan Peserta</h3>
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-6 text-sm text-zinc-700">
            <div>
              <dt class="text-zinc-500">Nama</dt>
              <dd class="font-medium">{{ $reg->fullname ?? $user->name }}</dd>
            </div>
            <div>
              <dt class="text-zinc-500">Email</dt>
              <dd class="font-medium">{{ $reg->email ?? $user->email }}</dd>
            </div>
            <div>
              <dt class="text-zinc-500">Institusi</dt>
              <dd class="font-medium">{{ $reg->institution_name ?? '-' }}</dd>
            </div>
            <div>
              <dt class="text-zinc-500">Program Studi</dt>
              <dd class="font-medium">{{ $reg->study_program ?? '-' }}</dd>
            </div>
            <div>
              <dt class="text-zinc-500">Periode</dt>
              <dd class="font-medium">
                @php
                  $sd = $reg?->start_date ? \Carbon\Carbon::parse($reg->start_date)->isoFormat('D MMM Y') : null;
                  $ed = $reg?->end_date ? \Carbon\Carbon::parse($reg->end_date)->isoFormat('D MMM Y') : null;
                @endphp
                {{ $sd && $ed ? "$sd – $ed" : '-' }}
              </dd>
            </div>
            <div>
              <dt class="text-zinc-500">Status</dt>
              <dd>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-primary-50 text-primary-700 ring-1 ring-primary-200">COMPLETED</span>
              </dd>
            </div>
          </dl>
        </div>

        {{-- <!-- Generate LOA -->
        <div class="bg-white border border-primary-200 rounded-2xl p-6 shadow-sm mb-8">
          <h3 class="text-lg font-semibold text-primary-900 mb-4">📄 Formulir Pembuatan LOA</h3>
          <p class="text-sm text-gray-600 mb-6">
            Isi tabel berikut untuk mendeskripsikan kegiatan atau capaian selama magang.  
            Kamu bisa menambahkan baris sesuai kebutuhan, lalu tekan tombol <strong>“Preview”</strong> untuk melihat hasilnya sebelum Generate PDF.
          </p>

          <!-- Form LOA -->
          <form id="loa-form" action="{{ route('user.loa.generate') }}" method="POST" data-no-loading="true" class="space-y-4">
            @csrf
            <input type="hidden" name="intern_id" value="{{ $reg->id }}">

            <!-- Wrapper dinamis untuk baris -->
            <div id="loa-rows" class="space-y-3">
              <!-- Baris awal -->
              <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1fr_1fr_1fr_1fr] gap-3 items-center loa-row">
                <!-- Kolom Nama Siswa -->
                <input name="loa_nama_siswa[]" type="text"
                      class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                      placeholder="Nama Siswa">

                <!-- Kolom NIM/NIS -->
                <input name="loa_nim_nis[]" type="text"
                      class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                      placeholder="NIM/NIS">

                <!-- Kolom Jurusan -->
                <input name="loa_jurusan[]" type="text"
                      class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                      placeholder="Jurusan">

                <!-- Kolom Instansi -->
                <input name="loa_instansi[]" type="text"
                      class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                      placeholder="Instansi">

                <!-- Kolom Periode Magang -->
                <input name="loa_periode[]" type="text"
                      class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                      placeholder="Periode Magang">

                <!-- Kolom Kontak -->
                <input name="loa_kontak[]" type="text"
                      class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                      placeholder="Kontak">

                <button type="button"
                        class="delete-loa-row text-red-600 hover:text-red-800 text-xs font-medium flex items-center gap-1 transition">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                  Hapus
                </button>
              </div>
            </div>

            <!-- Tombol tambah baris -->
            <div class="flex justify-end mt-2 gap-3">
              <button type="button"
                      class="add-loa-row inline-flex items-center gap-2 text-sm text-primary-700 font-medium hover:text-primary-800 transition"
                      data-add-target="loa-rows">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Tambah Baris
              </button>

              <button type="button" id="clearLoaRows"
                      class="inline-flex items-center gap-2 text-sm text-red-600 font-medium hover:text-red-700 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Hapus Semua
              </button>
            </div>

            <!-- Tombol Preview dan Generate -->
            <div class="flex flex-col sm:flex-row justify-end gap-3 pt-4">
              <button type="button" id="previewLoaBtn"
                      class="inline-flex items-center gap-2 rounded-lg border border-primary-400 text-primary-700 px-6 py-2 text-sm font-semibold hover:bg-primary-50 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553 2.276a1 1 0 010 1.448L15 16M4 6h16M4 12h8M4 18h16"/>
                </svg>
                Preview LOA
              </button>

              <button type="submit"
                      class="inline-flex items-center gap-2 rounded-lg bg-primary-600 text-white px-6 py-2 text-sm font-semibold hover:bg-primary-700 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M5 4a2 2 0 0 1 2-2h6l6 6v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4z"/>
                  <path d="M13 2v4a2 2 0 0 0 2 2h4"/>
                </svg>
                Generate LOA (PDF)
              </button>
            </div>
          </form>
        </div>

        <!-- Modal Preview LOA -->
        <div id="loa-preview-modal" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full">
          <div class="relative p-4 w-full max-w-4xl max-h-full">
            <div class="relative bg-white rounded-2xl shadow-lg border border-primary-100">
              <div class="flex items-center justify-between p-4 border-b rounded-t bg-primary-50">
                <h3 class="text-lg font-semibold text-primary-900">📄 Preview Letter of Acceptance (LOA)</h3>
                <button type="button" class="text-gray-500 hover:text-primary-700" id="closePreviewBtn">✕</button>
              </div>
              <div class="p-6 overflow-y-auto max-h-[80vh]" id="loaPreviewContent">
                <iframe id="loaPreviewFrame" src="{{ route('user.loa', $reg->id) }}"
                        class="w-full h-[70vh] border border-primary-200 rounded-lg"></iframe>
              </div>
            </div>
          </div>
        </div> --}}


        {{-- BUTTONS --}}
        <div class="grid md:grid-cols-3 gap-4 mb-8">
            {{-- Tombol Download SKL --}}
            <a href="{{ $canDownload ? route('user.skl.download', ['intern_id' => $reg->id]) : 'javascript:void(0)' }}"
              class="flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold transition
                      {{ $canDownload ? 'bg-primary-600 text-white hover:bg-primary-700' : 'bg-zinc-200 text-zinc-500 cursor-not-allowed' }}"
              @if(!$canDownload) disabled @endif>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3a1 1 0 0 1 1 1v9.586l2.293-2.293a1 1 0 1 1 1.414 1.414l-4.004 4.004a1 1 0 0 1-1.414 0l-4.004-4.004a1 1 0 1 1 1.414-1.414L11 13.586V4a1 1 0 0 1 1-1z"/>
                    <path d="M5 20a1 1 0 0 1 0-2h14a1 1 0 1 1 0 2H5z"/>
                </svg>
                Download SKL
            </a>

            {{-- Tombol Download Sertifikat --}}
            <a href="{{ $canDownload ? route('user.certificate.download', ['intern_id' => $reg->id]) : 'javascript:void(0)' }}"
              class="flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold transition
                      {{ $canDownload ? 'bg-primary-600 text-white hover:bg-primary-700' : 'bg-zinc-200 text-zinc-500 cursor-not-allowed' }}"
              @if(!$canDownload) disabled @endif>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3a1 1 0 0 1 1 1v9.586l2.293-2.293a1 1 0 1 1 1.414 1.414l-4.004 4.004a1 1 0 0 1-1.414 0l-4.004-4.004a1 1 0 1 1 1.414-1.414L11 13.586V4a1 1 0 0 1 1-1z"/>
                    <path d="M5 20a1 1 0 0 1 0-2h14a1 1 0 1 1 0 2H5z"/>
                </svg>
                Download Sertifikat
            </a>

            {{-- Tombol Download Membercard (3d) --}}
            <button type="button"
                    data-modal-target="modalMembercard3d"
                    data-modal-toggle="modalMembercard3d"
                    class="flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold transition
                          {{ $canDownload ? 'bg-primary-600 text-white hover:bg-primary-700' : 'bg-zinc-200 text-zinc-500 cursor-not-allowed' }}"
                    @if(!$canDownload) disabled @endif>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M5 4a2 2 0 0 1 2-2h6l6 6v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4z"/>
                    <path d="M13 2v4a2 2 0 0 0 2 2h4"/>
                </svg>
                Membercard 3D
            </button>

        </div>



      </div>
    @endif
  </div>
</div>

{{-- JS --}}
@push('scripts')
<script src="https://unpkg.com/@popperjs/core@2"></script>
<script src="https://unpkg.com/flowbite@2.5.1/dist/flowbite.min.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const wrap = document.getElementById('loa-rows');
    const clearBtn = document.getElementById('clearLoaRows');
    const previewModal = document.getElementById('loa-preview-modal');
    const closeBtn = document.getElementById('closePreviewBtn');
    const previewBtn = document.getElementById('previewLoaBtn');
    const iframe = document.getElementById('loaPreviewFrame');

    // --- Menambah Baris (mendukung kolom lengkap) ---
    document.addEventListener('click', (e) => {
      const addBtn = e.target.closest('.add-loa-row');
      if (addBtn) {
        const row = document.createElement('div');
        row.className = 'grid grid-cols-1 sm:grid-cols-[1fr_1fr_1fr_1fr_1fr_1fr] gap-3 items-center loa-row';
        row.innerHTML = `
          <input name="loa_nama_siswa[]" type="text"
                 class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                 placeholder="Nama Siswa">
          <input name="loa_nim_nis[]" type="text"
                 class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                 placeholder="NIM/NIS">
          <input name="loa_jurusan[]" type="text"
                 class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                 placeholder="Jurusan">
          <input name="loa_instansi[]" type="text"
                 class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                 placeholder="Instansi">
          <input name="loa_periode[]" type="text"
                 class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                 placeholder="Periode Magang">
          <input name="loa_kontak[]" type="text"
                 class="w-full border rounded p-2 text-xs border-primary-200 focus:ring-primary-500 focus:border-primary-500"
                 placeholder="Kontak">
          <button type="button"
                  class="delete-loa-row text-red-600 hover:text-red-800 text-xs font-medium flex items-center gap-1 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Hapus
          </button>
        `;
        wrap.appendChild(row);
      }

      // --- Hapus Baris (klik tombol atau ikon di dalamnya) ---
      const delBtn = e.target.closest('.delete-loa-row');
      if (delBtn) {
        const row = delBtn.closest('.loa-row');
        if (row) row.remove();
      }
    });

    // --- Hapus Semua Baris ---
    clearBtn?.addEventListener('click', () => {
      if (confirm('Yakin ingin menghapus semua baris LOA?')) {
        wrap.innerHTML = '';
      }
    });

    // --- Preview Modal (kirim data ke iframe) ---
    previewBtn?.addEventListener('click', () => {
      const valAll = (selector) =>
        Array.from(document.querySelectorAll(selector)).map(i => i.value.trim());

      const namaSiswa  = valAll('input[name="loa_nama_siswa[]"]');
      const nimNis     = valAll('input[name="loa_nim_nis[]"]');
      const jurusan    = valAll('input[name="loa_jurusan[]"]');
      const instansi   = valAll('input[name="loa_instansi[]"]');
      const periode    = valAll('input[name="loa_periode[]"]');
      const kontak     = valAll('input[name="loa_kontak[]"]');

      const maxLen = Math.max(
        namaSiswa.length, nimNis.length, jurusan.length,
        instansi.length, periode.length, kontak.length
      );

      const rows = Array.from({ length: maxLen }).map((_, i) => ({
        nomor    : i + 1, // Menambahkan nomor urut otomatis
        nama_siswa : namaSiswa[i] || '',
        nim_nis   : nimNis[i] || '',
        jurusan   : jurusan[i] || '',
        instansi  : instansi[i] || '',
        periode   : periode[i] || '',
        kontak    : kontak[i] || '',
      }));

      iframe?.contentWindow?.postMessage({ type: 'updateLOA', rows }, '*');

      previewModal.classList.remove('hidden');
      previewModal.classList.add('flex');
    });

    // --- Tutup Modal Preview ---
    closeBtn?.addEventListener('click', () => {
      previewModal.classList.add('hidden');
      previewModal.classList.remove('flex');
    });

    // --- Auto-resize iframe bila view mengirim tinggi ---
    window.addEventListener('message', (e) => {
      if (e.data?.type === 'setHeight' && iframe) {
        iframe.style.height = e.data.height + 'px';
      }
    });
  });
</script>
@endpush
@include('user.partials.modal-membercard-3d', ['download' => $download])
@endsection

