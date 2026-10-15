<?php

namespace Tests\Unit;

use App\Models\AbsenSyncState;
use App\Models\InternshipRegistration;
use Tests\TestCase;

class AbsenSyncStateModelTest extends TestCase
{
    /**
     * Memverifikasi konstanta status dan method helper status.
     */
    public function test_constants_and_status_checkers(): void
    {
        $this->assertSame('pending', AbsenSyncState::STATUS_PENDING);
        $this->assertSame('syncing', AbsenSyncState::STATUS_SYNCING);
        $this->assertSame('synced', AbsenSyncState::STATUS_SYNCED);
        $this->assertSame('failed', AbsenSyncState::STATUS_FAILED);
        $this->assertSame('dead_letter', AbsenSyncState::STATUS_DEAD_LETTER);

        $state = new AbsenSyncState(['sync_status' => AbsenSyncState::STATUS_PENDING]);
        $this->assertTrue($state->isPending());
        $this->assertFalse($state->isSyncing());
        $this->assertFalse($state->isSynced());
        $this->assertFalse($state->isFailed());
        $this->assertFalse($state->isDeadLetter());

        $state->sync_status = AbsenSyncState::STATUS_SYNCING;
        $this->assertTrue($state->isSyncing());

        $state->sync_status = AbsenSyncState::STATUS_SYNCED;
        $this->assertTrue($state->isSynced());

        $state->sync_status = AbsenSyncState::STATUS_FAILED;
        $this->assertTrue($state->isFailed());

        $state->sync_status = AbsenSyncState::STATUS_DEAD_LETTER;
        $this->assertTrue($state->isDeadLetter());
    }

    /**
     * Memverifikasi mekanisme klaim lease, pembuatan fencing token, dan penelusuran versi in-flight.
     */
    public function test_claim_lease_generates_fencing_token_and_tracks_in_flight(): void
    {
        $state = new AbsenSyncState([
            'registration_id'          => 101,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 2,
            'current_payload_checksum' => 'checksum_v2_abc',
            'attempt_count'            => 0,
            'max_attempts'             => 5,
        ]);

        $this->assertTrue($state->canBeClaimed());

        $workerId = 'worker_pod_7';
        $token = $state->claimLease($workerId, 120);

        $this->assertNotNull($token);
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $state->sync_status);
        $this->assertSame($workerId, $state->lease_owner);
        $this->assertSame($token, $state->fencing_token);
        $this->assertSame(2, $state->in_flight_payload_version);
        $this->assertSame('checksum_v2_abc', $state->in_flight_payload_checksum);
        $this->assertSame(1, $state->attempt_count);
        $this->assertNotNull($state->lease_expires_at);
        $this->assertNotNull($state->last_attempted_at);
    }

    /**
     * Memverifikasi perlindungan fencing token: worker dengan token basi/tidak cocok ditolak.
     */
    public function test_stale_worker_with_invalid_fencing_token_is_rejected(): void
    {
        $state = new AbsenSyncState([
            'registration_id'         => 102,
            'sync_status'             => AbsenSyncState::STATUS_SYNCING,
            'fencing_token'           => 'valid_token_123',
            'current_payload_version' => 1,
        ]);

        // Worker lama dengan token salah mencoba merekam sukses
        $successResult = $state->recordSuccess('stale_token_999', 200);
        $this->assertFalse($successResult);
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $state->sync_status);

        // Worker lama dengan token salah mencoba merekam gagal
        $failResult = $state->recordFailure('stale_token_999', 'Error timeout', 504);
        $this->assertFalse($failResult);
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $state->sync_status);
    }

    /**
     * Memverifikasi penanganan race condition versi payload:
     * Jika registrasi dimodifikasi (current_version > in_flight_version) saat worker sedang berjalan,
     * keberhasilan worker lama tidak menandai state sebagai 'synced', melainkan tetap 'pending'.
     */
    public function test_payload_version_race_condition_resets_to_pending_on_success(): void
    {
        $state = new AbsenSyncState([
            'registration_id'          => 103,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_v1',
            'attempt_count'            => 0,
            'max_attempts'             => 5,
        ]);

        $token = $state->claimLease('worker_1', 60);

        // Simulasi: registrasi diedit saat worker_1 sedang mengirim data -> versi naik ke v2
        $state->bumpPayloadVersion('checksum_v2');
        $this->assertSame(2, $state->current_payload_version);
        $this->assertSame(1, $state->in_flight_payload_version);

        // Worker_1 selesai mengirim v1 dan lapor sukses
        $success = $state->recordSuccess($token, 200);
        $this->assertTrue($success);

        // State TIDAK boleh 'synced' karena v2 belum dikirim! Harus 'pending'
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $state->sync_status);
        $this->assertSame(1, $state->last_synced_payload_version);
        $this->assertSame('checksum_v1', $state->last_synced_checksum);
        $this->assertNull($state->fencing_token);
        $this->assertNull($state->lease_owner);
    }

    /**
     * Memverifikasi keberhasilan normal jika versi tidak berubah menghasilkan status 'synced'.
     */
    public function test_successful_sync_marks_as_synced_when_versions_match(): void
    {
        $state = new AbsenSyncState([
            'registration_id'          => 104,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_v1',
            'attempt_count'            => 0,
            'max_attempts'             => 5,
        ]);

        $token = $state->claimLease('worker_1', 60);
        $success = $state->recordSuccess($token, 200);

        $this->assertTrue($success);
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $state->sync_status);
        $this->assertSame(1, $state->last_synced_payload_version);
        $this->assertSame('checksum_v1', $state->last_synced_checksum);
    }

    /**
     * Memverifikasi transisi ke dead_letter ketika percobaan mencapai max_attempts.
     */
    public function test_transitions_to_dead_letter_when_max_attempts_exceeded(): void
    {
        $state = new AbsenSyncState([
            'registration_id' => 105,
            'sync_status'     => AbsenSyncState::STATUS_SYNCING,
            'fencing_token'   => 'token_retry',
            'attempt_count'   => 5,
            'max_attempts'    => 5,
        ]);

        $this->assertTrue($state->hasExceededMaxAttempts());

        $failed = $state->recordFailure('token_retry', 'Persistent remote error 500', 500);
        $this->assertTrue($failed);
        $this->assertSame(AbsenSyncState::STATUS_DEAD_LETTER, $state->sync_status);
        $this->assertNull($state->next_retry_at);
        $this->assertFalse($state->canBeClaimed());
    }

    /**
     * Memverifikasi definisi relasi Eloquent ke InternshipRegistration.
     */
    public function test_eloquent_relation_definition(): void
    {
        $state = new AbsenSyncState();
        $relation = $state->registration();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $relation);
        $this->assertSame('registration_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(InternshipRegistration::class, $relation->getRelated());
    }
}
