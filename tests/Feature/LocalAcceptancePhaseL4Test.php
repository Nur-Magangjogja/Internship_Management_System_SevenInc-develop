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
use Database\Seeders\LocalDemoDatasetSeeder;
use Database\Seeders\MouTemplateSeeder;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetConnectionSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalAcceptancePhaseL4Test extends TestCase
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

        $this->unique = uniqid('l4_', true);

        $this->admin = User::create([
            'name'      => 'Admin L4 ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@example.com',
            'role'      => 'admin',
            'password'  => Hash::make('Secret123!'),
            'is_banned' => false,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR Staff L4 ' . substr($this->unique, 0, 6),
            'email'     => 'hr_' . $this->unique . '@example.com',
            'role'      => 'hr_staff',
            'password'  => Hash::make('Secret123!'),
            'is_banned' => false,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang L4 ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@example.com',
            'role'      => 'pemagang',
            'password'  => Hash::make('Secret123!'),
            'is_banned' => false,
        ]);
    }

    /**
     * Skenario 1: Demo Dataset Seeding Idempotency & Completeness
     */
    public function test_demo_dataset_seeder_is_idempotent_and_creates_all_expected_records(): void
    {
        $this->seed(LocalDemoDatasetSeeder::class);
        $this->seed(LocalDemoDatasetSeeder::class); // Run twice to verify idempotence

        $this->assertDatabaseHas('users', ['email' => 'admin.demo@seveninc.test', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'hr.demo@seveninc.test', 'role' => 'hr_staff']);
        $this->assertDatabaseHas('users', ['email' => 'pemagang.aktif@seveninc.test', 'role' => 'pemagang']);
        $this->assertDatabaseHas('institutions', ['normalized_name' => 'universitas gadjah mada demo']);
        $this->assertDatabaseHas('mous', ['company_mou_number' => '001/MOU/SI-DEMO/X/2026']);
        $this->assertDatabaseHas('daily_reports', ['challenges' => 'Tidak ada kendala berarti']);
    }

    /**
     * Skenario 2: Admin Core Journey
     */
    public function test_admin_core_journey_and_supervision(): void
    {
        // 1. Dashboard
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $response->assertStatus(200);

        // 2. Daftar Pemagang & Filter
        $response = $this->actingAs($this->admin)->get(route('admin.interns.index'));
        $response->assertStatus(200);

        // 3. Review Kontak Institusi
        $response = $this->actingAs($this->admin)->get(route('admin.contact-review.index'));
        $response->assertStatus(200);

        // 4. Activity Logs
        $response = $this->actingAs($this->admin)->get(route('admin.activity-logs.index'));
        $response->assertStatus(200);

        // 5. Spreadsheet Connections
        $response = $this->actingAs($this->admin)->get(route('admin.spreadsheet-connections.index'));
        $response->assertStatus(200);

        // 6. MOU Templates
        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.index'));
        $response->assertStatus(200);
    }

    /**
     * Skenario 3: HR Staff Core Journey & RBAC Boundary
     */
    public function test_hr_staff_core_journey_and_admin_route_blocking(): void
    {
        // 1. HR Dashboard
        $response = $this->actingAs($this->hrStaff)->get(route('hr.dashboard'));
        $response->assertStatus(200);

        // 2. Akses Follow-Up Index
        $response = $this->actingAs($this->hrStaff)->get(route('hr.follow-up.index'));
        $response->assertStatus(200);

        // 3. Akses MOU Index
        $response = $this->actingAs($this->hrStaff)->get(route('hr.mous.index'));
        $response->assertStatus(200);

        // 4. Verifikasi Pembatasan Akses: HR Staff TIDAK boleh mengakses rute Admin
        // Web requests diarahkan kembali ke hr.dashboard dengan error message
        $forbiddenAdminRoutes = [
            route('admin.users.index'),
            route('admin.spreadsheet-connections.index'),
            route('admin.activity-logs.index'),
            route('admin.mou-templates.index'),
        ];

        foreach ($forbiddenAdminRoutes as $route) {
            $resp = $this->actingAs($this->hrStaff)->get($route);
            $this->assertTrue(
                $resp->isRedirect(route('hr.dashboard')) || $resp->getStatusCode() === 403,
                "HR Staff should be blocked from accessing {$route}"
            );
        }

        // JSON requests mendapatkan 403 secara eksplisit
        $jsonResp = $this->actingAs($this->hrStaff)->getJson(route('admin.users.index'));
        $jsonResp->assertStatus(403);
    }

    /**
     * Skenario 4: Follow-Up Cycle Locking and Lifecycle
     */
    public function test_followup_cycle_locking_and_attempt_recording(): void
    {
        $inst = Institution::create([
            'name'            => 'Institut Teknologi Demo ' . $this->unique,
            'normalized_name' => 'institut teknologi demo ' . $this->unique,
            'type'            => 'Perguruan Tinggi',
            'city'            => 'Yogyakarta',
            'is_active'       => true,
        ]);

        $contact = InstitutionContact::create([
            'institution_id'  => $inst->id,
            'name'            => 'Bapak Dekan ' . $this->unique,
            'normalized_name' => 'bapak dekan ' . $this->unique,
            'phone'           => '081299998888',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $inst->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // 1. Claim Lock oleh HR Staff
        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.claim', $cycle));
        $response->assertRedirect();
        $cycle->refresh();
        $this->assertEquals($this->hrStaff->id, $cycle->locked_by);
        $this->assertNotNull($cycle->lock_expires_at);

        // 2. Rekam Percobaan Follow-Up
        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.attempts.store', $cycle), [
            'channel'        => 'whatsapp',
            'result'         => 'responded',
            'response_text'  => 'Tertarik bekerjasama',
            'notes'          => 'Catatan L4 acceptance',
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('followup_attempts', [
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'channel'           => 'whatsapp',
        ]);

        // 3. Selesaikan Siklus dengan Hasil Willing (MOU Ready)
        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => FollowUpCycle::RESULT_WILLING,
            'final_notes'  => 'Institusi siap tanda tangan MOU Tri Dharma',
        ]);
        $response->assertRedirect();

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_WILLING, $cycle->final_result);
        $this->assertNull($cycle->locked_by, 'Lock harus dilepas saat siklus selesai');
    }

    /**
     * Skenario 5: Pemagang Registration Draft and Final Submission
     */
    public function test_pemagang_registration_draft_and_final_submit(): void
    {
        $inst = Institution::create([
            'name'            => 'Universitas Pemagang L4 ' . $this->unique,
            'normalized_name' => 'universitas pemagang l4 ' . $this->unique,
            'type'            => 'Perguruan Tinggi',
            'city'            => 'Yogyakarta',
            'is_active'       => true,
        ]);

        $fac = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Komputer ' . $this->unique,
        ]);

        $prog = StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'Informatika ' . $this->unique,
        ]);

        $div = Division::firstOrCreate(
            ['name' => 'Web Development ' . $this->unique],
            ['code' => 'DEV', 'slug' => 'web-dev-' . $this->unique, 'is_active' => true]
        );

        // 1. Simpan Draft
        $draftPayload = [
            'fullname'            => 'Pemagang L4 Candidate',
            'phone_number'        => '087711223344',
            'current_city'        => 'Yogyakarta',
            'institution_name'    => $inst->name,
            'study_program'       => $prog->name,
            'internship_interest' => $div->name,
            'is_draft'            => '1',
        ];

        $response = $this->actingAs($this->pemagang)->post(route('pemagang.registration.draft'), $draftPayload);
        $this->assertTrue(in_array($response->getStatusCode(), [200, 302]));

        $this->assertDatabaseHas('internship_registrations', [
            'user_id'           => $this->pemagang->id,
            'internship_status' => IR::STATUS_NEW,
        ]);

        // 2. Submit Final
        $finalPayload = [
            'fullname'                 => 'Pemagang L4 Candidate',
            'student_id'               => 'NIM-L4-001',
            'email'                    => $this->pemagang->email,
            'phone_number'             => '087711223344',
            'gender'                   => 'Laki-laki',
            'current_city'             => 'Yogyakarta',
            'institution_id'           => $inst->id,
            'institution_name'         => $inst->name,
            'faculty_id'               => $fac->id,
            'faculty'                  => $fac->name,
            'study_program_id'         => $prog->id,
            'study_program'            => $prog->name,
            'major'                    => $prog->name,
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'internship_type'          => 'Magang Mandiri',
            'internship_interest'      => $div->name,
            'internship_reason'        => 'Ingin meningkatkan keahlian teknis.',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => '1',
            'planned_duration'         => '3 Bulan',
        ];

        $response = $this->actingAs($this->pemagang)->post(route('pemagang.registration.store'), $finalPayload);
        $this->assertTrue(in_array($response->getStatusCode(), [200, 302]));

        $this->assertDatabaseHas('internship_registrations', [
            'user_id'           => $this->pemagang->id,
            'internship_status' => IR::STATUS_WAITING,
        ]);

        // 3. Pemagang Dashboard Renders Stepper
        $dashResp = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $dashResp->assertStatus(200);
        $dashResp->assertSee('Under Review');
    }

    /**
     * Skenario 6: Private Document Access Control
     */
    public function test_private_documents_strictly_protected_from_unauthorized_access(): void
    {
        // Dokumen privat dilindungi dari pengguna yang tidak login
        $docUrl = route('admin.documents.serve', ['type' => 'skl', 'filename' => 'confidential.pdf']);
        
        $guestResp = $this->get($docUrl);
        $this->assertTrue(in_array($guestResp->getStatusCode(), [302, 401, 403, 404]));

        // Pemagang biasa ditolak dari route dokumen admin
        $pemagangResp = $this->actingAs($this->pemagang)->get($docUrl);
        $this->assertTrue(in_array($pemagangResp->getStatusCode(), [302, 403, 404]));

        // Download SKL pemagang ditolak jika belum berstatus COMPLETED
        $sklResp = $this->actingAs($this->pemagang)->get(route('user.skl.download'));
        $this->assertTrue(in_array($sklResp->getStatusCode(), [302, 403, 404]));
    }
}
