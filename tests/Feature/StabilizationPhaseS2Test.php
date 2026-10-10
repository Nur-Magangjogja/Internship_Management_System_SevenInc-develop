<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\InternshipRegistration;
use App\Models\AlumniMembercard;
use App\Models\InternLoa;
use App\Models\SklDocument;
use App\Models\InternCertificate;
use App\Models\InternAssessment;
use App\Models\ActivityLog;
use App\Models\FollowUpCycle;
use App\Models\FollowUpAttempt;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\Division;
use App\Models\City;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\DailyReport;
use App\Models\LeaveRequest;
use App\Models\PendingTask;
use App\Models\InternSkill;
use App\Models\InternTool;
use App\Models\InternInfoSource;
use App\Models\RegistrationContactLink;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StabilizationPhaseS2Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role, array $attributes = []): User
    {
        $unique = uniqid('s2_test_', true);
        return User::create(array_merge([
            'name'      => 'usr_' . Str::random(12),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createRegistration(User $user, string $status = 'completed'): InternshipRegistration
    {
        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas S2 Test']);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = StudyProgram::first() ?? StudyProgram::create(['name' => 'Sistem Informasi', 'faculty_id' => $fac->id]);
        $div  = Division::first() ?? Division::create(['name' => 'Administration', 'slug' => 'adm-s2-test']);

        return InternshipRegistration::create([
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
            'internship_status'      => $status,
        ]);
    }

    /**
     * 1. Admin Delete User Re-Auth:
     * Menghapus user tanpa kata sandi admin atau dengan kata sandi salah harus ditolak.
     */
    public function test_admin_delete_user_requires_correct_admin_password(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $target = $this->makeUser('user');

        // Tanpa admin_password
        $responseEmpty = $this->actingAs($admin)->delete(route('admin.users.destroy', $target->id), []);
        $responseEmpty->assertSessionHasErrors('admin_password');
        $this->assertFalse($target->fresh()->trashed());

        // Dengan admin_password salah
        $responseWrong = $this->actingAs($admin)->delete(route('admin.users.destroy', $target->id), [
            'admin_password' => 'wrongPassword',
        ]);
        $responseWrong->assertRedirect();
        $responseWrong->assertSessionHas('error');
        $this->assertFalse($target->fresh()->trashed());
    }

    /**
     * 2. Admin Delete User Success & Audit Log:
     * Menghapus user dengan password admin yang benar menjalankan soft delete dan mencatat audit log.
     */
    public function test_admin_can_soft_delete_user_with_valid_password_and_logs_activity(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $target = $this->makeUser('user');

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $target->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $this->assertTrue($target->fresh()->trashed());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'user_deleted',
            'actor_user_id' => $admin->id,
            'subject_id'    => $target->id,
        ]);
    }

    /**
     * 3. Soft-deleted user cannot login:
     * Akun yang telah di-soft-delete tidak dapat login melalui sistem autentikasi.
     */
    public function test_soft_deleted_user_cannot_login(): void
    {
        $password = 'plainPassword123';
        $user = $this->makeUser('pemagang', ['password' => Hash::make($password)]);

        // Soft delete user
        $user->delete();
        $this->assertTrue($user->fresh()->trashed());

        // Coba login
        $response = $this->post(route('user.login.submit'), [
            'login'    => $user->email,
            'password' => $password,
        ]);

        $this->assertGuest();
        $response->assertSessionHas('error');
    }

    /**
     * 4. EnsureAccountIsActive Middleware:
     * Jika sesi aktif mendeteksi user di-soft-delete atau di-ban, sesi segera dibatalkan.
     */
    public function test_ensure_account_is_active_middleware_blocks_soft_deleted_user_session(): void
    {
        $user = $this->makeUser('pemagang');
        $this->createRegistration($user, 'active');

        // Login user
        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        // Soft delete user secara tak terduga
        $user->delete();

        // Permintaan berikutnya harus membatalkan sesi dan diarahkan ke login
        $response = $this->get(route('pemagang.dashboard'));
        $response->assertRedirect(route('user.login'));
        $this->assertGuest();
    }

    /**
     * 5. History-Safe Deletion:
     * Soft delete akun pemagang tidak menghapus data pendaftaran dan seluruh dokumen turunan (LOA, SKL, Sertifikat, Membercard, Penilaian).
     */
    public function test_soft_deleting_user_preserves_registration_and_all_documents(): void
    {
        $pemagang = $this->makeUser('pemagang');
        $reg = $this->createRegistration($pemagang, 'completed');

        // Buat semua jenis dokumen historis untuk pendaftaran ini
        $membercard = AlumniMembercard::create([
            'intern_id'   => $reg->id,
            'member_code' => 'MC_S2_' . rand(1000, 9999),
            'batch_year'  => '2026',
        ]);

        $loa = InternLoa::create([
            'intern_id'           => $reg->id,
            'loa_number'          => '001/LOA/ADM/SEVEN.SI/IX/2026',
            'accepted_start_date' => now()->toDateString(),
            'accepted_end_date'   => now()->addMonths(3)->toDateString(),
            'company_name'        => 'Seven Inc',
            'signatory_name'      => 'Direktur Utama',
            'signatory_position'  => 'Direktur',
            'created_at'          => now(),
        ]);

        $skl = SklDocument::create([
            'intern_id'          => $reg->id,
            'skl_number'         => '001/SKL/ADM/SEVEN.SI/IX/2026',
            'company_name'       => 'Seven Inc',
            'signatory_name'     => 'Direktur Utama',
            'signatory_position' => 'Direktur',
        ]);

        $cert = InternCertificate::create([
            'intern_id'          => $reg->id,
            'certificate_number' => '001/SERT/ADM/SEVEN.SI/IX/2026',
            'company_name'       => 'Seven Inc',
            'signatory_name'     => 'Direktur Utama',
            'signatory_position' => 'Direktur',
            'created_at'         => now(),
        ]);

        $assessment = InternAssessment::create([
            'intern_id'          => $reg->id,
            'assessment_number'  => '001/NILAI/ADM/SEVEN.SI/IX/2026',
            'company_name'       => 'Seven Inc',
            'signatory_name'     => 'Direktur Utama',
            'signatory_position' => 'Direktur',
            'rata_rata'          => 92.5,
            'aspek_penilaian'    => ['kedisiplinan' => 95, 'kinerja' => 90],
        ]);

        // Verifikasi helper hasDocuments() mengembalikan true
        $this->assertTrue($reg->fresh()->hasDocuments());

        // Soft delete user
        $pemagang->delete();

        // 1. User berstatus trashed
        $this->assertTrue($pemagang->fresh()->trashed());

        // 2. Data pendaftaran masih ada 100%
        $this->assertDatabaseHas('internship_registrations', [
            'id'      => $reg->id,
            'user_id' => $pemagang->id,
        ]);

        // 3. Seluruh dokumen historis tetap utuh
        $this->assertDatabaseHas('alumni_membercards', ['id' => $membercard->id]);
        $this->assertDatabaseHas('intern_loas', ['id' => $loa->id]);
        $this->assertDatabaseHas('skl_documents', ['id' => $skl->id]);
        $this->assertDatabaseHas('intern_certificates', ['id' => $cert->id]);
        $this->assertDatabaseHas('intern_assessments', ['id' => $assessment->id]);

        // 4. Relasi pendaftaran ke user tetap berfungsi
        $freshReg = InternshipRegistration::find($reg->id);
        $this->assertNotNull($freshReg->user);
        $this->assertEquals($pemagang->id, $freshReg->user->id);
        $this->assertTrue($freshReg->user->trashed());
    }

    /**
     * 6. Admin Restore User:
     * Memulihkan user dari arsip terhapus membutuhkan kata sandi admin yang valid.
     */
    public function test_admin_restore_user_requires_admin_password(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $target = $this->makeUser('user');
        $target->delete();
        $this->assertTrue($target->fresh()->trashed());

        // Restore tanpa password
        $responseEmpty = $this->actingAs($admin)->post(route('admin.users.restore', $target->id), []);
        $responseEmpty->assertSessionHasErrors('admin_password');
        $this->assertTrue($target->fresh()->trashed());

        // Restore dengan password salah
        $responseWrong = $this->actingAs($admin)->post(route('admin.users.restore', $target->id), [
            'admin_password' => 'wrongPassword',
        ]);
        $responseWrong->assertRedirect();
        $responseWrong->assertSessionHas('error');
        $this->assertTrue($target->fresh()->trashed());
    }

    /**
     * 7. Admin Restore User Success & User Can Login Again:
     * Memulihkan akun dengan kata sandi admin yang valid mengaktifkan kembali akun dan mencatat log.
     */
    public function test_admin_restore_user_success_and_user_can_login_again(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);

        $userPassword = 'userLoginPass123';
        $target = $this->makeUser('pemagang', ['password' => Hash::make($userPassword)]);
        $this->createRegistration($target, 'completed');

        // Soft delete user
        $target->delete();
        $this->assertTrue($target->fresh()->trashed());

        // Admin restore akun
        $response = $this->actingAs($admin)->post(route('admin.users.restore', $target->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        // Akun tidak lagi trashed
        $this->assertFalse($target->fresh()->trashed());

        // Activity log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'user_restored',
            'actor_user_id' => $admin->id,
            'subject_id'    => $target->id,
        ]);

        // Pengguna yang dipulihkan dapat login kembali
        $loginResponse = $this->post(route('user.login.submit'), [
            'login'    => $target->email,
            'password' => $userPassword,
        ]);

        $loginResponse->assertRedirect(route('pemagang.dashboard'));
        $this->assertAuthenticatedAs($target->fresh());
    }

    /**
     * 8. Historical Actor Relations with withTrashed:
     * ActivityLog, FollowUpAttempt, dan FollowUpCycle tetap membaca aktor yang di-soft-delete.
     */
    public function test_historical_actor_relations_load_soft_deleted_users_via_with_trashed(): void
    {
        $staff = $this->makeUser('hr_staff');
        $inst = Institution::first() ?? Institution::create(['name' => 'Univ Actor Test']);
        $contact = InstitutionContact::create([
            'institution_id' => $inst->id,
            'name'           => 'Kontak Test',
            'phone'          => '081234567899',
            'position'       => 'Humas',
        ]);

        // Buat log aktivitas oleh staf
        $log = ActivityLogger::log(
            action: 'test_action',
            description: 'Staf melakukan aksi tes',
            actor: $staff
        );

        // Buat cycle dengan confirmed_by dan locked_by
        $cycle = FollowUpCycle::create([
            'institution_id'         => $inst->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'confirmed_by'           => $staff->id,
            'confirmed_at'           => now(),
            'locked_by'              => $staff->id,
            'locked_at'              => now(),
        ]);

        // Buat attempt oleh staf
        $attempt = FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $staff->id,
            'followed_up_at'    => now(),
            'channel'           => 'whatsapp',
            'result'            => 'responded',
        ]);

        // Sekarang soft-delete staf
        $staff->delete();
        $this->assertTrue($staff->fresh()->trashed());

        // Verifikasi relasi tetap dapat mengambil staf yang di-soft-delete
        $freshLog = ActivityLog::find($log->id);
        $this->assertNotNull($freshLog->actor);
        $this->assertEquals($staff->id, $freshLog->actor->id);

        $freshAttempt = FollowUpAttempt::find($attempt->id);
        $this->assertNotNull($freshAttempt->performer);
        $this->assertEquals($staff->id, $freshAttempt->performer->id);

        $freshCycle = FollowUpCycle::find($cycle->id);
        $this->assertNotNull($freshCycle->confirmer);
        $this->assertEquals($staff->id, $freshCycle->confirmer->id);
        $this->assertNotNull($freshCycle->lockedByUser);
        $this->assertEquals($staff->id, $freshCycle->lockedByUser->id);
    }

    /**
     * 9. InternController::destroy Re-Auth Requirement:
     * Menghapus pendaftaran magang membutuhkan kata sandi admin yang valid.
     */
    public function test_intern_controller_destroy_requires_admin_password(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $user = $this->makeUser('pemagang');
        $reg = $this->createRegistration($user, 'pending');

        // Tanpa password -> 422
        $responseEmpty = $this->actingAs($admin)->deleteJson(route('admin.interns.destroy', $reg->id), []);
        $responseEmpty->assertStatus(422);

        // Password salah -> 403
        $responseWrong = $this->actingAs($admin)->deleteJson(route('admin.interns.destroy', $reg->id), [
            'admin_password' => 'wrongPassword',
        ]);
        $responseWrong->assertStatus(403);
    }

    /**
    /**
     * 10. InternController::destroy Soft Deletes and Preserves All Child Records:
     * Menghapus pendaftaran pemagang melakukan soft-delete sehingga seluruh dokumen & child records tetap utuh di database.
     */
    public function test_intern_controller_destroy_soft_deletes_and_preserves_all_child_records(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $user = $this->makeUser('pemagang');
        $reg = $this->createRegistration($user, 'completed');

        // Buat seluruh child records: dokumen, log, tugas, dsb
        $loa = InternLoa::create([
            'intern_id'           => $reg->id,
            'loa_number'          => '002/LOA/ADM/SEVEN.SI/IX/2026',
            'accepted_start_date' => now()->toDateString(),
            'accepted_end_date'   => now()->addMonths(3)->toDateString(),
            'company_name'        => 'Seven Inc',
            'signatory_name'      => 'Direktur Utama',
            'signatory_position'  => 'Direktur',
            'created_at'          => now(),
        ]);

        $skl = SklDocument::create([
            'intern_id'          => $reg->id,
            'skl_number'         => '002/SKL/ADM/SEVEN.SI/IX/2026',
            'company_name'       => 'Seven Inc',
            'signatory_name'     => 'Direktur Utama',
            'signatory_position' => 'Direktur',
        ]);

        $cert = InternCertificate::create([
            'intern_id'          => $reg->id,
            'certificate_number' => '002/SERT/ADM/SEVEN.SI/IX/2026',
            'company_name'       => 'Seven Inc',
            'signatory_name'     => 'Direktur Utama',
            'signatory_position' => 'Direktur',
            'created_at'         => now(),
        ]);

        $membercard = AlumniMembercard::create([
            'intern_id'   => $reg->id,
            'member_code' => 'MC_S2_CH_' . rand(1000, 9999),
            'batch_year'  => '2026',
        ]);

        $assessment = InternAssessment::create([
            'intern_id'          => $reg->id,
            'assessment_number'  => '002/NILAI/ADM/SEVEN.SI/IX/2026',
            'company_name'       => 'Seven Inc',
            'signatory_name'     => 'Direktur Utama',
            'signatory_position' => 'Direktur',
            'rata_rata'          => 95.0,
            'aspek_penilaian'    => ['kedisiplinan' => 95],
        ]);

        $dailyReport = DailyReport::create([
            'intern_id'  => $reg->id,
            'date'       => now()->toDateString(),
            'activities' => 'Aktivitas pengujian S2',
            'challenges' => 'Tidak ada kendala',
        ]);

        $leaveRequest = LeaveRequest::create([
            'intern_id'  => $reg->id,
            'leave_type' => 'Sakit',
            'leave_date' => now()->toDateString(),
            'reason'     => 'Flu ringan',
            'status'     => 'approved',
        ]);

        $pendingTask = PendingTask::create([
            'intern_id'   => $reg->id,
            'title'       => 'Tugas modul S2',
            'description' => 'Mengecek integritas child relations',
        ]);

        $skill = InternSkill::create([
            'internship_registration_id' => $reg->id,
            'skill_category'             => 'programming',
            'skill_name'                 => 'PHP/Laravel',
        ]);

        $tool = InternTool::create([
            'internship_registration_id' => $reg->id,
            'tool_name'                  => 'VS Code',
        ]);

        $infoSource = InternInfoSource::create([
            'internship_registration_id' => $reg->id,
            'source_name'                => 'Website Resmi',
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $reg->institution_id,
            'name'           => 'Dosen Pembimbing Test',
            'phone'          => '081299998888',
        ]);

        $link = RegistrationContactLink::create([
            'internship_registration_id' => $reg->id,
            'institution_contact_id'     => $contact->id,
            'relation_year'              => (int) date('Y'),
            'is_primary'                 => true,
            'source'                     => 'registration',
        ]);

        $this->assertTrue($reg->fresh()->hasDocuments());

        // Admin menghapus pendaftaran yang memiliki dokumen & child records
        $response = $this->actingAs($admin)->deleteJson(route('admin.interns.destroy', $reg->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertStatus(200);

        // Pendaftaran berstatus trashed (soft delete)
        $this->assertTrue($reg->fresh()->trashed());
        $this->assertDatabaseHas('internship_registrations', ['id' => $reg->id]);

        // SELURUH CHILD RECORDS TETAP UTUH DI DATABASE (TIDAK ADA CASCADE LOSS)
        $this->assertDatabaseHas('intern_loas', ['id' => $loa->id]);
        $this->assertDatabaseHas('skl_documents', ['id' => $skl->id]);
        $this->assertDatabaseHas('intern_certificates', ['id' => $cert->id]);
        $this->assertDatabaseHas('alumni_membercards', ['id' => $membercard->id]);
        $this->assertDatabaseHas('intern_assessments', ['id' => $assessment->id]);
        $this->assertDatabaseHas('daily_reports', ['id' => $dailyReport->id]);
        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id]);
        $this->assertDatabaseHas('pending_tasks', ['id' => $pendingTask->id]);
        $this->assertDatabaseHas('intern_skills', ['id' => $skill->id]);
        $this->assertDatabaseHas('intern_tools', ['id' => $tool->id]);
        $this->assertDatabaseHas('intern_info_sources', ['id' => $infoSource->id]);
        $this->assertDatabaseHas('registration_contact_links', ['id' => $link->id]);

        // Navigasi relasi withTrashed dari child records ke pendaftaran tetap berfungsi
        $this->assertEquals($reg->id, $loa->fresh()->intern?->id);
        $this->assertEquals($reg->id, $skl->fresh()->intern?->id);
        $this->assertEquals($reg->id, $cert->fresh()->intern?->id);
        $this->assertEquals($reg->id, $membercard->fresh()->intern?->id);
        $this->assertEquals($reg->id, $assessment->fresh()->intern?->id);
        $this->assertEquals($reg->id, $dailyReport->fresh()->internshipRegistration?->id);
        $this->assertEquals($reg->id, $leaveRequest->fresh()->internshipRegistration?->id);
        $this->assertEquals($reg->id, $pendingTask->fresh()->internshipRegistration?->id);
        $this->assertEquals($reg->id, $skill->fresh()->internshipRegistration?->id);
        $this->assertEquals($reg->id, $tool->fresh()->internshipRegistration?->id);
        $this->assertEquals($reg->id, $infoSource->fresh()->internshipRegistration?->id);
        $this->assertEquals($reg->id, $link->fresh()->internshipRegistration?->id);
    }

    /**
     * 11. InternController::destroy Allowed For Registration Without Documents:
     * Pendaftaran yang tidak memiliki dokumen historis di-soft-delete dengan aman dan dicatat log.
     */
    public function test_intern_controller_destroy_allows_deleting_registration_without_documents(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $user = $this->makeUser('pemagang');
        $reg = $this->createRegistration($user, 'pending');

        $this->assertFalse($reg->fresh()->hasDocuments());

        $response = $this->actingAs($admin)->deleteJson(route('admin.interns.destroy', $reg->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertStatus(200);

        // Soft delete: baris tetap ada di DB namun trashed
        $this->assertTrue($reg->fresh()->trashed());
        $this->assertDatabaseHas('internship_registrations', ['id' => $reg->id]);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'intern_registration_deleted',
            'actor_user_id' => $admin->id,
        ]);
    }

    /**
     * 12. Admin Users Index Filter Trashed:
     * Memfilter pengguna dengan status=trashed menampilkan pengguna yang telah di-soft-delete.
     */
    public function test_admin_users_index_trashed_filter_displays_soft_deleted_users(): void
    {
        $admin = $this->makeUser('admin');
        $trashedUser = $this->makeUser('pemagang');
        $trashedUser->delete();

        $activeUser = $this->makeUser('pemagang');

        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'status' => 'trashed',
            'email'  => $trashedUser->email,
        ]));

        $response->assertStatus(200);
        $response->assertSee($trashedUser->name);
        $response->assertDontSee($activeUser->name);

        // Pengguna aktif tidak boleh muncul jika difilter sebagai arsip terhapus (trashed)
        $responseActive = $this->actingAs($admin)->get(route('admin.users.index', [
            'status' => 'trashed',
            'email'  => $activeUser->email,
        ]));
        $responseActive->assertStatus(200);
        $responseActive->assertDontSee($activeUser->name);
        $responseActive->assertSee('Tidak ada pengguna yang sesuai filter.');
    }

    /**
     * 13. Admin Restore Registration Requires Admin Password:
     * Memulihkan pendaftaran pemagang dari arsip menolak kata sandi yang salah atau kosong.
     */
    public function test_admin_restore_registration_requires_admin_password(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $user = $this->makeUser('pemagang');
        $reg = $this->createRegistration($user, 'completed');
        $reg->delete();
        $this->assertTrue($reg->fresh()->trashed());

        // Tanpa password -> 422
        $responseEmpty = $this->actingAs($admin)->postJson(route('admin.interns.restore', $reg->id), []);
        $responseEmpty->assertStatus(422);
        $this->assertTrue($reg->fresh()->trashed());

        // Password salah -> 403
        $responseWrong = $this->actingAs($admin)->postJson(route('admin.interns.restore', $reg->id), [
            'admin_password' => 'wrongPassword',
        ]);
        $responseWrong->assertStatus(403);
        $this->assertTrue($reg->fresh()->trashed());
    }

    /**
     * 14. Admin Restore Registration Success & Does Not Alter User Account State:
     * Memulihkan pendaftaran pemagang mengaktifkan kembali baris pendaftaran tanpa mengubah status akun pengguna.
     */
    public function test_admin_restore_registration_success_and_does_not_alter_user_state(): void
    {
        $adminPassword = 'adminStrongPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);

        // User dalam kondisi soft deleted & banned
        $user = $this->makeUser('pemagang', ['is_banned' => 1]);
        $user->delete();
        $this->assertTrue($user->fresh()->trashed());
        $this->assertEquals(1, $user->fresh()->is_banned);

        // Pendaftaran terhapus (trashed)
        $reg = $this->createRegistration($user, 'completed');
        $reg->delete();
        $this->assertTrue($reg->fresh()->trashed());

        // Admin memulihkan pendaftaran
        $response = $this->actingAs($admin)->postJson(route('admin.interns.restore', $reg->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertStatus(200);

        // Pendaftaran pulih dari status trashed
        $this->assertFalse($reg->fresh()->trashed());

        // Activity log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'intern_registration_restored',
            'actor_user_id' => $admin->id,
        ]);

        // PENTING: User akun TETAP trashed dan TETAP banned (tidak ikut pulih otomatis)
        $freshUser = User::withTrashed()->find($user->id);
        $this->assertTrue($freshUser->trashed());
        $this->assertEquals(1, $freshUser->is_banned);
        $this->assertEquals('pemagang', $freshUser->role);
    }

    /**
     * 15. Admin Interns Trashed Scope & Route Discovery:
     * Rute /admin/interns/trashed dan API scope=trashed mengembalikan data yang di-soft-delete.
     */
    public function test_admin_interns_trashed_scope_and_route_discovery(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('pemagang');
        $trashedReg = $this->createRegistration($user, 'completed');
        $trashedReg->delete();

        $activeReg = $this->createRegistration($user, 'active');

        // 1. Route halaman /admin/interns/trashed
        $responsePage = $this->actingAs($admin)->get(route('admin.interns.trashed'));
        $responsePage->assertStatus(200);

        // 2. Route API scope=trashed
        $responseApi = $this->actingAs($admin)->getJson(route('admin.interns.api', ['scope' => 'trashed']));
        $responseApi->assertStatus(200);
        $json = $responseApi->json();

        $ids = array_column($json['data'], 'id');
        $this->assertContains($trashedReg->id, $ids);
        $this->assertNotContains($activeReg->id, $ids);
    }
}
