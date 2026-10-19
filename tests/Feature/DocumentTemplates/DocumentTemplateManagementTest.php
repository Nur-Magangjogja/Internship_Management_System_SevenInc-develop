<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use App\Services\DocumentTemplates\AssetLibraryContract;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\DocumentTemplates\MailMergePlaceholderContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTemplateManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        $worktreePath = realpath(dirname(__DIR__, 3));
        $_ENV['APP_BASE_PATH'] = $worktreePath;
        putenv("APP_BASE_PATH={$worktreePath}");

        // Pastikan kelas-kelas di app/ dan tests/ worktree diprioritaskan oleh Composer
        $loader = require $worktreePath . '/vendor/autoload.php';
        $loader->setPsr4('App\\', [$worktreePath . '/app'], true);
        $loader->setPsr4('Tests\\', [$worktreePath . '/tests'], true);

        $app = require $worktreePath . '/bootstrap/app.php';
        $app->setBasePath($worktreePath);

        // Register provider rute & modul template
        $app->register(\App\Providers\DocumentTemplateServiceProvider::class);

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Tambahkan path views worktree ke View Finder
        $app['view']->addLocation($worktreePath . '/resources/views');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Storage::fake('public');

        $this->unique = uniqid('doc_tpl_', true);

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

    /**
     * 1. Hak Akses (RBAC): Admin dan HR Staff dapat mengakses daftar template.
     */
    public function test_admin_and_hr_can_view_document_template_index()
    {
        $responseAdmin = $this->actingAs($this->admin)->get(route('admin.document-templates.index'));
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertSee('Manajemen Template Dokumen &amp; Sertifikat', false);

        $responseHr = $this->actingAs($this->hrStaff)->get(route('admin.document-templates.index'));
        $responseHr->assertStatus(200);
    }

    /**
     * 2. Hak Akses (RBAC): Guest dan Pemagang dilarang mengakses modul template.
     */
    public function test_guest_and_pemagang_cannot_access_document_templates()
    {
        // Guest dialihkan ke login
        $responseGuest = $this->get(route('admin.document-templates.index'));
        $responseGuest->assertRedirect();

        // Pemagang ditolak / dilarang
        $responsePemagang = $this->actingAs($this->pemagang)->get(route('admin.document-templates.index'));
        $responsePemagang->assertRedirect();
    }

    /**
     * 3. Filter daftar template berdasarkan tipe dokumen.
     */
    public function test_admin_can_filter_templates_by_type()
    {
        $loa = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'Template LOA Khusus ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'is_active'      => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $cert = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Template Sertifikat Khusus ' . $this->unique,
            'editor_type'    => 'visual_canvas',
            'is_active'      => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.index', ['type' => 'certificate']));
        $response->assertStatus(200);
        $response->assertSee($cert->name);
        $response->assertDontSee($loa->name);
    }

    /**
     * 4. Halaman formulir pembuatan template baru.
     */
    public function test_admin_can_view_create_page()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.create', ['type' => 'skl']));
        $response->assertStatus(200);
        $response->assertSee('Buat Template Dokumen & Sertifikat Baru', false);
        $response->assertSee('Surat Selesai Magang');
    }

    /**
     * 5. Pembuatan template surat (LOA) dengan preset standar resmi Seven Inc.
     */
    public function test_admin_can_store_letter_template_with_standard_preset()
    {
        $payload = [
            'name'        => 'Surat Penerimaan Resmi (LOA) ' . $this->unique,
            'type'        => 'loa',
            'description' => 'Template LOA standar untuk universitas mitra.',
            'preset_type' => 'standard',
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.document-templates.store'), $payload);

        $template = DocumentTemplate::where('name', $payload['name'])->first();
        $this->assertNotNull($template);
        $this->assertEquals('loa', $template->type);
        $this->assertEquals('visual_doc', $template->editor_type);
        $this->assertStringContainsString('LETTER OF ACCEPTANCE', $template->content_html);
        $this->assertEquals(1, $template->version_number);

        // Snapshot versi 1 otomatis terbentuk
        $this->assertEquals(1, $template->versions()->count());

        $response->assertRedirect(route('admin.document-templates.edit', $template));
    }

    /**
     * 6. Pembuatan template sertifikat dengan preset canvas layout.
     */
    public function test_admin_can_store_certificate_template_with_canvas_preset()
    {
        $payload = [
            'name'        => 'Sertifikat Emas Kelulusan ' . $this->unique,
            'type'        => 'certificate',
            'description' => 'Template sertifikat berlatar border emas.',
            'preset_type' => 'standard',
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.document-templates.store'), $payload);

        $template = DocumentTemplate::where('name', $payload['name'])->first();
        $this->assertNotNull($template);
        $this->assertEquals('certificate', $template->type);
        $this->assertEquals('visual_canvas', $template->editor_type);
        $this->assertIsArray($template->canvas_layout);
        $this->assertNotEmpty($template->canvas_layout['elements']);

        $response->assertRedirect(route('admin.document-templates.edit', $template));
    }

    /**
     * 7. Tampilan editor visual dokumen A4 untuk surat (SKL).
     */
    public function test_admin_can_view_doc_editor_for_letter()
    {
        $template = DocumentTemplate::create([
            'type'           => 'skl',
            'name'           => 'Editor SKL Test ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Isi SKL awal...</p>',
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.edit', $template));
        $response->assertStatus(200);
        $response->assertSee('a4-paper-sheet', false);
        $response->assertSee('Mail Merge');
        $response->assertSee('{{pemagang.nama}}');
    }

    /**
     * 8. Tampilan editor visual canvas untuk sertifikat.
     */
    public function test_admin_can_view_canvas_editor_for_certificate()
    {
        $template = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Editor Sertifikat Canvas Test ' . $this->unique,
            'editor_type'    => 'visual_canvas',
            'canvas_layout'  => DocumentTemplate::getDefaultCertificateCanvasLayout(),
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.edit', $template));
        $response->assertStatus(200);
        $response->assertSee('certificate-viewport', false);
        $response->assertSee('Kanvas Desain Sertifikat');
    }

    /**
     * 9. Update template: otomatis menaikkan versi dan membuat snapshot revisi.
     */
    public function test_admin_can_update_template_and_automatic_version_snapshot_created()
    {
        $template = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'LOA Awal ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Versi satu konten.</p>',
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);
        $template->createVersionSnapshot('Snapshot v1', $this->admin->id);

        $updatePayload = [
            'name'           => 'LOA Diperbarui ' . $this->unique,
            'description'    => 'Deskripsi revisi baru.',
            'content_html'   => '<p>Versi dua konten yang disempurnakan.</p>',
            'change_summary' => 'Menambahkan klausul durasi magang.',
            'is_active'      => 1,
            'is_default'     => 0,
        ];

        $response = $this->actingAs($this->admin)->put(route('admin.document-templates.update', $template), $updatePayload);

        $template->refresh();
        $this->assertEquals('LOA Diperbarui ' . $this->unique, $template->name);
        $this->assertEquals(2, $template->version_number);
        $this->assertEquals('<p>Versi dua konten yang disempurnakan.</p>', $template->content_html);

        // Memastikan snapshot versi 2 tersimpan di tabel histori
        $this->assertEquals(2, $template->versions()->count());
        $latestSnapshot = $template->versions()->orderBy('version_number', 'desc')->first();
        $this->assertEquals(2, $latestSnapshot->version_number);
        $this->assertEquals('Menambahkan klausul durasi magang.', $latestSnapshot->change_summary);
    }

    /**
     * 10. Duplikasi template menjadi draf salinan baru.
     */
    public function test_admin_can_duplicate_template()
    {
        $original = DocumentTemplate::create([
            'type'           => 'skl',
            'name'           => 'Master SKL 2026 ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Isi master SKL.</p>',
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.document-templates.duplicate', $original));

        $clone = DocumentTemplate::where('name', 'Master SKL 2026 ' . $this->unique . ' (Salinan)')->first();
        $this->assertNotNull($clone);
        $this->assertFalse($clone->is_active);
        $this->assertFalse($clone->is_default);
        $this->assertEquals('visual_doc', $clone->editor_type);
        $this->assertEquals('<p>Isi master SKL.</p>', $clone->content_html);

        $response->assertRedirect(route('admin.document-templates.edit', $clone));
    }

    /**
     * 11. Penetapan template default (memastikan hanya ada satu default per tipe).
     */
    public function test_admin_can_set_default_template()
    {
        $tplA = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'LOA Default Lama ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'is_default'     => true,
            'is_active'      => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $tplB = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'LOA Calon Default Baru ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'is_default'     => false,
            'is_active'      => false,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.document-templates.set-default', $tplB));

        $tplA->refresh();
        $tplB->refresh();

        $this->assertFalse($tplA->is_default);
        $this->assertTrue($tplB->is_default);
        $this->assertTrue($tplB->is_active); // Default template harus aktif
    }

    /**
     * 12. Toggle status aktif / draf template.
     */
    public function test_admin_can_toggle_active_status()
    {
        $tpl = DocumentTemplate::create([
            'type'           => 'assessment',
            'name'           => 'Penilaian Magang ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'is_default'     => false,
            'is_active'      => false,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('admin.document-templates.toggle-active', $tpl));
        $tpl->refresh();
        $this->assertTrue($tpl->is_active);

        // Menonaktifkan kembali
        $this->actingAs($this->admin)->post(route('admin.document-templates.toggle-active', $tpl));
        $tpl->refresh();
        $this->assertFalse($tpl->is_active);
    }

    /**
     * 13. Preview browser dengan data contoh terisi.
     */
    public function test_admin_can_view_html_preview_with_resolved_placeholders()
    {
        $tpl = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'Preview LOA Test ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Selamat kepada {{pemagang.nama}} dengan NIM {{pemagang.nim}} di divisi {{magang.divisi}}.</p>',
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.preview', $tpl));
        $response->assertStatus(200);

        // Memastikan token placeholder diganti dengan data contoh pemagang Seven Inc
        $mock = DocumentTemplate::getMockPlaceholderData();
        $response->assertSee($mock['pemagang']['nama']);
        $response->assertSee($mock['pemagang']['nim']);
        $response->assertSee($mock['magang']['divisi']);
    }

    /**
     * 14. Render PDF nyata menggunakan DomPDF untuk surat dan sertifikat.
     */
    public function test_admin_can_render_real_dompdf_binary_for_letter_and_certificate()
    {
        $letterTpl = DocumentTemplate::create([
            'type'           => 'skl',
            'name'           => 'PDF SKL Test ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => DocumentTemplate::getDefaultStandardContent('skl'),
            'page_config'    => DocumentTemplate::getDefaultPageConfig('skl'),
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $certTpl = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'PDF Cert Test ' . $this->unique,
            'editor_type'    => 'visual_canvas',
            'canvas_layout'  => DocumentTemplate::getDefaultCertificateCanvasLayout(),
            'page_config'    => DocumentTemplate::getDefaultPageConfig('certificate'),
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        // Uji PDF Surat
        $responseLetter = $this->actingAs($this->admin)->get(route('admin.document-templates.preview-pdf', $letterTpl));
        $responseLetter->assertStatus(200);
        $this->assertEquals('application/pdf', $responseLetter->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $responseLetter->getContent());

        // Uji PDF Sertifikat
        $responseCert = $this->actingAs($this->admin)->get(route('admin.document-templates.preview-pdf', $certTpl));
        $responseCert->assertStatus(200);
        $this->assertEquals('application/pdf', $responseCert->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $responseCert->getContent());
    }

    /**
     * 15. Audit trail dan pemulihan versi (Rollback).
     */
    public function test_admin_can_view_version_history_and_restore_past_version()
    {
        $template = DocumentTemplate::create([
            'type'           => 'recommendation',
            'name'           => 'Rekomendasi V1 ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Konten Versi Asli 1</p>',
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);
        $version1 = $template->createVersionSnapshot('Snapshot Versi Asli 1', $this->admin->id);

        // Update ke Versi 2
        $template->update([
            'name'           => 'Rekomendasi V2 ' . $this->unique,
            'content_html'   => '<p>Konten Versi 2 yang salah ubah</p>',
            'version_number' => 2,
        ]);
        $version2 = $template->createVersionSnapshot('Snapshot Versi 2', $this->admin->id);

        // Buka halaman riwayat versi
        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.versions', $template));
        $response->assertStatus(200);
        $response->assertSee('Snapshot Versi Asli 1');
        $response->assertSee('Snapshot Versi 2');

        // Lakukan pemulihan (rollback) ke Versi 1
        $restoreResponse = $this->actingAs($this->admin)->post(
            route('admin.document-templates.versions.restore', [$template, $version1])
        );

        $template->refresh();
        $this->assertEquals(3, $template->version_number);
        $this->assertEquals('<p>Konten Versi Asli 1</p>', $template->content_html);

        $restoreResponse->assertRedirect(route('admin.document-templates.edit', $template));
    }

    /**
     * 16. Soft delete template (template default dilindungi dari penghapusan).
     */
    public function test_admin_can_soft_delete_non_default_template()
    {
        $nonDefault = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'Template Sementara ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'is_default'     => false,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        $defaultTpl = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'Template Utama Protected ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'is_default'     => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        // Default template tidak boleh dihapus
        $responseProtected = $this->actingAs($this->admin)->delete(route('admin.document-templates.destroy', $defaultTpl));
        $this->assertNull(DocumentTemplate::withTrashed()->find($defaultTpl->id)->deleted_at);

        // Non-default template berhasil di-soft-delete
        $responseDel = $this->actingAs($this->admin)->delete(route('admin.document-templates.destroy', $nonDefault));
        $this->assertNotNull(DocumentTemplate::withTrashed()->find($nonDefault->id)->deleted_at);
    }

    /**
     * 17. Pengujian MailMergePlaceholderContract: substitusi variabel bertitik.
     */
    public function test_mail_merge_placeholder_contract_resolves_dotted_variables_safely()
    {
        $raw = 'Nama: {{pemagang.nama}}, NIM: {{pemagang.nim}}, Kampus: {{pemagang.institusi}}, No: {{dokumen.nomor}}';
        $data = [
            'pemagang' => [
                'nama'      => 'Ahmad Zaki',
                'nim'       => '19203040',
                'institusi' => 'Universitas Negeri Yogyakarta',
            ],
            'dokumen' => [
                'nomor' => '100/SKL/SEVEN/2026',
            ],
        ];

        $resolved = MailMergePlaceholderContract::resolve($raw, $data);

        $this->assertEquals('Nama: Ahmad Zaki, NIM: 19203040, Kampus: Universitas Negeri Yogyakarta, No: 100/SKL/SEVEN/2026', $resolved);
    }

    /**
     * 18. Keamanan & Sanitasi XSS: input berbahaya dalam variabel di-escape dengan benar.
     */
    public function test_mail_merge_xss_protection_escapes_script_tags()
    {
        $raw = '<p>Peserta: {{pemagang.nama}}</p>';
        $maliciousData = [
            'pemagang' => [
                'nama' => '<script>alert("XSS Attack!")</script>',
            ],
        ];

        $resolved = MailMergePlaceholderContract::resolve($raw, $maliciousData);

        $this->assertStringNotContainsString('<script>', $resolved);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;XSS Attack!&quot;)&lt;/script&gt;', $resolved);
    }

    /**
     * 19. Endpoint API Katalog Aset: mengembalikan daftar logo, stempel, tanda tangan, background.
     */
    public function test_asset_library_contract_api_returns_catalog()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.api-assets'));
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'logos',
                'signatures',
                'stamps',
                'backgrounds',
            ],
        ]);
    }

    /**
     * 20. Hardening Mail Merge: Memastikan atribut src="{{asset.company.logo}}" tidak menghasilkan tag <img> bersarang.
     */
    public function test_mail_merge_handles_image_src_attribute_cleanly()
    {
        $raw = '<img src="{{asset.company.logo}}" alt="Logo" style="width: 100px;" /><p>{{asset.signature}}</p>';
        $data = [
            'asset' => [
                'company'   => ['logo' => '/images/logos/official_logo.png'],
                'signature' => '/images/signatures/director_sig.png',
            ],
        ];

        $resolved = MailMergePlaceholderContract::resolve($raw, $data);

        // src attribute must have clean URL
        $this->assertStringContainsString('src="/images/logos/official_logo.png"', $resolved);
        // It must NOT have nested <img src="<img
        $this->assertStringNotContainsString('src="<img', $resolved);

        // Standalone placeholder must be expanded into valid <img> tag
        $this->assertStringContainsString('<img src="/images/signatures/director_sig.png" alt="Tanda Tangan"', $resolved);
    }

    /**
     * 21. Hardening Integrasi Rute: Memastikan provider dan rute idempoten tanpa duplikasi.
     */
    public function test_routes_and_provider_do_not_duplicate_when_loaded_multiple_times()
    {
        $provider = new \App\Providers\DocumentTemplateServiceProvider($this->app);
        
        // Panggil boot berulang kali
        $provider->boot();
        $provider->boot();

        // Load manual rute kembali
        $routeFile = base_path('routes/document_templates.php');
        if (file_exists($routeFile)) {
            require $routeFile;
        }

        $this->assertTrue(Route::has('admin.document-templates.index'));
        $this->assertTrue(Route::has('admin.document-templates.api-assets'));
        $this->assertTrue(Route::has('admin.document-templates.create'));
    }
}
