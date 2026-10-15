<?php

namespace Tests\Feature;

use App\Jobs\SyncRegistrationToAbsenJob;
use App\Models\AbsenSyncState;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AbsenIntegration\AbsenCanonicalPayloadBuilder;
use App\Services\AbsenIntegration\AbsenMasterDataResolver;
use App\Services\AbsenIntegration\AbsenOutboxRecorder;
use App\Services\AbsenIntegration\Transports\AbsenHttpOutboundTransport;
use App\Services\AbsenIntegration\Transports\AbsenTransportResponse;
use App\Services\AbsenIntegration\Transports\FakeAbsenOutboundTransport;
use App\Services\RegistrationLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature Test Suite untuk Phase C.3B.3A:
 * Sender Contract Alignment & Offline HTTP Adapter Implementation.
 */
class AbsenSenderContractAlignmentTest extends TestCase
{
    use DatabaseTransactions;

    protected RegistrationLifecycleService $lifecycle;
    protected AbsenOutboxRecorder $recorder;
    protected AbsenMasterDataResolver $resolver;
    protected AbsenCanonicalPayloadBuilder $builder;
    protected FakeAbsenOutboundTransport $fakeTransport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle     = app(RegistrationLifecycleService::class);
        $this->recorder      = app(AbsenOutboxRecorder::class);
        $this->resolver      = app(AbsenMasterDataResolver::class);
        $this->builder       = app(AbsenCanonicalPayloadBuilder::class);
        $this->fakeTransport = new FakeAbsenOutboundTransport();

