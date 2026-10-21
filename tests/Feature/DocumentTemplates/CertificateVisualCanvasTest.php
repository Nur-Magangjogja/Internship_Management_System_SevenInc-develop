<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\DocumentTemplates\OfflineQrCodeGenerator;
use Illuminate\Support\Str;
use Tests\TestCase;

class CertificateVisualCanvasTest extends TestCase
{
    protected User $admin;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->unique = Str::random(10);
        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DocumentTemplate::where('created_by', $this->admin->id)->forceDelete();
            $this->admin->delete();
        }
        parent::tearDown();
    }

    /**
     * Test generator QR Code offline menghasilkan SVG dan data URI yang valid.
     */
    public function test_offline_qr_code_generator_produces_valid_vector_output(): void
    {
        $token = 'SERTIF/2026/04/IMS-00129';
        $svg = OfflineQrCodeGenerator::renderSvg($token, 120);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('viewBox=', $svg);
        $this->assertStringContainsString('<rect', $svg);

        $dataUri = OfflineQrCodeGenerator::renderDataUri($token, 100);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
    }

    /**
     * Test renderCertificateHtml mendukung seluruh 6 tipe elemen visual:
     * text, image, shape, line, qr_code, signature_group dengan rotasi dan styling.
     */
    public function test_renderer_supports_all_six_canvas_element_types_with_rotation(): void
    {
        $customLayout = [
            'canvas' => [
                'orientation'      => 'landscape',
                'background_color' => '#ffffff',
                'background_image' => '',
            ],
            'elements' => [
                [
                    'id'          => 'elem_heading',
                    'type'        => 'text',
                    'content'     => 'SERTIFIKAT KELULUSAN {{pemagang.nama}}',
                    'x_percent'   => 50.0,
                    'y_percent'   => 20.0,
                    'width_percent' => 70.0,
                    'rotation'    => 5.0,
                    'align'       => 'center',
                    'font_size_pt'=> 28,
                    'color'       => '#1e293b',
                    'z_index'     => 10,
                ],
                [
                    'id'          => 'elem_badge',
                    'type'        => 'shape',
                    'bg_color'    => '#eff6ff',
                    'border_color'=> '#3b82f6',
                    'border_width_px' => 3,
                    'border_radius_px'=> 8,
                    'border_style'=> 'dashed',
                    'x_percent'   => 50.0,
                    'y_percent'   => 40.0,
                    'width_percent' => 50.0,
                    'height_percent'=> 15.0,
                    'rotation'    => -2.0,
                    'opacity'     => 0.95,
                    'z_index'     => 5,
                ],
                [
                    'id'          => 'elem_divider',
                    'type'        => 'line',
                    'line_color'  => '#d97706',
                    'thickness_px'=> 4,
                    'line_style'  => 'solid',
                    'x_percent'   => 50.0,
                    'y_percent'   => 48.0,
                    'width_percent' => 65.0,
                    'rotation'    => 0,
                    'z_index'     => 8,
                ],
                [
                    'id'          => 'elem_logo',
                    'type'        => 'image',
                    'src'         => '{{asset.company.logo}}',
                    'x_percent'   => 20.0,
                    'y_percent'   => 15.0,
                    'width_px'    => 120,
                    'height_px'   => 50,
                    'rotation'    => 0,
                    'z_index'     => 12,
                ],
                [
                    'id'          => 'elem_qr',
                    'type'        => 'qr_code',
                    'qr_data'     => 'https://ims.seveninc.id/verify/{{dokumen.nomor}}',
                    'size_px'     => 90,
                    'x_percent'   => 85.0,
                    'y_percent'   => 80.0,
                    'rotation'    => 0,
                    'z_index'     => 15,
                ],
                [
                    'id'          => 'elem_sig',
                    'type'        => 'signature_group',
                    'date_text'   => 'Yogyakarta, {{dokumen.tanggal}}',
                    'role_text'   => 'Direktur {{perusahaan.nama}}',
                    'name_text'   => '{{perusahaan.pimpinan}}',
                    'sig_image'   => '{{asset.signature}}',
                    'stamp_image' => '{{asset.stamp}}',
                    'x_percent'   => 50.0,
                    'y_percent'   => 75.0,
                    'width_percent' => 45.0,
                    'rotation'    => 0,
                    'z_index'     => 20,
                ],
            ],
        ];

        $template = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Sertifikat Desain Lengkap',
            'editor_type'    => 'visual_canvas',
            'canvas_layout'  => $customLayout,
            'page_config'    => ['orientation' => 'landscape', 'paper_size' => 'a4'],
            'version_number' => 1,
            'is_active'      => true,
            'is_default'     => false,
            'created_by'     => $this->admin->id,
            'updated_by'     => $this->admin->id,
        ]);

        $mockData = DocumentTemplate::getMockPlaceholderData();
        $html = DocumentTemplateRenderer::renderCertificateHtml($template, $mockData);

        // Assert text rendered with resolved placeholder and rotation transform
        $this->assertStringContainsString('SERTIFIKAT KELULUSAN ' . $mockData['pemagang']['nama'], $html);
        $this->assertStringContainsString('rotate(5deg)', $html);

        // Assert shape rendered with background color, dashed border, and negative rotation
        $this->assertStringContainsString('background-color: #eff6ff', $html);
        $this->assertStringContainsString('border: 3px dashed #3b82f6', $html);
        $this->assertStringContainsString('rotate(-2deg)', $html);

        // Assert line rendered with amber color and thickness
        $this->assertStringContainsString('border-top: 4px solid #d97706', $html);

        // Assert image rendered with resolved logo
        $this->assertStringContainsString('alt="Elemen Sertifikat"', $html);

        // Assert QR code rendered with embedded data URI
        $this->assertStringContainsString('alt="QR Code Verifikasi"', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);

        // Assert signature block rendered
        $this->assertStringContainsString('Direktur ' . $mockData['perusahaan']['nama'], $html);
        $this->assertStringContainsString('alt="Tanda Tangan"', $html);
    }

    /**
     * Test PUT update menyimpan canvas layout baru, membuat version snapshot,
     * dan mempertahankan struktur JSON canvas secara akurat.
     */
    public function test_admin_can_update_certificate_canvas_and_create_version_snapshot(): void
    {
        $template = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Sertifikat Asli',
            'editor_type'    => 'visual_canvas',
            'canvas_layout'  => DocumentTemplate::getDefaultCertificateCanvasLayout(),
            'page_config'    => ['orientation' => 'landscape'],
            'version_number' => 1,
            'is_active'      => true,
            'is_default'     => true,
            'created_by'     => $this->admin->id,
            'updated_by'     => $this->admin->id,
        ]);

        $newLayout = $template->canvas_layout;
        $newLayout['elements'][] = [
            'id'          => 'elem_watermark_shape',
            'type'        => 'shape',
            'bg_color'    => '#f8fafc',
            'border_color'=> '#e2e8f0',
            'border_width_px' => 2,
            'border_radius_px'=> 12,
            'border_style'=> 'solid',
            'x_percent'   => 50.0,
            'y_percent'   => 50.0,
            'width_percent' => 80.0,
            'height_percent'=> 60.0,
            'rotation'    => 12.0,
            'z_index'     => 2,
        ];

        $response = $this->actingAs($this->admin)
            ->putJson(route('admin.document-templates.update', $template), [
                'name'           => 'Sertifikat Versi 2 Lengkap',
                'canvas_layout'  => $newLayout,
                'page_config'    => ['orientation' => 'landscape', 'paper_size' => 'A4'],
                'change_summary' => 'Menambahkan watermark shape dengan rotasi 12 derajat',
                'is_active'      => 1,
                'is_default'     => 1,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $template->refresh();
        $this->assertEquals(2, $template->version_number);
        $this->assertEquals('Sertifikat Versi 2 Lengkap', $template->name);

        // Verify version snapshot created
        $latestVersion = $template->versions()->latest('version_number')->first();
        $this->assertNotNull($latestVersion);
        $this->assertEquals(2, $latestVersion->version_number);
        $this->assertEquals('Menambahkan watermark shape dengan rotasi 12 derajat', $latestVersion->change_summary);
        $this->assertCount(count($newLayout['elements']), $latestVersion->canvas_layout['elements']);

        // Verify element persistence
        $shapeElem = collect($template->canvas_layout['elements'])->firstWhere('id', 'elem_watermark_shape');
        $this->assertNotNull($shapeElem);
        $this->assertEquals('shape', $shapeElem['type']);
        $this->assertEquals(12.0, (float) $shapeElem['rotation']);
    }

    /**
     * Test render DomPDF binary dari sertifikat visual canvas menghasilkan %PDF binary valid.
     */
    public function test_canvas_certificate_renders_valid_dompdf_binary(): void
    {
        $template = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Sertifikat PDF Test',
            'editor_type'    => 'visual_canvas',
            'canvas_layout'  => DocumentTemplate::getDefaultCertificateCanvasLayout(),
            'page_config'    => ['orientation' => 'landscape', 'paper_size' => 'a4'],
            'version_number' => 1,
            'is_active'      => true,
            'is_default'     => false,
            'created_by'     => $this->admin->id,
            'updated_by'     => $this->admin->id,
        ]);

        $mockData = DocumentTemplate::getMockPlaceholderData();
        $pdfBinary = DocumentTemplateRenderer::renderPdfBinary($template, $mockData);

        $this->assertNotEmpty($pdfBinary);
        $this->assertStringStartsWith('%PDF-', $pdfBinary);
    }

    /**
     * Test tampilan editor sertifikat memuat asset Alpine.js dan berkas certificate-bg.png tersedia.
     */
    public function test_certificate_editor_renders_with_alpine_and_valid_background_asset(): void
    {
        // 1. Verifikasi background image fisik ada di disk
        $bgPath = public_path('images/certificate/certificate-bg.png');
        $this->assertFileExists($bgPath);
        $this->assertGreaterThan(1000, filesize($bgPath));

        // 2. Buat template sertifikat
        $template = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Sertifikat Visual Test ' . $this->unique,
            'editor_type'    => 'visual_canvas',
            'canvas_layout'  => DocumentTemplate::getDefaultCertificateCanvasLayout(),
            'page_config'    => ['orientation' => 'landscape', 'paper_size' => 'a4'],
            'version_number' => 1,
            'is_active'      => true,
            'is_default'     => false,
            'created_by'     => $this->admin->id,
            'updated_by'     => $this->admin->id,
        ]);

        // 3. Akses halaman edit
        $response = $this->actingAs($this->admin)->get(route('admin.document-templates.edit', $template));
        $response->assertStatus(200);

        // 4. Pastikan Alpine.js script tag termuat di layout
        $response->assertSee('vendor/alpine/alpine.min.js', false);
        $response->assertSee('[x-cloak]', false);

        // 5. Pastikan viewport kanvas memiliki styling dasar dan tidak ambles
        $response->assertSee('id="certificate-viewport"', false);
        $response->assertSee('width: 840px; height: 594px;', false);
        $response->assertSee('x-data="certificateCanvasApp()"', false);
        $response->assertSee('/images/certificate/certificate-bg.png', false);

        // 6. Pastikan signature_group di kanvas editor selaras 1:1 dengan pratinjau
        $response->assertSee('width: 220px;', false);
        $response->assertSee('height: 50px;', false);
        $response->assertSee('height: 85px;', false);
        $response->assertSee('width: 85px;', false);
        $response->assertSee('top: -18px;', false);
        $response->assertSee('stamp_offset_x', false);
        $response->assertSee('font-size: 11pt;', false);
        $response->assertSee('font-size: 12pt;', false);
    }

    /**
     * Test ukuran logo perusahaan proporsional dan selaras antara visual canvas dan render PDF preview.
     */
    public function test_certificate_logo_dimensions_match_between_editor_and_renderer(): void
    {
        $defaultLayout = DocumentTemplate::getDefaultCertificateCanvasLayout();
        $logoElem = collect($defaultLayout['elements'])->firstWhere('id', 'elem_logo');

        $this->assertNotNull($logoElem);
        $this->assertEquals('image', $logoElem['type']);
        $this->assertEquals(16.5, (float) ($logoElem['width_percent'] ?? 0));
        $this->assertEquals(10.0, (float) ($logoElem['height_percent'] ?? 0));
        $this->assertTrue($logoElem['aspect_locked'] ?? false);

        $template = DocumentTemplate::create([
            'type'           => 'certificate',
            'name'           => 'Sertifikat Logo Size Test',
            'editor_type'    => 'visual_canvas',
            'canvas_layout'  => $defaultLayout,
            'page_config'    => ['orientation' => 'landscape', 'paper_size' => 'a4'],
            'version_number' => 1,
            'is_active'      => true,
            'is_default'     => false,
            'created_by'     => $this->admin->id,
            'updated_by'     => $this->admin->id,
        ]);

        $mockData = DocumentTemplate::getMockPlaceholderData();
        $html = DocumentTemplateRenderer::renderCertificateHtml($template, $mockData);

        // Assert rendered logo element container has width: 16.5% and height: 10%
        $this->assertStringContainsString('width: 16.5%', $html);
        $this->assertStringContainsString('height: 10%', $html);
        $this->assertStringContainsString('alt="Elemen Sertifikat"', $html);
        $this->assertStringContainsString('max-width: 100%; max-height: 100%; width: auto; height: auto;', $html);

        // Assert rendered PDF binary does NOT stretch the logo horizontally
        $pdfBinary = DocumentTemplateRenderer::renderPdfBinary($template, $mockData);
        $this->assertNotEmpty($pdfBinary);
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBinary, $matches)) {
            $foundLogo = false;
            foreach ($matches[1] as $stream) {
                $uncompressed = @gzuncompress($stream);
                $data = $uncompressed !== false ? $uncompressed : $stream;
                if (preg_match_all('/([0-9\.\-]+)\s+([0-9\.\-]+)\s+([0-9\.\-]+)\s+([0-9\.\-]+)\s+([0-9\.\-]+)\s+([0-9\.\-]+)\s+cm(?:[^Q]+?)\/([a-zA-Z0-9_]+)\s+Do/is', $data, $mList, PREG_SET_ORDER)) {
                    foreach ($mList as $m) {
                        $w = abs((float) $m[1]);
                        $h = abs((float) $m[4]);
                        // Background is ~3500x2480pt, logo is around 500-600pt, signature is ~415x187pt
                        if ($w > 200 && $w < 1000 && $h > 200 && $h < 1000) {
                            $ratio = round($w / $h, 2);
                            $this->assertEqualsWithDelta(1.0, $ratio, 0.05, "Logo aspect ratio in PDF should remain 1:1 and not be stretched");
                            $foundLogo = true;
                            break 2;
                        }
                    }
                }
            }
            $this->assertTrue($foundLogo, "Logo image was not found in PDF stream");
        }
    }

    /**
     * Test upload dan penggantian background sertifikat via endpoint API.
     */
    public function test_certificate_background_upload_and_switching_api(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('custom_bg.png', 1920, 1080);

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.document-templates.api-upload-background'), [
                'file' => $fakeImage,
                'name' => 'Background Mewah Drive',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $json = $response->json();
        $this->assertNotEmpty($json['asset']['url']);

        // Verify template creation accepts certificate_background
        $createResponse = $this->actingAs($this->admin)
            ->post(route('admin.document-templates.store'), [
                'name'                   => 'Template Sertifikat Background Custom',
                'type'                   => 'certificate',
                'preset_type'            => 'standard',
                'certificate_background' => $json['asset']['url'],
            ]);

        $createResponse->assertRedirect();
        $newTemplate = DocumentTemplate::where('name', 'Template Sertifikat Background Custom')->first();
        $this->assertNotNull($newTemplate);
        $this->assertEquals($json['asset']['url'], $newTemplate->canvas_layout['canvas']['background_image']);
    }
}
