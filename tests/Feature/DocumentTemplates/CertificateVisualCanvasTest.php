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
}
