<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Data Pendaftar Internship</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-primary-600 min-h-screen flex flex-col">

  <!-- Header -->
  <header class="bg-primary-700 shadow-md">
    <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
      <h1 class="text-white text-2xl font-bold">📋 Data Pendaftar Internship</h1>
      <button onclick="window.location='{{ route('dashboard.index') }}'" class="bg-white text-primary-700 px-4 py-2 rounded-lg font-medium shadow hover:bg-gray-100">
        Dashboard
      </button>
    </div>
  </header>

  <!-- Content -->
  <main class="flex-1">
    <div class="max-w-7xl mx-auto p-6">
      
      @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 shadow">
          {{ session('success') }}
        </div>
      @endif

      <div class="overflow-x-auto bg-white shadow-xl rounded-2xl">
        <table class="min-w-full text-sm text-left text-gray-600">
          <thead class="bg-primary-100 text-xs uppercase text-primary-800">
            <tr class="text-center">
              <th class="px-4 py-3 border">No</th>
              @php
                $fields = [
                  'fullname' => 'Nama Lengkap',
                  'born_date' => 'Tanggal Lahir',
                  'student_id' => 'NIM / NIS',
                  'email' => 'Email',
                  'gender' => 'Gender',
                  'phone_number' => 'Telepon',
                  'institution_name' => 'Institusi',
                  'study_program' => 'Prodi',
                  'faculty' => 'Fakultas',
                  'current_city' => 'Kota',
                  'internship_reason' => 'Alasan Magang',
                  'internship_type' => 'Jenis Magang',
                  'internship_arrangement' => 'Tipe Magang',
                  'current_status' => 'Status Saat Ini',
                  'english_book_ability' => 'Kemampuan Baca Inggris',
                  'supervisor_contact' => 'Kontak Pembimbing',
                  'internship_interest' => 'Bidang Minat',
                  'internship_interest_other' => 'Minat Lain',
                  'design_software' => 'Software Desain',
                  'video_software' => 'Software Video',
                  'programming_languages' => 'Bahasa Pemrograman',
                  'digital_marketing_type' => 'Digital Marketing',
                  'digital_marketing_type_other' => 'Marketing Lain',
                  'laptop_equipment' => 'Punya Laptop',
                  'owned_tools' => 'Alat yang Dimiliki',
                  'owned_tools_other' => 'Alat Lain',
                  'start_date' => 'Mulai',
                  'end_date' => 'Selesai',
                  'internship_info_sources' => 'Sumber Info',
                  'internship_info_other' => 'Info Lain',
                  'current_activities' => 'Aktivitas Saat Ini',
                  'boarding_info' => 'Info Kost',

                  'social_media_instagram' => 'Instagram',
                  'cv_ktp_portofolio_pdf' => 'File PDF',
                  'portofolio_visual' => 'File Visual'
                ];
              @endphp

              @foreach ($fields as $field => $label)
                <th class="px-4 py-3 border">{{ $label }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @forelse ($registrations as $i => $r)
              <tr class="hover:bg-primary-50 border-t text-center">
                <td class="px-4 py-3 border font-semibold">{{ $i + 1 }}</td>
                @foreach ($fields as $field => $label)
                  <td class="px-4 py-3 border">
                    @if (Str::startsWith($field, 'cv_') || Str::startsWith($field, 'portofolio_'))
                      @if ($r->$field)
                        <a href="{{ asset('storage/' . $r->$field) }}" target="_blank" class="text-primary-600 font-medium underline hover:text-primary-800">Lihat</a>
                      @else
                        <span class="text-gray-400">-</span>
                      @endif
                    @else
                      {{ $r->$field ?? '-' }}
                    @endif
                  </td>
                @endforeach
              </tr>
            @empty
              <tr>
                <td colspan="{{ count($fields) + 1 }}" class="text-center px-4 py-6 text-gray-500">Tidak ada data pendaftar.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <!-- Footer -->
  <footer class="bg-primary-700 text-white text-center py-4 mt-auto">
    <p class="text-sm">&copy; {{ date('Y') }} Sistem Pendaftaran Magang. All rights reserved.</p>
  </footer>

</body>
</html>
