<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\DocumentTemplates\AssetLibraryContract;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\DocumentTemplates\MailMergePlaceholderContract;
use App\Services\SystemAssetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageDocTemplateIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Storage::fake('public');
        Storage::fake('local');

        $this->unique = uniqid('sdt_test_', true);

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

    public function test_system_assets_appear_in_document_template_asset_catalog(): void
    {
        // 1. Simpan SystemAsset nyata ke storage privat
        $file = UploadedFile::fake()->image('custom_seven_logo.png', 200, 80);
        $assetService = app(SystemAssetService::class);
        $asset = $assetService->storeAsset($file, [
            'name'       => 'Logo Emas Seven Inc',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => true,
        ], $this->admin);

        // 2. Ambil katalog aset via contract
        $catalog = AssetLibraryContract::getAssetCatalog();

        $this->assertArrayHasKey('logos', $catalog);
        $found = collect($catalog['logos'])->firstWhere('id', 'asset_' . $asset->id);

        $this->assertNotNull($found, 'Aset sistem baru harus muncul dalam katalog logos');
        $this->assertEquals('Logo Emas Seven Inc', $found['name']);
        $this->assertEquals($asset->id, $found['asset_id']);
        $this->assertStringContainsString('/admin/system-assets/' . $asset->id . '/preview', $found['url']);

        // 3. Verifikasi via endpoint API JSON untuk editor visual
        $response = $this->actingAs($this->admin)->getJson(route('admin.document-templates.api-assets'));
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertTrue(collect($response->json('data.logos'))->contains('id', 'asset_' . $asset->id));
    }

    public function test_document_template_embeds_system_assets_into_pdf_via_base64(): void
    {
        // 1. Buat SystemAsset fisik
        $file = UploadedFile::fake()->image('official_stamp.png', 100, 100);
        $assetService = app(SystemAssetService::class);
        $stampAsset = $assetService->storeAsset($file, [
            'name'       => 'Stempel Resmi Merah',
            'category'   => SystemAsset::CATEGORY_STAMP,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => true,
        ], $this->admin);

        // 2. Buat Template Surat yang menyisipkan data-asset-id
        $template = DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'LOA dengan Stempel Sistem',
            'editor_type'    => 'visual_doc',
            'content_html'   => '<div class="letter-body"><p>Surat Penerimaan Resmi</p><img src="/admin/system-assets/' . $stampAsset->id . '/preview" data-asset-id="' . $stampAsset->id . '" alt="Stempel" style="width: 80px;" /></div>',
            'page_config'    => DocumentTemplate::getDefaultPageConfig('loa'),
            'version_number' => 1,
            'created_by'     => $this->admin->id,
            'is_active'      => true,
        ]);

        // 3. Render HTML surat dengan substitusi Base64
        $letterHtml = DocumentTemplateRenderer::renderLetterHtml($template);
        $resolvedHtml = DocumentTemplateRenderer::embedLocalImagesAsBase64($letterHtml);

        $this->assertStringContainsString('data:image/png;base64,', $resolvedHtml);
        $this->assertStringNotContainsString('src="/admin/system-assets/' . $stampAsset->id . '/preview"', $resolvedHtml);

        // 4. Render PDF binary nyata
        $pdfBinary = DocumentTemplateRenderer::renderPdfBinary($template);
        $this->assertNotEmpty($pdfBinary);
        $this->assertStringStartsWith('%PDF', $pdfBinary);
    }

    public function test_mail_merge_resolves_all_tokens_and_legacy_aliases_seamlessly(): void
    {
        $rawContent = '<h2>Surat Keterangan Magang</h2>'
            . '<p>Nama: {{pemagang.nama}} (Alias: [student_name])</p>'
            . '<p>Institusi: {{pemagang.institusi}} (Alias: [institution_name])</p>'
            . '<p>Divisi: {{magang.divisi}} (Alias: [division_name])</p>'
            . '<p>No Dokumen: {{dokumen.nomor}} / [company_mou_number]</p>'
            . '<p>Perusahaan: {{perusahaan.nama}} / [company_name]</p>';

        $dataset = [
            'pemagang' => [
                'nama'      => 'Budi Wicaksono',
                'institusi' => 'Universitas Indonesia',
            ],
            'magang' => [
                'divisi' => 'Backend Engineering and Cloud',
            ],
            'dokumen' => [
                'nomor' => '999/SKL/SEVEN/X/2026',
            ],
            'perusahaan' => [
                'nama' => 'PT SEVEN INC INDONESIA',
            ],
        ];

        $resolved = MailMergePlaceholderContract::resolve($rawContent, $dataset);

        $this->assertStringContainsString('Nama: Budi Wicaksono (Alias: Budi Wicaksono)', $resolved);
        $this->assertStringContainsString('Institusi: Universitas Indonesia (Alias: Universitas Indonesia)', $resolved);
        $this->assertStringContainsString('Divisi: Backend Engineering and Cloud (Alias: Backend Engineering and Cloud)', $resolved);
        $this->assertStringContainsString('No Dokumen: 999/SKL/SEVEN/X/2026 / 999/SKL/SEVEN/X/2026', $resolved);
        $this->assertStringContainsString('Perusahaan: PT SEVEN INC INDONESIA / PT SEVEN INC INDONESIA', $resolved);
        $this->assertStringNotContainsString('{{pemagang.nama}}', $resolved);
        $this->assertStringNotContainsString('[student_name]', $resolved);
    }

    public function test_versioning_and_rollback_preserves_complete_template_state(): void
    {
        // 1. Buat template awal (Versi 1)
        $template = DocumentTemplate::create([
            'type'           => 'skl',
            'name'           => 'Template SKL Asli',
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Versi Awal 1: Magang di Seven Inc</p>',
            'version_number' => 1,
            'created_by'     => $this->admin->id,
            'is_active'      => true,
        ]);
        $template->createVersionSnapshot('Snapshot Versi 1', $this->admin->id);

        // 2. Update template (Versi 2)
        $updateResponse = $this->actingAs($this->admin)->put(route('admin.document-templates.update', $template), [
            'name'           => 'Template SKL Dimodifikasi',
            'content_html'   => '<p>Versi Baru 2: Perubahan Kurikulum Magang</p>',
            'change_summary' => 'Pembaruan materi PKL',
        ]);
        $updateResponse->assertRedirect(route('admin.document-templates.edit', $template));

        $template->refresh();
        $this->assertEquals(2, $template->version_number);
        $this->assertEquals('Template SKL Dimodifikasi', $template->name);
        $this->assertEquals(2, $template->versions()->count());

        // 3. Rollback kembali ke Versi 1
        $version1 = $template->versions()->where('version_number', 1)->firstOrFail();
        $restoreResponse = $this->actingAs($this->admin)->post(
            route('admin.document-templates.versions.restore', [$template, $version1])
        );
        $restoreResponse->assertRedirect(route('admin.document-templates.edit', $template));

        $template->refresh();
        // Versi bertambah menjadi 3 yang memulihkan state versi 1
        $this->assertEquals(3, $template->version_number);
        $this->assertEquals('Template SKL Asli', $template->name);
        $this->assertStringContainsString('Versi Awal 1: Magang di Seven Inc', $template->content_html);
    }

    public function test_rbac_admin_and_hr_access_while_pemagang_and_guests_are_blocked(): void
    {
        $template = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Sertifikat Magang RBAC',
            'editor_type'    => 'visual_canvas',
            'version_number' => 1,
            'created_by'     => $this->admin->id,
            'is_active'      => true,
        ]);
        $version = $template->createVersionSnapshot('Snapshot RBAC', $this->admin->id);

        $file = UploadedFile::fake()->image('rbac_asset.png', 50, 50);
        $asset = app(SystemAssetService::class)->storeAsset($file, [
            'name'     => 'Asset RBAC',
            'category' => SystemAsset::CATEGORY_LOGO,
            'scope'    => SystemAsset::SCOPE_GLOBAL,
        ], $this->admin);

        // 1. Admin diizinkan pada seluruh endpoint
        $this->actingAs($this->admin)->get(route('admin.document-templates.index'))->assertStatus(200);
        $this->actingAs($this->admin)->get(route('admin.document-templates.create'))->assertStatus(200);
        $this->actingAs($this->admin)->get(route('admin.document-templates.edit', $template))->assertStatus(200);
        $this->actingAs($this->admin)->get(route('admin.document-templates.preview', $template))->assertStatus(200);
        $this->actingAs($this->admin)->get(route('admin.document-templates.preview-pdf', $template))->assertStatus(200);
        $this->actingAs($this->admin)->get(route('admin.document-templates.versions', $template))->assertStatus(200);
        $this->actingAs($this->admin)->getJson(route('admin.document-templates.api-assets'))->assertStatus(200);
        $this->actingAs($this->admin)->get(route('admin.system-assets.preview', $asset))->assertStatus(200);
        $this->actingAs($this->admin)->getJson(route('admin.system-assets.api.index'))->assertStatus(200);

        // 2. HR Staff diizinkan pada seluruh template routes & asset preview/api
        $this->actingAs($this->hrStaff)->get(route('admin.document-templates.index'))->assertStatus(200);
        $this->actingAs($this->hrStaff)->get(route('admin.document-templates.create'))->assertStatus(200);
        $this->actingAs($this->hrStaff)->get(route('admin.document-templates.edit', $template))->assertStatus(200);
        $this->actingAs($this->hrStaff)->get(route('admin.document-templates.preview', $template))->assertStatus(200);
        $this->actingAs($this->hrStaff)->get(route('admin.document-templates.preview-pdf', $template))->assertStatus(200);
        $this->actingAs($this->hrStaff)->get(route('admin.document-templates.versions', $template))->assertStatus(200);
        $this->actingAs($this->hrStaff)->getJson(route('admin.document-templates.api-assets'))->assertStatus(200);
        $this->actingAs($this->hrStaff)->get(route('admin.system-assets.preview', $asset))->assertStatus(200);
        $this->actingAs($this->hrStaff)->getJson(route('admin.system-assets.api.index'))->assertStatus(200);

        // 3. Pemagang diblokir dari seluruh endpoint template & asset (redirect web, 403 json)
        $this->actingAs($this->pemagang)->get(route('admin.document-templates.index'))->assertRedirect();
        $this->actingAs($this->pemagang)->get(route('admin.document-templates.create'))->assertRedirect();
        $this->actingAs($this->pemagang)->get(route('admin.document-templates.edit', $template))->assertRedirect();
        $this->actingAs($this->pemagang)->get(route('admin.document-templates.preview', $template))->assertRedirect();
        $this->actingAs($this->pemagang)->get(route('admin.document-templates.versions', $template))->assertRedirect();
        $this->actingAs($this->pemagang)->get(route('admin.system-assets.preview', $asset))->assertRedirect();

        $this->actingAs($this->pemagang)->post(route('admin.document-templates.store'), [
            'type' => 'loa', 'name' => 'Hacked LOA', 'editor_type' => 'visual_doc'
        ])->assertRedirect();
        $this->actingAs($this->pemagang)->put(route('admin.document-templates.update', $template), [
            'name' => 'Hacked Name'
        ])->assertRedirect();
        $this->actingAs($this->pemagang)->delete(route('admin.document-templates.destroy', $template))->assertRedirect();
        $this->actingAs($this->pemagang)->post(route('admin.document-templates.duplicate', $template))->assertRedirect();
        $this->actingAs($this->pemagang)->post(route('admin.document-templates.set-default', $template))->assertRedirect();
        $this->actingAs($this->pemagang)->post(route('admin.document-templates.toggle-active', $template))->assertRedirect();
        $this->actingAs($this->pemagang)->post(route('admin.document-templates.versions.restore', [$template, $version]))->assertRedirect();

        $this->actingAs($this->pemagang)->getJson(route('admin.document-templates.index'))->assertStatus(403);
        $this->actingAs($this->pemagang)->getJson(route('admin.document-templates.api-assets'))->assertStatus(403);
        $this->actingAs($this->pemagang)->getJson(route('admin.system-assets.api.index'))->assertStatus(403);

        // 4. Guest tidak terautentikasi dialihkan ke login
        auth()->logout();
        $this->get(route('admin.document-templates.index'))->assertRedirect();
        $this->get(route('admin.document-templates.create'))->assertRedirect();
        $this->get(route('admin.document-templates.edit', $template))->assertRedirect();
        $this->get(route('admin.system-assets.preview', $asset))->assertRedirect();
        $this->getJson(route('admin.system-assets.api.index'))->assertStatus(401);
    }
}
