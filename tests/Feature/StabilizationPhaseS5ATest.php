<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Institution;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\Division;
use App\Models\City;
use App\Models\InternshipRegistration as IR;
use App\Models\RegistrationAttachment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StabilizationPhaseS5ATest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role = 'pemagang', array $attributes = []): User
    {
        $unique = uniqid('s5a_test_', true);
        return User::create(array_merge([
            'name'      => 'usr_' . Str::random(10),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createHierarchy(): array
    {
        $inst = Institution::create([
            'name'            => 'Universitas Gadjah Mada ' . Str::random(5),
            'normalized_name' => 'universitas gadjah mada ' . Str::random(5),
            'is_active'       => true,
        ]);

        $fac = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Teknik',
        ]);

        $prog = StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'Teknologi Informasi',
        ]);

        $div = Division::firstOrCreate(
            ['name' => 'Programmer (Front End / Backend)'],
            ['slug' => 'programmer_fe_be', 'is_active' => true]
        );

        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        return compact('inst', 'fac', 'prog', 'div', 'city');
    }

    /**
     * 1. 2026 final submission berhasil dengan field kontrak 2026 tanpa field legacy (born_date, faculty, dll).
     */
    public function test_2026_final_submission_succeeds_without_obsolete_fields(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        $payload = [
            'fullname'                 => 'Ahmad Dahlan',
            'gender'                   => 'Laki-laki',
            'current_city'             => $h['city']->name,
            'email'                    => $user->email,
            'phone_number'             => '081234567890',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'NIM-2026-001',
            'institution_name'         => $h['inst']->name,
            'study_program'            => $h['prog']->name,
            'internship_interest'      => $h['div']->name,
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Mencari pengalaman kerja di Seven Inc.',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ];

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('internship_registrations', [
            'user_id'                  => $user->id,
            'fullname'                 => 'Ahmad Dahlan',
            'student_id'               => 'NIM-2026-001',
            'institution_id'           => $h['inst']->id,
            'faculty_id'               => $h['fac']->id,
            'study_program_id'         => $h['prog']->id,
            'division_id'              => $h['div']->id,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
            'born_date'                => null,
            'english_book_ability'     => null,
            'internship_status'        => IR::STATUS_WAITING,
        ]);
    }

    /**
     * 2. Draft nullable behavior: kolom yang belum dijawab bernilai NULL, bukan fake defaults.
     */
    public function test_draft_allows_nullable_unanswered_fields_without_fake_defaults(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        // Draft hanya mengisi nama dan institusi, field lain kosong
        $payload = [
            'fullname'         => 'Draft Candidate',
            'institution_name' => $h['inst']->name,
            'study_program'    => $h['prog']->name,
        ];

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.draft'), $payload);

        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        $this->assertTrue((bool)$reg->is_draft);
        $this->assertEquals('Draft Candidate', $reg->fullname);

        // Pastikan tidak ada default palsu
        $this->assertNull($reg->has_laptop, 'has_laptop harus NULL pada draft yang belum dijawab');
        $this->assertNull($reg->born_date, 'born_date harus NULL');
        $this->assertNull($reg->wfo_readiness, 'wfo_readiness harus NULL');
        $this->assertNull($reg->schedule_shift_readiness, 'schedule_shift_readiness harus NULL');
        $this->assertNull($reg->planned_duration, 'planned_duration harus NULL');
    }

    /**
     * 3. Legacy history readable: baris pendaftaran lama dengan nilai NOT NULL tetap terbaca tanpa error.
     */
    public function test_legacy_history_remains_readable(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('pemagang');

        $legacyReg = IR::create([
            'user_id'                => $user->id,
            'fullname'               => 'Historical Intern',
            'born_date'              => '1999-12-31',
            'gender'                 => 'Perempuan',
            'phone_number'           => '081299999999',
            'city_id'                => $h['city']->id,
            'student_id'             => 'NIM-OLD-999',
            'institution_id'         => $h['inst']->id,
            'faculty_id'             => $h['fac']->id,
            'study_program_id'       => $h['prog']->id,
            'division_id'            => $h['div']->id,
            'internship_reason'      => 'Historical reason',
            'internship_type'        => 'PKL',
            'internship_arrangement' => 'Onsite',
            'current_status'         => 'Lulusan Baru',
            'english_book_ability'   => 'Saya bisa',
            'internship_status'      => IR::STATUS_COMPLETED,
        ]);

        $found = IR::find($legacyReg->id);
        $this->assertNotNull($found);
        $this->assertEquals('1999-12-31', $found->born_date);
        $this->assertEquals('PKL', $found->internship_type);
        $this->assertEquals('Saya bisa', $found->english_book_ability);
        $this->assertEquals('Fakultas Teknik', $found->faculty);
    }

    /**
     * 4. faculty_id diturunkan secara otomatis dari selected study_program dan hirarki S4 valid.
     */
    public function test_faculty_is_derived_from_study_program_and_preserves_s4_hierarchy(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        $payload = [
            'fullname'                 => 'Siswa Mandiri',
            'gender'                   => 'Perempuan',
            'current_city'             => $h['city']->name,
            'email'                    => $user->email,
            'phone_number'             => '081234567811',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'NIM-S4-DERIVE',
            'institution_name'         => $h['inst']->name,
            'study_program'            => $h['prog']->name,
            // Tidak ada input 'faculty' sama sekali
            'internship_interest'      => $h['div']->name,
            'internship_type'          => 'Reguler-Kampus',
            'internship_reason'        => 'Alasan magang kampus',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 0,
            'planned_duration'         => '6 Bulan',
        ];

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        // faculty_id harus otomatis terisi ID Fakultas Teknik milik institusi terkait
        $this->assertEquals($h['fac']->id, $reg->faculty_id);
        $this->assertEquals($h['prog']->id, $reg->study_program_id);
        $this->assertEquals($h['inst']->id, $reg->institution_id);
    }

    /**
     * 5. Institusi nonaktif ditolak untuk pendaftaran baru.
     */
    public function test_inactive_institution_is_rejected_for_new_registration(): void
    {
        $h = $this->createHierarchy();
        $h['inst']->is_active = false;
        $h['inst']->save();

        $user = $this->makeUser('user');

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), [
                'fullname'                 => 'Candidate Inactive',
                'gender'                   => 'Laki-laki',
                'current_city'             => $h['city']->name,
                'email'                    => $user->email,
                'phone_number'             => '081234567812',
                'current_status'           => 'Mahasiswa / Pelajar Aktif',
                'student_id'               => 'NIM-INACTIVE',
                'institution_name'         => $h['inst']->name,
                'study_program'            => $h['prog']->name,
                'internship_interest'      => $h['div']->name,
                'internship_type'          => 'Magang Mandiri',
                'internship_reason'        => 'Alasan',
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup',
                'has_laptop'               => 1,
                'planned_duration'         => '3 Bulan',
            ]);

        $response->assertSessionHasErrors('institution_name');
    }

    /**
     * 6. Divisi tidak valid ditolak tanpa fallback Division::first().
     */
    public function test_invalid_division_is_rejected_without_silent_fallback(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), [
                'fullname'                 => 'Candidate Divisi',
                'gender'                   => 'Laki-laki',
                'current_city'             => $h['city']->name,
                'email'                    => $user->email,
                'phone_number'             => '081234567813',
                'current_status'           => 'Mahasiswa / Pelajar Aktif',
                'student_id'               => 'NIM-DIV-FAIL',
                'institution_name'         => $h['inst']->name,
                'study_program'            => $h['prog']->name,
                'internship_interest'      => 'Divisi Nonexistent 9999',
                'internship_type'          => 'Magang Mandiri',
                'internship_reason'        => 'Alasan',
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup',
                'has_laptop'               => 1,
                'planned_duration'         => '3 Bulan',
            ]);

        $response->assertSessionHasErrors('internship_interest');
    }

    /**
     * 7. Berkas PDF valid max 10MB tersimpan dan dicatat pada tabel registration_attachments.
     */
    public function test_valid_pdf_attachment_persisted_to_registration_attachments(): void
    {
        Storage::fake('public');

        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        $pdfFile = UploadedFile::fake()->create('cv_ktp_portofolio_lengkap.pdf', 2048, 'application/pdf');

        $payload = [
            'fullname'                 => 'Pelamar Berkas',
            'gender'                   => 'Laki-laki',
            'current_city'             => $h['city']->name,
            'email'                    => $user->email,
            'phone_number'             => '081234567814',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'NIM-FILE-01',
            'institution_name'         => $h['inst']->name,
            'study_program'            => $h['prog']->name,
            'internship_interest'      => $h['div']->name,
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Alasan',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
            'cv_ktp_portofolio_pdf'    => $pdfFile,
        ];

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);

        // Attachment harus tercatat pada registration_attachments
        $this->assertDatabaseHas('registration_attachments', [
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'original_name'              => 'cv_ktp_portofolio_lengkap.pdf',
            'mime_type'                  => 'application/pdf',
        ]);

        $attachment = $reg->attachments()->where('attachment_type', 'cv_ktp_portofolio')->first();
        $this->assertNotNull($attachment);
        Storage::disk('public')->assertExists($attachment->file_path);
    }

    /**
     * 8. Lampiran non-PDF ditolak server-side.
     */
    public function test_non_pdf_attachment_is_rejected(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        $docFile = UploadedFile::fake()->create('dokumen.docx', 500, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), [
                'fullname'                 => 'Candidate Non PDF',
                'gender'                   => 'Laki-laki',
                'current_city'             => $h['city']->name,
                'email'                    => $user->email,
                'phone_number'             => '081234567815',
                'current_status'           => 'Mahasiswa / Pelajar Aktif',
                'student_id'               => 'NIM-NON-PDF',
                'institution_name'         => $h['inst']->name,
                'study_program'            => $h['prog']->name,
                'internship_interest'      => $h['div']->name,
                'internship_type'          => 'Magang Mandiri',
                'internship_reason'        => 'Alasan',
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup',
                'has_laptop'               => 1,
                'planned_duration'         => '3 Bulan',
                'cv_ktp_portofolio_pdf'    => $docFile,
            ]);

        $response->assertSessionHasErrors('cv_ktp_portofolio_pdf');
    }

    /**
     * 9. Lampiran melebihi 10MB ditolak server-side.
     */
    public function test_oversized_pdf_greater_than_10mb_is_rejected(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        // 11MB file (11264 KB > 10240 KB limit)
        $oversizedFile = UploadedFile::fake()->create('big_portfolio.pdf', 11264, 'application/pdf');

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), [
                'fullname'                 => 'Candidate Oversized',
                'gender'                   => 'Laki-laki',
                'current_city'             => $h['city']->name,
                'email'                    => $user->email,
                'phone_number'             => '081234567816',
                'current_status'           => 'Mahasiswa / Pelajar Aktif',
                'student_id'               => 'NIM-OVERSIZED',
                'institution_name'         => $h['inst']->name,
                'study_program'            => $h['prog']->name,
                'internship_interest'      => $h['div']->name,
                'internship_type'          => 'Magang Mandiri',
                'internship_reason'        => 'Alasan',
                'wfo_readiness'            => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup',
                'has_laptop'               => 1,
                'planned_duration'         => '3 Bulan',
                'cv_ktp_portofolio_pdf'    => $oversizedFile,
            ]);

        $response->assertSessionHasErrors('cv_ktp_portofolio_pdf');
    }

    /**
     * 10. Preserved DB-only fields tetap tersimpan (current_activities, skills, profile_photo).
     */
    public function test_preserved_db_only_fields_remain_intact(): void
    {
        Storage::fake('public');

        $h = $this->createHierarchy();
        $user = $this->makeUser('user');

        $photo = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $payload = [
            'fullname'                 => 'Preserved Candidate',
            'gender'                   => 'Perempuan',
            'current_city'             => $h['city']->name,
            'email'                    => $user->email,
            'phone_number'             => '081234567817',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'NIM-PRESERVED',
            'institution_name'         => $h['inst']->name,
            'study_program'            => $h['prog']->name,
            'internship_interest'      => $h['div']->name,
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Alasan',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
            // Preserved AS-IS fields
            'current_activities'       => 'Kuliah online malam',
            'design_software'          => 'Figma, Illustrator',
            'video_software'           => 'Premiere Pro',
            'profile_photo'            => $photo,
        ];

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('Kuliah online malam', $reg->current_activities);
        $this->assertNotNull($reg->profile_photo);

        // Relasi skills tersimpan
        $this->assertDatabaseHas('intern_skills', [
            'internship_registration_id' => $reg->id,
            'skill_category'             => 'design',
            'skill_name'                 => 'Figma, Illustrator',
        ]);
    }

    /**
     * 11. Halaman formulir pendaftaran render 200 OK dan bebas dari emoji / unicode warning.
     */
    public function test_form_renders_without_prohibited_emojis(): void
    {
        $user = $this->makeUser('user');

        $response = $this->actingAs($user)
            ->get(route('pemagang.registration.form'));

        $response->assertStatus(200);

        $content = $response->getContent();

        // Cek simbol warning / emoji teks yang dilarang oleh Rule P
        $this->assertStringNotContainsString('⚠', $content);
        $this->assertStringNotContainsString('✕', $content);
    }
}
