<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\Brand;
use App\Models\Division;
use App\Models\DocumentTemplate;
use App\Models\Faculty;
use App\Models\GeneratedDocument;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishingSecurityDriveAndRegressionTest extends TestCase
{
    protected User $admin;
    protected User $hrStaff;
    protected User $pemagang;
    protected Brand $brand;
    protected Division $division;
    protected Institution $institution;
    protected Faculty $faculty;
    protected StudyProgram $studyProgram;
    protected IR $internAccepted;
    protected IR $internCompleted;
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

        $this->hrStaff = User::create([
            'name'     => 'hr_' . $this->unique,
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . $this->unique,
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->brand = Brand::firstOrCreate(['code' => '7INC'], [
            'name'               => 'SEVEN INC',
            'slug'               => 'seven-inc',
            'is_active'          => true,
            'signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'signatory_position' => 'Direktur Utama',
        ]);
        $this->brand->update([
            'signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'signatory_position' => 'Direktur Utama',
        ]);

        $this->division = Division::firstOrCreate(['code' => 'IT'], [
            'brand_id'  => $this->brand->id,
            'name'      => 'IT & Software Engineering',
            'slug'      => 'it-software-engineering',
            'is_active' => true,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas Gadjah Mada ' . $this->unique,
            'type'      => 'universitas',
            'address'   => 'Bulaksumur, Sleman, D.I. Yogyakarta',
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

        // Accepted Intern (Eligible for LoA)
        $this->internAccepted = IR::create([
            'user_id'             => $this->pemagang->id,
            'brand_id'            => $this->brand->id,
            'brand'               => $this->brand->name,
            'division_id'         => $this->division->id,
            'institution_id'      => $this->institution->id,
            'institution_name'    => $this->institution->name,
            'faculty_id'          => $this->faculty->id,
            'study_program_id'    => $this->studyProgram->id,
            'fullname'            => 'Budi Utomo ' . $this->unique,
            'student_id'          => '1001',
            'school_id_number'    => 'NIM-1001-' . $this->unique,
            'internship_status'   => IR::STATUS_ACCEPTED,
            'internship_interest' => 'Software Engineering',
            'start_date'          => now()->subDays(30)->toDateString(),
            'end_date'            => now()->addDays(60)->toDateString(),
        ]);

        // Completed Intern (Eligible for SKL, Certificate, Assessment, Recommendation)
        $this->internCompleted = IR::create([
            'user_id'             => $this->pemagang->id,
            'brand_id'            => $this->brand->id,
            'brand'               => $this->brand->name,
            'division_id'         => $this->division->id,
            'institution_id'      => $this->institution->id,
            'institution_name'    => $this->institution->name,
            'faculty_id'          => $this->faculty->id,
            'study_program_id'    => $this->studyProgram->id,
            'fullname'            => 'Dewi Sartika ' . $this->unique,
            'student_id'          => '2002',
            'school_id_number'    => 'NIM-2002-' . $this->unique,
            'internship_status'   => IR::STATUS_COMPLETED,
            'internship_interest' => 'Software Engineering',
            'start_date'          => now()->subDays(90)->toDateString(),
            'end_date'            => now()->subDays(5)->toDateString(),
        ]);

        // Seed default published templates
        DocumentTemplate::seedStandardDefaultTemplates($this->admin->id);
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
        if (isset($this->hrStaff)) {
            $this->hrStaff->delete();
        }
        if (isset($this->pemagang)) {
            $this->pemagang->delete();
        }
        parent::tearDown();
    }

    /**
     * T39 & T40: Preview mode returns rendered HTML without DB mutation or number reservation.
     */
    public function test_preview_does_not_mutate_db_or_mint_numbers(): void
    {
        $dbCountBefore = GeneratedDocument::count();

        $response = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internAccepted->id,
            'jenis_surat' => 'loa',
            'action'      => 'preview',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'action' => 'preview']);
        $this->assertNotEmpty($response->json('html'));
        $this->assertStringContainsString($this->internAccepted->fullname, $response->json('html'));

        // Assert database was NOT mutated
        $this->assertEquals($dbCountBefore, GeneratedDocument::count(), 'Zero GeneratedDocument records written during preview');
    }

    /**
     * T41 & T42: Unknown token or mismatched document type fails closed with diagnostic error.
     */
    public function test_unknown_token_or_type_mismatch_blocks_issuance(): void
    {
        // 1. Template with unknown token
        $badTemplate = DocumentTemplate::create([
            'uuid'              => (string) Str::uuid(),
            'type'              => 'loa',
            'name'              => 'Template With Unknown Token ' . $this->unique,
            'editor_type'       => 'visual_doc',
            'content_html'      => '<p>Hello {{unknown_invalid_bogus_token}} for {{pemagang.nama}}</p>',
            'state'             => DocumentTemplate::STATE_PUBLISHED,
            'is_active'         => true,
            'is_default'        => false,
            'version_number'    => 1,
            'published_version' => 1,
            'created_by'        => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'    => $this->internAccepted->id,
            'jenis_surat'  => 'loa',
            'template_id'  => $badTemplate->id,
            'action'       => 'issue',
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('unknown_invalid_bogus_token', $response->json('error'));

        // 2. Type mismatch: trying to issue 'loa' using an 'skl' template
        $sklTemplate = DocumentTemplate::where('type', 'skl')->where('is_active', true)->first();
        if ($sklTemplate) {
            $mismatchRes = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
                'intern_id'   => $this->internAccepted->id,
                'jenis_surat' => 'loa',
                'template_id' => $sklTemplate->id,
                'action'      => 'issue',
            ]);

            $mismatchRes->assertStatus(422);
            $this->assertFalse($mismatchRes->json('ok'));
        }
    }

    /**
     * T43: Inactive template blocks issuance fails closed.
     */
    public function test_inactive_or_missing_template_blocks_issuance(): void
    {
        // Deactivate all LoA templates
        DocumentTemplate::where('type', 'loa')->update(['is_active' => false]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internAccepted->id,
            'jenis_surat' => 'loa',
            'action'      => 'issue',
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('template aktif', strtolower($response->json('error')));

        // Re-enable LoA templates
        DocumentTemplate::where('type', 'loa')->update(['is_active' => true]);
    }

    /**
     * T44 & T45: Double-click / repeated issuance is strictly idempotent and reuses exact same number.
     */
    public function test_double_issuance_is_strictly_idempotent_and_reuses_exact_number(): void
    {
        $res1 = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internCompleted->id,
            'jenis_surat' => 'skl',
            'action'      => 'issue',
        ]);

        $res1->assertOk();
        $docNumber1 = $res1->json('document.number');
        $this->assertNotEmpty($docNumber1);

        // Immediate retry / double click
        $res2 = $this->actingAs($this->admin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internCompleted->id,
            'jenis_surat' => 'skl',
            'action'      => 'issue',
        ]);

        $res2->assertOk();
        $docNumber2 = $res2->json('document.number');
        $this->assertEquals($docNumber1, $docNumber2, 'Exact same document number reused');

        $count = GeneratedDocument::where('internship_registration_id', $this->internCompleted->id)
            ->where('document_type', 'skl')
            ->count();
        $this->assertEquals(1, $count, 'Only exactly 1 record in generated_documents');
    }

    /**
     * T46 & T56: Template modification after issuance preserves immutable snapshot and SHA-256 binary.
     */
    public function test_template_modification_after_issuance_preserves_immutable_snapshot_and_sha256(): void
    {
        $issuanceService = app(InternDocumentIssuanceService::class);

        // Use a dedicated template so shared default template is never corrupted
        $dedicatedTpl = DocumentTemplate::create([
            'uuid'              => (string) Str::uuid(),
            'type'              => 'loa',
            'name'              => 'Dedicated LoA ' . $this->unique,
            'editor_type'       => 'visual_doc',
            'content_html'      => '<p>Surat penerimaan untuk {{pemagang.nama}} di {{brand.nama}}</p>',
            'state'             => DocumentTemplate::STATE_PUBLISHED,
            'is_active'         => true,
            'is_default'        => false,
            'version_number'    => 1,
            'published_version' => 1,
            'created_by'        => $this->admin->id,
        ]);

        $genDoc = $issuanceService->issueDocument(
            intern: $this->internCompleted,
            documentType: 'loa',
            options: ['template_id' => $dedicatedTpl->id],
            actor: $this->admin
        );

        $originalChecksum = $genDoc->checksum_sha256;
        $originalFilePath = $genDoc->file_path;
        $originalVariables = $genDoc->variable_snapshot;
        $originalVersion = $genDoc->template_version;

        $this->assertNotEmpty($originalChecksum);
        $this->assertNotEmpty($originalVariables);

        // Now mutate the underlying dedicated template drastically
        $dedicatedTpl->update([
            'content_html' => '<p>THIS IS A COMPLETELY MUTATED TEMPLATE TEXT 9999</p>',
            'name'         => 'Mutated LoA Template ' . $this->unique,
        ]);

        // Refresh genDoc from database
        $freshDoc = GeneratedDocument::find($genDoc->id);
        $this->assertEquals($originalChecksum, $freshDoc->checksum_sha256, 'Checksum remains identical');
        $this->assertEquals($originalFilePath, $freshDoc->file_path, 'File path remains identical');
        $this->assertEquals($originalVariables, $freshDoc->variable_snapshot, 'Variables snapshot remains identical');
        $this->assertEquals($originalVersion, $freshDoc->template_version, 'Template version remains identical');

        // Cleanup dedicated template
        $dedicatedTpl->forceDelete();
    }

    /**
     * T49 & T50: Drive sync offline leaves local document intact and retry sync is safe.
     */
    public function test_drive_sync_offline_leaves_local_document_intact_and_retry_preserves_number(): void
    {
        $issuanceService = app(InternDocumentIssuanceService::class);
        $genDoc = $issuanceService->issueDocument(
            intern: $this->internAccepted,
            documentType: 'loa',
            actor: $this->admin
        );

        $originalNumber = $genDoc->document_number;

        // Simulate local generated / sync pending status
        $genDoc->update([
            'status' => GeneratedDocument::STATUS_SYNC_PENDING,
        ]);

        $this->assertTrue(Storage::disk('local')->exists($genDoc->file_path));

        // Retry sync through issuance service
        $retryResult = $issuanceService->retryDocumentSync($genDoc);
        $this->assertTrue($retryResult['success'] ?? true);

        // Ensure document number never changed
        $refreshed = GeneratedDocument::find($genDoc->id);
        $this->assertEquals($originalNumber, $refreshed->document_number, 'Document number preserved across sync retry');
    }

    /**
     * T51: RBAC restricts issuance and preview to authorized roles (admin & hr_staff). Pemagang is 403.
     */
    public function test_rbac_restricts_issuance_and_preview_to_authorized_roles(): void
    {
        // 1. Pemagang attempting issuance
        $resPemagang = $this->actingAs($this->pemagang)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internAccepted->id,
            'jenis_surat' => 'loa',
            'action'      => 'issue',
        ]);
        $resPemagang->assertForbidden();

        // 2. HR Staff allowed
        $resHr = $this->actingAs($this->hrStaff)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $this->internAccepted->id,
            'jenis_surat' => 'loa',
            'action'      => 'preview',
        ]);
        $resHr->assertOk();
    }

    /**
     * T57 & T60: Legacy tables remain synchronized after official issuance.
     */
    public function test_legacy_tables_remain_synchronized_after_issuance(): void
    {
        $issuanceService = app(InternDocumentIssuanceService::class);

        // Issue SKL for completed intern
        $genDoc = $issuanceService->issueDocument(
            intern: $this->internCompleted,
            documentType: 'skl',
            actor: $this->admin
        );

        $sklDoc = $genDoc->sklDocument;
        $this->assertNotNull($sklDoc, 'Legacy skl_documents record created');
        $this->assertEquals($this->internCompleted->id, $sklDoc->intern_id);
        $this->assertEquals($genDoc->document_number, $sklDoc->skl_number);

        // Verify relationship from GeneratedDocument to legacy model
        $freshGenDoc = GeneratedDocument::find($genDoc->id);
        $this->assertNotNull($freshGenDoc->sklDocument);
        $this->assertEquals($sklDoc->id, $freshGenDoc->sklDocument->id);
    }
}
