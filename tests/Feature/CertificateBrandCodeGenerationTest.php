<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\GeneratedDocument;
use App\Models\Division;
use App\Models\InternshipRegistration;
use App\Models\User;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class CertificateBrandCodeGenerationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DivisionSeeder::class);
        $this->seed(\Database\Seeders\BrandSeeder::class);
    }

    public function test_bulk_certificate_form_lists_completed_interns(): void
    {
        $admin = User::create([
            'name' => 'Certificate Form Admin',
            'email' => 'certificate_form_admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.certificate.bulk.interns.create'));

        $response->assertOk();
        $response->assertViewIs('certificates.bulk-interns');
    }

    public function test_intern_certificate_serial_uses_official_brand_code_from_brand_relation(): void
    {
        $admin = User::create([
            'name' => 'Certificate Code Admin',
            'email' => 'certificate_code_admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]);
        $pemagang = User::create([
            'name' => 'Certificate Code Intern',
            'email' => 'certificate_code_intern_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role' => 'pemagang',
        ]);
        $brand = Brand::where('code', 'MJ')->firstOrFail();
        $division = Division::where('name', 'Administrasi')->firstOrFail();

        $intern = InternshipRegistration::create([
            'user_id' => $pemagang->id,
            'fullname' => $pemagang->name,
            'email' => $pemagang->email,
            'brand_id' => $brand->id,
            'division_id' => $division->id,
            'end_date' => '2026-05-31',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $capturedSerial = null;
        $issuance = Mockery::mock(InternDocumentIssuanceService::class);
        $issuance->shouldReceive('issueDocument')
            ->once()
            ->withArgs(function ($issuedIntern, $documentType, $options, $actor) use (&$capturedSerial, $intern) {
                $capturedSerial = $options['document_number'] ?? null;
                return $issuedIntern->id === $intern->id
                    && $documentType === 'certificate'
                    && $actor?->id === auth()->id()
                    && str_contains((string) $capturedSerial, '/MJ.MJ/');
            })
            ->andReturn(Mockery::mock(GeneratedDocument::class));
        $this->app->instance(InternDocumentIssuanceService::class, $issuance);

        $response = $this->actingAs($admin)->post(route('admin.certificate.bulk.interns.store'), [
            'intern_ids' => [$intern->id],
        ]);

        $response->assertRedirect(route('admin.certificate.index'));
        $this->assertNotNull($capturedSerial);
        $this->assertStringContainsString('/MJ.MJ/', $capturedSerial);
    }
}
