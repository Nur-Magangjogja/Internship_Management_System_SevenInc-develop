<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\Brand;
use App\Models\Division;
use App\Models\DocumentTemplate;
use App\Models\Faculty;
use App\Models\GeneratedDocument;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class UniversalGenerationWizardTest extends TestCase
{
    protected User $admin;
    protected User $pemagang;
    protected Brand $brand;
    protected Division $division;
    protected Institution $institution;
    protected Faculty $faculty;
    protected StudyProgram $studyProgram;
    protected InternshipRegistration $internAccepted;
    protected InternshipRegistration $internCompleted;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->unique = Str::random(8);

        $this->admin = User::create([
            'name'     => 'admin_' . $this->unique,
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . $this->unique,
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->brand = Brand::firstOrCreate(['name' => 'SEVEN INC'], [
            'code'               => '7INC',
            'slug'               => 'seven-inc-' . $this->unique,
            'is_active'          => true,
            'signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'signatory_position' => 'Direktur Utama',
        ]);
        $this->brand->update([
            'signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'signatory_position' => 'Direktur Utama',
        ]);

        $this->division = Division::firstOrCreate(['name' => 'IT & Software Engineering'], [
            'brand_id' => $this->brand->id,
            'is_active' => true,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas Gadjah Mada ' . $this->unique,
            'type'      => 'universitas',
            'address'   => 'Bulaksumur, Yogyakarta',
            'is_active' => true,
        ]);

        $this->faculty = Faculty::firstOrCreate(
            ['name' => 'Fakultas Teknik ' . $this->unique],
            ['institution_id' => $this->institution->id]
        );

        $this->studyProgram = StudyProgram::firstOrCreate(
            ['name' => 'Teknologi Informasi ' . $this->unique],
            ['faculty_id' => $this->faculty->id]
        );

        // Accepted Intern (Eligible for LoA, not eligible for SKL/Certificate)
        $this->internAccepted = InternshipRegistration::create([
            'user_id'             => $this->pemagang->id,
            'brand_id'            => $this->brand->id,
            'brand'               => $this->brand->name,
            'division_id'         => $this->division->id,
            'institution_id'      => $this->institution->id,
            'institution_name'    => $this->institution->name,
            'faculty_id'          => $this->faculty->id,
            'study_program_id'    => $this->studyProgram->id,
            'fullname'            => 'Ahmad Fajar ' . $this->unique,
            'student_id'          => '1001',
            'school_id_number'    => 'NIM-1001-' . $this->unique,
            'internship_status'   => InternshipRegistration::STATUS_ACCEPTED,
            'internship_interest' => 'Software Engineering',
            'start_date'          => now()->subDays(30)->toDateString(),
            'end_date'            => now()->addDays(60)->toDateString(),
        ]);

        // Completed Intern (Eligible for all documents)
        $this->internCompleted = InternshipRegistration::create([
            'user_id'             => $this->pemagang->id,
            'brand_id'            => $this->brand->id,
            'brand'               => $this->brand->name,
            'division_id'         => $this->division->id,
            'institution_id'      => $this->institution->id,
            'institution_name'    => $this->institution->name,
            'faculty_id'          => $this->faculty->id,
            'study_program_id'    => $this->studyProgram->id,
            'fullname'            => 'Rina Rahmawati ' . $this->unique,
            'student_id'          => '2002',
            'school_id_number'    => 'NIM-2002-' . $this->unique,
            'internship_status'   => InternshipRegistration::STATUS_COMPLETED,
            'internship_interest' => 'Software Engineering',
            'start_date'          => now()->subDays(90)->toDateString(),
            'end_date'            => now()->subDays(5)->toDateString(),
        ]);

        // Ensure default templates exist for the document types
        DocumentTemplate::ensureDefaultTemplatesExist($this->admin->id);
    }

    protected function tearDown(): void
    {
        if (isset($this->internAccepted)) {
            GeneratedDocument::where('internship_registration_id', $this->internAccepted->id)->forceDelete();
            $this->internAccepted->forceDelete();
        }
        if (isset($this->internCompleted)) {
            GeneratedDocument::where('internship_registration_id', $this->internCompleted->id)->forceDelete();
            $this->internCompleted->forceDelete();
        }
        if (isset($this->institution)) {
            $this->institution->forceDelete();
        }
        if (isset($this->admin)) {
            $this->admin->delete();
        }
        if (isset($this->pemagang)) {
            $this->pemagang->delete();
        }
        parent::tearDown();
    }

    /**
     * Test preflight validation: LoA is eligible for accepted intern, but SKL and Certificate are rejected.
     */
    public function test_wizard_preflight_validates_eligibility_by_document_type(): void
    {
        // 1. Accepted intern is eligible for LoA
        $resLoa = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internAccepted->id,
            'jenis_surat' => 'loa',
            'action'      => 'validate',
        ]);

        $resLoa->assertOk();
        $resLoa->assertJson([
            'ok'       => true,
            'action'   => 'validate',
            'eligible' => true,
            'jenis'    => 'loa',
        ]);

        // 2. Accepted intern is NOT eligible for Certificate
        $resCert = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internAccepted->id,
            'jenis_surat' => 'certificate',
            'action'      => 'validate',
        ]);

        $resCert->assertOk();
        $resCert->assertJson([
            'ok'       => false,
            'action'   => 'validate',
            'eligible' => false,
            'jenis'    => 'certificate',
        ]);

        // 3. Completed intern is eligible for Certificate
        $resCertCompleted = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internCompleted->id,
            'jenis_surat' => 'certificate',
            'action'      => 'validate',
        ]);

        $resCertCompleted->assertOk();
        $resCertCompleted->assertJson([
            'ok'       => true,
            'action'   => 'validate',
            'eligible' => true,
            'jenis'    => 'certificate',
        ]);
    }

    /**
     * Test live preview generates HTML without writing to database or cloud.
     */
    public function test_wizard_preview_action_returns_live_html_without_db_write(): void
    {
        $dbCountBefore = GeneratedDocument::count();

        $response = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internAccepted->id,
            'jenis_surat' => 'loa',
            'action'      => 'preview',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'ok',
            'action',
            'jenis',
            'document_number',
            'html',
        ]);

        $this->assertStringContainsString($this->internAccepted->fullname, $response->json('html'));
        $this->assertEquals($dbCountBefore, GeneratedDocument::count(), 'Zero GeneratedDocument records written during preview');
    }

    /**
     * Test official issuance generates valid document and preserves number idempotently.
     */
    public function test_wizard_issuance_is_atomic_and_idempotent(): void
    {
        // First issuance
        $res1 = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internCompleted->id,
            'jenis_surat' => 'skl',
            'action'      => 'issue',
        ]);

        $res1->assertOk();
        $res1->assertJson(['ok' => true]);
        $docNumber = $res1->json('document.number');
        $this->assertNotEmpty($docNumber);

        // Second issuance without revision flag should reuse exact same document record
        $res2 = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internCompleted->id,
            'jenis_surat' => 'skl',
            'action'      => 'issue',
        ]);

        $res2->assertOk();
        $this->assertEquals($docNumber, $res2->json('document.number'), 'Idempotent: same document number reused');

        $sklDocs = GeneratedDocument::where('internship_registration_id', $this->internCompleted->id)
            ->where('document_type', 'skl')
            ->count();
        $this->assertEquals(1, $sklDocs, 'Only 1 active document record created');
    }

    /**
     * Test bulk generation endpoint processes multiple candidates idempotently.
     */
    public function test_bulk_generation_endpoint_processes_multiple_targets(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc.bulk'), [
            'jenis_surat' => 'loa',
            'targets'     => [
                ['intern_id' => $this->internAccepted->id],
                ['intern_id' => $this->internCompleted->id],
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok'        => true,
            'total'     => 2,
            'succeeded' => 2,
            'failed'    => 0,
        ]);

        $this->assertCount(2, $response->json('results'));
    }

    /**
     * Test preflight GET helper route returns structured diagnostics.
     */
    public function test_preflight_get_route_returns_structured_diagnostics(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('admin.interns.generate.wizard.preflight', [
            'intern_id'   => $this->internCompleted->id,
            'jenis_surat' => 'certificate',
        ]));

        $response->assertOk();
        $response->assertJsonStructure([
            'ok',
            'action',
            'eligible',
            'status_current',
            'jenis',
            'intern' => ['id', 'name', 'division'],
            'template' => ['id', 'name', 'version_number'],
            'is_already_issued',
            'resolved_variables',
            'missing_variables',
        ]);
    }
}
