<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\InternshipRegistration;
use App\Models\Institution;
use App\Models\Division;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

class AuthorizationSafetyNetTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Helper to create a user with a specific role
     */
    protected function makeUser(string $role = 'pemagang', array $attributes = []): User
    {
        $unique = uniqid('test_', true);
        return User::create(array_merge([
            'name'      => 'user_' . substr($unique, 0, 15),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    /**
     * 1. Guest cannot access admin dashboard and is redirected to admin.login
     */
    public function test_guest_cannot_access_admin_dashboard_and_is_redirected(): void
    {
        $response = $this->get(route('admin.dashboard.index'));
        $response->assertRedirect(route('user.login'));
    }

    /**
     * 2. Guest cannot access pemagang dashboard and is redirected to user.login
     */
    public function test_guest_cannot_access_pemagang_dashboard_and_is_redirected(): void
    {
        $response = $this->get(route('pemagang.dashboard'));
        $response->assertRedirect(route('user.login'));
    }

    /**
     * 3. Guest cannot access pemagang documents and is redirected to user.login
     */
    public function test_guest_cannot_access_pemagang_documents_and_is_redirected(): void
    {
        $response = $this->get(route('pemagang.documents'));
        $response->assertRedirect(route('user.login'));
    }

    /**
     * 4. Pemagang cannot access admin dashboard
     */
    public function test_pemagang_cannot_access_admin_dashboard(): void
    {
        $pemagang = $this->makeUser('pemagang');

        $response = $this->actingAs($pemagang)->get(route('admin.dashboard.index'));
        // RoleMiddleware redirects unauthorized role away from admin area
        $response->assertRedirect(route('user.dashboard'));
        $response->assertSessionHas('error');
    }

    /**
     * 5. Pemagang cannot access admin users management
     */
    public function test_pemagang_cannot_access_admin_user_management(): void
    {
        $pemagang = $this->makeUser('pemagang');

        $response = $this->actingAs($pemagang)->get(route('admin.users.index'));
        $response->assertRedirect(route('user.dashboard'));
        $response->assertSessionHas('error');
    }

    /**
     * 6. Pemagang cannot create users through admin route
     */
    public function test_pemagang_cannot_create_admin_users(): void
    {
        $pemagang = $this->makeUser('pemagang');
        $targetEmail = 'unauthorized_' . uniqid() . '@example.com';

        $response = $this->actingAs($pemagang)->post(route('admin.users.store'), [
            'name'                  => 'hacker_admin',
            'email'                 => $targetEmail,
            'role'                  => 'admin',
            'password'              => 'secret12345',
            'password_confirmation' => 'secret12345',
        ]);

        $response->assertRedirect(route('user.dashboard'));
        $this->assertDatabaseMissing('users', ['email' => $targetEmail]);
    }

    /**
     * 7. Pemagang cannot ban other users
     */
    public function test_pemagang_cannot_ban_other_users(): void
    {
        $pemagang = $this->makeUser('pemagang');
        $targetUser = $this->makeUser('pemagang');

        $response = $this->actingAs($pemagang)->post(route('admin.users.ban', $targetUser));
        $response->assertRedirect(route('user.dashboard'));

        $this->assertDatabaseHas('users', [
            'id'        => $targetUser->id,
            'is_banned' => 0,
        ]);
    }

    /**
     * 8. Admin can access admin dashboard
     */
    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->get(route('admin.dashboard.index'));
        $response->assertStatus(200);
    }

    /**
     * 9. Admin can view admin users list
     */
    public function test_admin_can_view_admin_users_list(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->get(route('admin.users.index'));
        $response->assertStatus(200);
    }

    /**
     * 10. Banned user cannot login and session is invalidated
     */
    public function test_banned_user_cannot_login_and_is_rejected(): void
    {
        $password = 'secretpass123';
        $bannedUser = $this->makeUser('pemagang', [
            'password'  => Hash::make($password),
            'is_banned' => 1,
        ]);

        $response = $this->post(route('user.login.submit'), [
            'login'    => $bannedUser->email,
            'password' => $password,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Akun Anda telah dinonaktifkan. Hubungi admin untuk informasi lebih lanjut.');
        $this->assertGuest();
    }

    /**
     * 11. Legacy 'user' role can access the registration form
     */
    public function test_legacy_user_role_can_access_registration_form(): void
    {
        $legacyUser = $this->makeUser('user');

        $response = $this->actingAs($legacyUser)->get(route('pemagang.registration.form'));
        $response->assertStatus(200);
    }

    /**
     * 12. Completed pemagang can view their documents page (alumni state)
     */
    public function test_completed_pemagang_can_view_documents_page(): void
    {
        $pemagang = $this->makeUser('pemagang');

        // Create institution, division, city, faculty, study program if needed
        $institution  = Institution::first() ?? Institution::create(['name' => 'Universitas Test']);
        $division     = Division::first() ?? Division::create(['name' => 'Administration', 'slug' => 'administration']);
        $city         = \App\Models\City::first() ?? \App\Models\City::create(['name' => 'Yogyakarta']);
        $faculty      = \App\Models\Faculty::first() ?? \App\Models\Faculty::create(['name' => 'Teknik', 'institution_id' => $institution->id]);
        $studyProgram = \App\Models\StudyProgram::first() ?? \App\Models\StudyProgram::create(['name' => 'Informatika', 'faculty_id' => $faculty->id]);

        // Create completed registration for this user
        InternshipRegistration::create([
            'user_id'                 => $pemagang->id,
            'fullname'                => 'Pemagang Completed',
            'born_date'               => '2001-01-01',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'city_id'                 => $city->id,
            'student_id'              => 'NIM12345',
            'institution_id'          => $institution->id,
            'faculty_id'              => $faculty->id,
            'study_program_id'        => $studyProgram->id,
            'internship_reason'       => 'Test completed alasan',
            'internship_type'         => 'PKL',
            'internship_arrangement'  => 'Onsite',
            'current_status'          => 'Mahasiswa/Pelajar',
            'english_book_ability'    => 'Saya bisa',
            'division_id'             => $division->id,
            'internship_status'       => 'completed',
        ]);

        $response = $this->actingAs($pemagang)->get(route('pemagang.documents'));
        $response->assertStatus(200);
    }

    /**
     * 13. Active pemagang can view their documents page
     */
    public function test_active_pemagang_can_view_documents_page(): void
    {
        $pemagang     = $this->makeUser('pemagang');
        $institution  = Institution::first() ?? Institution::create(['name' => 'Universitas Test']);
        $division     = Division::first() ?? Division::create(['name' => 'Administration', 'slug' => 'administration']);
        $city         = \App\Models\City::first() ?? \App\Models\City::create(['name' => 'Yogyakarta']);
        $faculty      = \App\Models\Faculty::first() ?? \App\Models\Faculty::create(['name' => 'Teknik', 'institution_id' => $institution->id]);
        $studyProgram = \App\Models\StudyProgram::first() ?? \App\Models\StudyProgram::create(['name' => 'Informatika', 'faculty_id' => $faculty->id]);

        InternshipRegistration::create([
            'user_id'                 => $pemagang->id,
            'fullname'                => 'Pemagang Active',
            'born_date'               => '2001-01-01',
            'gender'                  => 'Perempuan',
            'phone_number'            => '081234567891',
            'city_id'                 => $city->id,
            'student_id'              => 'NIM12346',
            'institution_id'          => $institution->id,
            'faculty_id'              => $faculty->id,
            'study_program_id'        => $studyProgram->id,
            'internship_reason'       => 'Test active alasan',
            'internship_type'         => 'PKL',
            'internship_arrangement'  => 'Onsite',
            'current_status'          => 'Mahasiswa/Pelajar',
            'english_book_ability'    => 'Saya bisa',
            'division_id'             => $division->id,
            'internship_status'       => 'active',
        ]);

        $response = $this->actingAs($pemagang)->get(route('pemagang.documents'));
        $response->assertStatus(200);
    }
}
