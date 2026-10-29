<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\BrandDomainResolverService;
use App\Services\BrandHostRegistryService;
use App\Services\BrandReadinessService;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use App\Services\InternDocumentIssuanceService;
use App\Services\SpreadsheetSyncEngineService;
use Database\Seeders\BrandSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Simulasi Pengujian Menyeluruh (End-to-End) Keberhasilan Fase 1 s/d Fase 4
 * dengan studi kasus penambahan Brand Baru ke-12 (Brand Dinamis).
 */
class EndToEndBrandTwelveSimulationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected Division $divisionDev;
    protected Division $divisionDesign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        app(BrandHostRegistryService::class)->forgetCache();

        $this->admin = User::create([
            'name' => 'Super Admin Simulator',
            'email' => 'admin_sim_' . uniqid() . '@seveninc.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $suffix = strtolower(substr(md5(uniqid('', true)), 0, 6));
        $this->divisionDev = Division::create([
            'name' => 'Web Developer ' . $suffix,
            'slug' => 'web-dev-' . $suffix,
            'code' => 'WD' . strtoupper($suffix),
            'is_active' => true,
        ]);

        $this->divisionDesign = Division::create([
            'name' => 'Graphic Designer ' . $suffix,
            'slug' => 'graphic-des-' . $suffix,
            'code' => 'GD' . strtoupper($suffix),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app(BrandHostRegistryService::class)->forgetCache();
        parent::tearDown();
    }

    public function test_full_lifecycle_and_tenant_isolation_of_brand_twelve(): void
    {
        $regHost = 'daftar.brand12baru.test';
        $priDomain = 'brand12baru.test';

        // =========================================================================
        // TAHAP 1: Admin Menambahkan Brand ke-12 (Status Awal: Draft)
        // =========================================================================
        $response = $this->actingAs($this->admin)->post(route('admin.brands.store'), [
            'code' => 'B12',
            'name' => 'Brand Dua Belas Dinamis',
            'registration_host' => $regHost,
            'primary_domain' => $priDomain,
            'division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC,
            'division_ids' => [$this->divisionDev->id],
            'company_address' => 'Jl. Inovasi No. 12, Sleman, DIY',
            'signatory_name' => 'Direktur Brand 12',
            'signatory_position' => 'Managing Director',
        ]);

        $response->assertRedirect(route('admin.brands.index'));
        $response->assertSessionHas('success');

        /** @var Brand $brand12 */
        $brand12 = Brand::where('code', 'B12')->firstOrFail();
        $this->assertSame('draft', $brand12->lifecycle_status);
        $this->assertFalse($brand12->is_default);
        $this->assertNotEmpty($brand12->domain_verification_token);
        $this->assertNull($brand12->domain_verified_at);

        // Host Brand 12 BELUM boleh masuk registry TrustHosts karena masih draft & belum verified
        $registry = app(BrandHostRegistryService::class);
        $this->assertNull($registry->brandIdForHost($regHost));
        $this->assertNotContains($regHost, $registry->trustedHostnames());

        // =========================================================================
        // TAHAP 2: Readiness Gate Mencegah Aktivasi Dini
        // =========================================================================
        $readiness = app(BrandReadinessService::class)->assess($brand12);
        $this->assertFalse($readiness['ready'], 'Brand baru tidak boleh langsung ready sebelum verifikasi.');
        $this->assertTrue(collect($readiness['blockers'])->contains(fn ($b) => str_contains($b, 'TXT')));

        // Mencoba bypass aktivasi lewat route aktivasi harus ditolak
        $activateAttempt = $this->actingAs($this->admin)->post(route('admin.brands.activate', $brand12));
        $activateAttempt->assertSessionHas('error');
        $this->assertSame('draft', $brand12->fresh()->lifecycle_status);

        // =========================================================================
        // TAHAP 3: Konfigurasi Domain & Spreadsheet Khusus Brand 12
        // =========================================================================
        // A. Simulasi verifikasi domain DNS TXT
        $brand12->domain_verified_at = now();
        $brand12->domain_verification_method = 'dns_txt';
        $brand12->save();

        // B. Konfigurasi Spreadsheet & Google Drive khusus Brand 12
        $b12Connection = SpreadsheetConnection::create([
            'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id' => $brand12->id,
            'display_name' => 'Spreadsheet Pendaftaran Brand 12',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/SHEET_B12_OFFICIAL/edit',
            'spreadsheet_id' => 'SHEET_B12_OFFICIAL',
            'drive_folder_url' => 'https://drive.google.com/drive/folders/DRIVE_B12_FOLDER',
            'drive_folder_id' => 'DRIVE_B12_FOLDER',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AK_TEST_B12/exec',
            'is_enabled' => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        // C. Periksa Readiness Gate kembali
        $readinessCheck = $this->actingAs($this->admin)->post(route('admin.brands.check-readiness', $brand12));
        $readinessCheck->assertSessionHas('success');
        $this->assertSame('ready', $brand12->fresh()->lifecycle_status);

        // D. Aktivasi Brand 12
        $activateResponse = $this->actingAs($this->admin)->post(route('admin.brands.activate', $brand12));
        $activateResponse->assertSessionHas('success');
        $this->assertSame('active', $brand12->fresh()->lifecycle_status);

        // =========================================================================
        // TAHAP 4: Host Registry & TrustHosts Terverifikasi Otomatis
        // =========================================================================
        $registry->forgetCache();
        $this->assertSame($brand12->id, $registry->brandIdForHost($regHost));
        $this->assertContains($regHost, $registry->trustedHostnames());
        $this->assertContains('^' . preg_quote($regHost, '/') . '$', $registry->trustedHostPatterns());

        // Resolver host otomatis mendeteksi Brand 12
        $resolvedBrand = app(BrandDomainResolverService::class)->resolveFromHost($regHost);
        $this->assertNotNull($resolvedBrand);
        $this->assertSame($brand12->id, $resolvedBrand->id);
        $this->assertSame('B12', $resolvedBrand->code);

        // =========================================================================
        // TAHAP 5: Registrasi Pemagang pada Tenant Brand 12 (Isolasi Divisi)
        // =========================================================================
        $divisionService = app(\App\Services\BrandDivisionService::class);
        $this->assertTrue($divisionService->isDivisionAllowed($brand12, $this->divisionDev->id));
        $this->assertFalse($divisionService->isDivisionAllowed($brand12, $this->divisionDesign->id));
        $this->assertTrue($divisionService->registrationDivisionNames($brand12)->contains($this->divisionDev->name));
        $this->assertFalse($divisionService->registrationDivisionNames($brand12)->contains($this->divisionDesign->name));

        // Buat akun dan registrasi pemagang untuk Brand 12
        $candidateUser = User::create([
            'name' => 'Calon Pemagang Brand 12',
            'username' => 'user_b12_' . uniqid(),
            'email' => 'pemagang_b12_' . uniqid() . '@example.test',
            'password' => Hash::make('secret123'),
            'role' => 'user',
            'brand_id' => $brand12->id,
            'registration_host' => $regHost,
        ]);

        $internship = InternshipRegistration::create([
            'user_id' => $candidateUser->id,
            'brand_id' => $brand12->id,
            'brand' => $brand12->name,
            'division_id' => $this->divisionDev->id,
            'registration_host' => $regHost,
            'fullname' => $candidateUser->name,
            'email' => $candidateUser->email,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
        ]);

        // Verifikasi integritas relasi
        $this->assertSame($brand12->id, $internship->brand_id);
        $this->assertSame($brand12->id, $candidateUser->fresh()->brand_id);
        $this->assertSame($regHost, $internship->registration_host);

        // =========================================================================
        // TAHAP 6: Isolasi Mutlak Google Drive & Spreadsheet (Zero Leak ke Seven Inc)
        // =========================================================================
        // A. Spreadsheet resolver harus mengembalikan koneksi Brand 12
        $resolvedConnection = app(SpreadsheetSyncEngineService::class)->getRegistrationConnection($brand12->id);
        $this->assertNotNull($resolvedConnection);
        $this->assertSame($b12Connection->id, $resolvedConnection->id);
        $this->assertSame('SHEET_B12_OFFICIAL', $resolvedConnection->spreadsheet_id);

        // B. CloudSyncManager menggunakan folder Google Drive milik Brand 12
        $fallbackDriver = new LocalStorageFallbackDriver();
        $cloudSync = new CloudSyncManager($fallbackDriver);

        $attachment = \App\Models\RegistrationAttachment::create([
            'internship_registration_id' => $internship->id,
            'category'                   => 'cv',
            'attachment_type'            => 'cv',
            'file_path'                  => 'attachments/test_cv_' . uniqid() . '.pdf',
            'original_name'              => 'test_cv.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put($attachment->file_path, '%PDF-dummy-content%');

        $cloudSync->uploadRegistrationAttachment($attachment);
        $this->assertSame('DRIVE_B12_FOLDER', $fallbackDriver->getRootFolderId(), 'Folder Drive wajib milik Brand 12.');

        // =========================================================================
        // TAHAP 7: Penomoran Sertifikat Resmi Memakai Kode Brand 12
        // =========================================================================
        $internship->internship_status = InternshipRegistration::STATUS_COMPLETED;
        $internship->save();

        $docService = app(InternDocumentIssuanceService::class);
        $certNumber = $docService->generateUniqueCertificateNumber($internship);
        $this->assertStringContainsString('/SEVEN.B12/', $certNumber, 'Nomor sertifikat wajib menggunakan kode B12.');

        // =========================================================================
        // TAHAP 8: Proteksi Seeder Non-Destruktif & Guard Penghapusan
        // =========================================================================
        // A. Jalankan BrandSeeder (harus mempertahankan Brand 12)
        $seeder = new BrandSeeder();
        $seeder->run();

        $this->assertDatabaseHas('brands', [
            'id' => $brand12->id,
            'code' => 'B12',
            'lifecycle_status' => 'active',
        ]);

        // B. Percobaan penghapusan Brand 12 saat memiliki data pemagang harus DITOLAK
        $deleteAttempt = $this->actingAs($this->admin)->delete(route('admin.brands.destroy', $brand12));
        $deleteAttempt->assertSessionHas('error');
        $this->assertDatabaseHas('brands', ['id' => $brand12->id]);

        // =========================================================================
        // TAHAP 9: Penangguhan (Suspension) Otomatis Mengeluarkan Host dari Registry
        // =========================================================================
        $suspendResponse = $this->actingAs($this->admin)->post(route('admin.brands.suspend', $brand12));
        $suspendResponse->assertSessionHas('success');
        $this->assertSame('suspended', $brand12->fresh()->lifecycle_status);

        $registry->forgetCache();
        $this->assertNull($registry->brandIdForHost($regHost), 'Host brand suspended harus langsung dihapus dari registry.');
        $this->assertNotContains($regHost, $registry->trustedHostnames());
    }
}
