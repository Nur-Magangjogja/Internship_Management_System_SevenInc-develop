<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

class AdminHrStaffManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role, array $attributes = []): User
    {
        $unique = uniqid('hr_mgmt_', true);
        return User::create(array_merge([
            'name'      => 'user_' . substr($unique, 0, 15),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    /**
     * 1. Admin dapat melihat daftar HR Staff
     */
    public function test_admin_can_view_hr_staff_list(): void
    {
        $admin = $this->makeUser('admin');
        $hr    = $this->makeUser('hr_staff');

        $response = $this->actingAs($admin)->get(route('admin.hr-staff.index', ['search' => $hr->name]));
        $response->assertStatus(200);
        $response->assertSee('Kelola Akun HR Staff');
        $response->assertSee($hr->name);
        $response->assertSee($hr->email);
    }

    /**
     * 2. Admin dapat membuat akun HR Staff baru dan aksinya tercatat di ActivityLog
     */
    public function test_admin_can_create_new_hr_staff_account_and_activity_is_logged(): void
    {
        $admin = $this->makeUser('admin');
        $username = 'hr_budi_' . time();
        $email = 'hr_budi_' . time() . '@seveninc.id';

        $response = $this->actingAs($admin)->post(route('admin.hr-staff.store'), [
            'name'                  => $username,
            'email'                 => $email,
            'password'              => 'securePass123',
            'password_confirmation' => 'securePass123',
        ]);

        $response->assertRedirect(route('admin.hr-staff.index'));
        $response->assertSessionHas('success');

        $createdUser = User::where('email', $email)->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals(User::ROLE_HR_STAFF, $createdUser->role);
        $this->assertEquals($username, $createdUser->name);

        // Verifikasi log audit
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'hr_staff_created',
            'actor_user_id' => $admin->id,
            'subject_id'    => $createdUser->id,
        ]);
    }

    /**
     * 3. Admin dapat memperbarui profil HR Staff dan tercatat di ActivityLog
     */
    public function test_admin_can_update_hr_staff_details_and_activity_is_logged(): void
    {
        $admin = $this->makeUser('admin');
        $hr    = $this->makeUser('hr_staff');

        $updatedName  = 'Updated_HR_' . time();
        $updatedEmail = 'updated_hr_' . time() . '@seveninc.id';

        $response = $this->actingAs($admin)->put(route('admin.hr-staff.update', $hr), [
            'name'  => $updatedName,
            'email' => $updatedEmail,
        ]);

        $response->assertRedirect(route('admin.hr-staff.index'));
        $response->assertSessionHas('success');

        $hr->refresh();
        $this->assertEquals($updatedName, $hr->name);
        $this->assertEquals($updatedEmail, $hr->email);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'hr_staff_updated',
            'actor_user_id' => $admin->id,
            'subject_id'    => $hr->id,
        ]);
    }

    /**
     * 4. Admin dapat menonaktifkan / mengaktifkan akun HR Staff via is_banned
     */
    public function test_admin_can_toggle_hr_staff_banned_status_and_activity_is_logged(): void
    {
        $admin = $this->makeUser('admin');
        $hr    = $this->makeUser('hr_staff', ['is_banned' => 0]);

        // Toggle menjadi nonaktif
        $response = $this->actingAs($admin)->post(route('admin.hr-staff.toggle-status', $hr));
        $response->assertRedirect();

        $hr->refresh();
        $this->assertTrue((bool) $hr->is_banned);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'hr_staff_status_toggled',
            'actor_user_id' => $admin->id,
            'subject_id'    => $hr->id,
        ]);
    }

    /**
     * 5. Admin tidak dapat menghapus akun HR Staff jika konfirmasi password salah
     */
    public function test_admin_cannot_delete_hr_staff_without_valid_password_confirmation(): void
    {
        $adminPassword = 'realAdminPassword123';
        $admin = $this->makeUser('admin', [
            'password' => Hash::make($adminPassword),
        ]);
        $hr = $this->makeUser('hr_staff');

        // Mencoba delete dengan password salah
        $response = $this->actingAs($admin)->delete(route('admin.hr-staff.destroy', $hr), [
            'admin_password' => 'wrongPassword',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $hr->refresh();
        $this->assertFalse($hr->trashed());
    }

    /**
     * 6. Admin dapat melakukan soft delete akun HR Staff dengan password benar dan tercatat di audit log
     */
    public function test_admin_can_soft_delete_hr_staff_with_valid_password_and_activity_is_logged(): void
    {
        $adminPassword = 'realAdminPassword123';
        $admin = $this->makeUser('admin', [
            'password' => Hash::make($adminPassword),
        ]);
        $hr = $this->makeUser('hr_staff');

        $response = $this->actingAs($admin)->delete(route('admin.hr-staff.destroy', $hr), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertRedirect(route('admin.hr-staff.index'));
        $response->assertSessionHas('success');

        $hr->refresh();
        $this->assertTrue($hr->trashed());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'hr_staff_deleted',
            'actor_user_id' => $admin->id,
            'subject_id'    => $hr->id,
        ]);
    }

    /**
     * 7. Akun HR Staff yang di-soft-delete tidak dapat login
     */
    public function test_soft_deleted_hr_staff_cannot_login(): void
    {
        $hrPassword = 'password123';
        $hr = $this->makeUser('hr_staff', [
            'password' => Hash::make($hrPassword),
        ]);

        // Soft delete akun
        $hr->delete();
        $this->assertTrue($hr->trashed());

        $response = $this->post(route('user.login.submit'), [
            'login'    => $hr->email,
            'password' => $hrPassword,
        ]);

        // Login ditolak karena akun berstatus soft-deleted
        $this->assertGuest();
    }

    /**
     * 8. Admin dapat memulihkan (restore) akun HR Staff yang terhapus dengan password benar
     */
    public function test_admin_can_restore_soft_deleted_hr_staff_with_valid_password(): void
    {
        $adminPassword = 'realAdminPassword123';
        $admin = $this->makeUser('admin', [
            'password' => Hash::make($adminPassword),
        ]);
        $hr = $this->makeUser('hr_staff');
        $hr->delete();

        $this->assertTrue($hr->trashed());

        $response = $this->actingAs($admin)->post(route('admin.hr-staff.restore', $hr->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertRedirect(route('admin.hr-staff.index'));
        $response->assertSessionHas('success');

        $hr->refresh();
        $this->assertFalse($hr->trashed());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'hr_staff_restored',
            'actor_user_id' => $admin->id,
            'subject_id'    => $hr->id,
        ]);
    }

    /**
     * 9. HR Staff tidak boleh mengakses modul kelola HR Staff
     */
    public function test_hr_staff_cannot_access_hr_management_module(): void
    {
        $hr = $this->makeUser('hr_staff');

        $response = $this->actingAs($hr)->get(route('admin.hr-staff.index'));
        $response->assertRedirect(route('hr.dashboard'));
        $response->assertSessionHas('error');
    }

    /**
     * 10. Pemagang tidak boleh mengakses modul kelola HR Staff
     */
    public function test_pemagang_cannot_access_hr_management_module(): void
    {
        $pemagang = $this->makeUser('pemagang');

        $response = $this->actingAs($pemagang)->get(route('admin.hr-staff.index'));
        $response->assertRedirect(route('user.dashboard'));
        $response->assertSessionHas('error');
    }
}
