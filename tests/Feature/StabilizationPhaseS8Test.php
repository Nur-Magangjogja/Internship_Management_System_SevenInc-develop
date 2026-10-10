<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StabilizationPhaseS8Test extends TestCase
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

        $this->unique = uniqid('s8_', true);

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
        $institution = Institution::create(['name' => 'Kampus S8 Lock ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dosen S8 Kunci ' . $this->unique,
            'phone'          => '081234567890',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        return FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);
    }

    public function test_hr_a_claim_succeeds_and_activity_logged(): void
    {
        $cycle = $this->createDummyCycle();

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.claim', $cycle), [
            'duration' => 20,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'status' => 'success',
        ]);

        $cycle->refresh();
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
        $this->assertTrue($cycle->isLocked());
        $this->assertTrue($cycle->isLockedBy($this->hrStaff1));

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_locked',
            'actor_user_id' => $this->hrStaff1->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_hr_b_concurrent_claim_fails_with_conflict_409(): void
    {
        $cycle = $this->createDummyCycle();

        // HR 1 mengunci tugas selama 15 menit
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // HR 2 mencoba klaim via JSON -> 409 Conflict
        $responseJson = $this->actingAs($this->hrStaff2)->postJson(route('hr.follow-up.claim', $cycle));
        $responseJson->assertStatus(409);
        $responseJson->assertJsonFragment([
            'status' => 'error',
        ]);

        // HR 2 mencoba klaim via Web -> Error Session
        $responseWeb = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.claim', $cycle));
        $responseWeb->assertRedirect();
        $responseWeb->assertSessionHas('error');

        $cycle->refresh();
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
    }

    public function test_claim_by_same_actor_is_idempotent_and_extends_lock(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now()->subMinutes(10),
            'lock_expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.claim', $cycle), [
            'duration' => 25,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'success']);

        $cycle->refresh();
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
        $this->assertTrue($cycle->lock_expires_at->gt(now()->addMinutes(20)));

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_lock_renewed',
            'actor_user_id' => $this->hrStaff1->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_expired_lock_can_be_reclaimed_by_another_hr_and_logged(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now()->subMinutes(20),
            'lock_expires_at' => now()->subMinutes(5),
        ]);

        $this->assertFalse($cycle->isLocked());

        // HR 2 sekarang dapat mengklaim ulang kunci yang telah kedaluwarsa
        $response = $this->actingAs($this->hrStaff2)->postJson(route('hr.follow-up.claim', $cycle), [
            'duration' => 15,
        ]);

        $response->assertStatus(200);
        $cycle->refresh();
        $this->assertEquals($this->hrStaff2->id, $cycle->locked_by);
        $this->assertTrue($cycle->isLocked());

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_lock_reclaimed',
            'actor_user_id' => $this->hrStaff2->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_reclaim_with_banned_or_soft_deleted_previous_owner_is_logged_with_reason(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // HR 1 diblokir
        $this->hrStaff1->update([
            'is_banned'  => true,
            'banned_at'  => now(),
            'ban_reason' => 'Security ban',
        ]);

        // Lock dianggap invalid oleh isLocked()
        $this->assertFalse($cycle->isLocked());

        // HR 2 dapat mengklaim ulang
        $response = $this->actingAs($this->hrStaff2)->postJson(route('hr.follow-up.claim', $cycle), [
            'duration' => 15,
        ]);

        $response->assertStatus(200);
        $cycle->refresh();
        $this->assertEquals($this->hrStaff2->id, $cycle->locked_by);
        $this->assertTrue($cycle->isLocked());

        $log = ActivityLog::where('action', 'followup_cycle_lock_reclaimed')
            ->where('subject_id', $cycle->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('owner_banned', $log->before_data['reclaim_reason'] ?? null);
    }

    public function test_claim_on_closed_or_completed_cycle_is_rejected(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'status'       => FollowUpCycle::STATUS_COMPLETED,
            'final_result' => FollowUpCycle::RESULT_WILLING,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.claim', $cycle));
        $response->assertStatus(409);
        $response->assertJsonFragment(['status' => 'error']);
    }

    public function test_hr_a_heartbeat_extends_lock_duration_successfully(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now()->subMinutes(10),
            'lock_expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.heartbeat', $cycle), [
            'duration' => 25,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'status' => 'success',
        ]);

        $cycle->refresh();
        $this->assertTrue($cycle->lock_expires_at->gt(now()->addMinutes(20)));

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'followup_cycle_lock_renewed',
            'actor_user_id' => $this->hrStaff1->id,
            'subject_type'  => FollowUpCycle::class,
            'subject_id'    => $cycle->id,
        ]);
    }

    public function test_heartbeat_by_non_lock_holder_fails_with_conflict(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($this->hrStaff2)->postJson(route('hr.follow-up.heartbeat', $cycle));

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'status' => 'error',
        ]);
    }

    public function test_heartbeat_on_expired_lock_fails(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now()->subMinutes(20),
            'lock_expires_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.heartbeat', $cycle));
        $response->assertStatus(409);
    }

    public function test_release_lock_by_owner_succeeds_and_logs_snapshot(): void
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
        $this->assertFalse($cycle->isLocked());

        $log = ActivityLog::where('action', 'followup_cycle_unlocked')
            ->where('subject_id', $cycle->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->hrStaff1->id, $log->before_data['previous_locked_by'] ?? null);
    }

    public function test_release_lock_by_non_owner_is_rejected(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.release', $cycle));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $cycle->refresh();
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
        $this->assertTrue($cycle->isLocked());
    }

    public function test_admin_force_unlock_releases_lock_and_records_audit_log(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.follow-up.force-release', $cycle));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertNull($cycle->locked_by);
        $this->assertNull($cycle->lock_expires_at);
        $this->assertFalse($cycle->isLocked());

        $log = ActivityLog::where('action', 'followup_cycle_force_unlocked')
            ->where('subject_id', $cycle->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->admin->id, $log->actor_user_id);
        $this->assertEquals($this->hrStaff1->id, $log->before_data['previous_locked_by'] ?? null);
    }

    public function test_non_admin_cannot_force_unlock(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // HTTP layer: non-admin gets 403 Forbidden
        $response = $this->actingAs($this->hrStaff2)->postJson(route('admin.follow-up.force-release', $cycle));
        $response->assertStatus(403);

        // Service layer defense-in-depth: non-admin force release throws DomainException
        $this->expectException(\DomainException::class);
        app(\App\Services\FollowUpService::class)->releaseLock($cycle, $this->hrStaff2, force: true);
    }

    public function test_record_attempt_without_lock_is_rejected(): void
    {
        $cycle = $this->createDummyCycle();
        // Cycle belum dikunci sama sekali

        $responseJson = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $responseJson->assertStatus(409);

        $responseWeb = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $responseWeb->assertRedirect();
        $responseWeb->assertSessionHas('error');

        $this->assertEquals(0, $cycle->attempts()->count());
    }

    public function test_record_attempt_with_expired_lock_is_rejected(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now()->subMinutes(20),
            'lock_expires_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $response->assertStatus(409);
        $this->assertEquals(0, $cycle->attempts()->count());
    }

    public function test_record_attempt_by_non_lock_holder_is_rejected(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($this->hrStaff2)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $response->assertStatus(409);
        $this->assertEquals(0, $cycle->attempts()->count());
    }

    public function test_admin_cannot_record_attempt_without_claiming_lock(): void
    {
        $cycle = $this->createDummyCycle();

        // Admin tanpa lock mencoba merekam attempt -> Ditolak (NO VALID LOCK = NO MUTATION)
        $response = $this->actingAs($this->admin)->postJson(route('admin.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $response->assertStatus(409);
        $this->assertEquals(0, $cycle->attempts()->count());
    }

    public function test_record_attempt_with_valid_lock_succeeds_and_preserves_lock(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
            'notes'          => 'Percobaan pertama berhasil terhubung.',
        ]);

        $response->assertStatus(200);
        $cycle->refresh();
        $this->assertEquals(1, $cycle->attempts()->count());
        $this->assertEquals(1, $cycle->current_attempt_count);
        $this->assertEquals($this->hrStaff1->id, $cycle->locked_by);
        $this->assertNotNull($cycle->locked_at);
        $this->assertTrue($cycle->lock_expires_at->gt(now()));
        $this->assertTrue($cycle->isLocked());

        // HR B cannot mutate immediately after HR A attempt
        $responseB = $this->actingAs($this->hrStaff2)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $responseB->assertStatus(409);

        // HR A explicit release -> HR B may claim
        $releaseResp = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.release', $cycle));
        $releaseResp->assertStatus(200);
        $cycle->refresh();
        $this->assertNull($cycle->locked_by);

        $claimResp = $this->actingAs($this->hrStaff2)->postJson(route('hr.follow-up.claim', $cycle), [
            'duration' => 15,
        ]);
        $claimResp->assertStatus(200);
        $cycle->refresh();
        $this->assertEquals($this->hrStaff2->id, $cycle->locked_by);
    }

    public function test_complete_cycle_without_lock_is_rejected(): void
    {
        $cycle = $this->createDummyCycle();
        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff1->id,
            'channel'           => 'whatsapp',
            'result'            => 'responded',
            'followed_up_at'    => now(),
        ]);
        $cycle->update(['current_attempt_count' => 1]);

        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'willing',
        ]);
        $response->assertStatus(409);

        $cycle->refresh();
        $this->assertNotEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
    }

    public function test_admin_cannot_complete_cycle_without_claiming_lock(): void
    {
        $cycle = $this->createDummyCycle();
        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff1->id,
            'channel'           => 'whatsapp',
            'result'            => 'responded',
            'followed_up_at'    => now(),
        ]);
        $cycle->update(['current_attempt_count' => 1]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.follow-up.complete', $cycle), [
            'final_result' => 'willing',
        ]);
        $response->assertStatus(409);

        $cycle->refresh();
        $this->assertNotEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
    }

    public function test_complete_cycle_with_valid_lock_succeeds_and_auto_releases_lock(): void
    {
        $cycle = $this->createDummyCycle();
        FollowUpAttempt::create([
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
            'final_notes'  => 'Institusi sepakat menerima pemagang.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertNull($cycle->locked_by);
        $this->assertFalse($cycle->isLocked());
    }

    public function test_banned_hr_loses_ability_to_write_despite_prior_lock(): void
    {
        $cycle = $this->createDummyCycle();
        $cycle->update([
            'locked_by'       => $this->hrStaff1->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);

        // HR 1 diblokir/banned
        $this->hrStaff1->update([
            'is_banned'  => true,
            'banned_at'  => now(),
            'ban_reason' => 'Pelanggaran keamanan data',
        ]);

        // Request HR 1 harus ditolak dengan 403 oleh EnsureAccountIsActive
        $response = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.heartbeat', $cycle));
        $response->assertStatus(403);
    }

    /**
     * Guardrail 3: Real Concurrency Contention Test using two independent CLI worker subprocesses
     * targeting strictly internship_management_system_testing.
     */
    public function test_real_concurrent_claim_contention_between_two_subprocesses(): void
    {
        // Setup dedicated fixture records directly committed to testing DB
        // Pause the outer DatabaseTransactions so records are visible across process boundaries
        DB::commit();

        $tag = uniqid('contention_', true);
        $institution = Institution::create(['name' => 'Contention Kampus ' . $tag, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Contention PIC ' . $tag,
            'phone'          => '081299999999',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $workerAUser = User::create([
            'name'     => 'workerA_' . substr($tag, 0, 8),
            'email'    => 'workerA_' . $tag . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $workerBUser = User::create([
            'name'     => 'workerB_' . substr($tag, 0, 8),
            'email'    => 'workerB_' . $tag . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        try {
            $workerScript = base_path('tests/Fixtures/claim_worker.php');
            $env = [
                'APP_ENV'     => 'testing',
                'DB_DATABASE' => 'internship_management_system_testing',
            ];

            // Launch worker A and worker B simultaneously
            $cmdA = ['php', $workerScript, 'WorkerA', (string) $workerAUser->id, (string) $cycle->id, '15'];
            $cmdB = ['php', $workerScript, 'WorkerB', (string) $workerBUser->id, (string) $cycle->id, '15'];

            $procA = new Process($cmdA, base_path(), $env, null, 10.0);
            $procB = new Process($cmdB, base_path(), $env, null, 10.0);

            $procA->start();
            $procB->start();

            $procA->wait();
            $procB->wait();

            $outputA = json_decode($procA->getOutput(), true);
            $outputB = json_decode($procB->getOutput(), true);

            $this->assertIsArray($outputA, "Worker A failed output: " . $procA->getOutput() . " " . $procA->getErrorOutput());
            $this->assertIsArray($outputB, "Worker B failed output: " . $procB->getOutput() . " " . $procB->getErrorOutput());

            // Validate both workers connected to testing DB
            $this->assertEquals('internship_management_system_testing', $outputA['database']);
            $this->assertEquals('internship_management_system_testing', $outputB['database']);

            // Validate mutual exclusion: exactly one 200 and one 409
            $statuses = [$outputA['status'], $outputB['status']];
            sort($statuses);
            $this->assertEquals([200, 409], $statuses, "Expected exactly one winner (200) and one loser (409). Got: " . json_encode($statuses));

            $winner = $outputA['status'] === 200 ? 'WorkerA' : 'WorkerB';
            $loser  = $winner === 'WorkerA' ? 'WorkerB' : 'WorkerA';
            $winnerUserId = $winner === 'WorkerA' ? $workerAUser->id : $workerBUser->id;

            $cycle->refresh();
            $this->assertEquals($winnerUserId, (int) $cycle->locked_by);
            $this->assertTrue($cycle->isLocked());

            // Report contention metrics
            echo "\n--- S8 CONTENTION METRICS ---\n";
            echo "worker A DB: {$outputA['database']}\n";
            echo "worker B DB: {$outputB['database']}\n";
            echo "winner: {$winner} (User ID {$winnerUserId})\n";
            echo "loser: {$loser}\n";
            echo "final locked_by: {$cycle->locked_by}\n";
            echo "exactly one winner: YES\n";
            echo "-----------------------------\n";
        } finally {
            // Clean up fixture records
            $cycle->forceDelete();
            $contact->forceDelete();
            $institution->forceDelete();
            $workerAUser->forceDelete();
            $workerBUser->forceDelete();

            // Restart transaction for tearDown
            DB::beginTransaction();
        }
    }
}
