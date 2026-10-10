<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Division;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InstitutionManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('test_', true);

        $this->admin = User::create([
            'name'     => 'admin_user_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'hr_user_' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_user_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);
    }

    public function test_admin_can_view_institutions_list(): void
    {
        $nameA = 'Kampus Alpha ' . $this->unique;
        $nameB = 'Kampus Beta ' . $this->unique;
        Institution::create(['name' => $nameA]);
        Institution::create(['name' => $nameB]);

        $response = $this->actingAs($this->admin)->get(route('admin.institutions.index'));

        $response->assertStatus(200);
        $response->assertSee($nameA);
        $response->assertSee($nameB);
    }

    public function test_admin_can_create_new_institution_with_contact_details_and_log_is_recorded(): void
    {
        $instName = 'Universitas Sains Teknologi ' . $this->unique;
        $payload = [
            'name'                => $instName,
            'address'             => 'Jl. Grafika No. 2, Yogyakarta',
            'phone'               => '0274-548512',
            'email'               => 'kerjasama@saintek.ac.id',
            'fax'                 => '0274-548513',
            'correspondence_unit' => 'Biro Kerjasama & Alumni',
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.institutions.store'), $payload);

        $response->assertRedirect(route('admin.institutions.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('institutions', [
            'name'                => $instName,
            'phone'               => '0274-548512',
            'email'               => 'kerjasama@saintek.ac.id',
            'correspondence_unit' => 'Biro Kerjasama & Alumni',
            'is_active'           => 1,
        ]);

        $inst = Institution::where('name', $instName)->first();
        $this->assertNotNull($inst->public_uuid);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'institution_created',
            'actor_user_id' => $this->admin->id,
            'subject_type'  => Institution::class,
            'subject_id'    => $inst->id,
        ]);
    }

    public function test_cannot_create_institution_with_duplicate_normalized_name(): void
    {
        $instName = 'Institut Riset ' . $this->unique;
        Institution::create(['name' => $instName]);

        $response = $this->actingAs($this->admin)->post(route('admin.institutions.store'), [
            'name' => '  ' . strtoupper($instName) . '  ',
        ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_admin_can_update_institution_details_and_log_is_recorded(): void
    {
        $institution = Institution::create([
            'name'  => 'Politeknik Uji ' . $this->unique,
            'phone' => '024-111111',
            'email' => 'lama@politeknik.ac.id',
        ]);

        $payload = [
            'name'                => 'Politeknik Uji ' . $this->unique,
            'phone'               => '024-999999',
            'email'               => 'baru@politeknik.ac.id',
            'address'             => 'Tembalang, Semarang',
            'fax'                 => '024-888888',
            'correspondence_unit' => 'Humas & BKK',
        ];

        $response = $this->actingAs($this->admin)->put(route('admin.institutions.update', $institution), $payload);

        $response->assertRedirect(route('admin.institutions.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('institutions', [
            'id'    => $institution->id,
            'phone' => '024-999999',
            'email' => 'baru@politeknik.ac.id',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'institution_updated',
            'subject_type' => Institution::class,
            'subject_id'   => $institution->id,
        ]);
    }

    public function test_admin_can_deactivate_institution_with_reason_and_log_is_recorded(): void
    {
        $institution = Institution::create([
            'name'      => 'SMK Mitra ' . $this->unique,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.institutions.toggle-status', $institution), [
            'inactive_reason' => 'Kerja sama periode 2025/2026 telah berakhir',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('institutions', [
            'id'              => $institution->id,
            'is_active'       => 0,
            'inactive_reason' => 'Kerja sama periode 2025/2026 telah berakhir',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'institution_status_toggled',
            'subject_type' => Institution::class,
            'subject_id'   => $institution->id,
        ]);
    }

    public function test_admin_can_reactivate_inactive_institution(): void
    {
        $institution = Institution::create([
            'name'            => 'SMK Nonaktif ' . $this->unique,
            'is_active'       => false,
            'inactive_reason' => 'Evaluasi kurikulum',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.institutions.toggle-status', $institution));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $institution->refresh();
        $this->assertTrue($institution->is_active);
        $this->assertNull($institution->inactive_reason);
    }

    public function test_hr_staff_cannot_toggle_institution_status(): void
    {
        $institution = Institution::create(['name' => 'Kampus HR Test ' . $this->unique, 'is_active' => true]);

        $response = $this->actingAs($this->hrStaff)->post(route('admin.institutions.toggle-status', $institution), [
            'inactive_reason' => 'Mencoba menonaktifkan',
        ]);

        $response->assertRedirect(route('hr.dashboard'));
    }

    public function test_hr_staff_can_view_institutions_list_and_detail(): void
    {
        $institution = Institution::create([
            'name'                => 'Universitas Mitra ' . $this->unique,
            'email'               => 'mitra@univ.ac.id',
            'correspondence_unit' => 'Direktorat Kerjasama',
        ]);

        $responseList = $this->actingAs($this->hrStaff)->get(route('hr.institutions.index', ['search' => $this->unique]));
        $responseList->assertStatus(200);
        $responseList->assertSee('Universitas Mitra ' . $this->unique);

        $responseDetail = $this->actingAs($this->hrStaff)->get(route('hr.institutions.show', $institution));
        $responseDetail->assertStatus(200);
        $responseDetail->assertSee('Direktorat Kerjasama');
    }

    public function test_pemagang_cannot_access_admin_or_hr_institutions(): void
    {
        $responseAdmin = $this->actingAs($this->pemagang)->get(route('admin.institutions.index'));
        $responseAdmin->assertRedirect(route('user.dashboard'));

        $responseHr = $this->actingAs($this->pemagang)->get(route('hr.institutions.index'));
        $responseHr->assertRedirect(route('user.dashboard'));
    }

    public function test_inactive_institution_is_excluded_from_pemagang_registration_form(): void
    {
        $activeInst   = Institution::create(['name' => 'Kampus Aktif ' . $this->unique, 'is_active' => true]);
        $inactiveInst = Institution::create(['name' => 'Kampus Nonaktif ' . $this->unique, 'is_active' => false]);

        $response = $this->actingAs($this->pemagang)->get(route('pemagang.registration.form'));

        $response->assertStatus(200);
        $response->assertSee('Kampus Aktif ' . $this->unique);
        $response->assertDontSee('Kampus Nonaktif ' . $this->unique);
    }

    public function test_new_registration_cannot_choose_inactive_institution(): void
    {
        $division = Division::first() ?? Division::create(['name' => 'Programmer (Front End / Backend)']);
        $inactiveInst = Institution::create([
            'name'      => 'Kampus Tutup ' . $this->unique,
            'is_active' => false,
        ]);

        $payload = [
            'fullname'            => 'Siswa Uji ' . $this->unique,
            'gender'              => 'Laki-laki',
            'born_date'           => '2002-01-01',
            'current_city'        => 'Yogyakarta',
            'email'               => $this->pemagang->email,
            'phone_number'        => '081234567890',
            'current_status'      => 'Mahasiswa/Pelajar',
            'student_id'          => '12345678',
            'institution_name'    => 'Kampus Tutup ' . $this->unique,
            'faculty'             => 'Teknik',
            'study_program'       => 'Informatika',
            'internship_interest' => $division->name,
            'internship_type'     => 'Magang Mandiri',
            'internship_reason'   => 'Ingin menambah pengalaman kerja.',
            'wfo_readiness'       => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'          => 1,
            'planned_duration'    => '3 Bulan',
        ];

        $response = $this->actingAs($this->pemagang)->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionHasErrors(['institution_name']);
    }

    public function test_existing_intern_from_deactivated_institution_preserves_data(): void
    {
        $institution = Institution::create(['name' => 'Politeknik Lama ' . $this->unique, 'is_active' => true]);
        $division    = Division::first() ?? Division::create(['name' => 'Web Developer', 'code' => 'WEB']);

        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $faculty = \App\Models\Faculty::first() ?? \App\Models\Faculty::create(['name' => 'Teknik', 'institution_id' => $institution->id]);
        $studyProgram = \App\Models\StudyProgram::first() ?? \App\Models\StudyProgram::create(['name' => 'Informatika', 'faculty_id' => $faculty->id]);

        $registration = InternshipRegistration::create([
            'user_id'                 => $this->pemagang->id,
            'fullname'                => 'Pemagang Lama',
            'born_date'               => '2001-01-01',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'city_id'                 => $city->id,
            'student_id'              => '12345678',
            'institution_id'          => $institution->id,
            'faculty_id'              => $faculty->id,
            'study_program_id'        => $studyProgram->id,
            'internship_reason'       => 'Pengalaman kerja',
            'internship_type'         => 'PKL',
            'internship_arrangement'  => 'Onsite',
            'current_status'          => 'Mahasiswa/Pelajar',
            'english_book_ability'    => 'Saya bisa',
            'division_id'             => $division->id,
            'internship_status'       => 'active',
            'start_date'              => now()->subMonth(),
            'end_date'                => now()->addMonths(2),
        ]);

        // Nonaktifkan institusi
        $institution->update([
            'is_active'       => false,
            'inactive_reason' => 'Perjanjian kerjasama usai',
        ]);

        $registration->refresh();
        $this->assertEquals($institution->id, $registration->institution_id);
        $this->assertEquals('Politeknik Lama ' . $this->unique, $registration->institution->name);
        $this->assertFalse($registration->institution->is_active);
    }
}
