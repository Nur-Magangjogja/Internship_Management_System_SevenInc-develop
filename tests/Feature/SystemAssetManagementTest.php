<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Institution;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\SystemAssetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemAssetManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $pemagang;
    private Brand $brand;
    private Institution $institution;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Storage::fake('local');

        $this->unique = uniqid('asset_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->brand = Brand::create([
            'name'        => 'Seven Inc ' . $this->unique,
            'code'        => 'SI_' . substr($this->unique, 0, 4),
            'description' => 'Test brand',
        ]);

        $this->institution = Institution::create([
            'name'                => 'SMK Test ' . $this->unique,
            'address'             => 'Yogyakarta',
            'phone'               => '08123456789',
            'email'               => 'smk_' . $this->unique . '@test.com',
            'correspondence_unit' => 'Humas',
            'is_active'           => true,
        ]);
    }

    public function test_admin_can_upload_new_asset(): void
    {
        $file = UploadedFile::fake()->image('logo_seveninc.png', 400, 200);

        $response = $this->actingAs($this->admin)->post(route('admin.system-assets.store'), [
            'name'       => 'Logo Seven Inc Primary',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'file'       => $file,
            'is_default' => '1',
        ]);

        $response->assertRedirect(route('admin.system-assets.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('system_assets', [
            'name'       => 'Logo Seven Inc Primary',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => 1,
            'is_active'  => 1,
            'version'    => 1,
        ]);

        $asset = SystemAsset::where('name', 'Logo Seven Inc Primary')->first();
        $this->assertNotNull($asset);
        $this->assertTrue(Storage::disk('local')->exists($asset->local_path));
    }

    public function test_replacing_asset_version_increments_version_and_updates_checksum(): void
    {
        $initialFile = UploadedFile::fake()->image('ttd_pejabat_v1.png', 200, 100);

        /** @var SystemAssetService $service */
        $service = app(SystemAssetService::class);
        $asset = $service->storeAsset($initialFile, [
            'name'     => 'Tanda Tangan Direktur',
            'category' => SystemAsset::CATEGORY_SIGNATURE,
            'scope'    => SystemAsset::SCOPE_GLOBAL,
        ], $this->admin);

        $this->assertEquals(1, $asset->version);
        $initialChecksum = $asset->checksum_sha256;
        $initialPath = $asset->local_path;

        // Replace with new file
        $newFile = UploadedFile::fake()->image('ttd_pejabat_v2.png', 300, 150);
        $response = $this->actingAs($this->admin)->post(route('admin.system-assets.replace-version', $asset), [
            'file' => $newFile,
        ]);

        $response->assertRedirect(route('admin.system-assets.index'));

        $asset->refresh();
        $this->assertEquals(2, $asset->version);
        $this->assertNotEquals($initialChecksum, $asset->checksum_sha256);
        $this->assertTrue(Storage::disk('local')->exists($asset->local_path));
        // Verify historical original file is preserved!
        $this->assertTrue(Storage::disk('local')->exists($initialPath));
    }

    public function test_setting_default_unsets_other_defaults(): void
    {
        $file1 = UploadedFile::fake()->image('logo1.png');
        $file2 = UploadedFile::fake()->image('logo2.png');

        /** @var SystemAssetService $service */
        $service = app(SystemAssetService::class);
        $asset1 = $service->storeAsset($file1, [
            'name'       => 'Logo Default 1',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => true,
        ], $this->admin);

        $asset2 = $service->storeAsset($file2, [
            'name'       => 'Logo Default 2',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => false,
        ], $this->admin);

        $this->assertTrue($asset1->fresh()->is_default);
        $this->assertFalse($asset2->fresh()->is_default);

        $this->actingAs($this->admin)->post(route('admin.system-assets.set-default', $asset2));

        $this->assertFalse($asset1->fresh()->is_default);
        $this->assertTrue($asset2->fresh()->is_default);
    }

    public function test_preview_endpoint_streams_binary_and_enforces_auth(): void
    {
        $file = UploadedFile::fake()->image('stamp.png');
        /** @var SystemAssetService $service */
        $service = app(SystemAssetService::class);
        $asset = $service->storeAsset($file, [
            'name'     => 'Stempel Perusahaan',
            'category' => SystemAsset::CATEGORY_STAMP,
            'scope'    => SystemAsset::SCOPE_GLOBAL,
        ], $this->admin);

        // Guest is blocked
        $guestResp = $this->get(route('admin.system-assets.preview', $asset));
        $guestResp->assertRedirect(); // redirected to login

        // Pemagang role is blocked
        $pemagangResp = $this->actingAs($this->pemagang)->get(route('admin.system-assets.preview', $asset));
        $pemagangResp->assertRedirect(route('user.dashboard'));

        $pemagangJson = $this->actingAs($this->pemagang)->getJson(route('admin.system-assets.preview', $asset));
        $pemagangJson->assertStatus(403);

        // Admin can stream binary
        $adminResp = $this->actingAs($this->admin)->get(route('admin.system-assets.preview', $asset));
        $adminResp->assertStatus(200);
        $adminResp->assertHeader('Content-Type', 'image/png');
    }

    public function test_asset_hierarchy_resolution(): void
    {
        $fileGlobal = UploadedFile::fake()->image('global_logo.png');
        $fileBrand = UploadedFile::fake()->image('brand_logo.png');

        /** @var SystemAssetService $service */
        $service = app(SystemAssetService::class);

        $globalAsset = $service->storeAsset($fileGlobal, [
            'name'       => 'Global Logo',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => true,
        ], $this->admin);

        $brandAsset = $service->storeAsset($fileBrand, [
            'name'       => 'Brand Logo',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_BRAND,
            'brand_id'   => $this->brand->id,
            'is_default' => true,
        ], $this->admin);

        // Resolving with brandId returns brand asset
        $resolvedWithBrand = $service->resolveAsset(
            category: SystemAsset::CATEGORY_LOGO,
            brandId: $this->brand->id
        );
        $this->assertEquals($brandAsset->id, $resolvedWithBrand->id);

        // Resolving without brandId returns global default
        $resolvedGlobal = $service->resolveAsset(
            category: SystemAsset::CATEGORY_LOGO,
            brandId: null
        );
        $this->assertEquals($globalAsset->id, $resolvedGlobal->id);
    }
}
