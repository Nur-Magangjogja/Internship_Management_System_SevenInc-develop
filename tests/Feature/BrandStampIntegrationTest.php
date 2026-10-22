<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\DocumentTemplate;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternCertificate;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAttendance;
use App\Models\WebinarCertificate;
use App\Services\DocumentTemplates\AssetLibraryContract;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class BrandStampIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Storage::fake('public');

        DocumentTemplate::ensureDefaultTemplatesExist();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_stamp_test@example.com'],
            ['name' => 'admin_stamp_test', 'password' => Hash::make('password'), 'role' => 'admin']
        );
    }

    public function test_admin_can_store_and_update_brand_with_stamp(): void
    {
        $this->actingAs($this->admin);

        $fakeStamp = UploadedFile::fake()->image('brand_stamp.png', 300, 300);

        // 1. Store brand with stamp
        $response = $this->post(route('admin.brands.store'), [
            'code'            => 'TESTSTP',
            'name'            => 'Brand Stamp Test',
            'company_address' => 'Jl. Pengujian Stampel No. 1',
            'stamp'           => $fakeStamp,
        ]);

        $response->assertRedirect(route('admin.brands.index'));

        $brand = Brand::where('code', 'TESTSTP')->first();
        $this->assertNotNull($brand);
        $this->assertNotNull($brand->stamp);
        Storage::disk('public')->assertExists($brand->stamp);
        $this->assertStringStartsWith('/storage/', $brand->stamp_url);

        $oldStampPath = $brand->stamp;

        // 2. Update brand with a new stamp
        $newFakeStamp = UploadedFile::fake()->image('brand_stamp_updated.png', 400, 400);

        $updateResponse = $this->put(route('admin.brands.update', $brand), [
            'code'            => 'TESTSTP',
            'name'            => 'Brand Stamp Test Updated',
            'company_address' => 'Jl. Pengujian Stampel No. 1 Updated',
            'stamp'           => $newFakeStamp,
        ]);

        $updateResponse->assertRedirect(route('admin.brands.index'));

        $brand->refresh();
        $this->assertNotEquals($oldStampPath, $brand->stamp);
        // Addendum Final V4: File lama tetap dipertahankan demi integritas issued document snapshots
        Storage::disk('public')->assertExists($oldStampPath);
        Storage::disk('public')->assertExists($brand->stamp);
    }

    public function test_brand_stamp_appears_in_asset_library_catalog(): void
    {
        Storage::disk('public')->put('brands/official_stamp.png', 'fake-stamp-binary-content');

        $brand = Brand::create([
            'code'            => 'STMCAT',
            'name'            => 'Brand Stempel Katalog',
            'company_address' => 'Jl. Katalog No. 12',
            'stamp'           => 'brands/official_stamp.png',
        ]);

        $catalog = AssetLibraryContract::getAssetCatalog();

        $this->assertArrayHasKey('stamps', $catalog);

        $foundStamp = collect($catalog['stamps'])->first(fn($item) =>
            str_contains($item['name'], 'Brand Stempel Katalog') &&
            $item['url'] === '/storage/brands/official_stamp.png'
        );

        $this->assertNotNull($foundStamp, 'Brand official stamp must be listed in AssetLibraryContract stamps catalog');
        $this->assertEquals('stamp', $foundStamp['slot']);
        $this->assertEquals('{{asset.stamp}}', $foundStamp['tag']);
    }

    public function test_issuance_service_populates_brand_stamp_context(): void
    {
        // 1x1 transparent PNG
        $pixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY44YAAAAASUVORK5CYII=');
        Storage::disk('public')->put('brands/pixel_stamp.png', $pixelPng);

        $brand = Brand::create([
            'code'            => 'STMPPUB',
            'name'            => 'Brand Stempel Publisher',
            'company_address' => 'Jl. Penerbitan No. 5',
            'stamp'           => 'brands/pixel_stamp.png',
        ]);

        $user = User::create([
            'name'     => 'Budi Santoso ' . uniqid(),
            'email'    => 'budi_stamp_' . uniqid() . '@example.com',
            'password' => Hash::make('secret'),
            'role'     => 'pemagang',
        ]);

        $division = Division::first() ?? Division::create(['name' => 'IT ' . uniqid(), 'slug' => 'it-' . uniqid()]);
        $inst = Institution::first() ?? Institution::create(['name' => 'Univ ' . uniqid()]);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas ' . uniqid(), 'institution_id' => $inst->id]);
        $prog = StudyProgram::first() ?? StudyProgram::create(['name' => 'Prodi ' . uniqid(), 'faculty_id' => $fac->id]);

        $intern = InternshipRegistration::create([
            'user_id'           => $user->id,
            'fullname'          => 'Budi Santoso',
            'phone_number'      => '08123456789',
            'gender'            => 'Laki-laki',
            'born_date'         => '2002-01-01',
            'birth_place'       => 'Sleman',
            'student_id'        => 'NIM' . rand(1000, 9999),
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'division_id'       => $division->id,
            'brand_id'          => $brand->id,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'start_date'        => '2026-11-01',
            'end_date'          => '2027-04-30',
        ]);

        $issuanceService = app(InternDocumentIssuanceService::class);
        $contextResult = $issuanceService->buildContext($intern, 'loa', isLiveIssuance: true);
        $variables = $contextResult['variables'];

        $this->assertArrayHasKey('asset.stamp', $variables);
        $this->assertArrayHasKey('asset.brand.stempel', $variables);
        $this->assertArrayHasKey('asset.company.stamp', $variables);
        $this->assertArrayHasKey('stamp', $variables);
        $this->assertArrayHasKey('stempel', $variables);

        $this->assertStringStartsWith('data:image/png;base64,', $variables['asset.stamp']);
        $this->assertEquals($variables['asset.stamp'], $variables['asset.brand.stempel']);
        $this->assertEquals($variables['asset.stamp'], $variables['stamp']);
    }

    public function test_document_template_renderer_substitutes_brand_stamp(): void
    {
        $pixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY44YAAAAASUVORK5CYII=');
        Storage::disk('public')->put('brands/canvas_stamp.png', $pixelPng);

        $brandStampUrl = '/storage/brands/canvas_stamp.png';
        $stampBase64 = DocumentTemplateRenderer::resolveImageToBase64($brandStampUrl);

        $this->assertNotEmpty($stampBase64);

        $template = DocumentTemplate::where('type', DocumentTemplate::TYPE_LOA)->first();
        if (!$template) {
            $template = DocumentTemplate::create([
                'type' => DocumentTemplate::TYPE_LOA,
                'name' => 'LOA Stamp Test',
                'body_content' => '<p>Cap Dokumen: <img src="{{asset.stamp}}" class="stamp" /></p>',
                'page_layout' => 'portrait',
                'page_size' => 'A4',
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        $context = [
            'asset.stamp' => $stampBase64,
            'pemagang.nama' => 'Ahmad Dahlan',
        ];

        $html = DocumentTemplateRenderer::renderLetterHtml($template, $context, true);

        $this->assertStringContainsString($stampBase64, $html);
    }

    public function test_webinar_certificate_pdf_view_renders_brand_stamp(): void
    {
        $pixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY44YAAAAASUVORK5CYII=');
        Storage::disk('public')->put('brands/webinar_stamp.png', $pixelPng);

        $brand = Brand::create([
            'code'            => 'STMPWEB',
            'name'            => 'Brand Stempel Webinar',
            'company_address' => 'Jl. Webinar No. 10',
            'stamp'           => 'brands/webinar_stamp.png',
        ]);

        $webinar = Webinar::create([
            'title'       => 'Webinar Nasional Test Stamp',
            'event_date'  => '2026-12-01',
            'brand_id'    => $brand->id,
        ]);

        $user = User::create([
            'name'     => 'Peserta Webinar Stamp',
            'email'    => 'peserta_stamp_' . uniqid() . '@example.com',
            'password' => Hash::make('secret'),
            'role'     => 'pemagang',
        ]);

        $attendance = WebinarAttendance::create([
            'webinar_id'        => $webinar->id,
            'user_id'           => $user->id,
            'attendance_status' => 'approved',
        ]);

        $cert = WebinarCertificate::create([
            'attendance_id'      => $attendance->id,
            'certificate_number' => 'CERT-WEB-STAMP-001',
            'company_name'       => $brand->name,
            'signatory_name'     => 'Pimpinan Webinar',
            'signatory_position' => 'Ketua Panitia',
        ]);

        $html = View::make('certificates.webinar-pdf', [
            'certificate' => $cert,
            'pdfTitle'    => 'Sertifikat Webinar Test',
        ])->render();

        $this->assertStringContainsString('alt="Stempel"', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_intern_certificate_pdf_view_renders_brand_stamp(): void
    {
        $pixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY44YAAAAASUVORK5CYII=');
        Storage::disk('public')->put('brands/intern_cert_stamp.png', $pixelPng);

        $brand = Brand::create([
            'code'            => 'STMPINT',
            'name'            => 'Brand Stempel Intern Cert',
            'company_address' => 'Jl. Magang No. 20',
            'stamp'           => 'brands/intern_cert_stamp.png',
        ]);

        $user = User::create([
            'name'     => 'Pemagang Cert Stamp',
            'email'    => 'intern_cert_stamp_' . uniqid() . '@example.com',
            'password' => Hash::make('secret'),
            'role'     => 'pemagang',
        ]);

        $division = Division::first() ?? Division::create(['name' => 'IT ' . uniqid(), 'slug' => 'it-' . uniqid()]);
        $inst = Institution::first() ?? Institution::create(['name' => 'Univ ' . uniqid()]);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas ' . uniqid(), 'institution_id' => $inst->id]);
        $prog = StudyProgram::first() ?? StudyProgram::create(['name' => 'Prodi ' . uniqid(), 'faculty_id' => $fac->id]);

        $intern = InternshipRegistration::create([
            'user_id'           => $user->id,
            'fullname'          => 'Pemagang Cert Stamp',
            'phone_number'      => '08123456789',
            'gender'            => 'Laki-laki',
            'born_date'         => '2002-01-01',
            'birth_place'       => 'Sleman',
            'student_id'        => 'NIM' . rand(1000, 9999),
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'division_id'       => $division->id,
            'brand_id'          => $brand->id,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'start_date'        => '2026-11-01',
            'end_date'          => '2027-04-30',
        ]);

        $cert = InternCertificate::create([
            'intern_id'          => $intern->id,
            'certificate_number' => 'CERT-INT-STAMP-001',
            'company_name'       => $brand->name,
            'signatory_name'     => 'Direktur Utama',
            'signatory_position' => 'Pimpinan',
        ]);

        $html = View::make('certificates.pdf', [
            'certificate' => $cert,
            'pdfTitle'    => 'Sertifikat Magang Test',
        ])->render();

        $this->assertStringContainsString('alt="Stempel"', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_loa_blade_view_renders_brand_stamp(): void
    {
        $fakeStampData = 'data:image/png;base64,FAKE_LOA_STAMP_BASE64_DATA';

        $intern = new InternshipRegistration([
            'start_date' => '2026-11-01',
            'end_date'   => '2027-04-30',
        ]);

        $interns = [
            [
                'nama_siswa' => 'Budi Stamp Test',
                'nim_nis'    => '12345678',
                'jurusan'    => 'Teknologi Informasi',
            ]
        ];

        $html = View::make('user.loa', [
            'intern'          => $intern,
            'interns'         => $interns,
            'headerTitle'     => 'SURAT PENERIMAAN MAGANG',
            'docNumber'       => 'LOA/2026/001',
            'openingGreeting' => 'Halo peserta,',
            'closingGreeting' => 'Terima kasih,',
            'logoData'        => null,
            'sigData'         => null,
            'stampData'       => $fakeStampData,
        ])->render();

        $this->assertStringContainsString('FAKE_LOA_STAMP_BASE64_DATA', $html);
        $this->assertStringContainsString('class="stamp"', $html);
    }

    public function test_skl_blade_view_renders_brand_stamp(): void
    {
        $fakeStampData = 'data:image/png;base64,FAKE_SKL_STAMP_BASE64_DATA';

        $html = View::make('user.skl', [
            'headerTitle'     => 'SURAT KETERANGAN LULUS',
            'docNumber'       => 'SKL/2026/001',
            'rows'            => [
                'Nama'        => 'Budi SKL Stamp Test',
            ],
            'openingGreeting' => 'Halo peserta,',
            'closingGreeting' => 'Terima kasih,',
            'logoData'        => null,
            'sigData'         => null,
            'stampData'       => $fakeStampData,
            'leaderName'      => 'Pimpinan SKL',
            'leaderTitle'     => 'Direktur',
        ])->render();

        $this->assertStringContainsString('FAKE_SKL_STAMP_BASE64_DATA', $html);
        $this->assertStringContainsString('class="stamp"', $html);
    }

    public function test_assessment_blade_view_renders_brand_stamp(): void
    {
        $fakeStampData = 'data:image/png;base64,FAKE_ASSESSMENT_STAMP_BASE64_DATA';

        // Fake assessment object
        $assessment = new \App\Models\InternAssessment([
            'assessment_number' => 'PENILAIAN/2026/001',
            'scores'            => ['disiplin' => 90],
            'final_score'       => 90,
            'grade'             => 'A',
            'signatory_name'    => 'Pimpinan Penilai',
            'signatory_position'=> 'HRD',
        ]);

        $html = View::make('admin.interns.pdf_assessment', [
            'assessment' => $assessment,
            'logoSrc'    => null,
            'sigSrc'     => null,
            'stampSrc'   => $fakeStampData,
            'autoPrint'  => false,
        ])->render();

        $this->assertStringContainsString('FAKE_ASSESSMENT_STAMP_BASE64_DATA', $html);
        $this->assertStringContainsString('class="stamp"', $html);
    }
}
