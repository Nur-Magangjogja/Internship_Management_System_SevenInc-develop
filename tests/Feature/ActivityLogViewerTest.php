<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ActivityLogViewerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('act_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'hr_' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);
    }

    public function test_admin_can_access_activity_logs_index(): void
    {
        ActivityLogger::log(
            action: 'test_action_' . $this->unique,
            description: 'Uji coba aktivitas log ' . $this->unique,
            actor: $this->admin
        );

        $response = $this->actingAs($this->admin)->get(route('admin.activity-logs.index'));

        $response->assertStatus(200);
        $response->assertSee('Log Aktivitas Sistem');
        $response->assertSee('test_action_' . $this->unique);
        $response->assertSee('Uji coba aktivitas log ' . $this->unique);
    }

    public function test_hr_staff_cannot_access_activity_logs_index(): void
    {
        $response = $this->actingAs($this->hrStaff)->get(route('admin.activity-logs.index'));

        // HR Staff tidak diizinkan masuk ke rute khusus admin
        $response->assertRedirect(route('hr.dashboard'));
    }

    public function test_pemagang_cannot_access_activity_logs_index(): void
    {
        $response = $this->actingAs($this->pemagang)->get(route('admin.activity-logs.index'));

        $response->assertRedirect(route('user.dashboard'));
    }

    public function test_filter_by_action(): void
    {
        $actA = 'action_alpha_' . $this->unique;
        $actB = 'action_beta_' . $this->unique;

        ActivityLogger::log(action: $actA, description: 'Alpha Desc ' . $this->unique, actor: $this->admin);
        ActivityLogger::log(action: $actB, description: 'Beta Desc ' . $this->unique, actor: $this->admin);

        $response = $this->actingAs($this->admin)->get(route('admin.activity-logs.index', ['action' => $actA]));

        $response->assertStatus(200);
        $response->assertViewHas('logs', function ($logs) use ($actA, $actB) {
            return $logs->contains('action', $actA) && !$logs->contains('action', $actB);
        });
    }

    public function test_filter_by_actor_role(): void
    {
        $hrAction = 'hr_specific_action_' . $this->unique;
        $adminAction = 'admin_specific_action_' . $this->unique;

        ActivityLogger::log(
            action: $hrAction,
            description: 'Dilakukan oleh HR ' . $this->unique,
            actor: $this->hrStaff
        );

        ActivityLogger::log(
            action: $adminAction,
            description: 'Dilakukan oleh Admin ' . $this->unique,
            actor: $this->admin
        );

        $response = $this->actingAs($this->admin)->get(route('admin.activity-logs.index', [
            'actor_role' => 'hr_staff',
            'search'     => $this->unique,
        ]));

        $response->assertStatus(200);
        $response->assertViewHas('logs', function ($logs) use ($hrAction, $adminAction) {
            return $logs->contains('action', $hrAction) && !$logs->contains('action', $adminAction);
        });
    }

    public function test_search_by_keyword(): void
    {
        $uniqueKeyword = 'KataKunciKhusus_' . $this->unique;

        ActivityLogger::log(
            action: 'keyword_action_' . $this->unique,
            description: "Mencatat aktivitas dengan {$uniqueKeyword} di deskripsi",
            actor: $this->admin
        );

        $response = $this->actingAs($this->admin)->get(route('admin.activity-logs.index', [
            'search' => $uniqueKeyword,
        ]));

        $response->assertStatus(200);
        $response->assertSee($uniqueKeyword);
    }

    public function test_admin_can_view_log_detail_via_json(): void
    {
        $log = ActivityLogger::log(
            action: 'json_action_' . $this->unique,
            description: 'Deskripsi untuk json view ' . $this->unique,
            before: ['status' => 'pending'],
            after: ['status' => 'approved'],
            actor: $this->admin
        );

        $response = $this->actingAs($this->admin)->getJson(route('admin.activity-logs.show', $log));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'public_uuid' => $log->public_uuid,
                'action'      => 'json_action_' . $this->unique,
                'actor_name'  => $this->admin->name,
                'actor_role'  => 'admin',
                'before_data' => ['status' => 'pending'],
                'after_data'  => ['status' => 'approved'],
            ],
        ]);
    }
}
