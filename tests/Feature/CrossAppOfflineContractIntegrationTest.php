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
use App\Services\AbsenIntegration\Transports\AbsenOutboundTransportInterface;
use App\Services\AbsenIntegration\Transports\CrossAppOfflineContractTransport;
use App\Services\AbsenIntegration\Transports\DisabledAbsenOutboundTransport;
use App\Services\RegistrationLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Feature Test Suite untuk Phase C.3B.3B:
 * Cross-Application Offline Contract Integration Test.
 * Menguji integrasi end-to-end implementasi asli sender IMS dan receiver Absen
 * tanpa melibatkan soket/jaringan HTTP nyata (Zero Network HTTP).
 */
class CrossAppOfflineContractIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected RegistrationLifecycleService $lifecycle;
    protected AbsenOutboxRecorder $recorder;
    protected AbsenMasterDataResolver $resolver;
    protected AbsenCanonicalPayloadBuilder $builder;
    protected CrossAppOfflineContractTransport $contractTransport;
    protected ?PDO $absenTestPdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle         = app(RegistrationLifecycleService::class);
        $this->recorder          = app(AbsenOutboxRecorder::class);
        $this->resolver          = app(AbsenMasterDataResolver::class);
        $this->builder           = app(AbsenCanonicalPayloadBuilder::class);
        $this->contractTransport = new CrossAppOfflineContractTransport();

        // Konfigurasi integrasi sender IMS
        Config::set('services.absen_djuragan.outbox_enabled', true);
        Config::set('services.absen_djuragan.outbound_enabled', true);
        Config::set('services.absen_djuragan.outbound_api_key', 'test_absen_secret_key_2026');
        Config::set('services.absen_djuragan.url', 'http://absen-djuragan.test/api/v1/external');

        // Buka koneksi direct read-only inspection ke DB testing Absen untuk verifikasi assertion
        try {
            $this->absenTestPdo = new PDO(
                'mysql:host=127.0.0.1;port=3300;dbname=absen_djuragan_testing',
                'root',
                '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database testing Absen Djuragan (127.0.0.1:3300/absen_djuragan_testing) tidak dapat diakses: ' . $e->getMessage());
        }
    }

    protected function createTestUser(?string $email = null): User
    {
        return User::create([
            'name'     => 'CrossApp Intern ' . uniqid(),
            'email'    => $email ?? ('crossapp_' . uniqid() . '@example.com'),
            'password' => Hash::make('secret123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);
    }

    protected function createTestRegistration(User $user, ?Institution $inst = null, ?Division $div = null): InternshipRegistration
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
            'fullname'          => 'Peserta CrossApp ' . uniqid(),
            'student_id'        => 'NIM' . rand(100000, 999999),
            'born_date'         => '2001-05-20',
            'birth_place'       => 'Jakarta',
            'gender'            => 'Laki-laki',
            'phone_number'      => '081234567890',
            'institution_id'    => $inst->id,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'division_id'       => $div->id,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'start_date'        => now()->addDays(7)->toDateString(),
            'end_date'          => now()->addMonths(6)->toDateString(),
        ]);
    }

    /**
     * 1. Verifikasi Transport Binding Aktual pada IMS.
     */
    public function test_01_transport_container_binding_resolution(): void
    {
        // Kondisi 1: Outbound enabled = false (default)
        Config::set('services.absen_djuragan.outbound_enabled', false);
        $transportDisabled = app(AbsenOutboundTransportInterface::class);
        $this->assertInstanceOf(DisabledAbsenOutboundTransport::class, $transportDisabled);

        // Kondisi 2: Outbound enabled = true
        Config::set('services.absen_djuragan.outbound_enabled', true);
        $transportEnabled = app(AbsenOutboundTransportInterface::class);
        $this->assertInstanceOf(AbsenHttpOutboundTransport::class, $transportEnabled);
    }

    /**
     * 2. Periksa Migration Ledger dan Schema Alignment pada Database Testing IMS.
     */
    public function test_02_migration_ledger_and_schema_alignment(): void
    {
        $this->assertTrue(Schema::hasTable('absen_sync_states'));
        $this->assertTrue(Schema::hasColumn('absen_sync_states', 'payload_snapshot'));
        $this->assertTrue(Schema::hasColumn('absen_sync_states', 'absen_user_id'));
        $this->assertTrue(Schema::hasColumn('absen_sync_states', 'intern_id'));
        $this->assertTrue(Schema::hasColumn('absen_sync_states', 'confirmed_payload_version'));
    }

    /**
     * 3. Bandingkan Checksum Menggunakan Implementasi Asli Kedua Sistem.
     */
    public function test_03_checksum_matching_between_ims_builder_and_absen_receiver(): void
    {
        $uniqueId = rand(10000, 99999);
        $testPayload = [
            'external_system'          => 'ims_seveninc',
            'external_user_id'         => (string) $uniqueId,
            'external_registration_id' => (string) ($uniqueId + 1),
            'payload_version'          => 1,
            'idempotency_key'          => "ims-reg-{$uniqueId}-v1-test",
            'email'                    => "checksum.test_{$uniqueId}@example.com",
            'full_name'                => 'Siti Rahmawati',
            'phone'                    => '081299887766',
            'gender'                   => 'Perempuan',
            'born_date'                => '2002-11-10',
            'birth_place'              => 'Bandung',
            'student_id'               => 'NIM2026999',
            'school_id'                => 10,
            'division_id'              => 20,
            'brand_id'                 => 30,
            'start_date'               => '2026-11-01',
            'end_date'                 => '2027-04-30',
        ];

        // 1. Checksum dihitung oleh IMS Canonical Builder
        $imsChecksum = $this->builder->calculateRemoteChecksum($testPayload);

        // 2. Kirim ke receiver Absen Djuragan melalui offline bridge untuk verifikasi
        $response = $this->contractTransport->sendRegistration($testPayload, $testPayload['idempotency_key']);

        // Jika receiver menerima dan memverifikasi checksum, status adalah 201 (Created)
        $this->assertTrue($response->isSuccess(), 'Response status: ' . $response->getStatusCode() . ' msg: ' . $response->getErrorMessage() . ' raw: ' . $response->getRawBody());
        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * 4. E2E: Integrasi Asli Sender IMS dan Receiver Absen Tanpa HTTP Jaringan Nyata.
     */
    public function test_04_e2e_successful_sync_creates_absen_records_and_updates_ims_outbox(): void
    {
        // 1. Siapkan master data mapping (IMS -> Absen)
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

        $user = $this->createTestUser('e2e.offline_' . uniqid() . '@example.com');
        $reg  = $this->createTestRegistration($user, $inst, $div);

        // 2. Rekam transisi outbox di IMS
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $syncState->sync_status);

        // 3. Jalankan Job pengiriman menggunakan CrossAppOfflineContractTransport
        $job = new SyncRegistrationToAbsenJob($reg->id, 'worker_e2e');
        $job->handle($this->contractTransport);

        // 4. Verifikasi State Outbox di Database Testing IMS
        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $freshState->sync_status);
        $this->assertNotNull($freshState->absen_user_id);
        $this->assertNotNull($freshState->intern_id);
        $this->assertSame(1, (int) $freshState->confirmed_payload_version);
        $this->assertSame(1, (int) $freshState->last_synced_payload_version);
        $this->assertNull($freshState->fencing_token);
        $this->assertNull($freshState->lease_owner);

        // 5. Verifikasi Data Asli yang Terbentuk di Database Testing Absen Djuragan
        $absenUserStmt = $this->absenTestPdo->prepare("SELECT * FROM users WHERE id = :id");
        $absenUserStmt->execute(['id' => $freshState->absen_user_id]);
        $absenUser = $absenUserStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($absenUser, 'User Absen harus benar-benar terbentuk di database absen_djuragan_testing.');
        $this->assertSame($user->email, $absenUser['email']);
        $this->assertSame(0, (int) $absenUser['is_active'], 'User baru harus pending aktivasi (is_active=0).');
        $this->assertSame(0, (int) $absenUser['is_confirm'], 'User baru harus belum konfirmasi (is_confirm=0).');
        $this->assertSame(1, (int) $absenUser['is_reset_token'], 'User baru harus memiliki is_reset_token=1 untuk device binding.');
        $this->assertSame(3, (int) $absenUser['role_id'], 'Role harus pemagang (role_id=3).');

        // Verifikasi Intern di Absen Djuragan
        $internStmt = $this->absenTestPdo->prepare("SELECT * FROM interns WHERE id = :id");
        $internStmt->execute(['id' => $freshState->intern_id]);
        $intern = $internStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($intern, 'Record Intern harus terbentuk di database Absen.');
        $this->assertSame((int) $freshState->absen_user_id, (int) $intern['user_id']);
        $this->assertSame(10, (int) $intern['school_id'], 'School ID harus hasil mapping (10), bukan IMS ID (1).');
        $this->assertSame(20, (int) $intern['division_id'], 'Division ID harus hasil mapping (20), bukan IMS ID (2).');

        // Verifikasi Identity Mapping di Absen
        $identStmt = $this->absenTestPdo->prepare("SELECT * FROM external_identity_mappings WHERE external_system = 'ims_seveninc' AND external_user_id = :uid");
        $identStmt->execute(['uid' => (string) $user->id]);
        $identMapping = $identStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($identMapping);
        $this->assertSame((int) $freshState->absen_user_id, (int) $identMapping['user_id']);

        // Verifikasi Token Aktivasi di Absen
        $tokenStmt = $this->absenTestPdo->prepare("SELECT * FROM user_activation_tokens WHERE user_id = :uid");
        $tokenStmt->execute(['uid' => $freshState->absen_user_id]);
        $token = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($token, 'Token aktivasi harus diterbitkan untuk akun baru.');
        $this->assertNull($token['consumed_at'], 'Token aktivasi belum boleh dikonsumsi.');
    }

    /**
     * 5. Idempotency Replay Menghasilkan Data Sama Tanpa Duplikasi.
     */
    public function test_05_idempotency_replay_returns_existing_records_without_duplicates(): void
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

        $user = $this->createTestUser('replay_' . uniqid() . '@example.com');
        $reg  = $this->createTestRegistration($user, $inst, $div);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        // Pengiriman Pertama
        $job1 = new SyncRegistrationToAbsenJob($reg->id, 'worker_1');
        $job1->handle($this->contractTransport);

        $state1 = $syncState->fresh();
        $absenUserId1 = $state1->absen_user_id;
        $internId1    = $state1->intern_id;

        // Hitung baris users sebelum replay
        $countUsersBefore = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $countInternsBefore = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM interns")->fetchColumn();

        // Pengiriman Ulang (Replay) dengan state yang sama
        $job2 = new SyncRegistrationToAbsenJob($reg->id, 'worker_2');
        // Reset status lokal ke failed untuk mensimulasikan retry worker
        $state1->update(['sync_status' => AbsenSyncState::STATUS_FAILED, 'next_retry_at' => now()->subMinute()]);
        $job2->handle($this->contractTransport);

        $state2 = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $state2->sync_status);
        $this->assertSame($absenUserId1, $state2->absen_user_id);
        $this->assertSame($internId1, $state2->intern_id);

        // Verifikasi tidak ada baris ganda di Absen
        $countUsersAfter = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $countInternsAfter = (int) $this->absenTestPdo->query("SELECT COUNT(*) FROM interns")->fetchColumn();

        $this->assertSame($countUsersBefore, $countUsersAfter, 'Replay tidak boleh menambah baris user di Absen.');
        $this->assertSame($countInternsBefore, $countInternsAfter, 'Replay tidak boleh menambah baris intern di Absen.');
    }

    /**
     * 6. Multi-Period: Akun Pengguna Sama Menghasilkan Intern Baru Tanpa Menimpa Periode Lama.
     */
    public function test_06_multi_period_registration_preserves_historical_mapping(): void
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

        $user = $this->createTestUser('multi.period_' . uniqid() . '@example.com');

        // Periode 1
        $reg1 = $this->createTestRegistration($user, $inst, $div);
        $syncState1 = $this->recorder->recordTransition($reg1, InternshipRegistration::STATUS_ACCEPTED);
        (new SyncRegistrationToAbsenJob($reg1->id, 'worker_p1'))->handle($this->contractTransport);

        $fresh1 = $syncState1->fresh();
        $absenUserId = $fresh1->absen_user_id;
        $internId1   = $fresh1->intern_id;

        // Periode 2 untuk user yang sama
        $reg2 = $this->createTestRegistration($user, $inst, $div);
        $reg2->start_date = now()->addMonths(8)->toDateString();
        $reg2->end_date   = now()->addMonths(14)->toDateString();
        $reg2->save();

        $syncState2 = $this->recorder->recordTransition($reg2, InternshipRegistration::STATUS_ACCEPTED);
        (new SyncRegistrationToAbsenJob($reg2->id, 'worker_p2'))->handle($this->contractTransport);

        $fresh2 = $syncState2->fresh();
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $fresh2->sync_status);

        // User ID Absen harus sama
        $this->assertSame($absenUserId, $fresh2->absen_user_id, 'Kedua periode harus terhubung ke akun Absen yang sama.');

        // Intern ID harus berbeda (periode baru)
        $this->assertNotSame($internId1, $fresh2->intern_id, 'Periode kedua harus menghasilkan baris intern baru.');

        // Kedua intern record harus ada di database Absen
        $intern1 = $this->absenTestPdo->query("SELECT * FROM interns WHERE id = {$internId1}")->fetch(PDO::FETCH_ASSOC);
        $intern2 = $this->absenTestPdo->query("SELECT * FROM interns WHERE id = {$fresh2->intern_id}")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($intern1);
        $this->assertNotEmpty($intern2);
        $this->assertSame((int) $absenUserId, (int) $intern1['user_id']);
        $this->assertSame((int) $absenUserId, (int) $intern2['user_id']);
    }

    /**
     * 7. Stale Version Replay Mempertahankan Status Pending Versi Lebih Baru pada IMS.
     */
    public function test_07_stale_version_replay_preserves_ims_newer_in_flight_version(): void
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

        $user = $this->createTestUser('stale.test_' . uniqid() . '@example.com');
        $reg  = $this->createTestRegistration($user, $inst, $div);

        // Buat dan sinkronkan versi 1
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);
        (new SyncRegistrationToAbsenJob($reg->id, 'worker_v1'))->handle($this->contractTransport);
        $this->assertSame(AbsenSyncState::STATUS_SYNCED, $syncState->fresh()->sync_status);

        // Naikkan ke versi 2 di IMS melalui lifecycle
        $reg->end_date = now()->addMonths(12)->toDateString();
        $reg->internship_status = InternshipRegistration::STATUS_ACTIVE;
        $reg->save();
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACTIVE);
        $this->assertSame(2, $syncState->current_payload_version);

        // Simulasikan request worker untuk versi 2 menerima replay acknowledgment versi 1 dari bridge mock
        $fakeReplayTransport = new class($this->contractTransport) implements AbsenOutboundTransportInterface {
            public function __construct(protected CrossAppOfflineContractTransport $inner) {}
            public function sendRegistration(array $payload, string $idempotencyKey): \App\Services\AbsenIntegration\Transports\AbsenTransportResponse {
                return \App\Services\AbsenIntegration\Transports\AbsenTransportResponse::success(200, [
                    'absen_user_id'   => 555,
                    'intern_id'       => 666,
                    'payload_version' => 1, // Versi 1
                    'is_replay'       => true,
                ]);
            }
        };

        (new SyncRegistrationToAbsenJob($reg->id, 'worker_v2'))->handle($fakeReplayTransport);

        $freshState = $syncState->fresh();
        // Versi 2 belum terpenuhi karena remote hanya mengonfirmasi versi 1 -> tetap PENDING
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $freshState->sync_status);
        $this->assertSame(1, (int) $freshState->confirmed_payload_version);
    }

    /**
     * 8. Missing Master Data Mapping Menahan Pengiriman Sebelum Menjangkau Bridge.
     */
    public function test_08_missing_master_data_mapping_blocks_dispatch(): void
    {
        Config::set('services.absen_djuragan.require_master_mapping', true);
        Config::set('services.absen_djuragan.mappings.institutions', []); // Kosong
        Config::set('services.absen_djuragan.mappings.divisions', []);    // Kosong

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user);
        $syncState = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        (new SyncRegistrationToAbsenJob($reg->id, 'worker_no_map'))->handle($this->contractTransport);

        $freshState = $syncState->fresh();
        $this->assertSame(AbsenSyncState::STATUS_FAILED, $freshState->sync_status);
        $this->assertStringContainsString('Master data mapping belum tersedia', $freshState->last_error_message);
    }
}
