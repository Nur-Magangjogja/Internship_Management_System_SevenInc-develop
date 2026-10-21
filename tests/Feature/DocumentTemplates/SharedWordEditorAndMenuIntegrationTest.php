<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\Brand;
use App\Models\Division;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternLoa;
use App\Models\InternshipRegistration as IR;
use App\Models\SklDocument;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\DocumentTemplates\TemplateCatalogService;
use App\Services\DocumentTemplates\TemplateLifecycleService;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class SharedWordEditorAndMenuIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $regularUser;
    private TemplateCatalogService $catalogService;
    private TemplateLifecycleService $lifecycleService;
    private string $unique;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->unique = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        $this->catalogService = app(TemplateCatalogService::class);
        $this->lifecycleService = app(TemplateLifecycleService::class);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin-editor-test@example.com'],
            [
                'name'     => 'Admin Test Editor',
                'password' => bcrypt('password'),
                'role'     => 'admin',
            ]
        );

        $this->regularUser = User::firstOrCreate(
            ['email' => 'regular-user-test@example.com'],
            [
                'name'     => 'Regular User Test',
                'password' => bcrypt('password'),
                'role'     => 'pemagang',
            ]
        );
    }

    private function createTestIntern(string $status = 'accepted'): array
    {
        $institution = Institution::create([
            'name' => "Institut {$this->unique}",
            'type' => 'universitas',
        ]);
        $faculty = Faculty::firstOrCreate(
            ['name' => "Fakultas {$this->unique}"],
            ['institution_id' => $institution->id]
        );
        $studyProgram = StudyProgram::firstOrCreate(
            ['name' => "Prodi {$this->unique}"],
            ['faculty_id' => $faculty->id]
        );
        $division = Division::firstOrCreate(
            ['name' => "Divisi {$this->unique}"],
            ['code' => 'DIV', 'slug' => "divisi-{$this->unique}"]
        );
        $brand = Brand::firstOrCreate(
            ['name' => "Seven Inc {$this->unique}"],
            ['code' => 'SEVEN', 'slug' => "seven-{$this->unique}"]
        );
        $user = User::create([
            'name'     => "User {$this->unique}",
            'email'    => "intern-{$this->unique}@example.com",
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $intern = IR::create([
            'user_id'             => $user->id,
            'fullname'            => "User {$this->unique}",
            'student_id'          => '12345678',
            'institution_id'      => $institution->id,
            'institution_name'    => $institution->name,
            'faculty_id'          => $faculty->id,
            'study_program_id'    => $studyProgram->id,
            'division_id'         => $division->id,
            'brand_id'            => $brand->id,
            'brand'               => $brand->name,
            'internship_status'   => $status,
            'internship_interest' => 'Programmer',
            'start_date'          => '2026-01-01',
            'end_date'            => '2026-06-30',
        ]);

        return [$intern, $user, $institution, $brand];
    }

    /**
     * A01 — Dari setiap 4 menu operasional, klik 'Kelola Template' membuka katalog yang terfilter ke tipe yang benar.
     */
    public function test_a01_four_menus_contain_actionable_catalog_links_with_correct_type_filter(): void
    {
        // 1. Data LOA
        $resLoa = $this->actingAs($this->admin)->get(route('admin.documents.loas'));
        $resLoa->assertStatus(200);
        $resLoa->assertSee(route('admin.document-templates.index', ['type' => 'loa']), false);
        $resLoa->assertSee(route('admin.document-templates.create', ['type' => 'loa']), false);

        // 2. Data SKL
        $resSkl = $this->actingAs($this->admin)->get(route('admin.documents.skls'));
        $resSkl->assertStatus(200);
        $resSkl->assertSee(route('admin.document-templates.index', ['type' => 'skl']), false);
        $resSkl->assertSee(route('admin.document-templates.create', ['type' => 'skl']), false);

        // 3. Surat Penilaian
        $resAssess = $this->actingAs($this->admin)->get(route('interns.assessment.index'));
        $resAssess->assertStatus(200);
        $resAssess->assertSee(route('admin.document-templates.index', ['type' => 'assessment']), false);
        $resAssess->assertSee(route('admin.document-templates.create', ['type' => 'assessment']), false);

        // 4. Sertifikat
        $resCert = $this->actingAs($this->admin)->get(route('admin.certificate.index'));
        $resCert->assertStatus(200);
        $resCert->assertSee(route('admin.document-templates.index', ['type' => 'certificate']), false);
        $resCert->assertSee(route('admin.document-templates.create', ['type' => 'certificate']), false);

        // Verifikasi katalog merespons filter dengan benar
        $resCatalogLoa = $this->actingAs($this->admin)->get(route('admin.document-templates.index', ['type' => 'loa']));
        $resCatalogLoa->assertStatus(200);
        $resCatalogLoa->assertSee('Letter of Acceptance');

        $resCatalogSkl = $this->actingAs($this->admin)->get(route('admin.document-templates.index', ['type' => 'skl']));
        $resCatalogSkl->assertStatus(200);
        $resCatalogSkl->assertSee('SKL');
    }

    /**
     * A02 — Klik 'Buat Template' dari halaman kosong membuat draf tanpa brand/institusi/pemagang.
     */
    public function test_a02_create_draft_starts_empty_without_party_binding(): void
    {
        $types = ['loa', 'skl', 'assessment', 'recommendation'];

        foreach ($types as $type) {
            $response = $this->actingAs($this->admin)->post(route('admin.document-templates.store'), [
                'type'        => $type,
                'name'        => "Template Blank {$type} {$this->unique}",
                'description' => "Pengujian A02 draf universal {$type}",
                'editor_type' => 'visual_doc',
            ]);

            $response->assertRedirect();

            $created = DocumentTemplate::where('name', "Template Blank {$type} {$this->unique}")->first();
            $this->assertNotNull($created);
            $this->assertEquals($type, $created->type);
            $this->assertFalse($created->is_active);
            $this->assertFalse($created->is_default);

            // Verifikasi invariant INV-01: Tidak ada kolom pengikatan pihak
            $this->assertFalse(array_key_exists('brand_id', $created->getAttributes()));
            $this->assertFalse(array_key_exists('institution_id', $created->getAttributes()));
        }
    }

    /**
     * A07 — Browser Save (HTTP PUT) → DB reload → Refresh → DomPDF preview.
     */
    public function test_a07_http_put_save_reloads_from_db_and_generates_valid_pdf(): void
    {
        $template = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => "LOA Word Suite {$this->unique}",
            'description'    => 'Pengujian Save Reopen A07',
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Paragraf awal sebelum diubah.</p>',
            'editor_json'    => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Paragraf awal']]]]],
            'page_config'    => DocumentTemplate::getDefaultPageConfig('loa'),
            'is_active'      => false,
            'is_default'     => false,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
            'updated_by'     => $this->admin->id,
        ]);

        // Simulasikan HTTP PUT yang dikirim oleh Form Editor Word Studio
        $putData = [
            'name'             => "LOA Word Suite {$this->unique} - Revised",
            'description'      => 'Deskripsi revisi baru',
            'change_summary'   => 'Pembaruan daftar dan tabel',
            'template_html'    => '<h2>SURAT KEPUTUSAN PENERIMAAN</h2><ol type="1"><li>Poin satu: Berkas diterima.</li><li>Poin dua: Magang dimulai {{start_date}}.</li></ol><table style="width:100%;"><tr><td>Kolom 1</td><td>Kolom 2</td></tr></table>',
            'editor_json'      => json_encode([
                'type' => 'doc',
                'content' => [
                    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'SURAT KEPUTUSAN PENERIMAAN']]],
                    ['type' => 'orderedList', 'attrs' => ['order' => 1], 'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Poin satu: Berkas diterima.']]]]]
                    ]]
                ]
            ]),
            'header_html'      => '<p><strong>KOP SURAT RESMI SEVEN INC</strong></p>',
            'footer_html'      => '<p>Halaman 1 — Dokumen Resmi</p>',
            'margin_top_mm'    => 25.0,
            'margin_bottom_mm' => 25.0,
            'margin_left_mm'   => 20.0,
            'margin_right_mm'  => 20.0,
            'font_family'      => 'Times New Roman',
            'font_size_pt'     => 12,
            'is_active'        => 1,
        ];

        $saveResponse = $this->actingAs($this->admin)->putJson(
            route('admin.document-templates.update', $template),
            $putData
        );

        $saveResponse->assertStatus(200);
        $saveResponse->assertJson(['success' => true]);

        // Muat ulang dari database
        $reloaded = DocumentTemplate::findOrFail($template->id);
        $this->assertEquals("LOA Word Suite {$this->unique} - Revised", $reloaded->name);
        $this->assertEquals(2, $reloaded->version_number);
        $this->assertTrue($reloaded->is_active);
        $this->assertStringContainsString('SURAT KEPUTUSAN PENERIMAAN', $reloaded->content_html);
        $this->assertStringContainsString('Poin satu: Berkas diterima.', $reloaded->content_html);
        $this->assertEquals(25.0, $reloaded->page_config['margin_top_mm']);
        $this->assertEquals('<p><strong>KOP SURAT RESMI SEVEN INC</strong></p>', $reloaded->page_config['header_html']);

        // Verifikasi snapshot versi 2 tersimpan di document_template_versions
        $version2 = DocumentTemplateVersion::where('document_template_id', $template->id)
            ->where('version_number', 2)
            ->first();
        $this->assertNotNull($version2);
        $this->assertEquals('Pembaruan daftar dan tabel', $version2->change_summary);

        // Verifikasi HTML dan DomPDF rendering
        $htmlOutput = DocumentTemplateRenderer::renderLetterHtml($reloaded, [
            'start_date' => '01 November 2026',
        ]);
        $this->assertStringContainsString('01 November 2026', $htmlOutput);
        $this->assertStringContainsString('list-style-type: decimal !important', $htmlOutput);
        $this->assertStringContainsString('KOP SURAT RESMI SEVEN INC', $htmlOutput);

        // Uji pembuatan binary PDF nyata
        $pdfBinary = DocumentTemplateRenderer::renderPdfBinary($reloaded, [
            'start_date' => '01 November 2026',
        ]);
        $this->assertNotEmpty($pdfBinary);
        $this->assertStringStartsWith('%PDF', $pdfBinary);
    }

    /**
     * A08 — Published revision → edit revisi baru menghasilkan draf baru tanpa memodifikasi snapshot terbit.
     */
    public function test_a08_published_revision_snapshot_remains_immutable_when_edited(): void
    {
        $template = DocumentTemplate::create([
            'type'              => 'skl',
            'name'              => "SKL Immutability {$this->unique}",
            'content_html'      => '<p>Versi terbit resmi pertama.</p>',
            'editor_json'       => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Versi 1']]]]],
            'page_config'       => DocumentTemplate::getDefaultPageConfig('skl'),
            'state'             => DocumentTemplate::STATE_PUBLISHED,
            'is_active'         => true,
            'version_number'    => 1,
            'published_version' => 1,
            'created_by'        => $this->admin->id,
            'updated_by'        => $this->admin->id,
        ]);

        $v1Snapshot = $template->createVersionSnapshot('Rilis resmi v1', $this->admin->id);

        // Edit template yang sudah berstatus terbit
        $this->lifecycleService->updateDraft($template, [
            'name'         => "SKL Immutability {$this->unique} - Draft v2",
            'content_html' => '<p>Draf revisi kedua yang sedang dikerjakan.</p>',
        ], $this->admin->id);

        $template->refresh();
        $this->assertEquals(2, $template->version_number);
        $this->assertEquals(DocumentTemplate::STATE_DRAFT, $template->state);

        // Snapshot versi 1 di tabel versions harus tetap persis seperti semula
        $v1Check = DocumentTemplateVersion::find($v1Snapshot->id);
        $this->assertEquals(1, $v1Check->version_number);
        $this->assertEquals('<p>Versi terbit resmi pertama.</p>', $v1Check->content_html);
        $this->assertEquals("SKL Immutability {$this->unique}", $v1Check->name);
    }

    /**
     * A09 — 0 template published aktif + percobaan generate ditolak dengan pesan yang jelas.
     */
    public function test_a09_generation_rejected_when_zero_active_template_exists(): void
    {
        [$intern] = $this->createTestIntern('accepted');

        // Nonaktifkan semua template LoA aktif yang ada
        DocumentTemplate::where('type', 'loa')->update(['is_active' => false]);

        $service = app(InternDocumentIssuanceService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Belum ada template aktif|Tidak ada template/i');

        $service->issueDocument(
            intern: $intern,
            documentType: 'loa',
            actor: $this->admin
        );
    }

    /**
     * A10 — LoA eligible tapi belum issued pada Data LOA tidak berstatus 'success' atau unduh sukses palsu.
     */
    public function test_a10_eligible_loa_shows_eligible_status_not_fake_success(): void
    {
        [$intern] = $this->createTestIntern('accepted');

        // Pastikan TIDAK ADA rekam InternLoa untuk reg ini
        InternLoa::where('intern_id', $intern->id)->delete();

        $response = $this->actingAs($this->admin)->get(route('admin.documents.loas'));
        $response->assertStatus(200);

        // Harus menampilkan badge 'Siap Dibuat' dan bukan 'Berhasil'
        $response->assertSee('Siap Dibuat');
        $response->assertDontSee('Lihat LOA</a>', false); // Belum ada link unduh untuk eligible
    }

    /**
     * A13 — Unduh dokumen yang sudah issued tidak membuat nomor atau file baru.
     */
    public function test_a13_download_issued_document_does_not_regenerate_number(): void
    {
        [$intern, $user] = $this->createTestIntern('completed');

        $fixedSklNumber = "001/SKL/SEVEN/{$this->unique}/2026";
        $sklDoc = SklDocument::create([
            'intern_id'          => $intern->id,
            'user_id'            => $user->id,
            'skl_number'         => $fixedSklNumber,
            'company_name'       => 'Seven Inc Test',
            'signatory_name'     => 'Ari Setia Husbana',
            'signatory_position' => 'HRD',
            'created_at'         => now()->subDays(5),
        ]);

        // Panggil download via admin route
        $res = $this->actingAs($this->admin)->get(route('admin.skl.download.for_user', $user->id));
        $res->assertStatus(200);

        // Nomor di database tidak boleh berubah
        $sklDoc->refresh();
        $this->assertEquals($fixedSklNumber, $sklDoc->skl_number);
        $this->assertEquals(1, SklDocument::where('intern_id', $intern->id)->count());
    }

    /**
     * A14 — Cross-role / unauthorised user access ke template studio ditolak 403.
     */
    public function test_a14_unauthorised_user_cannot_access_template_studio(): void
    {
        // Pemagang biasa mencoba membuka halaman katalog atau editor template
        $resCatalog = $this->actingAs($this->regularUser)->get(route('admin.document-templates.index'));
        $this->assertTrue(in_array($resCatalog->status(), [403, 302]));

        $template = DocumentTemplate::first();
        if ($template) {
            $resEdit = $this->actingAs($this->regularUser)->get(route('admin.document-templates.edit', $template));
            $this->assertTrue(in_array($resEdit->status(), [403, 302]));
        }
    }

    /**
     * A15 — Legacy template content dari sebelum refactor membuka dan merender tanpa kehilangan isi.
     */
    public function test_a15_legacy_html_template_renders_without_dropping_content(): void
    {
        $legacyHtml = '<div class="mou-document"><div class="mou-pasal"><h3>PASAL 1: KETENTUAN UMUM</h3><p>Ini adalah naskah historis dengan token {{intern_name}} dan nomor {{nomor_surat}}.</p></div></div>';

        $template = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => "Legacy LOA {$this->unique}",
            'content_html'   => $legacyHtml,
            'editor_json'    => null, // Legacy tidak memiliki editor_json
            'page_config'    => DocumentTemplate::getDefaultPageConfig('loa'),
            'is_active'      => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
            'updated_by'     => $this->admin->id,
        ]);

        $rendered = DocumentTemplateRenderer::renderLetterHtml($template, [
            'intern_name' => 'Budi Santoso',
            'nomor_surat' => '123/LOA/2026',
        ]);

        $this->assertStringContainsString('PASAL 1: KETENTUAN UMUM', $rendered);
        $this->assertStringContainsString('Budi Santoso', $rendered);
        $this->assertStringContainsString('123/LOA/2026', $rendered);
    }
}
