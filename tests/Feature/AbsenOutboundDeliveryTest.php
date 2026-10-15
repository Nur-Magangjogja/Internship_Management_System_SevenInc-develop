<?php

namespace Tests\Feature;

use App\Jobs\SyncRegistrationToAbsenJob;
use App\Models\AbsenSyncState;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AbsenIntegration\AbsenOutboxRecorder;
use App\Services\AbsenIntegration\Transports\AbsenOutboundTransportInterface;
use App\Services\AbsenIntegration\Transports\AbsenTransportResponse;
use App\Services\AbsenIntegration\Transports\DisabledAbsenOutboundTransport;
use App\Services\AbsenIntegration\Transports\FakeAbsenOutboundTransport;
use App\Services\RegistrationLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature Test Suite untuk Phase C.3B.1:
 * Offline Delivery Engine, Transport Mock, & Safety Hardening.
 */
class AbsenOutboundDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected RegistrationLifecycleService $lifecycle;
    protected AbsenOutboxRecorder $recorder;
    protected FakeAbsenOutboundTransport $fakeTransport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle     = app(RegistrationLifecycleService::class);
        $this->recorder      = app(AbsenOutboxRecorder::class);
        $this->fakeTransport = new FakeAbsenOutboundTransport();

        // Default: outbound disabled secara ketat
        Config::set('services.absen_djuragan.outbox_enabled', true);
        Config::set('services.absen_djuragan.outbound_enabled', false);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }

    protected function createTestUser(): User
    {
        return User::create([
            'name'     => 'Delivery User ' . uniqid(),
            'email'    => 'delivery_' . uniqid() . '@example.com',
            'password' => Hash::make('secret123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);
    }

    protected function createTestRegistration(User $user, string $status = InternshipRegistration::STATUS_ACCEPTED): InternshipRegistration
    {
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
            'user_id'           => $user->id,
            'fullname'          => 'Peserta Outbound ' . uniqid(),
            'student_id'        => '12345678',
            'born_date'         => '2002-05-10',
            'birth_place'       => 'Sleman',
            'gender'            => 'Laki-laki',
            'phone_number'      => '081234567890',
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'division_id'       => $div->id,
            'internship_status' => $status,
            'start_date'        => now()->addDays(5)->toDateString(),
            'end_date'          => now()->addMonths(3)->toDateString(),
        ]);
    }

    /**
     * 01: Outbound feature flag default bernilai false dan job default fail-closed.
     */
    public function test_01_outbound_default_off_fails_closed(): void
    {
        Config::set('services.absen_djuragan.outbound_enabled', false);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Eksekusi job tanpa passing fake transport (menggunakan transport default container)
        $job = new SyncRegistrationToAbsenJob($reg->id);
        $result = $job->handle();

        // Fail-closed: job mengembalikan null dan outbox row tetap pending tanpa mutasi
        $this->assertNull($result);
        $outbox = AbsenSyncState::where('registration_id', $reg->id)->first();
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $outbox->sync_status);
        $this->assertSame(0, $outbox->attempt_count);
    }

    /**
     * 02: Real HTTP requests dicegah secara mutlak oleh DisabledAbsenOutboundTransport.
     */
    public function test_02_disabled_transport_prevents_real_http_calls(): void
    {
        Http::fake();

        $disabledTransport = new DisabledAbsenOutboundTransport();
        $response = $disabledTransport->sendRegistration(['test' => 1], 'idempotency-key-test');

        $this->assertTrue($response->isDisabled());
        $this->assertSame(503, $response->getStatusCode());
        Http::assertNothingSent();
    }

    /**
     * 03: Fake transport success (200/201) menyelesaikan siklus pengiriman secara sukses atomik.
     */
    public function test_03_fake_transport_success_marks_outbox_synced(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->fakeTransport->willRespondWith(AbsenTransportResponse::success(200, [
            'absen_user_id' => 101,
            'intern_id'     => 202,
        ]));

        $job = new SyncRegistrationToAbsenJob($reg->id);
        $resultState = $job->handle($this->fakeTransport);

        $this->assertNotNull($resultState);
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $resultState->sync_status);
        $this->assertSame(1, $resultState->last_synced_payload_version);
        $this->assertSame($resultState->current_payload_checksum, $resultState->last_synced_checksum);
        $this->assertNotNull($resultState->last_synced_at);
        $this->assertSame(200, $resultState->last_response_status);
        $this->assertNull($resultState->fencing_token);
        $this->fakeTransport->assertSentCount(1);
    }

    /**
     * 04: Fake transport timeout mencatat failure dengan exponential backoff dan mempertahankan attempt.
     */
    public function test_04_fake_transport_timeout_records_retryable_failure(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->fakeTransport->willRespondWith(AbsenTransportResponse::timeout('cURL error 28: Operation timed out'));

        $job = new SyncRegistrationToAbsenJob($reg->id);
        $resultState = $job->handle($this->fakeTransport);

        $this->assertNotNull($resultState);
        $this->assertSame(AbsenSyncState::STATUS_FAILED, $resultState->sync_status);
        $this->assertSame(1, $resultState->attempt_count);
        $this->assertSame(408, $resultState->last_response_status);
        $this->assertNotNull($resultState->next_retry_at);
        $this->assertNull($resultState->fencing_token);
    }

    /**
     * 05: Fake transport validation failure (422) bertransisi ke dead_letter (non-retryable).
     */
    public function test_05_fake_transport_validation_failure_moves_to_dead_letter(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->fakeTransport->willRespondWith(AbsenTransportResponse::validationError(422, 'Field student_id invalid', ['student_id' => ['Format salah']]));

        $job = new SyncRegistrationToAbsenJob($reg->id);
        $resultState = $job->handle($this->fakeTransport);

        $this->assertNotNull($resultState);
        $this->assertSame(AbsenSyncState::STATUS_DEAD_LETTER, $resultState->sync_status);
        $this->assertSame(422, $resultState->last_response_status);
        $this->assertNull($resultState->next_retry_at);
    }

    /**
     * 06: Simulated conflict handling (409) mencatat kegagalan dengan konteks remote.
     */
    public function test_06_simulated_conflict_handling(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->fakeTransport->willRespondWith(AbsenTransportResponse::conflict(409, 'Conflict: Email already registered in remote system', ['email' => $user->email]));

        $job = new SyncRegistrationToAbsenJob($reg->id);
        $resultState = $job->handle($this->fakeTransport);

        $this->assertNotNull($resultState);
        $this->assertSame(AbsenSyncState::STATUS_FAILED, $resultState->sync_status);
        $this->assertSame(409, $resultState->last_response_status);
        $this->assertNotNull($resultState->next_retry_at);
    }

    /**
     * 07: Idempotency key stabil dan deterministik pada percobaan ulang (retry) untuk versi payload yang sama.
     */
    public function test_07_idempotency_key_stable_on_retry(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Attempt 1: Timeout
        $this->fakeTransport->willRespondWith(AbsenTransportResponse::timeout('Connection timeout'));
        (new SyncRegistrationToAbsenJob($reg->id))->handle($this->fakeTransport);

        $firstRequest = $this->fakeTransport->getLastSentRequest();
        $firstKey     = $firstRequest['idempotency_key'];

        // Reset next_retry_at agar eligible di-retry seketika
        AbsenSyncState::where('registration_id', $reg->id)->update(['next_retry_at' => now()->subMinute()]);

        // Attempt 2: Success
        $this->fakeTransport->willRespondWith(AbsenTransportResponse::success(200));
        (new SyncRegistrationToAbsenJob($reg->id))->handle($this->fakeTransport);

        $secondRequest = $this->fakeTransport->getLastSentRequest();
        $secondKey    = $secondRequest['idempotency_key'];

        $this->assertSame($firstKey, $secondKey, 'Idempotency key wajib stabil dan identik pada retry versi payload yang sama.');
        $this->assertStringContainsString("ims-reg-{$reg->id}-v1-", $firstKey);
    }

    /**
     * 08: Payload version baru menghasilkan identitas delivery dan idempotency key yang berbeda.
     */
    public function test_08_new_payload_version_yields_new_delivery_identity(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Attempt 1 pada v1
        $this->fakeTransport->willRespondWith(AbsenTransportResponse::success(200));
        (new SyncRegistrationToAbsenJob($reg->id))->handle($this->fakeTransport);
        $reqV1 = $this->fakeTransport->getLastSentRequest();

        // Mutasi status ke active -> versi naik ke v2
        $this->lifecycle->transition($reg->fresh(), InternshipRegistration::STATUS_ACTIVE, isAdmin: true);

        // Attempt 2 pada v2
        (new SyncRegistrationToAbsenJob($reg->id))->handle($this->fakeTransport);
        $reqV2 = $this->fakeTransport->getLastSentRequest();

        $this->assertNotSame($reqV1['idempotency_key'], $reqV2['idempotency_key']);
        $this->assertStringContainsString('-v1-', $reqV1['idempotency_key']);
        $this->assertStringContainsString('-v2-', $reqV2['idempotency_key']);
    }

    /**
     * 09: Pending outbox yang registrasinya berubah menjadi rejected dibatalkan sebelum pengiriman (pre-flight cancellation).
     */
    public function test_09_pending_outbox_cancelled_when_registration_rejected(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Admin membatalkan pendaftaran (accepted -> rejected)
        $this->lifecycle->transition($reg->fresh(), InternshipRegistration::STATUS_REJECTED, isAdmin: true, reason: 'Dokumen kelayakan dibatalkan.');

        $outbox = AbsenSyncState::where('registration_id', $reg->id)->first();
        $this->assertSame(AbsenSyncState::STATUS_CANCELLED, $outbox->sync_status);

        // Jalankan worker job: job harus mendeteksi status non-eligible dan TIDAK mengirim HTTP
        $jobResult = (new SyncRegistrationToAbsenJob($reg->id))->handle($this->fakeTransport);

        $this->assertNull($jobResult);
        $this->fakeTransport->assertNothingSent();
    }

    /**
     * 10: Registrasi yang berubah data saat pengiriman in-flight tidak dianggap synced untuk versi terbaru.
     */
    public function test_10_in_flight_mutation_prevents_stale_success_marking(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Worker klaim lease v1
        $fencingToken = $syncState->claimLeaseAtomic('worker_test', 120);

        // Simulasikan mutasi data lokal di UI saat request sedang terbang (versi naik ke v2)
        $syncState->bumpPayloadVersion('new_checksum_during_flight');

        // Worker menyelesaikan request HTTP untuk v1
        $syncState->recordSuccessAtomic($fencingToken, 200);

        $freshState = $syncState->fresh();
        // Karena versi lokal (v2) > versi in-flight (v1), status otomatis di-reset ke pending!
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $freshState->sync_status);
        $this->assertSame(1, $freshState->last_synced_payload_version);
        $this->assertSame(2, $freshState->current_payload_version);
    }

    /**
     * 11: Already-synced registration yang keluar (exited) menandai kebutuhan rekonsiliasi remote.
     */
    public function test_11_already_synced_registration_exited_marks_remote_action_required(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Sukseskan pengiriman pertama
        (new SyncRegistrationToAbsenJob($reg->id))->handle($this->fakeTransport);
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, AbsenSyncState::where('registration_id', $reg->id)->first()->sync_status);

        // Peserta magang keluar (accepted -> exited)
        $this->lifecycle->transition($reg->fresh(), InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Mengundurkan diri atas permintaan sendiri.');

        $outbox = AbsenSyncState::where('registration_id', $reg->id)->first();
        $this->assertSame(AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED, $outbox->sync_status);
        $this->assertNotNull($outbox->last_synced_at, 'Bukti sinkronisasi historis tetap dipertahankan.');
    }

    /**
     * 12: Expired lease recovery memungkinkan worker baru mengambil alih pekerjaan worker yang crash.
     */
    public function test_12_expired_lease_recovery(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Worker 1 klaim lease lalu crash (lease kedaluwarsa 5 menit lalu)
        $syncState->claimLeaseAtomic('worker_crashed', 120);
        $syncState->update(['lease_expires_at' => now()->subMinutes(5)]);

        $this->assertTrue($syncState->fresh()->canBeClaimed());

        // Worker 2 mengambil alih
        $newToken = $syncState->claimLeaseAtomic('worker_recovery', 120);

        $this->assertNotNull($newToken);
        $this->assertSame('worker_recovery', $syncState->fresh()->lease_owner);
    }

    /**
     * 13: Stale fencing token ditolak secara atomik pada penyelesaian sukses maupun gagal.
     */
    public function test_13_stale_fencing_token_rejected(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $staleToken = $syncState->claimLeaseAtomic('worker_stale', 120);
        $syncState->update(['lease_expires_at' => now()->subMinutes(5)]);

        // Worker baru mengklaim
        $validToken = $syncState->claimLeaseAtomic('worker_valid', 120);

        // Worker stale mencoba merekam sukses dengan token lama
        $successResult = $syncState->recordSuccessAtomic($staleToken, 200);
        $this->assertFalse($successResult, 'Stale token wajib ditolak!');

        // Worker stale mencoba merekam kegagalan dengan token lama
        $failureResult = $syncState->recordFailureAtomic($staleToken, 'Error stale', 500);
        $this->assertFalse($failureResult, 'Stale token wajib ditolak!');

        // Worker valid berhasil
        $validResult = $syncState->recordSuccessAtomic($validToken, 200);
        $this->assertTrue($validResult);
    }

    /**
     * 14: Payload checksum mismatch antara in-flight lease dan data lokal aktual mencegah pengiriman.
     */
    public function test_14_payload_checksum_mismatch_aborts_send(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Simulasikan modifikasi data model secara langsung di database (checksum berubah)
        $reg->fullname = 'Nama Diubah Langsung';
        $reg->save();

        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_test');
        $job->handle($this->fakeTransport);

        // HTTP send tidak boleh terjadi!
        $this->fakeTransport->assertNothingSent();

        // State harus di-reset ke pending
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $syncState->fresh()->sync_status);
    }

    /**
     * 15: Concurrent claimant rejection terverifikasi pada level conditional query.
     */
    public function test_15_concurrent_claimant_rejection(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Claimant 1 berhasil klaim
        $token1 = $syncState->claimLeaseAtomic('worker_1', 120);
        $this->assertNotNull($token1);

        // Claimant 2 mencoba klaim pada baris yang sama saat lease masih aktif
        $token2 = $syncState->claimLeaseAtomic('worker_2', 120);
        $this->assertNull($token2, 'Claimant 2 wajib ditolak karena lease masih aktif dipegang claimant 1.');
    }

    /**
     * 16: Failed job tidak mengambil alih atau merusak lease milik worker lain.
     */
    public function test_16_failed_job_does_not_hijack_active_worker_lease(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Worker aktif memegang lease
        $activeToken = $syncState->claimLeaseAtomic('worker_active', 120);

        // Job lain mencoba handle dengan worker_rogue
        $rogueJob = new SyncRegistrationToAbsenJob($reg->id, 'worker_rogue');
        $result = $rogueJob->handle($this->fakeTransport);

        $this->assertNull($result);
        $this->assertSame('worker_active', $syncState->fresh()->lease_owner);
        $this->assertSame($activeToken, $syncState->fresh()->fencing_token);
        $this->fakeTransport->assertNothingSent();
    }

    /**
     * 17: Attempt count tidak digandakan oleh lapisan retry queue ($tries = 1).
     */
    public function test_17_attempt_count_not_multiplied_by_queue_layers(): void
    {
        $job = new SyncRegistrationToAbsenJob(999);
        $this->assertSame(1, $job->tries, 'SyncRegistrationToAbsenJob::$tries wajib 1 untuk mencegah penggandaan retry.');
    }

    /**
     * 18: Canonical payload terbukti tidak memuat password, password_hash, religion, maupun API token.
     */
    public function test_18_payload_excludes_sensitive_credentials_and_tokens(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $job = new SyncRegistrationToAbsenJob($reg->id);
        $job->handle($this->fakeTransport);

        $sent = $this->fakeTransport->getLastSentRequest();
        $payload = $sent['payload'];

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('password_hash', $payload);
        $this->assertArrayNotHasKey('religion', $payload);
        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertArrayNotHasKey('token', $payload);
    }

    /**
     * 19: Perubahan profil di luar lifecycle terdokumentasi memerlukan rekonsiliasi.
     */
    public function test_19_direct_profile_updates_outside_lifecycle_requires_reconciliation(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $outbox = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Update langsung atribut tanpa melalui lifecycle service
        $reg->phone_number = '089999999999';
        $reg->save();

        // Checksum pada outbox masih mengacu pada data sebelum update
        $currentCanonical = $this->recorder->generateCanonicalPayload($reg->fresh(), $reg->internship_status);
        $newChecksum      = $this->recorder->calculateChecksum($currentCanonical);

        $this->assertNotSame($outbox->current_payload_checksum, $newChecksum);
        // Membuktikan perlunya rekonsiliasi atau hook penyelarasan data profil
    }

    /**
     * 20: Isolasi database development terjaga (0 baris eksternal pada dev db).
     */
    public function test_20_development_database_remains_untouched(): void
    {
        $pdo = new \PDO("mysql:host=127.0.0.1;port=3300;dbname=internship_management_system", "root", "");
        $count = $pdo->query("SELECT COUNT(*) AS total FROM internship_registrations")->fetchColumn();

        $this->assertSame(61, (int) $count, 'Jumlah registrasi development database wajib tetap 61.');
    }

    /**
     * 21: Audit C.3B.1.1 — Job Reconstruction dengan serialize/unserialize:
     * Instance job yang direkonstruksi oleh Laravel Queue tetap memiliki attemptToken stabil
     * dan method failed() dapat melepaskan lease secara kondisional.
     */
    public function test_21_job_reconstruction_releases_lease_conditionally_on_failure(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Buat job original
        $originalJob = new SyncRegistrationToAbsenJob($reg->id, 'worker_reconstructed');
        $this->assertNotEmpty($originalJob->attemptToken);

        // Simulasikan klaim lease menggunakan attemptToken original
        $fencingToken = $syncState->claimLeaseAtomic($originalJob->workerId, 120, $originalJob->attemptToken);
        $this->assertSame($originalJob->attemptToken, $fencingToken);
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $syncState->fresh()->sync_status);

        // Simulasikan serialisasi job oleh antrean Laravel dan rekonstruksi instance baru
        $serialized = serialize($originalJob);
        /** @var SyncRegistrationToAbsenJob $reconstructedJob */
        $reconstructedJob = unserialize($serialized);

        $this->assertNotSame($originalJob, $reconstructedJob, 'Wajib instance objek yang berbeda.');
        $this->assertSame($originalJob->attemptToken, $reconstructedJob->attemptToken, 'attemptToken wajib bertahan melalui serialisasi.');

        // Panggil failed() pada instance yang baru direkonstruksi
        $reconstructedJob->failed(new \RuntimeException('Queue worker timeout or memory limit reached'));

        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_FAILED, $freshState->sync_status);
        $this->assertNull($freshState->fencing_token, 'Fencing lease wajib dilepaskan secara aman.');
        $this->assertNull($freshState->lease_owner);
        $this->assertNotNull($freshState->next_retry_at);
        $this->assertStringContainsString('Queue worker timeout', $freshState->last_error_message);
    }

    /**
     * 22: Audit C.3B.1.1 — Job Reconstruction tanpa attemptToken (atau null)
     * tidak memodifikasi state database sama sekali (menyerahkan ke expired-lease recovery).
     */
    public function test_22_job_reconstruction_without_attempt_token_does_not_modify_state(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Worker memperoleh lease
        $activeToken = $syncState->claimLeaseAtomic('worker_active', 120);
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $syncState->fresh()->sync_status);

        // Rekonstruksi instance job tanpa attemptToken
        $jobWithoutToken = new SyncRegistrationToAbsenJob($reg->id, 'worker_active');
        $jobWithoutToken->attemptToken = null; // simulasikan token hilang/tidak tersedia

        $jobWithoutToken->failed(new \RuntimeException('Some error'));

        $freshState = $syncState->fresh();
        // State di database TIDAK BOLEH berubah!
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $freshState->sync_status);
        $this->assertSame($activeToken, $freshState->fencing_token);
        $this->assertSame('worker_active', $freshState->lease_owner);
    }

    /**
     * 23: Audit C.3B.1.1 — failed() milik Worker A tidak merusak atau melepaskan lease
     * yang sudah diambil alih oleh Worker B (expired lease recovery).
     */
    public function test_23_stale_job_failed_callback_does_not_hijack_worker_b_lease(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Job A klaim lease
        $jobA = new SyncRegistrationToAbsenJob($reg->id, 'worker_a');
        $tokenA = $syncState->claimLeaseAtomic($jobA->workerId, 120, $jobA->attemptToken);

        // Lease Job A kedaluwarsa 5 menit lalu
        $syncState->update(['lease_expires_at' => now()->subMinutes(5)]);

        // Worker B mengambil alih lease
        $jobB = new SyncRegistrationToAbsenJob($reg->id, 'worker_b');
        $tokenB = $syncState->claimLeaseAtomic($jobB->workerId, 120, $jobB->attemptToken);

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame('worker_b', $syncState->fresh()->lease_owner);

        // Simulasikan queue failure pada Job A (misal worker A baru terbangun dari freeze dan memanggil failed())
        $serializedA = serialize($jobA);
        /** @var SyncRegistrationToAbsenJob $reconstructedA */
        $reconstructedA = unserialize($serializedA);
        $reconstructedA->failed(new \RuntimeException('Worker A crash report'));

        // State Worker B HARUS TETAP UTUH!
        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCING, $freshState->sync_status);
        $this->assertSame($tokenB, $freshState->fencing_token);
        $this->assertSame('worker_b', $freshState->lease_owner);
    }

    /**
     * 24: Audit C.3B.1.1 — Konsistensi Retry Status:
     * - Pending NULL retry: eligible
     * - Failed due retry: eligible
     * - Failed future retry: NOT eligible
     * - Dead-letter: NOT eligible
     * - Cancelled: NOT eligible
     * - Remote-action-required: NOT eligible
     */
    public function test_24_retry_status_eligibility_matrix(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $state = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // 1. Pending dengan NULL next_retry_at -> eligible
        $state->update(['sync_status' => AbsenSyncState::STATUS_PENDING, 'next_retry_at' => null]);
        $this->assertTrue($state->fresh()->canBeClaimed());
        $this->assertSame(1, AbsenSyncState::where('id', $state->id)->eligibleForClaim()->count());

        // 2. Failed due retry (next_retry_at di masa lampau) -> eligible
        $state->update(['sync_status' => AbsenSyncState::STATUS_FAILED, 'next_retry_at' => now()->subMinute()]);
        $this->assertTrue($state->fresh()->canBeClaimed());
        $this->assertSame(1, AbsenSyncState::where('id', $state->id)->eligibleForClaim()->count());

        // 3. Failed future retry (next_retry_at di masa depan) -> NOT eligible
        $state->update(['sync_status' => AbsenSyncState::STATUS_FAILED, 'next_retry_at' => now()->addMinutes(10)]);
        $this->assertFalse($state->fresh()->canBeClaimed());
        $this->assertSame(0, AbsenSyncState::where('id', $state->id)->eligibleForClaim()->count());

        // 4. Dead-letter -> NOT eligible
        $state->update(['sync_status' => AbsenSyncState::STATUS_DEAD_LETTER, 'next_retry_at' => null]);
        $this->assertFalse($state->fresh()->canBeClaimed());
        $this->assertSame(0, AbsenSyncState::where('id', $state->id)->eligibleForClaim()->count());

        // 5. Cancelled -> NOT eligible
        $state->update(['sync_status' => AbsenSyncState::STATUS_CANCELLED, 'next_retry_at' => null]);
        $this->assertFalse($state->fresh()->canBeClaimed());
        $this->assertSame(0, AbsenSyncState::where('id', $state->id)->eligibleForClaim()->count());

        // 6. Remote-action-required -> NOT eligible sebagai registrasi baru
        $state->update(['sync_status' => AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED, 'next_retry_at' => null]);
        $this->assertFalse($state->fresh()->canBeClaimed());
        $this->assertSame(0, AbsenSyncState::where('id', $state->id)->eligibleForClaim()->count());
    }

    /**
     * 25: Audit C.3B.1.1 — Pemanggilan job ketika outbound_enabled = false
     * menghasilkan ZERO mutation: attempt_count tidak bertambah, lease tidak diklaim,
     * status tidak berubah, tidak ada retry terjadwal.
     */
    public function test_25_feature_flag_off_produces_zero_mutation_and_zero_attempt_count(): void
    {
        Config::set('services.absen_djuragan.outbound_enabled', false);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $initialAttempts = $syncState->attempt_count;
        $initialStatus   = $syncState->sync_status;

        // Panggil job dengan transport default (DisabledAbsenOutboundTransport)
        $job = new SyncRegistrationToAbsenJob($reg->id);
        $result = $job->handle();

        $this->assertNull($result);

        $freshState = $syncState->fresh();
        $this->assertSame($initialAttempts, $freshState->attempt_count, 'attempt_count tidak boleh bertambah saat flag OFF.');
        $this->assertSame($initialStatus, $freshState->sync_status, 'sync_status tidak boleh berubah saat flag OFF.');
        $this->assertNull($freshState->fencing_token, 'Lease tidak boleh diklaim saat flag OFF.');
        $this->assertNull($freshState->lease_owner);
        $this->assertNull($freshState->next_retry_at);
    }

    /**
     * 26: Audit C.3B.1.1 — Jika pengiriman HTTP remote sukses tetapi status registrasi lokal
     * telah berubah menjadi non-eligible (exited/rejected) saat in-flight:
     * status dicatat sebagai remote_action_required, bukti last_synced_at tersimpan,
     * dan TIDAK di-reset ke pending (mencegah loop retry registrasi baru).
     */
    public function test_26_in_flight_success_with_local_non_eligible_marks_remote_action_required(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Pasang handler pada fake transport yang mengubah status registrasi saat in-flight
        $this->fakeTransport->withHandler(function ($payload, $key) use ($reg) {
            // Simulasikan mutasi pendaftaran ke exited saat HTTP sedang berjalan
            $reg->fresh()->update(['internship_status' => InternshipRegistration::STATUS_EXITED]);
            return AbsenTransportResponse::success(200, ['status' => 'created']);
        });

        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_test');
        $resultState = $job->handle($this->fakeTransport);

        $this->assertNotNull($resultState);
        $this->assertSame(AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED, $resultState->sync_status);
        $this->assertNotNull($resultState->last_synced_at, 'Bukti sinkronisasi historis wajib tersimpan.');
        $this->assertNull($resultState->fencing_token, 'Lease wajib dibersihkan.');
        $this->assertNull($resultState->next_retry_at, 'Tidak boleh ada retry otomatis.');
    }

    /**
     * 27: Audit C.3B.1.1 — End-to-End Delivery tanpa rekonstruksi checksum manual di test.
     */
    public function test_27_end_to_end_delivery_without_manual_checksum(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);

        // 1. Lifecycle transition mencatat outbox record secara internal
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);
        $this->assertNotNull($syncState);
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $syncState->sync_status);
        $this->assertNotEmpty($syncState->current_payload_checksum);

        // 2. Fake transport disiapkan
        $this->fakeTransport->willRespondWith(AbsenTransportResponse::success(201, ['id' => 999], 'Created in mock Absen server'));

        // 3. Dispatch & handle job
        $job = new SyncRegistrationToAbsenJob($reg->id);
        $resultState = $job->handle($this->fakeTransport);

        // 4. Verifikasi state synced
        $this->assertNotNull($resultState);
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $resultState->sync_status);
        $this->assertSame(201, $resultState->last_response_status);
        $this->assertSame($syncState->current_payload_checksum, $resultState->last_synced_checksum);
        $this->assertSame(1, $resultState->last_synced_payload_version);
        $this->assertNotNull($resultState->last_synced_at);

        // 5. Verifikasi payload yang terkirim ke transport
        $lastSent = $this->fakeTransport->getLastSentRequest();
        $this->assertSame($reg->id, $lastSent['payload']['registration_id']);
        $this->assertSame($reg->fullname, $lastSent['payload']['fullname']);
        $this->assertStringStartsWith("ims-reg-{$reg->id}-v1-", $lastSent['idempotency_key']);
    }
}
