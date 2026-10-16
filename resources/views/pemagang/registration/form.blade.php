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
  $ans   = $customAnswers ?? collect();
  $hasImmutableSnapshot = !empty($reg?->formSnapshot);
  $hasValidQueryPreview = !empty($validatedQueryDate) && empty($reg?->formSnapshot);
  $old   = function($field) use ($hasImmutableSnapshot, $hasValidQueryPreview, $effectiveStartDate, $ans, $reg) {
      if ($field === 'start_date') {
          if (($hasImmutableSnapshot || $hasValidQueryPreview) && !empty($effectiveStartDate)) {
              return $effectiveStartDate;
          }
          $rawOld = old('start_date');
          if ($rawOld !== null) {
              if (is_string($rawOld) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($rawOld))) {
                  [$y, $m, $d] = array_map('intval', explode('-', trim($rawOld)));
                  if (checkdate($m, $d, $y)) {
                      return sprintf('%04d-%02d-%02d', $y, $m, $d);
                  }
              }
              // Jika old('start_date') berupa array, non-string, atau tanggal kalender tidak valid,
              // JANGAN pulihkan nilai malformed tersebut ke input HTML!
              return !empty($effectiveStartDate) ? $effectiveStartDate : '';
          }
          return !empty($effectiveStartDate) ? $effectiveStartDate : ($ans->get($field) ?? $reg?->$field ?? '');
      }
      return old(
          $field,
          ($ans->get($field) ?? $reg?->$field ?? '')
      );
  };
  $label = 'block mb-1.5 text-sm font-medium text-gray-700';
  $input = 'block w-full rounded-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 px-3 py-2.5 text-sm';
  $radio = 'w-4 h-4 text-green-600 border-gray-300 focus:ring-2 focus:ring-green-500';
  $check = $radio;
  $item  = 'flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition cursor-pointer';
  $group = 'border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden';

  // Normalize tanggal ke format Y-m-d untuk input type="date" secara aman terhadap array / non-string
  $toDateInput = function(mixed $val): string {
    if (!is_string($val) || trim($val) === '') return '';
    $trimmed = trim($val);
    // Sudah format Y-m-d
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        [$y, $m, $d] = array_map('intval', explode('-', $trimmed));
        if (checkdate($m, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
        return '';
    }
    // Coba parse dengan Carbon
    try { return \Carbon\Carbon::parse($trimmed)->format('Y-m-d'); }
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

    @if(session('schema_adjusted'))
      <div class="mb-5 bg-blue-50 border border-blue-200 text-blue-800 text-sm px-4 py-3 rounded-lg flex items-center gap-2">
        <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span>{{ session('schema_adjusted') }}</span>
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

    <form id="form-daftar"
      action="{{ route('pemagang.registration.store') }}"
      method="POST"
      enctype="multipart/form-data"
      class="space-y-5"
      data-current-year="{{ $activeSchema?->year ?? $queryDateYear ?? ($effectiveStartDate ? (int) substr($effectiveStartDate, 0, 4) : now()->year) }}"
      data-has-snapshot="{{ ($reg && $reg->formSnapshot) ? '1' : '0' }}"
      data-snapshot-year="{{ $reg?->formSnapshot?->schema?->year ?? '' }}">
      @csrf

      @if(!empty($isQueryDateInvalid))
        <div class="mb-5 bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3 rounded-lg flex items-center gap-2">
          <svg class="w-5 h-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          <span>Format tanggal mulai magang yang dipilih tidak valid. Harap pilih tanggal kalender yang sah.</span>
        </div>
      @elseif(!empty($validatedQueryDate) && empty($activeSchema))
        <div class="mb-5 bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 rounded-lg flex items-center gap-2">
          <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          <span>Formulir pendaftaran untuk periode magang tahun {{ $queryDateYear }} belum dibuka atau belum dipublikasikan oleh Admin.</span>
        </div>
      @endif

      @if(isset($activeSchema) && $activeSchema)
        <input type="hidden" name="form_schema_version" value="{{ $activeSchema->version_code }}">
        <input type="hidden" name="form_schema_id" value="{{ $activeSchema->id }}">
      @endif

      @if(!empty($useFormFields) && $useFormFields)
        {{-- ===== FORM DINAMIS (dari pengaturan admin) ===== --}}
        @include('pemagang.registration.partials.dynamic-form', [
            'mainFields'   => $mainFields,
            'extraFields'  => $extraFields,
            'customFields' => $customFields ?? collect(),
            'divisions'    => $divisions,
            'reg'          => $reg,
            'old'          => $old,
            'input'        => $input,
            'label'        => $label,
            'radio'        => $radio,
            'toDateInput'  => $toDateInput,
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

        {{-- Tanggal Mulai Magang (Pastikan selalu ada input start_date jika skema belum menyediakannya di mainFields) --}}
        @if($mainFields->where('field_key', 'start_date')->isEmpty())
        <div>
          <label class="{{ $label }}">Kapan Berencana Mulai Magang/PKL?</label>
          <input type="date" name="start_date"
            min="{{ date('Y-m-d') }}"
            class="{{ $input }}" value="{{ $toDateInput($old('start_date')) }}">
        </div>
        @endif
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

          {{-- Tempat & Tanggal Lahir --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Tempat Lahir <span class="text-red-500">*</span></label>
              <input type="text" name="birth_place" required placeholder="Contoh: Sleman, Yogyakarta"
                class="{{ $input }}" value="{{ $old('birth_place') }}">
            </div>
            <div>
              <label class="{{ $label }}">Tanggal Lahir <span class="text-red-500">*</span></label>
              <input type="date" name="born_date" required
                max="{{ date('Y-m-d') }}"
                class="{{ $input }}" value="{{ $toDateInput($old('born_date')) }}">
            </div>
          </div>

          {{-- Jenis Kelamin & Agama --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
            <div>
              <label class="{{ $label }}">Agama <span class="text-red-500">*</span></label>
              <select name="religion" required class="{{ $input }}">
                <option value="">-- Pilih Agama --</option>
                @foreach(['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'] as $rel)
                  <option value="{{ $rel }}" @selected($old('religion') === $rel)>{{ $rel }}</option>
                @endforeach
              </select>
            </div>
          </div>

          {{-- Tempat Lahir & Agama (Hanya ditampilkan jika kolom schema telah tersedia) --}}
          @php
            $hasBirthPlaceCol = \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'birth_place');
            $hasReligionCol   = \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'religion');
          @endphp
          @if($hasBirthPlaceCol || $hasReligionCol)
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @if($hasBirthPlaceCol)
            <div>
              <label class="{{ $label }}">Tempat Lahir</label>
              <input type="text" name="birth_place" placeholder="Contoh: Sleman" maxlength="100"
                class="{{ $input }}" value="{{ $old('birth_place') }}">
              @error('birth_place') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            @endif
            @if($hasReligionCol)
            <div>
              <label class="{{ $label }}">Agama</label>
              <select name="religion" class="{{ $input }}">
                <option value="">-- Pilih Agama (Opsional) --</option>
                @foreach(['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'] as $rel)
                  <option value="{{ $rel }}" @selected($old('religion') === $rel)>{{ $rel }}</option>
                @endforeach
              </select>
              @error('religion') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            @endif
          </div>
          @endif

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

      {{-- ==================== 4. BERKAS PENDAFTARAN MULTI-UPLOAD ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-1 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs flex items-center justify-center font-bold">4</span>
          Berkas Pendaftaran <span class="text-xs font-normal text-gray-500 normal-case">(Terkategori & Multi-Upload)</span>
        </h3>
        <p class="text-xs text-gray-400 mb-4">Unggah dokumen sesuai kategorinya. Anda dapat mengunggah sebagian dokumen sekarang dan melengkapi dokumen susulan kemudian.</p>

        {{-- Daftar Berkas yang Telah Tersimpan di Server --}}
        @php
          $allAttachments = $reg ? $reg->attachments()->latest('id')->get() : collect();
        @endphp
        @if($allAttachments->isNotEmpty() || $reg?->profile_photo)
          <div class="mb-5 p-4 bg-gray-50 border border-gray-200 rounded-xl">
            <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-3 flex items-center gap-2">
              <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Berkas Tersimpan di Server ({{ $allAttachments->count() }})
            </h4>
            <div class="space-y-2">
              @foreach($allAttachments as $att)
                <div class="flex flex-wrap items-center justify-between gap-2 p-2.5 bg-white border border-gray-200 rounded-lg text-xs">
                  <div class="flex items-center gap-2 min-w-0">
                    <span class="px-2 py-0.5 rounded font-semibold text-[10px] bg-emerald-100 text-emerald-800 uppercase">
                      {{ \App\Models\RegistrationAttachment::getCategoryLabel($att->attachment_type) }}
                    </span>
                    <a href="{{ route('pemagang.registration.attachment.download', $att->id) }}" target="_blank" class="text-blue-600 font-medium hover:underline truncate max-w-xs">
                      {{ $att->original_name }}
                    </a>
                    <span class="text-gray-400">({{ round($att->file_size / 1024, 1) }} KB)</span>
                    @if($att->drive_sync_status === 'synced')
                      <span class="text-[10px] text-emerald-600 font-medium flex items-center gap-1"><i class="fas fa-cloud-check"></i> Google Drive</span>
                    @else
                      <span class="text-[10px] text-gray-400 font-medium flex items-center gap-1"><i class="fas fa-hard-drive"></i> Lokal Privat</span>
                    @endif
                  </div>

                  @if(!$reg || $reg->is_draft || in_array($reg->internship_status, ['new', 'waiting']))
                    <form action="{{ route('pemagang.registration.attachment.delete', $att->id) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus berkas ini?')">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="text-red-500 hover:text-red-700 font-medium flex items-center gap-1 px-2 py-1 rounded hover:bg-red-50 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Hapus
                      </button>
                    </form>
                  @endif
                </div>
              @endforeach
            </div>
          </div>
        @endif

        {{-- Form Upload Per Kategori --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          {{-- 1. CV --}}
          <div class="p-3.5 border border-gray-200 rounded-lg bg-white">
            <label class="{{ $label }}">Curriculum Vitae (CV) <span class="text-xs text-gray-400 font-normal">(PDF/DOC/DOCX, Maks 10MB)</span></label>
            <input type="file" name="cv" accept=".pdf,.doc,.docx" class="{{ $input }} file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer" style="--file-bg: #1a5c38;">
            <p class="text-[11px] text-gray-400 mt-1">Lampirkan CV terbaru Anda.</p>
          </div>

          {{-- 2. KTM / KTP --}}
          <div class="p-3.5 border border-gray-200 rounded-lg bg-white">
            <label class="{{ $label }}">KTM atau KTP <span class="text-xs text-gray-400 font-normal">(PDF/JPG/PNG, Maks 5MB)</span></label>
            <input type="file" name="identity" accept=".pdf,.jpg,.jpeg,.png" class="{{ $input }} file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer" style="--file-bg: #1a5c38;">
            <p class="text-[11px] text-gray-400 mt-1">Kartu identitas mahasiswa atau KTP pendaftar.</p>
          </div>

          {{-- 3. Portofolio (Multi-Upload) --}}
          <div class="p-3.5 border border-gray-200 rounded-lg bg-white md:col-span-2">
            <label class="{{ $label }}">Portofolio Karya <span class="text-xs text-emerald-700 font-semibold">(Mendukung Banyak File, Maks 10MB/berkas)</span></label>
            <input type="file" name="portfolio[]" multiple accept=".pdf,.zip,.rar,.mp4,.doc,.docx,.png,.jpg,.jpeg" class="{{ $input }} file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer" style="--file-bg: #1a5c38;">
            <p class="text-[11px] text-gray-400 mt-1">Pilih satu atau beberapa file portofolio, sertifikat penghargaan, atau sampel karya (PDF, ZIP, RAR, video MP4, dokumen).</p>
          </div>

          {{-- 4. Surat Pengantar --}}
          <div class="p-3.5 border border-gray-200 rounded-lg bg-white">
            <label class="{{ $label }}">Surat Pengantar Kampus / Sekolah <span class="text-xs text-gray-400 font-normal">(PDF/DOCX, Maks 10MB)</span></label>
            <input type="file" name="cover_letter" accept=".pdf,.doc,.docx" class="{{ $input }} file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer" style="--file-bg: #1a5c38;">
            <p class="text-[11px] text-gray-400 mt-1">Surat permohonan magang dari institusi pendidikan.</p>
          </div>

          {{-- 5. Proposal Magang --}}
          <div class="p-3.5 border border-gray-200 rounded-lg bg-white">
            <label class="{{ $label }}">Proposal Magang / PKL <span class="text-xs text-gray-400 font-normal">(PDF/DOCX, Maks 10MB)</span></label>
            <input type="file" name="proposal" accept=".pdf,.doc,.docx" class="{{ $input }} file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer" style="--file-bg: #1a5c38;">
            <p class="text-[11px] text-gray-400 mt-1">Opsional jika kampus mensyaratkan proposal kegiatan.</p>
          </div>

          {{-- 6. Dokumen Pendukung Tambahan (Multi-Upload) --}}
          <div class="p-3.5 border border-gray-200 rounded-lg bg-white">
            <label class="{{ $label }}">Dokumen Pendukung Lainnya <span class="text-xs text-gray-400 font-normal">(Multi-File, Maks 10MB/berkas)</span></label>
            <input type="file" name="supporting_document[]" multiple accept=".pdf,.doc,.docx,.zip,.rar,.png,.jpg,.jpeg" class="{{ $input }} file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer" style="--file-bg: #1a5c38;">
            <p class="text-[11px] text-gray-400 mt-1">Transkrip nilai, sertifikat keahlian, atau surat izin orang tua.</p>
          </div>

          {{-- 7. Foto Profil --}}
          <div class="p-3.5 border border-gray-200 rounded-lg bg-white">
            <label class="{{ $label }}">Pasfoto Resmi <span class="text-xs text-gray-400 font-normal">(JPG/PNG/WEBP, Maks 5MB)</span></label>
            <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" class="{{ $input }} file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer" style="--file-bg: #1a5c38;">
            <p class="text-[11px] text-gray-400 mt-1">Pasfoto formal berlatar belakang rapi untuk identitas pemagang.</p>
          </div>
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

      {{-- Pertanyaan Tambahan dari Form Builder untuk form statis --}}
      @if(!empty($customFields) && $customFields->isNotEmpty())
      <div class="border-b border-gray-100 pb-5 pt-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-green-100 text-green-800 text-xs flex items-center justify-center font-bold">5</span>
          Pertanyaan Tambahan
        </h3>
        <div class="space-y-4">
          @foreach($customFields as $field)
          <div>
            <label class="{{ $label }}">
              {{ $field->label }}
              @if($field->is_required) <span class="text-red-500">*</span> @endif
            </label>
            @if($field->field_type === 'textarea')
              <textarea name="{{ $field->field_key }}" rows="3" placeholder="{{ $field->placeholder }}" class="{{ $input }} resize-none" {{ $field->is_required ? 'required' : '' }}>{{ $old($field->field_key) }}</textarea>
            @elseif($field->field_type === 'select')
              <select name="{{ $field->field_key }}" class="{{ $input }}" {{ $field->is_required ? 'required' : '' }}>
                <option value="">{{ $field->placeholder ?: '-- Pilih --' }}</option>
                @foreach($field->options ?? [] as $opt)
                  <option value="{{ $opt['value'] }}" {{ $old($field->field_key) == $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                @endforeach
              </select>
            @elseif($field->field_type === 'radio')
              <div class="flex gap-4">
                @foreach($field->options ?? [] as $opt)
                  <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="radio" name="{{ $field->field_key }}" value="{{ $opt['value'] }}" class="{{ $radio }}" {{ $old($field->field_key) == $opt['value'] ? 'checked' : '' }} {{ $field->is_required ? 'required' : '' }}>
                    {{ $opt['label'] }}
                  </label>
                @endforeach
              </div>
            @elseif($field->field_type === 'checkbox')
              <div class="{{ $group }}">
                @php $savedVals = (array) $old($field->field_key); @endphp
                @foreach($field->options ?? [] as $opt)
                  <label class="{{ $item }}">
                    <input type="checkbox" name="{{ $field->field_key }}[]" value="{{ $opt['value'] }}" class="{{ $check }}" {{ in_array($opt['value'], $savedVals) ? 'checked' : '' }}>
                    <span class="text-sm text-gray-700">{{ $opt['label'] }}</span>
                  </label>
                @endforeach
              </div>
            @else
              <input type="{{ $field->field_type }}" name="{{ $field->field_key }}" value="{{ $old($field->field_key) }}" placeholder="{{ $field->placeholder }}" class="{{ $input }}" {{ $field->is_required ? 'required' : '' }}>
            @endif
            @if($field->helper_text)
              <p class="mt-1 text-xs text-gray-400">{{ $field->helper_text }}</p>
            @endif
          </div>
          @endforeach
        </div>
      </div>
      @endif

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
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition"
            title="Simpan data identitas dasar secara lokal di browser ini">
            <i class="fas fa-save text-xs"></i>
            Simpan di Browser
          </button>
          <button type="button" id="btn-clear-local"
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 rounded-lg transition"
            title="Hapus data formulir yang tersimpan di browser ini">
            <i class="fas fa-trash-alt text-xs"></i>
            Hapus Draft Browser
          </button>
          <p class="text-[11px] text-gray-500 mt-2 w-full">
            <i class="fas fa-shield-alt text-emerald-600"></i> <strong>Privasi Penyimpanan Lokal:</strong> Fitur "Simpan di Browser" bersifat opsional dan menyimpan data identitas dasar secara teks biasa (tidak terenkripsi) di perangkat ini khusus untuk akun Anda. Berkas lampiran dan jawaban formulir tidak pernah disimpan di penyimpanan browser. <strong>Peringatan Perangkat Bersama:</strong> Jika Anda menggunakan komputer publik atau bersama, harap gunakan tombol "Hapus Draft Browser" sebelum meninggalkan perangkat untuk menjaga privasi akun Anda.
          </p>
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

  // ===== Client Storage Privacy & Hardening (Fix 2) =====
  const CURRENT_USER_ID = '{{ auth()->id() }}';
  const MANUAL_DRAFT_KEY = 'sertif_manual_draft_{{ auth()->id() }}';
  const TRANSIENT_YEAR_KEY = 'sertif_year_transient_{{ auth()->id() }}';
  const SAFE_CORE_FIELD_NAMES = [
    'fullname', 'student_id', 'born_date', 'birth_place', 'religion', 'gender',
    'phone_number', 'email', 'current_city', 'institution_name',
    'faculty', 'study_program'
  ];

  function extractSafeCoreData(form) {
    const data = {};
    if (!form) return data;
    SAFE_CORE_FIELD_NAMES.forEach(function(name) {
      const el = form.querySelector('[name="' + name + '"]');
      if (el && el.type !== 'file' && el.value) {
        data[name] = el.value.trim();
      }
    });
    return data;
  }

  function applySafeCoreData(form, data) {
    if (!form || !data || typeof data !== 'object') return;
    SAFE_CORE_FIELD_NAMES.forEach(function(name) {
      if (data[name] !== undefined) {
        const el = form.querySelector('[name="' + name + '"]');
        if (el && el.type !== 'file' && !el.value) {
          el.value = data[name];
        }
      }
    });
  }

  // 1. Simpan Manual ke Browser (Hanya data identitas dasar, per-user id)
  function saveFormLocalManual() {
    if (!CURRENT_USER_ID) return;
    const form = document.getElementById('form-daftar');
    if (!form) return;
    const data = extractSafeCoreData(form);
    localStorage.setItem(MANUAL_DRAFT_KEY, JSON.stringify(data));
    const btn = document.getElementById('btn-save-local');
    if (btn) {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-check text-xs"></i> Tersimpan!';
      btn.style.color = '#16a34a';
      setTimeout(function() { btn.innerHTML = orig; btn.style.color = ''; }, 2000);
    }
  }

  function restoreFormLocalManual() {
    if (!CURRENT_USER_ID) return;
    const saved = localStorage.getItem(MANUAL_DRAFT_KEY);
    if (!saved) return;
    try {
      const data = JSON.parse(saved);
      const form = document.getElementById('form-daftar');
      applySafeCoreData(form, data);
    } catch(e) {}
  }

  // 2. Penyimpanan Sementara Khusus Pergantian Tahun (Transient di sessionStorage)
  function saveYearSwitchTransientState() {
    if (!CURRENT_USER_ID) return;
    const form = document.getElementById('form-daftar');
    if (!form) return;
    const data = extractSafeCoreData(form);
    try {
      sessionStorage.setItem(TRANSIENT_YEAR_KEY, JSON.stringify(data));
    } catch(e) {}
  }

  function restoreYearSwitchTransientState() {
    if (!CURRENT_USER_ID) return;
    try {
      const saved = sessionStorage.getItem(TRANSIENT_YEAR_KEY);
      if (!saved) return;
      const data = JSON.parse(saved);
      const form = document.getElementById('form-daftar');
      applySafeCoreData(form, data);
      // Bersihkan seketika setelah restore (Fix 2: ephemeral transient storage)
      sessionStorage.removeItem(TRANSIENT_YEAR_KEY);
    } catch(e) {}
  }

  // 3. Hapus Draft Browser Manual (Mekanisme Pembersihan Perangkat Bersama)
  function clearFormLocalManual() {
    if (CURRENT_USER_ID) {
      localStorage.removeItem(MANUAL_DRAFT_KEY);
      sessionStorage.removeItem(TRANSIENT_YEAR_KEY);
    }
    const btn = document.getElementById('btn-clear-local');
    if (btn) {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-check text-xs"></i> Terhapus!';
      setTimeout(function() { btn.innerHTML = orig; }, 2000);
    }
  }

  document.getElementById('btn-save-local')?.addEventListener('click', saveFormLocalManual);
  document.getElementById('btn-clear-local')?.addEventListener('click', clearFormLocalManual);
  document.getElementById('form-daftar')?.addEventListener('submit', function() {
    if (CURRENT_USER_ID) {
      localStorage.removeItem(MANUAL_DRAFT_KEY);
      sessionStorage.removeItem(TRANSIENT_YEAR_KEY);
    }
  });

  // ===== Watcher Perubahan start_date Lintas Tahun (Gate 1 UX) =====
  function setupStartDateWatcher() {
    const form = document.getElementById('form-daftar');
    const startDateInput = document.querySelector('input[name="start_date"]');
    if (!form || !startDateInput) return;

    const currentYear = parseInt(form.dataset.currentYear || new Date().getFullYear(), 10);
    const hasSnapshot = form.dataset.hasSnapshot === '1';
    const snapshotYear = form.dataset.snapshotYear ? parseInt(form.dataset.snapshotYear, 10) : null;
    let previousValue = startDateInput.value;

    startDateInput.addEventListener('change', function() {
      const val = this.value;
      if (!val) return;

      const dateMatch = val.match(/^(\d{4})-\d{2}-\d{2}$/);
      if (!dateMatch) return;
      const selectedYear = parseInt(dateMatch[1], 10);

      // 1. Jika pendaftaran sudah mempunyai snapshot historis terverifikasi
      if (hasSnapshot && snapshotYear && selectedYear !== snapshotYear) {
        showFormError('Pendaftaran ini telah memiliki snapshot formulir tahun ' + snapshotYear + ' dan tidak dapat diubah ke periode tahun ' + selectedYear + '.');
        this.value = previousValue;
        return;
      }

      // 2. Jika tahun berbeda dari skema yang sedang aktif ditampilkan
      if (selectedYear >= 2020 && selectedYear <= 2099 && selectedYear !== currentYear) {
        // Simpan data identitas dasar secara sementara ke sessionStorage (Fix 2)
        saveYearSwitchTransientState();

        // Tampilkan indikator ramah
        let loadingBanner = document.getElementById('schema-loading-banner');
        if (!loadingBanner) {
          loadingBanner = document.createElement('div');
          loadingBanner.id = 'schema-loading-banner';
          loadingBanner.className = 'fixed inset-0 bg-gray-900/40 z-50 flex items-center justify-center p-4 backdrop-blur-sm';
          loadingBanner.innerHTML = `
            <div class="bg-white rounded-xl shadow-2xl p-6 max-w-sm w-full text-center">
              <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 mb-3 animate-spin">
                <i class="fas fa-circle-notch text-xl"></i>
              </div>
              <h3 class="text-base font-semibold text-gray-800 mb-1">Memuat Formulir Periode ` + selectedYear + `</h3>
              <p class="text-xs text-gray-500">Menyesuaikan pertanyaan formulir sesuai tahun mulai magang yang Anda pilih...</p>
            </div>
          `;
          document.body.appendChild(loadingBanner);
        }

        // Safe client-side reload with start_date query parameter
        try {
          const url = new URL(window.location.href);
          url.searchParams.set('start_date', val);
          window.location.href = url.toString();
        } catch (e) {
          const sep = window.location.href.indexOf('?') !== -1 ? '&' : '?';
          window.location.href = window.location.href + sep + 'start_date=' + encodeURIComponent(val);
        }
      } else {
        previousValue = val;
      }
    });
  }

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
    setupStartDateWatcher();

    // Restore data transisi pergantian tahun dari sessionStorage (jika baru reload lintas tahun)
    restoreYearSwitchTransientState();

    // Restore dari manual browser draft kalau fullname masih kosong (form baru)
    const firstInput = document.querySelector('#form-daftar input[name="fullname"]');
    if (firstInput && !firstInput.value) restoreFormLocalManual();

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
