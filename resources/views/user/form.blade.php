<!-- resources/views/internship-registration/form.blade.php -->
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <title>Form Pendaftaran Magang/PKL</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <style>
    @keyframes shake {
      0% { transform: translateX(0); }
      25% { transform: translateX(-4px); }
      50% { transform: translateX(4px); }
      75% { transform: translateX(-4px); }
      100% { transform: translateX(0); }
    }
    .animate-shake {
      animation: shake 2s ease-in-out infinite;
    }
  </style>
</head>

<body class="bg-primary-400/20  min-h-screen flex items-start sm:items-center justify-center p-4">
  <!-- Floating Action Button -->
  <div class="fixed top-4 left-4 z-50">
    <!-- Back to Dashboard Button -->
    <a href="{{ route('user.dashboard') }}" class="bg-primary-600 hover:bg-primary-700 text-white font-bold py-2 px-4 rounded-full shadow-md">
      Kembali ke Dashboard
    </a>
    <!-- Logout Button -->
    <form action="{{ route('user.logout') }}" method="POST" class="mt-2">
      @csrf
      <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full shadow-md">
        Logout
      </button>
    </form>
  </div>


  <div class="w-full max-w-2xl">
    <div class="bg-white/80  backdrop-blur rounded-2xl shadow-xl ring-1 ring-zinc-200 overflow-hidden">
      <section class="py-8 lg:py-10 px-5 sm:px-8">
        <h2 class="mb-3 text-2xl sm:text-3xl font-extrabold text-center text-zinc-900">
          Form Pendaftaran Magang/PKL
        </h2>
        <p class="mb-8 text-center text-sm sm:text-base text-zinc-600">
          Silakan isi form pendaftaran ini dengan lengkap untuk memudahkan kami dalam memproses. Terima kasih.
        </p>

        <form action="{{ route('internship.store') }}" method="POST" enctype="multipart/form-data" class="space-y-7">
          @csrf

          @php
            $label = 'block mb-2 text-sm font-medium text-zinc-800';
            $help  = 'mt-1 text-xs text-zinc-500';
            $input = 'block w-full rounded-lg border border-zinc-300 bg-white text-zinc-900 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 px-3 py-2.5 shadow-sm';
            $group = 'bg-white border border-zinc-300 rounded-lg';
            $radio = 'w-4 h-4 text-primary-600 border-zinc-300 focus:ring-2 focus:ring-primary-500';
            $check = $radio;
            $item  = 'flex items-center gap-3 px-3 py-2 hover:bg-zinc-50 transition';
          @endphp

          <!-- Nama Lengkap -->
          <div>
            <label for="fullname" class="{{ $label }}">Nama Lengkap</label>
            <input type="text" id="fullname" name="fullname" required placeholder="Muhammad Sumbul" class="{{ $input }}" value="{{ old('fullname') }}" />
          </div>

          <!-- Tahun Lahir (string) -->
          <div>
            <label for="born_date" class="{{ $label }}">Tahun Lahir</label>
            <input type="text" id="born_date" name="born_date" placeholder="25 Juni 2005" required class="{{ $input }}" value="{{ old('born_date') }}" />
          </div>


          @if(\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'religion'))
          <!-- Agama -->
          <div>
            <label for="religion" class="{{ $label }}">Agama <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            @php
              $valRel = strtolower(trim((string) old('religion')));
            @endphp
            <select id="religion" name="religion" class="{{ $input }}">
              <option value="">-- Pilih Agama (Opsional) --</option>
              @foreach(['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu'] as $rel)
                @php
                  $optLower = strtolower($rel);
                  $isSelected = ($valRel === $optLower)
                    || ($optLower === 'kristen protestan' && in_array($valRel, ['kristen', 'protestan', 'kristen protestan'], true))
                    || ($optLower === 'khonghucu' && in_array($valRel, ['khonghucu', 'konghucu'], true));
                @endphp
                <option value="{{ $rel }}" @selected($isSelected)>{{ $rel }}</option>
              @endforeach
            </select>
            @error('religion') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
          </div>
          @endif

          <!-- NIM/NIS -->
          <div>
            <label for="student_id" class="{{ $label }}">Nomor Induk Mahasiswa / Nomor Induk Siswa Nasional</label>
            <input type="text" id="student_id" name="student_id" placeholder="21111234" required class="{{ $input }}" value="{{ old('student_id') }}" />
          </div>

          <!-- Email -->
          <div>
            <label for="email" class="{{ $label }}">Email</label>
            <input type="email" id="email" name="email" placeholder="surya@example.com" required class="{{ $input }}" value="{{ old('email') }}" />
          </div>

          <!-- Jenis Kelamin (value Indonesia) -->
          <div>
            <h3 class="{{ $label }}">Jenis Kelamin</h3>
            @php
              $valGender = strtolower(trim((string) old('gender')));
              $isMale = in_array($valGender, ['laki-laki', 'l', 'male', 'pria'], true);
              $isFemale = in_array($valGender, ['perempuan', 'p', 'female', 'wanita'], true);
            @endphp
            <ul class="{{ $group }}">
              <li class="border-b border-zinc-200">
                <label for="gender-lk" class="{{ $item }}">
                  <input id="gender-lk" type="radio" value="Laki-laki" name="gender" class="{{ $radio }}" @checked($isMale) />
                  <span class="text-sm">Laki-laki</span>
                </label>
              </li>
              <li>
                <label for="gender-pr" class="{{ $item }}">
                  <input id="gender-pr" type="radio" value="Perempuan" name="gender" class="{{ $radio }}" @checked($isFemale) />
                  <span class="text-sm">Perempuan</span>
                </label>
              </li>
            </ul>
          </div>

          <!-- Telepon -->
          <div>
            <label for="phone_number" class="{{ $label }}">No. HP Aktif (WhatsApp)</label>
            <input type="text" id="phone_number" name="phone_number" placeholder="08xxxxxxxxxx" required class="{{ $input }}" value="{{ old('phone_number') }}" />
          </div>

          <!-- Institusi -->
          <div>
            <label for="institution_name" class="{{ $label }}">Asal Sekolah/Kampus</label>
            <input type="text" id="institution_name" name="institution_name" placeholder="SMA Negeri 1/Amikom Yogyakarta" required class="{{ $input }}" value="{{ old('institution_name') }}" />
          </div>

          <!-- Prodi -->
          <div>
            <label for="study_program" class="{{ $label }}">Program Studi</label>
            <input type="text" id="study_program" name="study_program" placeholder="Teknik Informatika" required class="{{ $input }}" value="{{ old('study_program') }}" />
          </div>

          <!-- Fakultas -->
          <div>
            <label for="faculty" class="{{ $label }}">Fakultas <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <input type="text" id="faculty" name="faculty" placeholder="Ilmu Komputer" class="{{ $input }}" value="{{ old('faculty') }}" />
          </div>


          <!-- Alasan -->
          <div>
            <label for="internship_reason" class="{{ $label }}">Mengapa Anda ingin Magang/PKL di sini?</label>
            <input type="text" id="internship_reason" name="internship_reason" placeholder="Alasan Anda" required class="{{ $input }}" value="{{ old('internship_reason') }}" />
          </div>

          <!-- Jenis Magang (value Indonesia) -->
          <div>
            <h3 class="{{ $label }}">Jenis Magang yang dipilih</h3>
            <ul class="{{ $group }}">
              <li class="border-b border-zinc-200">
                <label for="type-mandiri" class="{{ $item }}">
                  <input id="type-mandiri" type="radio" value="Magang Mandiri" name="internship_type" class="{{ $radio }}" @checked(old('internship_type') === 'Magang Mandiri') />
                  <span class="text-sm">Magang Mandiri</span>
                </label>
              </li>
              <li>
                <label for="type-kampus" class="{{ $item }}">
                  <input id="type-kampus" type="radio" value="Magang Kampus" name="internship_type" class="{{ $radio }}" @checked(old('internship_type') === 'Magang Kampus') />
                  <span class="text-sm">Magang Kampus / Reguler</span>
                </label>
              </li>
            </ul>
          </div>


          <!-- Sistem Magang: otomatis Onsite -->
          <input type="hidden" name="internship_arrangement" value="Onsite">



          <!-- Status Saat Ini (value Indonesia) -->
          <div>
            <h3 class="{{ $label }}">Status Anda saat ini</h3>
            <ul class="{{ $group }}">
              <li class="border-b border-zinc-200">
                <label for="status-student" class="{{ $item }}">
                  <input id="status-student" type="radio" value="Mahasiswa/Pelajar" name="current_status" class="{{ $radio }}" @checked(old('current_status') === 'Mahasiswa/Pelajar') />
                  <span class="text-sm">Masih sekolah/kuliah</span>
                </label>
              </li>
              <li class="border-b border-zinc-200">
                <label for="status-unemployed" class="{{ $item }}">
                  <input id="status-unemployed" type="radio" value="Tidak Bekerja" name="current_status" class="{{ $radio }}" @checked(old('current_status') === 'Tidak Bekerja') />
                  <span class="text-sm">Lulus & belum bekerja</span>
                </label>
              </li>
              <li>
                <label for="status-employed" class="{{ $item }}">
                  <input id="status-employed" type="radio" value="Karyawan" name="current_status" class="{{ $radio }}" @checked(old('current_status') === 'Karyawan') />
                  <span class="text-sm">Lulus & sudah bekerja</span>
                </label>
              </li>
            </ul>
          </div>

          <!-- Kemampuan baca buku Inggris -->
          <div>
            <h3 class="{{ $label }}">Kemampuan membaca buku berbahasa Inggris</h3>
            <ul class="{{ $group }}">
              <li class="border-b border-zinc-200">
                <label for="eng-bisa" class="{{ $item }}">
                  <input id="eng-bisa" type="radio" value="Saya bisa" name="english_book_ability" class="{{ $radio }}" @checked(old('english_book_ability') === 'Saya bisa') />
                  <span class="text-sm">Saya bisa</span>
                </label>
              </li>
              <li class="border-b border-zinc-200">
                <label for="eng-kurang" class="{{ $item }}">
                  <input id="eng-kurang" type="radio" value="Kurang bisa" name="english_book_ability" class="{{ $radio }}" @checked(old('english_book_ability') === 'Kurang bisa') />
                  <span class="text-sm">Kurang bisa</span>
                </label>
              </li>
              <li>
                <label for="eng-tidak" class="{{ $item }}">
                  <input id="eng-tidak" type="radio" value="Tidak bisa" name="english_book_ability" class="{{ $radio }}" @checked(old('english_book_ability') === 'Tidak bisa') />
                  <span class="text-sm">Tidak bisa</span>
                </label>
              </li>
            </ul>
          </div>

          <!-- Kontak pembimbing -->
          <div>
            <label for="supervisor_contact" class="{{ $label }}">No. HP/WA Dosen atau Guru Pembimbing (bisa menyusul) <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <input type="text" id="supervisor_contact" name="supervisor_contact" placeholder="0812xxxxxxx - Nama (Dosen/Guru)" class="{{ $input }}" value="{{ old('supervisor_contact') }}" />
          </div>

          <!-- Minat program (value Indonesia untuk simpan apa adanya) -->
          <div>
            <h3 class="{{ $label }}">Program Magang/PKL yang diminati</h3>
            <ul class="{{ $group }}">
              @foreach ([
                      'Project Manager', 'Administration', 'Human Resources (HR)', 'UI/UX', 'Programmer (Front End / Backend)', 'Photographer', 'Videographer', 'Graphic Designer', 'Social Media Specialist', 'Content Writer', 'Content Planner', 'Sales & Marketing', 'Public Relations (Marcomm)', 'Digital Marketing', 'TikTok Creator', 'Welding', 'Customer Service'
              ] as $labelText)
                <li class="border-b last:border-0 border-zinc-200">
                  <label class="{{ $item }}">
                    <input type="radio" value="{{ $labelText }}" name="internship_interest" class="{{ $radio }}" @checked(old('internship_interest') === $labelText) />
                    <span class="text-sm">{{ $labelText }}</span>
                  </label>
                </li>
              @endforeach
              <li class="p-3">
                <div class="flex items-center gap-3 mb-2">
                  <input id="radio-other" type="radio" value="Lainnya" name="internship_interest" class="{{ $radio }}" @checked(old('internship_interest') === 'Lainnya') />
                  <label for="radio-other" class="text-sm">Lainnya</label>
                </div>
                <input type="text" id="radio-other-input" name="internship_interest_other" placeholder="Sebutkan" class="{{ $input }}" value="{{ old('internship_interest_other') }}" />
              </li>
            </ul>
          </div>

          <!-- Info jadwal -->
          <div>
            <div class="p-5 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 text-zinc-800 dark:text-zinc-100">
              <h1 class="text-base md:text-lg font-semibold mb-2">📅 Jadwal Magang</h1>
              <p class="text-sm">Hari magang: <strong>Senin - Sabtu</strong>.</p>
              <p class="text-sm"><strong>Kantor 1:</strong> Pagi (06.30–13.00) & Siang (13.00–21.00 WIB)</p>
              <p class="text-sm"><strong>Kantor 2:</strong> Middle (09.00–17.00) / Pagi & Siang</p>
              <p class="text-sm"><strong>Kantor 4:</strong> Middle (09.00–17.00) / Pagi & Siang</p>
            </div>
          </div>

          <!-- Software Desain -->
          <div>
            <label for="design_software" class="{{ $label }}">
              Jika minat <strong>Desain Grafis</strong> / <strong>UI/UX</strong>, software apa yang dikuasai?
              <span class="{{ $help }}">(Jika bukan, isi “-”)</span>
            </label>
            <input id="design_software" name="design_software" placeholder="Figma, Photoshop" required class="{{ $input }}" value="{{ old('design_software') }}" />
          </div>

          <!-- Digital Marketing: materi -->
          <div>
            <label for="video_software" class="{{ $label }}">
              Jika minat <strong>Digital Marketing</strong>, materi apa yang ingin dipraktikkan?
              <span class="{{ $help }}">(Jika bukan, isi “-”)</span>
            </label>
            <input id="video_software" name="video_software" placeholder="Konten organik, Iklan, SEO" required class="{{ $input }}" value="{{ old('video_software') }}" />
          </div>

          <!-- Bahasa Pemrograman -->
          <div>
            <label for="programming_languages" class="{{ $label }}">
              Jika minat <strong>Programmer</strong>, bahasa pemrograman yang dikuasai?
              <span class="{{ $help }}">(Jika bukan, isi “-”)</span>
            </label>
            <input id="programming_languages" name="programming_languages" placeholder="PHP, JS, Python" required class="{{ $input }}" value="{{ old('programming_languages') }}" />
          </div>

          <!-- Tipe Digital Marketing (value Indonesia) -->
          <div>
            <h3 class="{{ $label }}">Jika memilih Digital Marketing, materi yang dipilih</h3>
            <ul class="{{ $group }}">
              <li class="border-b border-zinc-200">
                <label for="dm-organik" class="{{ $item }}">
                  <input id="dm-organik" type="radio" value="Organik" name="digital_marketing_type" class="{{ $radio }}" @checked(old('digital_marketing_type') === 'Organik') />
                  <span class="text-sm">
                    Digital Marketing Organik
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">(gratis, tanpa dana iklan)</span>
                  </span>
                </label>
              </li>
              <li class="border-b border-zinc-200">
                <label for="dm-ads" class="{{ $item }}">
                  <input id="dm-ads" type="radio" value="Iklan (FB/IG Ads)" name="digital_marketing_type" class="{{ $radio }}" @checked(old('digital_marketing_type') === 'Iklan (FB/IG Ads)') />
                  <span class="text-sm">
                    Digital Marketing Iklan (FB/IG Ads)
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">(min. 30K/hari selama berjalan)</span>
                  </span>
                </label>
              </li>
              <li class="p-3">
                <div class="flex items-center gap-3 mb-2">
                  <input id="dm-other" type="radio" value="Lainnya" name="digital_marketing_type" class="{{ $radio }}" @checked(old('digital_marketing_type') === 'Lainnya') />
                  <label for="dm-other" class="text-sm">Lainnya</label>
                </div>
                <input id="dm-other-input" name="digital_marketing_type_other" placeholder="Sebutkan" class="{{ $input }}" value="{{ old('digital_marketing_type_other') }}" />
              </li>
            </ul>
          </div>

          <!-- Punya Laptop? (value Indonesia) -->
          <div>
            <h3 class="{{ $label }}">Apakah memiliki laptop untuk magang?</h3>
            <ul class="{{ $group }}">
              <li class="border-b border-zinc-200 ">
                <label for="laptop-ya" class="{{ $item }}">
                  <input id="laptop-ya" type="radio" value="Ya" name="laptop_equipment" class="{{ $radio }}" @checked(old('laptop_equipment') === 'Ya') />
                  <span class="text-sm">YA ADA</span>
                </label>
              </li>
              <li>
                <label for="laptop-tidak" class="{{ $item }}">
                  <input id="laptop-tidak" type="radio" value="Tidak" name="laptop_equipment" class="{{ $radio }}" @checked(old('laptop_equipment') === 'Tidak') />
                  <span class="text-sm">TIDAK ADA</span>
                </label>
              </li>
            </ul>
          </div>

          <!-- Alat yang bisa dibawa (value Indonesia) -->
          <div>
            <h3 class="{{ $label }}">Jika YA, alat apa yang dapat dibawa?</h3>
            <ul class="{{ $group }}">
              @foreach ([
                'Corel / Photoshop',
                'Adobe Premiere / After Effects',
                'Kamera',
                'Drone',
                'Pen Tablet',
                'Tripod',
              ] as $text)
                <li class="border-b last:border-0 border-zinc-200">
                  <label class="{{ $item }}">
                    <input type="checkbox" value="{{ $text }}" name="owned_tools[]" class="{{ $check }}" @checked(collect(old('owned_tools', []))->contains($text)) />
                    <span class="text-sm">{{ $text }}</span>
                  </label>
                </li>
              @endforeach
              <li class="p-3">
                <div class="flex items-center gap-3 mb-2">
                  <input id="tool-other" type="checkbox" value="Lainnya" name="owned_tools[]" class="{{ $check }}" @checked(collect(old('owned_tools', []))->contains('Lainnya')) />
                  <label for="tool-other" class="text-sm">Lainnya</label>
                </div>
                <input type="text" id="tool-other-input" name="owned_tools_other" placeholder="Sebutkan alat lainnya" class="{{ $input }}" value="{{ old('owned_tools_other') }}" />
              </li>
            </ul>
          </div>

          <!-- Rentang Tanggal (STRING) -->
          <div>
            <label class="{{ $label }}">
              Kapan rencana mulai Magang/PKL?
              <span class="{{ $help }}">Tulis lengkap tanggal, bulan, tahun & durasi (contoh: 10 September 2025)</span>
            </label>
            <div class="flex flex-col sm:flex-row items-center gap-4">
              <input id="start_date" type="text" name="start_date" value="{{ old('start_date', request('start_date')) }}" class="{{ $input }}" placeholder="Tanggal mulai (10 September 2025)">
              <span class="text-zinc-700 dark:text-zinc-300">s/d</span>
              <input id="end_date" type="text" name="end_date" value="{{ old('end_date', request('end_date')) }}" class="{{ $input }}" placeholder="Tanggal selesai (10 Desember 2025)">
            </div>
          </div>

          <!-- Sumber Info (benahi other) -->
          <div>
            <h3 class="{{ $label }}">Anda tahu info magang ini dari mana?</h3>
            <ul class="{{ $group }}">
              @foreach (['Website','Instagram','Twitter','Glints','YouTube'] as $text)
                @php $val = \Illuminate\Support\Str::lower($text); @endphp
                <li class="border-b last:border-0 border-zinc-200">
                  <label class="{{ $item }}">
                    <input type="checkbox" value="{{ $val }}" name="internship_info_sources[]" class="{{ $check }}" @checked(collect(old('internship_info_sources', []))->contains($val)) />
                    <span class="text-sm">{{ $text }}</span>
                  </label>
                </li>
              @endforeach
              <li class="p-3">
                <div class="flex items-center gap-3 mb-2">
                  <input id="info-other" type="checkbox" value="other" name="internship_info_sources[]" class="{{ $check }}" @checked(collect(old('internship_info_sources', []))->contains('other')) />
                  <label for="info-other" class="text-sm">Lainnya</label>
                </div>
                <input id="info-other-input" name="internship_info_other" placeholder="Sebutkan sumber lain" class="{{ $input }}" value="{{ old('internship_info_other') }}" />
              </li>
            </ul>
          </div>

          <!-- Upload -->
          <div class="space-y-6">
            <div>
              <label for="file-input-1" class="{{ $label }}">
                Upload <strong>CV, Scan KTP/KTM, Portofolio</strong> <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span> <span class="{{ $help }}">Format PDF</span>
              </label>
              <input id="file-input-1" type="file" name="cv_ktp_portofolio_pdf" class="{{ $input }} file:mr-4 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-primary-600 file:text-white hover:file:bg-primary-700" />
            </div>
            <div>
              <label for="file-input-2" class="{{ $label }}">Upload <strong>Portofolio Visual</strong> <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
              <p class="{{ $help }}">JPG (desainer) atau link YouTube (videografer)</p>
              <input id="file-input-2" type="file" name="portofolio_visual" class="{{ $input }} file:mr-4 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-primary-600 file:text-white hover:file:bg-primary-700" />
            </div>
          </div>

          <!-- Kegiatan lain -->
          <div>
            <label for="current-activities" class="{{ $label }}">Kegiatan Anda saat ini selain magang/PKL? <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <p class="{{ $help }}">Jika ada, mohon sebutkan</p>
            <input id="current-activities" name="current_activities" placeholder="Tuliskan jawaban Anda..." class="{{ $input }}" value="{{ old('current_activities') }}" />
          </div>

          <!-- Info kos -->
          <div>
            <label class="{{ $label }}">Butuh info kos/kontrakan dekat kantor? <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <ul class="{{ $group }}">
              <li class="border-b border-zinc-200">
                <label for="boarding-yes" class="{{ $item }}">
                  <input id="boarding-yes" type="radio" value="Ya" name="boarding_info" class="{{ $radio }}" @checked(old('boarding_info') === 'Ya') />
                  <span class="text-sm">YA</span>
                </label>
              </li>
              <li>
                <label for="boarding-no" class="{{ $item }}">
                  <input id="boarding-no" type="radio" value="Tidak" name="boarding_info" class="{{ $radio }}" @checked(old('boarding_info') === 'Tidak') />
                  <span class="text-sm">TIDAK</span>
                </label>
              </li>
            </ul>
          </div>



          <!-- Instagram -->
          <div>
            <label for="social-media-link" class="{{ $label }}">Sosial Media (Instagram) <span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span></label>
            <input id="social-media-link" name="social_media_instagram" placeholder="@cakwlive" class="{{ $input }}" value="{{ old('social_media_instagram') }}" />
          </div>

          <!-- Info unpaid -->
          <div>
            <div class="p-5 rounded-lg border border-amber-300  bg-amber-50 text-amber-900 ">
              <h1 class="text-sm md:text-base leading-relaxed">
                Program Magang ini bersifat <span class="font-semibold">unpaid / tidak bergaji</span>.
                Jika Anda setuju, silakan <strong>SUBMIT</strong> dan <strong>KONFIRMASI</strong> ke WA Admin
                <span class="font-semibold">0895 2900 2944</span> dengan pesan:
                <span class="italic">"SAYA SUDAH ISI FORM"</span>. Terima kasih 🙏
              </h1>
            </div>
          </div>

          <!-- Submit -->
          <button type="submit" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-5 py-3 rounded-lg text-sm font-medium bg-primary-600 hover:bg-primary-700 text-white focus:outline-none focus:ring-4 focus:ring-primary-300 dark:focus:ring-primary-800">
            Kirim Formulir
          </button>
        </form>
      </section>
    </div>
  </div>
</body>
</html>
