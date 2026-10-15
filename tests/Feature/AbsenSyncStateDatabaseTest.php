<?php

namespace Tests\Feature;

use App\Models\AbsenSyncState;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature Test untuk Phase C.2B:
 * Test Database Migration & Atomic Outbox State Verification.
 */
class AbsenSyncStateDatabaseTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    protected function createTestUser(): User
    {
        return User::create([
            'name'     => 'User ' . uniqid('sync_', true),
            'email'    => 'sync_' . uniqid() . '@example.com',
            'password' => Hash::make('secret123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);
    }

    protected function createTestRegistration(User $user): InternshipRegistration
    {
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);
        $inst = Institution::firstOrCreate(
            ['name' => 'Universitas Gadjah Mada', 'normalized_name' => 'universitas gadjah mada'],
            ['is_active' => true]
        );
        $fac  = Faculty::firstOrCreate(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = StudyProgram::firstOrCreate(['name' => 'Teknologi Informasi', 'faculty_id' => $fac->id]);
        $div  = Division::firstOrCreate(
            ['name' => 'Administration'],
            ['slug' => 'administration', 'is_active' => true]
        );

        return InternshipRegistration::create([
            'user_id'                 => $user->id,
            'fullname'                => 'Peserta Outbox ' . uniqid(),
            'student_id'              => '98765432',
            'born_date'               => '2001-08-15',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'institution_id'          => $inst->id,
            'faculty_id'              => $fac->id,
            'study_program_id'        => $prog->id,
            'division_id'             => $div->id,
            'internship_status'       => InternshipRegistration::STATUS_ACCEPTED,
            'start_date'              => now()->addDays(7)->toDateString(),
            'end_date'                => now()->addMonths(3)->toDateString(),
        ]);
    }

    /**
     * 01: Verifikasi fisik tabel dan kolom absen_sync_states pada database testing.
     */
    public function test_01_physical_table_and_columns_exist_in_testing_database(): void
    {
        $this->assertTrue(Schema::hasTable('absen_sync_states'));

        $expectedColumns = [
            'id',
            'registration_id',
            'sync_status',
            'current_payload_version',
            'current_payload_checksum',
            'in_flight_payload_version',
            'in_flight_payload_checksum',
            'lease_owner',
            'fencing_token',
            'lease_expires_at',
            'last_synced_payload_version',
            'last_synced_checksum',
            'last_synced_at',
            'attempt_count',
            'max_attempts',
            'last_attempted_at',
            'next_retry_at',
            'last_response_status',
            'last_error_message',
            'last_error_context',
            'created_at',
            'updated_at',
        ];

        $this->assertTrue(Schema::hasColumns('absen_sync_states', $expectedColumns));
    }

    /**
     * 02: Constraint unique registration_id menolak dua state pada satu registrasi.
     */
    public function test_02_unique_registration_id_constraint(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_abc_1',
        ]);

        $this->expectException(QueryException::class);

        AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_abc_2',
        ]);
    }

    /**
     * 03: Foreign key ON DELETE RESTRICT menolak penghapusan parent jika state masih ada.
     */
    public function test_03_foreign_key_on_delete_restrict(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_initial',
        ]);

        $this->expectException(QueryException::class);

        // Menghapus registrasi secara fisik wajib ditolak oleh MySQL constraint fk_absen_sync_reg_id
        $reg->forceDelete();
    }

    /**
     * 04: Initial pending state terbuat dengan nilai default yang konsisten.
     */
    public function test_04_initial_pending_state_creation(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_v1_hash',
        ]);

        $this->assertSame(AbsenSyncState::STATUS_PENDING, $state->sync_status);
        $this->assertSame(1, $state->current_payload_version);
        $this->assertSame(0, $state->attempt_count);
        $this->assertSame(5, $state->max_attempts);
        $this->assertNull($state->next_retry_at);
        $this->assertNull($state->fencing_token);
        $this->assertNull($state->lease_owner);
    }

    /**
     * 05: Record pending baru dengan next_retry_at NULL tetap eligible untuk diproses.
     */
    public function test_05_pending_with_null_next_retry_at_is_eligible(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_p1',
            'next_retry_at'            => null,
        ]);

        $this->assertTrue($state->canBeClaimed());

        $eligible = AbsenSyncState::eligibleForClaim()->where('id', $state->id)->first();
        $this->assertNotNull($eligible);
        $this->assertSame($state->id, $eligible->id);
    }

    /**
     * 06: Status failed yang next_retry_at-nya di masa depan TIDAK eligible.
     */
    public function test_06_retry_before_scheduled_time_is_not_eligible(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_FAILED,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_retry_later',
            'attempt_count'            => 1,
            'next_retry_at'            => now()->addMinutes(15),
        ]);

        $this->assertFalse($state->canBeClaimed());

        $eligible = AbsenSyncState::eligibleForClaim()->where('id', $state->id)->first();
        $this->assertNull($eligible);
    }

    /**
     * 07: Status failed yang jadwal next_retry_at-nya telah tiba menjadi eligible.
     */
    public function test_07_retry_after_scheduled_time_is_eligible(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_FAILED,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_ready_retry',
            'attempt_count'            => 2,
            'next_retry_at'            => now()->subMinute(),
        ]);

        $this->assertTrue($state->canBeClaimed());

        $eligible = AbsenSyncState::eligibleForClaim()->where('id', $state->id)->first();
        $this->assertNotNull($eligible);
    }

    /**
     * 08: Record dead_letter TIDAK eligible secara otomatis.
     */
    public function test_08_dead_letter_not_eligible_automatically(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_DEAD_LETTER,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_dead',
            'attempt_count'            => 5,
            'max_attempts'             => 5,
            'next_retry_at'            => null,
        ]);

        $this->assertFalse($state->canBeClaimed());

        $eligible = AbsenSyncState::eligibleForClaim()->where('id', $state->id)->first();
        $this->assertNull($eligible);
    }

    /**
     * 09: Atomic claim pada database: Claimant pertama menang, claimant kedua ditolak.
     */
    public function test_09_atomic_lease_claim_by_two_claimants(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_atomic_claim',
        ]);

        // Claimant 1 melakukan atomic claim
        $token1 = $state->claimLeaseAtomic('worker_node_A', 60);
        $this->assertNotNull($token1);
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $state->sync_status);
        $this->assertSame('worker_node_A', $state->lease_owner);

        // Claimant 2 mencoba klaim pada baris yang sama persis
        $token2 = $state->claimLeaseAtomic('worker_node_B', 60);
        $this->assertNull($token2, 'Claimant kedua wajib ditolak karena lease masih aktif!');

        // State tetap dimiliki oleh worker A
        $state->refresh();
        $this->assertSame($token1, $state->fencing_token);
        $this->assertSame('worker_node_A', $state->lease_owner);
    }

    /**
     * 10: Rejection terhadap duplikasi klaim aktif.
     */
    public function test_10_rejection_of_duplicate_active_lease(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_SYNCING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_dup_lease',
            'fencing_token'            => 'token_active_xyz',
            'lease_owner'              => 'worker_active',
            'lease_expires_at'         => now()->addMinutes(5),
            'attempt_count'            => 1,
        ]);

        $this->assertFalse($state->canBeClaimed());
        $token = $state->claimLeaseAtomic('new_worker', 60);
        $this->assertNull($token);
    }

    /**
     * 11: Pemulihan lease kedaluwarsa (crash recovery worker).
     */
    public function test_11_expired_lease_recovery(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        // Simulasikan worker sebelumnya crash dan lease-nya telah kedaluwarsa 10 menit lalu
        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_SYNCING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_expired_lease',
            'fencing_token'            => 'crashed_worker_token',
            'lease_owner'              => 'crashed_worker',
            'lease_expires_at'         => now()->subMinutes(10),
            'attempt_count'            => 1,
            'max_attempts'             => 5,
        ]);

        $this->assertTrue($state->isLeaseExpired());
        $this->assertTrue($state->canBeClaimed());

        // Worker baru mengambil alih
        $newToken = $state->claimLeaseAtomic('recovery_worker', 90);
        $this->assertNotNull($newToken);
        $this->assertNotSame('crashed_worker_token', $newToken);
        $this->assertSame('recovery_worker', $state->lease_owner);
        $this->assertSame(2, $state->attempt_count);
    }

    /**
     * 12: Penolakan penyelesaian oleh worker dengan fencing token basi.
     */
    public function test_12_stale_fencing_token_rejected_on_completion(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_stale_check',
        ]);

        $validToken = $state->claimLeaseAtomic('worker_primary', 60);

        // Percobaan recordSuccess menggunakan token palsu/basi
        $success = $state->recordSuccessAtomic('stale_token_fake', 200);
        $this->assertFalse($success);

        $state->refresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $state->sync_status);
        $this->assertSame($validToken, $state->fencing_token);
    }

    /**
     * 13: Penolakan perekaman kegagalan oleh worker dengan fencing token basi.
     */
    public function test_13_stale_fencing_token_rejected_on_failure(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_failure_check',
        ]);

        $validToken = $state->claimLeaseAtomic('worker_primary', 60);

        $failed = $state->recordFailureAtomic('stale_token_fake', 'Remote timeout', 504);
        $this->assertFalse($failed);

        $state->refresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $state->sync_status);
        $this->assertNull($state->last_error_message);
    }

    /**
     * 14: Version Race Protection: Modifikasi data saat worker berjalan menjaga status pending.
     */
    public function test_14_payload_version_change_during_flight_resets_to_pending(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_v1_initial',
        ]);

        $token = $state->claimLeaseAtomic('worker_v1', 60);
        $this->assertSame(1, $state->in_flight_payload_version);

        // Simulasi update data registrasi oleh admin: versi naik ke v2
        $bumped = $state->bumpPayloadVersion('checksum_v2_updated');
        $this->assertTrue($bumped);
        $this->assertSame(2, $state->current_payload_version);

        // Worker v1 selesai dan melaporkan sukses ke database
        $success = $state->recordSuccessAtomic($token, 200);
        $this->assertTrue($success);

        // Verifikasi ke database: status TIDAK synced, melainkan pending karena ada v2
        $state->refresh();
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $state->sync_status);
        $this->assertSame(1, $state->last_synced_payload_version);
        $this->assertSame('checksum_v1_initial', $state->last_synced_checksum);
        $this->assertSame(2, $state->current_payload_version);
        $this->assertNull($state->fencing_token);
        $this->assertNull($state->lease_owner);
    }

    /**
     * 15: Attempt counter bertambah pada setiap klaim dan beralih ke dead_letter saat batas tercapai.
     */
    public function test_15_attempt_counter_and_max_attempts(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'hash_retry_limit',
            'attempt_count'            => 4,
            'max_attempts'             => 5,
        ]);

        // Klaim percobaan ke-5
        $token = $state->claimLeaseAtomic('worker_retry_final', 60);
        $this->assertNotNull($token);
        $this->assertSame(5, $state->attempt_count);

        // Gagal pada percobaan ke-5 -> beralih ke dead_letter
        $failed = $state->recordFailureAtomic($token, 'Server error 500', 500);
        $this->assertTrue($failed);

        $state->refresh();
        $this->assertSame(AbsenSyncState::STATUS_DEAD_LETTER, $state->sync_status);
        $this->assertNull($state->next_retry_at);
        $this->assertFalse($state->canBeClaimed());
    }

    /**
     * 16: Persistensi sukses kondisional menyimpan audit status dengan benar.
     */
    public function test_16_conditional_success_persistence(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_clean',
        ]);

        $token = $state->claimLeaseAtomic('worker_clean', 60);
        $success = $state->recordSuccessAtomic($token, 201);
        $this->assertTrue($success);

        $state->refresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $state->sync_status);
        $this->assertSame(1, $state->last_synced_payload_version);
        $this->assertSame('checksum_clean', $state->last_synced_checksum);
        $this->assertSame(201, $state->last_response_status);
        $this->assertNotNull($state->last_synced_at);
        $this->assertNull($state->fencing_token);
        $this->assertNull($state->lease_owner);
    }

    /**
     * 17: Persistensi kegagalan kondisional mencatat pesan tersanitasi dan menjadwalkan retry.
     */
    public function test_17_conditional_failure_persistence(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_fail_test',
            'attempt_count'            => 1,
            'max_attempts'             => 5,
        ]);

        $token = $state->claimLeaseAtomic('worker_fail', 60);

        $context = ['http_code' => 503, 'endpoint' => '/api/v1/external/register-sync'];
        $failed = $state->recordFailureAtomic($token, '<b>Service Unavailable</b>', 503, $context, 120);
        $this->assertTrue($failed);

        $state->refresh();
        $this->assertSame(AbsenSyncState::STATUS_FAILED, $state->sync_status);
        $this->assertSame(503, $state->last_response_status);
        $this->assertSame('Service Unavailable', $state->last_error_message, 'Pesan error wajib dibersihkan dari HTML tags!');
        $this->assertEquals($context, $state->last_error_context);
        $this->assertNotNull($state->next_retry_at);
        $this->assertNull($state->fencing_token);
    }

    /**
     * 18: Zero outbound HTTP calls selama manajemen outbox state.
     */
    public function test_18_zero_outbound_http_requests(): void
    {
        Http::fake();

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);

        $state = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_zero_http',
        ]);

        $token = $state->claimLeaseAtomic('worker_test', 60);
        $state->recordSuccessAtomic($token, 200);

        Http::assertNothingSent();
    }
}
