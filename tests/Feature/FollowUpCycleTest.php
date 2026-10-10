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
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FollowUpCycleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $hrStaff2;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('flw_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
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
    }

    private function lockCycleFor(FollowUpCycle $cycle, User $user): void
    {
        FollowUpCycle::where('id', $cycle->id)->update([
            'locked_by'       => $user->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);
        $cycle->refresh();
    }

    public function test_hr_and_admin_can_view_followup_index(): void
    {
        $institution = Institution::create(['name' => 'Universitas Indeks ' . $this->unique]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dosen A ' . $this->unique,
            'phone'          => '081234567890',
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $responseHr = $this->actingAs($this->hrStaff)->get(route('hr.follow-up.index'));
        $responseHr->assertStatus(200);
        $responseHr->assertSee('Universitas Indeks ' . $this->unique);
        $responseHr->assertSee('Dosen A ' . $this->unique);

        $responseAdmin = $this->actingAs($this->admin)->get(route('admin.follow-up.index'));
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertSee('Universitas Indeks ' . $this->unique);
    }

    public function test_hr_can_open_new_followup_cycle_and_activity_is_logged(): void
    {
        $institution = Institution::create(['name' => 'Kampus Baru ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dr. Budi Santoso ' . $this->unique,
            'phone'          => '081298765432',
            'is_verified'    => true,
        ]);

        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.store'), [
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('followup_cycles', [
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);

        $cycle = FollowUpCycle::where('institution_contact_id', $contact->id)->where('cycle_year', 2026)->first();
        $this->assertNotNull($cycle->public_uuid);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_created',
            'actor_user_id' => $this->hrStaff->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_cannot_open_duplicate_followup_cycle_for_same_contact_and_year(): void
    {
        $institution = Institution::create(['name' => 'Kampus Duplikat ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dr. Wahyudi ' . $this->unique,
        ]);

        FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.store'), [
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
        ]);

        $response->assertSessionHasErrors(['institution_contact_id']);
    }

    public function test_cannot_open_cycle_for_inactive_institution(): void
    {
        $institution = Institution::create([
            'name'            => 'Kampus Nonaktif ' . $this->unique,
            'is_active'       => false,
            'inactive_reason' => 'Kerjasama ditangguhkan',
        ]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Nonaktif ' . $this->unique,
            'is_verified'    => true,
        ]);

        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.store'), [
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
        ]);

        $response->assertSessionHasErrors(['institution_id']);
    }

    public function test_hr_can_view_followup_show_with_linked_interns(): void
    {
        $institution = Institution::create(['name' => 'Kampus Show ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Show ' . $this->unique,
            'phone'          => '081234567890',
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $response = $this->actingAs($this->hrStaff)->get(route('hr.follow-up.show', $cycle));

        $response->assertStatus(200);
        $response->assertSee('Kampus Show ' . $this->unique);
        $response->assertSee('Pembimbing Show ' . $this->unique);
        $response->assertSee('0 dari 3 Percobaan');
    }

    public function test_hr_can_record_attempt_1_2_and_3_with_different_performers(): void
    {
        $institution = Institution::create(['name' => 'Kampus Attempts ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dosen Attempt ' . $this->unique,
            'phone'          => '081211112222',
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt 1 by hrStaff
        $this->lockCycleFor($cycle, $this->hrStaff);
        $resp1 = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at'   => now()->format('Y-m-d H:i:s'),
            'channel'          => 'whatsapp',
            'result'           => 'no_answer',
            'response_text'    => 'Pesan WA sudah terkirim centang dua tapi belum dibalas.',
            'notes'            => 'Akan dicoba telepon besok.',
            'next_followup_at' => now()->addDay()->format('Y-m-d'),
        ]);

        $resp1->assertRedirect();
        $cycle->refresh();
        $this->assertEquals(1, $cycle->current_attempt_count);
        $this->assertEquals(FollowUpCycle::STATUS_IN_PROGRESS, $cycle->status);

        $attempt1 = $cycle->attempts()->where('attempt_number', 1)->first();
        $this->assertNotNull($attempt1);
        $this->assertEquals($this->hrStaff->id, $attempt1->performed_by);
        $this->assertEquals('whatsapp', $attempt1->channel);

        // Attempt 2 by hrStaff2 (different performer)
        $this->lockCycleFor($cycle, $this->hrStaff2);
        $resp2 = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at'   => now()->format('Y-m-d H:i:s'),
            'channel'          => 'phone',
            'result'           => 'call_back',
            'response_text'    => 'Dosen sedang mengajar di kelas, meminta ditelepon jam 3 sore.',
            'next_followup_at' => now()->format('Y-m-d'),
        ]);

        $resp2->assertRedirect();
        $cycle->refresh();
        $this->assertEquals(2, $cycle->current_attempt_count);
        $this->assertEquals(FollowUpCycle::STATUS_WAITING_RESPONSE, $cycle->status);

        $attempt2 = $cycle->attempts()->where('attempt_number', 2)->first();
        $this->assertEquals($this->hrStaff2->id, $attempt2->performed_by);

        // Attempt 3 by admin
        $this->lockCycleFor($cycle, $this->admin);
        $resp3 = $this->actingAs($this->admin)->post(route('admin.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'responded',
            'response_text'  => 'Dosen bersedia menerima proposal penempatan siswa magang.',
        ]);

        $resp3->assertRedirect();
        $cycle->refresh();
        $this->assertEquals(3, $cycle->current_attempt_count);

        $this->assertFalse($cycle->canAttempt()); // quota 3 reached
    }

    public function test_attempt_4_is_rejected(): void
    {
        $institution = Institution::create(['name' => 'Kampus Max Attempts ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Max ' . $this->unique,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'current_attempt_count'  => 3,
        ]);

        $this->lockCycleFor($cycle, $this->hrStaff);
        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('followup_attempts', [
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 4,
        ]);
    }

    public function test_hr_can_complete_cycle_with_final_decision_and_log_is_recorded(): void
    {
        $institution = Institution::create(['name' => 'Kampus Selesai ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Selesai ' . $this->unique,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'current_attempt_count'  => 1,
        ]);

        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff->id,
            'channel'           => 'whatsapp',
            'result'            => 'responded',
            'followed_up_at'    => now(),
        ]);

        $this->lockCycleFor($cycle, $this->hrStaff);
        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'willing',
            'final_notes'  => 'Jurusan siap mengirimkan 5 siswa magang di bulan November.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertEquals('willing', $cycle->final_result);
        $this->assertEquals('Jurusan siap mengirimkan 5 siswa magang di bulan November.', $cycle->final_notes);
        $this->assertEquals($this->hrStaff->id, $cycle->confirmed_by);
        $this->assertNotNull($cycle->completed_at);
        $this->assertTrue($cycle->isClosed());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_completed',
            'actor_user_id' => $this->hrStaff->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_cannot_record_attempt_on_closed_cycle(): void
    {
        $institution = Institution::create(['name' => 'Kampus Closed ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Closed ' . $this->unique,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'current_attempt_count'  => 1,
            'completed_at'           => now(),
        ]);

        $response = $this->actingAs($this->hrStaff)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'responded',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_pemagang_cannot_access_followup_routes(): void
    {
        $institution = Institution::create(['name' => 'Kampus Pemagang ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Pemagang ' . $this->unique,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt index
        $res1 = $this->actingAs($this->pemagang)->get(route('hr.follow-up.index'));
        $res1->assertRedirect(route('user.dashboard'));

        // Attempt create
        $res2 = $this->actingAs($this->pemagang)->get(route('hr.follow-up.create'));
        $res2->assertRedirect(route('user.dashboard'));

        // Attempt show
        $res3 = $this->actingAs($this->pemagang)->get(route('hr.follow-up.show', $cycle));
        $res3->assertRedirect(route('user.dashboard'));

        // Attempt store attempt
        $res4 = $this->actingAs($this->pemagang)->post(route('hr.follow-up.attempts.store', $cycle), [
            'channel' => 'phone',
            'result'  => 'responded',
        ]);
        $res4->assertRedirect(route('user.dashboard'));
    }
}
