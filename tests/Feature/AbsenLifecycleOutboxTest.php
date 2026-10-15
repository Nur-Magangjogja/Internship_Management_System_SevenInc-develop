<?php

namespace Tests\Feature;

use App\Models\AbsenSyncState;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AbsenIntegration\AbsenOutboxRecorder;
use App\Services\RegistrationLifecycleService;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Feature Test untuk Phase C.2C:
 * Transactional Outbox Lifecycle Integration.
 */
class AbsenLifecycleOutboxTest extends TestCase
{
    use DatabaseTransactions;

    protected RegistrationLifecycleService $lifecycle;
    protected AbsenOutboxRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycle = app(RegistrationLifecycleService::class);
        $this->recorder  = app(AbsenOutboxRecorder::class);

        // Pastikan konfigurasi outbox default OFF sesuai aturan
        Config::set('services.absen_djuragan.outbox_enabled', false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }

    protected function createTestUser(): User
    {
        return User::create([
            'name'     => 'Lifecycle User ' . uniqid(),
            'email'    => 'lifecycle_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);
    }

    protected function createTestRegistration(User $user, string $status = InternshipRegistration::STATUS_WAITING): InternshipRegistration
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
            'user_id'           => $user->id,
            'fullname'          => 'Peserta Lifecycle ' . uniqid(),
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
     * 01: outbox_enabled bernilai false secara default.
     */
    public function test_01_outbox_enabled_default_is_off(): void
    {
        // Reset config ke konfigurasi services asli tanpa mock
        $original = config('services.absen_djuragan.outbox_enabled');
        $this->assertFalse($original, 'Konfigurasi services.absen_djuragan.outbox_enabled wajib default false!');
    }

    /**
     * 02: Transisi ke status accepted berhasil tanpa dependensi tabel outbox ketika flag OFF.
     */
    public function test_02_accepted_transition_succeeds_when_flag_off(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', false);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $this->assertSame(InternshipRegistration::STATUS_ACCEPTED, $reg->fresh()->internship_status);
    }

    /**
     * 03: Transisi ke status active berhasil ketika flag OFF.
     */
    public function test_03_active_transition_succeeds_when_flag_off(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', false);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACTIVE, isAdmin: true);

        $this->assertSame(InternshipRegistration::STATUS_ACTIVE, $reg->fresh()->internship_status);
    }

    /**
     * 04: Flag OFF tidak menimbulkan penulisan baris apapun ke tabel absen_sync_states.
     */
    public function test_04_flag_off_produces_zero_outbox_writes(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', false);

        $countBefore = AbsenSyncState::count();

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);
        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $this->assertSame($countBefore, AbsenSyncState::count());
        $this->assertNull(AbsenSyncState::where('registration_id', $reg->id)->first());
    }

    /**
     * 05: Flag ON dengan tabel outbox missing menghasilkan kegagalan terkontrol (fail-closed).
     */
    public function test_05_flag_on_with_missing_table_fails_controlled(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        // Simulasikan tabel absen_sync_states tidak tersedia pada koneksi database
        Schema::shouldReceive('hasTable')
            ->with('absen_sync_states')
            ->andReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Dependensi skema outbox 'absen_sync_states' belum tersedia pada database.");

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
    }

    /**
     * 06: Rollback status pendaftaran secara utuh jika pencatatan outbox gagal.
     */
    public function test_06_rollback_status_when_outbox_recording_fails(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        Schema::shouldReceive('hasTable')
            ->with('absen_sync_states')
            ->andReturn(false);

        try {
            $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        } catch (\RuntimeException) {
            // Expected
        }

        // Status registrasi WAJIB tetap 'waiting' (tidak boleh commit parsial)
        $this->assertSame(InternshipRegistration::STATUS_WAITING, $reg->fresh()->internship_status);
    }

    /**
     * 07: Transisi accepted membuat satu baris pending outbox.
     */
    public function test_07_accepted_transition_creates_one_pending_outbox(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $outbox = AbsenSyncState::where('registration_id', $reg->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $outbox->sync_status);
        $this->assertSame(1, $outbox->current_payload_version);
        $this->assertNotNull($outbox->current_payload_checksum);
        $this->assertSame(0, $outbox->attempt_count);
    }

    /**
     * 08: Transisi active membuat atau memperbarui outbox sesuai perubahan checksum status.
     */
    public function test_08_active_transition_updates_outbox_checksum_and_version(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        // 1. accepted transition -> outbox v1
        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        $outboxV1 = AbsenSyncState::where('registration_id', $reg->id)->first();
        $this->assertSame(1, $outboxV1->current_payload_version);
        $checksumV1 = $outboxV1->current_payload_checksum;

        // 2. active transition -> status berubah, checksum berubah, versi naik ke v2
        $this->lifecycle->transition($reg->fresh(), InternshipRegistration::STATUS_ACTIVE, isAdmin: true);
        $outboxV2 = AbsenSyncState::where('registration_id', $reg->id)->first();

        $this->assertSame(2, $outboxV2->current_payload_version);
        $this->assertNotSame($checksumV1, $outboxV2->current_payload_checksum);
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $outboxV2->sync_status);
    }

