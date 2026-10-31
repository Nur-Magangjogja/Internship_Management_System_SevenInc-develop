<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\InternshipRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardBrandDistributionTest extends TestCase
{
    public function test_admin_dashboard_displays_brand_distribution_section_and_recent_intern_badges()
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test_brand_dash@example.com'],
            [
                'name'     => 'Admin Test Brand Dash',
                'username' => 'admin_test_brand_dash',
                'password' => bcrypt('password123'),
                'role'     => 'admin',
            ]
        );

        $brandA = Brand::firstOrCreate(
            ['code' => 'SI'],
            ['name' => 'SEVEN INC', 'is_default' => true]
        );
        $brandB = Brand::firstOrCreate(
            ['code' => 'RW'],
            ['name' => 'Republik Web', 'is_default' => false]
        );

        // Buat registrasi contoh untuk brand
        $userA = User::create([
            'name'     => 'Calon Pemagang Brand A',
            'username' => 'user_dash_test_a',
            'email'    => 'user_a_dash@example.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
            'brand_id' => $brandA->id,
        ]);
        $internA = InternshipRegistration::create([
            'user_id'           => $userA->id,
            'email'             => 'intern_a_dash@example.com',
            'fullname'          => 'Calon Pemagang Brand A',
            'brand_id'          => $brandA->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard.index'));

        $response->assertStatus(200);
        $response->assertViewHas('brandDistribution');
        $response->assertViewHas('brandChart');
        $response->assertViewHas('brandColors');

        // Pastikan konten section dan canvas muncul di HTML
        $response->assertSee('Distribusi Pemagang per Brand');
        $response->assertSee('admin-brand-distribution-chart');
        $response->assertSee('SEVEN INC');
        $response->assertSee('Default');

        // Pastikan Pendaftaran Terbaru memunculkan badge brand
        $response->assertSee('Calon Pemagang Brand A');
        $response->assertSee($brandA->name);

        // Bersihkan data tes
        $internA->forceDelete();
        $userA->forceDelete();
        $admin->forceDelete();
    }
}
