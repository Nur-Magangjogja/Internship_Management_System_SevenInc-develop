<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAttendance;
use App\Models\WebinarCertificate;
use App\Services\DocumentTemplates\AssetLibraryContract;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandCertificateBackgroundTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $pemagang;
    protected Brand $brand;
    protected DocumentTemplate $webinarTemplate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Storage::fake('public');

        DocumentTemplate::ensureDefaultTemplatesExist();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_bg_test@example.com'],
            ['name' => 'admin_bg_test', 'password' => Hash::make('password'), 'role' => 'admin']
        );

        $this->pemagang = User::firstOrCreate(
            ['email' => 'pemagang_bg_test@example.com'],
            ['name' => 'pemagang_bg_test', 'password' => Hash::make('password'), 'role' => 'pemagang']
        );

        // Buat fake file gambar background untuk brand
        Storage::disk('public')->put('brands/test_intern_bg.jpg', 'fake-image-intern-bg-content');
        Storage::disk('public')->put('brands/test_webinar_bg.jpg', 'fake-image-webinar-bg-content');

        $this->brand = Brand::create([
            'code'                      => 'BRNDBG',
            'name'                      => 'Brand Background Test',
            'company_address'           => 'Jl. Brand No. 99, Yogyakarta',
            'internship_certificate_bg' => 'brands/test_intern_bg.jpg',
            'webinar_certificate_bg'    => 'brands/test_webinar_bg.jpg',
            'signatory_name'            => 'Pimpinan Brand',
            'signatory_position'        => 'Direktur Utama',
        ]);

        $this->webinarTemplate = DocumentTemplate::where('type', DocumentTemplate::TYPE_CERTIFICATE)
            ->where('certificate_type', DocumentTemplate::CERT_TYPE_WEBINAR)
            ->first() ?? DocumentTemplate::getDefaultCertificate();
    }

    public function test_asset_library_includes_brand_backgrounds(): void
    {
        $catalog = AssetLibraryContract::getAssetCatalog();

        $this->assertArrayHasKey('backgrounds', $catalog);

        $brandWebinarBg = collect($catalog['backgrounds'])->first(fn($bg) => 
            str_contains($bg['name'], 'Brand Background Test') && str_contains($bg['name'], 'Webinar')
        );

        $brandInternBg = collect($catalog['backgrounds'])->first(fn($bg) => 
            str_contains($bg['name'], 'Brand Background Test') && str_contains($bg['name'], 'Magang')
        );

        $this->assertNotNull($brandWebinarBg, 'Brand webinar background should appear in asset catalog');
        $this->assertNotNull($brandInternBg, 'Brand intern background should appear in asset catalog');
        $this->assertEquals('/storage/brands/test_webinar_bg.jpg', $brandWebinarBg['url']);
        $this->assertEquals('/storage/brands/test_intern_bg.jpg', $brandInternBg['url']);
    }

    public function test_certificate_html_renderer_prioritizes_brand_background(): void
    {
        $brandBgUrl = '/storage/brands/test_webinar_bg.jpg';

        $data = [
            'asset.background' => $brandBgUrl,
            'pemagang.nama'    => 'Siti Aminah',
        ];

        $html = DocumentTemplateRenderer::renderCertificateHtml($this->webinarTemplate, $data, isLiveIssuance: true);

        // Latar belakang kanvas harus menggunakan gambar dari brand
        $b64Expected = DocumentTemplateRenderer::resolveImageToBase64($brandBgUrl);
        $this->assertNotEmpty($b64Expected);
        $this->assertStringContainsString($b64Expected, $html);
    }

    public function test_webinar_certificate_download_uses_brand_webinar_background(): void
    {
        $webinar = Webinar::create([
            'title'                   => 'Webinar Cyber Security',
            'event_date'              => now()->addDays(2),
            'brand_id'                => $this->brand->id,
            'certificate_source'      => Webinar::CERT_SOURCE_TEMPLATE,
            'certificate_template_id' => $this->webinarTemplate->id,
        ]);

        $attendance = WebinarAttendance::create([
            'webinar_id' => $webinar->id,
            'user_id'    => $this->pemagang->id,
            'status'     => WebinarAttendance::STATUS_PENDING,
            'proof_file' => 'proof.jpg',
        ]);

        // Admin approve
        $this->actingAs($this->admin)
            ->post(route('admin.webinars.attendances.approve', [$webinar->id, $attendance->id]))
            ->assertRedirect();

        $cert = WebinarCertificate::where('attendance_id', $attendance->id)->first();
        $this->assertNotNull($cert);
        // Pastikan background_image_path tersimpan sesuai brand setting
        $this->assertEquals('brands/test_webinar_bg.jpg', $cert->background_image_path);

        // Download certificate PDF
        $res = $this->actingAs($this->pemagang)
            ->get(route('pemagang.documents.sertifikat_webinar', $cert->id));

        $res->assertStatus(200);
        $this->assertEquals('application/pdf', $res->headers->get('content-type'));
    }
}
