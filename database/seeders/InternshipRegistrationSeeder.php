<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\InternshipRegistration as IR;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class InternshipRegistrationSeeder extends Seeder
{
    public function run(): void
    {
        $firstM = ['Muhammad','Ahmad','Nur','Rizky','Agus','Budi','Andi','Fajar','Arief','Rian','Bayu','Rizal','Fadil','Dwi','Dimas','Eko','Yoga','Ilham','Fauzan','Rafli','Yusuf','Heri','Dede','Galang','Aditya','Bagus','Zaki','Iqbal','Rangga','Reza'];
        $firstF = ['Siti','Nurul','Putri','Ayu','Dewi','Lia','Nadia','Intan','Rani','Dinda','Citra','Wulan','Anisa','Maya','Bella','Sarah','Rizka','Nabila','Fitri','Aulia','Tyas','Niken','Mega','Hana','Zahra','Indah','Silvia','Risa','Yuni','Vina'];
        $last    = ['Pratama','Saputra','Permana','Ramadhan','Santoso','Hidayat','Wijaya','Wibowo','Prasetyo','Fauzi','Kurniawan','Setiawan','Maulana','Pangestu','Firmansyah','Alamsyah','Siregar','Simanjuntak','Sihombing','Nugroho','Susanto','Santika','Lestari','Safitri','Utami','Herlambang','Cahyono','Kusuma','Wardani','Puspitasari'];

        $birthPlaces = ['Yogyakarta', 'Sleman', 'Bantul', 'Jakarta', 'Bandung', 'Semarang', 'Surabaya', 'Malang', 'Surakarta', 'Magelang', 'Cilacap', 'Purwokerto'];
        $religions = ['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha'];
        $internTypes = ['Magang Mandiri', 'Magang Kampus', 'PKL', 'Kampus Merdeka'];
        $arrangements = ['Onsite', 'Hibrida', 'Remote'];
        $currentStatus = ['Mahasiswa/Pelajar', 'Lulusan Baru', 'Karyawan', 'Tidak Bekerja'];
        $durations = ['3 Bulan', '4 Bulan', '5 Bulan', '6 Bulan'];
        $engAbility = ['Saya bisa', 'Kurang bisa', 'Tidak bisa'];
        $sources = ['website', 'instagram', 'twitter', 'glints', 'youtube'];
        $boardingChoices = ['Ya, butuh info kost/kontrakan dekat kantor', 'Tidak butuh info kost/sudah ada'];

        $supervisors = [
            ['Dr. Hendra Gunawan, M.Kom', '081234567890'],
            ['Dra. Sri Wahyuni, M.Pd', '081398765432'],
            ['Bambang Sutrisno, S.T., M.T.', '085712345678'],
            ['Nurhayati, S.Kom., M.Cs.', '087812345678'],
            ['Agus Priyono, M.Eng.', '089612345678'],
            ['Prof. Dr. Ir. Suyanto, M.T.', '081223344556'],
        ];

        $activities = [
            'Kuliah semester akhir (hanya menyusun skripsi/tugas akhir)',
            'Mengikuti kegiatan organisasi kampus di akhir pekan',
            'Tidak ada kegiatan lain, siap fokus magang penuh waktu',
            'Kuliah online di malam hari',
        ];

        // Distribusi status agar mencakup seluruh tab pendaftar dan pemagang
        $statusSequence = [
            'waiting', 'waiting', 'waiting', 'waiting', 'waiting',
            'accepted', 'accepted', 'accepted', 'accepted', 'accepted',
            'active', 'active', 'active', 'active',
            'completed', 'completed', 'completed',
            'rejected', 'rejected', 'rejected',
            'pending', 'pending',
            'exited', 'exited',
        ];

        $pick = fn(array $a) => $a[array_rand($a)];
        $randBool = fn(int $pctTrue = 50) => mt_rand(1, 100) <= $pctTrue;
        $randDate = function(string $min, string $max) {
            return date('Y-m-d', mt_rand(strtotime($min), strtotime($max)));
        };

        $cityIds = DB::table('cities')->pluck('id')->toArray();
        $institutionIds = DB::table('institutions')->pluck('id')->toArray();
        $facultyIds = DB::table('faculties')->pluck('id')->toArray();
        $studyProgramIds = DB::table('study_programs')->pluck('id')->toArray();
        $divisionIds = DB::table('divisions')->pluck('id')->toArray();
        $brandIds = DB::table('brands')->pluck('id')->toArray();

        $defaultCityId = !empty($cityIds) ? $cityIds[0] : 1;
        $defaultInstId = !empty($institutionIds) ? $institutionIds[0] : 1;
        $defaultFacId = !empty($facultyIds) ? $facultyIds[0] : 1;
        $defaultProdiId = !empty($studyProgramIds) ? $studyProgramIds[0] : 1;
        $defaultDivId = !empty($divisionIds) ? $divisionIds[0] : 1;
        $defaultBrandId = !empty($brandIds) ? $brandIds[0] : null;

        $password = Hash::make('password123');

        $totalRecords = 50;
        for ($i = 1; $i <= $totalRecords; $i++) {
            $isMale = $randBool(55);
            $fname  = $isMale ? $pick($firstM) : $pick($firstF);
            $lname  = $pick($last);
            $mname  = $randBool(35) ? ' ' . $pick($last) : '';
            $fullname = trim("$fname$mname $lname");

            $born = $randDate('1999-01-01', '2007-12-31');
            $studentId = sprintf('%02d%02d%04d', mt_rand(18, 25), mt_rand(1, 14), mt_rand(1000, 9999));
            $userSlug = Str::slug($fullname, '.');
            $email = $userSlug . mt_rand(1, 999) . '@gmail.com';
            $phone = '08' . mt_rand(11, 99) . mt_rand(100, 999) . mt_rand(1000, 9999);

            $reason = $pick([
                'Ingin menambah pengalaman kerja nyata dan mengasah keterampilan industri',
                'Mencari bimbingan praktis profesional sesuai jurusan yang ditempuh',
                'Memenuhi syarat mata kuliah magang/PKL dari kampus dengan hasil optimal',
                'Tertarik dengan budaya kerja Seven Inc dan ingin berkontribusi langsung'
            ]);

            $sup = $pick($supervisors);
            $status = $statusSequence[($i - 1) % count($statusSequence)];
            $createdAt = Carbon::create(2026, mt_rand(1, 10), mt_rand(1, 28), mt_rand(8, 17), mt_rand(0, 59), mt_rand(0, 59));

            // 1. Create User
            $userId = DB::table('users')->insertGetId([
                'name'       => $fullname,
                'email'      => $email,
                'password'   => $password,
                'role'       => 'pemagang',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // 2. Create Registration with ALL 47 fields
            $regId = DB::table('internship_registrations')->insertGetId([
                'user_id'                  => $userId,
                'fullname'                 => $fullname,
                'student_id'               => $studentId,
                'gender'                   => $isMale ? 'Laki-laki' : 'Perempuan',
                'birth_place'              => $pick($birthPlaces),
                'born_date'                => $born,
                'religion'                 => $pick($religions),
                'phone_number'             => $phone,
                'city_id'                  => !empty($cityIds) ? $pick($cityIds) : $defaultCityId,
                'institution_id'           => !empty($institutionIds) ? $pick($institutionIds) : $defaultInstId,
                'faculty_id'               => !empty($facultyIds) ? $pick($facultyIds) : $defaultFacId,
                'study_program_id'         => !empty($studyProgramIds) ? $pick($studyProgramIds) : $defaultProdiId,
                'internship_reason'        => $reason,
                'internship_type'          => $pick($internTypes),
                'internship_arrangement'   => $pick($arrangements),
                'current_status'           => $pick($currentStatus),
                'english_book_ability'     => $pick($engAbility),
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup dan bersedia mengikuti jadwal shifting Senin-Sabtu',
                'has_laptop'               => true,
                'planned_duration'         => $pick($durations),
                'division_id'              => !empty($divisionIds) ? $pick($divisionIds) : $defaultDivId,
                'brand_id'                 => !empty($brandIds) ? $pick($brandIds) : $defaultBrandId,
                'internship_status'        => $status,
                'start_date'               => $randDate('2026-08-01', '2026-10-01'),
                'end_date'                 => $randDate('2026-11-01', '2027-02-01'),
                'supervisor_name'          => $sup[0],
                'supervisor_contact'       => $sup[1],
                'current_activities'       => $pick($activities),
                'social_media_instagram'   => '@' . $userSlug,
                'boarding_info'            => $pick($boardingChoices),
                'created_at'               => $createdAt,
                'updated_at'               => $createdAt,
            ]);

            // 3. Create Pivots: Tools
            $toolItems = ['Laptop Core i5/i7', 'Mouse & Charger'];
            if ($randBool(60)) {
                $toolItems[] = 'Kamera Mirrorless / DSLR';
                $toolItems[] = 'Tripod';
            }
            foreach ($toolItems as $tool) {
                DB::table('intern_tools')->insert([
                    'internship_registration_id' => $regId,
                    'tool_name' => $tool
                ]);
            }

            // 4. Create Pivots: Skills
            $skillDefinitions = [
                ['category' => 'design', 'name' => 'Adobe Photoshop, Figma, CorelDraw'],
                ['category' => 'video', 'name' => 'Adobe Premiere Pro, CapCut, After Effects'],
                ['category' => 'programming', 'name' => 'PHP, Laravel, JavaScript, MySQL, HTML/CSS'],
                ['category' => 'digital_marketing', 'name' => 'Social Media Organic, Copywriting, Content Creator'],
            ];
            foreach ($skillDefinitions as $skillDef) {
                DB::table('intern_skills')->insert([
                    'internship_registration_id' => $regId,
                    'skill_category' => $skillDef['category'],
                    'skill_name' => $skillDef['name'],
                ]);
            }

            // 5. Create Pivots: Info Sources
            $srcs = collect($sources)->random(mt_rand(1, 2));
            foreach ($srcs as $src) {
                DB::table('intern_info_sources')->insert([
                    'internship_registration_id' => $regId,
                    'source_name' => $src
                ]);
            }

            // 6. Create Full Drive Attachments (CV, KTM/KTP, Portofolio, Surat Pengantar, Proposal, Dokumen Pendukung, Pasfoto)
            $attachmentDefs = [
                ['cat' => 'cv', 'type' => 'cv', 'label' => 'CV_' . $userSlug . '.pdf', 'mime' => 'application/pdf'],
                ['cat' => 'ktm_ktp', 'type' => 'ktm_ktp', 'label' => 'KTM_KTP_' . $userSlug . '.pdf', 'mime' => 'application/pdf'],
                ['cat' => 'portofolio', 'type' => 'portofolio', 'label' => 'Portofolio_' . $userSlug . '.pdf', 'mime' => 'application/pdf'],
                ['cat' => 'surat_pengantar', 'type' => 'surat_pengantar', 'label' => 'SuratPengantar_' . $userSlug . '.pdf', 'mime' => 'application/pdf'],
                ['cat' => 'proposal', 'type' => 'proposal', 'label' => 'Proposal_' . $userSlug . '.pdf', 'mime' => 'application/pdf'],
                ['cat' => 'dokumen_pendukung', 'type' => 'dokumen_pendukung', 'label' => 'DokumenPendukung_' . $userSlug . '.pdf', 'mime' => 'application/pdf'],
                ['cat' => 'pasfoto', 'type' => 'pasfoto', 'label' => 'Pasfoto_' . $userSlug . '.jpg', 'mime' => 'image/jpeg'],
            ];

            $folderDriveId = '1folder_reg_' . $regId . '_' . $userSlug;
            $folderDriveLink = 'https://drive.google.com/drive/folders/' . $folderDriveId . '?usp=sharing';
            DB::table('registration_attachments')->insert([
                'internship_registration_id' => $regId,
                'attachment_type'            => 'cv_ktp_portofolio',
                'category'                   => 'cv_ktp_portofolio',
                'file_path'                  => 'attachments/' . $regId,
                'original_name'              => 'Folder_Berkas_' . $userSlug,
                'file_size'                  => 0,
                'mime_type'                  => 'application/vnd.google-apps.folder',
                'drive_file_id'              => $folderDriveId,
                'drive_web_view_link'        => $folderDriveLink,
                'drive_sync_status'          => 'synced',
                'drive_synced_at'            => $createdAt,
                'created_at'                 => $createdAt,
                'updated_at'                 => $createdAt,
            ]);

            foreach ($attachmentDefs as $att) {
                $fakeDriveId = '1demo_' . $att['cat'] . '_' . $regId . '_' . Str::random(8);
                $fakeDriveLink = 'https://drive.google.com/file/d/' . $fakeDriveId . '/view?usp=sharing';
                DB::table('registration_attachments')->insert([
                    'internship_registration_id' => $regId,
                    'attachment_type'            => $att['type'],
                    'category'                   => $att['cat'],
                    'file_path'                  => 'attachments/' . $regId . '/' . $att['label'],
                    'original_name'              => $att['label'],
                    'file_size'                  => mt_rand(150000, 2500000),
                    'mime_type'                  => $att['mime'],
                    'drive_file_id'              => $fakeDriveId,
                    'drive_web_view_link'        => $fakeDriveLink,
                    'drive_sync_status'          => 'synced',
                    'drive_synced_at'            => $createdAt,
                    'created_at'                 => $createdAt,
                    'updated_at'                 => $createdAt,
                ]);
            }

            // 7. Jika status accepted, active, atau completed, catat aktivitas admin acceptance
            if (in_array($status, ['accepted', 'active', 'completed'], true)) {
                \App\Models\ActivityLog::create([
                    'actor_name_snapshot'  => 'Admin Seven Inc',
                    'actor_role_snapshot'  => 'admin',
                    'action'               => 'registration_status_updated',
                    'description'          => "Admin menerima pendaftaran magang {$fullname}",
                    'subject_type'         => 'App\Models\InternshipRegistration',
                    'subject_id'           => $regId,
                    'after_data'           => ['internship_status' => 'accepted'],
                    'metadata'             => [
                        'old_status'      => 'waiting',
                        'new_status'      => 'accepted',
                        'registration_id' => $regId,
                    ],
                    'created_at'           => $createdAt->copy()->addDays(2),
                ]);
            }
        }
    }
}
