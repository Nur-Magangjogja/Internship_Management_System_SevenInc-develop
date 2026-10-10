<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\City;
use App\Models\DailyReport;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternCertificate;
use App\Models\InternExtra;
use App\Models\InternLoa;
use App\Models\InternshipRegistration as IR;
use App\Models\LeaveRequest;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\PendingTask;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\FollowUpService;
use App\Services\MouService;
use App\Services\RegistrationLifecycleService;
use Database\Seeders\MouTemplateSeeder;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetConnectionSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalAcceptancePhaseL1Test extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $hrStaff;
    protected User $pemagang;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpreadsheetConnectionSeeder::class);
        $this->seed(Spreadsheet2026SchemaSeeder::class);
        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);
        $this->seed(MouTemplateSeeder::class);

        $this->unique = uniqid('l1_', true);

        $this->admin = User::create([
            'name'      => 'Admin L1 ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@example.com',
            'role'      => 'admin',
            'password'  => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR Staff L1 ' . substr($this->unique, 0, 6),
            'email'     => 'hr_' . $this->unique . '@example.com',
            'role'      => 'hr_staff',
            'password'  => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang L1 ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@example.com',
            'role'      => 'pemagang',
            'password'  => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
    }

    /**
     * Helper to create valid master relations.
     */
    protected function createMasterRelations(): array
    {
        $inst = Institution::create([
            'name'            => 'Universitas L1 ' . substr($this->unique, 0, 6),
            'normalized_name' => 'universitas l1 ' . substr($this->unique, 0, 6),
            'type'            => 'Perguruan Tinggi',
            'status'          => 'active',
        ]);

        $fac = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Teknik ' . substr($this->unique, 0, 6),
        ]);

        $prog = StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'Teknik Informatika ' . substr($this->unique, 0, 6),
        ]);

        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);

        $brand = Brand::first() ?? Brand::create([
            'name' => 'Seven Inc',
            'code' => 'SI',
        ]);

        $div = Division::first() ?? Division::create([
            'name' => 'Web Development',
            'code' => 'WEB',
            'slug' => 'web-dev-' . $this->unique,
        ]);

        return compact('inst', 'fac', 'prog', 'city', 'brand', 'div');
    }

    // ==========================================
    // 1. PEMAGANG JOURNEYS
    // ==========================================

    public function test_pemagang_login_and_dashboard_render(): void
    {
        $response = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $response->assertStatus(200);
        $response->assertViewIs('pemagang.dashboard');
    }

    public function test_pemagang_draft_and_final_submission_lifecycle(): void
    {
        $masters = $this->createMasterRelations();

        // 1. Save draft
        $draftData = [
            'fullname'            => 'Budi L1 Draft',
            'student_id'          => 'NIM-L1-001',
            'gender'              => 'L',
            'phone_number'        => '081299990001',
            'institution_name'    => $masters['inst']->name,
            'study_program'       => $masters['prog']->name,
            'current_city'        => $masters['city']->name,
            'internship_interest' => 'Web Development',
        ];

        $draftResponse = $this->actingAs($this->pemagang)->post(route('pemagang.registration.draft'), $draftData);
        $draftResponse->assertRedirect();

        $reg = IR::where('user_id', $this->pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals(IR::STATUS_NEW, $reg->internship_status);

        // 2. Submit final registration
        $submitData = [
            'fullname'                 => 'Budi L1 Final',
            'student_id'               => 'NIM-L1-001',
            'email'                    => 'budi_final_' . $this->unique . '@test.com',
            'gender'                   => 'Laki-laki',
            'phone_number'             => '081299990001',
            'institution_name'         => $masters['inst']->name,
            'study_program'            => $masters['prog']->name,
            'current_city'             => $masters['city']->name,
            'internship_interest'      => 'Web Development',
            'start_date'               => '2026-02-01',
            'end_date'                 => '2026-05-01',
            'internship_type'          => 'Mandiri',
            'current_status'           => 'Mahasiswa Aktif',
            'wfo_readiness'            => 'Siap',
            'schedule_shift_readiness' => 'Siap',
            'has_laptop'               => 1,
            'internship_reason'        => 'Mengembangkan skill software engineering.',
            'planned_duration'         => '3 Bulan',
        ];

        $submitResponse = $this->actingAs($this->pemagang)->post(route('pemagang.registration.store'), $submitData);
        $submitResponse->assertRedirect(route('home'));

        $regFresh = $reg->fresh();
        $this->assertEquals(IR::STATUS_WAITING, $regFresh->internship_status);
    }

    public function test_pemagang_daily_report_leave_request_and_pending_task_journeys(): void
    {
        $masters = $this->createMasterRelations();

        $intern = IR::create([
            'user_id'             => $this->pemagang->id,
            'fullname'            => $this->pemagang->name,
            'gender'              => 'L',
            'phone_number'        => '081299990001',
            'institution_id'      => $masters['inst']->id,
            'faculty_id'          => $masters['fac']->id,
            'study_program_id'    => $masters['prog']->id,
            'division_id'         => $masters['div']->id,
            'internship_interest' => 'Web Dev',
            'start_date'          => '2026-01-01',
            'end_date'            => '2026-04-01',
            'internship_status'   => IR::STATUS_ACCEPTED,
            'step'                => 5,
        ]);

        $this->actingAs($this->pemagang);

        // 1. Daily Report
        $reportRes = $this->post(route('user.storeDailyReport'), [
            'date'       => now()->toDateString(),
            'activities' => 'Mengerjakan modul autentikasi dan RBAC.',
            'challenges' => 'Tidak ada kendala berarti.',
        ]);
        $reportRes->assertRedirect();
        $this->assertDatabaseHas('daily_reports', [
            'intern_id'  => $intern->id,
            'activities' => 'Mengerjakan modul autentikasi dan RBAC.',
        ]);

        // 2. Leave Request
        $leaveRes = $this->post(route('user.storeLeaveRequest'), [
            'leave_type' => 'Sakit',
            'leave_date' => now()->addDays(2)->toDateString(),
            'reason'     => 'Keperluan akademik kampus.',
        ]);
        $leaveRes->assertRedirect();
        $this->assertDatabaseHas('leave_requests', [
            'intern_id' => $intern->id,
            'reason'    => 'Keperluan akademik kampus.',
        ]);

        // 3. Pending Task
        $taskRes = $this->post(route('user.storePendingTask'), [
            'task_title'       => 'Refactor endpoint API',
            'task_description' => 'Memastikan response code standar.',
        ]);
        $taskRes->assertRedirect();
        $this->assertDatabaseHas('pending_tasks', [
            'intern_id' => $intern->id,
            'title'     => 'Refactor endpoint API',
        ]);
    }

    public function test_pemagang_multi_period_registration_switching_and_isolation(): void
    {
        $masters = $this->createMasterRelations();

        // Periode 1: 2025 (Completed)
        $reg2025 = IR::create([
            'user_id'             => $this->pemagang->id,
            'fullname'            => $this->pemagang->name,
            'gender'              => 'L',
            'phone_number'        => '081299990001',
            'institution_id'      => $masters['inst']->id,
            'faculty_id'          => $masters['fac']->id,
            'study_program_id'    => $masters['prog']->id,
            'division_id'         => $masters['div']->id,
            'internship_interest' => 'Web Dev',
            'start_date'          => '2025-01-01',
            'end_date'            => '2025-04-01',
            'internship_status'   => IR::STATUS_COMPLETED,
            'step'                => 5,
            'created_at'          => '2025-01-01 10:00:00',
        ]);

        // Periode 2: 2026 (Active)
        $reg2026 = IR::create([
            'user_id'             => $this->pemagang->id,
            'fullname'            => $this->pemagang->name,
            'gender'              => 'L',
            'phone_number'        => '081299990001',
            'institution_id'      => $masters['inst']->id,
            'faculty_id'          => $masters['fac']->id,
            'study_program_id'    => $masters['prog']->id,
            'division_id'         => $masters['div']->id,
            'internship_interest' => 'Backend Dev',
            'start_date'          => '2026-01-01',
            'end_date'            => '2026-04-01',
            'internship_status'   => IR::STATUS_ACTIVE,
            'step'                => 5,
            'created_at'          => '2026-01-01 10:00:00',
        ]);

        /** @var RegistrationLifecycleService $lifecycle */
        $lifecycle = app(RegistrationLifecycleService::class);

        $resolved2025 = $lifecycle->resolveRegistrationForDocuments($this->pemagang, $reg2025->id);
        $this->assertEquals($reg2025->id, $resolved2025->id);
        $this->assertEquals(IR::STATUS_COMPLETED, $resolved2025->internship_status);

        $resolved2026 = $lifecycle->resolveRegistrationForDocuments($this->pemagang, $reg2026->id);
        $this->assertEquals($reg2026->id, $resolved2026->id);
        $this->assertEquals(IR::STATUS_ACTIVE, $resolved2026->internship_status);

        // Ownership tamper check: another user's registration throws 403 HttpException
        $otherUser = User::create([
            'name'      => 'Other Pemagang',
            'email'     => 'other_' . $this->unique . '@test.com',
            'role'      => 'pemagang',
            'password'  => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        $tamperThrown = false;
        try {
            $lifecycle->resolveRegistrationForDocuments($otherUser, $reg2025->id);
        } catch (HttpException $e) {
            $tamperThrown = true;
            $this->assertEquals(403, $e->getStatusCode());
        }
        $this->assertTrue($tamperThrown, 'Cross-user registration document resolution must throw 403 Forbidden.');
    }

    // ==========================================
    // 2. ADMIN JOURNEYS
    // ==========================================

    public function test_admin_dashboard_metrics_and_navigation(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $response->assertStatus(200);
        $response->assertViewIs('dashboard.index');
    }

    public function test_admin_user_management_and_status_filtering(): void
    {
        $this->actingAs($this->admin);

        // List users
        $indexRes = $this->get(route('admin.users.index'));
        $indexRes->assertStatus(200);

        // Filter active users
        $filterActive = $this->get(route('admin.users.index', ['status' => 'active']));
        $filterActive->assertStatus(200);

        // Ban a user
        $banRes = $this->post(route('admin.users.ban', $this->pemagang->id), [
            'ban_reason' => 'Melanggar aturan presensi magang.',
        ]);
        $banRes->assertRedirect();
        $this->assertTrue($this->pemagang->fresh()->is_banned);

        // Unban user
        $unbanRes = $this->post(route('admin.users.unban', $this->pemagang->id));
        $unbanRes->assertRedirect();
        $this->assertFalse($this->pemagang->fresh()->is_banned);
    }

    public function test_admin_institution_faculty_and_study_program_crud(): void
    {
        $this->actingAs($this->admin);

        // 1. Create Institution
        $instRes = $this->post(route('admin.institutions.store'), [
            'name'    => 'Institut Teknologi Bandung L1 ' . substr($this->unique, 0, 6),
            'type'    => 'Perguruan Tinggi',
            'status'  => 'active',
            'address' => 'Jl. Ganesha No. 10',
        ]);
        $instRes->assertRedirect();

        $inst = Institution::where('name', 'like', '%Institut Teknologi Bandung L1%')->first();
        $this->assertNotNull($inst);

        // 2. Create Faculty
        $fac = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'STEI ITB',
        ]);
        $this->assertDatabaseHas('faculties', ['id' => $fac->id, 'name' => 'STEI ITB']);

        // 3. Create Study Program
        $prog = StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'Informatika ITB',
        ]);
        $this->assertDatabaseHas('study_programs', ['id' => $prog->id, 'name' => 'Informatika ITB']);
    }

    public function test_admin_contact_review_and_verification_pipeline(): void
    {
        $masters = $this->createMasterRelations();

        $contact = InstitutionContact::create([
            'institution_id'  => $masters['inst']->id,
            'name'            => 'Bapak Dekan ' . $this->unique,
            'normalized_name' => 'bapak dekan ' . $this->unique,
            'position'        => 'Dekan',
            'phone'           => '081299990002',
            'email'           => 'dekan_' . $this->unique . '@test.com',
            'is_verified'     => false,
            'is_active'       => true,
        ]);

        $this->actingAs($this->admin);

        // Review contact candidate page
        $reviewPageRes = $this->get(route('admin.contact-review.index'));
        $reviewPageRes->assertStatus(200);

        // Verify contact
        $verifyRes = $this->post(route('admin.institution-contacts.verify', $contact->id));
        $verifyRes->assertRedirect();
        $this->assertTrue($contact->fresh()->is_verified);
    }

    public function test_admin_activity_logs_and_archive_retired_endpoints(): void
    {
        $this->actingAs($this->admin);

        // 1. Activity logs index and show JSON
        $logsRes = $this->get(route('admin.activity-logs.index'));
        $logsRes->assertStatus(200);

        $log = ActivityLogger::log(
            action: 'admin_test_acceptance',
            description: 'Aktivitas pengujian acceptance L1.',
            actor: $this->admin
        );
        $logShowRes = $this->getJson(route('admin.activity-logs.show', $log));
        $logShowRes->assertStatus(200);
        $logShowRes->assertJson(['success' => true]);

        // 2. Retired archive mutation endpoints must reject requests
        $generateRes = $this->post(route('admin.archive-exports.generate'), ['year' => 2026]);
        $this->assertTrue(in_array($generateRes->status(), [400, 403, 405, 410, 422, 302], true));

        // 3. Retired legacy import mutation endpoints must reject requests
        $importCommitRes = $this->post(route('admin.legacy-import.commit'), ['staging_id' => 1]);
        $this->assertTrue(in_array($importCommitRes->status(), [400, 403, 404, 405, 410, 422, 302], true));
    }

    // ==========================================
    // 3. HR STAFF JOURNEYS
    // ==========================================

    public function test_hr_staff_dashboard_metrics_and_navigation(): void
    {
        $response = $this->actingAs($this->hrStaff)->get(route('hr.dashboard'));
        $response->assertStatus(200);
        $response->assertViewIs('hr.dashboard');
    }

    public function test_hr_staff_followup_cycle_and_attempt_lifecycle(): void
    {
        $masters = $this->createMasterRelations();

        $contact = InstitutionContact::create([
            'institution_id'  => $masters['inst']->id,
            'name'            => 'Kaprodi Mitra ' . $this->unique,
            'normalized_name' => 'kaprodi mitra ' . $this->unique,
            'position'        => 'Kaprodi',
            'phone'           => '081299990003',
            'email'           => 'kaprodi_' . $this->unique . '@test.com',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        /** @var FollowUpService $service */
        $service = app(FollowUpService::class);

        // 1. Create cycle
        $cycle = $service->createCycle($contact, (int) date('Y'), $this->hrStaff);
        $this->assertEquals(FollowUpCycle::STATUS_OPEN, $cycle->status);

        // 2. Claim lock
        $lockedCycle = $service->claimLock($cycle, $this->hrStaff);
        $this->assertTrue($lockedCycle->isLockedBy($this->hrStaff));

        // 3. Record attempt 1
        $attempt1 = $service->recordAttempt($lockedCycle, [
            'channel'       => 'whatsapp',
            'notes'         => 'Kontak pertama via WhatsApp resmi.',
            'result'        => 'responded',
            'response_text' => 'Berminat kerjasama.',
        ], $this->hrStaff);
        $this->assertEquals(1, $attempt1->attempt_number);

        // 4. Complete cycle with willing status
        $completedCycle = $service->completeCycle(
            $lockedCycle,
            FollowUpCycle::RESULT_WILLING,
            'Kampus menyetujui draft kerjasama MOU.',
            $this->hrStaff
        );
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $completedCycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_WILLING, $completedCycle->final_result);
    }

    public function test_hr_staff_mou_lifecycle_and_signed_activation(): void
    {
        $masters = $this->createMasterRelations();

        $contact = InstitutionContact::create([
            'institution_id'  => $masters['inst']->id,
            'name'            => 'Wakil Rektor ' . $this->unique,
            'normalized_name' => 'wakil rektor ' . $this->unique,
            'position'        => 'Wakil Rektor IV',
            'phone'           => '081299990004',
            'email'           => 'warek4_' . $this->unique . '@test.com',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_contact_id' => $contact->id,
            'institution_id'         => $masters['inst']->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'final_notes'            => 'Siap bermitra.',
            'confirmed_by'           => $this->hrStaff->id,
            'confirmed_at'           => now(),
        ]);

        $template = MouTemplate::where('is_active', true)->first();

        /** @var MouService $mouService */
        $mouService = app(MouService::class);

        // 1. Generate MOU
        $mou = $mouService->generateMou([
            'institution_id'        => $masters['inst']->id,
            'followup_cycle_id'     => $cycle->id,
            'company_mou_number'    => 'MOU-L1-' . $this->unique,
            'mou_template_id'       => $template->id,
            'cooperation_confirmed' => true,
            'effective_start'       => now()->toDateString(),
            'effective_end'         => now()->addYears(3)->toDateString(),
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_GENERATED, $mou->status);

        // 2. Mark as delivered to campus
        $deliveredMou = $mouService->markDelivered($mou, [
            'delivered_at'       => now()->toDateString(),
            'delivery_method'    => 'email',
            'delivery_recipient' => 'Dr. Rektor',
            'delivery_notes'     => 'Dikirim via email resmi humas kampus.',
        ], $this->hrStaff);
        $this->assertEquals(Mou::STATUS_DELIVERED, $deliveredMou->status);

        // 3. Upload signed document and activate
        $fakePdf = UploadedFile::fake()->create('mou_signed_kampus.pdf', 200, 'application/pdf');
        $activeMou = $mouService->uploadSignedMou($deliveredMou, $fakePdf, [
            'signed_at' => now()->toDateString(),
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_ACTIVE, $activeMou->status);
        $this->assertNotNull($activeMou->signed_file_path);
    }

    public function test_hr_staff_is_strictly_forbidden_from_admin_exclusive_routes(): void
    {
        $this->actingAs($this->hrStaff);

        $forbiddenRoutes = [
            route('admin.users.index'),
            route('admin.activity-logs.index'),
            route('admin.spreadsheet-connections.index'),
        ];

        foreach ($forbiddenRoutes as $url) {
            $response = $this->get($url);
            $this->assertTrue(
                $response->status() === 403 || $response->isRedirect(),
                "HR staff must not access admin route: {$url}"
            );
        }
    }
}
