<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BrandDivisionRelationshipTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\DivisionSeeder::class);
        $this->seed(\Database\Seeders\OfficialBrandsDatasetSeeder::class);
        app(\App\Services\SpreadsheetConnectionService::class)->ensureBrandConnectionsExist();
        \App\Models\SpreadsheetConnection::where('connection_key', \App\Models\SpreadsheetConnection::KEY_REGISTRATION)
            ->update([
                'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/1DummyTestSpreadsheetId/edit',
                'spreadsheet_id'    => '1DummyTestSpreadsheetId',
                'drive_folder_url'  => 'https://drive.google.com/drive/folders/1DummyDriveFolderId',
                'drive_folder_id'   => '1DummyDriveFolderId',
                'is_enabled'        => true,
                'connection_status' => \App\Models\SpreadsheetConnection::STATUS_CONNECTED,
            ]);
    }

    protected function createAdmin(): User
    {
        static $counter = 1;
        $counter++;

        return User::create([
            'name'     => "Admin {$counter}",
            'email'    => "admin_div_{$counter}_" . uniqid() . "@example.com",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role'     => 'admin',
        ]);
    }

    protected function createPemagang(array $attributes = []): User
    {
        static $counter = 1;
        $counter++;

        return User::create(array_merge([
            'name'     => "Pemagang {$counter}",
            'email'    => "pemagang_div_{$counter}_" . uniqid() . "@example.com",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role'     => 'pemagang',
        ], $attributes));
    }

    /**
     * Test database brands hanya memiliki tepat 11 Brand Resmi tanpa duplikat SVII atau Alona.
     */
    public function test_brands_table_contains_only_the_11_official_brands(): void
    {
        $brands = Brand::all();
        $this->assertCount(11, $brands, 'Jumlah brand resmi harus tepat 11.');

        $expectedCodes = ['SI', 'RW', 'AKM', 'MJ', 'MK', 'TT', 'MO', 'BC', 'AK', 'TL', 'TS'];
        $actualCodes = $brands->pluck('code')->all();

        sort($expectedCodes);
        sort($actualCodes);

        $this->assertEquals($expectedCodes, $actualCodes);
        $this->assertFalse(Brand::where('code', 'SVII')->exists(), 'Brand SVII tidak boleh ada.');
        $this->assertFalse(Brand::where('code', 'ALB')->exists(), 'Brand ALB (Alona) tidak boleh ada.');
    }

    /**
     * Test relasi brand dan divisi: masing-masing brand memiliki divisi spesifik yang berbeda.
     */
    public function test_brands_have_specific_divisions_assigned(): void
    {
        $tl = Brand::where('code', 'TL')->firstOrFail();
        $rw = Brand::where('code', 'RW')->firstOrFail();
        $mo = Brand::where('code', 'MO')->firstOrFail();

        // Tukang Las memiliki divisi Las
        $tlDivisions = $tl->divisions->pluck('name')->all();
        $this->assertContains('Las', $tlDivisions);
        $this->assertContains('Administrasi', $tlDivisions);
        $this->assertNotContains('Programmer', $tlDivisions);

        // Republik Web memiliki Programmer dan UI/UX Designer
        $rwDivisions = $rw->divisions->pluck('name')->all();
        $this->assertContains('Programmer', $rwDivisions);
        $this->assertContains('UI/UX Designer', $rwDivisions);
        $this->assertNotContains('Las', $rwDivisions);

        // Makeover memiliki Fotografer dan Videografer
        $moDivisions = $mo->divisions->pluck('name')->all();
        $this->assertContains('Fotografer', $moDivisions);
        $this->assertContains('Videografer', $moDivisions);
        $this->assertNotContains('Programmer', $moDivisions);
        $this->assertNotContains('Las', $moDivisions);
    }

    /**
     * Test form pendaftaran memuat pilihan divisi khusus brand sesuai host pemohon.
     */
    public function test_registration_form_loads_only_brand_specific_divisions(): void
    {
        $pemagang = $this->createPemagang();

        // 1. Kunjungi via domain Tukang Las
        $responseTL = $this->actingAs($pemagang)
            ->get('http://daftar.tukanglas.org' . route('pemagang.registration.form', [], false));

        $responseTL->assertOk();
        $divisionsTL = $responseTL->viewData('divisions');
        $this->assertContains('Las', $divisionsTL);
        $this->assertNotContains('Programmer', $divisionsTL);

        // 2. Kunjungi via domain Republik Web
        $responseRW = $this->actingAs($pemagang)
            ->get('http://daftar.republikweb.net' . route('pemagang.registration.form', [], false));

        $responseRW->assertOk();
        $divisionsRW = $responseRW->viewData('divisions');
        $this->assertContains('Programmer', $divisionsRW);
        $this->assertContains('UI/UX Designer', $divisionsRW);
        $this->assertNotContains('Las', $divisionsRW);
    }

    /**
     * Test admin dapat memperbarui pilihan divisi pada suatu brand.
     */
    public function test_admin_can_update_brand_divisions(): void
    {
        $admin = $this->createAdmin();
        $brand = Brand::where('code', 'RW')->firstOrFail();

        $divProgrammer = Division::where('name', 'Programmer')->firstOrFail();
        $divAdministrasi = Division::where('name', 'Administrasi')->firstOrFail();

        $response = $this->actingAs($admin)
            ->put(route('admin.brands.update', $brand), [
                'code'         => $brand->code,
                'name'         => $brand->name,
                'division_ids' => [$divProgrammer->id, $divAdministrasi->id],
            ]);

        $response->assertRedirect(route('admin.brands.index'));

        $brand->refresh();
        $brandDivisionIds = $brand->divisions->pluck('id')->all();

        $this->assertCount(2, $brandDivisionIds);
        $this->assertContains($divProgrammer->id, $brandDivisionIds);
        $this->assertContains($divAdministrasi->id, $brandDivisionIds);
    }

    /**
     * Test admin dapat membuat divisi baru sekaligus menautkannya ke beberapa brand.
     */
    public function test_admin_can_create_division_with_linked_brands(): void
    {
        $admin = $this->createAdmin();
        $brandTL = Brand::where('code', 'TL')->firstOrFail();
        $brandRW = Brand::where('code', 'RW')->firstOrFail();

        $response = $this->actingAs($admin)
            ->post(route('admin.form-settings.divisions.store'), [
                'name'      => 'Mobile Flutter Developer',
                'code'      => 'FLUTTER',
                'brand_ids' => [$brandTL->id, $brandRW->id],
            ]);

        $response->assertRedirect(route('admin.form-settings.divisions'));
        $response->assertSessionHas('success');

        $division = Division::where('code', 'FLUTTER')->firstOrFail();
        $this->assertEquals('Mobile Flutter Developer', $division->name);
        $this->assertCount(2, $division->brands);
        $this->assertTrue($division->brands->contains('id', $brandTL->id));
        $this->assertTrue($division->brands->contains('id', $brandRW->id));
    }

    /**
     * Test admin dapat mengubah brand yang ditautkan ke divisi pada formulir edit divisi.
     */
    public function test_admin_can_update_division_linked_brands(): void
    {
        $admin = $this->createAdmin();
        $division = Division::firstOrFail();
        $brandMO = Brand::where('code', 'MO')->firstOrFail();
        $brandBC = Brand::where('code', 'BC')->firstOrFail();

        $response = $this->actingAs($admin)
            ->put(route('admin.form-settings.divisions.update', $division), [
                'name'      => $division->name,
                'code'      => $division->code,
                'brand_ids' => [$brandMO->id, $brandBC->id],
            ]);

        $response->assertRedirect(route('admin.form-settings.divisions'));
        $response->assertSessionHas('success');

        $division->refresh();
        $linkedBrandIds = $division->brands->pluck('id')->all();

        $this->assertCount(2, $linkedBrandIds);
        $this->assertContains($brandMO->id, $linkedBrandIds);
        $this->assertContains($brandBC->id, $linkedBrandIds);
    }

    /**
     * Test admin dapat melepas seluruh tautan brand dari divisi (uncheck all).
     */
    public function test_admin_can_unlink_all_brands_from_division(): void
    {
        $admin = $this->createAdmin();
        $division = Division::firstOrFail();

        $response = $this->actingAs($admin)
            ->put(route('admin.form-settings.divisions.update', $division), [
                'name' => $division->name,
                'code' => $division->code,
                // brand_ids dikosongkan/tidak dikirim (seperti saat semua checkbox di-uncheck)
            ]);

        $response->assertRedirect(route('admin.form-settings.divisions'));

        $division->refresh();
        $this->assertCount(0, $division->brands);
    }
}
