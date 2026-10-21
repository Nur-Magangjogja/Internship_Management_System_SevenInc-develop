@extends('pemagang.layouts.app')

@section('title', $webinar->title)
@section('breadcrumb', 'Webinar')

@section('content')

<div class="max-w-2xl mx-auto">

  {{-- Back --}}
  <a href="{{ route('pemagang.webinar.index') }}"
     class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 mb-5">
    <i class="fas fa-arrow-left text-xs"></i> Kembali ke Daftar Webinar
  </a>

  {{-- Info Webinar --}}
  <div class="bg-white rounded-xl border border-gray-100 p-6 mb-5 shadow-sm">
    <div class="flex items-start justify-between gap-3 mb-4">
      <div>
        <h2 class="text-lg font-bold text-gray-800">{{ $webinar->title }}</h2>
        <p class="text-sm text-gray-500 mt-1">
          <i class="fas fa-calendar-alt mr-1 text-green-600"></i>
          {{ $webinar->event_date->format('d M Y, H:i') }} WIB
          @if($webinar->event_end_date)
            — {{ $webinar->event_end_date->format('H:i') }} WIB
          @endif
        </p>
      </div>
      <span class="text-xs px-2.5 py-1 rounded-full font-semibold
        {{ $webinar->event_date->isPast() ? 'bg-gray-100 text-gray-500' : 'bg-green-100 text-green-700' }}">
        {{ $webinar->event_date->isPast() ? 'Selesai' : 'Akan Datang' }}
      </span>
    </div>

    @if($webinar->description)
      <p class="text-sm text-gray-600 leading-relaxed mb-4">{{ $webinar->description }}</p>
    @endif

    @if($webinar->zoom_link)
      <a href="{{ $webinar->zoom_link }}" target="_blank"
         class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-xl"
         style="background-color:#2563eb;">
        <i class="fas fa-video text-xs"></i>
        Buka Link {{ $webinar->platform }}
      </a>
    @endif
  </div>

  {{-- Status Kehadiran --}}
  <div class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
    <h3 class="text-sm font-semibold text-gray-700 mb-4">Status Kehadiran Kamu</h3>

    @if($attendance)

      @if($attendance->isApproved())
        {{-- Approved — sertifikat tersedia --}}
        <div class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-lg mb-4">
          <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center text-green-700 flex-shrink-0">
            <i class="fas fa-certificate text-lg"></i>
          </div>
          <div>
            <p class="font-semibold text-green-800 text-sm">Sertifikat Tersedia!</p>
            <p class="text-xs text-green-600">
              Bukti kehadiran kamu sudah disetujui pada {{ $attendance->reviewed_at?->format('d M Y') }}.
            </p>
          </div>
        </div>
        @if($attendance->certificate_id)
          @php
            $isGdriveCert = ($webinar->isGdriveCertificate()) || !empty($attendance->certificate?->gdrive_link);
            $certHref = $isGdriveCert 
              ? ($attendance->certificate?->gdrive_link ?: $webinar->gdrive_certificate_link)
              : route('pemagang.documents.sertifikat_webinar', $attendance->certificate_id);
          @endphp
          <a href="{{ $certHref }}"
             {{ $isGdriveCert ? 'target="_blank"' : '' }}
             class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-xl shadow-sm transition hover:opacity-90"
             style="background-color:#1a5c38;">
            <i class="{{ $isGdriveCert ? 'fab fa-google-drive' : 'fas fa-download' }} text-xs"></i>
            {{ $isGdriveCert ? 'Buka Sertifikat di Google Drive' : 'Unduh Sertifikat' }}
          </a>
        @endif

      @elseif($attendance->isPending())
        {{-- Pending --}}
        <div class="flex items-center gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg mb-4">
          <i class="fas fa-clock text-amber-600 text-xl flex-shrink-0"></i>
          <div>
            <p class="font-semibold text-amber-800 text-sm">Menunggu Review Admin</p>
            <p class="text-xs text-amber-600">Dikirim {{ $attendance->created_at->diffForHumans() }}. Admin akan meninjau bukti kehadiranmu.</p>
          </div>
        </div>

        {{-- Preview bukti --}}
        @if($attendance->proof_file)
          <div class="mb-4">
            <p class="text-xs font-medium text-gray-500 mb-1">Bukti yang sudah dikirim:</p>
            @php $ext = pathinfo($attendance->proof_file, PATHINFO_EXTENSION); @endphp
            @if(in_array(strtolower($ext), ['jpg','jpeg','png']))
              <img src="{{ asset('storage/' . $attendance->proof_file) }}"
                   alt="Bukti kehadiran" class="max-h-40 rounded-lg border border-gray-200">
            @else
              <a href="{{ asset('storage/' . $attendance->proof_file) }}" target="_blank"
                 class="text-sm text-blue-600 hover:underline">
                <i class="fas fa-file-pdf mr-1"></i>Lihat file bukti
              </a>
            @endif
          </div>
        @endif

      @else
        {{-- Rejected --}}
        <div class="flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-lg mb-4">
          <i class="fas fa-times-circle text-red-600 text-xl flex-shrink-0 mt-0.5"></i>
          <div>
            <p class="font-semibold text-red-800 text-sm">Bukti Ditolak</p>
            @if($attendance->rejection_reason)
              <p class="text-xs text-red-600 mt-1">Alasan: {{ $attendance->rejection_reason }}</p>
            @endif
            <p class="text-xs text-red-500 mt-1">Kamu bisa upload ulang bukti kehadiran di bawah.</p>
          </div>
        </div>
      @endif

    @endif

    {{-- Form Upload (tampil kalau belum submit atau sudah ditolak) --}}
    @if(!$attendance || $attendance->isRejected())
    <div class="{{ $attendance ? 'mt-4 pt-4 border-t border-gray-100' : '' }}">
      <p class="text-sm font-semibold text-gray-700 mb-3">
        {{ $attendance ? 'Upload Ulang Bukti Kehadiran' : 'Upload Bukti Kehadiran' }}
      </p>
      <p class="text-xs text-gray-500 mb-4">
        Upload foto atau screenshot saat kamu mengikuti webinar ini (JPG, PNG, atau PDF, maks. 5MB).
      </p>

      @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg">
          {{ session('success') }}
        </div>
      @endif
      @if(session('error'))
        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg">
          {{ session('error') }}
        </div>
      @endif
      @if($errors->any())
        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg">
          @foreach($errors->all() as $err) <div>{{ $err }}</div> @endforeach
        </div>
      @endif

      <form action="{{ route('pemagang.webinar.upload_proof', $webinar) }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">
            File Bukti <span class="text-red-500">*</span>
          </label>
          <input type="file" name="proof_file" accept=".jpg,.jpeg,.png,.pdf" required
                 class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:font-medium file:text-white cursor-pointer"
                 style="--file-bg:#1a5c38;">
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Catatan (opsional)</label>
          <textarea name="proof_note" rows="2"
                    placeholder="Contoh: Ini screenshot saat sesi tanya jawab berlangsung..."
                    class="w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 resize-none"></textarea>
        </div>
        <button type="submit"
                class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-xl"
                style="background-color:#1a5c38;">
          <i class="fas fa-upload text-xs"></i> Kirim Bukti Kehadiran
        </button>
      </form>
    </div>
    @endif

  </div>
</div>

@endsection
