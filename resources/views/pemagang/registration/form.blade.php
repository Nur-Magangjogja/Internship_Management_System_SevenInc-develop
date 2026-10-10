@extends('pemagang.layouts.app')

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<style>
  .ts-control {
      border: 1px solid #d1d5db; /* border-gray-300 */
      border-radius: 0.5rem; /* rounded-lg */
      padding: 0.5rem 0.75rem; /* px-3 py-2 */
      font-size: 0.875rem; /* text-sm */
      min-height: 42px;
  }
  .ts-control.focus {
      border-color: #1a5c38;
      box-shadow: 0 0 0 1px #1a5c38;
  }
  .tom-select .ts-dropdown {
      font-size: 0.875rem; /* text-sm */
      border-radius: 0.5rem;
  }
</style>
@endpush

@section('title', 'Daftar Magang')
@section('breadcrumb', 'Pendaftaran')

@section('content')

@php
  $reg   = $registration ?? null;
  $old   = fn($field) => old($field, $reg?->$field ?? '');
  $label = 'block mb-1.5 text-sm font-medium text-gray-700';
  $input = 'block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 px-3 py-2.5 text-sm';
  $radio = 'w-4 h-4 text-green-600 border-gray-300 focus:ring-2 focus:ring-green-500';
  $check = $radio;
  $item  = 'flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition cursor-pointer';
  $group = 'border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden';

  // Normalize tanggal ke format Y-m-d untuk input type="date"
  $toDateInput = function(string $val): string {
    if ($val === '') return '';
    // Sudah format Y-m-d
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    // Coba parse dengan Carbon
    try { return \Carbon\Carbon::parse($val)->format('Y-m-d'); }
    catch (\Throwable) { return ''; }
  };
@endphp

