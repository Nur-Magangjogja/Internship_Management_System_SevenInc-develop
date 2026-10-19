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

class AssetApiContractTest extends TestCase
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

        $this->unique = uniqid('api_asset_', true);

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
            'name' => 'Brand API Test ' . $this->unique,
            'code' => 'BAT',
        ]);

        $this->institution = Institution::create([
            'name'                => 'Institut API ' . $this->unique,
            'address'             => 'Yogyakarta',
            'phone'               => '0812345678',
            'email'               => 'api_' . $this->unique . '@test.com',
            'correspondence_unit' => 'Humas',
            'is_active'           => true,
        ]);
    }

    public function test_api_index_returns_paginated_assets_and_filters(): void
    {
        /** @var SystemAssetService $service */
        $service = app(SystemAssetService::class);

        $service->storeAsset(UploadedFile::fake()->image('logo1.png'), [
            'name'     => 'Seven Inc Logo Header',
            'category' => SystemAsset::CATEGORY_LOGO,
            'scope'    => SystemAsset::SCOPE_GLOBAL,
        ], $this->admin);

        $service->storeAsset(UploadedFile::fake()->image('signature1.png'), [
            'name'     => 'Tanda Tangan HR',
            'category' => SystemAsset::CATEGORY_SIGNATURE,
            'scope'    => SystemAsset::SCOPE_GLOBAL,
        ], $this->admin);

        $response = $this->actingAs($this->admin)->getJson(route('admin.system-assets.api.index', ['category' => 'logo']));

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
        ]);

        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $this->assertEquals('Seven Inc Logo Header', $items[0]['name']);
        $this->assertEquals('logo', $items[0]['category']);
        $this->assertArrayHasKey('preview_url', $items[0]);
        $this->assertArrayHasKey('variable_placeholder', $items[0]);
    }

    public function test_api_show_returns_asset_metadata_and_base64_data_uri(): void
    {
        /** @var SystemAssetService $service */
        $service = app(SystemAssetService::class);

        $asset = $service->storeAsset(UploadedFile::fake()->image('stamp_official.png', 150, 150), [
            'name'     => 'Stempel Resmi Perusahaan',
            'category' => SystemAsset::CATEGORY_STAMP,
            'scope'    => SystemAsset::SCOPE_GLOBAL,
        ], $this->admin);

        // Fetch by ID with data URI
        $responseId = $this->actingAs($this->admin)->getJson(
            route('admin.system-assets.api.show', ['idOrUuid' => $asset->id]) . '?include_data_uri=1'
        );

        $responseId->assertStatus(200);
        $responseId->assertJson([
            'ok'   => true,
            'data' => [
                'id'       => $asset->id,
                'name'     => 'Stempel Resmi Perusahaan',
                'category' => 'stamp',
            ],
        ]);
        $this->assertStringStartsWith('data:image/png;base64,', $responseId->json('data.data_uri'));

        // Fetch by UUID
        $responseUuid = $this->actingAs($this->admin)->getJson(
            route('admin.system-assets.api.show', ['idOrUuid' => $asset->public_uuid])
        );

        $responseUuid->assertStatus(200);
        $this->assertEquals($asset->id, $responseUuid->json('data.id'));
    }

    public function test_api_resolve_returns_best_matching_asset_for_document_context(): void
    {
        /** @var SystemAssetService $service */
        $service = app(SystemAssetService::class);

        // Global logo
        $globalLogo = $service->storeAsset(UploadedFile::fake()->image('global_logo.png'), [
            'name'       => 'Default Seven Inc Logo',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => true,
        ], $this->admin);

        // Brand-specific logo
        $brandLogo = $service->storeAsset(UploadedFile::fake()->image('brand_logo.png'), [
            'name'       => 'Brand Exclusive Logo',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_BRAND,
            'brand_id'   => $this->brand->id,
            'is_default' => true,
        ], $this->admin);

        // Request with brand_id resolves brand logo
        $responseBrand = $this->actingAs($this->admin)->getJson(
            route('admin.system-assets.api.resolve', [
                'category' => 'logo',
                'brand_id' => $this->brand->id,
            ])
        );

        $responseBrand->assertStatus(200);
        $this->assertEquals($brandLogo->id, $responseBrand->json('data.id'));

        // Request without brand_id resolves global default logo
        $responseGlobal = $this->actingAs($this->admin)->getJson(
            route('admin.system-assets.api.resolve', [
                'category' => 'logo',
            ])
        );

        $responseGlobal->assertStatus(200);
        $this->assertEquals($globalLogo->id, $responseGlobal->json('data.id'));
    }

    public function test_unauthorized_user_is_blocked_from_asset_api(): void
    {
        $response = $this->actingAs($this->pemagang)->getJson(route('admin.system-assets.api.index'));
        $response->assertStatus(403);
    }
}
