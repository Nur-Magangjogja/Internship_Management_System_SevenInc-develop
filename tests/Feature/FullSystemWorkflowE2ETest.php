<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\StudyProgram;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAttendance;
use App\Models\WebinarCertificate;
use App\Models\InternLoa;
use App\Models\SklDocument;
use App\Models\InternCertificate;
use App\Models\InternExtra;
use App\Models\AlumniMembercard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FullSystemWorkflowE2ETest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_complete_end_to_end_system_workflow(): void
    {
        // -------------------------------------------------------------
        // TAHAP 1: Admin Mengatur Brand & Divisi
        // -------------------------------------------------------------
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'admin',
                'password' => Hash::make('password'),
                'role'     => 'admin',
            ]
        );

        $brandCode = 'SI_' . strtoupper(substr(uniqid(), -3));
        $brand = Brand::create([
            'code'            => $brandCode,
            'name'            => 'Seven Inc',
            'company_address' => 'Jl. Imogiri Timur No. 7, Bantul, DIY',
            'signatory_name'  => 'Ari Setia Husbana',
            'signatory_position' => 'Direktur Utama',
        ]);

        $division = Division::firstOrCreate(
            ['name' => 'Web Development'],
            ['code' => 'WD', 'is_active' => true]
        );

        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas Indonesia', 'type' => 'Universitas']);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $sp   = StudyProgram::first() ?? StudyProgram::create(['name' => 'Informatika', 'faculty_id' => $fac->id]);

        // Verifikasi admin dapat mengakses menu Brand & Divisi
        $resBrand = $this->actingAs($admin)->get(route('admin.brands.index'));
        $resBrand->assertStatus(200);

        $resDiv = $this->actingAs($admin)->get(route('admin.form-settings.divisions'));
        $resDiv->assertStatus(200);

        // -------------------------------------------------------------
        // TAHAP 2: Buat Akun Pemagang Baru & Daftar Melalui Form Magang
        // -------------------------------------------------------------
        $pemagangUsername = 'pemagang_e2e_' . uniqid();
        $pemagangEmail    = $pemagangUsername . '@example.com';
        $pemagangPassword = 'password123';

        // Registrasi Akun
        $resReg = $this->post(route('user.register.submit'), [
            'username'              => $pemagangUsername,
            'email'                 => $pemagangEmail,
            'password'              => $pemagangPassword,
            'password_confirmation' => $pemagangPassword,
        ]);
        $resReg->assertRedirect();

        $pemagang = User::where('name', $pemagangUsername)->first();
        $this->assertNotNull($pemagang);

        // Pemagang buka form pendaftaran magang
        $resForm = $this->actingAs($pemagang)->get(route('pemagang.registration.form'));
        $resForm->assertStatus(200);

        // Submit formulir magang
        $resSubmit = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), [
            'fullname'               => 'Raden Budi Santoso',
            'email'                  => $pemagangEmail,
            'born_date'              => '2001-04-12',
            'gender'                 => 'Laki-laki',
            'phone_number'           => '081234567890',
            'current_city'           => 'Yogyakarta',
            'student_id'             => 'NIM2026E2E',
            'institution_name'       => 'Universitas Gadjah Mada',
            'faculty'                => 'Fakultas Teknik',
            'study_program'          => 'Teknologi Informasi',
            'internship_interest'    => $division->name,
            'internship_type'        => 'PKL',
            'internship_arrangement' => 'Onsite',
            'current_status'         => 'Mahasiswa/Pelajar',
            'english_book_ability'   => 'Saya bisa',
            'internship_reason'      => 'Ingin menimba ilmu profesional di Seven Inc',
            'start_date'             => '2026-01-01',
            'end_date'               => '2026-04-01',
        ]);
        $resSubmit->assertRedirect();

        $intern = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($intern);
        $this->assertEquals(IR::STATUS_WAITING, $intern->internship_status);

        // -------------------------------------------------------------
        // TAHAP 3: Admin Mengubah Status Pemagang Menjadi Diterima (Accepted)
        // -------------------------------------------------------------
        $resPendaftar = $this->actingAs($admin)->get(route('admin.interns.pendaftar'));
        $resPendaftar->assertStatus(200);

        // Data pendaftar dimuat oleh API table
        $resApi = $this->actingAs($admin)->getJson(route('admin.interns.api', ['status' => 'waiting']));
        $resApi->assertStatus(200);
        $resApi->assertJsonFragment(['fullname' => 'Raden Budi Santoso']);

        // Admin set status ke accepted
        $resAccept = $this->actingAs($admin)->patchJson(route('admin.interns.status.update', $intern->id), [
            'internship_status' => IR::STATUS_ACCEPTED,
            'brand'             => $brand->name,
        ]);
        $resAccept->assertStatus(200);

        $intern->refresh();
        $this->assertEquals(IR::STATUS_ACCEPTED, $intern->internship_status);

        // Verifikasi LOA otomatis terbuat
        $loa = InternLoa::where('intern_id', $intern->id)->first();
        $this->assertNotNull($loa);
        $this->assertStringContainsString('/LOA/', $loa->loa_number);

        // -------------------------------------------------------------
        // TAHAP 4: Pemagang Mengecek Ketersediaan Dokumen LOA
        // -------------------------------------------------------------
        $resDocsAccepted = $this->actingAs($pemagang)->get(route('pemagang.documents'));
        $resDocsAccepted->assertStatus(200);
        $resDocsAccepted->assertSee('LOA (Letter of Acceptance)');

        // -------------------------------------------------------------
        // TAHAP 5: Admin Mengubah Status ke Aktif & Mengisi Form Webinar
        // -------------------------------------------------------------
        $resActive = $this->actingAs($admin)->patchJson(route('admin.interns.status.update', $intern->id), [
            'internship_status' => IR::STATUS_ACTIVE,
        ]);
        $resActive->assertStatus(200);

        $intern->refresh();
        $this->assertEquals(IR::STATUS_ACTIVE, $intern->internship_status);

        // Verifikasi membercard terinisialisasi
        $membercard = AlumniMembercard::where('intern_id', $intern->id)->first();
        $this->assertNotNull($membercard);

        // Admin buat Webinar baru
        $resCreateWebinar = $this->actingAs($admin)->post(route('admin.webinars.store'), [
            'title'                      => 'Webinar E2E Modern Software Engineering',
            'event_date'                 => now()->addDays(2)->format('Y-m-d H:i:s'),
            'brand_id'                   => $brand->id,
            'description'                => 'Pelatihan implementasi arsitektur sistem enterprise.',
            'zoom_link'                  => 'https://zoom.us/j/1234567890',
        ]);
        $resCreateWebinar->assertRedirect(route('admin.webinars.index'));

        $webinar = Webinar::latest('id')->first();
        $this->assertNotNull($webinar);
        $this->assertEquals('Webinar E2E Modern Software Engineering', $webinar->title);

        // -------------------------------------------------------------
        // TAHAP 6: Pemagang Mengikuti Webinar (Upload Bukti Kehadiran)
        // -------------------------------------------------------------
        $resWebinarList = $this->actingAs($pemagang)->get(route('pemagang.webinar.index'));
        $resWebinarList->assertStatus(200);

        $proofFile = UploadedFile::fake()->image('proof.jpg');
        $resUploadProof = $this->actingAs($pemagang)->post(route('pemagang.webinar.upload_proof', $webinar->id), [
            'proof_file' => $proofFile,
            'proof_note' => 'Hadir penuh mengikuti materi webinar.',
        ]);
        $resUploadProof->assertRedirect();

        $attendance = WebinarAttendance::where('webinar_id', $webinar->id)
            ->where('user_id', $pemagang->id)
            ->first();
        $this->assertNotNull($attendance);
        $this->assertEquals(WebinarAttendance::STATUS_PENDING, $attendance->status);

        // -------------------------------------------------------------
        // TAHAP 7: Admin Approve Kehadiran & Generate Sertifikat Webinar
        // -------------------------------------------------------------
        $resApprove = $this->actingAs($admin)->post(route('admin.webinars.attendances.approve', [$webinar->id, $attendance->id]));
        $resApprove->assertRedirect();

        $attendance->refresh();
        $this->assertEquals(WebinarAttendance::STATUS_APPROVED, $attendance->status);

        // Admin trigger generate sertifikat webinar
        $resGenCerts = $this->actingAs($admin)->post(route('admin.webinars.generate_certs', $webinar->id));
        $resGenCerts->assertRedirect();

        $webinarCert = WebinarCertificate::where('attendance_id', $attendance->id)->first();
        $this->assertNotNull($webinarCert);

        // -------------------------------------------------------------
        // TAHAP 8: Admin Mengubah Status Pemagang ke Selesai (Completed)
        // -------------------------------------------------------------
        $resCompleted = $this->actingAs($admin)->patchJson(route('admin.interns.status.update', $intern->id), [
            'internship_status' => IR::STATUS_COMPLETED,
        ]);
        $resCompleted->assertStatus(200);

        $intern->refresh();
        $this->assertEquals(IR::STATUS_COMPLETED, $intern->internship_status);

        // Verifikasi dokumen SKL & Sertifikat otomatis terbentuk
        $skl = SklDocument::where('intern_id', $intern->id)->first();
        $this->assertNotNull($skl);
        $this->assertStringContainsString('/SKL/', $skl->skl_number);

        $cert = InternCertificate::where('intern_id', $intern->id)->first();
        $this->assertNotNull($cert);
        $this->assertStringContainsString('/SERT/', $cert->certificate_number);

        // -------------------------------------------------------------
        // TAHAP 9: Admin Mengelola Surat Rekomendasi dan Membercard
        // -------------------------------------------------------------
        // Preview Surat Rekomendasi di form kelola alumni
        $resRekPreview = $this->actingAs($admin)->get(route('admin.rekomendasi.preview', [
            'intern_id' => $intern->id,
            'brand_id'  => $brand->id,
        ]));
        $resRekPreview->assertStatus(200);
        // Verifikasi placeholder {divisi} menggunakan nama divisi ("Web Development")
        $resRekPreview->assertSee('Web Development');

        // Admin kirim / generate surat rekomendasi
        $resSendAll = $this->actingAs($admin)->post(route('admin.intern_extras.send_all', $intern->id), [
            'brand_id'             => $brand->id,
            'body_template'        => 'Menyatakan bahwa {nama} dari {instansi} telah magang di divisi {divisi}.',
            'alumni_group_url'     => 'https://chat.whatsapp.com/sample',
            'alumni_group_label'   => 'Grup Alumni Seven Inc',
            'job_info_url'         => 'https://jobs.seveninc.com',
            'job_info_description' => 'Lowongan Full Stack Developer',
        ]);
        $resSendAll->assertJson(['success' => true]);

        $extra = InternExtra::where('intern_id', $intern->id)->first();
        $this->assertNotNull($extra);
        $this->assertNotNull($extra->rekomendasi_path);

        // Admin buka detail membercard
        $resAdminMc = $this->actingAs($admin)->get(route('admin.membercards.show', $membercard->member_code));
        $resAdminMc->assertStatus(200);
        $resAdminMc->assertSee('Preview Membercard');
        $resAdminMc->assertSee('Raden Budi Santoso');
        $resAdminMc->assertSee($membercard->member_code);

        // -------------------------------------------------------------
        // TAHAP 10: Pemagang Cek Seluruh Dokumen & Membercard Digital
        // -------------------------------------------------------------
        $resDocsFinal = $this->actingAs($pemagang)->get(route('pemagang.documents'));
        $resDocsFinal->assertStatus(200);
        $resDocsFinal->assertSee('LOA (Letter of Acceptance)');
        $resDocsFinal->assertSee('SKL (Surat Keterangan Lulus)');
        $resDocsFinal->assertSee('Sertifikat Magang');
        $resDocsFinal->assertSee('Membercard Digital');
        $resDocsFinal->assertSee('Surat Rekomendasi');

        // Pemagang buka Membercard Digital dengan desain baru
        $resMcFinal = $this->actingAs($pemagang)->get(route('pemagang.membercard'));
        $resMcFinal->assertStatus(200);
        $resMcFinal->assertSee('Raden Budi Santoso');
        $resMcFinal->assertSee($membercard->member_code);
        $resMcFinal->assertSee('Seven Inc');
        $resMcFinal->assertSee('ALUMNI MEMBER');
        $resMcFinal->assertSee('VERIFIED');
    }
}
