<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\BrandDomainInputService;
use App\Services\BrandDomainResolverService;
use App\Services\BrandHostRegistryService;
use App\Services\BrandReadinessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BrandDynamicActivationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        app(BrandHostRegistryService::class)->forgetCache();

        $this->admin = User::create([
            'name' => 'Dynamic Brand Admin',
            'email' => 'dynamic_brand_admin_' . uniqid() . '@example.com',
            'password' => Hash::make('test-password'),
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        app(BrandHostRegistryService::class)->forgetCache();
        parent::tearDown();
    }

    public function test_registry_contains_only_exact_active_verified_brand_hosts(): void
    {
        $brand = Brand::create([
            'code' => 'F4TST',
            'name' => 'Fase Four Test Brand',
            'registration_host' => 'daftar.fase4test.example',
            'primary_domain' => 'fase4test.example',
            'lifecycle_status' => Brand::STATUS_ACTIVE,
            'domain_verified_at' => now(),
            'domain_verification_method' => 'dns_txt',
            'division_mode' => Brand::DIVISION_MODE_GLOBAL,
            'is_default' => false,
        ]);

        $registry = app(BrandHostRegistryService::class);
        $registry->forgetCache();

        $this->assertSame($brand->id, $registry->brandIdForHost('DAFTAR.FASE4TEST.EXAMPLE'));
        $this->assertSame($brand->id, $registry->brandIdForHost('fase4test.example'));
        $this->assertContains('daftar.fase4test.example', $registry->trustedHostnames());
        $this->assertNotContains('sub.fase4test.example', $registry->trustedHostnames());
    }

    public function test_unverified_or_inactive_brand_is_not_added_to_trusted_registry(): void
    {
        $unverified = Brand::create([
            'code' => 'F4UV',
            'name' => 'Unverified Brand',
            'registration_host' => 'daftar.unverified-fase4.example',
            'primary_domain' => 'unverified-fase4.example',
            'lifecycle_status' => Brand::STATUS_ACTIVE,
            'domain_verification_token' => 'some-token',
            'domain_verified_at' => null,
            'is_default' => false,
        ]);
        $suspended = Brand::create([
            'code' => 'F4SP',
            'name' => 'Suspended Brand',
            'registration_host' => 'daftar.suspended-fase4.example',
            'primary_domain' => 'suspended-fase4.example',
            'lifecycle_status' => Brand::STATUS_SUSPENDED,
            'domain_verified_at' => now(),
            'domain_verification_method' => 'dns_txt',
            'is_default' => false,
        ]);

        $registry = app(BrandHostRegistryService::class);
        $registry->forgetCache();

        $this->assertNull($registry->brandIdForHost($unverified->registration_host));
        $this->assertNull($registry->brandIdForHost($suspended->registration_host));
    }

    public function test_resolver_does_not_assign_unknown_external_host_to_default_brand(): void
    {
        $resolved = app(BrandDomainResolverService::class)->resolveFromHost('not-registered-tenant.example');
        $this->assertNull($resolved);
    }

    public function test_registry_cache_is_invalidated_when_active_brand_is_suspended(): void
    {
        $brand = Brand::create([
            'code' => 'F4CCH',
            'name' => 'Cache Invalidation Brand',
            'registration_host' => 'daftar.cache-fase4.example',
            'primary_domain' => 'cache-fase4.example',
            'lifecycle_status' => Brand::STATUS_ACTIVE,
            'domain_verified_at' => now(),
            'domain_verification_method' => 'dns_txt',
            'division_mode' => Brand::DIVISION_MODE_GLOBAL,
            'is_default' => false,
        ]);

        $registry = app(BrandHostRegistryService::class);
        $registry->forgetCache();
        $this->assertSame($brand->id, $registry->brandIdForHost($brand->registration_host));

        $brand->lifecycle_status = Brand::STATUS_SUSPENDED;
        $brand->save();

        $this->assertNull($registry->brandIdForHost($brand->registration_host));
    }

    public function test_domain_input_normalizes_origin_url_and_enforces_parent_domain(): void
    {
        $service = app(BrandDomainInputService::class);

        $this->assertSame('daftar.brandbaru.example', $service->normalize(' HTTPS://Daftar.BrandBaru.Example/ '));
        $this->assertSame('brandbaru.example', $service->normalize(' BRANDBARU.EXAMPLE. '));
        $this->assertTrue($service->registrationHostBelongsToPrimaryDomain('daftar.brandbaru.example', 'brandbaru.example'));
        $this->assertFalse($service->registrationHostBelongsToPrimaryDomain('daftar.brand-lain.example', 'brandbaru.example'));

        $this->expectException(\InvalidArgumentException::class);
        $service->normalize('https://brandbaru.example/admin');
    }

    public function test_brand_creation_rejects_registration_host_outside_primary_domain(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.brands.store'), [
            'code' => 'F4INVALID',
            'name' => 'Invalid Domain Brand',
            'registration_host' => 'daftar.domain-lain.example',
            'primary_domain' => 'domain-brand.example',
            'division_mode' => Brand::DIVISION_MODE_GLOBAL,
        ]);

        $response->assertSessionHasErrors('registration_host');
        $this->assertDatabaseMissing('brands', ['code' => 'F4INVALID']);
    }

    public function test_activation_endpoint_rejects_brand_that_has_not_passed_readiness_gate(): void
    {
        $brand = Brand::create([
            'code' => 'F4ACT',
            'name' => 'Pending Activation Brand',
            'registration_host' => 'daftar.pending-activation.example',
            'primary_domain' => 'pending-activation.example',
            'lifecycle_status' => Brand::STATUS_CONFIGURATION_PENDING,
            'domain_verification_token' => 'pending-token',
            'domain_verified_at' => null,
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.brands.activate', $brand));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'lifecycle_status' => Brand::STATUS_CONFIGURATION_PENDING,
        ]);
    }

    public function test_editing_brand_with_all_division_boxes_unchecked_clears_mapping_and_resets_readiness(): void
    {
        $suffix = strtolower(substr(md5(uniqid('', true)), 0, 8));
        $division = Division::create([
            'name' => 'Fase Four Division ' . $suffix,
            'slug' => 'fase-four-' . $suffix,
            'code' => 'F4' . strtoupper(substr($suffix, 0, 6)),
            'is_active' => true,
        ]);
        $brand = Brand::create([
            'code' => 'F4' . strtoupper($suffix),
            'name' => 'Division Mapping Brand ' . $suffix,
            'registration_host' => 'daftar.' . $suffix . '.example',
            'primary_domain' => $suffix . '.example',
            'lifecycle_status' => Brand::STATUS_ACTIVE,
            'domain_verified_at' => now(),
            'domain_verification_method' => 'dns_txt',
            'division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC,
            'is_default' => false,
        ]);
        $brand->divisions()->attach($division->id);

        $response = $this->actingAs($this->admin)->put(route('admin.brands.update', $brand), [
            'code' => $brand->code,
            'name' => $brand->name,
            'division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC,
            'division_ids_present' => '1',
            // All checkboxes intentionally unchecked: division_ids is omitted by HTML.
        ]);

        $response->assertRedirect(route('admin.brands.index'));
        $this->assertSame([], $brand->fresh()->divisions()->pluck('divisions.id')->all());
        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'lifecycle_status' => Brand::STATUS_CONFIGURATION_PENDING,
        ]);
    }

    public function test_configured_brand_can_pass_readiness_and_activate_with_exact_host_registration(): void
    {
        $suffix = strtolower(substr(md5(uniqid('', true)), 0, 8));
        Division::create([
            'name' => 'Global Active Division ' . $suffix,
            'slug' => 'global-active-' . $suffix,
            'code' => 'G' . strtoupper(substr($suffix, 0, 5)),
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'code' => 'F4RD' . strtoupper(substr($suffix, 0, 5)),
            'name' => 'Ready To Activate ' . $suffix,
            'registration_host' => 'daftar.' . $suffix . '.example',
            'primary_domain' => $suffix . '.example',
            'lifecycle_status' => Brand::STATUS_CONFIGURATION_PENDING,
            'domain_verified_at' => now(),
            'domain_verification_method' => 'dns_txt',
            'division_mode' => Brand::DIVISION_MODE_GLOBAL,
            'is_default' => false,
        ]);

        SpreadsheetConnection::create([
            'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id' => $brand->id,
            'display_name' => 'Registration ' . $suffix,
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/FASE4' . strtoupper($suffix) . '/edit',
            'spreadsheet_id' => 'FASE4' . strtoupper($suffix),
            'drive_folder_url' => 'https://drive.google.com/drive/folders/FASE4' . strtoupper($suffix),
            'drive_folder_id' => 'FASE4' . strtoupper($suffix),
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/FASE4TEST/exec',
            'is_enabled' => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.brands.check-readiness', $brand))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'lifecycle_status' => Brand::STATUS_READY]);

        $this->actingAs($this->admin)
            ->post(route('admin.brands.activate', $brand))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'lifecycle_status' => Brand::STATUS_ACTIVE]);
        app(BrandHostRegistryService::class)->forgetCache();
        $this->assertSame($brand->id, app(BrandHostRegistryService::class)->brandIdForHost($brand->registration_host));
    }

    public function test_readiness_gate_reports_missing_domain_verification_and_integrations(): void
    {
        $brand = Brand::create([
            'code' => 'F4RDY',
            'name' => 'Not Ready Brand',
            'registration_host' => 'daftar.not-ready-fase4.example',
            'primary_domain' => 'not-ready-fase4.example',
            'lifecycle_status' => Brand::STATUS_CONFIGURATION_PENDING,
            'domain_verification_token' => 'verification-token',
            'domain_verified_at' => null,
            'division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC,
            'is_default' => false,
        ]);

        $result = app(BrandReadinessService::class)->assess($brand);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertTrue(collect($result['blockers'])->contains(fn ($message) => str_contains($message, 'divisi aktif')));
        $this->assertTrue(collect($result['blockers'])->contains(fn ($message) => str_contains($message, 'TXT')));
        $this->assertTrue(collect($result['blockers'])->contains(fn ($message) => stripos($message, 'koneksi spreadsheet') !== false || str_contains($message, 'koneksi spreadsheet')));
    }

    public function test_resolver_on_app_url_never_falls_back_to_another_active_tenant_when_default_inactive(): void
    {
        $defaultBrand = Brand::getDefaultBrand() ?? Brand::create([
            'code' => 'SI',
            'name' => 'SEVEN INC',
            'is_default' => true,
            'lifecycle_status' => Brand::STATUS_ACTIVE,
            'domain_verified_at' => now(),
            'registration_host' => 'daftar.seveninc.test',
            'primary_domain' => 'seveninc.test',
        ]);
        $this->assertNotNull($defaultBrand);

        // Temporarily put default brand in configuration_pending
        $originalStatus = $defaultBrand->lifecycle_status;
        $defaultBrand->lifecycle_status = Brand::STATUS_CONFIGURATION_PENDING;
        $defaultBrand->save();

        // Create another active brand
        $otherActiveBrand = Brand::create([
            'code' => 'F4OTHER',
            'name' => 'Other Tenant Brand',
            'registration_host' => 'daftar.other-tenant.example',
            'primary_domain' => 'other-tenant.example',
            'lifecycle_status' => Brand::STATUS_ACTIVE,
            'domain_verified_at' => now(),
            'domain_verification_method' => 'dns_txt',
            'is_default' => false,
        ]);

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $resolved = app(BrandDomainResolverService::class)->resolveFromHost($appHost);

        // Crucial safety assertion: APP_URL must NEVER resolve to the other tenant brand
        if ($resolved !== null) {
            $this->assertNotSame($otherActiveBrand->id, $resolved->id, 'APP_URL must never switch to another tenant brand.');
            $this->assertSame($defaultBrand->id, $resolved->id, 'APP_URL belongs strictly to the default corporate tenant.');
        }

        // Restore
        $defaultBrand->lifecycle_status = $originalStatus;
        $defaultBrand->save();
    }

    public function test_verifying_dns_or_checking_readiness_on_suspended_brand_does_not_clear_suspended_status(): void
    {
        $brand = Brand::create([
            'code' => 'F4SUSP',
            'name' => 'Suspended Protection Brand',
            'registration_host' => 'daftar.suspended-prot.example',
            'primary_domain' => 'suspended-prot.example',
            'lifecycle_status' => Brand::STATUS_SUSPENDED,
            'domain_verification_token' => 'susp-token',
            'domain_verified_at' => null,
            'division_mode' => Brand::DIVISION_MODE_GLOBAL,
            'is_default' => false,
        ]);

        // Checking readiness must NOT mutate status to ready or configuration_pending
        $this->actingAs($this->admin)->post(route('admin.brands.check-readiness', $brand));
        $this->assertSame(Brand::STATUS_SUSPENDED, $brand->fresh()->lifecycle_status, 'Readiness check must preserve suspended status.');
    }

    public function test_suspended_brand_can_be_reactivated_via_unsuspend_when_ready(): void
    {
        $suffix = strtolower(substr(md5(uniqid('', true)), 0, 6));

        Division::create([
            'name' => 'Active Global Division ' . $suffix,
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'code' => 'F4UN' . strtoupper($suffix),
            'name' => 'Suspended Reactivation Brand ' . $suffix,
            'registration_host' => 'daftar.reactivate-' . $suffix . '.example',
            'primary_domain' => 'reactivate-' . $suffix . '.example',
            'lifecycle_status' => Brand::STATUS_SUSPENDED,
            'domain_verified_at' => now(),
            'domain_verification_method' => 'dns_txt',
            'division_mode' => Brand::DIVISION_MODE_GLOBAL,
            'is_default' => false,
        ]);

        SpreadsheetConnection::create([
            'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id' => $brand->id,
            'display_name' => 'Registration ' . $suffix,
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/REACTIVATE_' . strtoupper($suffix) . '/edit',
            'spreadsheet_id' => 'REACTIVATE_' . strtoupper($suffix),
            'drive_folder_url' => 'https://drive.google.com/drive/folders/REACTIVATE_' . strtoupper($suffix),
            'drive_folder_id' => 'REACTIVATE_' . strtoupper($suffix),
            'is_enabled' => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        // Attempt unsuspend
        $response = $this->actingAs($this->admin)->post(route('admin.brands.unsuspend', $brand));
        $response->assertSessionHas('success');
        $this->assertSame(Brand::STATUS_ACTIVE, $brand->fresh()->lifecycle_status);

        app(BrandHostRegistryService::class)->forgetCache();
        $this->assertSame($brand->id, app(BrandHostRegistryService::class)->brandIdForHost($brand->registration_host));
    }

    public function test_brand_operational_status_badge_and_missing_fields_reflection(): void
    {
        // 1. Profil Kosong
        $brand = Brand::create([
            'code' => 'BADGE' . strtoupper(substr(uniqid(), -4)),
            'name' => 'Badge Evaluation Brand',
            'lifecycle_status' => Brand::STATUS_DRAFT,
        ]);

        $badge = $brand->operational_status_badge;
        $this->assertSame('empty', $badge['code']);
        $this->assertSame('Profil Belum Diisi', $badge['label']);
        $this->assertFalse($brand->isProfileCompleteForDocuments());
        $this->assertArrayHasKey('signatory_name', $brand->getMissingOperationalFields());

        // 2. Profil Belum Lengkap (Diisi sebagian)
        $brand->update([
            'company_address' => 'Jl. Uji Coba No. 12',
            'signatory_name' => 'Direktur Uji Coba',
        ]);
        $badgePartial = $brand->fresh()->operational_status_badge;
        $this->assertSame('incomplete', $badgePartial['code']);
        $this->assertStringContainsString('Profil Belum Lengkap (2/5)', $badgePartial['label']);

        // 3. Menunggu Verifikasi DNS
        $brand->update([
            'registration_host' => 'daftar.badgeuji.example',
            'primary_domain' => 'badgeuji.example',
        ]);
        $badgeDns = $brand->fresh()->operational_status_badge;
        $this->assertSame('dns_pending', $badgeDns['code']);
        $this->assertSame('Menunggu Verifikasi DNS', $badgeDns['label']);

        // 4. Aktif & Terverifikasi
        $brand->update([
            'domain_verified_at' => now(),
            'lifecycle_status' => Brand::STATUS_ACTIVE,
        ]);
        $badgeActive = $brand->fresh()->operational_status_badge;
        $this->assertSame('active', $badgeActive['code']);
        $this->assertSame('Aktif & Terverifikasi', $badgeActive['label']);
    }
}
