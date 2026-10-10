<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration as IR;
use App\Models\Mou;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceProfilingL3Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $uid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uid = uniqid('prof_', true);

        // Buat user utama
        $this->admin = User::create([
            'name'      => 'Admin Prof ' . substr($this->uid, 0, 6),
            'email'     => 'admin_' . $this->uid . '@test.com',
            'password'  => bcrypt('SecretAdminPass123!'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR Staff Prof ' . substr($this->uid, 0, 6),
            'email'     => 'hr_' . $this->uid . '@test.com',
            'password'  => bcrypt('SecretHrPass123!'),
            'role'      => 'hr_staff',
            'is_banned' => false,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang Prof ' . substr($this->uid, 0, 6),
            'email'     => 'pemagang_' . $this->uid . '@test.com',
            'password'  => bcrypt('SecretPemagangPass123!'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        $this->seedRepresentativeDataset();
    }

    private function seedRepresentativeDataset(): void
    {
        City::firstOrCreate(['name' => 'Yogyakarta']);
        City::firstOrCreate(['name' => 'Sleman']);
        City::firstOrCreate(['name' => 'Bantul']);

        $div1 = Division::firstOrCreate(
            ['name' => 'Programmer'],
            ['slug' => 'programmer', 'code' => 'PRG', 'is_active' => true]
        );

        // Institusi & Kontak
        for ($i = 1; $i <= 10; $i++) {
            $inst = Institution::create([
                'name' => "Universitas Test {$this->uid} {$i}",
                'normalized_name' => strtolower("universitas test {$this->uid} {$i}"),
                'type' => 'universitas',
                'city' => 'Yogyakarta',
                'is_active' => true,
            ]);

            $contact = InstitutionContact::create([
                'institution_id' => $inst->id,
                'name' => "Dosen Pembimbing {$i}",
                'normalized_name' => "dosen pembimbing {$i}",
                'phone' => "0812345678{$i}",
                'is_active' => true,
                'is_verified' => true,
            ]);

            $cycle = FollowUpCycle::create([
                'institution_id' => $inst->id,
                'institution_contact_id' => $contact->id,
                'cycle_year' => 2026,
                'status' => $i % 2 === 0 ? FollowUpCycle::STATUS_COMPLETED : FollowUpCycle::STATUS_OPEN,
                'final_result' => $i % 2 === 0 ? FollowUpCycle::RESULT_WILLING : null,
                'completed_at' => $i % 2 === 0 ? now() : null,
                'last_activity_at' => now(),
            ]);

            FollowUpAttempt::create([
                'followup_cycle_id' => $cycle->id,
                'attempt_number' => 1,
                'channel' => 'whatsapp',
                'result' => 'responded',
                'response_text' => 'Menyetujui komunikasi',
                'notes' => 'Catatan follow-up',
                'performed_by' => $this->hrStaff->id,
                'followed_up_at' => now(),
            ]);

            if ($i <= 5) {
                Mou::create([
                    'institution_id' => $inst->id,
                    'followup_cycle_id' => $cycle->id,
                    'company_mou_number' => "00{$i}/MOU/SI/III/2026-{$this->uid}",
                    'first_party_institution_name' => $inst->name,
                    'first_party_signatory_name' => "Rektor {$i}",
                    'first_party_signatory_title' => 'Rektor',
                    'second_party_company_name' => 'PT Seven Inc',
                    'second_party_signatory_name' => 'Direktur',
                    'second_party_signatory_title' => 'Direktur',
                    'agreement_date' => now()->toDateString(),
                    'effective_start' => now()->toDateString(),
                    'effective_end' => now()->addYears(2)->toDateString(),
                    'status' => Mou::STATUS_ACTIVE,
                    'file_path' => "documents/mou/00{$i}.pdf",
                ]);
            }
        }

        $fac = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => "Fakultas Teknik {$this->uid}",
        ]);

        $prog = StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => "Teknologi Informasi {$this->uid}",
        ]);

        // Pendaftaran Pemagang
        for ($j = 1; $j <= 20; $j++) {
            $u = User::create([
                'name'      => "Pemagang {$this->uid} {$j}",
                'email'     => "pemagang_{$this->uid}_{$j}@test.com",
                'password'  => bcrypt('Secret123!'),
                'role'      => 'pemagang',
                'is_banned' => false,
            ]);

            IR::create([
                'user_id' => $u->id,
                'institution_id' => $inst->id,
                'faculty_id' => $fac->id,
                'study_program_id' => $prog->id,
                'major' => $prog->name,
                'fullname' => "Pemagang Test {$j}",
                'email' => $u->email,
                'phone_number' => "0898765432{$j}",
                'gender' => 'Laki-laki',
                'current_city' => 'Yogyakarta',
                'institution_name' => $inst->name,
                'study_program' => $prog->name,
                'faculty' => $fac->name,
                'current_status' => 'Mahasiswa / Pelajar Aktif',
                'internship_type' => 'Magang Mandiri',
                'internship_interest' => 'Programmer',
                'internship_reason' => 'Ingin belajar',
                'wfo_readiness' => 'Siap WFO di Yogyakarta',
                'schedule_shift_readiness' => 'Sanggup',
                'has_laptop' => 1,
                'planned_duration' => '3 Bulan',
                'internship_status' => match($j % 5) {
                    0 => IR::STATUS_WAITING,
                    1 => IR::STATUS_ACCEPTED,
                    2 => IR::STATUS_ACTIVE,
                    3 => IR::STATUS_COMPLETED,
                    default => IR::STATUS_REJECTED,
                },
                'is_draft' => false,
            ]);

            ActivityLog::create([
                'actor_id' => $this->admin->id,
                'actor_name_snapshot' => $this->admin->name,
                'actor_email_snapshot' => $this->admin->email,
                'actor_role_snapshot' => 'admin',
                'action' => 'admin_review',
                'description' => "Review pendaftar {$j}",
                'created_at' => now()->subHours($j),
            ]);
        }
    }

    private function profileEndpoint(callable $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startMem = memory_get_usage();
        $startTime = microtime(true);

        $response = $action();

        $endTime = microtime(true);
        $endMem = memory_get_usage();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $totalQueryTime = array_sum(array_column($queries, 'time'));

        return [
            'status' => $response->getStatusCode(),
            'response_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'query_count' => count($queries),
            'query_time_ms' => round($totalQueryTime, 2),
            'memory_kb' => round(($endMem - $startMem) / 1024, 2),
        ];
    }

    public function test_profile_admin_dashboard(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->admin)->get(route('admin.dashboard.index')));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] Admin Dashboard: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(60, $metrics['query_count']);
    }

    public function test_profile_admin_interns_json(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->admin)->get(route('admin.interns.api', ['per_page' => 15])));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] Admin Interns API: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(20, $metrics['query_count']);
    }

    public function test_profile_admin_activity_logs(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->admin)->get(route('admin.activity-logs.index')));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] Admin Activity Logs: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(15, $metrics['query_count']);
    }

    public function test_profile_hr_dashboard(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->hrStaff)->get(route('hr.dashboard')));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] HR Dashboard: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(30, $metrics['query_count']);
    }

    public function test_profile_hr_followup_index(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->hrStaff)->get(route('hr.follow-up.index')));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] HR Follow-Up Index: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(25, $metrics['query_count']);
    }

    public function test_profile_hr_mou_index(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->hrStaff)->get(route('hr.mous.index')));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] HR MOU Index: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(20, $metrics['query_count']);
    }

    public function test_profile_pemagang_dashboard(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->pemagang)->get(route('pemagang.dashboard')));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] Pemagang Dashboard: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(10, $metrics['query_count']);
    }

    public function test_profile_pemagang_registration_form(): void
    {
        $metrics = $this->profileEndpoint(fn() => $this->actingAs($this->pemagang)->get(route('pemagang.registration.form')));

        $this->assertEquals(200, $metrics['status']);
        echo "\n[PROFILE] Pemagang Form: {$metrics['response_time_ms']}ms, Queries: {$metrics['query_count']}, DB Time: {$metrics['query_time_ms']}ms, Mem: {$metrics['memory_kb']}KB\n";
        $this->assertLessThan(15, $metrics['query_count']);
    }
}
