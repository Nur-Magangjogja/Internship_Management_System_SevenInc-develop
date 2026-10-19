<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\InternshipRegistration as IR;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Seed akun pemagang dummy representatif dengan data lengkap seluruh 47 kolom.
 * Password semua akun: password123
 */
class DummyPemagangSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password123');

        $data = [
            ['Budi Santoso',      'pemagang1@demo.com', 'waiting',   'Yogyakarta', 'Laki-laki',  'Islam',             '081234567801'],
            ['Siti Rahayu',       'pemagang2@demo.com', 'waiting',   'Sleman',     'Perempuan',  'Islam',             '081234567802'],
            ['Reza Pratama',      'pemagang3@demo.com', 'accepted',  'Bantul',     'Laki-laki',  'Kristen Protestan', '081234567803'],
            ['Rina Safitri',      'pemagang4@demo.com', 'accepted',  'Jakarta',    'Perempuan',  'Islam',             '081234567804'],
            ['Ahmad Fauzi',       'pemagang5@demo.com', 'active',    'Bantul',     'Laki-laki',  'Islam',             '081234567805'],
            ['Fajar Ramadhan',    'pemagang6@demo.com', 'rejected',  'Semarang',   'Laki-laki',  'Islam',             '081234567806'],
            ['Bambang Subambang', 'pemagang7@demo.com', 'completed', 'Yogyakarta', 'Laki-laki',  'Katolik',           '081234567807'],
            ['Maya Indah',        'pemagang8@demo.com', 'exited',    'Surabaya',   'Perempuan',  'Hindu',             '081234567808'],
            ['Dimas Eko Prayogo', 'pemagang9@demo.com', 'pending',   'Kulonprogo', 'Laki-laki',  'Islam',             '081234567809'],
        ];

        $cityIds = DB::table('cities')->pluck('id')->toArray();
        $institutionIds = DB::table('institutions')->pluck('id')->toArray();
        $facultyIds = DB::table('faculties')->pluck('id')->toArray();
        $studyProgramIds = DB::table('study_programs')->pluck('id')->toArray();
        $divisionIds = DB::table('divisions')->pluck('id')->toArray();
        $brandIds = DB::table('brands')->pluck('id')->toArray();

        $defaultCityId  = !empty($cityIds) ? $cityIds[0] : 1;
        $defaultInstId  = !empty($institutionIds) ? $institutionIds[0] : 1;
        $defaultFacId   = !empty($facultyIds) ? $facultyIds[0] : 1;
        $defaultProdiId = !empty($studyProgramIds) ? $studyProgramIds[0] : 1;
        $defaultDivId   = !empty($divisionIds) ? $divisionIds[0] : 1;
        $defaultBrandId = !empty($brandIds) ? $brandIds[0] : null;

        foreach ($data as $i => [$name, $email, $status, $city, $gender, $religion, $phone]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name'     => $name,
                    'password' => $password,
                    'role'     => 'pemagang',
                ]
            );

            $createdAt = Carbon::create(2026, 2, 10 + $i, 9, 30, 0);

            $existingReg = DB::table('internship_registrations')->where('user_id', $user->id)->first();
            $regData = [
                'user_id'                  => $user->id,
                'fullname'                 => $name,
                'student_id'               => '2026' . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'gender'                   => $gender,
                'birth_place'              => $city,
                'born_date'                => '2002-0' . min(9, $i + 1) . '-15',
                'religion'                 => $religion,
                'phone_number'             => $phone,
                'city_id'                  => !empty($cityIds) ? $cityIds[$i % count($cityIds)] : $defaultCityId,
                'institution_id'           => !empty($institutionIds) ? $institutionIds[$i % count($institutionIds)] : $defaultInstId,
                'faculty_id'               => !empty($facultyIds) ? $facultyIds[$i % count($facultyIds)] : $defaultFacId,
                'study_program_id'         => !empty($studyProgramIds) ? $studyProgramIds[$i % count($studyProgramIds)] : $defaultProdiId,
                'internship_reason'        => 'Ingin mendapatkan pengalaman kerja industri profesional di Seven Inc.',
                'internship_type'          => 'Magang Mandiri',
                'internship_arrangement'   => 'Onsite',
                'current_status'           => 'Mahasiswa/Pelajar',
                'english_book_ability'     => 'Saya bisa',
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup dan bersedia mengikuti jadwal shifting Senin-Sabtu',
                'has_laptop'               => true,
                'planned_duration'         => '3 Bulan',
                'division_id'              => !empty($divisionIds) ? $divisionIds[$i % count($divisionIds)] : $defaultDivId,
                'brand_id'                 => $defaultBrandId,
                'internship_status'        => $status,
                'start_date'               => '2026-09-01',
                'end_date'                 => '2026-12-01',
                'supervisor_name'          => 'Dr. Hendra Gunawan, M.Kom',
                'supervisor_contact'       => '081298765432',
                'current_activities'       => 'Kuliah semester akhir, fokus magang penuh waktu',
                'social_media_instagram'   => '@' . Str::slug($name, ''),
                'boarding_info'            => 'Ya, butuh rekomendasi kost dekat kantor',
                'created_at'               => $createdAt,
                'updated_at'               => $createdAt,
            ];

            if ($existingReg) {
                DB::table('internship_registrations')->where('id', $existingReg->id)->update($regData);
                $regId = $existingReg->id;
            } else {
                $regId = DB::table('internship_registrations')->insertGetId($regData);
            }

            // Pivot Attachments
            if (!DB::table('registration_attachments')->where('internship_registration_id', $regId)->exists()) {
                $userSlug = Str::slug($name, '_');
                $attachments = [
                    ['cat' => 'cv', 'type' => 'cv', 'file' => 'CV_' . $userSlug . '.pdf'],
                    ['cat' => 'ktm_ktp', 'type' => 'ktm_ktp', 'file' => 'KTM_' . $userSlug . '.pdf'],
                    ['cat' => 'portofolio', 'type' => 'portofolio', 'file' => 'Portofolio_' . $userSlug . '.pdf'],
                    ['cat' => 'surat_pengantar', 'type' => 'surat_pengantar', 'file' => 'SuratPengantar_' . $userSlug . '.pdf'],
                    ['cat' => 'proposal', 'type' => 'proposal', 'file' => 'Proposal_' . $userSlug . '.pdf'],
                    ['cat' => 'dokumen_pendukung', 'type' => 'dokumen_pendukung', 'file' => 'DokumenPendukung_' . $userSlug . '.pdf'],
                    ['cat' => 'pasfoto', 'type' => 'pasfoto', 'file' => 'Pasfoto_' . $userSlug . '.jpg'],
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

                foreach ($attachments as $att) {
                    $fakeDriveId = '1demo_' . $att['cat'] . '_' . $regId . '_' . Str::random(8);
                    $fakeDriveLink = 'https://drive.google.com/file/d/' . $fakeDriveId . '/view?usp=sharing';
                    DB::table('registration_attachments')->insert([
                        'internship_registration_id' => $regId,
                        'attachment_type'            => $att['type'],
                        'category'                   => $att['cat'],
                        'file_path'                  => 'attachments/' . $regId . '/' . $att['file'],
                        'original_name'              => $att['file'],
                        'file_size'                  => 524288,
                        'mime_type'                  => str_ends_with($att['file'], '.jpg') ? 'image/jpeg' : 'application/pdf',
                        'drive_file_id'              => $fakeDriveId,
                        'drive_web_view_link'        => $fakeDriveLink,
                        'drive_sync_status'          => 'synced',
                        'drive_synced_at'            => $createdAt,
                        'created_at'                 => $createdAt,
                        'updated_at'                 => $createdAt,
                    ]);
                }
            }

            // Pivot Tools
            if (!DB::table('intern_tools')->where('internship_registration_id', $regId)->exists()) {
                DB::table('intern_tools')->insert([
                    ['internship_registration_id' => $regId, 'tool_name' => 'Laptop Core i5/i7'],
                    ['internship_registration_id' => $regId, 'tool_name' => 'Mouse & Charger'],
                ]);
            }

            // Pivot Skills
            if (!DB::table('intern_skills')->where('internship_registration_id', $regId)->exists()) {
                DB::table('intern_skills')->insert([
                    ['internship_registration_id' => $regId, 'skill_category' => 'design', 'skill_name' => 'Adobe Photoshop, Figma'],
                    ['internship_registration_id' => $regId, 'skill_category' => 'video', 'skill_name' => 'Adobe Premiere Pro'],
                    ['internship_registration_id' => $regId, 'skill_category' => 'programming', 'skill_name' => 'PHP, Laravel, MySQL'],
                    ['internship_registration_id' => $regId, 'skill_category' => 'digital_marketing', 'skill_name' => 'Social Media Marketing'],
                ]);
            }

            // Pivot Info Sources
            if (!DB::table('intern_info_sources')->where('internship_registration_id', $regId)->exists()) {
                DB::table('intern_info_sources')->insert([
                    ['internship_registration_id' => $regId, 'source_name' => 'instagram'],
                ]);
            }
        }
    }
}
