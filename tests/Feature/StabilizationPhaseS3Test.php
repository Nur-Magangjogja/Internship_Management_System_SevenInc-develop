<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\InternshipRegistration;
use App\Models\Institution;
use App\Models\Division;
use App\Models\City;
use App\Models\Faculty;
use App\Models\StudyProgram;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StabilizationPhaseS3Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role, array $attributes = []): User
    {
        $unique = uniqid('s3_test_', true);
        return User::create(array_merge([
            'name'      => 'usr_' . Str::random(12),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createRegistration(User $user, array $overrides = []): InternshipRegistration
    {
        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas S3 Test']);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = StudyProgram::first() ?? StudyProgram::create(['name' => 'Sistem Informasi', 'faculty_id' => $fac->id]);
        $div  = Division::first() ?? Division::create(['name' => 'Administration', 'slug' => 'adm-s3-test']);

        return InternshipRegistration::create(array_merge([
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
            'internship_status'      => 'completed',
        ], $overrides));
    }

    /**
     * 1. Admin tidak dapat menonaktifkan (ban) akun miliknya sendiri melalui UserController.
     */
    public function test_admin_cannot_ban_own_account(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.users.ban', $admin->id), [
            'ban_reason' => 'Tes ban diri sendiri',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');

        $this->assertFalse((bool) $admin->fresh()->is_banned);
    }

    /**
     * 2. Admin tidak dapat menghapus (delete) akun miliknya sendiri melalui UserController.
     */
    public function test_admin_cannot_delete_own_account(): void
    {
        $adminPassword = 'adminSecretPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $admin->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Anda tidak dapat menghapus akun Anda sendiri.');

        $this->assertFalse($admin->fresh()->trashed());
    }

    /**
     * 3. Mengedit akun HR Staff via UserController diarahkan ke modul khusus HrStaffController.
     */
    public function test_editing_hr_staff_via_generic_user_controller_redirects_to_hr_module(): void
    {
        $admin = $this->makeUser('admin');
        $hrStaff = $this->makeUser(User::ROLE_HR_STAFF);

        $response = $this->actingAs($admin)->get(route('admin.users.edit', $hrStaff->id));

        $response->assertRedirect(route('admin.hr-staff.edit', $hrStaff->id));
        $response->assertSessionHas('info');
    }

    /**
     * 4. Memperbarui akun HR Staff via UserController ditolak dan diarahkan ke modul HR Staff.
     */
    public function test_updating_hr_staff_via_generic_user_controller_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $hrStaff = $this->makeUser(User::ROLE_HR_STAFF);
        $originalEmail = $hrStaff->email;

        $response = $this->actingAs($admin)->put(route('admin.users.update', $hrStaff->id), [
            'name'  => 'Hacked_HR_Name',
            'email' => 'hacked_hr@example.com',
            'role'  => 'pemagang',
        ]);

        $response->assertRedirect(route('admin.hr-staff.index'));
        $response->assertSessionHas('error');

        // Pastikan atribut HR Staff tidak termutasi
        $hrStaff->refresh();
        $this->assertEquals($originalEmail, $hrStaff->email);
        $this->assertEquals(User::ROLE_HR_STAFF, $hrStaff->role);
    }

    /**
     * 5. Menghapus akun HR Staff via UserController ditolak dan diarahkan ke modul HR Staff.
     */
    public function test_destroying_hr_staff_via_generic_user_controller_is_rejected(): void
    {
        $adminPassword = 'adminSecretPassword123';
        $admin = $this->makeUser('admin', ['password' => Hash::make($adminPassword)]);
        $hrStaff = $this->makeUser(User::ROLE_HR_STAFF);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $hrStaff->id), [
            'admin_password' => $adminPassword,
        ]);

        $response->assertRedirect(route('admin.hr-staff.index'));
        $response->assertSessionHas('error');

        $this->assertFalse($hrStaff->fresh()->trashed());
    }

    /**
     * 6. Menonaktifkan (ban) atau mengaktifkan (unban) HR Staff via UserController ditolak.
     */
    public function test_banning_or_unbanning_hr_staff_via_generic_user_controller_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $hrStaff = $this->makeUser(User::ROLE_HR_STAFF, ['is_banned' => 0]);

        // Coba ban via UserController
        $banResponse = $this->actingAs($admin)->post(route('admin.users.ban', $hrStaff->id), [
            'ban_reason' => 'Tes ban HR',
        ]);
        $banResponse->assertRedirect(route('admin.hr-staff.index'));
        $banResponse->assertSessionHas('error');
        $this->assertFalse((bool) $hrStaff->fresh()->is_banned);

        // Coba unban via UserController
        $unbanResponse = $this->actingAs($admin)->post(route('admin.users.unban', $hrStaff->id));
        $unbanResponse->assertRedirect(route('admin.hr-staff.index'));
        $unbanResponse->assertSessionHas('error');
    }

    /**
     * 7. HrStaffController mencegah admin mengubah status akun miliknya sendiri jika sengaja ditargetkan.
     */
    public function test_hr_staff_controller_prevents_self_toggle_status(): void
    {
        // Jika akun admin ditargetkan ke toggleStatus
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.hr-staff.toggle-status', $admin->id));
        // Karena role bukan hr_staff, mengembalikan 404
        $response->assertStatus(404);
    }

    /**
     * 8. Daftar pengguna tidak menduplikasi baris user yang memiliki banyak histori pendaftaran.
     */
    public function test_user_list_does_not_duplicate_users_with_multiple_registrations(): void
    {
        $admin = $this->makeUser('admin');
        $multiUser = $this->makeUser('pemagang');

        // Buat 3 pendaftaran magang untuk user yang sama (multi-period)
        $reg1 = $this->createRegistration($multiUser, ['fullname' => 'Multi Period 2024']);
        $reg2 = $this->createRegistration($multiUser, ['fullname' => 'Multi Period 2025']);
        $reg3 = $this->createRegistration($multiUser, ['fullname' => 'Multi Period 2026 Latest']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'email' => $multiUser->email,
        ]));

        $response->assertStatus(200);

        // Ambil objek paginator yang dikirim ke view
        $paginator = $response->viewData('users');
        $matchingUsers = collect($paginator->items())->filter(fn($u) => $u->id === $multiUser->id);

        // Harus tepat 1 baris, tidak boleh duplikat 3 baris
        $this->assertCount(1, $matchingUsers);

        // Nama tampilan mencerminkan pendaftaran terbaru (display_name / latestOfMany)
        $this->assertEquals('Multi Period 2026 Latest', $matchingUsers->first()->display_name);

        // Identitas akun (username) tetap utuh pada kolom users.name
        $this->assertEquals($multiUser->name, $matchingUsers->first()->name);
    }

    /**
     * 9. Pencarian berdasarkan nama mencocokkan fullname pendaftaran maupun username pengguna.
     */
    public function test_user_list_search_by_name_matches_registration_fullname_or_username(): void
    {
        $admin = $this->makeUser('admin');

        $customUsername = 'special_user_' . Str::random(8);
        $customFullname = 'Sang Pemagang Legendaris ' . Str::random(6);

        $user = $this->makeUser('pemagang', ['name' => $customUsername]);
        $this->createRegistration($user, ['fullname' => $customFullname]);

        // Cari dengan fullname
        $responseByFullname = $this->actingAs($admin)->get(route('admin.users.index', [
            'name' => $customFullname,
        ]));
        $responseByFullname->assertStatus(200);
        $responseByFullname->assertSee($customFullname);

        // Cari dengan username
        $responseByUsername = $this->actingAs($admin)->get(route('admin.users.index', [
            'name' => $customUsername,
        ]));
        $responseByUsername->assertStatus(200);
        $responseByUsername->assertSee($customFullname);
    }

    /**
     * 10. Daftar pengguna menampilkan aksi tautan ke modul khusus untuk akun HR Staff.
     */
    public function test_user_list_shows_hr_staff_with_dedicated_manage_link(): void
    {
        $admin = $this->makeUser('admin');
        $hrStaff = $this->makeUser(User::ROLE_HR_STAFF);

        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'email' => $hrStaff->email,
        ]));

        $response->assertStatus(200);
        $response->assertSee(route('admin.hr-staff.edit', $hrStaff->id));
        $response->assertSee('HR Staff');
    }

    /**
     * 11. Kolom User::name tetap mempertahankan identitas akun (username) dan tidak bermutasi
     * menjadi registration fullname saat diakses pada model instance.
     */
    public function test_user_account_name_retains_identity_and_does_not_mutate_into_registration_fullname(): void
    {
        $uniqueUsername = 'identity_account_' . Str::random(8);
        $businessFullname = 'Nama Bisnis Pemagang ' . Str::random(6);

        $user = $this->makeUser('pemagang', ['name' => $uniqueUsername]);
        $this->createRegistration($user, ['fullname' => $businessFullname]);

        $freshUser = $user->fresh();

        // Identitas akun murni tetap merupakan username
        $this->assertEquals($uniqueUsername, $freshUser->name);

        // Accessor terpisah display_name menghasilkan nama bisnis dari registrasi
        $this->assertEquals($businessFullname, $freshUser->display_name);
        $this->assertEquals($businessFullname, $freshUser->latest_registration_name);
    }

    /**
     * 12. Pencarian berdasarkan nama menemukan user lewat histori registrasi terdahulu
     * tanpa menimbulkan duplikasi baris pada hasil paginasi.
     */
    public function test_user_list_search_by_name_matches_historical_registration_fullname_without_duplicates(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('pemagang');

        $historicalFullname = 'Riwayat Lama ' . Str::random(8);
        $latestFullname = 'Riwayat Terbaru ' . Str::random(8);

        $this->createRegistration($user, ['fullname' => $historicalFullname]);
        $this->createRegistration($user, ['fullname' => $latestFullname]);

        // Cari dengan nama lama (historical)
        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'name' => $historicalFullname,
        ]));

        $response->assertStatus(200);
        $paginator = $response->viewData('users');
        $matching = collect($paginator->items())->filter(fn($u) => $u->id === $user->id);

        // Tepat 1 baris, tidak boleh duplikat
        $this->assertCount(1, $matching);
        // Tampilan menampilkan pendaftaran terbaru
        $this->assertEquals($latestFullname, $matching->first()->display_name);
        $this->assertEquals($user->name, $matching->first()->name);
    }
}
