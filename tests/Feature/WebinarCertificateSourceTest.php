<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAttendance;
use App\Models\WebinarCertificate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebinarCertificateSourceTest extends TestCase
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
            ['email' => 'admin_wbn_test@example.com'],
            ['name' => 'admin_wbn', 'password' => Hash::make('password'), 'role' => 'admin']
        );

        $this->pemagang = User::firstOrCreate(
            ['email' => 'pemagang_wbn_test@example.com'],
            ['name' => 'pemagang_wbn', 'password' => Hash::make('password'), 'role' => 'pemagang']
        );

        $this->brand = Brand::first() ?? Brand::create([
            'code'               => 'TEST',
            'name'               => 'Seven Inc Test',
            'company_address'    => 'Jl. Test No. 123',
            'signatory_name'     => 'Direktur Test',
            'signatory_position' => 'Direktur',
        ]);

        $this->webinarTemplate = DocumentTemplate::where('type', DocumentTemplate::TYPE_CERTIFICATE)
            ->where('certificate_type', DocumentTemplate::CERT_TYPE_WEBINAR)
            ->first() ?? DocumentTemplate::getDefaultCertificate();
    }

    public function test_document_templates_have_magang_and_webinar_types(): void
    {
        $magangTemplate = DocumentTemplate::where('type', DocumentTemplate::TYPE_CERTIFICATE)
            ->where('certificate_type', DocumentTemplate::CERT_TYPE_MAGANG)
            ->first();

        $webinarTemplate = DocumentTemplate::where('type', DocumentTemplate::TYPE_CERTIFICATE)
            ->where('certificate_type', DocumentTemplate::CERT_TYPE_WEBINAR)
            ->first();

        $this->assertNotNull($magangTemplate, 'Sertifikat Magang template should exist');
        $this->assertNotNull($webinarTemplate, 'Sertifikat Webinar template should exist');
        $this->assertEquals(DocumentTemplate::CERT_TYPE_MAGANG, $magangTemplate->certificate_type);
        $this->assertEquals(DocumentTemplate::CERT_TYPE_WEBINAR, $webinarTemplate->certificate_type);
        $this->assertTrue($webinarTemplate->isWebinarCertificate());
        $this->assertTrue($magangTemplate->isInternshipCertificate());
    }

    public function test_admin_can_create_webinar_with_template_certificate_source(): void
    {
        $res = $this->actingAs($this->admin)->post(route('admin.webinars.store'), [
            'title'                   => 'Webinar AI Engineering',
            'event_date'              => now()->addDays(3)->format('Y-m-d H:i:s'),
            'brand_id'                => $this->brand->id,
            'description'             => 'Belajar AI Engineering dari dasar.',
            'certificate_source'      => 'template',
            'certificate_template_id' => $this->webinarTemplate->id,
        ]);

        $res->assertRedirect(route('admin.webinars.index'));

        $webinar = Webinar::where('title', 'Webinar AI Engineering')->latest('id')->first();
        $this->assertNotNull($webinar);
        $this->assertEquals(Webinar::CERT_SOURCE_TEMPLATE, $webinar->certificate_source);
        $this->assertEquals($this->webinarTemplate->id, $webinar->certificate_template_id);
        $this->assertNull($webinar->gdrive_certificate_link);
        $this->assertTrue($webinar->isTemplateCertificate());
        $this->assertFalse($webinar->isGdriveCertificate());
    }

    public function test_admin_can_create_webinar_with_gdrive_certificate_source(): void
    {
        $driveUrl = 'https://drive.google.com/drive/folders/1a2b3c4d5e6f';

        $res = $this->actingAs($this->admin)->post(route('admin.webinars.store'), [
            'title'                   => 'Webinar Fullstack Laravel',
            'event_date'              => now()->addDays(5)->format('Y-m-d H:i:s'),
            'brand_id'                => $this->brand->id,
            'description'             => 'Belajar Laravel modern.',
            'certificate_source'      => 'gdrive',
            'gdrive_certificate_link' => $driveUrl,
        ]);

        $res->assertRedirect(route('admin.webinars.index'));

        $webinar = Webinar::where('title', 'Webinar Fullstack Laravel')->latest('id')->first();
        $this->assertNotNull($webinar);
        $this->assertEquals(Webinar::CERT_SOURCE_GDRIVE, $webinar->certificate_source);
        $this->assertEquals($driveUrl, $webinar->gdrive_certificate_link);
        $this->assertNull($webinar->certificate_template_id);
        $this->assertTrue($webinar->isGdriveCertificate());
        $this->assertFalse($webinar->isTemplateCertificate());
    }

    public function test_gdrive_requires_valid_url_when_gdrive_source_selected(): void
    {
        $res = $this->actingAs($this->admin)->post(route('admin.webinars.store'), [
            'title'                   => 'Webinar Invalid Gdrive',
            'event_date'              => now()->addDays(5)->format('Y-m-d H:i:s'),
            'brand_id'                => $this->brand->id,
            'certificate_source'      => 'gdrive',
            'gdrive_certificate_link' => '', // kosong
        ]);

        $res->assertSessionHasErrors('gdrive_certificate_link');

        $resInvalidUrl = $this->actingAs($this->admin)->post(route('admin.webinars.store'), [
            'title'                   => 'Webinar Invalid Gdrive 2',
            'event_date'              => now()->addDays(5)->format('Y-m-d H:i:s'),
            'brand_id'                => $this->brand->id,
            'certificate_source'      => 'gdrive',
            'gdrive_certificate_link' => 'bukan-url-valid',
        ]);

        $resInvalidUrl->assertSessionHasErrors('gdrive_certificate_link');
    }

    public function test_approval_and_download_for_gdrive_certificate(): void
    {
        $driveUrl = 'https://drive.google.com/drive/folders/abcdef123456';

        $webinar = Webinar::create([
            'title'                   => 'Webinar UI UX Design',
            'event_date'              => now()->addDays(1),
            'brand_id'                => $this->brand->id,
            'certificate_source'      => Webinar::CERT_SOURCE_GDRIVE,
            'gdrive_certificate_link' => $driveUrl,
        ]);

        $attendance = WebinarAttendance::create([
            'webinar_id' => $webinar->id,
            'user_id'    => $this->pemagang->id,
            'status'     => WebinarAttendance::STATUS_PENDING,
            'proof_file' => 'proof.jpg',
        ]);

        // Admin approve attendance
        $this->actingAs($this->admin)
            ->post(route('admin.webinars.attendances.approve', [$webinar->id, $attendance->id]))
            ->assertRedirect();

        $cert = WebinarCertificate::where('attendance_id', $attendance->id)->first();
        $this->assertNotNull($cert);
        $this->assertEquals($driveUrl, $cert->gdrive_link);

        // When pemagang downloads/views certificate, it redirects away to GDrive URL
        $downloadRes = $this->actingAs($this->pemagang)
            ->get(route('pemagang.documents.sertifikat_webinar', $cert->id));

        $downloadRes->assertRedirect($driveUrl);

        // Also verify the webinar show page displays the GDrive link
        $showRes = $this->actingAs($this->pemagang)
            ->get(route('pemagang.webinar.show', $webinar->id));
        $showRes->assertStatus(200);
        $showRes->assertSee('Buka Sertifikat di Google Drive');
        $showRes->assertSee($driveUrl);
    }

    public function test_approval_and_download_for_template_certificate(): void
    {
        $webinar = Webinar::create([
            'title'                   => 'Webinar Data Science',
            'event_date'              => now()->addDays(1),
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

        // Admin approve attendance
        $this->actingAs($this->admin)
            ->post(route('admin.webinars.attendances.approve', [$webinar->id, $attendance->id]))
            ->assertRedirect();

        $cert = WebinarCertificate::where('attendance_id', $attendance->id)->first();
        $this->assertNotNull($cert);
        $this->assertEquals($this->webinarTemplate->id, $cert->document_template_id);
        $this->assertNull($cert->gdrive_link);

        // When pemagang downloads certificate, it renders/downloads PDF
        $downloadRes = $this->actingAs($this->pemagang)
            ->get(route('pemagang.documents.sertifikat_webinar', $cert->id));

        $downloadRes->assertStatus(200);
        $this->assertEquals('application/pdf', $downloadRes->headers->get('content-type'));

        // Also verify the webinar show page displays Unduh Sertifikat
        $showRes = $this->actingAs($this->pemagang)
            ->get(route('pemagang.webinar.show', $webinar->id));
        $showRes->assertStatus(200);
        $showRes->assertSee('Unduh Sertifikat');
    }
}
