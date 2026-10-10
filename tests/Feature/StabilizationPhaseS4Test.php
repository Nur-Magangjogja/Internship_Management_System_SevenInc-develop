<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Institution;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\Brand;
use App\Models\Division;
use App\Models\City;
use App\Models\InternshipRegistration as IR;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StabilizationPhaseS4Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role = 'pemagang', array $attributes = []): User
    {
        $unique = uniqid('s4_test_', true);
        return User::create(array_merge([
            'name'      => 'usr_' . Str::random(12),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createRegistration(User $user, array $overrides = []): IR
    {
        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas S4 Test']);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = StudyProgram::first() ?? StudyProgram::create(['name' => 'Sistem Informasi', 'faculty_id' => $fac->id]);
        $div  = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s4-test']);

        return IR::create(array_merge([
            'user_id'                => $user->id,
            'fullname'               => 'Pemagang ' . $user->name,
            'born_date'              => '2001-01-01',
            'gender'                 => 'Laki-laki',
            'phone_number'           => '081234567890',
            'student_id'             => 'NIM_' . rand(1000, 9999),
            'city_id'                => $city->id,
            'institution_id'         => $inst->id,
            'faculty_id'             => $fac->id,
            'study_program_id'       => $prog->id,
            'internship_reason'      => 'Pengembangan kompetensi',
            'internship_type'        => 'Magang Mandiri',
            'internship_arrangement' => 'Onsite',
            'current_status'         => 'Mahasiswa/Pelajar',
            'english_book_ability'   => 'Saya bisa',
            'division_id'            => $div->id,
            'internship_status'      => IR::STATUS_WAITING,
        ], $overrides));
    }

    /**
     * 1. Dua institusi berbeda yang memiliki nama fakultas yang sama disimpan sebagai record terpisah yang ter-scope.
     */
    public function test_faculty_with_same_name_under_institution_a_and_b_are_stored_as_separate_scoped_records(): void
    {
        $instA = Institution::create(['name' => 'Institut Teknologi Bandung ' . Str::random(5)]);
        $instB = Institution::create(['name' => 'Institut Teknologi Sepuluh Nopember ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'programmer']);

        $facultyName = 'Fakultas Teknik Industri';

        $userA = $this->makeUser('user');
        $this->actingAs($userA)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Peserta A',
            'email'               => $userA->email,
            'phone_number'        => '081234567891',
            'student_id'          => '12345671',
            'gender'              => 'Laki-laki',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => $instA->name,
            'faculty'             => $facultyName,
            'study_program'       => 'Teknik Industri',
            'current_city'        => 'Bandung',
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        $userB = $this->makeUser('user');
        $this->actingAs($userB)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Peserta B',
            'email'               => $userB->email,
            'phone_number'        => '081234567892',
            'student_id'          => '12345672',
            'gender'              => 'Perempuan',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => $instB->name,
            'faculty'             => $facultyName,
            'study_program'       => 'Teknik Industri',
            'current_city'        => 'Surabaya',
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        $facultiesA = Faculty::where('institution_id', $instA->id)->where('name', $facultyName)->get();
        $facultiesB = Faculty::where('institution_id', $instB->id)->where('name', $facultyName)->get();

        $this->assertCount(1, $facultiesA);
        $this->assertCount(1, $facultiesB);
        $this->assertNotEquals($facultiesA->first()->id, $facultiesB->first()->id);
    }

    /**
     * 2. Pendaftaran di Institusi B tidak boleh menunjuk ke Fakultas milik Institusi A.
     */
    public function test_registration_with_institution_b_cannot_link_faculty_belonging_to_institution_a(): void
    {
        $instA = Institution::create(['name' => 'Universitas Indonesia ' . Str::random(5)]);
        $instB = Institution::create(['name' => 'Universitas Gadjah Mada ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'programmer']);

        $facultyName = 'Fakultas Ilmu Komputer';

        // Buat terlebih dahulu fakultas di Institusi A
        $facultyA = Faculty::create([
            'name'           => $facultyName,
            'institution_id' => $instA->id,
        ]);

        // Pendaftar mendaftar di Institusi B dengan nama fakultas yang sama
        $userB = $this->makeUser('user');
        $response = $this->actingAs($userB)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Peserta B',
            'email'               => $userB->email,
            'phone_number'        => '081234567893',
            'student_id'          => '12345673',
            'gender'              => 'Laki-laki',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => $instB->name,
            'faculty'             => $facultyName,
            'study_program'       => 'Sistem Informasi',
            'current_city'        => 'Yogyakarta',
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        $regB = IR::where('user_id', $userB->id)->first();
        $this->assertNotNull($regB);
        $this->assertEquals($instB->id, $regB->institution_id);

        // Pastikan fakultas yang tertaut BUKAN milik Institusi A
        $this->assertNotEquals($facultyA->id, $regB->faculty_id);

        $facultyB = Faculty::find($regB->faculty_id);
        $this->assertNotNull($facultyB);
        $this->assertEquals($instB->id, $facultyB->institution_id);
    }

    /**
     * 3. Program studi dengan nama yang sama di bawah fakultas berbeda tersimpan sebagai scoped record.
     */
    public function test_study_program_with_same_name_under_faculty_a_and_b_are_stored_as_separate_scoped_records(): void
    {
        $inst = Institution::create(['name' => 'Universitas Multi Fakultas ' . Str::random(5)]);
        $facA = Faculty::create(['name' => 'Fakultas Teknik ' . Str::random(5), 'institution_id' => $inst->id]);
        $facB = Faculty::create(['name' => 'Fakultas MIPA ' . Str::random(5), 'institution_id' => $inst->id]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'programmer']);

        $studyName = 'Informatika';

        $progA = StudyProgram::create([
            'name'       => $studyName,
            'faculty_id' => $facA->id,
        ]);

        $userB = $this->makeUser('user');
        $response = $this->actingAs($userB)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Peserta MIPA',
            'email'               => $userB->email,
            'phone_number'        => '081234567894',
            'student_id'          => '12345674',
            'gender'              => 'Perempuan',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => $inst->name,
            'faculty'             => $facB->name,
            'study_program'       => $studyName,
            'current_city'        => 'Yogyakarta',
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        $regB = IR::where('user_id', $userB->id)->first();
        $this->assertNotNull($regB);
        $this->assertEquals($facB->id, $regB->faculty_id);

        // Pastikan program studi yang tertaut BUKAN milik Fakultas A
        $this->assertNotEquals($progA->id, $regB->study_program_id);

        $progB = StudyProgram::find($regB->study_program_id);
        $this->assertNotNull($progB);
        $this->assertEquals($facB->id, $progB->faculty_id);
    }

    /**
     * 4. Pendaftaran atau update status tidak boleh membuat master Brand baru dari teks bebas fiktif.
     */
    public function test_registration_cannot_create_new_brand_from_arbitrary_string(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('pemagang');

        $reg = $this->createRegistration($user, [
            'fullname'          => 'Peserta Brand Test',
            'internship_status' => IR::STATUS_WAITING,
        ]);

        $initialBrandCount = Brand::count();
        $fictionalBrand = 'Brand_Fiktif_' . Str::random(8);

        // Admin mencoba mengubah status menjadi accepted dengan brand fiktif
        $response = $this->actingAs($admin)->patch(route('admin.interns.status.update', $reg->id), [
            'internship_status' => IR::STATUS_ACCEPTED,
            'brand'             => $fictionalBrand,
        ]);

        // Tidak boleh ada brand baru yang tercipta
        $this->assertEquals($initialBrandCount, Brand::count());
        $this->assertDatabaseMissing('brands', ['name' => $fictionalBrand]);
    }

    /**
     * 5. Variasi ejaan/singkatan institusi nonaktif tidak dapat melewati blokade pendaftaran.
     */
    public function test_inactive_institution_spelling_variations_cannot_bypass_registration_block(): void
    {
        $uniqueWord = 'Cakrawala' . Str::random(4);
        $canonicalName = 'Universitas ' . $uniqueWord;
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'programmer']);

        // Institusi dinonaktifkan oleh admin
        $inactiveInst = Institution::create([
            'name'            => $canonicalName,
            'is_active'       => false,
            'inactive_reason' => 'Sedang dalam peninjauan kemitraan',
        ]);

        $this->assertFalse((bool) $inactiveInst->is_active);

        // Pendaftar mencoba mendaftar dengan variasi singkatan "Univ."
        $user = $this->makeUser('user');
        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Calon Pendaftar',
            'email'               => $user->email,
            'phone_number'        => '081234567895',
            'student_id'          => '12345675',
            'gender'              => 'Laki-laki',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => 'Univ. ' . $uniqueWord,
            'faculty'             => 'Fakultas Teknik',
            'study_program'       => 'Informatika',
            'current_city'        => 'Yogyakarta',
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        // Pendaftaran harus ditolak dan menampilkan pesan error institusi nonaktif
        $response->assertSessionHasErrors('institution_name');
        $this->assertDatabaseMissing('internship_registrations', ['user_id' => $user->id]);
    }

    /**
     * 6. Model Institution secara otomatis menghitung kolom normalized_name saat disimpan.
     */
    public function test_institution_model_automatically_computes_normalized_name_on_save(): void
    {
        $inst = Institution::create([
            'name' => 'Univ. Islam Negeri Sunan Kalijaga (Yogyakarta)',
        ]);

        $this->assertNotNull($inst->normalized_name);
        $this->assertEquals('universitas islam negeri sunan kalijaga yogyakarta', $inst->normalized_name);

        // Saat diupdate
        $inst->name = 'Institut Seni Indonesia - Yogyakarta';
        $inst->save();

        $this->assertEquals('institut seni indonesia yogyakarta', $inst->fresh()->normalized_name);
    }

    /**
     * 7. Input divisi yang tidak valid menghasilkan validation error dan tidak diam-diam fallback ke Division::first().
     */
    public function test_invalid_division_produces_validation_error_and_does_not_fallback_to_first_division(): void
    {
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas Uji S4 ' . Str::random(5)]);
        $user = $this->makeUser('user');

        $invalidDivisionName = 'Divisi Fiktif Tidak Ada ' . Str::random(8);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Calon Pemagang Divisi Test',
            'email'               => $user->email,
            'phone_number'        => '081234567899',
            'student_id'          => '12345679',
            'gender'              => 'Laki-laki',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => $inst->name,
            'faculty'             => 'Fakultas Teknik',
            'study_program'       => 'Informatika',
            'current_city'        => 'Yogyakarta',
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $invalidDivisionName,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        $response->assertSessionHasErrors('internship_interest');
        $this->assertDatabaseMissing('internship_registrations', ['user_id' => $user->id]);
    }

    /**
     * 8. Data registrasi dengan relasi cross-parent (mismatch historis) tetap dapat dibaca tanpa error.
     */
    public function test_historical_cross_parent_mismatches_remain_readable(): void
    {
        $instA = Institution::create(['name' => 'Universitas Asal ' . Str::random(5)]);
        $instB = Institution::create(['name' => 'Universitas Lain ' . Str::random(5)]);

        $facB = Faculty::create(['name' => 'Fakultas Kampus B', 'institution_id' => $instB->id]);
        $facC = Faculty::create(['name' => 'Fakultas Kampus C', 'institution_id' => $instA->id]);
        $progC = StudyProgram::create(['name' => 'Prodi Kampus C', 'faculty_id' => $facC->id]);

        $user = $this->makeUser('pemagang');

        // Buat registrasi cross-parent sengaja (simulasi data historis mismatch)
        $reg = $this->createRegistration($user, [
            'institution_id'   => $instA->id,
            'faculty_id'       => $facB->id, // Mismatch: fakultas milik instB, padahal reg milik instA
            'study_program_id' => $progC->id, // Mismatch: prodi milik facC, padahal reg milik facB
        ]);

        $freshReg = IR::with(['institution', 'facultyRel', 'studyProgramRel'])->find($reg->id);
        $this->assertNotNull($freshReg);
        $this->assertEquals($instA->id, $freshReg->institution->id);
        $this->assertEquals($facB->id, $freshReg->facultyRel->id);
        $this->assertEquals($progC->id, $freshReg->studyProgramRel->id);
    }
}

