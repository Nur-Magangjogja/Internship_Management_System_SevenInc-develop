<?php

namespace Tests\Feature;

use App\Jobs\SyncRegistrationToAbsenJob;
use App\Models\AbsenSyncState;
use App\Models\Brand;
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
use App\Services\RegistrationLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\TestCase;

/**
 * Feature Test Suite untuk Fast-Track MVP:
 * Real Localhost HTTP Integration Test (IMS SevenInc × Absen Djuragan).
 * Menguji komunikasi HTTP nyata via loopback 127.0.0.1:8001
 * antara aplikasi testing IMS dan server testing Absen Djuragan.
 */
class RealLocalhostHttpIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected RegistrationLifecycleService $lifecycle;
    protected AbsenOutboxRecorder $recorder;
    protected AbsenMasterDataResolver $resolver;
    protected AbsenCanonicalPayloadBuilder $builder;
    protected AbsenHttpOutboundTransport $httpTransport;
    protected ?PDO $absenTestPdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle     = app(RegistrationLifecycleService::class);
        $this->recorder      = app(AbsenOutboxRecorder::class);
        $this->resolver      = app(AbsenMasterDataResolver::class);
        $this->builder       = app(AbsenCanonicalPayloadBuilder::class);
        $this->httpTransport = app(AbsenHttpOutboundTransport::class);

        // Konfigurasi HTTP nyata ke server testing Absen Djuragan pada 127.0.0.1:8001
        Config::set('services.absen_djuragan.outbox_enabled', true);
        Config::set('services.absen_djuragan.outbound_enabled', true);
        Config::set('services.absen_djuragan.outbound_api_key', 'test_absen_secret_key_2026');
        Config::set('services.absen_djuragan.url', 'http://127.0.0.1:8001/api/v1/external');
        Config::set('services.absen_djuragan.sync_timeout', 10);

        try {
            $this->absenTestPdo = new PDO(
                'mysql:host=127.0.0.1;port=3300;dbname=absen_djuragan_testing',
                'root',
                '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database testing Absen Djuragan tidak dapat diakses: ' . $e->getMessage());
        }

        // Preflight HTTP ping ke server Absen Djuragan pada 127.0.0.1:8001
        $ping = @file_get_contents('http://127.0.0.1:8001/api/v1/external/register-sync', false, stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 3]
        ]));

        if ($ping === false) {
            $this->markTestSkipped('Server testing Absen Djuragan pada http://127.0.0.1:8001 tidak merespons. Pastikan artisan serve aktif.');
        }
    }

    protected function createTestUser(?string $email = null): User
    {
        return User::create([
            'name'     => 'RealHttp Intern ' . uniqid(),
            'email'    => $email ?? ('realhttp_' . uniqid() . '@example.com'),
            'password' => Hash::make('secret123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);
    }

    protected function createTestRegistration(User $user, ?Institution $inst = null, ?Division $div = null, ?Brand $brand = null): InternshipRegistration
    {
        $inst = $inst ?: Institution::firstOrCreate(['name' => 'Universitas Indonesia'], [
            'normalized_name' => 'universitas indonesia',
            'is_active'       => true,
        ]);
        $fac  = Faculty::firstOrCreate(['name' => 'Fakultas Ilmu Komputer', 'institution_id' => $inst->id]);
        $prog = StudyProgram::firstOrCreate(['name' => 'Teknologi Informasi', 'faculty_id' => $fac->id]);
        $div  = $div ?: Division::firstOrCreate(['slug' => 'programmer'], [
            'name'      => 'Programmer',
            'is_active' => true,
        ]);

        return InternshipRegistration::create([
            'user_id'           => $user->id,
            'fullname'          => 'Peserta RealHttp ' . uniqid(),
            'student_id'        => 'NIM' . rand(100000, 999999),
            'born_date'         => '2001-05-20',
            'birth_place'       => 'Jakarta',
            'gender'            => 'Laki-laki',
            'phone_number'      => '081234567890',
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'division_id'       => $div->id,
            'brand_id'          => $brand?->id,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'start_date'        => now()->addDays(7)->toDateString(),
            'end_date'          => now()->addMonths(6)->toDateString(),
        ]);
    }

    /**
     * 1. Buktikan Registrasi Sukses Melalui Real Localhost HTTP.
     */
    public function test_01_real_http_successful_registration_and_acknowledgement(): void
    {
        $inst = Institution::firstOrCreate(['name' => 'Universitas Indonesia'], [
            'normalized_name' => 'universitas indonesia',
            'is_active'       => true,
        ]);
        $div = Division::firstOrCreate(['slug' => 'programmer'], [
            'name'      => 'Programmer',
            'is_active' => true,
        ]);

        // Mapping eksplisit terverifikasi: IMS inst -> Absen school 10, IMS div -> Absen div 20
        Config::set('services.absen_djuragan.mappings.institutions', [$inst->id => 10]);
        Config::set('services.absen_djuragan.mappings.divisions', [$div->id => 20]);

        $user = $this->createTestUser('real.success_' . uniqid() . '@example.com');
        $reg  = $this->createTestRegistration($user, $inst, $div);

        // Transisi lifecycle outbox
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $syncState->sync_status);

        // Eksekusi Job pengiriman dengan HTTP Transport asli
        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_http_01');
        $job->handle($this->httpTransport);

        // Verifikasi State Outbox di Database Testing IMS
        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $freshState->sync_status);
        $this->assertSame(201, (int) $freshState->last_response_status);
        $this->assertNotNull($freshState->absen_user_id);
        $this->assertNotNull($freshState->intern_id);
        $this->assertSame(1, (int) $freshState->confirmed_payload_version);
        $this->assertNull($freshState->fencing_token);
        $this->assertNull($freshState->lease_owner);

        // Verifikasi basis data penerima (Absen Djuragan testing)
        $absenUserStmt = $this->absenTestPdo->prepare("SELECT * FROM users WHERE id = :id");
        $absenUserStmt->execute(['id' => $freshState->absen_user_id]);
        $absenUser = $absenUserStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($absenUser);
        $this->assertSame($user->email, $absenUser['email']);
        $this->assertSame(0, (int) $absenUser['is_active']);
        $this->assertSame(0, (int) $absenUser['is_confirm']);
        $this->assertSame(1, (int) $absenUser['is_reset_token']);
        $this->assertSame(3, (int) $absenUser['role_id']);

        // Verifikasi Intern
        $internStmt = $this->absenTestPdo->prepare("SELECT * FROM interns WHERE id = :id");
        $internStmt->execute(['id' => $freshState->intern_id]);
        $intern = $internStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($intern);
        $this->assertSame((int) $freshState->absen_user_id, (int) $intern['user_id']);
        $this->assertSame(10, (int) $intern['school_id']);
        $this->assertSame(20, (int) $intern['division_id']);

        // Verifikasi Token Aktivasi Mandiri
        $tokStmt = $this->absenTestPdo->prepare("SELECT * FROM user_activation_tokens WHERE user_id = :uid");
        $tokStmt->execute(['uid' => $freshState->absen_user_id]);
        $tok = $tokStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($tok);
        $this->assertNull($tok['consumed_at']);
    }

    /**
     * 2. Buktikan Idempotency Replay Melalui Real Localhost HTTP.
     */
    public function test_02_real_http_idempotent_replay_preserves_identities_without_duplicate_rows(): void
    {
        $inst = Institution::firstOrCreate(['name' => 'Universitas Indonesia'], [
            'normalized_name' => 'universitas indonesia',
            'is_active'       => true,
        ]);
        $div = Division::firstOrCreate(['slug' => 'programmer'], [
            'name'      => 'Programmer',
            'is_active' => true,
        ]);
        Config::set('services.absen_djuragan.mappings.institutions', [$inst->id => 10]);
        Config::set('services.absen_djuragan.mappings.divisions', [$div->id => 20]);

        $user = $this->createTestUser('real.replay_' . uniqid() . '@example.com');
        $reg  = $this->createTestRegistration($user, $inst, $div);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Pengiriman pertama
        (new SyncRegistrationToAbsenJob($reg->id, 'worker_http_p1'))->handle($this->httpTransport);
        $state1 = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $state1->sync_status);
        $origUserId = $state1->absen_user_id;
        $origInternId = $state1->intern_id;

        $userCountBefore = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $internCountBefore = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM interns")->fetchColumn();

        // Pengiriman ulang (Replay) dengan me-reset status outbox ke failed (simulasi retry)
        $state1->update(['sync_status' => AbsenSyncState::STATUS_FAILED, 'next_retry_at' => now()->subMinute()]);
        (new SyncRegistrationToAbsenJob($reg->id, 'worker_http_p2'))->handle($this->httpTransport);

        $state2 = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $state2->sync_status);
        $this->assertSame($origUserId, $state2->absen_user_id);
        $this->assertSame($origInternId, $state2->intern_id);
        $this->assertSame(200, (int) $state2->last_response_status); // Replay returns 200

        $userCountAfter = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $internCountAfter = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM interns")->fetchColumn();

        $this->assertSame($userCountBefore, $userCountAfter, 'HTTP Replay tidak boleh menduplikasi user.');
        $this->assertSame($internCountBefore, $internCountAfter, 'HTTP Replay tidak boleh menduplikasi intern.');
    }

    /**
     * 3. Buktikan Pembaruan Versi Payload (Update) Melalui Real Localhost HTTP.
     */
    public function test_03_real_http_payload_version_update_updates_intern_and_receipt(): void
    {
        $inst = Institution::firstOrCreate(['name' => 'Universitas Indonesia'], [
            'normalized_name' => 'universitas indonesia',
            'is_active'       => true,
        ]);
        $div = Division::firstOrCreate(['slug' => 'programmer'], [
            'name'      => 'Programmer',
            'is_active' => true,
        ]);
        Config::set('services.absen_djuragan.mappings.institutions', [$inst->id => 10]);
        Config::set('services.absen_djuragan.mappings.divisions', [$div->id => 20]);

        $user = $this->createTestUser('real.update_' . uniqid() . '@example.com');
        $reg  = $this->createTestRegistration($user, $inst, $div);

        // Versi 1
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);
        (new SyncRegistrationToAbsenJob($reg->id, 'worker_http_v1'))->handle($this->httpTransport);
        $this->assertSame(1, (int) $syncState->fresh()->confirmed_payload_version);

        // Naikkan versi ke 2 di IMS (misal perubahan tanggal selesai magang)
        $newEndDate = now()->addMonths(10)->toDateString();
        $reg->end_date = $newEndDate;
        $reg->internship_status = InternshipRegistration::STATUS_ACTIVE;
        $reg->save();

        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACTIVE);
        $this->assertSame(2, (int) $syncState->current_payload_version);

        // Eksekusi pengiriman versi 2 via HTTP
        (new SyncRegistrationToAbsenJob($reg->id, 'worker_http_v2'))->handle($this->httpTransport);

        $fresh2 = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $fresh2->sync_status);
        $this->assertSame(2, (int) $fresh2->confirmed_payload_version);
        $this->assertSame(2, (int) $fresh2->last_synced_payload_version);

        // Verifikasi bahwa data tanggal di Absen terupdate
        $internStmt = $this->absenTestPdo->prepare("SELECT * FROM interns WHERE id = :id");
        $internStmt->execute(['id' => $fresh2->intern_id]);
        $intern = $internStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($newEndDate, $intern['end_date']);
    }

    /**
     * 4. Buktikan Error Handling HTTP 401 Unauthorized Masuk ke Dead Letter.
     */
    public function test_04_real_http_unauthorized_api_key_moves_to_dead_letter(): void
    {
        $inst = Institution::firstOrCreate(['name' => 'Universitas Indonesia'], [
            'normalized_name' => 'universitas indonesia',
            'is_active'       => true,
        ]);
        $div = Division::firstOrCreate(['slug' => 'programmer'], [
            'name'      => 'Programmer',
            'is_active' => true,
        ]);
        Config::set('services.absen_djuragan.mappings.institutions', [$inst->id => 10]);
        Config::set('services.absen_djuragan.mappings.divisions', [$div->id => 20]);

        // Simulasikan API key salah
        Config::set('services.absen_djuragan.outbound_api_key', 'invalid_unauthorized_key_999');

        $user = $this->createTestUser('real.unauth_' . uniqid() . '@example.com');
        $reg  = $this->createTestRegistration($user, $inst, $div);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        (new SyncRegistrationToAbsenJob($reg->id, 'worker_http_unauth'))->handle($this->httpTransport);

        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_DEAD_LETTER, $freshState->sync_status);
        $this->assertSame(401, (int) $freshState->last_response_status);
        $this->assertStringContainsString('Unauthorized', $freshState->last_error_message);
    }

    /**
     * 5. Buktikan Error Handling Missing Master Data Mapping Tertahan Sebelum Dispatch.
     */
    public function test_05_real_http_missing_master_data_mapping_blocks_http_dispatch(): void
    {
        Config::set('services.absen_djuragan.require_master_mapping', true);
        Config::set('services.absen_djuragan.mappings.institutions', []); // Kosong
        Config::set('services.absen_djuragan.mappings.divisions', []);    // Kosong

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        (new SyncRegistrationToAbsenJob($reg->id, 'worker_http_nomap'))->handle($this->httpTransport);

        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_FAILED, $freshState->sync_status);
        $this->assertStringContainsString('Master data mapping belum tersedia', $freshState->last_error_message);
    }

    /**
     * 6. Buktikan Multi-Period Registrasi untuk Pengguna yang Sama Melalui HTTP Nyata.
     */
    public function test_06_real_http_multi_period_creates_new_intern_for_same_user(): void
    {
        $inst = Institution::firstOrCreate(['name' => 'Universitas Indonesia'], [
            'normalized_name' => 'universitas indonesia',
            'is_active'       => true,
        ]);
        $div = Division::firstOrCreate(['slug' => 'programmer'], [
            'name'      => 'Programmer',
            'is_active' => true,
        ]);
        Config::set('services.absen_djuragan.mappings.institutions', [$inst->id => 10]);
        Config::set('services.absen_djuragan.mappings.divisions', [$div->id => 20]);

        $user = $this->createTestUser('real.multi_' . uniqid() . '@example.com');

        // Periode 1
        $reg1 = $this->createTestRegistration($user, $inst, $div);
        $sync1 = $this->recorder->recordTransition($reg1, InternshipRegistration::STATUS_ACCEPTED);
        (new SyncRegistrationToAbsenJob($reg1->id, 'worker_http_mp1'))->handle($this->httpTransport);

        $fresh1 = $sync1->fresh();
        $absenUserId = $fresh1->absen_user_id;
        $internId1   = $fresh1->intern_id;

        // Periode 2 untuk user yang sama
        $reg2 = $this->createTestRegistration($user, $inst, $div);
        $reg2->start_date = now()->addMonths(8)->toDateString();
        $reg2->end_date   = now()->addMonths(14)->toDateString();
        $reg2->save();

        $sync2 = $this->recorder->recordTransition($reg2, InternshipRegistration::STATUS_ACCEPTED);
        (new SyncRegistrationToAbsenJob($reg2->id, 'worker_http_mp2'))->handle($this->httpTransport);

        $fresh2 = $sync2->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $fresh2->sync_status);
        $this->assertSame($absenUserId, $fresh2->absen_user_id, 'User ID Absen harus sama untuk akun yang sama.');
        $this->assertNotSame($internId1, $fresh2->intern_id, 'Intern ID harus berbeda untuk periode pendaftaran baru.');
    }
}
