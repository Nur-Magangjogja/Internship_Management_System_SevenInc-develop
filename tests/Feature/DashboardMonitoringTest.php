<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardMonitoringTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff1;
    private User $hrStaff2;
    private User $pemagang;
    private Institution $institution;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('dash_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff1 = User::create([
            'name'     => 'hr1_' . substr($this->unique, 0, 8),
            'email'    => 'hr1_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->hrStaff2 = User::create([
            'name'     => 'hr2_' . substr($this->unique, 0, 8),
            'email'    => 'hr2_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->institution = Institution::create([
            'name'                => 'Univ Dashboard Test ' . $this->unique,
            'address'             => 'Yogyakarta',
            'phone'               => '0274-111222',
            'email'               => 'univ_' . $this->unique . '@test.com',
            'correspondence_unit' => 'Biro Kerjasama',
            'is_active'           => true,
        ]);
    }

    public function test_hr_dashboard_displays_followup_and_mou_metrics()
    {
        $response = $this->actingAs($this->hrStaff1)->get(route('hr.dashboard'));

        $response->assertStatus(200);
        $response->assertViewIs('hr.dashboard');
        $response->assertViewHasAll([
            'user',
            'followUpMetrics',
            'mouMetrics',
            'masterCounts',
            'myActiveTasks',
            'pendingFollowUps',
            'readyMouPartners',
            'recentMous',
        ]);

        $response->assertSee('Dashboard Operasional HR');
        $response->assertSee('Antrean Tugas Follow-Up');
        $response->assertSee('Siklus Dokumen Kerja Sama');
    }

    public function test_admin_can_access_hr_dashboard()
    {
        $response = $this->actingAs($this->admin)->get(route('hr.dashboard'));

        $response->assertStatus(200);
        $response->assertViewIs('hr.dashboard');
    }

    public function test_pemagang_and_guest_cannot_access_hr_dashboard()
    {
        $responsePemagang = $this->actingAs($this->pemagang)->get(route('hr.dashboard'));
        $this->assertTrue(in_array($responsePemagang->status(), [403, 302]));

        auth()->logout();
        $responseGuest = $this->get(route('hr.dashboard'));
        $responseGuest->assertRedirect(route('user.login'));
    }

    public function test_admin_dashboard_displays_partnership_monitoring_metrics()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));

        $response->assertStatus(200);
        $response->assertViewIs('dashboard.index');
        $response->assertViewHasAll([
            'partnershipMetrics',
            'recentPartnershipLogs',
            'expiringMous',
        ]);

        $response->assertSee('Monitoring Operasional Kemitraan');
        $response->assertSee('Tindak Lanjut Institusi');
    }

    public function test_hr_staff_cannot_access_admin_dashboard()
    {
        $response = $this->actingAs($this->hrStaff1)->get(route('admin.dashboard.index'));
        $this->assertTrue(in_array($response->status(), [403, 302]));
    }

    public function test_claimed_task_shows_on_hr_dashboard_for_lock_holder()
    {
        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Bpk. Pembimbing ' . $this->unique,
            'position_title' => 'Koordinator PKL',
            'phone'          => '0812345678',
            'is_active'      => true,
        ]);

        // Cycle dikunci oleh hrStaff1
        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'locked_by'              => $this->hrStaff1->id,
            'locked_at'              => now(),
            'lock_expires_at'        => now()->addMinutes(15),
            'last_activity_at'       => now(),
        ]);

        // hrStaff1 membuka dashboard -> melihat tugas terkunci atas namanya
        $response1 = $this->actingAs($this->hrStaff1)->get(route('hr.dashboard'));
        $response1->assertStatus(200);
        $response1->assertSee($this->institution->name);
        $this->assertEquals(1, $response1->viewData('followUpMetrics')['my_claimed']);

        // hrStaff2 membuka dashboard -> melihat 0 tugas yang sedang ia kunci
        $response2 = $this->actingAs($this->hrStaff2)->get(route('hr.dashboard'));
        $response2->assertStatus(200);
        $this->assertEquals(0, $response2->viewData('followUpMetrics')['my_claimed']);
    }

    public function test_ready_to_mou_institutions_show_on_hr_dashboard()
    {
        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Ibu Kontak MOU ' . $this->unique,
            'position_title' => 'Wakil Rektor',
            'phone'          => '0812345678',
            'is_active'      => true,
        ]);

        // Cycle selesai dengan hasil WILLING
        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'completed_at'           => now(),
            'confirmed_by'           => $this->hrStaff1->id,
            'confirmed_at'           => now(),
        ]);

        $response = $this->actingAs($this->hrStaff1)->get(route('hr.dashboard'));
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->viewData('mouMetrics')['ready_to_generate']);
        $response->assertSee('Generate MOU');
    }

    public function test_expiring_mous_show_on_admin_dashboard()
    {
        $expiringNumber = 'MOU/7INC/EXP/' . substr($this->unique, 0, 6);

        Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => $expiringNumber,
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Rektor Test',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->subYears(2)->toDateString(),
            'effective_start'              => now()->subYears(2)->toDateString(),
            'effective_end'                => now()->addDays(20)->toDateString(), // 20 hari lagi kedaluwarsa (< 60 hari)
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_at'                    => now()->subYears(2),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $response->assertStatus(200);
        $response->assertSee($expiringNumber);
        $this->assertGreaterThanOrEqual(1, $response->viewData('partnershipMetrics')['mou_expiring_soon']);
    }
}