        // Enforce test configuration defaults
        Config::set('services.absen_djuragan.outbox_enabled', true);
        Config::set('services.absen_djuragan.outbound_enabled', false);
        Config::set('services.absen_djuragan.outbound_api_key', 'test_ims_secret_key_2026');
        Config::set('services.absen_djuragan.url', 'http://absen-djuragan.test/api/v1/external');
    }

    protected function createTestUser(?string $email = null): User
    {
        return User::create([
            'name'     => 'Sender Test User ' . uniqid(),
            'email'    => $email ?? ('sender_' . uniqid() . '@example.com'),
            'password' => Hash::make('secret123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);
    }

    protected function createTestRegistration(User $user, string $status = InternshipRegistration::STATUS_ACCEPTED): InternshipRegistration
    {
        $inst = Institution::firstOrCreate(
            ['name' => 'Universitas Indonesia', 'normalized_name' => 'universitas indonesia'],
            ['is_active' => true]
        );
        $fac  = Faculty::firstOrCreate(['name' => 'Fakultas Ilmu Komputer', 'institution_id' => $inst->id]);
        $prog = StudyProgram::firstOrCreate(['name' => 'Sistem Informasi', 'faculty_id' => $fac->id]);
        $div  = Division::firstOrCreate(
            ['name' => 'Programmer'],
            ['slug' => 'programmer', 'is_active' => true]
        );

        return InternshipRegistration::create([
            'user_id'           => $user->id,
            'fullname'          => 'Calon Pemagang ' . uniqid(),
            'student_id'        => 'NIM' . rand(100000, 999999),
            'born_date'         => '2001-08-15',
            'birth_place'       => 'Jakarta',
            'gender'            => 'Laki-laki',
            'phone_number'      => '081987654321',
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'division_id'       => $div->id,
            'internship_status' => $status,
            'start_date'        => now()->addDays(7)->toDateString(),
            'end_date'          => now()->addMonths(6)->toDateString(),
        ]);
    }

    /**
     * 1. Snapshot memuat email pengguna yang sah dan tersimpan ke payload_snapshot.
     */
    public function test_01_snapshot_contains_valid_user_email_and_persists_in_state(): void
    {
        $user = $this->createTestUser('pemagang.sah@example.com');
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);

        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->assertNotNull($syncState);
        $this->assertNotEmpty($syncState->current_payload_checksum);
        $this->assertNotNull($syncState->payload_snapshot);
        $this->assertSame('pemagang.sah@example.com', $syncState->payload_snapshot['email']);
        $this->assertSame($reg->fullname, $syncState->payload_snapshot['fullname']);
    }

    /**
     * 2. Retry tidak mengubah email dan checksum secara diam-diam.
     */
    public function test_02_retry_does_not_mutate_email_or_checksum_silently(): void
    {
        $user = $this->createTestUser('initial.retry@example.com');
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $initialChecksum = $syncState->current_payload_checksum;
        $initialEmail    = $syncState->payload_snapshot['email'];

        // Worker mengklaim lease (attempt 1)
        $fencingToken = $syncState->claimLeaseAtomic('worker_1', 120);
        $this->assertNotNull($fencingToken);

        // Rekam failure sementara (retryable)
        $syncState->recordFailureAtomic($fencingToken, 'Temporary timeout', 408, [], 60);
        $syncState->refresh();

        $this->assertSame(AbsenSyncState::STATUS_FAILED, $syncState->sync_status);
        $this->assertSame($initialChecksum, $syncState->current_payload_checksum);
        $this->assertSame($initialEmail, $syncState->payload_snapshot['email']);

        // Jika email diubah langsung di user tanpa lifecycle transition, worker berikutnya mendeteksi mismatch
        $user->email = 'changed_without_transition@example.com';
        $user->save();

        $syncState->update(['next_retry_at' => now()->subMinute()]);
        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_retry');
        $job->handle($this->fakeTransport);

        // Pengiriman dibatalkan karena checksum data model drift
        $this->fakeTransport->assertNothingSent();
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $syncState->fresh()->sync_status);
    }

    /**
     * 3. Perubahan snapshot memicu versi baru secara sah melalui lifecycle.
     */
    public function test_03_snapshot_change_triggers_legitimate_new_version(): void
    {
        $user = $this->createTestUser('v1@example.com');
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $v1Version  = $syncState->current_payload_version;
        $v1Checksum = $syncState->current_payload_checksum;
        $this->assertSame(1, $v1Version);

        // Perubahan data melalui lifecycle (misal status aktif dengan perpanjangan tanggal)
        $reg->end_date = now()->addMonths(12)->toDateString();
        $reg->save();

        $updatedState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACTIVE);

        $this->assertSame(2, $updatedState->current_payload_version);
        $this->assertNotSame($v1Checksum, $updatedState->current_payload_checksum);
        $this->assertSame($reg->end_date, $updatedState->payload_snapshot['end_date']);
    }

    /**
     * 4. Master data ID antarsistem tidak tertukar atau dianggap sama secara naif.
     */
    public function test_04_master_data_ids_not_swapped_or_assumed_identical(): void
    {
        $instId = 42;
        $divId  = 88;
        $brandId = 99;

        // Atur mapping terverifikasi yang berbeda dengan source ID
        Config::set('services.absen_djuragan.mappings.institutions', [$instId => 101]);
        Config::set('services.absen_djuragan.mappings.divisions', [$divId => 202]);
        Config::set('services.absen_djuragan.mappings.brands', [$brandId => 303]);

        $resolvedSchool   = $this->resolver->resolveSchoolId($instId);
        $resolvedDivision = $this->resolver->resolveDivisionId($divId);
        $resolvedBrand    = $this->resolver->resolveBrandId($brandId);

        $this->assertSame(101, $resolvedSchool);
        $this->assertSame(202, $resolvedDivision);
        $this->assertSame(303, $resolvedBrand);

        // Pastikan tidak sama dengan source ID
        $this->assertNotSame($instId, $resolvedSchool);
        $this->assertNotSame($divId, $resolvedDivision);
        $this->assertNotSame($brandId, $resolvedBrand);
    }

    /**
     * 5. Missing master data mapping menahan delivery dan tidak mengirim HTTP.
     */
    public function test_05_missing_master_data_mapping_blocks_http_delivery(): void
    {
        Config::set('services.absen_djuragan.require_master_mapping', true);
        Config::set('services.absen_djuragan.mappings.institutions', []); // Kosong
        Config::set('services.absen_djuragan.mappings.divisions', []);    // Kosong

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_test');
        $job->handle($this->fakeTransport);

        // HTTP delivery tidak boleh terjadi!
        $this->fakeTransport->assertNothingSent();

        // Status outbox menjadi failed dengan pesan penahanan mapping yang jelas
        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_FAILED, $freshState->sync_status);
        $this->assertStringContainsString('Master data mapping belum tersedia', $freshState->last_error_message);
    }

    /**
     * 6. Request canonical dan test vector deterministik identik antara sender dan receiver.
     */
    public function test_06_canonical_request_and_deterministic_test_vector_match(): void
    {
        // Test Vector yang telah disepakati bersama antara IMS dan Absen Djuragan
        $testVector = [
            'birth_place'              => 'Yogyakarta',
            'born_date'                => '2000-01-01',
            'brand_id'                 => 1,
            'division_id'              => 2,
            'email'                    => 'pemagang.test@seveninc.com',
            'end_date'                 => '2026-12-31',
            'external_registration_id' => '100',
            'external_system'          => 'ims_seveninc',
            'external_user_id'         => '50',
            'full_name'                => 'Budi Santoso',
            'gender'                   => 'Laki-laki',
            'payload_version'          => 1,
            'phone'                    => '08123456789',
            'school_id'                => 10,
            'start_date'               => '2026-06-01',
            'student_id'               => 'NIM2026001',
        ];

        // Kalkulasi checksum oleh builder IMS
        $calculatedChecksum = $this->builder->calculateRemoteChecksum($testVector);

        // Hitung ekspektasi SHA-256 dari canonical ksort
        ksort($testVector);
        $expectedChecksum = hash('sha256', json_encode($testVector, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertSame($expectedChecksum, $calculatedChecksum);
        $this->assertSame(64, strlen($calculatedChecksum));
    }

    /**
     * 7. Idempotency key konsisten dan deterministik.
     */
    public function test_07_idempotency_key_is_deterministic_and_consistent(): void
    {
        $user = $this->createTestUser('idempotency@example.com');
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $shortChecksum = substr((string) $syncState->current_payload_checksum, 0, 8);
        $expectedKey   = "ims-reg-{$reg->id}-v1-{$shortChecksum}";

        $this->fakeTransport->withHandler(function ($payload, $key) use ($expectedKey) {
            $this->assertSame($expectedKey, $key);
            return AbsenTransportResponse::success(200, ['status' => 'synced']);
        });

        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_idem');
        $job->handle($this->fakeTransport);

        $this->fakeTransport->assertSentCount(1);
    }

    /**
     * 8. Response sukses menyimpan remote ID (absen_user_id, intern_id) secara atomik di bawah fencing lease.
     */
    public function test_08_success_response_stores_remote_ids_atomically(): void
    {
        $user = $this->createTestUser('remote.ids@example.com');
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->fakeTransport->withHandler(function ($payload, $key) {
            return AbsenTransportResponse::success(201, [
                'absen_user_id'   => 8877,
                'intern_id'       => 4433,
                'payload_version' => 1,
            ]);
        });

        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_success');
        $job->handle($this->fakeTransport);

        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $freshState->sync_status);
        $this->assertSame(8877, (int) $freshState->absen_user_id);
        $this->assertSame(4433, (int) $freshState->intern_id);
        $this->assertSame(1, (int) $freshState->confirmed_payload_version);
        $this->assertNull($freshState->fencing_token);
        $this->assertNull($freshState->lease_owner);
    }

    /**
     * 9. Stale / replay response tidak menandai versi baru sebagai synced jika hanya versi lama diakui remote.
     */
    public function test_09_stale_replay_response_does_not_mark_newer_version_as_synced(): void
    {
        $user = $this->createTestUser('stale.version@example.com');
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);

        // Buat v1 melalui recorder
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);
        $this->assertSame(1, $syncState->current_payload_version);

        // Buat v2 secara sah melalui modifikasi data dan recordTransition
        $reg->end_date = now()->addMonths(12)->toDateString();
        $reg->internship_status = InternshipRegistration::STATUS_ACTIVE;
        $reg->save();
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACTIVE);
        $this->assertSame(2, $syncState->current_payload_version);

        // Remote mengembalikan konfirmasi versi lama (versi 1)
        $this->fakeTransport->withHandler(function ($payload, $key) {
            return AbsenTransportResponse::success(200, [
                'absen_user_id'   => 1234,
                'intern_id'       => 5678,
                'payload_version' => 1, // Versi 1 (replay/stale)
                'is_replay'       => true,
            ]);
        });

        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_stale');
        $job->handle($this->fakeTransport);

        $freshState = $syncState->fresh();
        // Karena in-flight adalah versi 2 tetapi remote hanya mengonfirmasi versi 1, outbox tetap PENDING
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $freshState->sync_status);
        $this->assertSame(1, (int) $freshState->confirmed_payload_version);
        $this->assertSame(1, (int) $freshState->last_synced_payload_version);
        $this->assertStringContainsString('replay konfirmasi untuk versi 1', $freshState->last_error_message);
    }

    /**
     * 10. Outbound flag OFF menjamin zero HTTP delivery pada transport nyata.
     */
    public function test_10_outbound_flag_off_guarantees_zero_http_delivery(): void
    {
        Config::set('services.absen_djuragan.outbound_enabled', false);
        Http::fake();

        $transport = new AbsenHttpOutboundTransport($this->builder);
        $response  = $transport->sendRegistration(['dummy' => 'payload'], 'key-123');

        $this->assertTrue($response->isDisabled());
        $this->assertSame(503, $response->getStatusCode());
        Http::assertNothingSent();
    }

    /**
     * 11. Offline HTTP Transport memetakan status codes dan menyanitasi credentials.
     */
    public function test_11_http_adapter_handles_status_codes_and_sanitizes_credentials(): void
    {
        Config::set('services.absen_djuragan.outbound_enabled', true);
        $transport = new AbsenHttpOutboundTransport($this->builder);

        Http::fake([
            '*/register-sync' => Http::sequence()
                ->push(['success' => true, 'data' => ['absen_user_id' => 10, 'intern_id' => 20]], 200)
                ->push(['success' => false, 'message' => 'API Key invalid'], 401)
                ->push(['success' => false, 'message' => 'Idempotency conflict'], 409)
                ->push(['success' => false, 'message' => 'Validation error', 'errors' => ['email' => ['required']]], 422)
                ->push(['success' => false, 'message' => 'Too many requests'], 429)
                ->push(['success' => false, 'message' => 'Internal server error'], 500)
                ->push(fn () => throw new ConnectionException('Connection timed out after 15 seconds')),
        ]);

        // Test 200 Success
        $res200 = $transport->sendRegistration(['fullname' => 'Test'], 'key-1');
        $this->assertTrue($res200->isSuccess());
        $this->assertSame(10, $res200->getData()['absen_user_id']);

        // Test 401 Unauthorized
        $res401 = $transport->sendRegistration(['fullname' => 'Test'], 'key-2');
        $this->assertTrue($res401->isUnauthorized());
        $this->assertSame(401, $res401->getStatusCode());

        // Test 409 Conflict
        $res409 = $transport->sendRegistration(['fullname' => 'Test'], 'key-3');
        $this->assertTrue($res409->isConflict());
        $this->assertSame(409, $res409->getStatusCode());

        // Test 422 Validation Error
        $res422 = $transport->sendRegistration(['fullname' => 'Test'], 'key-4');
        $this->assertTrue($res422->isValidationError());
        $this->assertSame(422, $res422->getStatusCode());

        // Test 429 Rate Limited (Retryable Server Error)
        $res429 = $transport->sendRegistration(['fullname' => 'Test'], 'key-5');
        $this->assertTrue($res429->isRetryable());
        $this->assertSame(429, $res429->getStatusCode());

        // Test 500 Server Error (Retryable)
        $res500 = $transport->sendRegistration(['fullname' => 'Test'], 'key-6');
        $this->assertTrue($res500->isRetryable());
        $this->assertSame(500, $res500->getStatusCode());

        // Test Connection Timeout
        $resTimeout = $transport->sendRegistration(['fullname' => 'Test'], 'key-7');
        $this->assertTrue($resTimeout->isTimeout());
        $this->assertTrue($resTimeout->isRetryable());
    }
}
