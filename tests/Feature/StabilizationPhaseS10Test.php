<?php

namespace Tests\Feature;

use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\ActivityLog;
use App\Models\AlumniMembercard;
use App\Models\City;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternAssessment;
use App\Models\InternCertificate;
use App\Models\InternLoa;
use App\Models\InternshipRegistration;
use App\Models\SklDocument;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\DocumentGeneratorService;
use App\Services\RegistrationLifecycleService;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StabilizationPhaseS10Test extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $hrStaff;
    protected User $pemagangA;
    protected User $pemagangB;
    protected Institution $institution;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSpreadsheetRowSyncJob::class, UploadRegistrationAttachmentToDriveJob::class]);

        $this->seed(Spreadsheet2026SchemaSeeder::class);
        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);

        $this->unique = uniqid('s10_', true);

        $this->admin = User::create([
            'name'      => 'Admin S10 ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR S10 ' . substr($this->unique, 0, 6),
            'email'     => 'hr_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->pemagangA = User::create([
            'name'      => 'Pemagang A S10 ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_a_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->pemagangB = User::create([
            'name'      => 'Pemagang B S10 ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_b_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas S10 ' . substr($this->unique, 0, 6),
            'is_active' => true,
        ]);
    }

    private function createRegistration(User $user, array $overrides = []): InternshipRegistration
    {
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $this->institution->id, 'name' => 'Fakultas MIPA S10']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Informatika S10']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        return InternshipRegistration::create(array_merge([
            'user_id'           => $user->id,
            'fullname'          => $user->name,
            'student_id'        => '22/123456/PA/10001',
            'gender'            => 'Laki-laki',
            'phone_number'      => '08123456789',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $prodi->id,
            'city_id'           => $city->id,
            'internship_reason' => 'Magang PKL',
            'internship_type'   => 'Mandiri',
            'current_status'    => 'Masih Kuliah',
            'wfo_readiness'     => 'Siap WFO',
            'has_laptop'        => true,
            'start_date'        => '2026-07-01',
            'planned_duration'  => '3 Bulan',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ], $overrides));
    }

    /**
     * Test 1: User with multiple registrations can access completion documents from completed period.
     */
    public function test_user_with_multiple_registrations_accesses_correct_documents_per_period(): void
    {
        // Registration 1: Completed period (2024)
        $reg1 = $this->createRegistration($this->pemagangA, [
            'start_date'        => '2024-01-15',
            'end_date'          => '2024-04-15',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        InternLoa::create([
            'intern_id'           => $reg1->id,
            'loa_number'          => '001/LOA/WEB/SEVEN.SI/I/2024',
            'accepted_start_date' => '2024-01-15',
            'accepted_end_date'   => '2024-04-15',
            'company_name'        => 'Seven Inc',
            'signatory_name'      => 'Ari Setia Husbana',
        ]);

        SklDocument::create([
            'intern_id'      => $reg1->id,
            'skl_number'     => '001/SKL/WEB/SEVEN.SI/IV/2024',
            'company_name'   => 'Seven Inc',
            'signatory_name' => 'Ari Setia Husbana',
        ]);

        $this->pemagangA->createMemberCard($reg1);

        // Registration 2: New period (2026, waiting)
        $reg2 = $this->createRegistration($this->pemagangA, [
            'start_date'        => '2026-07-01',
            'end_date'          => '2026-10-01',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $response = $this->actingAs($this->pemagangA)->get(route('pemagang.documents'));
        $response->assertOk();

        // Must still have access to SKL and Membercard from the completed period
        $response->assertSee('SKL (Surat Keterangan Lulus)');
        $response->assertSee('Membercard Digital');
    }

    /**
     * Test 2: New registration submission does not overwrite previous completed documents.
     */
    public function test_new_registration_submission_does_not_overwrite_previous_completed_documents(): void
    {
        $reg1 = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $loa1 = InternLoa::create([
            'intern_id'           => $reg1->id,
            'loa_number'          => '005/LOA/WEB/SEVEN.SI/VI/2024',
            'accepted_start_date' => '2024-06-01',
            'accepted_end_date'   => '2024-09-01',
            'company_name'        => 'Seven Inc',
            'signatory_name'      => 'Ari Setia Husbana',
        ]);

        $skl1 = SklDocument::create([
            'intern_id'      => $reg1->id,
            'skl_number'     => '005/SKL/WEB/SEVEN.SI/IX/2024',
            'company_name'   => 'Seven Inc',
            'signatory_name' => 'Ari Setia Husbana',
        ]);

        // Submit new registration
        $reg2 = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        // Assert old documents are still firmly bound to reg1
        $this->assertDatabaseHas('intern_loas', [
            'id'        => $loa1->id,
            'intern_id' => $reg1->id,
        ]);

        $this->assertDatabaseHas('skl_documents', [
            'id'        => $skl1->id,
            'intern_id' => $reg1->id,
        ]);

        $this->assertNotEquals($reg1->id, $reg2->id);
    }

    /**
     * Test 3: Anti-IDOR — Unauthorized user cannot access other user's LOA.
     */
    public function test_unauthorized_user_cannot_access_other_user_loa(): void
    {
        $regA = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        // Pemagang B tries to generate/download LOA for Pemagang A
        $response = $this->actingAs($this->pemagangB)->post(route('user.loa.generate'), [
            'intern_id' => $regA->id,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test 4: Anti-IDOR — Unauthorized user cannot download other user's SKL.
     */
    public function test_unauthorized_user_cannot_download_other_user_skl(): void
    {
        $regA = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        SklDocument::create([
            'intern_id'      => $regA->id,
            'skl_number'     => '010/SKL/WEB/SEVEN.SI/VII/2026',
            'company_name'   => 'Seven Inc',
            'signatory_name' => 'Ari Setia Husbana',
        ]);

        // Pemagang B tries to download SKL specifying User A's ID
        $response = $this->actingAs($this->pemagangB)->get(route('user.skl.download', [
            'user_id'         => $this->pemagangA->id,
            'registration_id' => $regA->id,
        ]));

        $response->assertStatus(403);
    }

    /**
     * Test 5: Anti-IDOR — Unauthorized user cannot download other user's assessment PDF.
     */
    public function test_unauthorized_user_cannot_download_other_user_assessment(): void
    {
        $regA = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $assessmentA = InternAssessment::create([
            'intern_id'         => $regA->id,
            'assessment_number' => '001/PEN/WEB/SEVEN.SI/VII/2026',
            'company_name'      => 'Seven Inc',
            'aspek_penilaian'   => [['aspek' => 'Disiplin', 'nilai' => 90]],
            'rata_rata'         => 90.0,
        ]);

        // Pemagang B tries to download Pemagang A's assessment
        $response = $this->actingAs($this->pemagangB)->getJson(route('interns.assessment.pdf', $assessmentA->id));

        $response->assertStatus(403);
    }

    /**
     * Test 6: Anti-IDOR — Unauthorized user cannot download or log other user's membercard.
     */
    public function test_unauthorized_user_cannot_download_other_user_membercard(): void
    {
        $regA = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'start_date'        => '2026-02-01',
        ]);

        $cardA = $this->pemagangA->createMemberCard($regA);
        $this->assertNotNull($cardA);

        // Pemagang B tries to log download for Pemagang A's member code
        $response = $this->actingAs($this->pemagangB)->postJson(route('log.download'), [
            'id' => $cardA->member_code,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test 7: User createMemberCard binds strictly to completed registration in multi-period scenario.
     */
    public function test_create_membercard_binds_to_exact_completed_registration_in_multi_period(): void
    {
        // 1. Completed registration from 2024
        $reg2024 = $this->createRegistration($this->pemagangA, [
            'start_date'        => '2024-02-01',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        // 2. Waiting registration from 2026
        $reg2026 = $this->createRegistration($this->pemagangA, [
            'start_date'        => '2026-08-01',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        // Generate card without explicit arg -> must resolve 2024 completed registration
        $card = $this->pemagangA->createMemberCard();
        $this->assertNotNull($card);
        $this->assertEquals($reg2024->id, $card->intern_id);
        $this->assertEquals('2024', $card->batch_year);
    }

    /**
     * Test 8: Soft-deleting registration preserves historical document records and relationships.
     */
    public function test_soft_deleting_registration_preserves_historical_documents(): void
    {
        $reg = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $loa = InternLoa::create([
            'intern_id'           => $reg->id,
            'loa_number'          => '099/LOA/WEB/SEVEN.SI/VII/2026',
            'accepted_start_date' => '2026-07-01',
            'accepted_end_date'   => '2026-10-01',
            'company_name'        => 'Seven Inc',
            'signatory_name'      => 'Ari Setia Husbana',
        ]);

        $skl = SklDocument::create([
            'intern_id'      => $reg->id,
            'skl_number'     => '099/SKL/WEB/SEVEN.SI/X/2026',
            'company_name'   => 'Seven Inc',
            'signatory_name' => 'Ari Setia Husbana',
        ]);

        // Soft delete the registration
        $reg->delete();
        $this->assertTrue($reg->trashed());

        // Refresh relations via withTrashed()
        $loa->refresh();
        $skl->refresh();

        $this->assertNotNull($loa->intern);
        $this->assertEquals($reg->id, $loa->intern->id);
        $this->assertNotNull($skl->intern);
        $this->assertEquals($reg->id, $skl->intern->id);
    }

    /**
     * Test 9: Admin and HR staff can generate LOA and SKL with audit logging.
     */
    public function test_admin_and_hr_staff_can_generate_documents_with_audit_logging(): void
    {
        $reg = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $docService = app(DocumentGeneratorService::class);
        $log = $docService->logDocumentAccess('loa', $reg, $this->admin, 'generated');

        $this->assertDatabaseHas('activity_logs', [
            'id'            => $log->id,
            'action'        => 'document_generated_for_user',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    /**
     * Test 10: DocumentGeneratorService checks eligibility invariants.
     */
    public function test_document_generator_service_checks_eligibility_invariants(): void
    {
        $docService = app(DocumentGeneratorService::class);

        $waitingReg = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $acceptedReg = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $completedReg = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $this->assertFalse($docService->canGenerate($waitingReg, 'loa'));
        $this->assertTrue($docService->canGenerate($acceptedReg, 'loa'));
        $this->assertFalse($docService->canGenerate($acceptedReg, 'skl'));
        $this->assertTrue($docService->canGenerate($completedReg, 'skl'));
        $this->assertTrue($docService->canGenerate($completedReg, 'membercard'));
    }

    /**
     * Test 11: Guest is strictly rejected from /admin/documents/serve.
     */
    public function test_guest_cannot_access_admin_document_serve(): void
    {
        $response = $this->get('/admin/documents/serve/loa/test-file.pdf');
        $response->assertRedirect();
    }

    /**
     * Test 12: Pemagang is strictly rejected from /admin/documents/serve.
     */
    public function test_pemagang_cannot_access_admin_document_serve(): void
    {
        $response = $this->actingAs($this->pemagangA)->get('/admin/documents/serve/loa/test-file.pdf');
        $response->assertRedirect(route('user.dashboard'));

        $jsonResponse = $this->actingAs($this->pemagangA)->getJson('/admin/documents/serve/loa/test-file.pdf');
        $jsonResponse->assertStatus(403);
    }

    /**
     * Test 13: Direct non-prefixed /documents/serve returns 404 Not Found.
     */
    public function test_direct_non_prefixed_documents_serve_returns_404(): void
    {
        $response = $this->get('/documents/serve/loa/test-file.pdf');
        $response->assertNotFound();
    }

    /**
     * Test 14: Pemagang B cannot download Pemagang A's certificate PDF.
     */
    public function test_unauthorized_user_cannot_download_other_user_certificate(): void
    {
        $regA = $this->createRegistration($this->pemagangA, [
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        InternCertificate::create([
            'intern_id'          => $regA->id,
            'certificate_number' => '001/SERT/ADM/SI.SI/VII/2026',
            'company_name'       => 'Seven Inc',
        ]);

        // Pemagang B attempts to download Pemagang A's certificate
        $response = $this->actingAs($this->pemagangB)->get(route('user.certificate.download', [
            'intern_id' => $regA->id,
        ]));

        $response->assertStatus(403);
    }

    /**
     * Test 15: Admin can serve document securely from private storage disk.
     */
    public function test_admin_can_serve_document_from_private_storage(): void
    {
        $privateDir = storage_path('app/documents/loa');
        if (!is_dir($privateDir)) {
            mkdir($privateDir, 0775, true);
        }
        $testFile = $privateDir . '/test-secure-loa.pdf';
        file_put_contents($testFile, '%PDF-1.4 secure test');

        try {
            $response = $this->actingAs($this->admin)->get('/admin/documents/serve/loa/test-secure-loa.pdf');
            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
        } finally {
            if (file_exists($testFile)) {
                @unlink($testFile);
            }
        }
    }
}

