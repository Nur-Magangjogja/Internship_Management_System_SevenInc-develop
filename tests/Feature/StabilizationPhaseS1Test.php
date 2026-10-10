<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\AlumniMembercard;
use App\Models\InternshipRegistration;
use App\Models\Brand;
use App\Models\Division;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

class StabilizationPhaseS1Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role, array $attributes = []): User
    {
        $unique = uniqid('s1_test_', true);
        return User::create(array_merge([
            'name'      => 'user_' . substr($unique, 0, 15),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createRegistration(User $user, array $attributes = []): InternshipRegistration
    {
        $cityId = \App\Models\City::first()?->id ?? 1;
        $instId = \App\Models\Institution::first()?->id ?? 1;
        $facultyId = \App\Models\Faculty::first()?->id ?? 1;
        $studyProgId = \App\Models\StudyProgram::first()?->id ?? 1;
        $divId = \App\Models\Division::first()?->id ?? 1;

        return InternshipRegistration::create(array_merge([
            'user_id'                => $user->id,
            'fullname'               => $user->name,
            'born_date'              => '2001-01-01',
            'gender'                 => 'Laki-laki',
            'phone_number'           => '081234567890',
            'student_id'             => '12345678',
            'city_id'                => $cityId,
            'institution_id'         => $instId,
            'faculty_id'             => $facultyId,
            'study_program_id'       => $studyProgId,
            'internship_reason'      => 'Pengembangan skill',
            'internship_type'        => 'Magang Mandiri',
            'internship_arrangement' => 'Onsite',
            'current_status'         => 'Mahasiswa/Pelajar',
            'english_book_ability'   => 'Saya bisa',
            'division_id'            => $divId,
            'internship_status'      => InternshipRegistration::STATUS_COMPLETED,
        ], $attributes));
    }

    /**
     * 1. Admin login redirect: Admin yang sudah login mengakses /admin/login atau /login
     * diarahkan ke admin.dashboard.index tanpa RouteNotFoundException.
     */
    public function test_admin_redirect_to_dashboard(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->get('/admin/login');
        $response->assertRedirect(route('admin.dashboard.index'));

        $responseGuest = $this->actingAs($admin)->get('/login');
        $responseGuest->assertRedirect(route('admin.dashboard.index'));
    }

    /**
     * 2. Auth redirect untuk HR Staff dan Pemagang dari guest route (/login).
     */
    public function test_auth_redirect_for_hr_and_pemagang(): void
    {
        $hr = $this->makeUser('hr_staff');
        $responseHr = $this->actingAs($hr)->get('/login');
        $responseHr->assertRedirect(route('hr.dashboard'));

        $pemagang = $this->makeUser('pemagang');
        $responsePemagang = $this->actingAs($pemagang)->get('/login');
        $responsePemagang->assertRedirect('/pemagang/dashboard');
    }

    /**
     * 3. Middleware EnsureAccountIsActive: Sesi user yang di-ban langsung di-invalidate
     * pada request aktif berikutnya.
     */
    public function test_banned_user_session_is_invalidated_immediately(): void
    {
        $pemagang = $this->makeUser('pemagang');

        // Request pertama sebelum di-ban (aktif)
        $response = $this->actingAs($pemagang)->get(route('pemagang.dashboard'));
        $response->assertStatus(200);

        // Ban user di database
        $pemagang->update(['is_banned' => 1]);

        // Request berikutnya harus ditolak, session di-invalidate, dan logout
        $nextResponse = $this->actingAs($pemagang)->get(route('pemagang.dashboard'));
        $nextResponse->assertRedirect(route('user.login'));
        $this->assertGuest();
    }

    /**
     * 4. Middleware EnsureAccountIsActive: User yang di-soft-delete langsung di-logout.
     */
    public function test_soft_deleted_user_session_is_invalidated(): void
    {
        $pemagang = $this->makeUser('pemagang');

        $response = $this->actingAs($pemagang)->get(route('pemagang.dashboard'));
        $response->assertStatus(200);

        // Soft delete user
        $pemagang->delete();

        $nextResponse = $this->actingAs($pemagang)->get(route('pemagang.dashboard'));
        $nextResponse->assertRedirect(route('user.login'));
        $this->assertGuest();
    }

    /**
     * 5. Middleware EnsureAccountIsActive: Menghasilkan respon JSON 403 jika request meminta JSON.
     */
    public function test_banned_user_receives_403_json(): void
    {
        $user = $this->makeUser('pemagang', ['is_banned' => 1]);

        $response = $this->actingAs($user)->getJson(route('pemagang.dashboard'));
        $response->assertStatus(403);
        $this->assertGuest();
    }

    /**
     * 6. Route Security: Route /admin/loa/* strictly melarang pemagang biasa dan HR staff (admin only).
     */
    public function test_loa_admin_routes_strictly_deny_pemagang_and_hr(): void
    {
        $pemagang = $this->makeUser('pemagang');
        $hr = $this->makeUser('hr_staff');
        $admin = $this->makeUser('admin');

        // Pemagang ditolak (web redirect ke user.dashboard, json 403)
        $this->actingAs($pemagang)->get(route('admin.loa.interns'))
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('error');
        $this->actingAs($pemagang)->getJson(route('admin.loa.interns'))
            ->assertStatus(403);
        $this->actingAs($pemagang)->postJson(route('admin.loa.generate'))
            ->assertStatus(403);

        // HR Staff ditolak dari admin LOA (admin-only)
        $this->actingAs($hr)->getJson(route('admin.loa.interns'))
            ->assertStatus(403);
        $this->actingAs($hr)->postJson(route('admin.loa.generate'))
            ->assertStatus(403);

        // Admin diizinkan ke daftar dokumen LOA
        $this->actingAs($admin)->get(route('admin.documents.loas'))->assertStatus(200);

        // HR Staff dialihkan secara aman jika mencoba membuka daftar dokumen eksklusif admin
        $this->actingAs($hr)->get(route('admin.documents.loas'))->assertRedirect(route('hr.dashboard'));
    }

    /**
     * 7. Route /register dilindungi middleware guest (user yang sudah login tidak bisa register baru).
     */
    public function test_authenticated_user_cannot_register_again(): void
    {
        $pemagang = $this->makeUser('pemagang');

        $this->actingAs($pemagang)->get(route('user.register'))
            ->assertRedirect('/pemagang/dashboard');

        $this->actingAs($pemagang)->post(route('user.register.submit'), [
            'name'                  => 'Another User',
            'email'                 => 'another@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/pemagang/dashboard');
    }

    /**
     * 8. Endpoint /log-download strictly pemagang-only, memvalidasi kode, fail-closed, dan ownership.
     */
    public function test_log_download_endpoint_validation_and_ownership(): void
    {
        // 1. Guest ditolak dengan 401
        $responseGuest = $this->postJson(route('log.download'), ['id' => 'ANY_CODE']);
        $responseGuest->assertStatus(401);

        // 2. Role hr_staff ditolak dengan 403
        $hr = $this->makeUser('hr_staff');
        $this->actingAs($hr)->postJson(route('log.download'), ['id' => 'ANY_CODE'])->assertStatus(403);

        // 3. Role user reguler ditolak dengan 403
        $regularUser = $this->makeUser('user');
        $this->actingAs($regularUser)->postJson(route('log.download'), ['id' => 'ANY_CODE'])->assertStatus(403);

        // Siapkan user A dan user B (keduanya pemagang)
        $userA = $this->makeUser('pemagang');
        $userB = $this->makeUser('pemagang');

        // 4. Pemagang memanggil dengan kode tidak ada -> 404
        $response404 = $this->actingAs($userA)->postJson(route('log.download'), ['id' => 'NONEXISTENT_CODE']);
        $response404->assertStatus(404);

        // Buat registration untuk user A
        $regA = $this->createRegistration($userA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $cardA = AlumniMembercard::create([
            'intern_id'      => $regA->id,
            'member_code'    => 'TEST_CARD_A_' . uniqid(),
            'batch_year'     => '2026',
            'has_downloaded' => false,
        ]);

        // 5. User B mencoba log download card milik User A -> 403 Forbidden (IDOR prevention)
        $responseForbidden = $this->actingAs($userB)->postJson(route('log.download'), [
            'id' => $cardA->member_code,
        ]);
        $responseForbidden->assertStatus(403);

        // 6. User A memanggil endpoint log download card miliknya sendiri -> 200 OK
        $responseOk = $this->actingAs($userA)->postJson(route('log.download'), [
            'id' => $cardA->member_code,
        ]);
        $responseOk->assertStatus(200);

        // Verifikasi database has_downloaded berubah menjadi true
        $this->assertTrue((bool)$cardA->fresh()->has_downloaded);
    }

    /**
     * 9. Resource institutions tidak meng-expose route destroy (menghindari arbitrary drop).
     */
    public function test_institutions_destroy_route_is_not_exposed(): void
    {
        $this->assertFalse(Route::has('institutions.destroy'));
    }

    /**
     * 10. HR Staff tidak memiliki rute DELETE pada institution-contacts.
     */
    public function test_hr_cannot_delete_institution_contacts(): void
    {
        $this->assertFalse(Route::has('hr.institution-contacts.destroy'));
    }

    /**
     * 11. DELETE /admin/interns/{intern} strictly menolak non-admin (guest, pemagang, hr_staff).
     * Sesuai guardrail S1: HANYA menguji negative authorization, TIDAK menjalankan hard delete.
     */
    public function test_admin_interns_destroy_denies_non_admin(): void
    {
        $pemagang = $this->makeUser('pemagang');
        $hr = $this->makeUser('hr_staff');

        // Guest ditolak (401)
        $this->deleteJson('/admin/interns/999999')->assertStatus(401);

        // Pemagang ditolak (403)
        $this->actingAs($pemagang)->deleteJson('/admin/interns/999999')->assertStatus(403);

        // HR Staff ditolak (403)
        $this->actingAs($hr)->deleteJson('/admin/interns/999999')->assertStatus(403);
    }

    /**
     * 12. Granular authorization pada route grup /pemagang/*.
     * - Role 'user' diizinkan pendaftaran & pengaturan akun.
     * - Role 'user' ditolak dari dashboard, dokumen, membercard, webinar (khusus role 'pemagang').
     */
    public function test_pemagang_routes_granular_authorization(): void
    {
        $regularUser = $this->makeUser('user');
        $pemagang = $this->makeUser('pemagang');

        // User reguler boleh buka form pendaftaran & pengaturan
        $this->actingAs($regularUser)->get(route('pemagang.registration.form'))->assertStatus(200);
        $this->actingAs($regularUser)->get(route('pemagang.settings'))->assertStatus(200);

        // User reguler ditolak dari fitur modul pemagang aktif
        $this->actingAs($regularUser)->getJson(route('pemagang.dashboard'))->assertStatus(403);
        $this->actingAs($regularUser)->getJson(route('pemagang.documents'))->assertStatus(403);
        $this->actingAs($regularUser)->getJson(route('pemagang.membercard'))->assertStatus(403);
        $this->actingAs($regularUser)->getJson(route('pemagang.webinar.index'))->assertStatus(403);

        // Pemagang diizinkan mengakses dashboard
        $this->actingAs($pemagang)->get(route('pemagang.dashboard'))->assertStatus(200);
    }

    /**
     * 13. Null safety pada daily report, leave request, pending tasks jika user belum memiliki registration.
     */
    public function test_user_daily_report_leave_task_null_safety(): void
    {
        // Pemagang tanpa pendaftaran magang
        $pemagangWithoutReg = $this->makeUser('pemagang');

        // Akses GET diarahkan dengan aman ke dashboard tanpa error 500
        $this->actingAs($pemagangWithoutReg)->get(route('user.dailyReport'))
            ->assertRedirect(route('pemagang.dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($pemagangWithoutReg)->get(route('user.leaveRequest'))
            ->assertRedirect(route('pemagang.dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($pemagangWithoutReg)->get(route('user.pendingTasks'))
            ->assertRedirect(route('pemagang.dashboard'))
            ->assertSessionHas('error');

        // POST store diarahkan kembali dengan pesan error tanpa throw 500
        $this->actingAs($pemagangWithoutReg)->post(route('user.storeDailyReport'), [
            'date'       => '2026-09-29',
            'activities' => 'Kegiatan pengujian',
            'challenges' => 'Tidak ada',
        ])->assertSessionHas('error');
    }

    /**
     * 14. Route /user/loa/generate enforce pemagang dan ownership.
     */
    public function test_user_loa_generate_enforces_pemagang_and_ownership(): void
    {
        $hr = $this->makeUser('hr_staff');
        $userA = $this->makeUser('pemagang');
        $userB = $this->makeUser('pemagang');

        $regA = $this->createRegistration($userA, [
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        // HR staff memanggil user loa generate -> ditolak 403
        $this->actingAs($hr)->postJson(route('user.loa.generate'), [
            'intern_id' => $regA->id,
        ])->assertStatus(403);

        // User B mencoba generate LOA milik User A -> ditolak 403
        $this->actingAs($userB)->postJson(route('user.loa.generate'), [
            'intern_id' => $regA->id,
        ])->assertStatus(403);
    }

    /**
     * 15. Route caching berhasil tanpa error closure/serialization dan dibersihkan kembali setelahnya.
     */
    public function test_route_cache_succeeds_and_is_cleaned_up(): void
    {
        try {
            $exitCode = \Illuminate\Support\Facades\Artisan::call('route:cache');
            $this->assertSame(0, $exitCode, 'Artisan route:cache harus keluar dengan exit code 0');
        } finally {
            \Illuminate\Support\Facades\Artisan::call('route:clear');
        }
    }
}
