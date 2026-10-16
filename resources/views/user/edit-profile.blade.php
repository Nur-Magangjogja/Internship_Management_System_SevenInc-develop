@extends('layouts.dashboard')

@php
    use Illuminate\Support\Str;
    use Illuminate\Support\Facades\Storage;
    use Carbon\Carbon;

    /** @var \App\Models\InternshipRegistration|null $intern */
    $intern = $intern ?? (auth()->user()->internshipRegistration ?? null);

    // utility classes
    $label = 'block mb-2 text-[13px] sm:text-sm font-semibold 
              text-gray-800 dark:text-gray-200 select-none';
    $input = 'w-full h-11 sm:h-11 lg:h-12 px-3 rounded-xl mb-3
              border border-gray-300/80 dark:border-gray-700/70
              bg-white/95 dark:bg-gray-800/80
              text-gray-900 dark:text-gray-100
              placeholder-gray-400 dark:placeholder-gray-500
              shadow-sm transition-colors
              hover:border-gray-400 dark:hover:border-gray-600
              focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500
              disabled:opacity-60 disabled:cursor-not-allowed
              read-only:bg-gray-50 dark:read-only:bg-gray-800/60';
    $card  = 'rounded-2xl bg-white/95 dark:bg-gray-900/80 
              backdrop-blur-sm
              ring-1 ring-gray-200/80 dark:ring-white/10
              shadow
              transform-gpu transition-all duration-300
              hover:shadow-xl hover:-translate-y-0.5
              p-6 md:p-7 lg:p-8
              mb-8 md:mb-10 last:mb-0';

    // === STATUS MAP (default "waiting")
    $status = $intern->internship_status ?? 'waiting';
    $statusMap = [
        'waiting'   => ['label' => 'Menunggu Review', 'cls' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
        'pending'   => ['label' => 'Pending',         'cls' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
        'accepted'  => ['label' => 'Diterima',        'cls' => 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-200'],
        'rejected'  => ['label' => 'Ditolak',         'cls' => 'bg-gray-200 text-gray-700 dark:bg-gray-800/60 dark:text-gray-200'],
        'active'    => ['label' => 'Aktif',           'cls' => 'bg-blue-100 text-blue-800 dark:bg-blue-600/20 dark:text-blue-300'],
        'completed' => ['label' => 'Selesai',         'cls' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200'],
        'exited'    => ['label' => 'Keluar',          'cls' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'],
    ];

    // === TIMELINE (4 step)
    $steps  = ['waiting','accepted','active','completed'];
    $labels = [
      'waiting'   => 'Menunggu Review',
      'accepted'  => 'Diterima',
      'active'    => 'Aktif',
      'completed' => 'Selesai',
    ];
    $currentIdx = array_search($status, $steps);
    $currentIdx = $currentIdx === false ? 0 : $currentIdx;

    // helper tanggal aman utk input date
    $toInputDate = function($v) {
        if (!$v) return null;
        try { return Carbon::parse($v)->format('Y-m-d'); }
        catch (\Throwable $e) { return Str::of((string)$v)->substr(0, 10); }
    };

    // ====== Prefill & normalisasi untuk field tambahan ======
    $normalizeYesNo = function ($v) {
        $v = Str::lower(trim((string)$v));
        if (in_array($v, ['ya','y','yes','1','true','yes-laptop','ya ada']))   return 'ya';
        if (in_array($v, ['tidak','no','n','0','false','tidak ada']))           return 'tidak';
        return $v;
    };

    $laptop  = $normalizeYesNo(old('laptop_equipment', $intern->laptop_equipment ?? ''));
    $boarding= $normalizeYesNo(old('boarding_info',    $intern->boarding_info ?? ''));


    // owned_tools di DB kemungkinan CSV → jadikan array & lowercase utk cek
    $ownedToolsStored = collect(preg_split('/\s*,\s*/', (string)($intern->owned_tools ?? ''), -1, PREG_SPLIT_NO_EMPTY))
                        ->map(fn($x)=>trim($x))->values()->all();
    $ownedToolsOld = old('owned_tools', $ownedToolsStored);
    $ownedToolsSelectedLC = collect(is_array($ownedToolsOld) ? $ownedToolsOld : [$ownedToolsOld])
                            ->map(fn($x)=>Str::lower(trim((string)$x)))->filter()->values()->all();

    $ownedToolsOptions = [
        'Corel / Photoshop',
        'Adobe Premiere / After Effects',
        'Kamera',
        'Drone',
        'Pen Tablet',
        'Tripod',
    ];

    // sumber info
    $infoStored = collect(preg_split('/\s*,\s*/', (string)($intern->internship_info_sources ?? ''), -1, PREG_SPLIT_NO_EMPTY))
                  ->map(fn($x)=>trim($x))->values()->all();
    $infoOld = old('internship_info_sources', $infoStored);
    $infoSelectedLC = collect(is_array($infoOld) ? $infoOld : [$infoOld])
                      ->map(fn($x)=>Str::lower(trim((string)$x)))->filter()->values()->all();

    $infoSourcesOptions = [
        'website'   => 'Website',
        'instagram' => 'Instagram',
        'twitter'   => 'Twitter',
        'glints'    => 'Glints',
        'youtube'   => 'YouTube',
    ];

    $dmType = old('digital_marketing_type', $intern->digital_marketing_type ?? '');
@endphp

<style>
@media (prefers-reduced-motion: no-preference) {
  @keyframes in-up { from {opacity:0; transform: translateY(8px)} to {opacity:1; transform:none} }
  .anim-in-up { animation: in-up .5s cubic-bezier(.22,1,.36,1) both; }
  @keyframes in-scale { from {opacity:0; transform: scale(.98)} to {opacity:1; transform: none} }
  .animate-pop-soft { animation: in-scale .44s cubic-bezier(.22,1,.36,1) both; }
  @keyframes pulse-soft { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.30);} 70% { box-shadow:0 0 0 10px rgba(16,185,129,0);} 100%{box-shadow:0 0 0 0 rgba(16,185,129,0);} }
  .animate-pulse-soft { animation: pulse-soft 1.4s ease-out 2; }
  .anim-stagger > * { opacity:0; transform: translateY(8px); animation: in-up .5s ease-out forwards; }
  .anim-stagger > *:nth-child(1){animation-delay:.04s}.anim-stagger > *:nth-child(2){animation-delay:.08s}
  .anim-stagger > *:nth-child(3){animation-delay:.12s}.anim-stagger > *:nth-child(4){animation-delay:.16s}
  .anim-stagger > *:nth-child(5){animation-delay:.20s}.anim-stagger > *:nth-child(6){animation-delay:.24s}
  .anim-stagger > *:nth-child(7){animation-delay:.28s}.anim-stagger > *:nth-child(8){animation-delay:.32s}
}
</style>

@section('content')
<div class="bg-primary-300 px-3 sm:px-4 lg:px-6 pt-4 pb-6">
  <div class="max-w-none w-full">

    {{-- HEADER --}}
    <div class="w-full rounded-xl bg-white dark:bg-gray-800 shadow p-6 mb-6">
      
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <h2 class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-gray-100">
          Edit Profil
        </h2>
        @if ($intern)
          <span class="inline-flex animate-pop-soft items-center rounded-full px-2.5 py-0.5 text-xl font-bold
            {{ $statusMap[$status]['cls'] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' }} f">
            {{ $statusMap[$status]['label'] ?? ucfirst($status) }}
          </span>
        @endif
      </div>

      @if ($intern)
        <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
          Didaftarkan: <span class="font-medium">{{ optional($intern->created_at)->diffForHumans() ?? '-' }}</span>
          <span class="mx-2 text-gray-400">•</span>
          Terakhir diperbarui: <span class="font-medium">{{ optional($intern->updated_at)->diffForHumans() ?? '-' }}</span>
        </div>

        {{-- TIMELINE --}}
        <div class="mt-5">
          <div class="relative">
            <div class="absolute left-0 right-0 top-3 h-1 rounded-full bg-gray-200 dark:bg-gray-700"></div>
            <div class="grid grid-cols-4 gap-2 relative">
              @foreach($steps as $i => $st)
                <div class="flex flex-col items-center text-center">
                  <div class="z-10 h-6 w-6 rounded-full grid place-items-center
                        {{ $i <= $currentIdx ? 'bg-primary-500 text-white'.($i === $currentIdx ? ' animate-pulse-soft' : '') : 'bg-gray-300 dark:bg-gray-600 text-transparent' }}">
                    <span class="text-[10px] font-bold">•</span>
                  </div>
                  <div class="mt-2 text-[12px] {{ $i <= $currentIdx ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $labels[$st] }}
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        </div>

        {{-- INFO STATUS --}}
        @if(in_array($status, ['accepted','active','completed']))
          <div class="mt-4 rounded-lg border border-primary-200/70 bg-primary-50 px-4 py-3 text-[13px] text-primary-900
                      dark:border-primary-800/60 dark:bg-primary-900/20 dark:text-primary-200">
            Selamat status kamu sekarang adalah <b><em>Diterima</em></b>, kamu otomatis menjadi <b><span class="font-bold">Pemagang</span></b>.
          </div>
        @elseif($status === 'rejected')
          <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-700
                      dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
            Pengajuanmu ditolak. Kamu bisa perbarui profil lalu ajukan kembali nanti.
          </div>
        @else
          <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-900
                      dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200">
            Berkas sudah kami terima. Tim sedang review. Notifikasi akan dikirim via email/WA.
          </div>
        @endif
      @endif
    </div>

    {{-- ALERTS --}}
    @if (session('success'))
      <div class="mb-4 px-4 py-3 rounded-xl bg-green-50 text-green-700 border border-green-200 dark:bg-green-900/20 dark:text-green-200 dark:border-green-800">
        {{ session('success') }}
      </div>
    @endif
    @if (session('info'))
      <div class="mb-4 px-4 py-3 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 dark:bg-blue-900/20 dark:text-blue-200 dark:border-blue-800">
        {{ session('info') }}
      </div>
    @endif
    @if ($errors->any())
      <div class="mb-6 px-4 py-3 rounded-xl bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/20 dark:text-red-200 dark:border-red-800">
        <div class="font-semibold mb-1">Ada yang perlu dicek ulang:</div>
        <ul class="list-disc list-inside text-sm">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    {{-- TANPA DATA --}}
    @if (empty($intern))
      <div class="px-4 py-5 rounded-xl bg-yellow-50 text-yellow-800 border border-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-100 dark:border-yellow-800">
        Kamu belum memiliki data pendaftaran. <a href="{{ route('internship.form') }}" class="underline font-medium">Isi form dulu</a> ya ✍️
      </div>
    @else

    {{-- FORM --}}
    <form action="{{ route('user.updateProfile') }}" method="POST" enctype="multipart/form-data" class="space-y-10 anim-stagger">
      @csrf

      {{-- Data Pribadi --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">🌱</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Data Pribadi</h3>
        </div>

        <div class="grid grid-cols-12 gap-x-6 gap-y-6">
          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Nama Lengkap</label>
            <input type="text" name="fullname" value="{{ old('fullname', $intern->fullname) }}"
                  class="{{ $input }} @error('fullname') border-red-500 focus:ring-red-500 @enderror" required autofocus>
            @error('fullname') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Tempat Lahir</label>
            <input type="text" name="birth_place" value="{{ old('birth_place', $intern->birth_place) }}"
                  class="{{ $input }} @error('birth_place') border-red-500 focus:ring-red-500 @enderror" placeholder="Contoh: Sleman">
            @error('birth_place') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Tanggal Lahir</label>
            <input type="date" name="born_date" value="{{ old('born_date', $toInputDate($intern->born_date)) }}"
                  class="{{ $input }} @error('born_date') border-red-500 focus:ring-red-500 @enderror" required>
            @error('born_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          @php $g = old('gender', $intern->gender); @endphp
          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Jenis Kelamin</label>
            <select name="gender" class="{{ $input }} pr-10 @error('gender') border-red-500 focus:ring-red-500 @enderror" required>
              <option value="" disabled {{ $g ? '' : 'selected' }}>Pilih gender</option>
              <option value="male"   {{ $g === 'male' ? 'selected' : '' }}>Laki-laki</option>
              <option value="female" {{ $g === 'female' ? 'selected' : '' }}>Perempuan</option>
            </select>
            @error('gender') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          @php $rel = old('religion', $intern->religion); @endphp
          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Agama</label>
            <select name="religion" class="{{ $input }} pr-10 @error('religion') border-red-500 focus:ring-red-500 @enderror">
              <option value="" {{ $rel ? '' : 'selected' }}>Pilih Agama</option>
              @foreach(['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'] as $agama)
                <option value="{{ $agama }}" {{ $rel === $agama ? 'selected' : '' }}>{{ $agama }}</option>
              @endforeach
            </select>
            @error('religion') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Email</label>
            <input type="email" name="email" value="{{ old('email', $intern->email) }}"
                  class="{{ $input }} @error('email') border-red-500 focus:ring-red-500 @enderror" required>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Pastikan email aktif untuk komunikasi.</p>
            @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">No. HP</label>
            <input type="text" name="phone_number" value="{{ old('phone_number', $intern->phone_number) }}"
                  class="{{ $input }} @error('phone_number') border-red-500 focus:ring-red-500 @enderror" required>
            @error('phone_number') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          @if(\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'birth_place'))
          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Tempat Lahir</label>
            <input type="text" name="birth_place" value="{{ old('birth_place', $intern->birth_place) }}"
                  placeholder="Contoh: Sleman" maxlength="100"
                  class="{{ $input }} @error('birth_place') border-red-500 focus:ring-red-500 @enderror">
            @error('birth_place') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
          @endif

          @if(\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'religion'))
          @php $relVal = old('religion', $intern->religion); @endphp
          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Agama</label>
            <select name="religion" class="{{ $input }} pr-10 @error('religion') border-red-500 focus:ring-red-500 @enderror">
              <option value="">-- Pilih Agama (Opsional) --</option>
              @foreach(['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'] as $rel)
                <option value="{{ $rel }}" @selected($relVal === $rel)>{{ $rel }}</option>
              @endforeach
            </select>
            @error('religion') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
          @endif
        </div>
      </section>

      {{-- Akademik --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">🎓</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Akademik</h3>
        </div>

        <div class="grid grid-cols-12 gap-x-6 gap-y-6">
          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">NIM / Student ID</label>
            <input type="text" name="student_id" value="{{ old('student_id', $intern->student_id) }}"
                   class="{{ $input }} @error('student_id') border-red-500 focus:ring-red-500 @enderror" required>
            @error('student_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Institusi</label>
            <input type="text" name="institution_name" value="{{ old('institution_name', $intern->institution_name) }}"
                   class="{{ $input }} @error('institution_name') border-red-500 focus:ring-red-500 @enderror" required>
            @error('institution_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Program Studi</label>
            <input type="text" name="study_program" value="{{ old('study_program', $intern->study_program) }}"
                   class="{{ $input }} @error('study_program') border-red-500 focus:ring-red-500 @enderror" required>
            @error('study_program') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Fakultas</label>
            <input type="text" name="faculty" value="{{ old('faculty', $intern->faculty) }}"
                   class="{{ $input }} @error('faculty') border-red-500 focus:ring-red-500 @enderror" required>
            @error('faculty') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Kota Saat Ini</label>
            <input type="text" name="current_city" value="{{ old('current_city', $intern->current_city) }}"
                   class="{{ $input }} @error('current_city') border-red-500 focus:ring-red-500 @enderror" required>
            @error('current_city') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
        </div>

        {{-- Periode Magang --}}
        <div class="flex items-center gap-2 mb-6 mt-8">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">🗓️</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Periode Magang</h3>
        </div>

        <div class="grid grid-cols-12 gap-x-6 gap-y-6">
          <div class="col-span-12 md:col-span-6">
            <label class="{{ $label }}">Tanggal Mulai</label>
            <input type="date" name="start_date" value="{{ old('start_date', $toInputDate($intern->start_date)) }}" class="{{ $input }}">
            @error('start_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
          <div class="col-span-12 md:col-span-6">
            <label class="{{ $label }}">Tanggal Selesai</label>
            <input type="date" name="end_date" value="{{ old('end_date', $toInputDate($intern->end_date)) }}" class="{{ $input }}">
            @error('end_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
          Kosongkan jika belum pasti. Format otomatis <code>YYYY-MM-DD</code>.
        </p>
      </section>

      {{-- Preferensi & Alasan --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">🎯</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Preferensi & Alasan</h3>
        </div>

        <div class="grid grid-cols-12 gap-x-6 gap-y-6">
          <div class="col-span-12">
            <label class="{{ $label }}">Alasan Magang</label>
            <textarea name="internship_reason" rows="4" class="{{ $input }} @error('internship_reason') border-red-500 focus:ring-red-500 @enderror">{{ old('internship_reason', $intern->internship_reason) }}</textarea>
            @error('internship_reason') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Tipe Magang</label>
            <input type="text" name="internship_type" value="{{ old('internship_type', $intern->internship_type) }}" class="{{ $input }} @error('internship_type') border-red-500 focus:ring-red-500 @enderror" required>
            @error('internship_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <input type="hidden" name="internship_arrangement" value="Onsite">


          <div class="col-span-12 md:col-span-6 lg:col-span-4">
            <label class="{{ $label }}">Status Saat Ini</label>
            <input type="text" name="current_status" value="{{ old('current_status', $intern->current_status) }}" class="{{ $input }} @error('current_status') border-red-500 focus:ring-red-500 @enderror" required>
            @error('current_status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Kemampuan Buku Inggris</label>
            <input type="text" name="english_book_ability" value="{{ old('english_book_ability', $intern->english_book_ability) }}" class="{{ $input }} @error('english_book_ability') border-red-500 focus:ring-red-500 @enderror" required>
            @error('english_book_ability') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Kontak Dosen Pembimbing</label>
            <input type="text" name="supervisor_contact" value="{{ old('supervisor_contact', $intern->supervisor_contact) }}" class="{{ $input }} @error('supervisor_contact') border-red-500 focus:ring-red-500 @enderror">
            @error('supervisor_contact') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Minat Divisi</label>
            <input type="text" name="internship_interest" value="{{ old('internship_interest', $intern->internship_interest) }}" class="{{ $input }} @error('internship_interest') border-red-500 focus:ring-red-500 @enderror" required>
            @error('internship_interest') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6 lg:col-span-6">
            <label class="{{ $label }}">Minat (Lainnya)</label>
            <input type="text" name="internship_interest_other" value="{{ old('internship_interest_other', $intern->internship_interest_other) }}" class="{{ $input }} @error('internship_interest_other') border-red-500 focus:ring-red-500 @enderror">
            @error('internship_interest_other') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
        </div>
      </section>

      {{-- Keahlian Teknis (baru) --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">🧰</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Keahlian Teknis</h3>
        </div>

        <div class="grid grid-cols-12 gap-x-6 gap-y-6">
          <div class="col-span-12 md:col-span-4">
            <label class="{{ $label }}">Software Desain (jika Desain/UIUX)</label>
            <input name="design_software" value="{{ old('design_software', $intern->design_software) }}" placeholder="Figma, Photoshop, …" class="{{ $input }}">
            @error('design_software') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-4">
            <label class="{{ $label }}">Digital Marketing (materi yang diminati)</label>
            <input name="video_software" value="{{ old('video_software', $intern->video_software) }}" placeholder="Konten organik, Iklan, SEO …" class="{{ $input }}">
            @error('video_software') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-4">
            <label class="{{ $label }}">Bahasa Pemrograman (jika Programmer)</label>
            <input name="programming_languages" value="{{ old('programming_languages', $intern->programming_languages) }}" placeholder="PHP, JS, Python …" class="{{ $input }}">
            @error('programming_languages') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
        </div>

        {{-- Digital Marketing: tipe materi --}}
        <div class="mt-6">
          <label class="{{ $label }}">Jika memilih Digital Marketing, materi yang dipilih</label>
          <ul class="rounded-xl border border-gray-300/80 dark:border-gray-700/70 overflow-hidden">
            <li class="border-b border-gray-200/70 dark:border-gray-700/60">
              <label class="flex items-center gap-3 px-3 py-2">
                <input type="radio" name="digital_marketing_type" value="Organik" {{ Str::lower($dmType) === 'organik' ? 'checked' : '' }} class="h-4 w-4">
                <span class="text-sm">Digital Marketing Organik <span class="block text-xs text-gray-500">gratis, tanpa dana iklan</span></span>
              </label>
            </li>
            <li class="border-b border-gray-200/70 dark:border-gray-700/60">
              <label class="flex items-center gap-3 px-3 py-2">
                <input type="radio" name="digital_marketing_type" value="Iklan (FB/IG Ads)" {{ Str::startsWith(Str::lower($dmType), 'iklan') ? 'checked' : '' }} class="h-4 w-4">
                <span class="text-sm">Digital Marketing Iklan (FB/IG Ads) <span class="block text-xs text-gray-500">min. 30K/hari selama berjalan</span></span>
              </label>
            </li>
            <li class="p-3">
              <div class="flex items-center gap-3 mb-2">
                <input id="dm-other" type="radio" name="digital_marketing_type" value="Lainnya" {{ Str::lower($dmType) === 'lainnya' ? 'checked' : '' }} class="h-4 w-4">
                <label for="dm-other" class="text-sm">Lainnya</label>
              </div>
              <input name="digital_marketing_type_other" value="{{ old('digital_marketing_type_other', $intern->digital_marketing_type_other) }}" placeholder="Sebutkan" class="{{ $input }}">
            </li>
          </ul>
        </div>
      </section>

      {{-- Perlengkapan & Tools (baru) --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">💻</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Perlengkapan & Tools</h3>
        </div>

        {{-- Punya laptop? --}}
        <div class="mb-5">
          <label class="{{ $label }}">Apakah memiliki laptop untuk magang?</label>
          <ul class="rounded-xl border border-gray-300/80 dark:border-gray-700/70 overflow-hidden">
            <li class="border-b border-gray-200/70 dark:border-gray-700/60">
              <label class="flex items-center gap-3 px-3 py-2">
                <input type="radio" name="laptop_equipment" value="Ya" {{ $laptop === 'ya' ? 'checked' : '' }} class="h-4 w-4">
                <span class="text-sm">YA ADA</span>
              </label>
            </li>
            <li>
              <label class="flex items-center gap-3 px-3 py-2">
                <input type="radio" name="laptop_equipment" value="Tidak" {{ $laptop === 'tidak' ? 'checked' : '' }} class="h-4 w-4">
                <span class="text-sm">TIDAK ADA</span>
              </label>
            </li>
          </ul>
        </div>

        {{-- Tools yang bisa dibawa --}}
        <div>
          <label class="{{ $label }}">Jika YA, alat apa yang dapat dibawa?</label>
          <ul class="rounded-xl border border-gray-300/80 dark:border-gray-700/70 overflow-hidden">
            @foreach ($ownedToolsOptions as $tool)
              @php
                $isChecked = in_array(Str::lower($tool), $ownedToolsSelectedLC, true);
              @endphp
              <li class="border-b last:border-0 border-gray-200/70 dark:border-gray-700/60">
                <label class="flex items-center gap-3 px-3 py-2">
                  <input type="checkbox" name="owned_tools[]" value="{{ $tool }}" {{ $isChecked ? 'checked' : '' }} class="h-4 w-4">
                  <span class="text-sm">{{ $tool }}</span>
                </label>
              </li>
            @endforeach
            <li class="p-3">
              <div class="flex items-center gap-3 mb-2">
                @php $otherChecked = in_array('lainnya', $ownedToolsSelectedLC, true); @endphp
                <input id="tool-other" type="checkbox" name="owned_tools[]" value="Lainnya" {{ $otherChecked ? 'checked' : '' }} class="h-4 w-4">
                <label for="tool-other" class="text-sm">Lainnya</label>
              </div>
              <input name="owned_tools_other" value="{{ old('owned_tools_other', $intern->owned_tools_other) }}" placeholder="Sebutkan alat lainnya" class="{{ $input }}">
            </li>
          </ul>
        </div>
      </section>

      {{-- Sumber Informasi (baru) --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">🔗</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Sumber Informasi Magang</h3>
        </div>

        <ul class="rounded-xl border border-gray-300/80 dark:border-gray-700/70 overflow-hidden">
          @foreach ($infoSourcesOptions as $val => $text)
            @php $checked = in_array($val, $infoSelectedLC, true) || in_array(Str::lower($text), $infoSelectedLC, true); @endphp
            <li class="border-b last:border-0 border-gray-200/70 dark:border-gray-700/60">
              <label class="flex items-center gap-3 px-3 py-2">
                <input type="checkbox" name="internship_info_sources[]" value="{{ $val }}" {{ $checked ? 'checked' : '' }} class="h-4 w-4">
                <span class="text-sm">{{ $text }}</span>
              </label>
            </li>
          @endforeach
          <li class="p-3">
            @php $infoOtherChecked = in_array('other', $infoSelectedLC, true) || in_array('lainnya', $infoSelectedLC, true); @endphp
            <div class="flex items-center gap-3 mb-2">
              <input id="info-other" type="checkbox" name="internship_info_sources[]" value="other" {{ $infoOtherChecked ? 'checked' : '' }} class="h-4 w-4">
              <label for="info-other" class="text-sm">Lainnya</label>
            </div>
            <input name="internship_info_other" value="{{ old('internship_info_other', $intern->internship_info_other) }}" placeholder="Sebutkan sumber lain" class="{{ $input }}">
          </li>
        </ul>
      </section>

      {{-- Kegiatan & Domisili & Kontak (baru) --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">📇</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Kegiatan & Kontak</h3>
        </div>

        <div class="grid grid-cols-12 gap-x-6 gap-y-6">
          <div class="col-span-12">
            <label class="{{ $label }}">Kegiatan Anda saat ini selain magang/PKL?</label>
            <input name="current_activities" value="{{ old('current_activities', $intern->current_activities) }}" placeholder="Tuliskan jawaban Anda..." class="{{ $input }}">
            @error('current_activities') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6">
            <label class="{{ $label }}">Butuh info kos/kontrakan dekat kantor?</label>
            <ul class="rounded-xl border border-gray-300/80 dark:border-gray-700/70 overflow-hidden">
              <li class="border-b border-gray-200/70 dark:border-gray-700/60">
                <label class="flex items-center gap-3 px-3 py-2">
                  <input type="radio" name="boarding_info" value="Ya" {{ $boarding === 'ya' ? 'checked' : '' }} class="h-4 w-4">
                  <span class="text-sm">YA</span>
                </label>
              </li>
              <li>
                <label class="flex items-center gap-3 px-3 py-2">
                  <input type="radio" name="boarding_info" value="Tidak" {{ $boarding === 'tidak' ? 'checked' : '' }} class="h-4 w-4">
                  <span class="text-sm">TIDAK</span>
                </label>
              </li>
            </ul>
          </div>



          <div class="col-span-12 md:col-span-6">
            <label class="{{ $label }}">Sosial Media (Instagram)</label>
            <input name="social_media_instagram" value="{{ old('social_media_instagram', $intern->social_media_instagram) }}" placeholder="@username" class="{{ $input }}">
            @error('social_media_instagram') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
        </div>

        {{-- Info Jadwal (informasi statis dari form page) --}}
        <div class="mt-6 p-5 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 text-zinc-800 dark:text-zinc-100">
          <h1 class="text-base md:text-lg font-semibold mb-2">📅 Jadwal Magang</h1>
          <p class="text-sm">Hari magang: <strong>Senin - Sabtu</strong>.</p>
          <p class="text-sm"><strong>Kantor 1:</strong> Pagi (06.30–13.00) & Siang (13.00–21.00 WIB)</p>
          <p class="text-sm"><strong>Kantor 2:</strong> Middle (09.00–17.00) / Pagi & Siang</p>
          <p class="text-sm"><strong>Kantor 4:</strong> Middle (09.00–17.00) / Pagi & Siang</p>
        </div>
      </section>

      {{-- Berkas --}}
      <section class="{{ $card }}">
        <div class="flex items-center gap-2 mb-6">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">📎</span>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Berkas</h3>
        </div>

        <div class="grid grid-cols-12 gap-x-6 gap-y-6">
          <div class="col-span-12 md:col-span-6">
            <label class="{{ $label }}">CV/KTP/Portofolio (PDF)</label>
            @if ($intern->cv_ktp_portofolio_pdf)
              <div class="text-sm mb-2">
                File saat ini:
                <a class="text-primary-700 dark:text-primary-300 underline"
                   href="{{ Storage::disk('public')->url($intern->cv_ktp_portofolio_pdf) }}" target="_blank">
                  {{ basename($intern->cv_ktp_portofolio_pdf) }}
                </a>
              </div>
            @endif
            <label class="group flex items-center justify-center w-full rounded-xl border border-dashed
                          border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-800/80 px-3 py-6 text-sm
                          shadow-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/70 transition">
              <input type="file" name="cv_ktp_portofolio_pdf" accept="application/pdf" class="hidden">
              <span class="group-hover:underline">Unggah PDF (maks 10MB)</span>
            </label>
            @error('cv_ktp_portofolio_pdf') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>

          <div class="col-span-12 md:col-span-6">
            <label class="{{ $label }}">Portofolio Visual (JPG/PNG/PDF)</label>
            @if ($intern->portofolio_visual)
              <div class="text-sm mb-2">
                File saat ini:
                <a class="text-primary-700 dark:text-primary-300 underline"
                   href="{{ Storage::disk('public')->url($intern->portofolio_visual) }}" target="_blank">
                  {{ basename($intern->portofolio_visual) }}
                </a>
              </div>
            @endif
            <label class="group flex items-center justify-center w-full rounded-xl border border-dashed
                          border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-800/80 px-3 py-6 text-sm
                          shadow-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/70 transition">
              <input type="file" name="portofolio_visual" accept=".jpg,.jpeg,.png,application/pdf" class="hidden">
              <span class="group-hover:underline">Unggah JPG/PNG/PDF (maks 10MB)</span>
            </label>
            @error('portofolio_visual') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
          </div>
        </div>
      </section>

      {{-- Aksi --}}
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
        <button type="submit"
                class="inline-flex justify-center items-center gap-2 px-5 py-3 rounded-xl bg-primary-600 text-white hover:bg-primary-700 shadow">
          💾 Simpan Perubahan
        </button>
        <a href="{{ route('user.dashboard') }}"
           class="inline-flex justify-center items-center gap-2 px-5 py-3 rounded-xl border border-gray-300 dark:border-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800/60 shadow">
          Batal
        </a>
      </div>
    </form>
    @endif
  </div>
</div>
@endsection
