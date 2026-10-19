<?php

namespace Tests\Feature\HR;

// Ensure worktree app directory is loaded even when vendor is shared with develop
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
}, true, true);

// Explicitly require worktree controllers
require_once dirname(__DIR__, 3) . '/app/Http/Controllers/Admin/InstitutionController.php';
require_once dirname(__DIR__, 3) . '/app/Http/Controllers/HR/FollowUpController.php';
require_once dirname(__DIR__, 3) . '/app/Http/Controllers/HR/DashboardController.php';
require_once dirname(__DIR__, 3) . '/app/Http/Controllers/HR/MouController.php';

use App\Models\ActivityLog;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\User;
use App\Services\InstitutionFollowUpWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InstitutionFollowUpWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    private User $admin;
    private User $hrStaff;
    private User $hrStaff2;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        // Ensure views are resolved from worktree resources/views
        $worktreePath = dirname(__DIR__, 3);
        $this->app['config']->set('view.paths', [$worktreePath . '/resources/views']);
        $this->app['view.finder']->setPaths([$worktreePath . '/resources/views']);

        $this->unique = uniqid('wf_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'hr1_' . substr($this->unique, 0, 8),
            'email'    => 'hr1_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->hrStaff2 = User::create([
            'name'     => 'hr2_' . substr($this->unique, 0, 8),
            'email'    => 'hr2_' . $this->unique . '@test.com',
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

    private function createInstitutionWithContact(): array
    {
        $institution = Institution::create([
            'name'      => 'Universitas Kemitraan ' . $this->unique,
            'is_active' => true,
            'email'     => 'partner_' . $this->unique . '@kampus.ac.id',
            'phone'     => '021-778899',
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Prof. Dr. Hendra ' . $this->unique,
            'position_title' => 'Dekan Fakultas Vokasi',
            'phone'          => '081234567890',
            'email'          => 'hendra_' . $this->unique . '@kampus.ac.id',
            'is_verified'    => true,
            'is_active'      => true,
        ]);

        return [$institution, $contact];
    }

    private function lockCycleFor(FollowUpCycle $cycle, User $user): void
    {
        FollowUpCycle::where('id', $cycle->id)->update([
            'locked_by'       => $user->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(20),
        ]);
        $cycle->refresh();
    }

    /**
     * 1. Workflow service maps all 5 workflow stages properly.
     */
    public function test_service_resolves_all_five_workflow_stages_correctly(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();
        $service = app(InstitutionFollowUpWorkflowService::class);

        // Stage 1: Belum Dihubungi (open, 0 attempts)
        $cycle1 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);
        $wf1 = $service->resolveCycleWorkflow($cycle1);
        $this->assertEquals(InstitutionFollowUpWorkflowService::STAGE_NOT_CONTACTED, $wf1['stage']);
        $this->assertEquals('Belum Dihubungi', $wf1['label']);

        // Stage 2: Menunggu Respons (status in_progress, attempt with no response yet)
        $cycle2 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2025,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'current_attempt_count'  => 1,
        ]);
        FollowUpAttempt::create([
            'followup_cycle_id'      => $cycle2->id,
            'institution_contact_id' => $contact->id,
            'attempt_number'         => 1,
            'performed_by'           => $this->hrStaff->id,
            'followed_up_at'         => now()->subDay(),
            'channel'                => 'whatsapp',
            'result'                 => 'no_response',
            'notes'                  => 'Menunggu konfirmasi balasan',
        ]);
        $wf2 = $service->resolveCycleWorkflow($cycle2);
        $this->assertEquals(InstitutionFollowUpWorkflowService::STAGE_WAITING_RESPONSE, $wf2['stage']);
        $this->assertEquals('Menunggu Respons', $wf2['label']);

        // Stage 3: Perlu Tindak Lanjut (status in_progress with response or next_followup_at)
        $cycle3 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2024,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'current_attempt_count'  => 2,
        ]);
        FollowUpAttempt::create([
            'followup_cycle_id'      => $cycle3->id,
            'institution_contact_id' => $contact->id,
            'attempt_number'         => 2,
            'performed_by'           => $this->hrStaff->id,
            'followed_up_at'         => now()->subDay(),
            'channel'                => 'phone',
            'result'                 => 'call_back',
            'next_followup_at'       => Carbon::tomorrow()->format('Y-m-d'),
        ]);
        $wf3 = $service->resolveCycleWorkflow($cycle3);
        $this->assertEquals(InstitutionFollowUpWorkflowService::STAGE_NEEDS_FOLLOW_UP, $wf3['stage']);
        $this->assertEquals('Perlu Tindak Lanjut', $wf3['label']);

        // Stage 4: Bersedia Bekerja Sama (status completed, final_result = willing)
        $cycle4 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2023,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => 'willing',
            'confirmed_by'           => $this->hrStaff->id,
            'confirmed_at'           => now(),
        ]);
        $wf4 = $service->resolveCycleWorkflow($cycle4);
        $this->assertEquals(InstitutionFollowUpWorkflowService::STAGE_WILLING, $wf4['stage']);
        $this->assertEquals('Bersedia Bekerja Sama', $wf4['label']);
        $this->assertEquals('create_mou', $wf4['next_action']);

        // Stage 5: Selesai (status completed, final_result = rejected or no_response)
        $cycle5 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2022,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => 'rejected',
            'confirmed_by'           => $this->hrStaff->id,
            'confirmed_at'           => now(),
        ]);
        $wf5 = $service->resolveCycleWorkflow($cycle5);
        $this->assertEquals(InstitutionFollowUpWorkflowService::STAGE_COMPLETED, $wf5['stage']);
        $this->assertEquals('Selesai', $wf5['label']);
    }

    /**
     * 2. Overdue and due today detection and query scopes.
     */
    public function test_overdue_and_due_today_detection_and_query_scopes(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();
        $service = app(InstitutionFollowUpWorkflowService::class);

        // Overdue cycle: in_progress with next_followup_at yesterday
        $overdueCycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'current_attempt_count'  => 1,
        ]);
        FollowUpAttempt::create([
            'followup_cycle_id'      => $overdueCycle->id,
            'institution_contact_id' => $contact->id,
            'attempt_number'         => 1,
            'performed_by'           => $this->hrStaff->id,
            'followed_up_at'         => now()->subDays(3),
            'channel'                => 'phone',
            'result'                 => 'responded',
            'next_followup_at'       => Carbon::yesterday()->format('Y-m-d'),
        ]);

        $wfOverdue = $service->resolveCycleWorkflow($overdueCycle);
        $this->assertTrue($wfOverdue['is_overdue']);
        $this->assertFalse($wfOverdue['is_due_today']);
        $this->assertStringContainsString('Lewat Jadwal', $wfOverdue['due_label']);

        // Scope test: Overdue
        $overdueList = FollowUpCycle::overdue()->pluck('id')->toArray();
        $this->assertContains($overdueCycle->id, $overdueList);

        // Due today cycle: in_progress with next_followup_at today
        $dueTodayCycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2025,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'current_attempt_count'  => 1,
        ]);
        FollowUpAttempt::create([
            'followup_cycle_id'      => $dueTodayCycle->id,
            'institution_contact_id' => $contact->id,
            'attempt_number'         => 1,
            'performed_by'           => $this->hrStaff->id,
            'followed_up_at'         => now()->subDay(),
            'channel'                => 'email',
            'result'                 => 'no_response',
            'next_followup_at'       => Carbon::today()->format('Y-m-d'),
        ]);

        $wfDueToday = $service->resolveCycleWorkflow($dueTodayCycle);
        $this->assertFalse($wfDueToday['is_overdue']);
        $this->assertTrue($wfDueToday['is_due_today']);
        $this->assertStringContainsString('Hari Ini', $wfDueToday['due_label']);

        // Scope test: Due Today
        $dueTodayList = FollowUpCycle::dueToday()->pluck('id')->toArray();
        $this->assertContains($dueTodayCycle->id, $dueTodayList);
    }

    /**
     * 3. Anti-duplicate attempt detection prevents double submits within 30s window.
     */
    public function test_anti_duplicate_attempt_prevention(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);
        $this->lockCycleFor($cycle, $this->hrStaff);

        // First attempt via HTTP POST
        $response1 = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at'         => now()->format('Y-m-d H:i:s'),
            'institution_contact_id' => $contact->id,
            'channel'                => 'whatsapp',
            'result'                 => 'responded',
            'response_text'          => 'Dosen menyambut baik program magang.',
            'notes'                  => 'Koordinasi silabus kurikulum magang.',
        ]);
        $response1->assertRedirect();
        $response1->assertSessionHas('success');

        $this->assertEquals(1, FollowUpAttempt::where('followup_cycle_id', $cycle->id)->count());

        // Rapid second identical attempt within 30 seconds
        $response2 = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at'         => now()->format('Y-m-d H:i:s'),
            'institution_contact_id' => $contact->id,
            'channel'                => 'whatsapp',
            'result'                 => 'responded',
            'response_text'          => 'Dosen menyambut baik program magang.',
            'notes'                  => 'Koordinasi silabus kurikulum magang.',
        ]);
        $response2->assertRedirect();
        $response2->assertSessionHas('info');

        // Verify count remains 1 (no duplicate record created)
        $this->assertEquals(1, FollowUpAttempt::where('followup_cycle_id', $cycle->id)->count());
    }

    /**
     * 4. Institution and Contact models provide convenient workflow accessors.
     */
    public function test_institution_and_contact_workflow_accessors(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();

        // Contact has no cycle initially
        $initialStatus = $contact->follow_up_status_info;
        $this->assertFalse($initialStatus['has_cycle']);
        $this->assertEquals('Belum Dihubungi', $initialStatus['label']);
        $this->assertEquals('Mulai Follow-Up', $initialStatus['cta_label']);

        // Create cycle for contact
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);
        $contact->unsetRelation('currentYearCycle');
        $contact->refresh();

        $updatedStatus = $contact->follow_up_status_info;
        $this->assertTrue($updatedStatus['has_cycle']);
        $this->assertStringContainsString('Attempt 1', $updatedStatus['cta_label']);

        // Institution cooperation summary
        $summary = $institution->cooperation_summary;
        $this->assertIsArray($summary);
        $this->assertFalse($summary['has_active_mou']);
        $this->assertFalse($summary['is_willing_for_mou']);
    }

    /**
     * 5. HR Dashboard displays 5 workflow metrics and urgent queues.
     */
    public function test_hr_dashboard_displays_workflow_metrics_and_queues(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();

        // Create an overdue cycle
        $overdueCycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
        ]);
        FollowUpAttempt::create([
            'followup_cycle_id'      => $overdueCycle->id,
            'institution_contact_id' => $contact->id,
            'attempt_number'         => 1,
            'performed_by'           => $this->hrStaff->id,
            'followed_up_at'         => now()->subDays(2),
            'channel'                => 'phone',
            'result'                 => 'responded',
            'next_followup_at'       => Carbon::yesterday()->format('Y-m-d'),
        ]);

        $contact2 = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dra. Maya ' . $this->unique,
            'position_title' => 'Kaprodi Vokasi',
            'phone'          => '081298765432',
            'email'          => 'maya_' . $this->unique . '@kampus.ac.id',
            'is_verified'    => true,
            'is_active'      => true,
        ]);

        // Create a willing cycle
        $willingCycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact2->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => 'willing',
            'confirmed_by'           => $this->hrStaff->id,
            'confirmed_at'           => now(),
        ]);

        $response = $this->actingAs($this->hrStaff)->get(route('hr.dashboard'));
        $response->assertOk();
        $response->assertSee('Alur Follow-Up Institusi');
        $response->assertSee('Perlu Tindak Lanjut');
        $response->assertSee('Bersedia Bekerja Sama');
        $response->assertSee('Perlu Ditindaklanjuti &amp; Lewat Jadwal', false);
        $response->assertSee('Mitra Bersedia Bekerja Sama');
        $response->assertSee('Buat Draft MOU');
    }

    /**
     * 6. Follow-up index filtering by workflow stage and overdue status.
     */
    public function test_hr_follow_up_index_filtering(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();

        $contact2 = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dra. Maya ' . $this->unique,
            'position_title' => 'Kaprodi Vokasi',
            'phone'          => '081298765432',
            'email'          => 'maya_' . $this->unique . '@kampus.ac.id',
            'is_verified'    => true,
            'is_active'      => true,
        ]);

        $openCycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $willingCycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact2->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => 'willing',
        ]);

        // Filter by willing stage
        $resWilling = $this->actingAs($this->hrStaff)->get(route('hr.follow-up.index', ['stage' => 'willing']));
        $resWilling->assertOk();
        $resWilling->assertSee('Bersedia Bekerja Sama');

        // Filter by not_contacted stage
        $resOpen = $this->actingAs($this->hrStaff)->get(route('hr.follow-up.index', ['stage' => 'not_contacted']));
        $resOpen->assertOk();
        $resOpen->assertSee('Belum Dihubungi');
    }

    /**
     * 7. MoU draft creation pre-fills contact details from willing follow-up cycle.
     */
    public function test_mou_draft_creation_prefills_contact_signatory(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();

        $template = MouTemplate::create([
            'name'                 => 'Template Standar ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<h1>MOU [company_name] & [institution_name]</h1><p>Nomor: [company_mou_number]</p>',
            'allowed_placeholders' => ['company_name', 'institution_name', 'company_mou_number'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $willingCycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => 'willing',
            'confirmed_by'           => $this->hrStaff->id,
            'confirmed_at'           => now(),
        ]);

        $response = $this->actingAs($this->hrStaff)->get(route('hr.mous.create', [
            'institution_id'  => $institution->id,
            'cycle_id'        => $willingCycle->id,
            'contact_id'      => $contact->id,
            'mou_template_id' => $template->id,
        ]));

        $response->assertOk();
        $response->assertSee($contact->name);
        $response->assertSee($contact->position_title);
        $response->assertSee('fillSignatory');
    }

    /**
     * 8. Institution detail view renders cooperation status, MoU table, and cycle history.
     */
    public function test_institution_show_view_renders_mous_and_cycle_history(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => 'willing',
            'confirmed_by'           => $this->hrStaff->id,
            'confirmed_at'           => now(),
        ]);

        $mou = Mou::create([
            'institution_id'                  => $institution->id,
            'followup_cycle_id'               => $cycle->id,
            'company_mou_number'              => 'MOU/7INC/' . $this->unique . '/2026',
            'institution_mou_number'          => '001/TEST/2026',
            'agreement_place'                 => 'Yogyakarta',
            'agreement_date'                  => '2026-09-28',
            'effective_start'                 => '2026-09-28',
            'effective_end'                   => '2028-09-28',
            'first_party_institution_name'    => $institution->name,
            'first_party_signatory_name'      => $contact->name,
            'first_party_signatory_title'     => $contact->position_title,
            'second_party_company_name'       => 'SEVEN INC',
            'second_party_signatory_name'     => 'Rekso Pamungkas',
            'second_party_signatory_title'    => 'Direktur Utama',
            'status'                          => 'active',
            'generated_by'                    => $this->admin->id,
        ]);

        // View as Admin
        $resAdmin = $this->actingAs($this->admin)->get(route('admin.institutions.show', $institution));
        $resAdmin->assertOk();
        $resAdmin->assertSee('Dokumen Kerja Sama (MoU &amp; PKS)', false);
        $resAdmin->assertSee($mou->company_mou_number);
        $resAdmin->assertSee('Siklus Follow-Up &amp; Penjajakan Kemitraan', false);
        $resAdmin->assertSee('Follow-Up ' . date('Y'));

        // View as HR Staff
        $resHr = $this->actingAs($this->hrStaff)->get(route('hr.institutions.show', $institution));
        $resHr->assertOk();
        $resHr->assertSee($mou->company_mou_number);
    }

    /**
     * 9. Role permissions: HR Staff cannot force-release another user's lock; Pemagang cannot access.
     */
    public function test_role_permissions_and_lock_protection(): void
    {
        [$institution, $contact] = $this->createInstitutionWithContact();

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);
        $this->lockCycleFor($cycle, $this->hrStaff);

        // HR 2 cannot record attempt when locked by HR 1
        $resHr2Attempt = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at'         => now()->format('Y-m-d H:i:s'),
            'institution_contact_id' => $contact->id,
            'channel'                => 'phone',
            'result'                 => 'responded',
        ]);
        $resHr2Attempt->assertRedirect();
        $resHr2Attempt->assertSessionHas('error');

        // HR 2 cannot force-release HR 1's lock (route is admin-only, hr gets 403 or redirect)
        $resHr2Force = $this->actingAs($this->hrStaff2)->post(route('admin.follow-up.force-release', $cycle));
        $this->assertTrue(in_array($resHr2Force->status(), [403, 302]));

        // Admin CAN force-release lock
        $resAdminForce = $this->actingAs($this->admin)->post(route('admin.follow-up.force-release', $cycle));
        $resAdminForce->assertRedirect();
        $resAdminForce->assertSessionHas('success');
        $cycle->refresh();
        $this->assertNull($cycle->locked_by);

        // Pemagang cannot access follow-up index (redirects to user dashboard)
        $resPemagang = $this->actingAs($this->pemagang)->get(route('hr.follow-up.index'));
        $resPemagang->assertRedirect(route('user.dashboard'));
    }
}
