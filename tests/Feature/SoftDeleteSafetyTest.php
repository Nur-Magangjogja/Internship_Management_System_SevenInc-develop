<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\InternshipRegistration;
use App\Models\Institution;
use App\Models\Division;
use App\Models\AlumniMembercard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

class SoftDeleteSafetyTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role, array $attributes = []): User
    {
        $unique = uniqid('sd_test_', true);
        return User::create(array_merge([
            'name'      => 'user_' . substr($unique, 0, 15),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createRegistration(User $user, string $status = 'completed'): InternshipRegistration
    {
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas Test']);
        $div  = Division::first() ?? Division::create(['name' => 'Administration', 'slug' => 'admin-test']);
        $city = \App\Models\City::first() ?? \App\Models\City::create(['name' => 'Yogyakarta']);
        $fac  = \App\Models\Faculty::first() ?? \App\Models\Faculty::create(['name' => 'Teknik', 'institution_id' => $inst->id]);
        $prog = \App\Models\StudyProgram::first() ?? \App\Models\StudyProgram::create(['name' => 'Informatika', 'faculty_id' => $fac->id]);

        return InternshipRegistration::create([
            'user_id'                 => $user->id,
            'fullname'                => 'Pemagang ' . $user->name,
            'born_date'               => '2001-01-01',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'city_id'                 => $city->id,
            'student_id'              => 'NIM_' . rand(1000, 9999),
            'institution_id'          => $inst->id,
            'faculty_id'              => $fac->id,
            'study_program_id'        => $prog->id,
            'internship_reason'       => 'Pengalaman kerja',
            'internship_type'         => 'PKL',
            'internship_arrangement'  => 'Onsite',
            'current_status'          => 'Mahasiswa/Pelajar',
            'english_book_ability'    => 'Saya bisa',
            'division_id'             => $div->id,
            'internship_status'       => $status,
        ]);
    }

    /**
     * 1. Soft delete pada user TIDAK menghapus pendaftaran atau dokumen turunan
     */
    public function test_soft_deleting_user_preserves_internship_registration_and_documents(): void
    {
        $pemagang = $this->makeUser('pemagang');
        $reg = $this->createRegistration($pemagang, 'completed');

        $membercard = AlumniMembercard::create([
            'intern_id'   => $reg->id,
            'member_code' => 'TEST_CARD_' . rand(1000, 9999),
            'batch_year'  => '2026',
        ]);

        // Soft delete user
        $pemagang->delete();

        $this->assertTrue($pemagang->fresh()->trashed());

        // Verifikasi pendaftaran dan membercard tetap ada di database
        $this->assertDatabaseHas('internship_registrations', [
            'id'      => $reg->id,
            'user_id' => $pemagang->id,
        ]);

        $this->assertDatabaseHas('alumni_membercards', [
            'id'        => $membercard->id,
            'intern_id' => $reg->id,
        ]);
    }

    /**
     * 2. Relasi user() pada InternshipRegistration tetap dapat membaca user yang di-soft delete (withTrashed)
     */
    public function test_internship_registration_can_access_soft_deleted_user_via_relation(): void
    {
        $pemagang = $this->makeUser('pemagang');
        $reg = $this->createRegistration($pemagang, 'completed');

        $originalName = $pemagang->name;
        $originalEmail = $pemagang->email;

        $pemagang->delete();

        // Refresh registration dan akses relasi user
        $freshReg = InternshipRegistration::find($reg->id);
        $this->assertEquals($originalName, $freshReg->user->getRawOriginal('name'));
        $this->assertEquals($originalEmail, $freshReg->user->email);
        $this->assertTrue($freshReg->user->trashed());
    }

    /**
     * 3. Admin tidak dapat menghapus pengguna umum tanpa konfirmasi password yang valid
     */
    public function test_admin_cannot_delete_general_user_without_password_confirmation(): void
    {
        $admin = $this->makeUser('admin', [
            'password' => Hash::make('adminSecret123'),
        ]);
        $targetUser = $this->makeUser('pemagang');

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $targetUser->id), [
            'admin_password' => 'wrongAdminPassword',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertFalse($targetUser->fresh()->trashed());
    }

    /**
     * 4. Admin dapat melakukan soft delete pengguna dengan password yang valid dan tercatat di audit log
     */
    public function test_admin_can_soft_delete_general_user_with_password_confirmation_and_log_is_created(): void
    {
        $password = 'adminSecret123';
        $admin = $this->makeUser('admin', [
            'password' => Hash::make($password),
        ]);
        $targetUser = $this->makeUser('pemagang');

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $targetUser->id), [
            'admin_password' => $password,
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $this->assertTrue($targetUser->fresh()->trashed());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'user_deleted',
            'actor_user_id' => $admin->id,
            'subject_id'    => $targetUser->id,
        ]);
    }

    /**
     * 5. Pemagang selesai (alumni) tetap dapat login dan mengakses halaman dokumen selama akun aktif
     */
    public function test_completed_pemagang_can_still_login_and_access_documents_if_not_deleted(): void
    {
        $password = 'mypassword123';
        $pemagang = $this->makeUser('pemagang', [
            'password' => Hash::make($password),
        ]);
        $this->createRegistration($pemagang, 'completed');

        // Login sukses
        $loginResponse = $this->post(route('user.login.submit'), [
            'login'    => $pemagang->email,
            'password' => $password,
        ]);
        $loginResponse->assertRedirect(route('pemagang.dashboard'));

        // Akses halaman dokumen sebagai alumni
        $docResponse = $this->actingAs($pemagang)->get(route('pemagang.documents'));
        $docResponse->assertStatus(200);
    }
}