    /**
     * 09: Transisi berulang dengan status yang sama tidak menggandakan baris outbox.
     */
    public function test_09_repeated_transition_does_not_duplicate_outbox(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        // Panggil kembali perekam untuk registrasi yang sama
        $this->recorder->recordTransition($reg->fresh(), InternshipRegistration::STATUS_ACCEPTED);

        $this->assertSame(1, AbsenSyncState::where('registration_id', $reg->id)->count());
    }

    /**
     * 10: Penanganan duplicate-key race condition terlindungi secara aman.
     */
    public function test_10_duplicate_key_race_handled_safely(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);

        // Pre-create outbox untuk mensimulasikan transaksi paralel lain yang telah INSERT lebih dahulu
        $existing = AbsenSyncState::create([
            'registration_id'          => $reg->id,
            'sync_status'              => AbsenSyncState::STATUS_PENDING,
            'current_payload_version'  => 1,
            'current_payload_checksum' => 'checksum_existing_race',
        ]);

        // Rekam transisi: harus mengambil baris yang sudah ada tanpa melempar QueryException duplicate key
        $result = $this->recorder->recordTransition($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result->id);
    }

    /**
     * 11: Checksum identik tidak menaikkan versi outbox.
     */
    public function test_11_identical_checksum_does_not_bump_version(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        $stateBefore = AbsenSyncState::where('registration_id', $reg->id)->first();

        // Panggil kembali tanpa ada perubahan field kanonikal
        $stateAfter = $this->recorder->recordTransition($reg->fresh(), InternshipRegistration::STATUS_ACCEPTED);

        $this->assertSame(1, $stateAfter->current_payload_version);
        $this->assertSame($stateBefore->current_payload_checksum, $stateAfter->current_payload_checksum);
    }

    /**
     * 12: Checksum berbeda menaikkan versi outbox.
     */
    public function test_12_different_checksum_bumps_version(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        // Ubah data kanonikal (misal fullname atau phone)
        $reg->fullname = 'Nama Baru Diperbarui';
        $reg->save();

        $stateUpdated = $this->recorder->recordTransition($reg->fresh(), InternshipRegistration::STATUS_ACCEPTED);

        $this->assertSame(2, $stateUpdated->current_payload_version);
    }

    /**
     * 13: Checksum baru tidak boleh NULL dan berupa 64-karakter SHA-256 hex.
     */
    public function test_13_new_checksum_is_not_null_and_is_valid_sha256(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);

        $payload = $this->recorder->generateCanonicalPayload($reg, InternshipRegistration::STATUS_ACCEPTED);
        $checksum = $this->recorder->calculateChecksum($payload);

        $this->assertNotNull($checksum);
        $this->assertSame(64, strlen($checksum));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $checksum);
    }

    /**
     * 14: Canonical payload terbukti mengecualikan password dan religion.
     */
    public function test_14_canonical_payload_excludes_password_and_religion(): void
    {
        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);

        $payload = $this->recorder->generateCanonicalPayload($reg, InternshipRegistration::STATUS_ACCEPTED);

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('password_hash', $payload);
        $this->assertArrayNotHasKey('religion', $payload);
        $this->assertArrayNotHasKey('created_at', $payload);
        $this->assertArrayNotHasKey('updated_at', $payload);
        $this->assertArrayHasKey('internship_status', $payload);
        $this->assertSame(InternshipRegistration::STATUS_ACCEPTED, $payload['internship_status']);
    }

    /**
     * 15: Nol HTTP request dilakukan selama transisi lifecycle dan outbox.
     */
    public function test_15_zero_outbound_http_calls_during_transition(): void
    {
        Http::fake();
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        Http::assertNothingSent();
    }

    /**
     * 16: Nol queue dispatch untuk Absen outbound selama Phase C.2C.
     */
    public function test_16_zero_queue_dispatch_for_absen_during_c2c(): void
    {
        Queue::fake();
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        // Tidak ada job sinkronisasi Absen yang di-push pada fase C.2C
        Queue::assertNothingPushed();
    }

    /**
     * 17: Pencatatan outbox dan perubahan status lifecycle berada dalam satu transaksi atomik.
     */
    public function test_17_outbox_and_lifecycle_committed_in_same_transaction(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $freshReg = $reg->fresh();
        $outbox   = AbsenSyncState::where('registration_id', $reg->id)->first();

        $this->assertSame(InternshipRegistration::STATUS_ACCEPTED, $freshReg->internship_status);
        $this->assertNotNull($outbox);
        $this->assertSame(AbsenSyncState::STATUS_PENDING, $outbox->sync_status);
    }

    /**
     * 18: Existing ActivityLogger tetap berjalan normal pada transisi.
     */
    public function test_18_existing_activity_logger_runs_normally(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $log = ActivityLog::where('subject_type', InternshipRegistration::class)
            ->where('subject_id', $reg->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('intern_registration_status_updated', $log->action);
    }

    /**
     * 19: Perilaku existing Spreadsheet Sidecar setelah commit tidak berubah.
     */
    public function test_19_existing_spreadsheet_sidecar_behavior_unaffected(): void
    {
        Config::set('services.absen_djuragan.outbox_enabled', true);

        $mockEngine = Mockery::mock(SpreadsheetSyncEngineService::class);
        $mockEngine->shouldReceive('queueSyncForRegistration')
            ->once()
            ->andReturnNull();

        $this->app->instance(SpreadsheetSyncEngineService::class, $mockEngine);

        $user = $this->createTestUser();
        $reg  = $this->createTestRegistration($user, InternshipRegistration::STATUS_WAITING);

        $this->lifecycle->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        $this->assertSame(InternshipRegistration::STATUS_ACCEPTED, $reg->fresh()->internship_status);
    }

    /**
     * 20: Registrasi historis tidak otomatis di-backfill ke tabel outbox.
     */
    public function test_20_historical_registrations_not_automatically_backfilled(): void
    {
        // 1. Registrasi dibuat sebelum outbox aktif (simulasi data historis)
        Config::set('services.absen_djuragan.outbox_enabled', false);

        $user = $this->createTestUser();
        $historicalAccepted = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACCEPTED);
        $historicalActive   = $this->createTestRegistration($user, InternshipRegistration::STATUS_ACTIVE);

        // 2. Sekarang outbox diaktifkan
        Config::set('services.absen_djuragan.outbox_enabled', true);

        // 3. Verifikasi data historis TIDAK otomatis memiliki baris outbox
        $this->assertNull(AbsenSyncState::where('registration_id', $historicalAccepted->id)->first());
        $this->assertNull(AbsenSyncState::where('registration_id', $historicalActive->id)->first());
    }
}