<div class="max-w-4xl mx-auto">
  <div class="bg-white rounded-xl border border-gray-100 p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-1">Form Pendaftaran Magang</h2>
    <p class="text-sm text-gray-500 mb-6">Lengkapi data berikut untuk mendaftar program magang</p>

    @if($reg && $reg->is_draft)
      <div class="mb-5 flex items-center gap-2 bg-yellow-50 border border-yellow-200 text-yellow-700 text-sm px-4 py-3 rounded-lg">
        <i class="fas fa-save"></i>
        Draft tersimpan {{ $reg->draft_saved_at ? \Carbon\Carbon::parse($reg->draft_saved_at)->diffForHumans() : '' }}. Lanjutkan dan kirim pendaftaran Anda.
      </div>
    @endif

    @if ($errors->any())
      <div class="mb-5 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">
        <strong>Ada kesalahan:</strong>
        <ul class="mt-1 list-disc list-inside">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    {{-- Stepper Progress Bar --}}
    <div class="mb-6 rounded-xl border border-gray-150 bg-gray-50/70 p-3 sm:p-4">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-4 text-xs">
        <div class="flex items-center gap-2 text-emerald-800 font-semibold">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-white font-bold text-[11px]">1</span>
          <span class="truncate">Data Diri & Edukasi</span>
        </div>
        <div class="flex items-center gap-2 text-gray-600 font-medium">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-700 font-bold text-[11px]">2</span>
          <span class="truncate">Pilihan & Durasi</span>
        </div>
        <div class="flex items-center gap-2 text-gray-600 font-medium">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-700 font-bold text-[11px]">3</span>
          <span class="truncate">Berkas & Portofolio</span>
        </div>
        <div class="flex items-center gap-2 text-gray-600 font-medium">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-700 font-bold text-[11px]">4</span>
          <span class="truncate">Finalisasi & Submit</span>
        </div>
      </div>
    </div>

    <form id="form-daftar" action="{{ route('pemagang.registration.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
      @csrf

      @if(!empty($useFormFields) && $useFormFields)
        {{-- ===== FORM DINAMIS (dari pengaturan admin) ===== --}}
        @include('pemagang.registration.partials.dynamic-form', [
            'mainFields'  => $mainFields,
            'extraFields' => $extraFields,
            'divisions'   => $divisions,
            'reg'         => $reg,
            'old'         => $old,
            'input'       => $input,
            'label'       => $label,
            'radio'       => $radio,
            'toDateInput' => $toDateInput,
        ])
        {{-- Hidden fields untuk kolom NOT NULL yang tidak dirender --}}
        {{-- Nama Pembimbing --}}
        <div>
          <label class="{{ $label }}">Nama Pembimbing (Opsional)</label>
          <input type="text" name="supervisor_name" placeholder="Nama pembimbing (jika ada)"
            class="{{ $input }}" value="{{ $old('supervisor_name', $reg?->supervisor_name !== '-' ? $reg?->supervisor_name : '') }}">
        </div>

        {{-- No. WA Pembimbing --}}
        <div>
          <label class="{{ $label }}">No. WA Pembimbing (Opsional)</label>
          <input type="tel" name="supervisor_contact" placeholder="08xxxxxxxxxx"
            pattern="[0-9]*" inputmode="numeric" title="Hanya boleh angka"
            class="{{ $input }}" value="{{ $old('supervisor_contact', $reg?->supervisor_contact !== '-' ? $reg?->supervisor_contact : '') }}">
          <p class="mt-1 text-xs text-gray-400">Hanya angka</p>
        </div>

        {{-- Kegiatan Lain --}}
        <div>
          <label class="{{ $label }}">Kegiatan Lain Selain Magang</label>
          <textarea name="current_activities" rows="3" placeholder="Contoh: Kuliah malam, freelance, dll (tulis '-' jika tidak ada)"
            class="{{ $input }} resize-none">{{ $old('current_activities', $reg?->current_activities !== '-' ? $reg?->current_activities : '') }}</textarea>
        </div>
      @else
      {{-- ===== FORM STATIS (fallback) ===== --}}

      {{-- ==================== 1. DATA DIRI ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-green-100 text-green-800 text-xs flex items-center justify-center font-bold">1</span>
          Data Diri <span class="text-xs font-normal text-red-500 normal-case">(Wajib)</span>
        </h3>
        
        <div class="space-y-4">
          {{-- Nama Lengkap --}}
          <div>
            <label class="{{ $label }}">Nama Lengkap <span class="text-red-500">*</span></label>
            <input type="text" name="fullname" required placeholder="Muhammad Sumbul"
              class="{{ $input }}" value="{{ $old('fullname') }}">
          </div>

          {{-- Jenis Kelamin --}}
          <div>
            <label class="{{ $label }}">Jenis Kelamin <span class="text-red-500">*</span></label>
            <div class="flex gap-6 pt-1">
              <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                <input type="radio" name="gender" value="Laki-laki" class="{{ $radio }}"
                  @checked($old('gender') === 'Laki-laki')> Laki-laki
              </label>
              <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                <input type="radio" name="gender" value="Perempuan" class="{{ $radio }}"
                  @checked($old('gender') === 'Perempuan')> Perempuan
              </label>
            </div>
          </div>

          {{-- Kota Domisili --}}
          <div>
            <label class="{{ $label }}">Kota Domisili <span class="text-red-500">*</span></label>
            <input type="text" id="current_city" name="current_city" required placeholder="Yogyakarta"
              class="{{ $input }} tomselect-input" value="{{ $old('current_city') }}"
              data-options="{{ json_encode(collect($cities ?? [])->pluck('name')) }}">
          </div>

          {{-- Email & No HP --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Email <span class="text-red-500">*</span></label>
              <input type="email" name="email" required placeholder="kamu@email.com"
                class="{{ $input }}" value="{{ $old('email') }}">
            </div>
            <div>
              <label class="{{ $label }}">No. HP (WhatsApp) <span class="text-red-500">*</span></label>
              <input type="tel" name="phone_number" required placeholder="08xxxxxxxxxx"
                pattern="[0-9]{10,15}" inputmode="numeric" title="Hanya boleh angka, 10-15 digit"
                class="{{ $input }}" value="{{ $old('phone_number') }}">
              <p class="mt-1 text-xs text-gray-400">Hanya angka, contoh: 08123456789</p>
            </div>
          </div>
        </div>
      </div>

      {{-- ==================== 2. DATA MAHASISWA / PENDIDIKAN ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-green-100 text-green-800 text-xs flex items-center justify-center font-bold">2</span>
          Data Mahasiswa / Pendidikan <span class="text-xs font-normal text-red-500 normal-case">(Wajib)</span>
        </h3>

        <div class="space-y-4">
          {{-- Status Saat Ini & NIM / NPM --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Status Saat Ini <span class="text-red-500">*</span></label>
              <select name="current_status" required class="{{ $input }}">
                <option value="">-- Pilih Status --</option>
                <option value="Mahasiswa / Pelajar Aktif" @selected($old('current_status') === 'Mahasiswa / Pelajar Aktif')>Mahasiswa / Pelajar Aktif</option>
                <option value="Lulus Kuliah / Sekolah (Belum Bekerja)" @selected($old('current_status') === 'Lulus Kuliah / Sekolah (Belum Bekerja)')>Lulus Kuliah / Sekolah (Belum Bekerja)</option>
                <option value="Lulus Kuliah / Sekolah (Sedang Bekerja)" @selected($old('current_status') === 'Lulus Kuliah / Sekolah (Sedang Bekerja)')>Lulus Kuliah / Sekolah (Sedang Bekerja)</option>
              </select>
            </div>
            <div>
              <label class="{{ $label }}">NIM / NPM / NISN <span class="text-red-500">*</span></label>
              <input type="text" name="student_id" required placeholder="21552011045"
                pattern="[0-9A-Za-z\-]+" inputmode="text"
                class="{{ $input }}" value="{{ $old('student_id') }}">
              <p class="mt-1 text-xs text-gray-400">Contoh: 21552011045</p>
            </div>
          </div>

          {{-- Universitas & Program Studi --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Universitas / Sekolah <span class="text-red-500">*</span></label>
              <input type="text" id="institution_name" name="institution_name" required placeholder="Telkom University"
                class="{{ $input }} tomselect-input" value="{{ $old('institution_name') }}"
                data-options="{{ json_encode(collect($institutions ?? [])->pluck('name')) }}">
            </div>
            <div>
              <label class="{{ $label }}">Program Studi / Jurusan <span class="text-red-500">*</span></label>
              <input type="text" id="study_program" name="study_program" required placeholder="Rekayasa Perangkat Lunak"
                class="{{ $input }} tomselect-input" value="{{ $old('study_program') }}"
                data-options="{{ json_encode(collect($studyPrograms ?? [])->pluck('name')) }}">
            </div>
          </div>
        </div>
      </div>

      {{-- ==================== 3. DATA MAGANG ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-green-100 text-green-800 text-xs flex items-center justify-center font-bold">3</span>
          Data Magang <span class="text-xs font-normal text-red-500 normal-case">(Wajib)</span>
        </h3>

        <div class="space-y-4">
          {{-- Divisi Diminati & Jenis Magang --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Program Magang yang Diminati <span class="text-red-500">*</span></label>
              <select name="internship_interest" required class="{{ $input }}">
                <option value="">-- Pilih Divisi --</option>
                @foreach($divisions ?? [] as $div)
                  <option value="{{ $div }}" @selected($old('internship_interest') === $div)>
                    {{ $div }}
                  </option>
                @endforeach
              </select>
            </div>
            <div>
              <label class="{{ $label }}">Jenis Magang <span class="text-red-500">*</span></label>
              <select name="internship_type" required class="{{ $input }}" id="select-internship-type-static">
                <option value="">-- Pilih --</option>
                <option value="Magang Mandiri" @selected($old('internship_type') === 'Magang Mandiri')>Magang Mandiri</option>
                <option value="Reguler-Kampus" @selected($old('internship_type') === 'Reguler-Kampus')>Reguler-Kampus</option>
              </select>
            </div>
          </div>

          {{-- Kesiapan WFO & Kesanggupan Shifting --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Kesiapan Sistem Magang WFO di Yogyakarta <span class="text-red-500">*</span></label>
              <select name="wfo_readiness" required class="{{ $input }}">
                <option value="">-- Pilih Kesiapan WFO --</option>
                <option value="Siap WFO di Yogyakarta" @selected($old('wfo_readiness') === 'Siap WFO di Yogyakarta')>Siap WFO di Yogyakarta</option>
                <option value="Tidak Siap" @selected($old('wfo_readiness') === 'Tidak Siap')>Tidak Siap</option>
              </select>
            </div>
            <div>
              <label class="{{ $label }}">Kesanggupan Jadwal Senin-Sabtu & Shifting <span class="text-red-500">*</span></label>
              <select name="schedule_shift_readiness" required class="{{ $input }}">
                <option value="">-- Pilih Kesanggupan --</option>
                <option value="Sanggup" @selected($old('schedule_shift_readiness') === 'Sanggup')>Sanggup</option>
                <option value="Tidak Sanggup" @selected($old('schedule_shift_readiness') === 'Tidak Sanggup')>Tidak Sanggup</option>
              </select>
            </div>
          </div>

          {{-- Kepemilikan Laptop & Durasi Magang --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Apakah Memiliki Laptop Sendiri? <span class="text-red-500">*</span></label>
              <div class="flex gap-6 pt-2">
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                  <input type="radio" name="has_laptop" value="1" class="{{ $radio }}"
                    @checked($old('has_laptop') === '1' || $old('has_laptop') === true || $old('has_laptop') === 1)> Ya (Punya Sendiri)
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                  <input type="radio" name="has_laptop" value="0" class="{{ $radio }}"
                    @checked($old('has_laptop') === '0' || $old('has_laptop') === false || ($old('has_laptop') === 0 && $old('has_laptop') !== ''))> Tidak
                </label>
              </div>
            </div>
            <div>
              <label class="{{ $label }}">Berapa Lama Rencana Magang? <span class="text-red-500">*</span></label>
              <select name="planned_duration" required class="{{ $input }}">
                <option value="">-- Pilih Durasi --</option>
                <option value="1 Bulan" @selected($old('planned_duration') === '1 Bulan')>1 Bulan</option>
                <option value="2 Bulan" @selected($old('planned_duration') === '2 Bulan')>2 Bulan</option>
                <option value="3 Bulan" @selected($old('planned_duration') === '3 Bulan')>3 Bulan</option>
                <option value="4 Bulan" @selected($old('planned_duration') === '4 Bulan')>4 Bulan</option>
                <option value="5 Bulan" @selected($old('planned_duration') === '5 Bulan')>5 Bulan</option>
                <option value="6 Bulan" @selected($old('planned_duration') === '6 Bulan')>6 Bulan</option>
                <option value="Lebih dari 6 Bulan" @selected($old('planned_duration') === 'Lebih dari 6 Bulan')>Lebih dari 6 Bulan</option>
              </select>
            </div>
          </div>

          {{-- Tanggal Mulai --}}
          <div>
            <label class="{{ $label }}">Kapan Berencana Mulai Magang/PKL?</label>
            <input type="date" name="start_date"
              min="{{ date('Y-m-d') }}"
              class="{{ $input }}" value="{{ $toDateInput($old('start_date')) }}">
          </div>

          {{-- Alasan Magang --}}
          <div>
            <label class="{{ $label }}">Alasan Ingin Magang di Sini <span class="text-red-500">*</span></label>
            <textarea name="internship_reason" required rows="3" placeholder="Tuliskan alasan Anda..."
              class="{{ $input }} resize-none">{{ $old('internship_reason') }}</textarea>
          </div>
        </div>
      </div>

      {{-- ==================== 4. BERKAS PENDUKUNG (OPSIONAL) ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-1 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs flex items-center justify-center font-bold">4</span>
          Upload Berkas <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>
        <p class="text-xs text-gray-400 mb-4">Berkas pendukung dapat dilengkapi sekarang atau disusulkan kemudian</p>

        <div class="space-y-4">
          <div>
            <label class="{{ $label }}">Upload PDF CV + scan KTM/KTP + Portofolio (Maks. 10MB) <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
            <input type="file" name="cv_ktp_portofolio_pdf" id="cv_ktp_portofolio_pdf" accept=".pdf"
              class="{{ $input }} file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer"
              style="--file-bg: #1a5c38;">
            <div id="cv-feedback" class="hidden mt-2 p-2.5 rounded-lg border text-xs"></div>
            @php
              $existingCv = $reg?->attachments()->where('attachment_type', 'cv_ktp_portofolio')->latest('id')->first();
            @endphp
            @if($existingCv)
              <p class="text-xs text-gray-500 mt-1.5 flex items-center gap-1.5">
                <i class="fas fa-file-pdf text-red-500"></i>
                File tersimpan: <a href="{{ asset('storage/' . $existingCv->file_path) }}" target="_blank" class="text-blue-600 hover:underline font-medium">{{ $existingCv->original_name }}</a> ({{ round($existingCv->file_size / 1024, 1) }} KB)
              </p>
            @endif
          </div>
          <div>
            <label class="{{ $label }}">Foto Profil (JPG/PNG, Maks. 5MB) <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
            <input type="file" name="profile_photo" id="profile_photo" accept=".jpg,.jpeg,.png"
              class="{{ $input }} file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer"
              style="--file-bg: #1a5c38;">
            <div id="photo-feedback" class="hidden mt-2 p-2.5 rounded-lg border text-xs"></div>
            @if($reg?->profile_photo)
              <p class="text-xs text-gray-500 mt-1.5 flex items-center gap-1.5">
                <i class="fas fa-image text-emerald-500"></i>
                Foto tersimpan: <a href="{{ asset('storage/' . $reg->profile_photo) }}" target="_blank" class="text-blue-600 hover:underline font-medium">{{ basename($reg->profile_photo) }}</a>
              </p>
            @endif
          </div>
          <p class="text-xs text-gray-400">Format PDF maks 10MB untuk berkas gabungan, JPG/PNG maks 5MB untuk foto profil.</p>
        </div>
      </div>

      {{-- ==================== 5. KEAHLIAN & FASILITAS (OPSIONAL) ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs flex items-center justify-center font-bold">5</span>
          Keahlian & Perlengkapan <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>

        <div class="space-y-4">

          {{-- Skill Fields --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Software Desain</label>
              <input type="text" name="design_software" placeholder="Figma, Photoshop"
                class="{{ $input }}" value="{{ $old('design_software') }}">
            </div>
            <div>
              <label class="{{ $label }}">Software Video</label>
              <input type="text" name="video_software" placeholder="Premiere Pro, After Effects"
                class="{{ $input }}" value="{{ $old('video_software') }}">
            </div>
            <div>
              <label class="{{ $label }}">Bahasa Pemrograman</label>
              <input type="text" name="programming_languages" placeholder="PHP, JS"
                class="{{ $input }}" value="{{ $old('programming_languages') }}">
            </div>
            <div>
              <label class="{{ $label }}">Digital Marketing</label>
              <input type="text" name="digital_marketing_type" placeholder="SEO, Ads"
                class="{{ $input }}" value="{{ $old('digital_marketing_type') }}">
            </div>
          </div>

          {{-- Peralatan --}}
          <div>
            <label class="{{ $label }}">Peralatan (Laptop, Kamera, Tripod, dll)</label>
            <input type="text" name="owned_tools" placeholder="Asus ROG, Kamera, Tripod"
              class="{{ $input }}" value="{{ $old('owned_tools') ?: $old('laptop_equipment') }}">
          </div>
        </div>
      </div>

      {{-- ==================== 6. INFORMASI TAMBAHAN (OPSIONAL) ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs flex items-center justify-center font-bold">6</span>
          Informasi Tambahan <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>

        <div class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Nama Pembimbing (Opsional)</label>
              <input type="text" name="supervisor_name" placeholder="Nama pembimbing (jika ada)"
                class="{{ $input }}" value="{{ $old('supervisor_name', $reg?->supervisor_name !== '-' ? $reg?->supervisor_name : '') }}">
            </div>
            <div>
              <label class="{{ $label }}">No. WA Pembimbing (Opsional)</label>
              <input type="tel" name="supervisor_contact" placeholder="08xxxxxxxxxx"
                pattern="[0-9]*" inputmode="numeric" title="Hanya boleh angka"
                class="{{ $input }}" value="{{ $old('supervisor_contact', $reg?->supervisor_contact !== '-' ? $reg?->supervisor_contact : '') }}">
              <p class="mt-1 text-xs text-gray-400">Hanya angka</p>
            </div>
          </div>

          <div>
            <label class="{{ $label }}">Kegiatan Lain Selain Magang</label>
            <textarea name="current_activities" rows="2" placeholder="Contoh: Kuliah malam, freelance, dll (tulis '-' jika tidak ada)"
              class="{{ $input }} resize-none">{{ $old('current_activities', $reg?->current_activities !== '-' ? $reg?->current_activities : '') }}</textarea>
          </div>

          <div>
            <label class="{{ $label }}">Butuh Informasi Kost?</label>
            <select name="boarding_info" class="{{ $input }}">
              <option value="Tidak" @selected(($old('boarding_info') ?: 'Tidak') === 'Tidak')>Tidak</option>
              <option value="Ya"    @selected($old('boarding_info') === 'Ya')>Ya</option>
            </select>
          </div>

          <div>
            <label class="{{ $label }}">Instagram</label>
            <div class="flex items-center gap-0">
              <span class="inline-flex items-center px-3 py-2.5 rounded-l-lg border border-r-0 border-gray-200 bg-gray-50 text-sm text-gray-500">@</span>
              <input type="text" name="social_media_instagram" placeholder="username_kamu"
                class="block flex-1 rounded-r-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 px-3 py-2.5 text-sm"
                value="{{ $old('social_media_instagram', $reg?->social_media_instagram !== '-' ? $reg?->social_media_instagram : '') }}">
            </div>
          </div>

          <div>
            <label class="{{ $label }}">Tahu Info Magang Dari</label>
            <div class="{{ $group }}">
              @php
                $infoSources = $old('internship_info_sources', $reg?->internship_info_sources ?? '');
                $infoSourcesArr = is_array($infoSources)
                    ? $infoSources
                    : array_map('trim', explode(',', (string) $infoSources));
              @endphp
              @foreach([
                'Instagram'           => 'Instagram',
                'TikTok'              => 'TikTok',
                'LinkedIn'            => 'LinkedIn',
                'Referral Teman'      => 'Referral Teman',
                'Website'             => 'Website',
                'Campus'              => 'Kampus/Universitas',
                'Lainnya'             => 'Lainnya',
              ] as $val => $lbl)
              <label class="{{ $item }}">
                <input type="checkbox" name="internship_info_sources[]" value="{{ $val }}" class="{{ $check }}"
                  @checked(in_array($val, $infoSourcesArr))>
                <span class="text-sm text-gray-700">{{ $lbl }}</span>
              </label>
              @endforeach
            </div>
          </div>
        </div>
      </div>

      @endif {{-- end @else (form statis) --}}

      {{-- Info unpaid --}}
      @if(!$registration || $registration->is_draft || $registration->internship_status === 'waiting')
      <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
        <strong>Perhatian:</strong> Program magang ini bersifat <strong>unpaid / tidak bergaji</strong>.
        Setelah submit, konfirmasi ke WA Admin <strong>0895 2900 2944</strong> dengan pesan
        <em>"SAYA SUDAH ISI FORM"</em>.
      </div>
      @endif

      {{-- Tombol --}}
      <div class="flex flex-wrap items-center gap-3 pt-3 border-t border-gray-100">
        @if(!$registration || $registration->is_draft)
          {{-- Belum submit → tombol kirim + server draft + local draft --}}
          <button type="submit"
            class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-lg shadow-sm hover:opacity-95 transition"
            style="background-color:#1a5c38;">
            <i class="fas fa-paper-plane text-xs"></i>
            Kirim Pendaftaran
          </button>
          <button type="submit" formaction="{{ route('pemagang.registration.draft') }}" formnovalidate
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 rounded-lg transition">
            <i class="fas fa-cloud-arrow-up text-xs"></i>
            Simpan Draft ke Server
          </button>
          <button type="button" id="btn-save-local"
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
            <i class="fas fa-save text-xs"></i>
            Simpan di Browser
          </button>
        @else
          {{-- Sudah submit → hanya bisa update data --}}
          <button type="submit"
            class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-lg shadow-sm hover:opacity-95 transition"
            style="background-color:#1a5c38;">
            <i class="fas fa-save text-xs"></i>
            Simpan Perubahan Data
          </button>
          <a href="{{ route('pemagang.dashboard') }}"
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
            Kembali
          </a>
        @endif
      </div>

    </form>
  </div>
</div>

@push('scripts')
<script>
  // ===== Validasi real-time field numerik =====
  function setupNumericValidation(selector, message) {
    document.querySelectorAll(selector).forEach(function(input) {
      // Buat elemen pesan error
      const msg = document.createElement('p');
      msg.className = 'text-xs text-red-500 mt-1 hidden';
      msg.textContent = message;
      input.parentNode.appendChild(msg);

      input.addEventListener('input', function() {
        const val = this.value.replace(/\s/g, '');
        const hasLetter = /[a-zA-Z]/.test(val);

        if (hasLetter) {
          this.classList.add('border-red-400', 'ring-red-400');
          this.classList.remove('border-gray-200');
          msg.classList.remove('hidden');
        } else {
          this.classList.remove('border-red-400', 'ring-red-400');
          this.classList.add('border-gray-200');
          msg.classList.add('hidden');
        }
      });

      // Blokir huruf saat paste
      input.addEventListener('paste', function(e) {
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        if (/[a-zA-Z]/.test(pasted)) {
          e.preventDefault();
          input.dispatchEvent(new Event('input'));
        }
      });
    });
  }

  // ===== Validasi visual saat submit — highlight field kosong =====
  function setupRequiredHighlight() {
    const form = document.getElementById('form-daftar');
    if (!form) return;

    form.addEventListener('submit', function(e) {
      // Lewatkan validasi submit jika tombol yang diklik adalah simpan draft ke server
      if (e.submitter && (e.submitter.hasAttribute('formnovalidate') || e.submitter.getAttribute('formaction')?.includes('draft'))) {
        return;
      }

      let hasError = false;

      // Cek semua input/select/textarea yang required
      form.querySelectorAll('[required]').forEach(function(field) {
        const wrapper = field.closest('div');
        let errMsg = wrapper?.querySelector('.field-error-msg');

        if (!field.value.trim()) {
          hasError = true;
          field.classList.add('border-red-400', 'ring-1', 'ring-red-400');
          field.classList.remove('border-gray-200');

          if (!errMsg) {
            errMsg = document.createElement('p');
            errMsg.className = 'field-error-msg text-xs text-red-500 mt-1';
            errMsg.textContent = 'Field ini wajib diisi';
            field.parentNode.appendChild(errMsg);
          }
          errMsg.classList.remove('hidden');
        } else {
          field.classList.remove('border-red-400', 'ring-1', 'ring-red-400');
          field.classList.add('border-gray-200');
          if (errMsg) errMsg.classList.add('hidden');
        }
      });

      // Cek radio groups yang required
      const radioGroups = {};
      form.querySelectorAll('input[type="radio"][required]').forEach(function(r) {
        radioGroups[r.name] = radioGroups[r.name] || [];
        radioGroups[r.name].push(r);
      });

      Object.entries(radioGroups).forEach(function([name, radios]) {
        const checked = radios.some(r => r.checked);
        const container = radios[0].closest('div.border') || radios[0].closest('div');
        let errMsg = container?.parentNode?.querySelector('.radio-error-' + name);

        if (!checked) {
          hasError = true;
          if (!errMsg) {
            errMsg = document.createElement('p');
            errMsg.className = 'radio-error-' + name + ' text-xs text-red-500 mt-1';
            errMsg.textContent = 'Pilih salah satu opsi';
            container?.parentNode?.appendChild(errMsg);
          }
        } else if (errMsg) {
          errMsg.remove();
        }
      });

      if (hasError) {
        e.preventDefault();
        // Scroll ke field error pertama
        const firstError = form.querySelector('.border-red-400');
        if (firstError) {
          firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
          firstError.focus();
        }

        // Tampilkan toast error di atas
        showFormError('Ada beberapa field yang belum diisi. Silakan periksa kembali.');
      }
    });

    // Clear error saat field diisi
    form.querySelectorAll('[required]').forEach(function(field) {
      field.addEventListener('input', function() {
        if (this.value.trim()) {
          this.classList.remove('border-red-400', 'ring-1', 'ring-red-400');
          this.classList.add('border-gray-200');
          const errMsg = this.parentNode.querySelector('.field-error-msg');
          if (errMsg) errMsg.classList.add('hidden');
        }
      });
      field.addEventListener('change', function() {
        field.dispatchEvent(new Event('input'));
      });
    });
  }

  function showFormError(message) {
    let toast = document.getElementById('form-error-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'form-error-toast';
      toast.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 flex items-center gap-2 bg-red-50 border border-red-300 text-red-700 text-sm font-medium px-5 py-3 rounded-xl shadow-lg';
      document.body.appendChild(toast);
    }
    toast.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}
      <button onclick="this.parentNode.remove()" class="ml-3 text-red-400 hover:text-red-600 font-bold" aria-label="Tutup">X</button>`;
    toast.style.display = 'flex';
    setTimeout(() => toast?.remove(), 5000);
  }

  // ===== localStorage: simpan & restore form =====
  const FORM_KEY = 'pemagang_form_{{ auth()->id() }}';

  function saveFormLocal() {
    const form = document.getElementById('form-daftar');
    if (!form) return;
    const data = {};
    new FormData(form).forEach(function(val, key) {
      if (key === '_token') return;
      data[key] = data[key] ? [].concat(data[key], val) : val;
    });
    localStorage.setItem(FORM_KEY, JSON.stringify(data));
    const btn = document.getElementById('btn-save-local');
    if (btn) {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-check text-xs"></i> Tersimpan!';
      btn.style.color = '#16a34a';
      setTimeout(function() { btn.innerHTML = orig; btn.style.color = ''; }, 2000);
    }
  }

  function restoreFormLocal() {
    const saved = localStorage.getItem(FORM_KEY);
    if (!saved) return;
    try {
      const data = JSON.parse(saved);
      const form = document.getElementById('form-daftar');
      if (!form) return;
      Object.entries(data).forEach(function([key, val]) {
        const name = key.replace('[]', '');
        form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]').forEach(function(el) {
          if (el.type === 'radio') {
            if (el.value === val) el.checked = true;
          } else if (el.type === 'checkbox') {
            if ([].concat(val).includes(el.value)) el.checked = true;
          } else if (el.type !== 'file' && !el.value) {
            el.value = val;
          }
        });
      });
    } catch(e) {}
  }

  document.getElementById('btn-save-local')?.addEventListener('click', saveFormLocal);
  document.getElementById('form-daftar')?.addEventListener('submit', function() {
    localStorage.removeItem(FORM_KEY);
  });

  // ===== Feedback dan validasi ukuran upload berkas (real-time) =====
  function setupFileUploadFeedback() {
    const cvInput = document.getElementById('cv_ktp_portofolio_pdf');
    const cvBox = document.getElementById('cv-feedback');
    if (cvInput && cvBox) {
      cvInput.addEventListener('change', function() {
        const file = this.files?.[0];
        if (!file) {
          cvBox.className = 'hidden';
          cvBox.innerHTML = '';
          return;
        }
        const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        if (!isPdf) {
          cvBox.className = 'mt-2 p-2.5 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs flex items-center gap-2';
          cvBox.innerHTML = '<i class="fas fa-exclamation-circle text-red-600"></i> Berkas harus berformat PDF (.pdf)';
          this.value = '';
          return;
        }
        if (file.size > 10 * 1024 * 1024) {
          cvBox.className = 'mt-2 p-2.5 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs flex items-center gap-2';
          cvBox.innerHTML = '<i class="fas fa-exclamation-circle text-red-600"></i> Ukuran berkas (' + sizeMb + ' MB) melebihi batas maksimal 10 MB';
          this.value = '';
          return;
        }
        cvBox.className = 'mt-2 p-2.5 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 text-xs flex items-center gap-2';
        cvBox.innerHTML = '<i class="fas fa-check-circle text-emerald-600"></i> Berkas siap diunggah: <strong class="ml-1">' + file.name + '</strong> (' + sizeMb + ' MB)';
      });
    }

    const photoInput = document.getElementById('profile_photo');
    const photoBox = document.getElementById('photo-feedback');
    if (photoInput && photoBox) {
      photoInput.addEventListener('change', function() {
        const file = this.files?.[0];
        if (!file) {
          photoBox.className = 'hidden';
          photoBox.innerHTML = '';
          return;
        }
        const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
        const isImage = file.type.startsWith('image/') || /\.(jpe?g|png)$/i.test(file.name);
        if (!isImage) {
          photoBox.className = 'mt-2 p-2.5 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs flex items-center gap-2';
          photoBox.innerHTML = '<i class="fas fa-exclamation-circle text-red-600"></i> Berkas harus berformat gambar JPG/PNG';
          this.value = '';
          return;
        }
        if (file.size > 5 * 1024 * 1024) {
          photoBox.className = 'mt-2 p-2.5 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs flex items-center gap-2';
          photoBox.innerHTML = '<i class="fas fa-exclamation-circle text-red-600"></i> Ukuran foto (' + sizeMb + ' MB) melebihi batas maksimal 5 MB';
          this.value = '';
          return;
        }
        photoBox.className = 'mt-2 p-2.5 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 text-xs flex items-center gap-2';
        photoBox.innerHTML = '<i class="fas fa-check-circle text-emerald-600"></i> Foto siap diunggah: <strong class="ml-1">' + file.name + '</strong> (' + sizeMb + ' MB)';
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function() {
    setupRequiredHighlight();
    setupFileUploadFeedback();

    // Restore dari localStorage kalau fullname masih kosong (form baru)
    const firstInput = document.querySelector('#form-daftar input[name="fullname"]');
    if (firstInput && !firstInput.value) restoreFormLocal();

    setupNumericValidation(
      'input[name="phone_number"]',
      'No. HP hanya boleh berisi angka (contoh: 08123456789)'
    );


    // ===== Keterangan Jenis Magang (form statis fallback) =====
    (function() {
      var sel = document.getElementById('select-internship-type-static');
      var box = document.getElementById('internship-type-desc-static');
      if (!sel || !box) return;

      function updateDesc() {
        var v = sel.value;
        box.querySelectorAll('.desc-item').forEach(function(el) {
          // Tampilkan semua jika belum dipilih, atau hanya yang cocok
          el.style.display = (!v || el.dataset.forType === v) ? '' : 'none';
        });
      }

      sel.addEventListener('change', updateDesc);
      updateDesc(); // run on load
    })();

    // ===== TomSelect untuk Autocomplete dengan Create ======
    document.querySelectorAll('.tomselect-input').forEach(function(el) {
        var rawOptions = el.getAttribute('data-options');
        var options = [];
        if (rawOptions) {
            options = JSON.parse(rawOptions).map(function(name) {
                return { value: name, text: name };
            });
        }
        
        new TomSelect(el, {
            options: options,
            create: true,
            maxItems: 1,
            maxOptions: 50,
            sortField: {
                field: "text",
                direction: "asc"
            },
            render: {
                option_create: function(data, escape) {
                    return '<div class="create">Gunakan teks <strong>"' + escape(data.input) + '"</strong>&hellip;</div>';
                },
                no_results: function(data, escape) {
                    return '<div class="no-results">Tekan enter untuk menambahkan "' + escape(data.input) + '"</div>';
                }
            }
        });
    });
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
@endpush

@endsection
