<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Models\AbsenSyncState;
use App\Services\AbsenDjuraganSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AbsenDjuraganSyncServiceFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL & ~E_DEPRECATED);
    }

    protected function createInternHelper(array $overrides = []): InternshipRegistration
    {
        $user = User::create([
            'name'     => 'Intern ' . uniqid(),
            'email'    => 'intern_' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'role'     => 'pemagang',
        ]);

        $division = Division::firstOrCreate(
            ['name' => 'Programmer'],
            ['slug' => 'programmer', 'is_active' => true]
        );

        $brand = Brand::firstOrCreate(
            ['name' => 'Seven Inc'],
            ['code' => 'SI', 'is_active' => true]
        );

        $inst = Institution::firstOrCreate(
            ['name' => 'Universitas Gadjah Mada'],
            ['normalized_name' => 'universitas gadjah mada', 'is_active' => true]
        );
        $fac  = Faculty::firstOrCreate(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = StudyProgram::firstOrCreate(['name' => 'Teknologi Informasi', 'faculty_id' => $fac->id]);

        $defaultData = [
            'user_id'           => $user->id,
            'fullname'          => 'Unit Test Intern ' . uniqid(),
            'phone_number'      => '08123456789',
            'gender'            => 'Laki-laki',
            'born_date'         => '2002-01-01',
            'birth_place'       => 'Sleman',
            'religion'          => 'Islam',
            'student_id'        => 'NIM' . rand(1000, 9999),
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'division_id'       => $division->id,
            'brand_id'          => $brand->id,
            'internship_status' => 'waiting',
            'start_date'        => '2026-11-01',
            'end_date'          => '2027-04-30',
        ];

        return InternshipRegistration::create(array_merge($defaultData, $overrides));
    }

    public function test_sync_intern_sends_v2_payload_with_status_and_persists_state()
    {
        $intern = $this->createInternHelper(['internship_status' => 'accepted']);
        $user = $intern->user;

        Http::fake([
            '*/register-sync' => Http::response([
                'success' => true,
                'message' => 'Akun berhasil disinkronkan ke Absen Djuragan.',
                'data'    => [
                    'absen_user_id' => 777,
                    'intern_id'     => 888,
                    'email'         => $user->email,
                    'is_active'     => true,
                ],
            ], 201),
        ]);

        $result = AbsenDjuraganSyncService::syncIntern($intern, 'Seven Inc', 'accepted');

        $this->assertTrue($result);

        // Verify HTTP request received status
        Http::assertSent(function ($request) use ($user) {
            if (!str_contains($request->url(), 'register-sync')) {
                return false;
            }
            $data = $request->data();
            return ($data['email'] ?? null) === $user->email
                && ($data['status'] ?? null) === 'accepted'
                && ($data['brand_name'] ?? null) === 'Seven Inc'
                && ($data['division_name'] ?? null) === 'Programmer';
        });

        // Test status exited
        Http::fake([
            '*/register-sync' => Http::response([
                'success' => true,
                'message' => 'Status pemagang dinonaktifkan di Absen Djuragan.',
                'data'    => [
                    'absen_user_id' => 777,
                    'intern_id'     => 888,
                    'email'         => $user->email,
                    'is_active'     => false,
                ],
            ], 200),
        ]);

        $intern->internship_status = 'exited';
        $intern->save();

        $resultExited = AbsenDjuraganSyncService::syncIntern($intern, 'Seven Inc', 'exited');
        $this->assertTrue($resultExited);

        $stateExited = AbsenSyncState::where('registration_id', $intern->id)->first();
        $this->assertEquals(AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED, $stateExited->sync_status);
    }

    public function test_single_acc_via_controller_triggers_sync()
    {
        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin_single_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $intern = $this->createInternHelper(['internship_status' => 'waiting']);
        $user = $intern->user;

        Http::fake([
            '*/register-sync' => Http::response([
                'success' => true,
                'message' => 'Akun berhasil disinkronkan ke Absen Djuragan.',
                'data'    => [
                    'absen_user_id' => 999,
                    'intern_id'     => 888,
                    'email'         => $user->email,
                    'is_active'     => true,
                ],
            ], 201),
        ]);

        $response = $this->actingAs($admin)
            ->patchJson(route('admin.interns.status.update', $intern->id), [
                'internship_status' => 'accepted',
                'brand'             => 'Seven Inc',
            ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request) use ($user) {
            if (!str_contains($request->url(), 'register-sync')) {
                return false;
            }
            $data = $request->data();
            return ($data['email'] ?? null) === $user->email
                && ($data['status'] ?? null) === 'accepted'
                && ($data['brand_name'] ?? null) === 'Seven Inc';
        });
    }

    public function test_bulk_acc_via_controller_triggers_sync()
    {
        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin_bulk_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $intern1 = $this->createInternHelper(['internship_status' => 'waiting']);
        $intern2 = $this->createInternHelper(['internship_status' => 'waiting']);

        Http::fake([
            '*/register-sync' => Http::response([
                'success' => true,
                'message' => 'Synced',
                'data'    => ['absen_user_id' => 101, 'intern_id' => 201, 'is_active' => true],
            ], 201),
        ]);

        $response = $this->actingAs($admin)
            ->patchJson(route('admin.interns.status.bulk'), [
                'ids'               => [$intern1->id, $intern2->id],
                'internship_status' => 'accepted',
            ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request) use ($intern1) {
            if (!str_contains($request->url(), 'register-sync')) {
                return false;
            }
            $data = $request->data();
            return ($data['email'] ?? null) === $intern1->user->email && ($data['status'] ?? null) === 'accepted';
        });
        Http::assertSent(function ($request) use ($intern2) {
            if (!str_contains($request->url(), 'register-sync')) {
                return false;
            }
            $data = $request->data();
            return ($data['email'] ?? null) === $intern2->user->email && ($data['status'] ?? null) === 'accepted';
        });
    }

    public function test_update_active_intern_dates_triggers_sync()
    {
        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin_edit_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $intern = $this->createInternHelper([
            'internship_status' => 'active',
            'start_date'        => '2026-11-01',
            'end_date'          => '2027-04-30',
        ]);
        $user = $intern->user;

        Http::fake([
            '*/register-sync' => Http::response([
                'success' => true,
                'message' => 'Updated in Absen',
                'data'    => ['absen_user_id' => 101, 'intern_id' => 201, 'is_active' => true],
            ], 200),
        ]);

        $response = $this->actingAs($admin)
            ->patch(route('admin.interns.update', $intern->id), [
                'fullname'   => $intern->fullname,
                'student_id' => $intern->student_id,
                'email'      => $user->email,
                'start_date' => '2026-12-01',
                'end_date'   => '2027-05-31',
            ]);

        $response->assertSessionHasNoErrors();

        Http::assertSent(function ($request) use ($user) {
            if (!str_contains($request->url(), 'register-sync')) {
                return false;
            }
            $data = $request->data();
            return ($data['email'] ?? null) === $user->email
                && ($data['start_date'] ?? null) === '2026-12-01'
                && ($data['end_date'] ?? null) === '2027-05-31';
        });
    }

    public function test_update_status_exited_triggers_sync_deactivation()
    {
        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin_exit_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $intern = $this->createInternHelper(['internship_status' => 'active']);
        $user = $intern->user;

        Http::fake([
            '*/register-sync' => Http::response([
                'success' => true,
                'message' => 'Deactivated in Absen',
                'data'    => ['absen_user_id' => 101, 'intern_id' => 201, 'is_active' => false],
            ], 200),
        ]);

        $response = $this->actingAs($admin)
            ->patchJson(route('admin.interns.status.update', $intern->id), [
                'internship_status' => 'exited',
                'reason'            => 'Selesai masa magang lebih awal',
            ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request) use ($user) {
            if (!str_contains($request->url(), 'register-sync')) {
                return false;
            }
            $data = $request->data();
            return ($data['email'] ?? null) === $user->email
                && ($data['status'] ?? null) === 'exited';
        });
    }
}
