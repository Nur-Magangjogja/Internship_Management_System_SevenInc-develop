<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\SpreadsheetConnectionService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BrandLifecycleAndSpreadsheetSafetyTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DivisionSeeder::class);
        $this->seed(\Database\Seeders\BrandSeeder::class);

        $this->admin = User::create([
            'name' => 'Brand Safety Admin',
            'email' => 'brand_safety_admin_' . uniqid() . '@example.com',
            'password' => Hash::make('test-password'),
            'role' => 'admin',
        ]);
    }

    public function test_regular_brand_seeder_preserves_dynamic_brand(): void
    {
        $dynamic = Brand::create([
            'code' => 'DYN' . strtoupper(substr(uniqid(), -5)),
            'name' => 'Dynamic Brand Test',
            'is_default' => false,
        ]);

        $this->seed(\Database\Seeders\BrandSeeder::class);

        $this->assertDatabaseHas('brands', [
            'id' => $dynamic->id,
            'code' => $dynamic->code,
            'name' => 'Dynamic Brand Test',
        ]);
    }

    public function test_default_brand_cannot_be_deleted(): void
    {
        $default = Brand::query()->where('is_default', true)->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.brands.destroy', $default));

        $response->assertRedirect(route('admin.brands.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('brands', ['id' => $default->id]);
    }

    public function test_brand_with_associated_user_cannot_be_deleted(): void
    {
        $brand = Brand::create([
            'code' => 'USE' . strtoupper(substr(uniqid(), -5)),
            'name' => 'Brand With User',
            'is_default' => false,
        ]);

        User::create([
            'name' => 'Dependent Intern User',
            'email' => 'dependent_' . uniqid() . '@example.com',
            'password' => Hash::make('test-password'),
            'role' => 'pemagang',
            'brand_id' => $brand->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.brands.destroy', $brand));

        $response->assertRedirect(route('admin.brands.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_brand_with_webinars_cannot_be_deleted(): void
    {
        $brand = Brand::create([
            'code' => 'WEB' . strtoupper(substr(uniqid(), -5)),
            'name' => 'Brand With Webinar',
            'is_default' => false,
        ]);

        \Illuminate\Support\Facades\DB::table('webinars')->insert([
            'title' => 'Historical Brand Webinar',
            'event_date' => now(),
            'brand_id' => $brand->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.brands.destroy', $brand));

        $response->assertRedirect(route('admin.brands.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_spreadsheet_connection_update_rejects_duplicate_brand_scope_with_friendly_validation(): void
    {
        $brandSI = Brand::query()->where('code', 'SI')->firstOrFail();
        $brandRW = Brand::query()->where('code', 'RW')->firstOrFail();
        $service = app(SpreadsheetConnectionService::class);

        $sourceConnection = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION, $brandSI->id);
        $targetConnection = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION, $brandRW->id);

        $response = $this->actingAs($this->admin)->put(
            route('admin.spreadsheet-connections.update', $sourceConnection),
            [
                'display_name' => $sourceConnection->display_name,
                'spreadsheet_url' => $sourceConnection->spreadsheet_url,
                'apps_script_endpoint_url' => $sourceConnection->apps_script_endpoint_url,
                'drive_folder_url' => $sourceConnection->drive_folder_url,
                'brand_id' => $brandRW->id,
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHasErrors('_connection_key_scope');
        $this->assertDatabaseHas('spreadsheet_connections', [
            'id' => $sourceConnection->id,
            'brand_id' => $brandSI->id,
        ]);
        $this->assertDatabaseHas('spreadsheet_connections', [
            'id' => $targetConnection->id,
            'brand_id' => $brandRW->id,
        ]);
    }


    public function test_spreadsheet_connection_update_rejects_duplicate_global_null_scope(): void
    {
        $brandSI = Brand::query()->where('code', 'SI')->firstOrFail();
        $service = app(SpreadsheetConnectionService::class);
        $sourceConnection = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION, $brandSI->id);

        $globalConnection = SpreadsheetConnection::create([
            'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id' => null,
            'display_name' => 'Legacy Global Registration Connection',
            'is_enabled' => false,
            'connection_status' => SpreadsheetConnection::STATUS_NOT_CONFIGURED,
        ]);

        $response = $this->actingAs($this->admin)->put(
            route('admin.spreadsheet-connections.update', $sourceConnection),
            [
                'display_name' => $sourceConnection->display_name,
                'spreadsheet_url' => $sourceConnection->spreadsheet_url,
                'apps_script_endpoint_url' => $sourceConnection->apps_script_endpoint_url,
                'drive_folder_url' => $sourceConnection->drive_folder_url,
                'brand_id' => null,
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHasErrors('_connection_key_scope');
        $this->assertDatabaseHas('spreadsheet_connections', [
            'id' => $sourceConnection->id,
            'brand_id' => $brandSI->id,
        ]);
        $this->assertDatabaseHas('spreadsheet_connections', [
            'id' => $globalConnection->id,
            'brand_id' => null,
        ]);
    }

    public function test_unique_index_rollback_refuses_unsafe_downgrade_without_deleting_rows(): void
    {
        $brandSI = Brand::query()->where('code', 'SI')->firstOrFail();
        $brandRW = Brand::query()->where('code', 'RW')->firstOrFail();
        $service = app(SpreadsheetConnectionService::class);
        $siConnection = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION, $brandSI->id);
        $rwConnection = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION, $brandRW->id);

        $migration = require database_path('migrations/2026_11_15_000002_update_spreadsheet_connections_unique_index.php');

        try {
            $migration->down();
            $this->fail('Rollback harus ditolak ketika connection_key yang sama digunakan beberapa brand.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback spreadsheet_connections unique index dibatalkan', $exception->getMessage());
        }

        $this->assertDatabaseHas('spreadsheet_connections', [
            'id' => $siConnection->id,
            'brand_id' => $brandSI->id,
        ]);
        $this->assertDatabaseHas('spreadsheet_connections', [
            'id' => $rwConnection->id,
            'brand_id' => $brandRW->id,
        ]);
    }

    public function test_spreadsheet_connection_service_rejects_duplicate_brand_scope(): void
    {
        $brandSI = Brand::query()->where('code', 'SI')->firstOrFail();
        $brandRW = Brand::query()->where('code', 'RW')->firstOrFail();
        $service = app(SpreadsheetConnectionService::class);
        $sourceConnection = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION, $brandSI->id);
        $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION, $brandRW->id);

        try {
            $service->updateConnection($sourceConnection, [
                'display_name' => $sourceConnection->display_name,
                'spreadsheet_url' => $sourceConnection->spreadsheet_url,
                'apps_script_endpoint_url' => $sourceConnection->apps_script_endpoint_url,
                'drive_folder_url' => $sourceConnection->drive_folder_url,
                'brand_id' => $brandRW->id,
            ], $this->admin);
            $this->fail('Service harus menolak duplikasi koneksi brand.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sudah memiliki koneksi spreadsheet', $exception->getMessage());
        }

        $this->assertSame($brandSI->id, (int) $sourceConnection->fresh()->brand_id);
    }
}
