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
              <div class="flex gap-4 pt-2">
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
                <option value="">-- Pilih --</option>
                <option value="Mahasiswa/Pelajar" @selected($old('current_status') === 'Mahasiswa/Pelajar')>Masih Kuliah/Sekolah</option>
                <option value="Tidak Bekerja" @selected($old('current_status') === 'Tidak Bekerja')>Lulus & Belum Bekerja</option>
                <option value="Karyawan" @selected($old('current_status') === 'Karyawan')>Lulus & Sudah Bekerja</option>
              </select>
            </div>
            <div>
              <label class="{{ $label }}">NIM / NPM <span class="text-red-500">*</span></label>
              <input type="text" name="student_id" required placeholder="21552011045"
                pattern="[0-9A-Za-z\-]+" inputmode="text"
                class="{{ $input }}" value="{{ $old('student_id') }}">
              <p class="mt-1 text-xs text-gray-400">Contoh: 21552011045</p>
            </div>
          </div>

          {{-- Universitas & Fakultas & Prodi --}}
          <div>
            <label class="{{ $label }}">Universitas / Sekolah <span class="text-red-500">*</span></label>
            <input type="text" id="institution_name" name="institution_name" required placeholder="Telkom University"
              class="{{ $input }} tomselect-input" value="{{ $old('institution_name') }}"
              data-options="{{ json_encode(collect($institutions ?? [])->pluck('name')) }}">
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Fakultas <span class="text-red-500">*</span></label>
              <input type="text" id="faculty" name="faculty" required placeholder="Ilmu Komputer"
                class="{{ $input }} tomselect-input" value="{{ $old('faculty') }}"
                data-options="{{ json_encode(collect($faculties ?? [])->pluck('name')) }}">
            </div>
            <div>
              <label class="{{ $label }}">Program Studi <span class="text-red-500">*</span></label>
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
          {{-- Divisi Diminati --}}
          <div>
            <label class="{{ $label }}">Divisi Diminati <span class="text-red-500">*</span></label>
            <select name="internship_interest" required class="{{ $input }}">
              <option value="">-- Pilih Divisi --</option>
              @foreach($divisions ?? [] as $div)
                <option value="{{ $div }}" @selected($old('internship_interest') === $div)>
                  {{ $div }}
                </option>
              @endforeach
            </select>
          </div>

          {{-- Jenis Magang --}}
          <div>
            <label class="{{ $label }}">Jenis Magang <span class="text-red-500">*</span></label>
            <select name="internship_type" required class="{{ $input }}" id="select-internship-type-static">
              <option value="">-- Pilih --</option>
              <option value="Kampus Merdeka" @selected($old('internship_type') === 'Kampus Merdeka')>Kampus Merdeka (MBKM)</option>
              <option value="Magang Kampus"  @selected($old('internship_type') === 'Magang Kampus')>Magang Kampus</option>
              <option value="Magang Mandiri" @selected($old('internship_type') === 'Magang Mandiri')>Magang Mandiri</option>
              <option value="PKL"            @selected($old('internship_type') === 'PKL')>PKL (Praktik Kerja Lapangan)</option>
            </select>
            <input type="hidden" name="internship_arrangement" value="Onsite">
            {{-- Keterangan jenis magang --}}
            <div id="internship-type-desc-static" class="mt-2 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 space-y-1">
              <p class="text-xs text-gray-500 leading-snug desc-item" data-for-type="Kampus Merdeka">
                <span class="font-semibold text-gray-700">Kampus Merdeka (MBKM):</span>
                Program magang bersertifikat resmi dari Kemendikbudristek (MSIB).
              </p>
              <p class="text-xs text-gray-500 leading-snug desc-item" data-for-type="Magang Kampus">
                <span class="font-semibold text-gray-700">Magang Kampus:</span>
                Magang dari kampus untuk pemenuhan tugas akhir atau penilaian akademik.
              </p>
              <p class="text-xs text-gray-500 leading-snug desc-item" data-for-type="Magang Mandiri">
                <span class="font-semibold text-gray-700">Magang Mandiri:</span>
                Magang inisiatif pribadi secara mandiri tanpa terikat penilaian akademik kampus.
              </p>
              <p class="text-xs text-gray-500 leading-snug desc-item" data-for-type="PKL">
                <span class="font-semibold text-gray-700">PKL:</span>
                Praktik Kerja Lapangan, umumnya untuk siswa siswi tingkat SMK/sederajat.
              </p>
            </div>
          </div>

          {{-- Durasi Magang --}}
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Tanggal Mulai</label>
              <input type="date" name="start_date"
                min="{{ date('Y-m-d') }}"
                class="{{ $input }}" value="{{ $toDateInput($old('start_date')) }}">
            </div>
            <div>
              <label class="{{ $label }}">Tanggal Selesai</label>
              <input type="date" name="end_date"
                class="{{ $input }}" value="{{ $toDateInput($old('end_date')) }}">
            </div>
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
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="{{ $label }}">Surat Pengantar (PDF) <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
              <input type="file" name="cv_ktp_portofolio_pdf" accept=".pdf"
                class="{{ $input }} file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer"
                style="--file-bg: #1a5c38;">
              @if($reg?->cv_ktp_portofolio_pdf)
                <p class="text-xs text-gray-400 mt-1">
                  File sebelumnya: <a href="{{ asset('storage/' . $reg->cv_ktp_portofolio_pdf) }}" target="_blank" class="text-blue-600 hover:underline">{{ basename($reg->cv_ktp_portofolio_pdf) }}</a>
                </p>
              @endif
            </div>
            <div>
              <label class="{{ $label }}">CV / Portfolio (PDF) <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
              <input type="file" name="portofolio_visual" accept=".pdf,.jpg,.jpeg,.png"
                class="{{ $input }} file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer">
              @if($reg?->portofolio_visual)
                <p class="text-xs text-gray-400 mt-1">
                  File sebelumnya: <a href="{{ asset('storage/' . $reg->portofolio_visual) }}" target="_blank" class="text-blue-600 hover:underline">{{ basename($reg->portofolio_visual) }}</a>
                </p>
              @endif
            </div>
          </div>
          <div>
            <label class="{{ $label }}">Foto Profil (JPG/PNG) <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
            <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png"
              class="{{ $input }} file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer"
              style="--file-bg: #1a5c38;">
            @if($reg?->profile_photo)
              <p class="text-xs text-gray-400 mt-1">
                File sebelumnya: <a href="{{ asset('storage/' . $reg->profile_photo) }}" target="_blank" class="text-blue-600 hover:underline">{{ basename($reg->profile_photo) }}</a>
              </p>
            @endif
          </div>
          <p class="text-xs text-gray-400">Maks. 5MB per file, format PDF/JPG/PNG sesuai ketentuan field</p>
        </div>
      </div>

      {{-- ==================== 5. KEAHLIAN & FASILITAS (OPSIONAL) ==================== --}}
      <div class="border-b border-gray-100 pb-5">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs flex items-center justify-center font-bold">5</span>
          Keahlian & Perlengkapan <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>

        <div class="space-y-4">
          {{-- Kemampuan Bahasa Inggris --}}
          <div>
            <label class="{{ $label }}">Kemampuan Membaca Buku Bahasa Inggris <span class="text-xs text-gray-400 font-normal">(Opsional)</span></label>
            <select name="english_book_ability" class="{{ $input }}">
              <option value="">-- Pilih (Opsional) --</option>
              <option value="Saya bisa" @selected($old('english_book_ability') === 'Saya bisa')>Saya bisa</option>
              <option value="Kurang bisa" @selected($old('english_book_ability') === 'Kurang bisa')>Kurang bisa</option>
              <option value="Tidak bisa" @selected($old('english_book_ability') === 'Tidak bisa')>Tidak bisa</option>
            </select>
          </div>

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
      <div class="flex items-center gap-3 pt-2">
        @if(!$registration || $registration->is_draft)
          {{-- Belum submit → tombol kirim + draft --}}
          <button type="submit"
            class="flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-lg"
            style="background-color:#1a5c38;">
            <i class="fas fa-paper-plane text-xs"></i>
            Kirim Pendaftaran
          </button>
          <button type="button" id="btn-save-local"
            class="flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
            <i class="fas fa-save text-xs"></i>
            Simpan Sementara
          </button>
        @else
          {{-- Sudah submit → hanya bisa update data --}}
          <button type="submit"
            class="flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white rounded-lg"
            style="background-color:#1a5c38;">
            <i class="fas fa-save text-xs"></i>
            Simpan Perubahan Data
          </button>
          <a href="{{ route('pemagang.dashboard') }}"
            class="flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
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
            errMsg.textContent = '⚠ Field ini wajib diisi';
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
            errMsg.textContent = '⚠ Pilih salah satu opsi';
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
      <button onclick="this.parentNode.remove()" class="ml-3 text-red-400 hover:text-red-600">✕</button>`;
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

  document.addEventListener('DOMContentLoaded', function() {
    setupRequiredHighlight();

    // Restore dari localStorage kalau fullname masih kosong (form baru)
    const firstInput = document.querySelector('#form-daftar input[name="fullname"]');
    if (firstInput && !firstInput.value) restoreFormLocal();

    setupNumericValidation(
      'input[name="phone_number"]',
      '⚠ No. HP hanya boleh berisi angka (contoh: 08123456789)'
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
