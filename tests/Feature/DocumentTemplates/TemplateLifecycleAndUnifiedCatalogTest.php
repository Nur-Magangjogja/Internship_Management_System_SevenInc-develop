<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\Brand;
use App\Models\Division;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\MouTemplate;
use App\Models\User;
use App\Services\DocumentTemplates\TemplateCatalogService;
use App\Services\DocumentTemplates\TemplateLifecycleService;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class TemplateLifecycleAndUnifiedCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private TemplateCatalogService $catalogService;
    private TemplateLifecycleService $lifecycleService;
    private string $unique;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        $worktreePath = realpath(dirname(__DIR__, 3));
        $_ENV['APP_BASE_PATH'] = $worktreePath;
        putenv("APP_BASE_PATH={$worktreePath}");

        $loader = require $worktreePath . '/vendor/autoload.php';
        $loader->setPsr4('App\\', [$worktreePath . '/app'], true);
        $loader->setPsr4('Tests\\', [$worktreePath . '/tests'], true);

        $app = require $worktreePath . '/bootstrap/app.php';
        $app->setBasePath($worktreePath);
        $app->register(\App\Providers\DocumentTemplateServiceProvider::class);
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app['view']->addLocation($worktreePath . '/resources/views');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->unique = uniqid('fase2_', true);

        $this->admin = User::create([
            'name'     => 'Admin ' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->catalogService = app(TemplateCatalogService::class);
        $this->lifecycleService = app(TemplateLifecycleService::class);
    }

    /**
     * 1. Katalog Gabungan Enam Jenis Dokumen (MoU, LoA, SKL, Sertifikat, Penilaian, Rekomendasi).
     */
    public function test_unified_catalog_aggregates_all_six_document_types_without_duplication()
    {
        // Buat 1 template per tipe dokumen umum
        $types = [
            DocumentTemplate::TYPE_LOA,
            DocumentTemplate::TYPE_SKL,
            DocumentTemplate::TYPE_CERTIFICATE,
            DocumentTemplate::TYPE_ASSESSMENT,
            DocumentTemplate::TYPE_RECOMMENDATION,
        ];

        foreach ($types as $type) {
            $this->lifecycleService->createDraft([
                'type' => $type,
                'name' => "Template {$type} " . $this->unique,
            ], $this->admin->id);
        }

        // Buat 1 template MOU di tabel mou_templates
        MouTemplate::create([
            'name'                 => "Template MOU " . $this->unique,
            'template_html'        => "<p>Naskah MOU {$this->unique}</p>",
            'allowed_placeholders' => [],
            'is_active'            => true,
            'is_default'           => false,
            'state'                => 'published',
            'created_by'           => $this->admin->id,
        ]);

        $catalog = $this->catalogService->getCatalog(['type' => 'all']);

        $this->assertNotEmpty($catalog['items']);
        $this->assertArrayHasKey('mou', $catalog['counts']['by_type']);
        $this->assertArrayHasKey('loa', $catalog['counts']['by_type']);
        $this->assertArrayHasKey('skl', $catalog['counts']['by_type']);
        $this->assertArrayHasKey('certificate', $catalog['counts']['by_type']);
        $this->assertArrayHasKey('assessment', $catalog['counts']['by_type']);
        $this->assertArrayHasKey('recommendation', $catalog['counts']['by_type']);

        // Verifikasi bahwa filter type=mou hanya menampilkan MoU via adapter
        $mouCatalog = $this->catalogService->getCatalog(['type' => 'mou']);
        foreach ($mouCatalog['items'] as $item) {
            $this->assertEquals(DocumentTemplate::TYPE_MOU, $item->type);
        }

        // Verifikasi filter type=loa hanya menampilkan LOA
        $loaCatalog = $this->catalogService->getCatalog(['type' => 'loa']);
        foreach ($loaCatalog['items'] as $item) {
            $this->assertEquals(DocumentTemplate::TYPE_LOA, $item->type);
        }
    }

    /**
     * 2. Universal Template Authoring: Dilarang mengikat brand, institusi, atau pemagang saat authoring (INV-01).
     */
    public function test_universal_template_authoring_prohibits_party_or_intern_binding()
    {
        // Uji penolakan saat ada binding brand_id
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Template universal tidak boleh terikat ke brand, institusi, atau pemagang");

        $this->lifecycleService->createDraft([
            'type'     => DocumentTemplate::TYPE_LOA,
            'name'     => 'Template Tidak Valid ' . $this->unique,
            'brand_id' => 99,
        ], $this->admin->id);
    }

    public function test_universal_template_authoring_prohibits_institution_binding()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Template universal tidak boleh terikat ke brand, institusi, atau pemagang");

        $this->lifecycleService->createDraft([
            'type'           => DocumentTemplate::TYPE_SKL,
            'name'           => 'Template Tidak Valid Inst ' . $this->unique,
            'institution_id' => 88,
        ], $this->admin->id);
    }

    /**
     * 3. Lifecycle Transisi: Working Draft -> Published Revision -> Active / Default -> Archived.
     */
    public function test_template_lifecycle_full_workflow_from_draft_to_published_and_archived()
    {
        // Langkah 1: Buat Working Draft
        $template = $this->lifecycleService->createDraft([
            'type'         => DocumentTemplate::TYPE_LOA,
            'name'         => 'LOA Standar ' . $this->unique,
            'content_html' => '<p>Konten Draf Awal</p>',
        ], $this->admin->id);

        $this->assertEquals(DocumentTemplate::STATE_DRAFT, $template->state);
        $this->assertFalse($template->is_active);
        $this->assertFalse($template->is_default);
        $this->assertEquals(1, $template->version_number);
        $this->assertNull($template->published_version);

        // Langkah 2: Draft belum boleh diaktifkan langsung (INV-09)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Template berstatus draf belum dapat diaktifkan');
        $this->lifecycleService->activate($template, $this->admin->id);
    }

    public function test_publishing_draft_creates_immutable_snapshot_and_allows_activation()
    {
        $template = $this->lifecycleService->createDraft([
            'type'         => DocumentTemplate::TYPE_SKL,
            'name'         => 'SKL Standar ' . $this->unique,
            'content_html' => '<p>Konten SKL Siap Terbit</p>',
        ], $this->admin->id);

        // Langkah 3: Publikasikan revisi
        $version = $this->lifecycleService->publishRevision($template, 'Rilis resmi SKL 2026', $this->admin->id);

        $template->refresh();
        $this->assertEquals(DocumentTemplate::STATE_PUBLISHED, $template->state);
        $this->assertEquals(1, $template->published_version);
        $this->assertNotNull($template->published_at);
        $this->assertNotEmpty($template->content_sha256);

        // Verifikasi snapshot di document_template_versions
        $this->assertEquals($version->id, $template->versions()->first()->id);
        $this->assertEquals('<p>Konten SKL Siap Terbit</p>', $version->content_html);

        // Langkah 4: Aktivasi & Penetapan Default
        $this->lifecycleService->activate($template, $this->admin->id);
        $template->refresh();
        $this->assertTrue($template->is_active);

        $this->lifecycleService->setDefault($template, $this->admin->id);
        $template->refresh();
        $this->assertTrue($template->is_default);
        $this->assertTrue($template->is_active);

        // Langkah 5: Pengarsipan (Archived) - Default tidak boleh langsung diarsipkan
        $caught = false;
        try {
            $this->lifecycleService->archive($template, $this->admin->id);
        } catch (InvalidArgumentException $e) {
            $caught = true;
            $this->assertStringContainsString('Template default tidak dapat diarsipkan', $e->getMessage());
        }
        $this->assertTrue($caught);

        // Buat template kedua dan jadikan default agar template pertama bisa diarsipkan
        $template2 = $this->lifecycleService->createDraft([
            'type'         => DocumentTemplate::TYPE_SKL,
            'name'         => 'SKL Pengganti ' . $this->unique,
            'content_html' => '<p>Konten Pengganti</p>',
        ], $this->admin->id);
        $this->lifecycleService->publishRevision($template2, 'Rilis v1', $this->admin->id);
        $this->lifecycleService->setDefault($template2, $this->admin->id);

        // Template pertama kini non-default, dapat diarsipkan
        $template->refresh();
        $this->assertFalse($template->is_default);
        $this->lifecycleService->archive($template, $this->admin->id);

        $template->refresh();
        $this->assertEquals(DocumentTemplate::STATE_ARCHIVED, $template->state);
        $this->assertFalse($template->is_active);
        $this->assertFalse($template->is_default);
    }

    /**
     * 4. Single Default Uniqueness per Document Type (Atomis dengan Pessimistic Locking).
     */
    public function test_single_default_per_document_type_enforced_atomically()
    {
        $tplA = $this->lifecycleService->createDraft([
            'type'         => DocumentTemplate::TYPE_ASSESSMENT,
            'name'         => 'Penilaian Versi A ' . $this->unique,
            'content_html' => '<p>Format Penilaian A</p>',
        ], $this->admin->id);
        $this->lifecycleService->publishRevision($tplA, 'Publish A', $this->admin->id);
        $this->lifecycleService->setDefault($tplA, $this->admin->id);

        $tplB = $this->lifecycleService->createDraft([
            'type'         => DocumentTemplate::TYPE_ASSESSMENT,
            'name'         => 'Penilaian Versi B ' . $this->unique,
            'content_html' => '<p>Format Penilaian B</p>',
        ], $this->admin->id);
        $this->lifecycleService->publishRevision($tplB, 'Publish B', $this->admin->id);
        $this->lifecycleService->setDefault($tplB, $this->admin->id);

        $tplA->refresh();
        $tplB->refresh();

        // Template B harus menjadi satu-satunya default
        $this->assertFalse($tplA->is_default, 'Template A harus otomatis dicabut dari status default');
        $this->assertTrue($tplB->is_default, 'Template B harus menjadi default aktif');

        // Pastikan hanya ada tepat 1 default di database untuk tipe assessment
        $defaultCount = DocumentTemplate::where('type', DocumentTemplate::TYPE_ASSESSMENT)
            ->where('is_default', true)
            ->count();
        $this->assertEquals(1, $defaultCount);
    }

    /**
     * 5. Atomis Single Default pada MOU (mou_templates).
     */
    public function test_single_default_enforced_atomically_for_mou()
    {
        $mouA = MouTemplate::create([
            'name'                 => 'MOU Master A ' . $this->unique,
            'template_html'        => '<p>Isi MOU A</p>',
            'allowed_placeholders' => [],
            'is_active'            => true,
            'is_default'           => false,
            'state'                => 'published',
            'created_by'           => $this->admin->id,
        ]);
        $this->lifecycleService->setMouDefault($mouA, $this->admin->id);

        $mouB = MouTemplate::create([
            'name'                 => 'MOU Master B ' . $this->unique,
            'template_html'        => '<p>Isi MOU B</p>',
            'allowed_placeholders' => [],
            'is_active'            => true,
            'is_default'           => false,
            'state'                => 'published',
            'created_by'           => $this->admin->id,
        ]);
        $this->lifecycleService->setMouDefault($mouB, $this->admin->id);

        $mouA->refresh();
        $mouB->refresh();

        $this->assertFalse($mouA->is_default);
        $this->assertTrue($mouB->is_default);

        $mouDefaultCount = MouTemplate::where('is_default', true)->count();
        $this->assertEquals(1, $mouDefaultCount);
    }

    /**
     * 6. Penyuntingan Published Template Membuat Draf Revisi Baru Tanpa Merusak Snapshot Terbit (INV-07).
     */
    public function test_modifying_published_template_creates_working_draft_without_mutating_published_snapshot()
    {
        $template = $this->lifecycleService->createDraft([
            'type'         => DocumentTemplate::TYPE_RECOMMENDATION,
            'name'         => 'Rekomendasi V1 ' . $this->unique,
            'content_html' => '<p>Isi Rekomendasi Versi 1</p>',
        ], $this->admin->id);

        $snapshotV1 = $this->lifecycleService->publishRevision($template, 'Rilis Versi 1', $this->admin->id);
        $this->assertEquals(1, $snapshotV1->version_number);

        // Edit konten pada template yang sudah berstatus published
        $updated = $this->lifecycleService->updateDraft($template, [
            'name'         => 'Rekomendasi V2 Draft ' . $this->unique,
            'content_html' => '<p>Isi Rekomendasi Versi 2 Baru</p>',
        ], $this->admin->id);

        $this->assertEquals(2, $updated->version_number);
        $this->assertEquals(DocumentTemplate::STATE_DRAFT, $updated->state);
        $this->assertEquals(1, $updated->published_version, 'Published version tetap menunjuk ke versi 1 selama versi 2 masih draf');

        // Pastikan snapshot historis versi 1 tidak ikut terubah!
        $snapshotV1->refresh();
        $this->assertEquals('<p>Isi Rekomendasi Versi 1</p>', $snapshotV1->content_html);
        $this->assertEquals(1, $snapshotV1->version_number);
    }

    /**
     * 7. Pemulihan Versi Historis (Restore) Menghasilkan Revisi Baru Tanpa Menimpa Histori (INV-07).
     */
    public function test_restore_version_creates_new_revision_and_preserves_historical_snapshots()
    {
        $template = $this->lifecycleService->createDraft([
            'type'         => DocumentTemplate::TYPE_LOA,
            'name'         => 'LOA Sejarah ' . $this->unique,
            'content_html' => '<p>Paragraf Pertama Asli</p>',
        ], $this->admin->id);

        $v1 = $this->lifecycleService->publishRevision($template, 'V1 Asli', $this->admin->id);

        $this->lifecycleService->updateDraft($template, [
            'content_html' => '<p>Paragraf Kedua Modifikasi</p>',
        ], $this->admin->id);
        $v2 = $this->lifecycleService->publishRevision($template, 'V2 Modifikasi', $this->admin->id);

        $this->assertEquals(2, $template->fresh()->published_version);

        // Pulihkan (Restore) kembali ke versi 1
        $restored = $this->lifecycleService->restoreVersion($template, $v1, $this->admin->id);

        // Nomor versi meningkat ke 3, isi kembali ke V1
        $this->assertEquals(3, $restored->version_number);
        $this->assertEquals(3, $restored->published_version);
        $this->assertEquals('<p>Paragraf Pertama Asli</p>', $restored->content_html);

        // Riwayat snapshot versi 1 dan versi 2 tetap utuh di database
        $this->assertEquals(3, $restored->versions()->count());
        $this->assertEquals('<p>Paragraf Pertama Asli</p>', DocumentTemplateVersion::find($v1->id)->content_html);
        $this->assertEquals('<p>Paragraf Kedua Modifikasi</p>', DocumentTemplateVersion::find($v2->id)->content_html);
    }

    /**
     * 8. Nonaktifkan Fallback Preset Tersembunyi: Penerbitan Resmi Dituntut Gagal (Fail-Closed) Jika 0 Template Aktif.
     */
    public function test_official_issuance_fails_closed_when_zero_active_templates_exist()
    {
        // Pastikan TIDAK ADA template aktif untuk SKL di database
        DocumentTemplate::where('type', DocumentTemplate::TYPE_SKL)->update(['is_active' => false]);

        $brand = Brand::create([
            'name'            => 'Seven Inc ' . substr($this->unique, 0, 6),
            'code'            => 'SI',
            'company_address' => 'Yogyakarta',
        ]);
        $division = Division::firstOrCreate(['code' => 'ENG'], ['name' => 'Engineering', 'slug' => 'engineering']);
        $institution = Institution::create(['name' => 'Universitas ' . substr($this->unique, 0, 6), 'address' => 'Yogya']);
        $faculty = \App\Models\Faculty::firstOrCreate(
            ['name' => 'Fakultas Teknik ' . substr($this->unique, 0, 6)],
            ['institution_id' => $institution->id]
        );
        $studyProgram = \App\Models\StudyProgram::firstOrCreate(
            ['name' => 'Informatika ' . substr($this->unique, 0, 6)],
            ['faculty_id' => $faculty->id]
        );

        $intern = IR::create([
            'user_id'           => $this->admin->id,
            'fullname'          => 'Pemagang Uji ' . substr($this->unique, 0, 6),
            'student_id'        => '12345678',
            'institution_id'    => $institution->id,
            'institution_name'  => $institution->name,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $studyProgram->id,
            'brand_id'          => $brand->id,
            'brand'             => $brand->name,
            'division_id'       => $division->id,
            'internship_status' => IR::STATUS_COMPLETED,
            'start_date'        => '2026-01-01',
            'end_date'          => '2026-04-01',
        ]);

        /** @var InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(InternDocumentIssuanceService::class);

        // Penerbitan resmi HARUS MENOLAK dengan pesan error tegas, BUKAN diam-diam membuat preset transien!
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Belum ada template aktif untuk jenis dokumen 'skl'. Penerbitan resmi ditolak");

        $issuanceService->issueDocument($intern, 'skl', [], $this->admin);
    }
}
