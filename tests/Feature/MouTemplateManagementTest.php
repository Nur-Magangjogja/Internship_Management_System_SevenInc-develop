<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Institution;
use App\Models\MouTemplate;
use App\Models\User;
use App\Services\MouDocumentRenderer;
use Database\Seeders\MouTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MouTemplateManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->unique = uniqid('mou_', true);

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

    public function test_admin_can_view_mou_template_list()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.index'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.index');
        $response->assertSee('Template MOU');
    }

    public function test_admin_can_view_create_mou_template_page()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.create'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.create');
        $response->assertSee('{{institution_name}}', false);
        $response->assertSee('mouWordCanvas', false);
        $response->assertSee('mouHeaderCanvas', false);
        $response->assertSee('mouFooterCanvas', false);
        $response->assertSee('initMouWordProcessor', false);
        $response->assertSee('Desain & Tata Letak Tabel', false);
        $response->assertSee('Header & Footer', false);
        $response->assertSee('NOTA KESEPAHAMAN');
        $response->assertSee('PELAKSANAAN TRI DHARMA PERGURUAN TINGGI');

        // Pastikan tombol blok kaku V2 telah dihapus sesuai spesifikasi V3 Word Processor
        $response->assertDontSee('+ Blok Pasal Baru');
        $response->assertDontSee('Geser Pasal');
        $response->assertDontSee('+ Tabel Komparisi');
    }

    public function test_admin_can_create_new_mou_template_and_activity_logged()
    {
        // Pastikan template lama sudah ada
        $existing = MouTemplate::create([
            'name'                 => 'Existing Template ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Existing</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $postData = [
            'name'                    => 'Template Kemitraan Baru ' . $this->unique,
            'version_number'          => 2,
            'template_html'           => '<h1>SURAT PERJANJIAN [company_name] & [institution_name]</h1><p>Nomor: [company_mou_number]</p>',
            'company_name'            => 'PT SEVEN INC INDONESIA',
            'company_address'         => 'Jl. Ring Road Utara, Sleman',
            'company_phone'           => '0274-123456',
            'company_email'           => 'hr@seveninc.com',
            'company_signatory_name'  => 'Direktur Utama Test',
            'company_signatory_title' => 'Direktur Utama',
            'agreement_place'         => 'Sleman',
            'is_active'               => '1',
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.mou-templates.store'), $postData);

        $response->assertRedirect(route('admin.mou-templates.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('mou_templates', [
            'name'           => 'Template Kemitraan Baru ' . $this->unique,
            'version_number' => 2,
            'is_active'      => 1,
        ]);

        // Template lama harus otomatis dinonaktifkan
        $this->assertDatabaseHas('mou_templates', [
            'id'        => $existing->id,
            'is_active' => 0,
        ]);

        // Activity log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_created',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_view_edit_mou_template_page()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Edit Test ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Konten Test</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.edit', $template));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.edit');
        $response->assertSee('Template Edit Test ' . $this->unique);
        $response->assertSee('mouWordCanvas', false);
    }

    public function test_admin_can_update_mou_template_and_activity_logged()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Sebelum Diubah ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Versi Awal</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        $updateData = [
            'name'                    => 'Template Setelah Diubah ' . $this->unique,
            'version_number'          => 2,
            'template_html'           => '<p>Versi Revisi</p>',
            'company_name'            => 'SEVEN INC REVISED',
            'company_address'         => 'Yogyakarta Baru',
            'company_phone'           => '08123456789',
            'company_email'           => 'partnership@seveninc.com',
            'company_signatory_name'  => 'Signatory Baru',
            'company_signatory_title' => 'Chief Executive Officer',
            'agreement_place'         => 'Yogyakarta',
        ];

        $response = $this->actingAs($this->admin)->put(route('admin.mou-templates.update', $template), $updateData);

        $response->assertRedirect(route('admin.mou-templates.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('mou_templates', [
            'id'             => $template->id,
            'name'           => 'Template Setelah Diubah ' . $this->unique,
            'version_number' => 2,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_updated',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_save_and_reopen_preserves_page_config_media_assets_and_company_defaults()
    {
        $postData = [
            'name'                        => 'Template Roundtrip Config ' . $this->unique,
            'version_number'              => 10,
            'template_html'               => '<div class="mou-doc-root"><p style="text-align: justify;">Isi dokumen [institution_name]</p></div>',
            'company_name'                => 'PT. SEVEN INC. CUSTOM',
            'company_address'             => 'Jl. Janti No. 59 Bantul',
            'company_phone'               => '(0274) 555777',
            'company_fax'                 => '(0274) 555888',
            'company_email'               => 'legal@seveninc.co.id',
            'company_signatory_name'      => 'Rekario Danny Sanjaya, S.Kom',
            'company_signatory_title'     => 'Direktur',
            'company_correspondence_unit' => 'Divisi Legal & Kemitraan',
            'agreement_place'             => 'Bantul',
            'agreement_duration'          => '3 (tiga) tahun',
            'paper_size'                  => 'A4',
            'orientation'                 => 'portrait',
            'margin_top_mm'               => 25,
            'margin_right_mm'             => 18,
            'margin_bottom_mm'            => 35,
            'margin_left_mm'              => 22,
            'font_family'                 => 'Georgia, serif',
            'font_size_pt'                => 11,
            'line_height'                 => 1.5,
            'show_paraf_footer'           => '1',
            'show_page_numbers'           => '0',
            'company_logo'                => '/images/mou/seven-inc-logo.png',
            'institution_logo'            => 'none',
            'is_active'                   => '1',
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.mou-templates.store'), $postData);
        $response->assertRedirect(route('admin.mou-templates.index'));

        $template = MouTemplate::where('name', 'Template Roundtrip Config ' . $this->unique)->firstOrFail();

        $pageCfg = $template->getPageConfig();
        $this->assertEquals(25.0, $pageCfg['margin_top_mm']);
        $this->assertEquals(18.0, $pageCfg['margin_right_mm']);
        $this->assertEquals(35.0, $pageCfg['margin_bottom_mm']);
        $this->assertEquals(22.0, $pageCfg['margin_left_mm']);
        $this->assertEquals('Georgia, serif', $pageCfg['font_family']);
        $this->assertEquals(11.0, $pageCfg['font_size_pt']);
        $this->assertEquals(1.5, $pageCfg['line_height']);
        $this->assertTrue($pageCfg['show_paraf_footer']);
        $this->assertFalse($pageCfg['show_page_numbers']);

        $mediaCfg = $template->getMediaAssets();
        $this->assertEquals('/images/mou/seven-inc-logo.png', $mediaCfg['company_logo']);
        $this->assertEquals('none', $mediaCfg['institution_logo']);

        $compDefs = $template->getCompanyDefaultValues();
        $this->assertEquals('PT. SEVEN INC. CUSTOM', $compDefs['company_name']);
        $this->assertEquals('(0274) 555888', $compDefs['company_fax']);
        $this->assertEquals('Divisi Legal & Kemitraan', $compDefs['company_correspondence_unit']);
        $this->assertEquals('3 (tiga) tahun', $compDefs['agreement_duration']);

        // Buka kembali halaman edit dan pastikan konfigurasi ter-hydrate
        $editResponse = $this->actingAs($this->admin)->get(route('admin.mou-templates.edit', $template));
        $editResponse->assertStatus(200);
        $editResponse->assertSee('Divisi Legal & Kemitraan');
        $editResponse->assertSee('3 (tiga) tahun');
    }

    public function test_automatic_pasal_and_ayat_renumbering_on_save_and_render()
    {
        // Simulasikan Admin menghapus Pasal 2 & Pasal 4 sehingga tersisa Pasal 1, Pasal 3, Pasal 5
        // serta nomor ayat tidak berurutan ((1), (4), (9))
        $unOrderedHtml = <<<'HTML'
<div class="mou-doc-root">
    <section class="mou-pasal-block" data-pasal="true">
        <div class="mou-pasal-header">
            <p class="mou-pasal-number">Pasal 1</p>
            <p class="mou-pasal-title">MAKSUD DAN TUJUAN</p>
        </div>
        <ol class="mou-ayat-list">
            <li class="mou-ayat-item"><span class="mou-ayat-num">(1)</span><div class="mou-ayat-body"><p>Ayat kesatu pasal pertama.</p></div></li>
            <li class="mou-ayat-item"><span class="mou-ayat-num">(4)</span><div class="mou-ayat-body"><p>Ayat kedua yang sebelumnya bernomor empat.</p></div></li>
        </ol>
    </section>
    <section class="mou-pasal-block" data-pasal="true">
        <div class="mou-pasal-header">
            <p class="mou-pasal-number">Pasal 3</p>
            <p class="mou-pasal-title">PELAKSANAAN KEGIATAN</p>
        </div>
        <ol class="mou-ayat-list">
            <li class="mou-ayat-item"><span class="mou-ayat-num">(9)</span><div class="mou-ayat-body"><p>Ayat pertama di pasal kedua.</p></div></li>
            <li class="mou-ayat-item"><span class="mou-ayat-num">(12)</span><div class="mou-ayat-body"><p>Ayat kedua di pasal kedua.</p></div></li>
            <li class="mou-ayat-item"><span class="mou-ayat-num">(15)</span><div class="mou-ayat-body"><p>Ayat ketiga di pasal kedua.</p></div></li>
        </ol>
    </section>
    <section class="mou-pasal-block" data-pasal="true">
        <div class="mou-pasal-header">
            <p class="mou-pasal-number">Pasal 5</p>
            <p class="mou-pasal-title">PENUTUP</p>
        </div>
        <ol class="mou-ayat-list">
            <li class="mou-ayat-item"><span class="mou-ayat-num">(7)</span><div class="mou-ayat-body"><p>Ayat penutup.</p></div></li>
        </ol>
    </section>
</div>
HTML;

        $response = $this->actingAs($this->admin)->post(route('admin.mou-templates.store'), [
            'name'           => 'Template Auto Numbering ' . $this->unique,
            'version_number' => 11,
            'template_html'  => $unOrderedHtml,
            'is_active'      => '0',
        ]);

        $response->assertRedirect(route('admin.mou-templates.index'));

        $template = MouTemplate::where('name', 'Template Auto Numbering ' . $this->unique)->firstOrFail();

        // Pastikan Pasal 1, Pasal 2, Pasal 3 berurutan otomatis dan Pasal 5 sudah berubah jadi Pasal 3
        $this->assertStringContainsString('>Pasal 1<', $template->template_html);
        $this->assertStringContainsString('>Pasal 2<', $template->template_html);
        $this->assertStringContainsString('>Pasal 3<', $template->template_html);
        $this->assertStringNotContainsString('>Pasal 5<', $template->template_html);

        // Pastikan ayat dinormalisasi ulang menjadi (1), (2), (3) dan bukan (4), (9), (12), (15), (7)
        $this->assertStringContainsString('>(1)<', $template->template_html);
        $this->assertStringContainsString('>(2)<', $template->template_html);
        $this->assertStringContainsString('>(3)<', $template->template_html);
        $this->assertStringNotContainsString('>(4)<', $template->template_html);
        $this->assertStringNotContainsString('>(9)<', $template->template_html);
        $this->assertStringNotContainsString('>(15)<', $template->template_html);
    }

    public function test_allowlist_html_and_css_sanitizer_strips_xss_scripts_event_handlers_and_untrusted_images()
    {
        $maliciousHtml = <<<'HTML'
<div class="mou-doc-root" onclick="alert('xss')">
    <script>window.stealCookies();</script>
    <iframe src="https://evil.example.com/frame"></iframe>
    <p style="text-align: justify; position: fixed; top: 0; background-image: url(javascript:alert(1)); font-weight: bold;">
        Paragraf Resmi [institution_name]
    </p>
    <img src="https://evil.example.com/remote-logo.png" onerror="alert('xss')">
    <img src="/images/mou/seven-inc-logo.png" alt="Logo Aman" style="width: 85px;">
</div>
HTML;

        /** @var MouDocumentRenderer $renderer */
        $renderer = app(MouDocumentRenderer::class);
        $sanitized = $renderer->sanitizeEditableTemplate($maliciousHtml);

        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('stealCookies', $sanitized);
        $this->assertStringNotContainsString('<iframe', $sanitized);
        $this->assertStringNotContainsString('onclick', $sanitized);
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringNotContainsString('position: fixed', $sanitized);
        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringNotContainsString('evil.example.com', $sanitized);

        // Elemen aman dan gambar lokal tetap dipertahankan
        $this->assertStringContainsString('Paragraf Resmi [institution_name]', $sanitized);
        $this->assertStringContainsString('text-align: justify', $sanitized);
        $this->assertStringContainsString('/images/mou/seven-inc-logo.png', $sanitized);
    }

    public function test_admin_can_upload_valid_logo_media_and_rejects_invalid_files_and_unauthorized_roles()
    {
        $validPng = UploadedFile::fake()->image('logo_kampus.png', 240, 240);

        $response = $this->actingAs($this->admin)->postJson(route('admin.mou-templates.upload-media'), [
            'media_file' => $validPng,
            'slot'       => 'institution_logo',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['url', 'slot', 'width', 'height'])
            ->assertJsonPath('slot', 'institution_logo');

        $uploadedUrl = $response->json('url');
        $this->assertStringStartsWith('/storage/mou-templates/media/', $uploadedUrl);

        // Uji upload file berbahaya (bukan gambar)
        $fakePhp = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');
        $badResponse = $this->actingAs($this->admin)->postJson(route('admin.mou-templates.upload-media'), [
            'media_file' => $fakePhp,
            'slot'       => 'company_logo',
        ]);
        $badResponse->assertStatus(422);

        // Uji RBAC: HR Staff dan Pemagang tidak boleh upload media template
        $hrResponse = $this->actingAs($this->hrStaff)->postJson(route('admin.mou-templates.upload-media'), [
            'media_file' => $validPng,
        ]);
        $this->assertTrue(in_array($hrResponse->status(), [403, 302]));
    }

    public function test_admin_can_download_a4_preview_pdf_with_running_paraf_footer_and_page_numbers()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Word A4 PDF ' . $this->unique,
            'version_number'       => 3,
            'template_html'        => MouTemplate::getWordStandardTemplateContent(),
            'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
            'default_values'       => array_merge(
                MouTemplate::getDefaultCompanyValues(),
                [
                    '_page_config'  => MouTemplate::getDefaultPageConfig(),
                    '_media_assets' => MouTemplate::getDefaultMediaAssets(),
                ]
            ),
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.preview-pdf', $template));

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $pdfBinary = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.', $pdfBinary);
        $this->assertGreaterThan(5000, strlen($pdfBinary));

        // Pastikan HTML yang dirender ke PDF memiliki ruang footer khusus dan tabel Paraf
        $pdfViewHtml = view('hr.mou.pdf-document', [
            'title'        => $template->name,
            'renderedHtml' => $template->renderContent([], true),
            'pageConfig'   => $template->getPageConfig(),
            'canonicalCss' => app(MouDocumentRenderer::class)->getCanonicalCss(),
        ])->render();

        $this->assertStringContainsString('Paraf PIHAK KESATU', $pdfViewHtml);
        $this->assertStringContainsString('Paraf PIHAK KEDUA', $pdfViewHtml);
        $this->assertStringContainsString('Halaman {PAGE_NUM} dari {PAGE_COUNT}', $pdfViewHtml);
        // Pastikan margin bawah dokumen diamankan secara dinamis (>= 44mm untuk paraf standar) agar footer tidak menimpa teks/tabel
        $this->assertMatchesRegularExpression('/margin:\s*25\.4mm\s+25\.4mm\s+4[4-9](\.\d+)?mm\s+25\.4mm/', $pdfViewHtml);
    }

    public function test_default_institution_logo_is_neutral_and_not_umpo_for_general_institutions()
    {
        $mediaAssets = MouTemplate::getDefaultMediaAssets();

        $this->assertEquals('/images/mou/seven-inc-logo.png', $mediaAssets['company_logo']);
        $this->assertEquals('/images/mou/institution-logo-placeholder.png', $mediaAssets['institution_logo']);
        $this->assertStringNotContainsString('umpo', strtolower($mediaAssets['institution_logo']));
    }

    public function test_mou_template_seeder_adds_v3_word_template_without_overwriting_existing_templates()
    {
        // Buat template v1 yang sudah dikustomisasi Admin
        $customV1 = MouTemplate::updateOrCreate(
            ['version_number' => 1],
            [
                'name'                 => 'Custom Admin V1 ' . $this->unique,
                'template_html'        => '<p>Naskah Kustom Admin V1 Jangan Ditimpa</p>',
                'allowed_placeholders' => ['institution_name'],
                'default_values'       => ['company_name' => 'SEVEN INC CUSTOM V1'],
                'is_active'            => true,
                'created_by'           => $this->admin->id,
            ]
        );

        // Hapus v3 jika ada agar kita dapat menguji bahwa seeder membuat v3 tanpa menimpa v1
        MouTemplate::withTrashed()->where('version_number', 3)->forceDelete();

        $this->seed(MouTemplateSeeder::class);

        // V1 tetap utuh seperti kustomisasi Admin
        $customV1->refresh();
        $this->assertEquals('<p>Naskah Kustom Admin V1 Jangan Ditimpa</p>', $customV1->template_html);
        $this->assertTrue($customV1->is_active);

        // V3 berhasil ditambahkan sebagai versi baru tanpa merusak V1
        $seededV3 = MouTemplate::where('version_number', 3)->first();
        $this->assertNotNull($seededV3);
        $this->assertStringContainsString('PELAKSANAAN TRI DHARMA PERGURUAN TINGGI', $seededV3->template_html);
    }

    public function test_admin_can_toggle_template_active_state()
    {
        $template1 = MouTemplate::create([
            'name'                 => 'Template Satu ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Satu</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $template2 = MouTemplate::create([
            'name'                 => 'Template Dua ' . $this->unique,
            'version_number'       => 2,
            'template_html'        => '<p>Dua</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        // Aktifkan template 2
        $response = $this->actingAs($this->admin)->post(route('admin.mou-templates.toggle-active', $template2));

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('mou_templates', [
            'id'        => $template2->id,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('mou_templates', [
            'id'        => $template1->id,
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_activated',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_preview_mou_template_with_rendered_placeholders()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Preview ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<div>Mitra: [institution_name] | Perusahaan: [company_name]</div>',
            'allowed_placeholders' => ['institution_name', 'company_name'],
            'default_values'       => ['company_name' => 'SEVEN INC REKSA'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.preview', $template));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.preview');
        // Harus me-render konten yang di-parse tokennya (default mock institution name atau nama perusahaan)
        $response->assertSee('SEVEN INC REKSA');
        $response->assertSee('UNIVERSITAS CONTOH INDONESIA');
    }

    public function test_admin_can_soft_delete_mou_template_and_activity_logged()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template To Delete ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Hapus saya</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.mou-templates.destroy', $template));

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        // Pastikan ter-soft delete
        $this->assertSoftDeleted('mou_templates', [
            'id' => $template->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_deleted',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_hr_staff_and_pemagang_cannot_manage_mou_templates()
    {
        // HR staff coba akses index template (role:admin only middleware)
        $responseHr = $this->actingAs($this->hrStaff)->get(route('admin.mou-templates.index'));
        $this->assertTrue(in_array($responseHr->status(), [403, 302]));

        // Pemagang coba akses index template
        $responsePemagang = $this->actingAs($this->pemagang)->get(route('admin.mou-templates.index'));
        $this->assertTrue(in_array($responsePemagang->status(), [403, 302]));
    }

    public function test_guest_cannot_access_mou_templates_and_is_redirected()
    {
        $responseGuest = $this->get(route('admin.mou-templates.index'));
        $responseGuest->assertRedirect(route('user.login'));
    }

    public function test_sanitizer_unwraps_a4_page_sheets_and_merges_split_pasal_and_table_continuations_into_canonical_html()
    {
        $paginatedHtml = <<<'HTML'
<div class="mou-a4-pages-container">
    <div class="mou-a4-page" data-page-number="1">
        <div class="mou-a4-page-header" data-pagination-artifact="true">Kertas A4 — Halaman 1 dari 2</div>
        <div class="mou-a4-page-body mou-doc-canvas">
            <div class="mou-pasal" data-pasal="true">
                <div class="mou-pasal-heading">
                    <div class="mou-pasal-label">Pasal <span class="mou-pasal-num">9</span></div>
                    <div class="mou-pasal-title">RUANG LINGKUP DAN KORESPONDENSI</div>
                </div>
                <div class="mou-pasal-body">
                    <ol class="mou-ayat-list">
                        <li class="mou-ayat-item" data-ayat="true">
                            <span class="mou-ayat-num">(9)</span>
                            <div class="mou-ayat-text">Ayat pertama di halaman kesatu.</div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
        <div class="mou-a4-page-footer" data-pagination-artifact="true">Halaman 1 dari 2</div>
    </div>
    <div class="mou-a4-page" data-page-number="2">
        <div class="mou-a4-page-header" data-pagination-artifact="true">Kertas A4 — Halaman 2 dari 2</div>
        <div class="mou-a4-page-body mou-doc-canvas">
            <div class="mou-pasal mou-pasal-continuation" data-pasal-continuation="true">
                <div class="mou-pasal-body">
                    <ol class="mou-ayat-list">
                        <li class="mou-ayat-item" data-ayat="true">
                            <span class="mou-ayat-num">(8)</span>
                            <div class="mou-ayat-text">Ayat kedua lanjutan di halaman kedua.</div>
                        </li>
                    </ol>
                </div>
            </div>
            <div class="mou-pasal" data-pasal="true">
                <div class="mou-pasal-heading">
                    <div class="mou-pasal-label">Pasal <span class="mou-pasal-num">15</span></div>
                    <div class="mou-pasal-title">PENUTUP</div>
                </div>
                <div class="mou-pasal-body">
                    <ol class="mou-ayat-list">
                        <li class="mou-ayat-item" data-ayat="true">
                            <span class="mou-ayat-num">(5)</span>
                            <div class="mou-ayat-text">Ayat pertama pada pasal kedua.</div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
        <div class="mou-a4-page-footer" data-pagination-artifact="true">Halaman 2 dari 2</div>
    </div>
</div>
HTML;

        $canonical = MouDocumentRenderer::sanitizeEditableTemplate($paginatedHtml, true);

        // Artefak halaman (.mou-a4-page, .mou-a4-page-header, .mou-a4-page-footer, .mou-pasal-continuation) harus bersih dari HTML kanonis
        $this->assertStringNotContainsString('mou-a4-page', $canonical);
        $this->assertStringNotContainsString('mou-pasal-continuation', $canonical);
        $this->assertStringNotContainsString('Kertas A4 — Halaman', $canonical);

        // Hanya ada 2 blok .mou-pasal kanonis (Pasal 1 dengan 2 ayat yang telah digabung, dan Pasal 2 dengan 1 ayat)
        $this->assertEquals(2, substr_count($canonical, 'data-pasal="true"'));
        $this->assertStringContainsString('<span class="mou-pasal-num">1</span>', $canonical);
        $this->assertStringContainsString('<span class="mou-pasal-num">2</span>', $canonical);
        $this->assertStringContainsString('<span class="mou-ayat-num">(1)</span>', $canonical);
        $this->assertStringContainsString('<span class="mou-ayat-num">(2)</span>', $canonical);
        $this->assertStringContainsString('Ayat pertama di halaman kesatu.', $canonical);
        $this->assertStringContainsString('Ayat kedua lanjutan di halaman kedua.', $canonical);
    }

    public function test_create_and_edit_pages_do_not_duplicate_company_default_inputs_and_use_selective_bold_typography()
    {
        $createResponse = $this->actingAs($this->admin)->get(route('admin.mou-templates.create'));
        $createResponse->assertStatus(200);
        $createHtml = $createResponse->getContent();

        // Pastikan input company_name dan company_signatory_name hanya dirender satu kali (tidak duplikat)
        $this->assertEquals(1, substr_count($createHtml, 'name="company_name"'));
        $this->assertEquals(1, substr_count($createHtml, 'name="company_signatory_name"'));
        $this->assertEquals(1, substr_count($createHtml, 'name="company_address"'));

        // Pastikan naskah standar Word tidak memberikan style="font-weight: bold;" pada seluruh div.mou-ayat-text
        $stdHtml = MouTemplate::getWordStandardTemplateContent();
        $this->assertStringNotContainsString('<div class="mou-ayat-text" style="font-weight: bold;">', $stdHtml);
        $this->assertStringContainsString('<strong>PARA PIHAK</strong>', $stdHtml);
        $this->assertStringContainsString('<strong>PIHAK KESATU</strong>', $stdHtml);
        $this->assertStringContainsString('<strong>PIHAK KEDUA</strong>', $stdHtml);
    }

    public function test_dompdf_renders_single_page_two_page_and_multi_page_documents_accurately()
    {
        $pageConfig = MouTemplate::getDefaultPageConfig();

        // 1. Dokumen 1 halaman singkat
        $onePageHtml = '<div class="mou-document mou-doc-canvas"><p>Dokumen Singkat Satu Halaman</p></div>';
        $pdf1 = MouDocumentRenderer::renderPdfBinary($onePageHtml, $pageConfig, 'Test 1 Page');
        $pageCount1 = preg_match_all('/\/Type\s*\/Page\b/', $pdf1);
        $this->assertEquals(1, $pageCount1);

        // 2. Dokumen 2 halaman dengan manual page break
        $twoPageHtml = '<div class="mou-document mou-doc-canvas"><p>Halaman Satu</p><div class="mou-page-break" data-page-break="true"></div><p>Halaman Dua</p></div>';
        $pdf2 = MouDocumentRenderer::renderPdfBinary($twoPageHtml, $pageConfig, 'Test 2 Pages');
        $pageCount2 = preg_match_all('/\/Type\s*\/Page\b/', $pdf2);
        $this->assertEquals(2, $pageCount2);

        // 3. Dokumen standar Nota Kesepahaman Tri Dharma Perguruan Tinggi (multi-halaman dengan 7 Pasal & tabel korespondensi + tanda tangan)
        $template = new MouTemplate([
            'name'                 => 'Standar Multi Halaman',
            'version_number'       => 3,
            'template_html'        => MouTemplate::getWordStandardTemplateContent(),
            'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
            'default_values'       => array_merge(
                MouTemplate::getDefaultCompanyValues(),
                [
                    '_page_config'  => $pageConfig,
                    '_media_assets' => MouTemplate::getDefaultMediaAssets(),
                ]
            ),
        ]);
        $renderedMulti = $template->renderContent([
            'institution_name'            => 'UNIVERSITAS MUHAMMADIYAH PONOROGO',
            'institution_mou_number'      => '807/I.0/K/2025',
            'company_mou_number'          => '004/MOU/SEVENINC/V/2025',
            'institution_signatory_name'  => 'Dr. Rido Kurnianto, M.Ag.',
            'institution_signatory_title' => 'Rektor',
            'institution_address'         => 'Jalan Budi Utomo Nomor 10 Ponorogo 63471',
        ], false);

        $pdfMulti = MouDocumentRenderer::renderPdfBinary($renderedMulti, $pageConfig, 'Test Multi Pages');
        $pageCountMulti = preg_match_all('/\/Type\s*\/Page\b/', $pdfMulti);
        $this->assertGreaterThanOrEqual(2, $pageCountMulti);
    }

    public function test_v3_roundtrip_table_column_row_height_and_header_footer_paraf_persistence_across_save_reopen_and_update()
    {
        $customBodyHtml = <<<'HTML'
<p style="text-align: center; line-height: 1.5;"><strong>PERJANJIAN KERJA SAMA BEBAS V3</strong></p>
<table class="mou-signature-table" data-table-width="480pt" style="width: 480pt; border-collapse: collapse;">
    <colgroup>
        <col width="240" style="width: 240px;">
        <col width="240" style="width: 240px;">
    </colgroup>
    <tbody>
        <tr>
            <td colwidth="240" data-cell-width="240px" style="width: 240px; text-align: center;"><strong>PIHAK KESATU</strong></td>
            <td colwidth="240" data-cell-width="240px" style="width: 240px; text-align: center;"><strong>PIHAK KEDUA</strong></td>
        </tr>
        <tr data-row-height="35mm" style="height: 35mm;">
            <td colwidth="240" data-row-height="35mm" style="width: 240px; height: 35mm;">&nbsp;</td>
            <td colwidth="240" data-row-height="35mm" style="width: 240px; height: 35mm;">&nbsp;</td>
        </tr>
    </tbody>
</table>
HTML;

        $customHeaderHtml = '<p style="text-align: right; font-size: 9pt;">Kop Resmi {{company_name}} &amp; {{institution_name}}</p>';
        $customFirstHeaderHtml = '<p style="text-align: center; font-size: 10pt;"><strong>HEADER KHUSUS HALAMAN PERTAMA {{company_mou_number}}</strong></p>';
        $customFooterParafHtml = <<<'HTML'
<table class="mou-paraf-table" data-table-width="260pt" style="width: 260pt; border-collapse: collapse;">
    <colgroup>
        <col width="180" style="width: 180px;">
        <col width="166" style="width: 166px;">
    </colgroup>
    <tbody>
        <tr>
            <td colwidth="180" data-cell-width="180px" style="width: 180px; border: 1px solid #000000; padding: 3pt 5pt;"><strong>Paraf Mitra {{institution_name}}</strong></td>
            <td colwidth="166" data-cell-width="166px" style="width: 166px; border: 1px solid #000000; padding: 3pt 5pt;"><strong>Paraf {{company_name}}</strong></td>
        </tr>
        <tr data-row-height="32pt" style="height: 32pt;">
            <td colwidth="180" data-row-height="32pt" style="width: 180px; height: 32pt; border: 1px solid #000000;">&nbsp;</td>
            <td colwidth="166" data-row-height="32pt" style="width: 166px; height: 32pt; border: 1px solid #000000;">&nbsp;</td>
        </tr>
    </tbody>
</table>
HTML;

        $sampleEditorJson = json_encode([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'PERJANJIAN KERJA SAMA BEBAS V3']]],
            ],
        ]);

        // 1. Simpan pertama kali (Store)
        $storeResponse = $this->actingAs($this->admin)->post(route('admin.mou-templates.store'), [
            'name'                   => 'Template V3 Roundtrip ' . $this->unique,
            'version_number'         => 30,
            'template_html'          => $customBodyHtml,
            'editor_json'            => $sampleEditorJson,
            'header_html'            => $customHeaderHtml,
            'footer_html'            => $customFooterParafHtml,
            'first_page_header_html' => $customFirstHeaderHtml,
            'first_page_footer_html' => $customFooterParafHtml,
            'different_first_page'   => '1',
            'header_distance_mm'     => 14.5,
            'footer_distance_mm'     => 11.5,
            'margin_top_mm'          => 25.4,
            'margin_right_mm'        => 25.4,
            'margin_bottom_mm'       => 25.4,
            'margin_left_mm'         => 25.4,
            'show_paraf_box'         => '1',
            'show_page_number'       => '1',
            'is_active'              => '1',
        ]);
        $storeResponse->assertRedirect(route('admin.mou-templates.index'));

        $template = MouTemplate::where('name', 'Template V3 Roundtrip ' . $this->unique)->firstOrFail();

        // Verifikasi atribut ukuran tabel TTD di body tersimpan
        $this->assertStringContainsString('data-table-width="480pt"', $template->template_html);
        $this->assertStringContainsString('data-row-height="35mm"', $template->template_html);
        $this->assertStringContainsString('colwidth="240"', $template->template_html);

        // Verifikasi konfigurasi V3 Header/Footer & Kotak Paraf tersimpan
        $cfg = $template->getPageConfig();
        $this->assertEquals(3, $cfg['_schema_version']);
        $this->assertTrue($cfg['different_first_page']);
        $this->assertEquals(14.5, $cfg['header_distance_mm']);
        $this->assertEquals(11.5, $cfg['footer_distance_mm']);
        $this->assertStringContainsString('data-table-width="260pt"', $cfg['footer_html']);
        $this->assertStringContainsString('data-row-height="32pt"', $cfg['footer_html']);
        $this->assertStringContainsString('colwidth="180"', $cfg['footer_html']);
        $this->assertStringContainsString('HEADER KHUSUS HALAMAN PERTAMA', $cfg['first_page_header_html']);
        $this->assertNotNull($template->getEditorJson());

        // 2. Buka kembali di halaman Edit (Reopen)
        $editResponse = $this->actingAs($this->admin)->get(route('admin.mou-templates.edit', $template));
        $editResponse->assertStatus(200);
        $editResponse->assertSee('data-table-width="480pt"');
        $editResponse->assertSee('data-row-height="35mm"');
        $editResponse->assertSee('data-row-height="32pt"');

        // 3. Simpan ulang (Update) dengan tinggi baris Paraf diperbesar ke 40pt
        $updatedFooterParafHtml = str_replace('32pt', '40pt', $cfg['footer_html']);
        $updateResponse = $this->actingAs($this->admin)->put(route('admin.mou-templates.update', $template), [
            'name'                   => $template->name,
            'version_number'         => 31,
            'template_html'          => $template->template_html,
            'editor_json'            => json_encode($template->getEditorJson()),
            'header_html'            => $cfg['header_html'],
            'footer_html'            => $updatedFooterParafHtml,
            'first_page_header_html' => $cfg['first_page_header_html'],
            'first_page_footer_html' => $updatedFooterParafHtml,
            'different_first_page'   => '1',
            'header_distance_mm'     => 14.5,
            'footer_distance_mm'     => 11.5,
            'show_paraf_box'         => '1',
            'show_page_number'       => '1',
        ]);
        $updateResponse->assertRedirect(route('admin.mou-templates.index'));

        $template->refresh();
        $updatedCfg = $template->getPageConfig();
        $this->assertStringContainsString('data-row-height="40pt"', $updatedCfg['footer_html']);
        $this->assertStringContainsString('height: 40pt', $updatedCfg['footer_html']);
        $this->assertStringContainsString('data-row-height="35mm"', $template->template_html);
    }

    public function test_v3_enlarged_paraf_footer_table_dynamically_expands_dompdf_page_bottom_margin_and_prevents_overlap()
    {
        $defaultCfg = MouTemplate::getDefaultPageConfig();

        // Render dengan Paraf standar (18pt)
        $standardPdfHtml = view('hr.mou.pdf-document', [
            'title'        => 'Standar Paraf',
            'renderedHtml' => '<div class="mou-document"><p>Isi dokumen standar</p></div>',
            'pageConfig'   => $defaultCfg,
            'canonicalCss' => MouDocumentRenderer::getCanonicalDocumentCss($defaultCfg),
        ])->render();

        preg_match('/@page\s*\{\s*margin:\s*([0-9.]+)mm\s+([0-9.]+)mm\s+([0-9.]+)mm\s+([0-9.]+)mm;/i', $standardPdfHtml, $mStd);
        $this->assertNotEmpty($mStd);
        $stdBottomMarginMm = (float) $mStd[3];
        $this->assertGreaterThanOrEqual(44.0, $stdBottomMarginMm);

        // Perbesar tinggi baris Kotak Paraf menjadi 45mm
        $enlargedCfg = $defaultCfg;
        $enlargedCfg['footer_html'] = str_replace('18pt', '45mm', MouTemplate::getDefaultFooterParafHtml());

        $enlargedPdfHtml = view('hr.mou.pdf-document', [
            'title'        => 'Enlarged Paraf',
            'renderedHtml' => '<div class="mou-document"><p>Isi dokumen paraf besar</p></div>',
            'pageConfig'   => $enlargedCfg,
            'canonicalCss' => MouDocumentRenderer::getCanonicalDocumentCss($enlargedCfg),
        ])->render();

        preg_match('/@page\s*\{\s*margin:\s*([0-9.]+)mm\s+([0-9.]+)mm\s+([0-9.]+)mm\s+([0-9.]+)mm;/i', $enlargedPdfHtml, $mLarge);
        $this->assertNotEmpty($mLarge);
        $largeBottomMarginMm = (float) $mLarge[3];

        // Margin bawah otomatis bertambah signifikan (> 65mm) sehingga isi dokumen tidak pernah menimpa Kotak Paraf besar
        $this->assertGreaterThan($stdBottomMarginMm + 20.0, $largeBottomMarginMm);
        $this->assertGreaterThan(65.0, $largeBottomMarginMm);

        // Pastikan DomPDF menghasilkan biner PDF valid tanpa error
        $pdfBinary = MouDocumentRenderer::renderPdfBinary(
            '<div class="mou-document"><p>Isi dokumen paraf besar</p></div>',
            $enlargedCfg,
            'Test Enlarged Paraf PDF'
        );
        $this->assertStringStartsWith('%PDF-1.', $pdfBinary);
    }

    public function test_v3_runtime_bundle_exists_no_stale_hot_file_and_exposes_word_processor_api()
    {
        // 1. Pastikan file public/hot tidak ada sehingga browser tidak tertipu memuat dev server mati
        $this->assertFileDoesNotExist(public_path('hot'));

        // 2. Pastikan manifest Vite terkompilasi dan bundle JS memuat fungsi runtime V3
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/js/app.js', $manifest);

        $bundleFile = public_path('build/' . $manifest['resources/js/app.js']['file']);
        $this->assertFileExists($bundleFile);

        $bundleContents = file_get_contents($bundleFile);
        $this->assertStringContainsString('initMouWordProcessor', $bundleContents);
        $this->assertStringContainsString('loadExistingTemplateData', $bundleContents);
        $this->assertStringContainsString('toggleUppercaseSelection', $bundleContents);
        $this->assertStringContainsString('setPaperSize', $bundleContents);
        $this->assertStringContainsString('setOrientation', $bundleContents);
        $this->assertStringContainsString('replaceLivePreviewPlaceholders', $bundleContents);
    }

    public function test_create_and_edit_pages_provide_saved_templates_loader_mail_merge_groups_and_ribbon_controls()
    {
        $savedTemplate = MouTemplate::create([
            'name'                 => 'Template Tersimpan Kustom ' . $this->unique,
            'version_number'       => 42,
            'template_html'        => '<p>Isi Template Tersimpan Kustom [institution_name]</p>',
            'allowed_placeholders' => ['institution_name', 'company_name'],
            'default_values'       => array_merge(MouTemplate::getDefaultCompanyValues(), [
                'company_name'     => 'SEVEN INC KUSTOM LOADER',
                '_page_config'     => MouTemplate::getDefaultPageConfig(),
                '_media_assets'    => MouTemplate::getDefaultMediaAssets(),
            ]),
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $createRes = $this->actingAs($this->admin)->get(route('admin.mou-templates.create'));
        $createRes->assertStatus(200);
        $html = $createRes->getContent();

        // Pemilih Template Tersimpan & Payload JSON tersedia
        $this->assertStringContainsString('id="ribbonSavedTemplateSelect"', $html);
        $this->assertStringContainsString('id="btnLoadSelectedSavedTemplate"', $html);
        $this->assertStringContainsString('id="mouSavedTemplatesPayloadJson"', $html);
        $this->assertStringContainsString('Template Tersimpan Kustom ' . $this->unique, $html);
        $this->assertStringContainsString('SEVEN INC KUSTOM LOADER', $html);

        // Panel Mail Merge terlihat langsung (tidak .hidden) dan dikelompokkan
        $this->assertStringContainsString('Pihak Kesatu (Institusi)', $html);
        $this->assertStringContainsString('Pihak Kedua (Perusahaan)', $html);
        $this->assertStringContainsString('Tempat &amp; Waktu Perjanjian', $html);
        $this->assertStringContainsString('Logo &amp; Identitas Visual', $html);
        $this->assertStringContainsString('onmousedown="event.preventDefault()"', $html);

        // Kontrol Ribbon yang dipulihkan (Uppercase AA, Hyperlink, Simbol, Paper Size, Orientation, Diagnostic Banner)
        $this->assertStringContainsString('id="btnToggleUppercase"', $html);
        $this->assertStringContainsString('id="btnInsertHyperlink"', $html);
        $this->assertStringContainsString('id="ribbonQuickSymbolSelect"', $html);
        $this->assertStringContainsString('id="layoutPaperSize"', $html);
        $this->assertStringContainsString('id="layoutOrientation"', $html);
        $this->assertStringContainsString('id="mouEditorDiagnosticBanner"', $html);
    }

    public function test_update_template_prevents_accidental_overwrite_with_empty_document_unless_explicitly_confirmed()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Anti Kosong ' . $this->unique,
            'version_number'       => 5,
            'template_html'        => '<p>Naskah Penting Jangan Sampai Hilang [institution_name]</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        // 1. Kirim update dengan dokumen kosong (<p></p>) tanpa allow_blank_template -> Harus ditolak!
        $blockedResponse = $this->actingAs($this->admin)
            ->from(route('admin.mou-templates.edit', $template))
            ->put(route('admin.mou-templates.update', $template), [
                'name'                 => $template->name,
                'version_number'       => 5,
                'template_html'        => '<p></p>',
                'allow_blank_template' => '0',
            ]);

        $blockedResponse->assertRedirect(route('admin.mou-templates.edit', $template));
        $blockedResponse->assertSessionHasErrors(['template_html']);

        $template->refresh();
        $this->assertStringContainsString('Naskah Penting Jangan Sampai Hilang', $template->template_html);

        // 2. Kirim update kosong dengan allow_blank_template = 1 -> Diizinkan
        $allowedResponse = $this->actingAs($this->admin)
            ->put(route('admin.mou-templates.update', $template), [
                'name'                 => $template->name,
                'version_number'       => 5,
                'template_html'        => '<p></p>',
                'allow_blank_template' => '1',
            ]);

        $allowedResponse->assertRedirect(route('admin.mou-templates.index'));
    }

    public function test_editor_json_integrity_check_rejects_stale_or_invalid_json_and_falls_back_to_template_html()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template JSON Sync ' . $this->unique,
            'version_number'       => 7,
            'template_html'        => '<p>Isi Kanonis Baru Dari HTML</p>',
            'allowed_placeholders' => ['institution_name'],
            'default_values'       => [
                '_editor_json' => [
                    'type'    => 'doc',
                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Isi Lama Tidak Sinkron']]]],
                ],
                '_editor_html_sha256' => hash('sha256', '<p>Isi Lama Tidak Sinkron</p>'),
            ],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        // Karena hash tidak cocok dengan template_html saat ini, getEditorJson() harus mengembalikan null agar editor memuat template_html
        $this->assertNull($template->getEditorJson());

        // Jika _editor_json rusak (kosong atau tanpa content), getEditorJson() juga mengembalikan null
        $template->update([
            'default_values' => [
                '_editor_json' => ['type' => 'doc', 'content' => []],
            ],
        ]);
        $this->assertNull($template->fresh()->getEditorJson());
    }

    public function test_v3_pagination_layout_metrics_header_footer_isolation_and_different_first_page()
    {
        // 1. Simpan template MOU V3 dengan pengaturan halaman, jarak header/footer, dan different first page aktif
        $firstPageHeaderHtml = '<p style="text-align: center;"><strong>KOP SURAT KHUSUS HALAMAN PERTAMA</strong></p>';
        $firstPageFooterHtml = '<table class="mou-table-paraf"><tr><td>Paraf Pihak 1 Hal 1</td><td>Paraf Pihak 2 Hal 1</td></tr></table>';
        $defaultHeaderHtml   = '<p style="text-align: right; font-size: 8pt;">Dokumen Perjanjian Kerjasama Seven Inc</p>';
        $defaultFooterHtml   = '<table class="mou-table-paraf"><tr><td>Paraf Pihak 1</td><td>Paraf Pihak 2</td></tr></table>';

        $longDocumentHtml = '<div class="mou-doc-root">' .
            '<h1>NOTA KESEPAHAMAN BERSAMA</h1>' .
            '<p>' . str_repeat('Paragraf panjang isi dokumen kerja sama yang mengalir melintasi batas halaman. ', 40) . '</p>' .
            '<div class="mou-page-break" data-page-break="true" style="page-break-before: always;"></div>' .
            '<h2>PASAL 2: KEGIATAN KEMITRAAN</h2>' .
            '<p>' . str_repeat('Uraian klausul lanjutan pada lembar kedua perjanjian kerja sama. ', 35) . '</p>' .
            '</div>';

        $response = $this->actingAs($this->admin)->post(route('admin.mou-templates.store'), [
            'name'                   => 'Template Multi Halaman & Header Footer ' . $this->unique,
            'version_number'         => 1,
            'template_html'          => $longDocumentHtml,
            'header_html'            => $defaultHeaderHtml,
            'footer_html'            => $defaultFooterHtml,
            'first_page_header_html' => $firstPageHeaderHtml,
            'first_page_footer_html' => $firstPageFooterHtml,
            'different_first_page'   => '1',
            'show_paraf_box'         => '1',
            'show_page_number'       => '1',
            'paper_size'             => 'A4',
            'orientation'            => 'portrait',
            'margin_top_mm'          => 25.4,
            'margin_bottom_mm'       => 25.4,
            'margin_left_mm'         => 25.4,
            'margin_right_mm'        => 25.4,
            'header_distance_mm'     => 12.0,
            'footer_distance_mm'     => 10.0,
            'font_family'            => 'Times New Roman',
            'font_size_pt'           => 12,
            'line_height'            => 1.15,
        ]);

        $response->assertRedirect(route('admin.mou-templates.index'));

        $template = MouTemplate::where('name', 'Template Multi Halaman & Header Footer ' . $this->unique)->firstOrFail();
        $pageCfg = $template->getPageConfig();

        // 2. Verifikasi keutuhan konfigurasi layout A4 dan isolasi Header/Footer
        $this->assertEquals('A4', $pageCfg['paper_size']);
        $this->assertEquals('portrait', $pageCfg['orientation']);
        $this->assertEquals(25.4, $pageCfg['margin_top_mm']);
        $this->assertEquals(25.4, $pageCfg['margin_bottom_mm']);
        $this->assertEquals(25.4, $pageCfg['margin_left_mm']);
        $this->assertEquals(25.4, $pageCfg['margin_right_mm']);
        $this->assertEquals(12.0, $pageCfg['header_distance_mm']);
        $this->assertEquals(10.0, $pageCfg['footer_distance_mm']);
        $this->assertTrue($pageCfg['different_first_page']);
        $this->assertStringContainsString('KOP SURAT KHUSUS HALAMAN PERTAMA', $pageCfg['first_page_header_html']);
        $this->assertStringContainsString('Paraf Pihak 1 Hal 1', $pageCfg['first_page_footer_html']);
        $this->assertStringContainsString('Dokumen Perjanjian Kerjasama Seven Inc', $pageCfg['header_html']);
        $this->assertStringContainsString('Paraf Pihak 1', $pageCfg['footer_html']);

        // 3. Render PDF resmi melalui DomPDF dan pastikan menghasilkan dokumen multi-halaman valid
        $pdfBinary = \App\Services\MouDocumentRenderer::renderPdfBinary(
            $template->template_html,
            $pageCfg,
            'Dokumen Uji Multi Halaman'
        );

        $this->assertStringStartsWith('%PDF-1.', $pdfBinary);
        $this->assertGreaterThan(1000, strlen($pdfBinary));
    }
}

