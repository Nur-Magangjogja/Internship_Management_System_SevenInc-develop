<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FollowUpTaskLockTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff1;
    private User $hrStaff2;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('lck_', true);

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
    }

    private function createDummyCycle(): FollowUpCycle
    {
        $institution = Institution::create(['name' => 'Kampus Lock ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dosen Kunci ' . $this->unique,
            'phone'          => '081234567890',
        ]);

        return FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);
    }

    public function test_hr_staff_can_claim_lock_on_open_cycle(): void
    {
        $cycle = $this->createDummyCycle();

        $response = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.claim', $cycle), [
            'duration' => 20,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
        $this->assertNotNull($cycle->locked_at);
        $this->assertNotNull($cycle->lock_expires_at);
        $this->assertTrue($cycle->isLocked());
        $this->assertTrue($cycle->isLockedBy($this->hrStaff1));
        $this->assertFalse($cycle->isLockedByOther($this->hrStaff1));
        $this->assertTrue($cycle->isLockedByOther($this->hrStaff2));

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_locked',
            'actor_user_id' => $this->hrStaff1->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_another_hr_staff_cannot_claim_active_lock(): void
    {
        $cycle = $this->createDummyCycle();

        // HR 1 mengunci tugas selama 15 menit
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // HR 2 mencoba mengklaim kunci yang sama
        $response = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.claim', $cycle));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $cycle->refresh();
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by); // tetap milik HR 1
    }

    public function test_another_hr_staff_can_claim_lock_after_expiration(): void
    {
        $cycle = $this->createDummyCycle();

        // HR 1 mengunci tugas, tetapi sudah kedaluwarsa 5 menit lalu
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now()->subMinutes(20),
            'lock_expires_at' => now()->subMinutes(5),
        ]);

        $this->assertFalse($cycle->isLocked()); // lock sudah expired

        // HR 2 mengklaim tugas
        $response = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.claim', $cycle));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertEquals($this->hrStaff2->id, $cycle->locked_by);
        $this->assertTrue($cycle->isLocked());
    }

    public function test_lock_holder_can_release_lock_voluntarily(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.release', $cycle));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertNull($cycle->locked_by);
        $this->assertNull($cycle->locked_at);
        $this->assertNull($cycle->lock_expires_at);
        $this->assertFalse($cycle->isLocked());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_unlocked',
            'actor_user_id' => $this->hrStaff1->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_non_holder_hr_staff_cannot_release_anothers_active_lock(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // HR 2 mencoba melepaskan kunci HR 1
        $response = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.release', $cycle));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $cycle->refresh();
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
    }

    public function test_admin_can_force_release_active_lock(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // Admin melakukan force release
        $response = $this->actingAs($this->admin)->post(route('admin.follow-up.force-release', $cycle));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertNull($cycle->locked_by);
        $this->assertFalse($cycle->isLocked());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_force_unlocked',
            'actor_user_id' => $this->admin->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_recording_attempt_preserves_lock(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'responded',
            'response_text'  => 'Guru pembimbing merespons dengan baik.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertEquals(1, $cycle->current_attempt_count);
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
        $this->assertNotNull($cycle->lock_expires_at);
        $this->assertTrue($cycle->isLocked());
    }

    public function test_other_hr_staff_cannot_record_attempt_when_locked(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // HR 2 mencoba merekam percobaan saat HR 1 mengunci
        $response = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $cycle->refresh();
        $this->assertEquals(0, $cycle->current_attempt_count); // tidak bertambah
    }

    public function test_completing_cycle_automatically_releases_lock(): void
    {
        $cycle = $this->createDummyCycle();
        \App\Models\FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff1->id,
            'channel'           => 'whatsapp',
            'result'            => 'responded',
            'followed_up_at'    => now(),
        ]);
        $cycle->update([
            'locked_by'             => $this->hrStaff1->id,
            'locked_at'             => now(),
            'lock_expires_at'       => now()->addMinutes(15),
            'current_attempt_count' => 1,
        ]);

        $response = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'willing',
            'final_notes'  => 'Disepakati untuk periode magang berikutnya.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertNull($cycle->locked_by);
        $this->assertNull($cycle->lock_expires_at);
        $this->assertFalse($cycle->isLocked());
    }
}
