@extends('pemagang.layouts.app')

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<style>
  .ts-wrapper {
      width: 100% !important;
      max-width: 100% !important;
  }
  .ts-control {
      border: 1px solid #d1d5db !important; /* border-gray-300 */
      border-radius: 0.5rem !important; /* rounded-lg */
      padding: 0.5rem 0.75rem !important; /* px-3 py-2 */
      font-size: 0.875rem !important; /* text-sm */
      min-height: 42px;
      width: 100% !important;
      max-width: 100% !important;
      box-sizing: border-box !important;
  }
  .ts-control.focus {
      border-color: #1a5c38 !important;
      box-shadow: 0 0 0 1px #1a5c38 !important;
  }
  .tom-select .ts-dropdown {
      font-size: 0.875rem !important; /* text-sm */
      border-radius: 0.5rem !important;
      max-width: 100% !important;
      box-sizing: border-box !important;
      z-index: 50 !important;
  }
  .ts-dropdown .option {
      word-break: break-word !important;
  }
  /* Sembunyikan scrollbar pada stepper mobile tapi tetap bisa di-scroll */
  .no-scrollbar::-webkit-scrollbar {
      display: none;
  }
  .no-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
  }
  /* Kolom setengah lebar (half-col) pada grid desktop (>= 768px):
     Menjajarkan input secara presisi meskipun panjang teks label atau helper text berbeda */
  @media (min-width: 768px) {
    .form-half-col {
      display: flex;
      flex-direction: column;
      height: 100%;
    }
    .form-half-col .form-label-wrap {
      min-height: 2.875rem; /* ~46px, menampung 2 baris teks label dengan rapi */
      display: flex;
      align-items: flex-start;
      margin-bottom: 0.375rem;
    }
    .form-half-col .form-label-wrap > label {
      margin-bottom: 0 !important;
      line-height: 1.35;
    }
    .form-half-col .form-control-wrap {
      min-height: 42px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }
    .form-half-col .form-helper-wrap {
      margin-top: 0.25rem;
    }
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

      if ($field === 'institution_name') {
          $val = old('institution_name');
          if ($val !== null && $val !== '') return $val;
          if ($ans->has('institution_name') && !empty($ans->get('institution_name'))) return $ans->get('institution_name');
          if (!empty($reg?->institution_name)) return $reg->institution_name;
          if (!empty($reg?->institution?->name)) return $reg->institution->name;
          if (!empty($reg?->institution_id)) {
              $inst = \App\Models\Institution::find($reg->institution_id);
              if ($inst) return $inst->name;
          }
          return '';
      }

      if ($field === 'current_city') {
          $val = old('current_city');
          if ($val !== null && $val !== '') return $val;
          if ($ans->has('current_city') && !empty($ans->get('current_city'))) return $ans->get('current_city');
          if (!empty($reg?->current_city)) return $reg->current_city;
          if (!empty($reg?->city?->name)) return $reg->city->name;
          if (!empty($reg?->city_id)) {
              $c = \App\Models\City::find($reg->city_id);
              if ($c) return $c->name;
          }
          return '';
      }

      if ($field === 'study_program') {
          $val = old('study_program');
          if ($val !== null && $val !== '') return $val;
          if ($ans->has('study_program') && !empty($ans->get('study_program'))) return $ans->get('study_program');
          if (!empty($reg?->study_program)) return $reg->study_program;
          if (!empty($reg?->studyProgramRel?->name)) return $reg->studyProgramRel->name;
          if (!empty($reg?->study_program_id)) {
              $sp = \App\Models\StudyProgram::find($reg->study_program_id);
              if ($sp) return $sp->name;
          }
          return '';
      }

      if ($field === 'faculty') {
          $val = old('faculty');
          if ($val !== null && $val !== '') return $val;
          if ($ans->has('faculty') && !empty($ans->get('faculty'))) return $ans->get('faculty');
          if (!empty($reg?->faculty)) return $reg->faculty;
          if (!empty($reg?->facultyRel?->name)) return $reg->facultyRel->name;
          if (!empty($reg?->faculty_id)) {
              $fac = \App\Models\Faculty::find($reg->faculty_id);
              if ($fac) return $fac->name;
          }
          return '';
      }

      if ($field === 'gender') {
          $val = old('gender');
          if ($val !== null && $val !== '') return $val;
          if ($ans->has('gender') && !empty($ans->get('gender'))) return $ans->get('gender');
          if (!empty($reg?->gender)) return $reg->gender;
          if (!empty($reg?->getRawOriginal('gender'))) return $reg->getRawOriginal('gender');
          return '';
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

<div class="max-w-4xl mx-auto w-full min-w-0">
  <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 md:p-8 w-full min-w-0 overflow-hidden">
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

    {{-- Stepper Progress Bar (5 Steps) --}}
    <div class="mb-6 rounded-xl border border-gray-150 bg-gray-50/90 p-2 sm:p-3 sticky top-4 z-20 backdrop-blur-md bg-white/95 shadow-xs overflow-hidden">
      <div class="flex overflow-x-auto no-scrollbar sm:grid sm:grid-cols-5 gap-1.5 sm:gap-2 text-xs pb-1 sm:pb-0 scroll-smooth items-center" id="form-stepper">
        <a href="#section-1-data-diri" data-step="1" class="stepper-tab shrink-0 flex items-center gap-1.5 sm:gap-2 px-2.5 py-1.5 sm:p-1.5 rounded-lg text-emerald-800 font-semibold transition hover:opacity-80">
          <span class="step-num flex h-5 w-5 sm:h-6 sm:w-6 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-white font-bold text-[10px] sm:text-[11px]">1</span>
          <span class="truncate whitespace-nowrap sm:whitespace-normal">Data Diri & Edukasi</span>
        </a>
        <a href="#section-2-pilihan-durasi" data-step="2" class="stepper-tab shrink-0 flex items-center gap-1.5 sm:gap-2 px-2.5 py-1.5 sm:p-1.5 rounded-lg text-gray-600 font-medium transition hover:text-emerald-700">
          <span class="step-num flex h-5 w-5 sm:h-6 sm:w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-700 font-bold text-[10px] sm:text-[11px]">2</span>
          <span class="truncate whitespace-nowrap sm:whitespace-normal">Pilihan & Durasi</span>
        </a>
        <a href="#section-3-berkas-portofolio" data-step="3" class="stepper-tab shrink-0 flex items-center gap-1.5 sm:gap-2 px-2.5 py-1.5 sm:p-1.5 rounded-lg text-gray-600 font-medium transition hover:text-emerald-700">
          <span class="step-num flex h-5 w-5 sm:h-6 sm:w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-700 font-bold text-[10px] sm:text-[11px]">3</span>
          <span class="truncate whitespace-nowrap sm:whitespace-normal">Berkas & Portofolio <span class="hidden md:inline text-[10px] sm:text-[11px] font-normal text-gray-400"></span></span>
        </a>
        <a href="#section-4-informasi-tambahan" data-step="4" class="stepper-tab shrink-0 flex items-center gap-1.5 sm:gap-2 px-2.5 py-1.5 sm:p-1.5 rounded-lg text-gray-600 font-medium transition hover:text-emerald-700">
          <span class="step-num flex h-5 w-5 sm:h-6 sm:w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-700 font-bold text-[10px] sm:text-[11px]">4</span>
          <span class="truncate whitespace-nowrap sm:whitespace-normal">Informasi Tambahan</span>
        </a>
        <a href="#section-5-finalisasi-submit" data-step="5" class="stepper-tab shrink-0 flex items-center gap-1.5 sm:gap-2 px-2.5 py-1.5 sm:p-1.5 rounded-lg text-gray-600 font-medium transition hover:text-emerald-700">
          <span class="step-num flex h-5 w-5 sm:h-6 sm:w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-700 font-bold text-[10px] sm:text-[11px]">5</span>
          <span class="truncate whitespace-nowrap sm:whitespace-normal">Finalisasi & Submit</span>
        </a>
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
            'groupedFields' => $groupedFields ?? null,
            'mainFields'    => $mainFields,
            'extraFields'   => $extraFields,
            'customFields'  => $customFields ?? collect(),
            'divisions'     => $divisions,
            'institutions'  => $institutions ?? collect(),
            'cities'        => $cities ?? collect(),
            'faculties'     => $faculties ?? collect(),
            'studyPrograms' => $studyPrograms ?? collect(),
            'reg'           => $reg,
            'old'           => $old,
            'input'         => $input,
            'label'         => $label,
            'radio'         => $radio,
            'toDateInput'   => $toDateInput,
        ])
        {{-- Fallback fields hanya jika skema tidak mendefinisikan field tersebut sama sekali --}}
        @php
            $definedFieldKeys = $activeSchema ? $activeSchema->fields->pluck('field_key')->all() : [];
            $hasFallbackSupervisor = (!in_array('supervisor_name', $definedFieldKeys) && $extraFields->where('field_key', 'supervisor_name')->isEmpty())
                || (!in_array('supervisor_contact', $definedFieldKeys) && $extraFields->where('field_key', 'supervisor_contact')->isEmpty());
        @endphp

        @if($hasFallbackSupervisor)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5 pt-3">
          @if(!in_array('supervisor_name', $definedFieldKeys) && $extraFields->where('field_key', 'supervisor_name')->isEmpty())
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Nama Pembimbing (Opsional)</label>
            </div>
            <div class="form-control-wrap">
              <input type="text" name="supervisor_name" placeholder="Nama pembimbing (jika ada)"
                class="{{ $input }}" value="{{ $old('supervisor_name', $reg?->supervisor_name !== '-' ? $reg?->supervisor_name : '') }}">
            </div>
          </div>
          @endif

          @if(!in_array('supervisor_contact', $definedFieldKeys) && $extraFields->where('field_key', 'supervisor_contact')->isEmpty())
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">No. WA Pembimbing (Opsional)</label>
            </div>
            <div class="form-control-wrap">
              <input type="tel" name="supervisor_contact" placeholder="08xxxxxxxxxx"
                pattern="[0-9]*" inputmode="numeric" title="Hanya boleh angka"
                class="{{ $input }}" value="{{ $old('supervisor_contact', $reg?->supervisor_contact !== '-' ? $reg?->supervisor_contact : '') }}">
            </div>
            <div class="form-helper-wrap">
              <p class="mt-1 text-xs text-gray-400">Hanya angka</p>
            </div>
          </div>
          @endif
        </div>
        @endif

        @if(!in_array('current_activities', $definedFieldKeys) && $extraFields->where('field_key', 'current_activities')->isEmpty())
        <div class="pt-3 min-w-0">
          <label class="{{ $label }}">Kegiatan Lain Selain Magang <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
          <textarea name="current_activities" rows="3" placeholder="Contoh: Kuliah malam, freelance, dll (tulis '-' jika tidak ada)"
            class="{{ $input }} resize-none">{{ $old('current_activities', $reg?->current_activities !== '-' ? $reg?->current_activities : '') }}</textarea>
        </div>
        @endif

        {{-- Tanggal Mulai Magang (Pastikan selalu ada input start_date jika skema belum menyediakannya sama sekali) --}}
        @if(!in_array('start_date', $definedFieldKeys) && $mainFields->where('field_key', 'start_date')->isEmpty() && $extraFields->where('field_key', 'start_date')->isEmpty())
        <div class="pt-3 min-w-0">
          <label class="{{ $label }}">Kapan Berencana Mulai Magang/PKL? <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
          <input type="date" name="start_date"
            min="{{ date('Y-m-d') }}"
            class="{{ $input }}" value="{{ $toDateInput($old('start_date')) }}">
        </div>
        @endif
      @else
      {{-- ===== FORM STATIS (fallback) ===== --}}

      {{-- ==================== 1. DATA DIRI & EDUKASI ==================== --}}
      <div id="section-1-data-diri" class="border-b border-gray-100 pb-6 pt-2">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">1</span>
          Data Diri & Edukasi <span class="text-xs font-normal text-red-500 normal-case">(Wajib)</span>
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
          {{-- Nama Lengkap --}}
          <div class="col-span-1 md:col-span-2 min-w-0">
            <label class="{{ $label }}">Nama Lengkap <span class="text-red-500">*</span></label>
            <input type="text" name="fullname" required placeholder="Muhammad Sumbul"
              class="{{ $input }}" value="{{ $old('fullname') }}">
          </div>

          {{-- Tanggal Lahir --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Tanggal Lahir <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="date" name="born_date" required
                max="{{ date('Y-m-d') }}"
                class="{{ $input }} @error('born_date') border-red-500 focus:ring-red-500 @enderror" value="{{ $toDateInput($old('born_date')) }}">
            </div>
            @error('born_date')
            <div class="form-helper-wrap">
              <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
            </div>
            @enderror
          </div>

          {{-- Jenis Kelamin --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Jenis Kelamin <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              @php
                $valGender = strtolower(trim((string) $old('gender')));
                $isMale = in_array($valGender, ['laki-laki', 'l', 'male', 'pria'], true);
                $isFemale = in_array($valGender, ['perempuan', 'p', 'female', 'wanita'], true);
              @endphp
              <div class="flex flex-wrap items-center gap-4 sm:gap-6 pt-1 min-h-[42px]">
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                  <input type="radio" name="gender" value="Laki-laki" class="{{ $radio }}"
                    @checked($isMale)> Laki-laki
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                  <input type="radio" name="gender" value="Perempuan" class="{{ $radio }}"
                    @checked($isFemale)> Perempuan
                </label>
              </div>
            </div>
            @error('gender')
            <div class="form-helper-wrap">
              <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
            </div>
            @enderror
          </div>

          {{-- Agama --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Agama <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              @php
                $valRel = strtolower(trim((string) $old('religion')));
              @endphp
              <select name="religion" required class="{{ $input }} @error('religion') border-red-500 focus:ring-red-500 @enderror">
                <option value="">-- Pilih Agama --</option>
                @foreach(['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'] as $rel)
                  @php
                    $optRelLower = strtolower($rel);
                    $isSelected = ($valRel === $optRelLower)
                      || ($optRelLower === 'kristen protestan' && in_array($valRel, ['kristen', 'protestan', 'kristen protestan'], true))
                      || ($optRelLower === 'khonghucu' && in_array($valRel, ['khonghucu', 'konghucu'], true));
                  @endphp
                  <option value="{{ $rel }}" @selected($isSelected)>{{ $rel }}</option>
                @endforeach
              </select>
            </div>
            @error('religion')
            <div class="form-helper-wrap">
              <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
            </div>
            @enderror
          </div>

          {{-- Status Saat Ini --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Status Anda saat ini <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <select name="current_status" required class="{{ $input }}">
                <option value="">-- Pilih Status --</option>
                <option value="Mahasiswa / Pelajar Aktif" @selected($old('current_status') === 'Mahasiswa / Pelajar Aktif')>Mahasiswa / Pelajar Aktif</option>
                <option value="Lulus Kuliah / Sekolah (Belum Bekerja)" @selected($old('current_status') === 'Lulus Kuliah / Sekolah (Belum Bekerja)')>Lulus Kuliah / Sekolah (Belum Bekerja)</option>
                <option value="Lulus Kuliah / Sekolah (Sedang Bekerja)" @selected($old('current_status') === 'Lulus Kuliah / Sekolah (Sedang Bekerja)')>Lulus Kuliah / Sekolah (Sedang Bekerja)</option>
              </select>
            </div>
          </div>

          {{-- NIM / NPM --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Nomor Induk Mahasiswa / NISN <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="text" name="student_id" required placeholder="21552011045"
                pattern="[0-9A-Za-z\-]+" inputmode="text"
                class="{{ $input }}" value="{{ $old('student_id') }}">
            </div>
            <div class="form-helper-wrap">
              <p class="mt-1 text-xs text-gray-400">Contoh: 21552011045</p>
            </div>
          </div>

          {{-- Email --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Email <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="email" name="email" required placeholder="kamu@email.com"
                class="{{ $input }}" value="{{ $old('email') }}">
            </div>
          </div>

          {{-- No HP --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">No. HP Aktif / WhatsApp <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="tel" name="phone_number" required placeholder="08xxxxxxxxxx"
                pattern="[0-9]{10,15}" inputmode="numeric" title="Hanya boleh angka, 10-15 digit"
                class="{{ $input }}" value="{{ $old('phone_number') }}">
            </div>
            <div class="form-helper-wrap">
              <p class="mt-1 text-xs text-gray-400">Hanya angka, contoh: 08123456789</p>
            </div>
          </div>

          {{-- Asal Kampus --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Asal Sekolah/Kampus <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="text" id="institution_name" name="institution_name" required placeholder="Telkom University"
                class="{{ $input }} tomselect-input" value="{{ $old('institution_name') }}"
                data-options="{{ json_encode(collect($institutions ?? [])->pluck('name')) }}">
            </div>
          </div>

          {{-- Program Studi --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Program Studi <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="text" id="study_program" name="study_program" required placeholder="Rekayasa Perangkat Lunak"
                class="{{ $input }} tomselect-input" value="{{ $old('study_program') }}"
                data-options="{{ json_encode(collect($studyPrograms ?? [])->pluck('name')) }}">
            </div>
          </div>
        </div>
      </div>

      {{-- ==================== 2. PILIHAN & DURASI ==================== --}}
      <div id="section-2-pilihan-durasi" class="border-b border-gray-100 pb-6 pt-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">2</span>
          Pilihan & Durasi <span class="text-xs font-normal text-red-500 normal-case">(Wajib)</span>
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
          {{-- Divisi Diminati --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Program Magang/PKL apa yang Anda minati? <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <select name="internship_interest" required class="{{ $input }}">
                <option value="">-- Pilih Divisi --</option>
                @foreach($divisions ?? [] as $div)
                  <option value="{{ $div }}" @selected($old('internship_interest') === $div)>
                    {{ $div }}
                  </option>
                @endforeach
              </select>
            </div>
          </div>

          {{-- Jenis Magang --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Jenis Magang apa yang Anda pilih? <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <select name="internship_type" required class="{{ $input }}" id="select-internship-type-static">
                <option value="">-- Pilih --</option>
                <option value="Magang Mandiri" @selected($old('internship_type') === 'Magang Mandiri')>Magang Mandiri</option>
                <option value="Reguler-Kampus" @selected($old('internship_type') === 'Reguler-Kampus')>Reguler-Kampus</option>
              </select>
            </div>
          </div>

          {{-- Kesiapan WFO --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Apakah Anda siap dengan Sistem Magang WFO di Yogyakarta? <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <select name="wfo_readiness" required class="{{ $input }}">
                <option value="">-- Pilih Kesiapan WFO --</option>
                <option value="Siap WFO di Yogyakarta" @selected($old('wfo_readiness') === 'Siap WFO di Yogyakarta')>Siap WFO di Yogyakarta</option>
                <option value="Tidak Siap" @selected($old('wfo_readiness') === 'Tidak Siap')>Tidak Siap</option>
              </select>
            </div>
          </div>

          {{-- Kesanggupan Shifting --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Hari magang adalah Senin - Sabtu. <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <select name="schedule_shift_readiness" required class="{{ $input }}">
                <option value="">-- Pilih Kesiapan Shifting --</option>
                <option value="Kantor 1 : Shift pagi (06.30 - 13.00 WIB) / Shift siang (13.00 - 21.00 WIB)." @selected($old('schedule_shift_readiness') === 'Kantor 1 : Shift pagi (06.30 - 13.00 WIB) / Shift siang (13.00 - 21.00 WIB).')>Kantor 1 : Shift pagi / Shift siang</option>
                <option value="Kantor 2 :  Shift pagi (06.30 - 13.00 WIB) / Shift Middle (09.00 - 17.00 WIB) / Shift siang (13.00 - 21.00 WIB)." @selected($old('schedule_shift_readiness') === 'Kantor 2 :  Shift pagi (06.30 - 13.00 WIB) / Shift Middle (09.00 - 17.00 WIB) / Shift siang (13.00 - 21.00 WIB).')>Kantor 2 :  Shift pagi / Middle / siang</option>
                <option value="Kantor 4 :  Shift pagi (06.30 - 13.00 WIB) / Shift Middle (09.00 - 17.00 WIB) /  Shift siang (13.00 - 21.00 WIB)." @selected($old('schedule_shift_readiness') === 'Kantor 4 :  Shift pagi (06.30 - 13.00 WIB) / Shift Middle (09.00 - 17.00 WIB) /  Shift siang (13.00 - 21.00 WIB).')>Kantor 4 :  Shift pagi / Middle / siang</option>
              </select>
            </div>
          </div>

          {{-- Kepemilikan Laptop --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Apakah Anda ada alat kerja sendiri (LAPTOP)? <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
              <div class="flex flex-wrap items-center gap-4 sm:gap-6 pt-1 min-h-[42px]">
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                  <input type="radio" name="has_laptop" value="1" class="{{ $radio }}"
                    @checked($old('has_laptop') === '1' || $old('has_laptop') === true || $old('has_laptop') === 1)> Ya (Punya Sendiri)
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                  <input type="radio" name="has_laptop" value="0" class="{{ $radio }}"
                    @checked($old('has_laptop') === '0' || $old('has_laptop') === false || ($old('has_laptop') === 0 && $old('has_laptop') !== ''))> Tidak
                </label>
              </div>
            </div>
          </div>

          {{-- Durasi Magang --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Berapa lama Anda akan Magang/PKL? <span class="text-red-500">*</span></label>
            </div>
            <div class="form-control-wrap">
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
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Kapan berencana mulai Magang/PKL? <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="date" name="start_date"
                min="{{ date('Y-m-d') }}"
                class="{{ $input }}" value="{{ $toDateInput($old('start_date')) }}">
            </div>
          </div>

          {{-- Alasan Magang --}}
          <div class="col-span-1 md:col-span-2 min-w-0">
            <label class="{{ $label }}">Mengapa Anda ingin Magang/PKL disini? <span class="text-red-500">*</span></label>
            <textarea name="internship_reason" required rows="3" placeholder="Tuliskan alasan Anda..."
              class="{{ $input }} resize-none">{{ $old('internship_reason') }}</textarea>
          </div>
        </div>
      </div>

      {{-- ==================== 3. BERKAS & PORTOFOLIO ==================== --}}
      <div id="section-3-berkas-portofolio" class="border-b border-gray-100 pb-6 pt-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-1 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">3</span>
          Berkas & Portofolio <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>
        <p class="text-xs text-gray-400 mb-4">Unggah dokumen sesuai kategorinya. Anda dapat mengunggah sebagian dokumen sekarang dan melengkapi dokumen susulan kemudian.</p>

        {{-- Daftar Berkas yang Telah Tersimpan di Server --}}
        @php
          $allAttachments = $reg ? $reg->attachments()->latest('id')->get() : collect();
        @endphp
        @if($allAttachments->isNotEmpty() || $reg?->profile_photo)
          <div class="mb-5 p-3.5 sm:p-4 bg-gray-50 border border-gray-200 rounded-xl overflow-hidden">
            <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-3 flex items-center gap-2">
              <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Berkas Tersimpan di Server ({{ $allAttachments->count() }})
            </h4>
            <div class="space-y-2">
              @foreach($allAttachments as $att)
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 p-3 bg-white border border-gray-200 rounded-lg text-xs overflow-hidden">
                  <div class="flex flex-wrap items-center gap-2 min-w-0 max-w-full">
                    <span class="px-2 py-0.5 rounded font-semibold text-[10px] bg-emerald-100 text-emerald-800 uppercase shrink-0">
                      {{ \App\Models\RegistrationAttachment::getCategoryLabel($att->attachment_type) }}
                    </span>
                    <a href="{{ route('pemagang.registration.attachment.download', $att->id) }}" target="_blank" class="text-blue-600 font-medium hover:underline truncate max-w-[200px] sm:max-w-xs md:max-w-md">
                      {{ $att->original_name }}
                    </a>
                    <span class="text-gray-400 shrink-0">({{ round($att->file_size / 1024, 1) }} KB)</span>
                    @if($att->drive_sync_status === 'synced')
                      <span class="text-[10px] text-emerald-600 font-medium flex items-center gap-1 shrink-0"><i class="fas fa-cloud-check"></i> Google Drive</span>
                    @else
                      <span class="text-[10px] text-gray-400 font-medium flex items-center gap-1 shrink-0"><i class="fas fa-hard-drive"></i> Lokal Privat</span>
                    @endif
                  </div>

                  @if(!$reg || $reg->is_draft || in_array($reg->internship_status, ['new', 'waiting']))
                    <form action="{{ route('pemagang.registration.attachment.delete', $att->id) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus berkas ini?')" class="shrink-0 self-end sm:self-auto">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="text-red-500 hover:text-red-700 font-medium flex items-center gap-1 px-2.5 py-1 rounded hover:bg-red-50 transition text-xs">
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
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
          {{-- 1. CV --}}
          <div class="col-span-1 min-w-0 p-3.5 sm:p-4 border border-gray-200 rounded-lg bg-gray-50/50 hover:bg-gray-50 transition form-half-col justify-between">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Curriculum Vitae (CV) <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span> <span class="text-xs text-gray-400 font-normal">(PDF/DOC/DOCX, Maks 10MB)</span></label>
            </div>
            <div class="form-control-wrap mt-auto">
              <input type="file" name="cv" accept=".pdf,.doc,.docx" class="{{ $input }} max-w-full file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white file:bg-emerald-700 hover:file:bg-emerald-800 cursor-pointer">
            </div>
            <div class="form-helper-wrap">
              <p class="text-[11px] text-gray-400 mt-1.5">Lampirkan CV terbaru Anda.</p>
            </div>
          </div>

          {{-- 2. KTM / KTP --}}
          <div class="col-span-1 min-w-0 p-3.5 sm:p-4 border border-gray-200 rounded-lg bg-gray-50/50 hover:bg-gray-50 transition form-half-col justify-between">
            <div class="form-label-wrap">
              <label class="{{ $label }}">KTM atau KTP <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span> <span class="text-xs text-gray-400 font-normal">(PDF/JPG/PNG, Maks 5MB)</span></label>
            </div>
            <div class="form-control-wrap mt-auto">
              <input type="file" name="identity" accept=".pdf,.jpg,.jpeg,.png" class="{{ $input }} max-w-full file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white file:bg-emerald-700 hover:file:bg-emerald-800 cursor-pointer">
            </div>
            <div class="form-helper-wrap">
              <p class="text-[11px] text-gray-400 mt-1.5">Kartu identitas mahasiswa atau KTP pendaftar.</p>
            </div>
          </div>

          {{-- 3. Portofolio (Multi-Upload) --}}
          <div class="col-span-1 md:col-span-2 min-w-0 p-3.5 sm:p-4 border border-gray-200 rounded-lg bg-gray-50/50 hover:bg-gray-50 transition">
            <label class="{{ $label }}">Portofolio Karya <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span> <span class="text-xs text-emerald-700 font-semibold">(Mendukung Banyak File, Maks 10MB/berkas)</span></label>
            <input type="file" name="portfolio[]" multiple accept=".pdf,.zip,.rar,.mp4,.doc,.docx,.png,.jpg,.jpeg" class="{{ $input }} max-w-full file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white file:bg-emerald-700 hover:file:bg-emerald-800 cursor-pointer">
            <p class="text-[11px] text-gray-400 mt-1.5">Pilih satu atau beberapa file portofolio, sertifikat penghargaan, atau sampel karya (PDF, ZIP, RAR, video MP4, dokumen).</p>
          </div>

          {{-- 4. Surat Pengantar --}}
          <div class="col-span-1 min-w-0 p-3.5 sm:p-4 border border-gray-200 rounded-lg bg-gray-50/50 hover:bg-gray-50 transition form-half-col justify-between">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Surat Pengantar Kampus / Sekolah <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span> <span class="text-xs text-gray-400 font-normal">(PDF/DOCX, Maks 10MB)</span></label>
            </div>
            <div class="form-control-wrap mt-auto">
              <input type="file" name="cover_letter" accept=".pdf,.doc,.docx" class="{{ $input }} max-w-full file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white file:bg-emerald-700 hover:file:bg-emerald-800 cursor-pointer">
            </div>
            <div class="form-helper-wrap">
              <p class="text-[11px] text-gray-400 mt-1.5">Surat permohonan magang dari institusi pendidikan.</p>
            </div>
          </div>

          {{-- 5. Dokumen Pendukung Tambahan (Multi-Upload) --}}
          <div class="col-span-1 min-w-0 p-3.5 sm:p-4 border border-gray-200 rounded-lg bg-gray-50/50 hover:bg-gray-50 transition form-half-col justify-between">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Dokumen Pendukung Lainnya <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span> <span class="text-xs text-gray-400 font-normal">(Multi-File, Maks 10MB/berkas)</span></label>
            </div>
            <div class="form-control-wrap mt-auto">
              <input type="file" name="supporting_document[]" multiple accept=".pdf,.doc,.docx,.zip,.rar,.png,.jpg,.jpeg" class="{{ $input }} max-w-full file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white file:bg-emerald-700 hover:file:bg-emerald-800 cursor-pointer">
            </div>
            <div class="form-helper-wrap">
              <p class="text-[11px] text-gray-400 mt-1.5">Transkrip nilai, sertifikat keahlian, atau surat izin orang tua.</p>
            </div>
          </div>
        </div>
      </div>

      {{-- ==================== 4. INFORMASI TAMBAHAN ==================== --}}
      <div id="section-4-informasi-tambahan" class="border-b border-gray-100 pb-6 pt-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">4</span>
          Informasi Tambahan <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
          {{-- Skill Fields --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Software Desain <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="text" name="design_software" placeholder="Figma, Photoshop"
                class="{{ $input }}" value="{{ $old('design_software') }}">
            </div>
          </div>
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Software Video <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="text" name="video_software" placeholder="Premiere Pro, After Effects"
                class="{{ $input }}" value="{{ $old('video_software') }}">
            </div>
          </div>
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Bahasa Pemrograman <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="text" name="programming_languages" placeholder="PHP, JS"
                class="{{ $input }}" value="{{ $old('programming_languages') }}">
            </div>
          </div>
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Digital Marketing <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            </div>
            <div class="form-control-wrap">
              <input type="text" name="digital_marketing_type" placeholder="SEO, Ads"
                class="{{ $input }}" value="{{ $old('digital_marketing_type') }}">
            </div>
          </div>

          {{-- Peralatan --}}
          <div class="col-span-1 md:col-span-2 min-w-0">
            <label class="{{ $label }}">Jika YA, alat apa yang Anda miliki, yang bisa Anda bawa selama Magang/PKL? <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <input type="text" name="owned_tools" placeholder="Asus ROG, Kamera, Tripod"
              class="{{ $input }}" value="{{ $old('owned_tools') ?: $old('laptop_equipment') }}">
          </div>

          {{-- Pembimbing --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Nama Pembimbing (Opsional)</label>
            </div>
            <div class="form-control-wrap">
              <input type="text" name="supervisor_name" placeholder="Nama pembimbing (jika ada)"
                class="{{ $input }}" value="{{ $old('supervisor_name', $reg?->supervisor_name !== '-' ? $reg?->supervisor_name : '') }}">
            </div>
          </div>
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">No. WA Pembimbing (Opsional)</label>
            </div>
            <div class="form-control-wrap">
              <input type="tel" name="supervisor_contact" placeholder="08xxxxxxxxxx"
                pattern="[0-9]*" inputmode="numeric" title="Hanya boleh angka"
                class="{{ $input }}" value="{{ $old('supervisor_contact', $reg?->supervisor_contact !== '-' ? $reg?->supervisor_contact : '') }}">
            </div>
            <div class="form-helper-wrap">
              <p class="mt-1 text-xs text-gray-400">Hanya angka</p>
            </div>
          </div>

          {{-- Kegiatan Lain Selain Magang --}}
          <div class="col-span-1 md:col-span-2 min-w-0">
            <label class="{{ $label }}">Kegiatan Lain Selain Magang <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <textarea name="current_activities" rows="2" placeholder="Contoh: Kuliah malam, freelance, dll (tulis '-' jika tidak ada)"
              class="{{ $input }} resize-none">{{ $old('current_activities', $reg?->current_activities !== '-' ? $reg?->current_activities : '') }}</textarea>
          </div>

          {{-- Info Kost --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Apakah anda membutuhkan info kost/kontrakan dekat kantor? <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            </div>
            <div class="form-control-wrap">
              <select name="boarding_info" class="{{ $input }}">
                <option value="Tidak" @selected(($old('boarding_info') ?: 'Tidak') === 'Tidak')>Tidak</option>
                <option value="Ya"    @selected($old('boarding_info') === 'Ya')>Ya</option>
              </select>
            </div>
          </div>

          {{-- Instagram --}}
          <div class="col-span-1 min-w-0 form-half-col">
            <div class="form-label-wrap">
              <label class="{{ $label }}">Sosial Media (Instagram) yang dimiliki <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            </div>
            <div class="form-control-wrap">
              <div class="flex items-center gap-0 w-full max-w-full">
                <span class="inline-flex items-center px-3 py-2.5 rounded-l-lg border border-r-0 border-gray-200 bg-gray-50 text-sm text-gray-500 shrink-0">@</span>
                <input type="text" name="social_media_instagram" placeholder="username_kamu"
                  class="block flex-1 min-w-0 w-full rounded-r-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 px-3 py-2.5 text-sm"
                  value="{{ $old('social_media_instagram', $reg?->social_media_instagram !== '-' ? $reg?->social_media_instagram : '') }}">
              </div>
            </div>
          </div>

          {{-- Info Sumber --}}
          <div class="col-span-1 md:col-span-2 min-w-0">
            <label class="{{ $label }}">Anda tahu info magang ini dari mana? <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <div class="{{ $group }}">
              @php
                $infoSources = $old('internship_info_sources', $reg?->internship_info_sources ?? '');
                $infoSourcesArr = is_array($infoSources)
                    ? $infoSources
                    : array_map('trim', explode(',', (string) $infoSources));
              @endphp
              <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">
                <div class="divide-y divide-gray-100">
                  @foreach([
                    'Instagram'           => 'Instagram',
                    'TikTok'              => 'TikTok',
                    'LinkedIn'            => 'LinkedIn',
                    'Referral Teman'      => 'Referral Teman',
                  ] as $val => $lbl)
                  <label class="{{ $item }}">
                    <input type="checkbox" name="internship_info_sources[]" value="{{ $val }}" class="{{ $check }}"
                      @checked(in_array($val, $infoSourcesArr))>
                    <span class="text-sm text-gray-700 break-words">{{ $lbl }}</span>
                  </label>
                  @endforeach
                </div>
                <div class="divide-y divide-gray-100">
                  @foreach([
                    'Website'             => 'Website',
                    'Campus'              => 'Kampus/Universitas',
                    'Lainnya'             => 'Lainnya',
                  ] as $val => $lbl)
                  <label class="{{ $item }}">
                    <input type="checkbox" name="internship_info_sources[]" value="{{ $val }}" class="{{ $check }}"
                      @checked(in_array($val, $infoSourcesArr))>
                    <span class="text-sm text-gray-700 break-words">{{ $lbl }}</span>
                  </label>
                  @endforeach
                </div>
              </div>
            </div>
          </div>

          {{-- Pertanyaan Tambahan dari Form Builder untuk form statis --}}
          @if(!empty($customFields) && $customFields->isNotEmpty())
          <div class="col-span-1 md:col-span-2 min-w-0 pt-4 border-t border-gray-100">
            <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-3">Pertanyaan Tambahan</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
              @foreach($customFields as $field)
              @php
                $isCfFull = ($field->column_span !== 2) || $field->field_type === 'textarea' || ($field->field_type === 'checkbox' && count($field->options ?? []) > 2);
              @endphp
              <div class="{{ $isCfFull ? 'col-span-1 md:col-span-2 min-w-0' : 'col-span-1 min-w-0 form-half-col' }}">
                @if(!$isCfFull)
                <div class="form-label-wrap">
                  <label class="{{ $label }}">
                    {{ $field->label }}
                    @if($field->is_required) <span class="text-red-500">*</span> @endif
                  </label>
                </div>
                <div class="form-control-wrap">
                @else
                <label class="{{ $label }}">
                  {{ $field->label }}
                  @if($field->is_required) <span class="text-red-500">*</span> @endif
                </label>
                @endif

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
                  <div class="flex flex-wrap items-center gap-4 pt-1 min-h-[42px]">
                    @foreach($field->options ?? [] as $opt)
                      <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                        <input type="radio" name="{{ $field->field_key }}" value="{{ $opt['value'] }}" class="{{ $radio }}" {{ $old($field->field_key) == $opt['value'] ? 'checked' : '' }} {{ $field->is_required ? 'required' : '' }}>
                        <span class="break-words">{{ $opt['label'] }}</span>
                      </label>
                    @endforeach
                  </div>
                @elseif($field->field_type === 'checkbox')
                  <div class="{{ $group }}">
                    @php $savedVals = (array) $old($field->field_key); @endphp
                    @foreach($field->options ?? [] as $opt)
                      <label class="{{ $item }}">
                        <input type="checkbox" name="{{ $field->field_key }}[]" value="{{ $opt['value'] }}" class="{{ $check }}" {{ in_array($opt['value'], $savedVals) ? 'checked' : '' }}>
                        <span class="text-sm text-gray-700 break-words">{{ $opt['label'] }}</span>
                      </label>
                    @endforeach
                  </div>
                @else
                  <input type="{{ $field->field_type }}" name="{{ $field->field_key }}" value="{{ $old($field->field_key) }}" placeholder="{{ $field->placeholder }}" class="{{ $input }}" {{ $field->is_required ? 'required' : '' }}>
                @endif

                @if(!$isCfFull)
                </div>
                @endif

                @if($field->helper_text)
                  <div class="form-helper-wrap">
                    <p class="mt-1 text-xs text-gray-400">{{ $field->helper_text }}</p>
                  </div>
                @endif
              </div>
              @endforeach
            </div>
          </div>
          @endif
        </div>
      </div>
      @endif {{-- end @else (form statis) --}}

      {{-- ==================== 5. FINALISASI & SUBMIT ==================== --}}
      <div id="section-5-finalisasi-submit" class="pt-5 border-t border-gray-100 space-y-4">
        <div class="flex items-center gap-2 pb-1">
          <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">5</span>
          <div>
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Finalisasi & Submit</h3>
            <p class="text-xs text-gray-500">Tinjau ketentuan program magang dan kirim formulir pendaftaran Anda.</p>
          </div>
        </div>

        {{-- Info unpaid --}}
        @if(!$registration || $registration->is_draft || $registration->internship_status === 'waiting')
        <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
          <strong>Perhatian:</strong> Program magang ini bersifat <strong>unpaid / tidak bergaji</strong>.
          Setelah submit, konfirmasi ke WA Admin <strong>0895 2900 2944</strong> dengan pesan
          <em>"SAYA SUDAH ISI FORM"</em>.
        </div>
        @endif

        {{-- Tombol --}}
        <div class="flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center gap-3 pt-4 border-t border-gray-100">
          @if(!$registration || $registration->is_draft)
            {{-- Belum submit → tombol kirim + server draft + local draft --}}
            <button type="submit"
              class="w-full sm:w-auto justify-center inline-flex items-center gap-2 px-6 py-2.5 text-sm font-semibold text-white rounded-lg shadow-sm hover:opacity-95 transition cursor-pointer"
              style="background-color:#1a5c38;">
              <i class="fas fa-paper-plane text-xs"></i>
              Kirim Pendaftaran
            </button>
            <button type="submit" formaction="{{ route('pemagang.registration.draft') }}" formnovalidate
              class="w-full sm:w-auto justify-center inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 rounded-lg transition cursor-pointer">
              <i class="fas fa-cloud-arrow-up text-xs"></i>
              Simpan Draft ke Server
            </button>
            <button type="button" id="btn-save-local"
              class="w-full sm:w-auto justify-center inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition cursor-pointer"
              title="Simpan data identitas dasar secara lokal di browser ini">
              <i class="fas fa-save text-xs"></i>
              Simpan di Browser
            </button>
            <button type="button" id="btn-clear-local"
              class="w-full sm:w-auto justify-center inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 rounded-lg transition cursor-pointer"
              title="Hapus data formulir yang tersimpan di browser ini">
              <i class="fas fa-trash-alt text-xs"></i>
              Hapus Draft Browser
            </button>
            <p class="text-[11px] text-gray-500 mt-2 w-full leading-relaxed">
              <i class="fas fa-shield-alt text-emerald-600"></i> <strong>Privasi Penyimpanan Lokal:</strong> Fitur "Simpan di Browser" bersifat opsional dan menyimpan data identitas dasar secara teks biasa (tidak terenkripsi) di perangkat ini khusus untuk akun Anda. Berkas lampiran dan jawaban formulir tidak pernah disimpan di penyimpanan browser. <strong>Peringatan Perangkat Bersama:</strong> Jika Anda menggunakan komputer publik atau bersama, harap gunakan tombol "Hapus Draft Browser" sebelum meninggalkan perangkat untuk menjaga privasi akun Anda.
            </p>
          @else
            {{-- Sudah submit → hanya bisa update data --}}
            <button type="submit"
              class="w-full sm:w-auto justify-center inline-flex items-center gap-2 px-6 py-2.5 text-sm font-semibold text-white rounded-lg shadow-sm hover:opacity-95 transition cursor-pointer"
              style="background-color:#1a5c38;">
              <i class="fas fa-save text-xs"></i>
              Simpan Perubahan Data
            </button>
            <a href="{{ route('pemagang.dashboard') }}"
              class="w-full sm:w-auto justify-center inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
              Kembali
            </a>
          @endif
        </div>
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
    'fullname', 'student_id', 'born_date', 'religion', 'gender',
    'phone_number', 'email', 'institution_name',
    'faculty', 'study_program'
  ];

  function extractSafeCoreData(form) {
    const data = {};
    if (!form) return data;
    SAFE_CORE_FIELD_NAMES.forEach(function(name) {
      const el = form.querySelector('[name="' + name + '"]');
      if (el && el.type !== 'file' && el.type !== 'password' && !name.toLowerCase().includes('password') && el.value) {
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
        if (el && el.type !== 'file' && el.type !== 'password' && !name.toLowerCase().includes('password') && !el.value) {
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

    // ===== Stepper Smooth Navigation & Scroll Spy =====
    (function() {
      const stepperTabs = document.querySelectorAll('#form-stepper .stepper-tab');
      if (!stepperTabs.length) return;

      const sectionIds = [
        'section-1-data-diri',
        'section-2-pilihan-durasi',
        'section-3-berkas-portofolio',
        'section-4-informasi-tambahan',
        'section-5-finalisasi-submit'
      ];

      function updateActiveStep(activeId) {
        stepperTabs.forEach(function(tab) {
          const href = tab.getAttribute('href');
          const numBadge = tab.querySelector('.step-num');
          const isActive = (href === '#' + activeId);

          if (isActive) {
            tab.classList.add('text-emerald-800', 'font-semibold', 'bg-emerald-50', 'border-emerald-300');
            tab.classList.remove('text-gray-600', 'font-medium', 'bg-white', 'border-gray-200');
            if (numBadge) {
              numBadge.classList.add('bg-emerald-700', 'text-white');
              numBadge.classList.remove('bg-gray-200', 'text-gray-700');
            }
            // Auto scroll stepper tab into view on mobile
            const stepperContainer = document.getElementById('form-stepper');
            if (stepperContainer && window.innerWidth < 640) {
              const tabLeft = tab.offsetLeft;
              const tabWidth = tab.offsetWidth;
              const containerWidth = stepperContainer.offsetWidth;
              stepperContainer.scrollTo({
                left: tabLeft - (containerWidth / 2) + (tabWidth / 2),
                behavior: 'smooth'
              });
            }
          } else {
            tab.classList.remove('text-emerald-800', 'font-semibold', 'bg-emerald-50', 'border-emerald-300');
            tab.classList.add('text-gray-600', 'font-medium', 'bg-white', 'border-gray-200');
            if (numBadge) {
              numBadge.classList.remove('bg-emerald-700', 'text-white');
              numBadge.classList.add('bg-gray-200', 'text-gray-700');
            }
          }
        });
      }

      stepperTabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
          const targetId = this.getAttribute('href')?.replace('#', '');
          const targetEl = document.getElementById(targetId);
          if (targetEl) {
            e.preventDefault();
            const yOffset = -90; // offset untuk sticky stepper
            const y = targetEl.getBoundingClientRect().top + window.pageYOffset + yOffset;
            window.scrollTo({ top: y, behavior: 'smooth' });
            updateActiveStep(targetId);
          }
        });
      });

      let ticking = false;
      window.addEventListener('scroll', function() {
        if (!ticking) {
          window.requestAnimationFrame(function() {
            const scrollPos = window.scrollY + 120;
            let currentId = sectionIds[0];
            for (let i = 0; i < sectionIds.length; i++) {
              const el = document.getElementById(sectionIds[i]);
              if (el && el.offsetTop <= scrollPos) {
                currentId = sectionIds[i];
              }
            }
            if (currentId) {
              updateActiveStep(currentId);
            }
            ticking = false;
          });
          ticking = true;
        }
      }, { passive: true });
    })();


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
            try {
                options = JSON.parse(rawOptions).map(function(name) {
                    return { value: name, text: name };
                });
            } catch (e) {
                options = [];
            }
        }
        
        var currentVal = el.value ? el.value.trim() : '';
        if (currentVal) {
            var exists = options.some(function(o) {
                return o.value.toLowerCase() === currentVal.toLowerCase();
            });
            if (!exists) {
                options.unshift({ value: currentVal, text: currentVal });
            }
        }

        var ts = new TomSelect(el, {
            options: options,
            items: currentVal ? [currentVal] : [],
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

        if (currentVal) {
            ts.setValue(currentVal, true);
        }
    });
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
@endpush

@endsection
