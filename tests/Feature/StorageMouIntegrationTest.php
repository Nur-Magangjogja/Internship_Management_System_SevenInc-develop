<?php

namespace Tests\Feature;

use App\Models\CloudStorageSetting;
use App\Models\FollowUpCycle;
use App\Models\GeneratedDocument;
use App\Models\Institution;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\DocumentPublishingService;
use App\Services\MouDocumentRenderer;
use App\Services\MouService;
use App\Services\SystemAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageMouIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $hrStaff;
    protected User $pemagang;
    protected Institution $institution;
    protected FollowUpCycle $cycle;
    protected MouTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $unique = uniqid('test_', true);

        $this->admin = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin_' . $unique . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR Staff',
            'email'     => 'hr_' . $unique . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_active' => true,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang User',
            'email'     => 'pemagang_' . $unique . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_active' => true,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Politeknik Negeri Media Kreatif',
            'address'   => 'Jl. Srengseng Sawah, Jagakarsa, Jakarta Selatan',
            'email'     => 'info@polimedia.ac.id',
            'phone'     => '0217864795',
            'is_active' => true,
        ]);

        $contact = \App\Models\InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Dr. Hendra Saputra',
            'position_title' => 'Ketua Jurusan TI',
            'phone'          => '081299998888',
            'email'          => 'hendra@test.com',
            'is_active'      => true,
        ]);

        $this->cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_number'           => 1,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'notes'                  => 'Institusi sepakat bekerja sama secara penuh.',
        ]);

        $this->template = MouTemplate::create([
            'name'                 => 'Template Integrasi Storage & Editor',
            'version_number'       => 1,
            'template_html'        => '<div class="mou-document"><p>Naskah MOU antara {{perusahaan.nama}} dan {{institusi.nama}} nomor {{dokumen.nomor}}.</p><p>Logo: [company_logo]</p></div>',
            'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
            'default_values'       => array_merge(
                MouTemplate::getDefaultCompanyValues(),
                [
                    '_page_config'  => MouTemplate::getDefaultPageConfig(),
                    '_media_assets' => MouTemplate::getDefaultMediaAssets(),
                ]
            ),
            'is_active'            => true,
        ]);
    }

    public function test_word_editor_includes_asset_library_modal_and_launchers()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.edit', $this->template));

        $response->assertStatus(200);
        $response->assertSee('id="btnOpenAssetLibraryModal"', false);
        $response->assertSee('id="mouAssetLibraryModal"', false);
        $response->assertSee('Perpustakaan Aset &amp; Dokumen', false);
        $response->assertSee('id="mouAssetCategoryTabs"', false);
        $response->assertSee('id="mouAssetItemsList"', false);
    }

    public function test_sanitizer_preserves_data_asset_id_and_preview_urls()
    {
        $htmlWithAsset = '<div class="mou-document">'
            . '<p>Dokumen Resmi</p>'
            . '<img src="/admin/system-assets/42/preview" data-asset-id="42" data-asset-code="company_logo" data-token="asset.company.logo" alt="Logo Utama" style="width: 100px;">'
            . '</div>';

        $sanitized = MouDocumentRenderer::sanitizeEditableTemplate($htmlWithAsset, false);

        $this->assertStringContainsString('data-asset-id="42"', $sanitized);
        $this->assertStringContainsString('data-asset-code="company_logo"', $sanitized);
        $this->assertStringContainsString('data-token="asset.company.logo"', $sanitized);
        $this->assertStringContainsString('src="/admin/system-assets/42/preview"', $sanitized);
    }

    public function test_embed_local_images_resolves_system_asset_by_id_to_base64()
    {
        // 1. Buat SystemAsset fisik di storage
        $file = UploadedFile::fake()->image('company_logo.png', 100, 100);
        $assetService = app(SystemAssetService::class);
        $asset = $assetService->storeAsset($file, [
            'name'       => 'Logo Seven Inc Master',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => true,
        ], $this->admin);

        // 2. HTML dokumen yang menggunakan data-asset-id
        $html = '<div class="mou-document"><p>Header</p><img src="/admin/system-assets/' . $asset->id . '/preview" data-asset-id="' . $asset->id . '" alt="Logo"></div>';

        // 3. Resolusi ke Base64
        $resolvedHtml = MouDocumentRenderer::embedLocalImagesAsBase64($html);

        $this->assertStringContainsString('data:image/png;base64,', $resolvedHtml);
        $this->assertStringNotContainsString('src="/admin/system-assets/' . $asset->id . '/preview"', $resolvedHtml);
    }

    public function test_variable_resolver_resolves_both_dotted_tokens_and_legacy_bracket_aliases()
    {
        $html = '<p>Pemagang: {{pemagang.nama}} (NIM: {{pemagang.nim}})</p>'
            . '<p>Mitra: [institution_name] ({{institusi.nama}})</p>'
            . '<p>No Dokumen: {{dokumen.nomor}} / [company_mou_number]</p>'
            . '<p>Perusahaan: {{company_name}} / {{perusahaan.nama}}</p>';

        $mou = new Mou([
            'company_mou_number'           => '099/MOU/SEVEN/X/2026',
            'first_party_institution_name' => 'Politeknik Negeri Media Kreatif',
            'second_party_company_name'    => 'PT SEVEN INC INDONESIA',
            'agreement_date'               => '2026-10-19',
        ]);

        $resolver = app(\App\Services\MailMerge\VariableResolverService::class);
        $ctx = $resolver->resolveContext(
            intern: null,
            institution: $this->institution,
            brand: null,
            mou: $mou,
            overrides: [
                'pemagang.nama' => 'Rizky Ramadhan',
                'pemagang.nim'  => '220509101',
            ]
        );

        $rendered = $resolver->renderContent($html, $ctx);

        $this->assertStringContainsString('Pemagang: Rizky Ramadhan', $rendered);
        $this->assertStringContainsString('NIM: 220509101', $rendered);
        $this->assertStringContainsString('099/MOU/SEVEN/X/2026', $rendered);
        $this->assertStringContainsString('PT SEVEN INC INDONESIA', $rendered);
        $this->assertStringNotContainsString('{{pemagang.nama}}', $rendered);
        $this->assertStringNotContainsString('[company_mou_number]', $rendered);
    }

    public function test_mou_renderer_resolves_all_placeholders_and_asset_tokens()
    {
        $template = new MouTemplate([
            'name'                 => 'Test Template Tokens',
            'version_number'       => 1,
            'template_html'        => '<div class="mou-document"><p>Nomor: {{dokumen.nomor}}</p><p>Perusahaan: {{company_name}}</p><p>Institusi: [institution_name]</p><p>Logo: {{asset.company.logo}}</p></div>',
            'allowed_placeholders' => ['company_name', 'institution_name', 'dokumen.nomor', 'asset.company.logo'],
            'default_values'       => MouTemplate::getDefaultCompanyValues(),
        ]);

        $rendered = MouDocumentRenderer::renderTemplate($template, [
            'dokumen.nomor'    => 'MOU-TEST-001',
            'company_name'     => 'SEVEN MEDIA CORP',
            'institution_name' => 'Institut Seni Indonesia Yogyakarta',
        ], false);

        $this->assertStringContainsString('Nomor: MOU-TEST-001', $rendered);
        $this->assertStringContainsString('Perusahaan: SEVEN MEDIA CORP', $rendered);
        $this->assertStringContainsString('Institusi: Institut Seni Indonesia Yogyakarta', $rendered);
        $this->assertStringContainsString('<img', $rendered); // asset.company.logo resolved to img tag
    }

    public function test_mou_generation_publishes_to_generated_document_and_storage_pipeline()
    {
        $mouService = app(MouService::class);

        $mouData = [
            'institution_id'                  => $this->institution->id,
            'followup_cycle_id'               => $this->cycle->id,
            'mou_template_id'                 => $this->template->id,
            'company_mou_number'              => '101/SPK.MAGANG/CEO/SEVEN/X/2026',
            'institution_mou_number'          => 'POLIMEDIA/MOU/X/2026',
            'first_party_institution_name'    => $this->institution->name,
            'first_party_signatory_name'      => 'Dr. Tipri Rose Kartika',
            'first_party_signatory_title'     => 'Direktur',
            'second_party_company_name'       => 'SEVEN INC',
            'second_party_signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'second_party_signatory_title'    => 'CEO',
            'agreement_place'                 => 'Yogyakarta',
            'agreement_date'                  => '2026-10-19',
            'effective_start'                 => '2026-10-19',
            'effective_end'                   => '2031-10-19',
            'cooperation_confirmed'           => true,
        ];

        $mou = $mouService->generateMou($mouData, $this->admin);

        $this->assertInstanceOf(Mou::class, $mou);
        $this->assertEquals(Mou::STATUS_GENERATED, $mou->status);

        // Verifikasi GeneratedDocument otomatis terbit
        $genDoc = GeneratedDocument::where('document_number', '101/SPK.MAGANG/CEO/SEVEN/X/2026')->first();
        $this->assertNotNull($genDoc);
        $this->assertEquals(GeneratedDocument::TYPE_MOU, $genDoc->document_type);
        $this->assertEquals($this->institution->id, $genDoc->institution_id);
        $this->assertNotNull($genDoc->file_path);
        $this->assertNotNull($genDoc->checksum_sha256);
        $this->assertTrue(Storage::disk('local')->exists($genDoc->file_path));

        // Verifikasi relasi generatedDocument pada model Mou
        $this->assertNotNull($mou->generatedDocument);
        $this->assertEquals($genDoc->id, $mou->generatedDocument->id);
    }

    public function test_publishing_pipeline_for_mou_is_idempotent()
    {
        $mou = Mou::create([
            'institution_id'               => $this->institution->id,
            'followup_cycle_id'            => $this->cycle->id,
            'mou_template_id'              => $this->template->id,
            'company_mou_number'           => '102/SPK.MAGANG/CEO/SEVEN/X/2026',
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Tipri Rose',
            'first_party_signatory_title'  => 'Direktur',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekario Danny',
            'second_party_signatory_title' => 'CEO',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-10-19',
            'effective_start'              => '2026-10-19',
            'effective_end'                => '2031-10-19',
            'status'                       => Mou::STATUS_GENERATED,
            'rendered_document_html'       => '<div class="mou-document"><p>Naskah Idempotensi</p></div>',
            'render_data_snapshot'         => ['_page_config' => MouTemplate::getDefaultPageConfig()],
        ]);

        $pubService = app(DocumentPublishingService::class);

        $doc1 = $pubService->publishMouDocument($mou, $this->admin);
        $doc2 = $pubService->publishMouDocument($mou, $this->admin);

        $this->assertEquals($doc1->id, $doc2->id);
        $this->assertEquals($doc1->checksum_sha256, $doc2->checksum_sha256);
        $this->assertEquals(1, GeneratedDocument::where('document_number', '102/SPK.MAGANG/CEO/SEVEN/X/2026')->count());
    }

    public function test_cloud_sync_mou_updates_both_mou_and_generated_document_records()
    {
        $mou = Mou::create([
            'institution_id'               => $this->institution->id,
            'followup_cycle_id'            => $this->cycle->id,
            'mou_template_id'              => $this->template->id,
            'company_mou_number'           => '103/SPK.MAGANG/CEO/SEVEN/X/2026',
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Tipri Rose',
            'first_party_signatory_title'  => 'Direktur',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekario Danny',
            'second_party_signatory_title' => 'CEO',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-10-19',
            'effective_start'              => '2026-10-19',
            'effective_end'                => '2031-10-19',
            'status'                       => Mou::STATUS_GENERATED,
            'rendered_document_html'       => '<div class="mou-document"><p>Test Cloud Sync</p></div>',
            'render_data_snapshot'         => ['_page_config' => MouTemplate::getDefaultPageConfig()],
        ]);

        $pubService = app(DocumentPublishingService::class);
        $genDoc = $pubService->publishMouDocument($mou, $this->admin);

        $mou->update(['generated_file_path' => $genDoc->file_path]);

        $syncManager = app(CloudSyncManager::class);
        $result = $syncManager->syncMou($mou, $this->admin);

        $this->assertNotEmpty($result['file_id']);
        $this->assertNotNull($mou->fresh()->drive_file_id);
        $this->assertNotNull($genDoc->fresh()->drive_file_id);
    }
}
