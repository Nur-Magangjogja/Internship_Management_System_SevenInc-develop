<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

class HrStaffAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role, array $attributes = []): User
    {
        $unique = uniqid('hr_test_', true);
        return User::create(array_merge([
            'name'      => 'user_' . substr($unique, 0, 15),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    /**
     * 1. Verifikasi role hr_staff dapat disimpan dan dibaca dari database
     */
    public function test_hr_staff_role_can_be_stored_in_database(): void
    {
        $hr = $this->makeUser('hr_staff');

        $this->assertDatabaseHas('users', [
            'id'   => $hr->id,
            'role' => 'hr_staff',
        ]);

        $this->assertTrue($hr->isHrStaff());
        $this->assertFalse($hr->isAdmin());
        $this->assertFalse($hr->isPemagang());
    }

    /**
     * 2. Login hr_staff harus mengalihkan ke hr.dashboard
     */
    public function test_hr_staff_login_redirects_to_hr_dashboard(): void
    {
        $password = 'hr_secret_123';
        $hr = $this->makeUser('hr_staff', [
            'password' => Hash::make($password),
        ]);

        $response = $this->post(route('user.login.submit'), [
            'login'    => $hr->email,
            'password' => $password,
        ]);

        $response->assertRedirect(route('hr.dashboard'));
        $this->assertAuthenticatedAs($hr);
    }

    /**
     * 3. hr_staff dapat membuka dashboard HR
     */
    public function test_hr_staff_can_access_hr_dashboard(): void
    {
        $hr = $this->makeUser('hr_staff');

        $response = $this->actingAs($hr)->get(route('hr.dashboard'));
        $response->assertStatus(200);
        $response->assertSee('Dashboard Operasional HR');
        $response->assertSee('Role: HR Staff');
    }

    /**
     * 4. Multi-role middleware: admin juga dapat mengakses area HR untuk monitoring
     */
    public function test_admin_can_also_access_hr_dashboard(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->get(route('hr.dashboard'));
        $response->assertStatus(200);
    }

    /**
     * 5. hr_staff tidak boleh mengakses kelola pengguna admin (admin.users)
     */
    public function test_hr_staff_cannot_access_admin_exclusive_user_management(): void
    {
        $hr = $this->makeUser('hr_staff');

        $response = $this->actingAs($hr)->get(route('admin.users.index'));
        // Harus dialihkan kembali secara aman ke hr.dashboard
        $response->assertRedirect(route('hr.dashboard'));
        $response->assertSessionHas('error');
    }

    /**
     * 6. hr_staff tidak boleh mengakses kelola brand admin (admin.brands)
     */
    public function test_hr_staff_cannot_access_admin_exclusive_brand_management(): void
    {
        $hr = $this->makeUser('hr_staff');

        $response = $this->actingAs($hr)->get(route('admin.brands.index'));
        $response->assertRedirect(route('hr.dashboard'));
        $response->assertSessionHas('error');
    }

    /**
     * 7. Pemagang tidak boleh mengakses dashboard HR
     */
    public function test_pemagang_cannot_access_hr_dashboard(): void
    {
        $pemagang = $this->makeUser('pemagang');

        $response = $this->actingAs($pemagang)->get(route('hr.dashboard'));
        $response->assertRedirect(route('user.dashboard'));
        $response->assertSessionHas('error');
    }

    /**
     * 8. Tamu (guest) yang mencoba membuka dashboard HR dialihkan ke login
     */
    public function test_guest_cannot_access_hr_dashboard(): void
    {
        $response = $this->get(route('hr.dashboard'));
        $response->assertRedirect(route('user.login'));
    }

    /**
     * 9. Root route (/) me-redirect hr_staff ke hr.dashboard
     */
    public function test_root_redirects_authenticated_hr_staff_to_hr_dashboard(): void
    {
        $hr = $this->makeUser('hr_staff');

        $response = $this->actingAs($hr)->get(route('home'));
        $response->assertRedirect(route('hr.dashboard'));
    }

    /**
     * 10. Login page (/login) me-redirect hr_staff yang sudah authenticated ke hr.dashboard
     */
    public function test_login_page_redirects_authenticated_hr_staff_to_hr_dashboard(): void
    {
        $hr = $this->makeUser('hr_staff');

        $response = $this->actingAs($hr)->get(route('user.login'));
        $response->assertRedirect(route('hr.dashboard'));
    }
}
