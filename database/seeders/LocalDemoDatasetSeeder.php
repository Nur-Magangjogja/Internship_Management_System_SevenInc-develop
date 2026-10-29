<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\City;
use App\Models\DailyReport;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternLoa;
use App\Models\InternshipRegistration as IR;
use App\Models\LeaveRequest;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\PendingTask;
use App\Models\RegistrationAttachment;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LocalDemoDatasetSeeder extends Seeder
{
    /**
     * Run the local demonstration data seeds.
     * Completely non-destructive and idempotent.
     */
    public function run(): void
    {
        // 1. Akun Pengguna Representatif
        $admin = User::firstOrCreate(
            ['email' => 'admin.demo@seveninc.test'],
            [
                'name'      => 'Demo Admin Seven Inc',
                'password'  => Hash::make('DemoPassword2026!'),
                'role'      => 'admin',
                'is_banned' => false,
            ]
        );

        $hrStaff = User::firstOrCreate(
            ['email' => 'hr.demo@seveninc.test'],
            [
                'name'      => 'Demo HR Staff Seven Inc',
                'password'  => Hash::make('DemoPassword2026!'),
                'role'      => 'hr_staff',
                'is_banned' => false,
            ]
        );

        $pemagangAktif = User::firstOrCreate(
            ['email' => 'pemagang.aktif@seveninc.test'],
            [
                'name'      => 'Ahmad Rizki (Pemagang Aktif)',
                'password'  => Hash::make('DemoPassword2026!'),
                'role'      => 'pemagang',
                'is_banned' => false,
            ]
        );

        $pemagangAlumni = User::firstOrCreate(
            ['email' => 'pemagang.alumni@seveninc.test'],
            [
                'name'      => 'Siti Aisyah (Alumni Magang)',
                'password'  => Hash::make('DemoPassword2026!'),
                'role'      => 'pemagang',
                'is_banned' => false,
            ]
        );

        $pemagangDraft = User::firstOrCreate(
            ['email' => 'pemagang.draft@seveninc.test'],
            [
                'name'      => 'Bambang Pratama (Draft Pendaftar)',
                'password'  => Hash::make('DemoPassword2026!'),
                'role'      => 'pemagang',
                'is_banned' => false,
            ]
        );

        // 2. Master Data (Kota, Divisi, Institusi)
        City::firstOrCreate(['name' => 'Yogyakarta']);
        City::firstOrCreate(['name' => 'Sleman']);
        City::firstOrCreate(['name' => 'Bantul']);

        $division = Division::firstOrCreate(
            ['name' => 'Web Developer'],
            ['slug' => 'web-developer', 'code' => 'WEB', 'is_active' => true]
        );

        $brand = Brand::firstOrCreate(
            ['code' => 'SI'],
            ['name' => 'Seven Inc', 'company_address' => 'Jl. Janti Gg. Arjuna No.59, Bantul', 'signatory_name' => 'Direktur Utama', 'signatory_position' => 'Direktur']
        );

        $ugm = Institution::firstOrCreate(
            ['normalized_name' => 'universitas gadjah mada demo'],
            [
                'name'      => 'Universitas Gadjah Mada Demo',
                'address'   => 'Bulaksumur, Sleman, D.I. Yogyakarta',
                'is_active' => true,
            ]
        );

        $uny = Institution::firstOrCreate(
            ['normalized_name' => 'universitas negeri yogyakarta demo'],
            [
                'name'      => 'Universitas Negeri Yogyakarta Demo',
                'address'   => 'Jl. Colombo No. 1, Sleman, D.I. Yogyakarta',
                'is_active' => true,
            ]
        );

        $smk = Institution::firstOrCreate(
            ['normalized_name' => 'smk negeri 2 yogyakarta demo'],
            [
                'name'      => 'SMK Negeri 2 Yogyakarta Demo',
                'address'   => 'Jl. AM Sangaji No. 47, Yogyakarta',
                'is_active' => true,
            ]
        );

        $facTeknik = Faculty::firstOrCreate(
            ['institution_id' => $ugm->id, 'name' => 'Fakultas Teknik'],
            ['institution_id' => $ugm->id, 'name' => 'Fakultas Teknik']
        );

        $prodiTI = StudyProgram::firstOrCreate(
            ['faculty_id' => $facTeknik->id, 'name' => 'Teknologi Informasi'],
            ['faculty_id' => $facTeknik->id, 'name' => 'Teknologi Informasi']
        );

        $facUNY = Faculty::firstOrCreate(
            ['institution_id' => $uny->id, 'name' => 'Fakultas Teknik UNY'],
            ['institution_id' => $uny->id, 'name' => 'Fakultas Teknik UNY']
        );

        $prodiUNY = StudyProgram::firstOrCreate(
            ['faculty_id' => $facUNY->id, 'name' => 'Pendidikan Teknik Informatika'],
            ['faculty_id' => $facUNY->id, 'name' => 'Pendidikan Teknik Informatika']
        );

        // Kontak Institusi
        $kontak1 = InstitutionContact::firstOrCreate(
            ['institution_id' => $ugm->id, 'phone' => '081234567890'],
            [
                'name'            => 'Dr. Budi Santoso, M.T.',
                'normalized_name' => 'dr budi santoso mt',
                'position_title'  => 'Ketua Program Studi',
                'email'           => 'budi.santoso@ugm.demo.ac.id',
                'is_verified'     => true,
                'is_active'       => true,
            ]
        );

        $kontak2 = InstitutionContact::firstOrCreate(
            ['institution_id' => $uny->id, 'phone' => '081298765432'],
            [
                'name'            => 'Dra. Endang Purwati, M.Pd.',
                'normalized_name' => 'dra endang purwati mpd',
                'position_title'  => 'Koordinator Magang Mahasiswa',
                'email'           => 'endang.p@uny.demo.ac.id',
                'is_verified'     => true,
                'is_active'       => true,
            ]
        );

        $kontak3 = InstitutionContact::firstOrCreate(
            ['institution_id' => $smk->id, 'phone' => '081345678901'],
            [
                'name'            => 'Ir. Haryanto, S.Pd.',
                'normalized_name' => 'ir haryanto spd',
                'position_title'  => 'Kepala Program Keahlian RPL',
                'email'           => 'haryanto@smk2.demo.sch.id',
                'is_verified'     => false, // Calon kontak belum diverifikasi
                'is_active'       => true,
            ]
        );

        // 3. Siklus Follow-Up Kemitraan (Beragam Status)
        // Siklus 1: Completed & Willing (siap MOU)
        $cycle1 = FollowUpCycle::firstOrCreate(
            ['institution_id' => $ugm->id, 'institution_contact_id' => $kontak1->id, 'cycle_year' => 2026],
            [
                'status'                => FollowUpCycle::STATUS_COMPLETED,
                'final_result'          => FollowUpCycle::RESULT_WILLING,
                'final_notes'           => 'Pihak prodi menyetujui program kemitraan magang tahun 2026.',
                'current_attempt_count' => 2,
                'started_at'            => now()->subDays(10),
                'completed_at'          => now()->subDays(2),
                'confirmed_by'          => $admin->id,
                'confirmed_at'          => now()->subDays(2),
                'last_activity_at'      => now()->subDays(2),
            ]
        );

        FollowUpAttempt::firstOrCreate(
            ['followup_cycle_id' => $cycle1->id, 'attempt_number' => 1],
            [
                'channel'        => 'whatsapp',
                'result'         => 'responded',
                'response_text'  => 'Siap dikirimi draft proposal kerjasama',
                'notes'          => 'Kontak via WA koordinator prodi',
                'performed_by'   => $hrStaff->id,
                'followed_up_at' => now()->subDays(8),
            ]
        );

        FollowUpAttempt::firstOrCreate(
            ['followup_cycle_id' => $cycle1->id, 'attempt_number' => 2],
            [
                'channel'        => 'call',
                'result'         => 'responded',
                'response_text'  => 'Menyetujui MoU Tri Dharma dan penempatan mahasiswa',
                'notes'          => 'Telepon konfirmasi kesepakatan',
                'performed_by'   => $hrStaff->id,
                'followed_up_at' => now()->subDays(2),
            ]
        );

        // Siklus 2: In Progress (Terkunci oleh HR)
        $cycle2 = FollowUpCycle::firstOrCreate(
            ['institution_id' => $uny->id, 'institution_contact_id' => $kontak2->id, 'cycle_year' => 2026],
            [
                'status'                => FollowUpCycle::STATUS_IN_PROGRESS,
                'current_attempt_count' => 1,
                'started_at'            => now()->subDays(3),
                'locked_by'             => $hrStaff->id,
                'locked_at'             => now()->subMinutes(5),
                'lock_expires_at'       => now()->addMinutes(10),
                'last_activity_at'      => now()->subMinutes(5),
            ]
        );

        FollowUpAttempt::firstOrCreate(
            ['followup_cycle_id' => $cycle2->id, 'attempt_number' => 1],
            [
                'channel'        => 'whatsapp',
                'result'         => 'no_response',
                'response_text'  => null,
                'notes'          => 'Pesan terkirim centang dua, menunggu konfirmasi balik',
                'performed_by'   => $hrStaff->id,
                'followed_up_at' => now()->subDays(2),
            ]
        );

        // Siklus 3: Open (Belum dimulai, kontak belum diverifikasi)
        $cycle3 = FollowUpCycle::firstOrCreate(
            ['institution_id' => $smk->id, 'institution_contact_id' => $kontak3->id, 'cycle_year' => 2026],
            [
                'status'                => FollowUpCycle::STATUS_OPEN,
                'current_attempt_count' => 0,
                'started_at'            => now()->subDay(),
                'last_activity_at'      => now()->subDay(),
            ]
        );

        // 4. Dokumen MOU Demo
        Mou::firstOrCreate(
            ['company_mou_number' => '001/MOU/SI-DEMO/X/2026'],
            [
                'institution_id'                => $ugm->id,
                'followup_cycle_id'             => $cycle1->id,
                'first_party_institution_name'  => $ugm->name,
                'first_party_signatory_name'    => 'Dr. Budi Santoso, M.T.',
                'first_party_signatory_title'   => 'Ketua Program Studi',
                'second_party_company_name'     => 'PT Seven Inc',
                'second_party_signatory_name'   => 'Direktur Utama Seven Inc',
                'second_party_signatory_title'  => 'Direktur',
                'agreement_date'                => now()->subDays(2)->toDateString(),
                'effective_start'               => now()->subDays(2)->toDateString(),
                'effective_end'                 => now()->addYears(2)->toDateString(),
                'status'                        => Mou::STATUS_ACTIVE,
                'generated_file_path'           => 'documents/mou/001-MOU-SI-DEMO.pdf',
                'signed_file_path'              => 'documents/mou/001-MOU-SI-DEMO-signed.pdf',
            ]
        );

        // 5. Pendaftaran Pemagang Representatif
        // Pemagang 1: Aktif Magang
        $regAktif = IR::firstOrCreate(
            ['user_id' => $pemagangAktif->id],
            [
                'institution_id'           => $ugm->id,
                'faculty_id'               => $facTeknik->id,
                'study_program_id'         => $prodiTI->id,
                'division_id'              => $division->id,
                'brand_id'                 => $brand->id,
                'fullname'                 => 'Ahmad Rizki',
                'phone_number'             => '081234560001',
                'gender'                   => 'Laki-laki',
                'current_status'           => 'Mahasiswa / Pelajar Aktif',
                'internship_type'          => 'Magang Kampus Merdeka',
                'internship_reason'        => 'Ingin mengasah kemampuan web development berskala riil di industri.',
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup memenuhi jam kerja penuh',
                'has_laptop'               => 1,
                'planned_duration'         => '6 Bulan',
                'internship_status'        => IR::STATUS_ACTIVE,
                'start_date'               => '2026-02-01',
                'end_date'                 => '2026-08-01',
            ]
        );

        // Daily report & tasks untuk pemagang aktif
        DailyReport::firstOrCreate(
            ['intern_id' => $regAktif->id, 'date' => now()->subDay()->toDateString()],
            [
                'activities'  => 'Pengembangan Modul Kemitraan: Mempelajari arsitektur database dan alur kerja approval',
                'challenges'  => 'Tidak ada kendala berarti',
            ]
        );

        PendingTask::firstOrCreate(
            ['intern_id' => $regAktif->id, 'title' => 'Slicing UI Multistep Form'],
            [
                'description' => 'Memastikan tampilan form pendaftaran responsif di mobile',
                'status'      => 'in_progress',
            ]
        );

        LeaveRequest::firstOrCreate(
            ['intern_id' => $regAktif->id, 'leave_date' => now()->addDays(7)->toDateString()],
            [
                'leave_type' => 'izin',
                'reason'     => 'Ujian Tengah Semester di Kampus',
                'status'     => 'pending',
            ]
        );

        // Pemagang 2: Alumni (Selesai Magang)
        $regAlumni = IR::firstOrCreate(
            ['user_id' => $pemagangAlumni->id],
            [
                'institution_id'           => $uny->id,
                'faculty_id'               => $facUNY->id,
                'study_program_id'         => $prodiUNY->id,
                'division_id'              => $division->id,
                'brand_id'                 => $brand->id,
                'fullname'                 => 'Siti Aisyah',
                'phone_number'             => '081234560002',
                'gender'                   => 'Perempuan',
                'current_status'           => 'Mahasiswa / Pelajar Aktif',
                'internship_type'          => 'Magang Reguler',
                'internship_reason'        => 'Mengembangkan portofolio profesional.',
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup',
                'has_laptop'               => 1,
                'planned_duration'         => '3 Bulan',
                'internship_status'        => IR::STATUS_COMPLETED,
                'start_date'               => '2025-06-01',
                'end_date'                 => '2025-09-01',
            ]
        );

        // Pemagang 3: Draft Pendaftaran (Belum submit)
        IR::firstOrCreate(
            ['user_id' => $pemagangDraft->id],
            [
                'institution_id'    => $ugm->id,
                'faculty_id'        => $facTeknik->id,
                'study_program_id'  => $prodiTI->id,
                'division_id'       => $division->id,
                'brand_id'          => $brand->id,
                'fullname'          => 'Bambang Pratama',
                'phone_number'      => '081234560003',
                'internship_status' => IR::STATUS_NEW,
            ]
        );
    }
}
