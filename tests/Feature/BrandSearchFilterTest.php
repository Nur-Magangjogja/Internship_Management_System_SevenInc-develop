<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\InternshipRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BrandSearchFilterTest extends TestCase
{
    use DatabaseTransactions;
    private function createAdmin(): User
    {
        static $counter = 200;
        $counter++;

        return User::create([
            'name'     => "Admin {$counter}",
            'email'    => "admin_{$counter}_" . uniqid() . "@example.com",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role'     => 'admin',
        ]);
    }

    public function test_pendaftar_page_contains_brand_filter_and_search_placeholder(): void
    {
        $admin = $this->createAdmin();
        $brand = Brand::first();

        $response = $this->actingAs($admin)->get(route('admin.interns.pendaftar'));

        $response->assertOk();
        $response->assertSee('id="brand-filter"', false);
        $response->assertSee('Semua Brand', false);
        if ($brand) {
            $response->assertSee($brand->name, false);
        }
        $response->assertSee('placeholder="Cari nama / universitas / brand..."', false);

        $admin->delete();
    }

    public function test_pendaftar_page_selects_brand_from_query_parameter(): void
    {
        $admin = $this->createAdmin();
        $brand = Brand::first();

        if ($brand) {
            $response = $this->actingAs($admin)->get(route('admin.interns.pendaftar', ['brand_id' => $brand->id]));
            $response->assertOk();
            $response->assertSee('value="' . $brand->id . '" selected', false);
        }

        $admin->delete();
    }

    public function test_api_filters_waiting_candidates_by_brand_id(): void
    {
        $admin = $this->createAdmin();

        $brand1 = Brand::firstOrCreate(['code' => 'TEST_B1'], ['name' => 'Brand Test Satu']);
        $brand2 = Brand::firstOrCreate(['code' => 'TEST_B2'], ['name' => 'Brand Test Dua']);

        $candidate1 = InternshipRegistration::create([
            'fullname'          => 'Calon Brand Satu',
            'brand_id'          => $brand1->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'is_draft'          => false,
            'created_at'        => now(),
        ]);

        $candidate2 = InternshipRegistration::create([
            'fullname'          => 'Calon Brand Dua',
            'brand_id'          => $brand2->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'is_draft'          => false,
            'created_at'        => now(),
        ]);

        // Filter by brand 1
        $res1 = $this->actingAs($admin)->getJson(route('admin.interns.api', [
            'scope'    => 'waiting',
            'mode'     => 'pendaftar',
            'brand_id' => $brand1->id,
        ]));

        $res1->assertOk();
        $data1 = $res1->json('data');
        $ids1 = collect($data1)->pluck('id')->all();

        $this->assertContains($candidate1->id, $ids1);
        $this->assertNotContains($candidate2->id, $ids1);

        // Filter by brand 2
        $res2 = $this->actingAs($admin)->getJson(route('admin.interns.api', [
            'scope'    => 'waiting',
            'mode'     => 'pendaftar',
            'brand_id' => $brand2->id,
        ]));

        $res2->assertOk();
        $data2 = $res2->json('data');
        $ids2 = collect($data2)->pluck('id')->all();

        $this->assertContains($candidate2->id, $ids2);
        $this->assertNotContains($candidate1->id, $ids2);

        // Clean up
        $candidate1->forceDelete();
        $candidate2->forceDelete();
        $admin->delete();
    }

    public function test_api_filters_by_brand_name_or_code_string(): void
    {
        $admin = $this->createAdmin();

        $brandAlpha = Brand::firstOrCreate(['code' => 'ALPHA_CODE'], ['name' => 'Alpha Brand Name']);

        $candidate = InternshipRegistration::create([
            'fullname'          => 'Calon Alpha Terdaftar',
            'brand_id'          => $brandAlpha->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'is_draft'          => false,
            'created_at'        => now(),
        ]);

        // Filter using brand code
        $resCode = $this->actingAs($admin)->getJson(route('admin.interns.api', [
            'brand' => 'ALPHA_CODE',
        ]));
        $resCode->assertOk();
        $idsCode = collect($resCode->json('data'))->pluck('id')->all();
        $this->assertContains($candidate->id, $idsCode);

        // Filter using brand name
        $resName = $this->actingAs($admin)->getJson(route('admin.interns.api', [
            'brand' => 'Alpha Brand Name',
        ]));
        $resName->assertOk();
        $idsName = collect($resName->json('data'))->pluck('id')->all();
        $this->assertContains($candidate->id, $idsName);

        // Clean up
        $candidate->forceDelete();
        $admin->delete();
    }

    public function test_api_filters_active_interns_by_brand_id(): void
    {
        $admin = $this->createAdmin();

        $brandA = Brand::firstOrCreate(['code' => 'TEST_BA'], ['name' => 'Brand Alpha']);
        $brandB = Brand::firstOrCreate(['code' => 'TEST_BB'], ['name' => 'Brand Beta']);

        $internActiveA = InternshipRegistration::create([
            'fullname'          => 'Pemagang Aktif Alpha',
            'brand_id'          => $brandA->id,
            'internship_status' => InternshipRegistration::STATUS_ACTIVE,
            'is_draft'          => false,
            'start_date'        => now()->subDays(10)->toDateString(),
            'created_at'        => now()->subDays(15),
        ]);

        $internActiveB = InternshipRegistration::create([
            'fullname'          => 'Pemagang Aktif Beta',
            'brand_id'          => $brandB->id,
            'internship_status' => InternshipRegistration::STATUS_ACTIVE,
            'is_draft'          => false,
            'start_date'        => now()->subDays(10)->toDateString(),
            'created_at'        => now()->subDays(15),
        ]);

        $res = $this->actingAs($admin)->getJson(route('admin.interns.api', [
            'scope'    => 'active',
            'mode'     => 'pemagang',
            'brand_id' => $brandA->id,
        ]));

        $res->assertOk();
        $data = $res->json('data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($internActiveA->id, $ids);
        $this->assertNotContains($internActiveB->id, $ids);

        // Clean up
        $internActiveA->forceDelete();
        $internActiveB->forceDelete();
        $admin->delete();
    }

    public function test_api_search_query_matches_brand_name(): void
    {
        $admin = $this->createAdmin();

        $brandSpecial = Brand::firstOrCreate(['code' => 'SPEC_BR'], ['name' => 'BrandKhususUnik']);

        $candidate = InternshipRegistration::create([
            'fullname'          => 'Peserta Bernama Biasa',
            'brand_id'          => $brandSpecial->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'is_draft'          => false,
            'created_at'        => now(),
        ]);

        $res = $this->actingAs($admin)->getJson(route('admin.interns.api', [
            'q' => 'BrandKhususUnik',
        ]));

        $res->assertOk();
        $data = $res->json('data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($candidate->id, $ids);

        // Clean up
        $candidate->forceDelete();
        $admin->delete();
    }
}
