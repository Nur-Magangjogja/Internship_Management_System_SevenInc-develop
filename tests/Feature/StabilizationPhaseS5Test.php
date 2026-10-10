<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Institution;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\Division;
use App\Models\City;
use App\Models\SklDocument;
use App\Models\InternshipRegistration as IR;
use App\Services\RegistrationLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StabilizationPhaseS5Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role = 'pemagang', array $attributes = []): User
    {
        $unique = uniqid('s5_test_', true);
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
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas S5 Test']);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = StudyProgram::first() ?? StudyProgram::create(['name' => 'Sistem Informasi', 'faculty_id' => $fac->id]);
        $div  = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s5-test']);

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
            'internship_status'      => IR::STATUS_COMPLETED,
        ], $overrides));
    }

    /**
     * 1. Submitting pendaftaran baru tidak menimpa histori magang yang sudah completed.
     */
    public function test_submitting_new_registration_does_not_overwrite_completed_history(): void
    {
        $user = $this->makeUser('pemagang');
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'programmer']);

        // Periode 1: Pemagang telah menyelesaikan magang (completed)
        $pastReg = $this->createRegistration($user, [
            'fullname'          => 'Andi Periode Lama',
            'internship_status' => IR::STATUS_COMPLETED,
        ]);

        $this->assertEquals(1, $user->internshipRegistrations()->count());

        // Periode 2: Andi mendaftar magang lagi untuk periode baru
        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Andi Periode Baru',
            'email'               => $user->email,
            'phone_number'        => '081234567899',
            'student_id'          => 'NIM_NEW_2027',
            'gender'              => 'Laki-laki',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => 'Universitas Gadjah Mada',
            'faculty'             => 'Fakultas Teknik',
            'study_program'       => 'Teknologi Informasi',
            'current_city'        => 'Yogyakarta',
            'internship_reason'   => 'Magang lanjutan periode kedua',
            'internship_interest' => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        $response->assertSessionDoesntHaveErrors();

        // Verifikasi: Sekarang ada 2 baris pendaftaran berbeda
        $registrations = $user->internshipRegistrations()->orderBy('id')->get();
        $this->assertCount(2, $registrations);

        // Rekord pertama (histori lama) tetap utuh
        $this->assertEquals($pastReg->id, $registrations[0]->id);
        $this->assertEquals('Andi Periode Lama', $registrations[0]->fullname);
        $this->assertEquals(IR::STATUS_COMPLETED, $registrations[0]->internship_status);

        // Rekord kedua (periode baru) tercipta mandiri dengan status waiting
        $this->assertNotEquals($pastReg->id, $registrations[1]->id);
        $this->assertEquals('Andi Periode Baru', $registrations[1]->fullname);
        $this->assertEquals('NIM_NEW_2027', $registrations[1]->student_id);
        $this->assertEquals(IR::STATUS_WAITING, $registrations[1]->internship_status);
    }

    /**
     * 2. Relasi HasMany internshipRegistrations dan helper hasCompletedInternship bekerja dengan benar.
     */
    public function test_user_has_many_internship_registrations_relation(): void
    {
        $user = $this->makeUser('pemagang');
        $this->assertFalse($user->hasCompletedInternship());

        $reg1 = $this->createRegistration($user, ['internship_status' => IR::STATUS_COMPLETED]);
        $reg2 = $this->createRegistration($user, ['internship_status' => IR::STATUS_WAITING]);

        $this->assertCount(2, $user->fresh()->internshipRegistrations);
        $this->assertTrue($user->fresh()->hasCompletedInternship());
    }

    /**
     * 3. Role 'pemagang' alumni tidak dicabut saat pendaftaran periode barunya ditolak (rejected) atau keluar (exited).
     */
    public function test_alumnus_retains_pemagang_role_even_if_subsequent_registration_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $user  = $this->makeUser('pemagang');

        // Pendaftaran 1: Completed
        $this->createRegistration($user, ['internship_status' => IR::STATUS_COMPLETED]);

        // Pendaftaran 2: Waiting
        $reg2 = $this->createRegistration($user, ['internship_status' => IR::STATUS_WAITING]);

        // Admin mengubah status pendaftaran kedua menjadi rejected
        $response = $this->actingAs($admin)->patch(route('admin.interns.status.update', $reg2->id), [
            'internship_status' => IR::STATUS_REJECTED,
        ]);

        $response->assertSessionDoesntHaveErrors();

        // Role user HARUS TETAP 'pemagang' karena memiliki riwayat alumni completed
        $this->assertEquals('pemagang', $user->fresh()->role);
    }

    /**
     * 4. Alumni tetap dapat mengunduh dokumen SKL dari periode completed meskipun memiliki pendaftaran periode baru yang waiting.
     */
    public function test_alumnus_can_still_access_and_download_documents_from_past_completed_period(): void
    {
        $user = $this->makeUser('pemagang');

        // Periode 1: Completed dengan SKL
        $completedReg = $this->createRegistration($user, [
            'fullname'          => 'Alumni Berprestasi',
            'internship_status' => IR::STATUS_COMPLETED,
            'start_date'        => '2024-01-01',
            'end_date'          => '2024-06-30',
        ]);

        SklDocument::create([
            'intern_id'          => $completedReg->id,
            'skl_number'         => '001/SKL/VI/2024',
            'company_name'       => 'Seven Inc',
            'signatory_name'     => 'Ari Setia Husbana',
            'signatory_position' => 'Direktur',
        ]);

        // Periode 2: Baru mendaftar lagi, status waiting
        $newReg = $this->createRegistration($user, [
            'fullname'          => 'Alumni Daftar Lagi',
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // Alumni membuka halaman dokumen saya
        $docPage = $this->actingAs($user)->get(route('pemagang.documents'));
        $docPage->assertStatus(200);

        // Alumni mengunduh SKL
        $response = $this->actingAs($user)->get(route('user.skl.download'));

        // Tidak boleh 403, harus berhasil mengunduh SKL dari periode completed
        $response->assertStatus(200);
        $this->assertTrue(str_contains($response->headers->get('content-type', ''), 'application/pdf')
            || str_contains($response->headers->get('content-disposition', ''), 'SKL'));
    }

    /**
     * 5. RegistrationLifecycleService memberlakukan transisi status yang valid.
     */
    public function test_registration_lifecycle_service_enforces_valid_state_transitions(): void
    {
        $service = app(RegistrationLifecycleService::class);
        $user = $this->makeUser('pemagang');

        $reg = $this->createRegistration($user, ['internship_status' => IR::STATUS_WAITING]);

        // waiting -> accepted (valid)
        $service->transition($reg, IR::STATUS_ACCEPTED);
        $this->assertEquals(IR::STATUS_ACCEPTED, $reg->fresh()->internship_status);

        // accepted -> active (valid)
        $service->transition($reg, IR::STATUS_ACTIVE);
        $this->assertEquals(IR::STATUS_ACTIVE, $reg->fresh()->internship_status);

        // active -> completed (valid)
        $service->transition($reg, IR::STATUS_COMPLETED);
        $this->assertEquals(IR::STATUS_COMPLETED, $reg->fresh()->internship_status);
    }

    /**
     * 6. RegistrationLifecycleService menolak transisi status ilegal (loncatan status sembarang).
     */
    public function test_registration_lifecycle_service_blocks_invalid_state_transitions(): void
    {
        $service = app(RegistrationLifecycleService::class);
        $user = $this->makeUser('pemagang');

        $reg = $this->createRegistration($user, ['internship_status' => IR::STATUS_WAITING]);

        // Transisi waiting langsung ke completed (non-admin) harus ditolak
        $this->expectException(InvalidArgumentException::class);
        $service->transition($reg, IR::STATUS_COMPLETED, isAdmin: false);
    }

    /**
     * 7. Alumni dengan pendaftaran periode baru (waiting/rejected) tetap dapat mengakses Sertifikat dan Membercard dari periode completed.
     */
    public function test_alumnus_with_newer_rejected_or_waiting_registration_can_access_completed_documents(): void
    {
        $user = $this->makeUser('pemagang');

        // Periode 1: Completed dengan Membercard
        $completedReg = $this->createRegistration($user, [
            'fullname'          => 'Alumni Periode 1',
            'internship_status' => IR::STATUS_COMPLETED,
        ]);

        \App\Models\AlumniMembercard::create([
            'intern_id'       => $completedReg->id,
            'member_code'     => 'MC-TEST-001',
            'batch_year'      => 2024,
            'has_downloaded'  => false,
        ]);

        // Periode 2: Registrasi baru berstatus waiting lalu ditolak (rejected)
        $newReg = $this->createRegistration($user, [
            'fullname'          => 'Alumni Periode 2',
            'internship_status' => IR::STATUS_REJECTED,
        ]);

        // Alumni melihat membercard
        $response = $this->actingAs($user)->get(route('pemagang.membercard'));
        $response->assertStatus(200);
        $response->assertSee('MC-TEST-001');
    }

    /**
     * 8. Upaya akses dokumen antar pengguna yang berbeda via ID (tampering) diblokir dengan 403 Forbidden.
     */
    public function test_ownership_tampering_is_blocked_on_document_downloads(): void
    {
        $userA = $this->makeUser('pemagang');
        $userB = $this->makeUser('pemagang');

        $regA = $this->createRegistration($userA, ['internship_status' => IR::STATUS_COMPLETED]);
        $regB = $this->createRegistration($userB, ['internship_status' => IR::STATUS_COMPLETED]);

        // User B mencoba mengakses dokumen milik User A dengan memasukkan registration_id User A
        $response = $this->actingAs($userB)->get(route('pemagang.membercard', ['registration_id' => $regA->id]));
        $response->assertStatus(403);

        $responseSkl = $this->actingAs($userB)->get(route('user.skl.download', ['registration_id' => $regA->id]));
        $responseSkl->assertStatus(403);
    }

    /**
     * 9. Admin override pada status invalid atau closed period wajib menyertakan alasan dan dicatat di activity log.
     */
    public function test_admin_override_on_closed_or_invalid_transition_requires_reason_and_logs_activity(): void
    {
        $admin = $this->makeUser('admin');
        $user  = $this->makeUser('pemagang');

        $completedReg = $this->createRegistration($user, ['internship_status' => IR::STATUS_COMPLETED]);

        // 1. Admin mencoba mengubah status registration yang sudah completed tanpa reason -> Ditolak (422)
        $failResponse = $this->actingAs($admin)->patch(route('admin.interns.status.update', $completedReg->id), [
            'internship_status' => IR::STATUS_ACTIVE,
            // reason dikosongkan
        ]);
        $failResponse->assertSessionHas('error');
        $this->assertEquals(IR::STATUS_COMPLETED, $completedReg->fresh()->internship_status);

        // 2. Admin mengubah status dengan menyertakan reason eksplisit -> Berhasil dan tercatat di activity_logs
        $successResponse = $this->actingAs($admin)->patch(route('admin.interns.status.update', $completedReg->id), [
            'internship_status' => IR::STATUS_ACTIVE,
            'reason'            => 'Koreksi administratif perpanjangan magang oleh admin',
        ]);
        $successResponse->assertSessionDoesntHaveErrors();
        $this->assertEquals(IR::STATUS_ACTIVE, $completedReg->fresh()->internship_status);

        // Verifikasi activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'action'      => 'intern_registration_status_corrected',
            'subject_id'  => $completedReg->id,
            'description' => "Admin melakukan koreksi status pendaftaran #{$completedReg->id} dari 'completed' ke 'active'. Alasan: Koreksi administratif perpanjangan magang oleh admin",
        ]);
    }

    /**
     * 10. Model User memisahkan semantic latestRegistration, currentRegistration, dan latestCompletedRegistration.
     */
    public function test_user_model_semantic_relations_separate_latest_current_and_completed(): void
    {
        $user = $this->makeUser('pemagang');

        // Periode 1: Completed
        $reg1 = $this->createRegistration($user, [
            'fullname'          => 'Periode 1',
            'internship_status' => IR::STATUS_COMPLETED,
        ]);

        // Saat ini baru ada periode 1 (completed), maka tidak ada current operational registration
        $this->assertEquals($reg1->id, $user->latestRegistration->id);
        $this->assertNull($user->currentRegistration());
        $this->assertEquals($reg1->id, $user->latestCompletedRegistration->id);

        // Periode 2: Waiting (pendaftaran baru sedang diproses)
        $reg2 = $this->createRegistration($user, [
            'fullname'          => 'Periode 2',
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // Sekarang latestRegistration adalah reg2, currentRegistration adalah reg2, tapi latestCompletedRegistration tetap reg1
        $this->assertEquals($reg2->id, $user->fresh()->latestRegistration->id);
        $this->assertEquals($reg2->id, $user->currentRegistration()->id);
        $this->assertEquals($reg1->id, $user->latestCompletedRegistration->id);
    }

    /**
     * 11. Pendaftaran ulang tidak mengubah identitas akun (users.name).
     */
    public function test_reregistration_does_not_mutate_account_identity_name(): void
    {
        $user = $this->makeUser('pemagang', ['name' => 'Original Account Name']);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'programmer']);

        $this->createRegistration($user, [
            'fullname'          => 'Nama Registrasi Periode 1',
            'internship_status' => IR::STATUS_COMPLETED,
        ]);

        $this->assertEquals('Original Account Name', $user->fresh()->name);

        // Submit periode 2 dengan nama pendaftaran yang berbeda
        $this->actingAs($user)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Nama Registrasi Periode 2 Berbeda',
            'email'               => $user->email,
            'phone_number'        => '081234567899',
            'student_id'          => 'NIM_2028',
            'gender'              => 'Laki-laki',
            'born_date'           => '2001-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => 'Universitas Gadjah Mada',
            'faculty'             => 'Fakultas Teknik',
            'study_program'       => 'Teknologi Informasi',
            'current_city'        => 'Yogyakarta',
            'internship_reason'   => 'Magang lanjutan',
            'internship_interest' => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ]);

        // Verifikasi: users.name akun asli TETAP utuh tidak termutasi
        $this->assertEquals('Original Account Name', $user->fresh()->name);
        $this->assertEquals(2, $user->internshipRegistrations()->count());
    }
}
