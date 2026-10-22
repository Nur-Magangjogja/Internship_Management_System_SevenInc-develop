@extends('pemagang.layouts.app')

@section('title', 'Dokumen Saya')
@section('breadcrumb', 'Dokumen')

@section('content')

@php 
  $status = $registration?->internship_status; 
  $isCompleted = $status === 'completed';
@endphp

<h2 class="text-lg font-semibold text-gray-800 mb-1">Dokumen Saya</h2>
<p class="text-sm text-gray-500 mb-6">Unduh surat dan berkas yang berkaitan dengan magang Anda</p>

{{-- ===== DOCUMENT CARDS ===== --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">

  @foreach($docs as $key => $doc)
  <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col">

    {{-- Icon --}}
    <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4
      {{ $doc['available'] ? 'bg-green-50 text-green-700' : (isset($doc['pending']) && $doc['pending'] ? 'bg-amber-50 text-amber-600' : 'bg-gray-100 text-gray-400') }}">
      <i class="fas {{ $doc['icon'] }} text-xl"></i>
    </div>

    {{-- Label & description --}}
    <div class="flex-1">
      <p class="text-sm font-semibold text-gray-800 mb-1">{{ $doc['label'] }}</p>
      <p class="text-xs text-gray-400">{{ $doc['description'] }}</p>
      @if($doc['date'])
        <p class="text-xs text-gray-400 mt-1">{{ \Carbon\Carbon::parse($doc['date'])->format('d M Y') }}</p>
      @endif
    </div>

    {{-- Action button --}}
    <div class="mt-4">
      @if($doc['available'] && $key === 'loa')
        {{-- LOA butuh POST dengan intern_id --}}
        <form action="{{ route('user.loa.generate') }}" method="POST">
          @csrf
          <input type="hidden" name="intern_id" value="{{ $doc['intern_id'] ?? '' }}">
          <button type="submit"
            class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-white rounded-lg"
            style="background-color:#1a5c38;">
            <i class="fas fa-download text-xs"></i> Unduh PDF
          </button>
        </form>
      @elseif($doc['available'] && $key === 'membercard')
        {{-- Membercard → lihat preview dulu --}}
        <a href="{{ $doc['route'] }}"
           class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-white rounded-lg"
           style="background-color:#1a5c38;">
          <i class="fas fa-eye text-xs"></i> Lihat Kartu
        </a>
      @elseif($doc['available'] && $doc['route'])
        <a href="{{ $doc['route'] }}"
           class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-white rounded-lg"
           style="background-color:#1a5c38;">
          <i class="fas fa-download text-xs"></i> Unduh PDF
        </a>
      @elseif($doc['available'])
        <span class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-gray-500 bg-gray-100 rounded-lg cursor-not-allowed">
          <i class="fas fa-check text-xs text-green-600"></i> Tersedia
        </span>
      @elseif(isset($doc['pending']) && $doc['pending'])
        {{-- Status selesai tapi admin belum generate dokumen --}}
        <span class="flex items-center justify-center gap-1.5 w-full py-2 text-xs text-amber-700 bg-amber-50 rounded-lg border border-dashed border-amber-300">
          <i class="fas fa-clock"></i> Menunggu admin
        </span>
      @elseif($key === 'surat_penilaian' && !$isCompleted)
        {{-- Status belum selesai, khusus surat penilaian --}}
        <span class="flex items-center justify-center w-full py-2 text-xs text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200" title="Dokumen ini hanya bisa diunduh setelah status magang Anda selesai">
          <i class="fas fa-lock mr-1"></i> Terkunci (Belum Selesai)
        </span>
      @else
        <span class="flex items-center justify-center w-full py-2 text-xs text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200">
          Belum tersedia
        </span>
      @endif
    </div>
  </div>
  @endforeach

</div>

{{-- ===== SERTIFIKAT WEBINAR ===== --}}
@if(isset($webinarCerts) && $webinarCerts->isNotEmpty())
<div class="mt-8">
  <h3 class="text-sm font-semibold text-gray-700 mb-1">Sertifikat Webinar</h3>
  <p class="text-xs text-gray-400 mb-4">Sertifikat kehadiran webinar yang sudah disetujui admin</p>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach($webinarCerts as $wc)
    <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col">
      <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4 bg-blue-50 text-blue-700">
        <i class="fas fa-video text-xl"></i>
      </div>
      <div class="flex-1">
        <p class="text-sm font-semibold text-gray-800 mb-1">
          {{ $wc->attendance?->webinar?->title ?? 'Sertifikat Webinar' }}
        </p>
        <p class="text-xs text-gray-500 mb-1">
          @if($wc->attendance?->webinar?->event_date)
            <i class="fas fa-calendar-alt mr-1 text-green-600"></i>
            {{ $wc->attendance->webinar->event_date->format('d M Y') }}
          @endif
        </p>
        <p class="text-xs text-gray-400">
          Disetujui: {{ $wc->attendance?->reviewed_at?->format('d M Y') ?? '—' }}
        </p>
      </div>
      <div class="mt-4">
        @php
          $webinarObj = $wc->attendance?->webinar;
          $isGdriveWc = ($webinarObj && $webinarObj->isGdriveCertificate()) || !empty($wc->gdrive_link);
          $wcHref = $isGdriveWc
            ? ($wc->gdrive_link ?: $webinarObj?->gdrive_certificate_link)
            : route('pemagang.documents.sertifikat_webinar', $wc->id);
        @endphp
        <a href="{{ $wcHref }}"
           {{ $isGdriveWc ? 'target="_blank"' : '' }}
           class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-white rounded-lg transition hover:opacity-90"
           style="background-color:#1a5c38;">
          <i class="{{ $isGdriveWc ? 'fab fa-google-drive' : 'fas fa-download' }} text-xs"></i>
          {{ $isGdriveWc ? 'Buka di Google Drive' : 'Unduh Sertifikat' }}
        </a>
      </div>
    </div>
    @endforeach
  </div>
</div>
@else
<div class="mt-8">
  <h3 class="text-sm font-semibold text-gray-700 mb-1">Sertifikat Webinar</h3>
  <p class="text-xs text-gray-400 mb-4">Sertifikat kehadiran webinar yang sudah disetujui admin</p>
  <div class="bg-white rounded-xl border border-dashed border-gray-200 p-6 text-center text-sm text-gray-400">
    <i class="fas fa-video text-2xl mb-2 block text-gray-300"></i>
    Belum ada sertifikat webinar. Ikuti webinar dan upload bukti kehadiran untuk mendapatkannya.
    <div class="mt-3">
      <a href="{{ route('pemagang.webinar.index') }}"
         class="inline-flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg text-white"
         style="background-color:#1a5c38;">
        <i class="fas fa-external-link-alt text-xs"></i> Lihat Webinar
      </a>
    </div>
  </div>
</div>
@endif

{{-- ===== EKSKLUSIF (Surat Rekomendasi, Grup Alumni, Info Kerja) ===== --}}


<div class="mt-8">
  <h3 class="text-sm font-semibold text-gray-700 mb-1">Akses Eksklusif</h3>
  <p class="text-xs text-gray-400 mb-4">
    Tersedia setelah magang selesai · Hanya diberikan kepada pemagang tertentu oleh admin
  </p>

  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

    {{-- Surat Rekomendasi --}}
    <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col">
      <div class="w-11 h-11 rounded-xl flex items-center justify-center mb-4
        {{ $isCompleted && $extras?->rekomendasi_path ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-400' }}">
        <i class="fas fa-medal text-lg"></i>
      </div>
      <p class="text-sm font-semibold text-gray-800 mb-1">Surat Rekomendasi</p>
      <p class="text-xs text-gray-400 flex-1 mb-4">
        Surat rekomendasi dari mitra magang untuk keperluan karir Anda.
      </p>
      @if(!$isCompleted)
        <span class="flex items-center justify-center w-full py-2 text-xs text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200">
          <i class="fas fa-lock mr-1"></i> Belum tersedia
        </span>
      @elseif($extras?->rekomendasi_path)
        <a href="{{ route('pemagang.documents.rekomendasi') }}"
           class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-white rounded-lg"
           style="background-color:#1a5c38;">
          <i class="fas fa-download text-xs"></i> Unduh PDF
        </a>
      @else
        <span class="flex items-center justify-center w-full py-2 text-xs text-amber-600 bg-amber-50 rounded-lg border border-dashed border-amber-200">
          <i class="fas fa-clock mr-1"></i> Belum diberikan admin
        </span>
      @endif
    </div>

    {{-- Link Grup Alumni --}}
    <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col">
      <div class="w-11 h-11 rounded-xl flex items-center justify-center mb-4
        {{ $isCompleted && $extras?->alumni_group_url ? 'bg-purple-50 text-purple-700' : 'bg-gray-100 text-gray-400' }}">
        <i class="fas fa-users text-lg"></i>
      </div>
      <p class="text-sm font-semibold text-gray-800 mb-1">Grup Alumni</p>
      <p class="text-xs text-gray-400 flex-1 mb-4">
        Bergabung ke komunitas alumni magang InternHub.
      </p>
      @if(!$isCompleted)
        <span class="flex items-center justify-center w-full py-2 text-xs text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200">
          <i class="fas fa-lock mr-1"></i> Belum tersedia
        </span>
      @elseif($extras?->alumni_group_url)
        <a href="{{ $extras->alumni_group_url }}" target="_blank"
           class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-white rounded-lg bg-purple-600 hover:bg-purple-700 transition">
          <i class="fas fa-external-link-alt text-xs"></i>
          {{ $extras->alumni_group_label ?? 'Buka Link' }}
        </a>
      @else
        <span class="flex items-center justify-center w-full py-2 text-xs text-amber-600 bg-amber-50 rounded-lg border border-dashed border-amber-200">
          <i class="fas fa-clock mr-1"></i> Belum diberikan admin
        </span>
      @endif
    </div>

    {{-- Info Kerja --}}
    <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col">
      <div class="w-11 h-11 rounded-xl flex items-center justify-center mb-4
        {{ $isCompleted && $extras?->job_info_url ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-400' }}">
        <i class="fas fa-briefcase text-lg"></i>
      </div>
      <p class="text-sm font-semibold text-gray-800 mb-1">Info Kerja</p>
      <p class="text-xs text-gray-400 flex-1 mb-4">
        Informasi lowongan pekerjaan dari jaringan mitra InternHub.
      </p>
      @if(!$isCompleted)
        <span class="flex items-center justify-center w-full py-2 text-xs text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200">
          <i class="fas fa-lock mr-1"></i> Belum tersedia
        </span>
      @elseif($extras?->job_info_url)
        <div>
          @if($extras->job_info_description)
            <p class="text-xs text-gray-600 mb-2">{{ $extras->job_info_description }}</p>
          @endif
          <a href="{{ $extras->job_info_url }}" target="_blank"
             class="flex items-center justify-center gap-2 w-full py-2 text-sm font-medium text-white rounded-lg"
             style="background-color:#1a5c38;">
            <i class="fas fa-external-link-alt text-xs"></i> Lihat Info Kerja
          </a>
        </div>
      @else
        <span class="flex items-center justify-center w-full py-2 text-xs text-amber-600 bg-amber-50 rounded-lg border border-dashed border-amber-200">
          <i class="fas fa-clock mr-1"></i> Belum diberikan admin
        </span>
      @endif
    </div>

  </div>
</div>

{{-- ===== RIWAYAT DOWNLOAD ===== --}}
@if($downloadHistory->isNotEmpty())
<div class="bg-white rounded-xl border border-gray-100 p-5">
  <p class="text-sm font-semibold text-gray-700 mb-4">Riwayat Unduhan</p>
  <div class="space-y-2">
    @foreach($downloadHistory as $dl)
    <div class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-green-50 flex items-center justify-center text-green-700">
          <i class="fas fa-file-pdf text-sm"></i>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-700">{{ strtoupper($dl->doc_type) }}</p>
          <p class="text-xs text-gray-400">{{ $dl->downloaded_at?->format('d M Y, H:i') }}</p>
        </div>
      </div>
      <span class="text-xs px-2 py-1 rounded-full
        {{ $dl->status === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600' }}">
        {{ $dl->status === 'success' ? 'Berhasil' : 'Gagal' }}
      </span>
    </div>
    @endforeach
  </div>
</div>
@endif

{{-- Info kalau belum ada dokumen sama sekali --}}
@if($registration === null || $registration->is_draft)
<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5 text-sm text-yellow-800">
  <i class="fas fa-info-circle mr-2"></i>
  Dokumen akan tersedia setelah Anda mengirim form pendaftaran. 
  <a href="{{ route('pemagang.registration.form') }}" class="font-semibold underline">Daftar sekarang</a>.
</div>
@endif

@endsection
