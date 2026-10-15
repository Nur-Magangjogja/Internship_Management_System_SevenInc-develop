<?php

namespace Tests\Feature;

use App\Jobs\ProcessSidecarSyncJob;
use App\Models\City;
use App\Models\Faculty;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSidecarSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\RegistrationLifecycleService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SidecarSpreadsheetSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pemagang;
    protected SpreadsheetConnection $regConnection;
    protected MockSpreadsheetTransport $mockTransport;
    protected RegistrationSpreadsheetMappingService $mappingService;
    protected SpreadsheetSyncEngineService $syncEngine;
    protected FormBuilderService $formBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();

        $this->seed(Spreadsheet2026SchemaSeeder::class);

        $this->formBuilder = app(FormBuilderService::class);
        $this->formBuilder->resetGracePeriodOverride();

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->mappingService = app(RegistrationSpreadsheetMappingService::class);
        $this->syncEngine = new SpreadsheetSyncEngineService(
            transport: $this->mockTransport,
            registrationMappingService: $this->mappingService
        );

        $this->regConnection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Spreadsheet Pendaftaran Magang',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test-reg-sheet/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
                'spreadsheet_id'           => 'test-reg-sheet',
            ]
        );
        $this->regConnection->update([
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test-reg-sheet/edit',
            'is_enabled'               => true,
            'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/test-apps-script-exec/exec',
            'spreadsheet_id'           => 'test-reg-sheet',
        ]);

        $this->admin = User::create([
            'name'      => 'Admin Sidecar',
            'email'     => 'admin_sidecar_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang Sidecar',
            'email'     => 'pemagang_sidecar_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->formBuilder->resetGracePeriodOverride();
        parent::tearDown();
    }

    protected function createVerifiedAcceptedRegistration(array $overrides = []): InternshipRegistration
    {
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);
        $inst = Institution::firstOrCreate(['name' => 'Universitas Indonesia', 'normalized_name' => 'universitas indonesia'], ['is_active' => true]);
        $fac  = Faculty::firstOrCreate(['name' => 'Fakultas Ilmu Komputer', 'institution_id' => $inst->id]);
        $prog = StudyProgram::firstOrCreate(['name' => 'Teknik Informatika', 'faculty_id' => $fac->id]);

        $reg = InternshipRegistration::create(array_merge([
            'user_id'                 => $this->pemagang->id,
            'fullname'                => 'Budi Sidecar',
            'student_id'              => '12345678',
            'born_date'               => '2002-05-10',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'institution_id'          => $inst->id,
            'faculty_id'              => $fac->id,
            'study_program_id'        => $prog->id,
            'city_id'                 => $city->id,
            'internship_interest'     => 'Programmer (Front End / Backend)',
            'start_date'              => '2026-06-01',
            'end_date'                => '2026-08-31',
            'internship_type'         => 'WFO',
            'internship_arrangement'  => 'Mandiri',
            'current_status'          => 'Mahasiswa Aktif',
            'wfo_readiness'           => 'Siap',
            'schedule_shift_readiness'=> 'Siap',
            'has_laptop'              => true,
            'planned_duration'        => '3 Bulan',
            'internship_status'       => InternshipRegistration::STATUS_ACCEPTED,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $reg->created_at = $overrides['created_at'];
            $reg->saveQuietly();
        } else {
            $reg->created_at = Carbon::create(2026, 3, 15, 10, 0, 0);
            $reg->saveQuietly();
        }

        // Catat admin acceptance pada ActivityLog agar lolos verification gate
        \App\Services\ActivityLogger::log(
            action: 'update_status',
            description: 'Status pendaftaran diubah dari waiting ke accepted',
            subject: $reg,
            actor: $this->admin,
            metadata: ['new_status' => 'accepted']
        );

        return $reg;
    }

    /**
     * 1. Sidecar sync ditahan fail-closed jika baris utama belum pernah tersinkronisasi.
     */
    public function test_sidecar_sync_held_fail_closed_if_main_row_not_synced(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();

        // Buat custom answers & snapshot
        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'portfolio_url',
            'field_type' => 'url',
            'label'      => 'Portfolio URL',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);

        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['portfolio_url' => 'https://example.com/port'], $published, $this->admin);

        // Queue sidecar sync
        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->assertNotNull($sidecarSync);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PENDING, $sidecarSync->sync_status);

        // Process sidecar sync: Main row belum pernah sync -> FAIL CLOSED
        $result = $this->syncEngine->processSidecarSyncRecord($sidecarSync);
        $this->assertFalse($result);

        $freshSync = $sidecarSync->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $freshSync->sync_status);
        $this->assertStringContainsString('MAIN_ROW_NOT_SYNCED', $freshSync->error_message);
        $this->assertNull($freshSync->last_synced_at);
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * 2. Sidecar sync berhasil jika baris utama telah tersinkronisasi (Main.Z = Sidecar.A).
     */
    public function test_sidecar_sync_succeeds_when_main_row_is_verified_synced(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();

        // 1. Simulasikan sinkronisasi baris utama telah selesai (Main.Z = syncId)
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertNotNull($mainSync);
        $this->syncEngine->processSyncRecord($mainSync);
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        // 2. Buat custom field & snapshot
        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'custom_github',
            'field_type' => 'url',
            'label'      => 'GitHub Profile',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['custom_github' => 'https://github.com/buditest'], $published, $this->admin);

        // 3. Queue & process sidecar sync
        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->assertNotNull($sidecarSync);

        $result = $this->syncEngine->processSidecarSyncRecord($sidecarSync);
        $this->assertTrue($result);

        $freshSidecar = $sidecarSync->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $freshSidecar->sync_status);
        $this->assertEquals($mainSync->external_sync_id, $freshSidecar->external_sync_id);
        $this->assertNotNull($freshSidecar->last_synced_at);
        $this->assertNotNull($freshSidecar->payload_checksum);
        $this->assertEquals(2, $freshSidecar->row_index);

        // Verifikasi remote tab pada mock
        $remoteSidecar = $this->mockTransport->getRemoteRow('2026_Custom_Fields', $freshSidecar->external_sync_id);
        $this->assertNotNull($remoteSidecar);
        $this->assertEquals($freshSidecar->external_sync_id, $remoteSidecar['SERTIF_SYNC_ID']);
        $this->assertEquals((string)$reg->id, $remoteSidecar['SERTIF_REGISTRATION_ID']);
        $this->assertEquals($published->version_code, $remoteSidecar['FORM_SCHEMA_VERSION']);
        $this->assertEquals('https://github.com/buditest', $remoteSidecar['custom_github']);

        // Aggregate sync status must be synced
        $this->assertEquals('synced', $this->syncEngine->getAggregateSyncStatus($reg));
    }

    /**
     * 3. Idempotensi sidecar sync: pemanggilan ulang dengan data yang sama adalah no-op.
     */
    public function test_sidecar_sync_is_idempotent_no_op_when_checksum_unchanged(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'custom_bio',
            'field_type' => 'textarea',
            'label'      => 'Bio',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['custom_bio' => 'Biografi lengkap'], $published, $this->admin);

        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->syncEngine->processSidecarSyncRecord($sidecarSync);

        $initialCalls = $this->mockTransport->callCount;

        // Jalankan ulang tanpa force
        $secondRun = $this->syncEngine->processSidecarSyncRecord($sidecarSync);
        $this->assertTrue($secondRun);

        // Tidak ada HTTP call baru
        $this->assertEquals($initialCalls, $this->mockTransport->callCount);
    }

    /**
     * 4. Transient timeout pada sidecar sync memicu retry tanpa menduplikasi baris.
     */
    public function test_sidecar_transient_timeout_retries_with_backoff_without_duplicate(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'custom_skill',
            'field_type' => 'text',
            'label'      => 'Skill',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['custom_skill' => 'PHP Laravel'], $published, $this->admin);

        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);

        // Simulasikan timeout setelah remote berhasil ditulis
        $this->mockTransport->simulateTimeoutAfterRemoteSuccess = true;
        $resTimeout = $this->syncEngine->processSidecarSyncRecord($sidecarSync);
        $this->assertFalse($resTimeout);

        $freshSync = $sidecarSync->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $freshSync->sync_status);
        $this->assertEquals(1, $freshSync->attempt_count);
        $this->assertNotNull($freshSync->next_retry_at);

        // Sebelum jatuh tempo next_retry_at, direct retry harus ditolak (fail-closed)
        $resPremature = $this->syncEngine->processSidecarSyncRecord($freshSync);
        $this->assertFalse($resPremature, 'Premature direct retry must be rejected fail-closed');

        // Majukan waktu setelah masa backoff jatuh tempo
        \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(35));

        // Retry kedua saat koneksi kembali normal dan jadwal retry telah jatuh tempo
        $this->mockTransport->simulateTimeoutAfterRemoteSuccess = false;
        $resRetry = $this->syncEngine->processSidecarSyncRecord($freshSync);
        $this->assertTrue($resRetry);

        // Reset test time
        \Illuminate\Support\Carbon::setTestNow();

        // Baris remote tepat 1 (tidak duplikat)
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026_Custom_Fields'));
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $freshSync->fresh()->sync_status);
    }

    /**
     * 5. Partial failure tracking: Main row synced namun sidecar pending/failed menghasilkan status agregat parsial.
     */
    public function test_partial_main_and_sidecar_failure_status_distinction(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'custom_notes',
            'field_type' => 'text',
            'label'      => 'Notes',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['custom_notes' => 'Catatan'], $published, $this->admin);

        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PENDING, $sidecarSync->sync_status);

        // Sebelum sidecar disinkronkan: main sudah synced, sidecar pending -> status agregat partial_main_synced
        $this->assertEquals('partial_main_synced', $this->syncEngine->getAggregateSyncStatus($reg));

        // Jika sidecar gagal:
        $sidecarSync->update(['sync_status' => SpreadsheetSidecarSync::STATUS_FAILED]);
        $this->assertEquals('failed', $this->syncEngine->getAggregateSyncStatus($reg));
    }

    /**
     * 6. Option 1: Registrasi legacy tanpa form snapshot/custom answers membuat baris placeholder 1:1 setelah baris utama tersinkronisasi.
     */
    public function test_legacy_registration_creates_sidecar_placeholder_row(): void
    {
        // a. Registrasi unaccepted / waiting tidak boleh dibuatkan placeholder
        $unacceptedReg = $this->createVerifiedAcceptedRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $this->assertFalse($this->mappingService->requiresSidecarSync($unacceptedReg));
        $this->assertNull($this->syncEngine->queueSidecarSyncForRegistration($unacceptedReg));

        // b. Registrasi verified accepted sebelum baris utama sync belum memerlukan sidecar
        $reg = $this->createVerifiedAcceptedRegistration();
        $this->assertFalse($this->mappingService->requiresSidecarSync($reg));

        // c. Sinkronisasikan baris utama terlebih dahulu
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        // d. Setelah baris utama terverifikasi sync, sidecar placeholder WAJIB dibuat (Option 1)
        $this->assertTrue($this->mappingService->requiresSidecarSync($reg));
        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->assertNotNull($sidecarSync);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PENDING, $sidecarSync->sync_status);

        // e. Proses sinkronisasi sidecar placeholder
        $result = $this->syncEngine->processSidecarSyncRecord($sidecarSync);
        $this->assertTrue($result);

        $freshSidecar = $sidecarSync->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $freshSidecar->sync_status);
        $this->assertNull($freshSidecar->form_schema_version);
        $this->assertNotNull($freshSidecar->last_synced_at);

        // f. Verifikasi struktur remote baris placeholder:
        // A = syncId, B = regId, C = blank (''), D = last_synced_at, E+ = tidak ada kolom tambahan
        $remoteSidecar = $this->mockTransport->getRemoteRow('2026_Custom_Fields', $freshSidecar->external_sync_id);
        $this->assertNotNull($remoteSidecar);
        $this->assertEquals($freshSidecar->external_sync_id, $remoteSidecar['SERTIF_SYNC_ID']);
        $this->assertEquals((string) $reg->id, $remoteSidecar['SERTIF_REGISTRATION_ID']);
        $this->assertEquals('', $remoteSidecar['FORM_SCHEMA_VERSION']);
        $this->assertNotEmpty($remoteSidecar['SERTIF_LAST_SYNC_AT']);

        // g. Status agregat harus 'synced'
        $this->assertEquals('synced', $this->syncEngine->getAggregateSyncStatus($reg));
    }

    /**
     * 7. Gate B Compatibility: Halaman pendaftaran tidak crash saat tabel form_schemas unmigrated.
     */
    public function test_gate_b_unmigrated_form_schemas_compatibility(): void
    {
        // Akses route formulir pendaftaran oleh pemagang
        $res = $this->actingAs($this->pemagang)->get(route('pemagang.registration.form'));
        $res->assertOk();
        $res->assertSee('Form Pendaftaran Magang');
    }

    /**
     * 8. Pemicu otomatis sidecar: Setelah sinkronisasi baris utama berhasil, sidecar otomatis diantrekan dan job dipicu.
     */
    public function test_sidecar_sync_auto_triggered_after_successful_main_row_sync(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();

        // Siapkan custom field dan snapshot
        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'portfolio_url',
            'field_type' => 'url',
            'label'      => 'Portfolio',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['portfolio_url' => 'https://example.com/port'], $published, $this->admin);

        // Sebelum main sync: sidecar sync belum ada
        $this->assertDatabaseMissing('spreadsheet_sidecar_syncs', [
            'internship_registration_id' => $reg->id,
        ]);

        // Antrekan dan proses main sync
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $result = $this->syncEngine->processSyncRecord($mainSync);
        $this->assertTrue($result);
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        // Sidecar sync otomatis dibuat dengan status pending
        $this->assertDatabaseHas('spreadsheet_sidecar_syncs', [
            'internship_registration_id' => $reg->id,
            'sync_status'                => SpreadsheetSidecarSync::STATUS_PENDING,
        ]);

        $sidecarSync = SpreadsheetSidecarSync::where('internship_registration_id', $reg->id)->first();
        $this->assertNotNull($sidecarSync);

        // ProcessSidecarSyncJob wajib dipush ke antrean
        Queue::assertPushed(ProcessSidecarSyncJob::class, function ($job) use ($sidecarSync) {
            return $job->sidecarSyncId === $sidecarSync->id;
        });
    }

    /**
     * 9. Sidecar tidak dipicu jika sinkronisasi baris utama gagal.
     */
    public function test_sidecar_sync_not_triggered_when_main_sync_fails(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'notes',
            'field_type' => 'text',
            'label'      => 'Notes',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['notes' => 'Catatan'], $published, $this->admin);

        // Paksa main sync gagal
        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            'Koneksi Google Sheets terputus',
            'NETWORK_ERROR',
            true
        );

        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $result = $this->syncEngine->processSyncRecord($mainSync);
        $this->assertFalse($result);
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $mainSync->fresh()->sync_status);

        // Sidecar sync TIDAK boleh dibuat atau dipush
        $this->assertDatabaseMissing('spreadsheet_sidecar_syncs', [
            'internship_registration_id' => $reg->id,
        ]);
        Queue::assertNotPushed(ProcessSidecarSyncJob::class);
    }

    /**
     * 10. Kegagalan penambahan kolom header ekstensi (ensureExtensionColumns) menggugurkan mutasi baris sidecar (fail-closed).
     */
    public function test_ensure_extension_columns_failure_aborts_without_calling_upsert(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'hobby',
            'field_type' => 'text',
            'label'      => 'Hobi',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['hobby' => 'Membaca'], $published, $this->admin);

        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->assertNotNull($sidecarSync);

        // Simulasikan kegagalan pada ensureExtensionColumns
        $this->mockTransport->forcedEnsureResponse = SpreadsheetTransportResponse::failure(
            'Header expansion lock timeout',
            'HEADER_LOCK_TIMEOUT',
            true
        );

        $initialCallCount = count($this->mockTransport->callHistory);
        $result = $this->syncEngine->processSidecarSyncRecord($sidecarSync);

        $this->assertFalse($result);

        $fresh = $sidecarSync->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $fresh->sync_status);
        $this->assertStringContainsString('HEADER_LOCK_TIMEOUT', $fresh->error_message);

        // Pastikan upsertExtensionRow TIDAK PERNAH dipanggil dalam history
        $upsertCalls = array_filter(
            $this->mockTransport->callHistory,
            fn($c) => ($c['action'] ?? '') === 'upsert_extension_row'
        );
        $this->assertEmpty($upsertCalls, 'upsertExtensionRow must NOT be called when ensureExtensionColumns fails!');
    }

    /**
     * 11. Remote identity incomplete: Baris utama dengan cell SERTIF_REGISTRATION_ID kosong menolak mutasi sidecar.
     */
    public function test_remote_identity_incomplete_rejects_upsert(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'skill',
            'field_type' => 'text',
            'label'      => 'Skill',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['skill' => 'PHP'], $published, $this->admin);

        $sidecarSync = $this->syncEngine->queueSidecarSyncForRegistration($reg);

        // Ubah baris utama remote agar cell registration id kosong
        $syncId = $sidecarSync->external_sync_id;
        $this->mockTransport->remoteSheets['2026'][$syncId]['SERTIF_REGISTRATION_ID'] = '';
        $this->mockTransport->remoteSheets['2026'][$syncId]['sertif_registration_id'] = '';

        $result = $this->syncEngine->processSidecarSyncRecord($sidecarSync);
        $this->assertFalse($result);

        $fresh = $sidecarSync->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_FAILED, $fresh->sync_status);
        $this->assertStringContainsString('REMOTE_IDENTITY_INCOMPLETE', $fresh->error_message);
    }

    /**
     * 12. Rekonsiliasi manual terarah: reconcileSidecarForRegistration hanya memproses registrasi diterima dengan prior main sync.
     */
    public function test_controlled_reconciliation_sidecar_for_registration(): void
    {
        // a. Registrasi unaccepted: ditolak (return null)
        $unaccepted = $this->createVerifiedAcceptedRegistration(['internship_status' => InternshipRegistration::STATUS_PENDING]);
        $resUnaccepted = $this->syncEngine->reconcileSidecarForRegistration($unaccepted, $this->admin);
        $this->assertNull($resUnaccepted);

        // b. Registrasi accepted tetapi belum pernah main sync: ditolak (return null)
        $unSyncedReg = $this->createVerifiedAcceptedRegistration();
        $resUnsynced = $this->syncEngine->reconcileSidecarForRegistration($unSyncedReg, $this->admin);
        $this->assertNull($resUnsynced);

        // c. Registrasi accepted dengan prior main sync: berhasil direkonsiliasi
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $reconciled = $this->syncEngine->reconcileSidecarForRegistration($reg, $this->admin);
        $this->assertNotNull($reconciled);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PENDING, $reconciled->sync_status);
        $this->assertEquals(0, $reconciled->attempt_count);
        $this->assertNull($reconciled->payload_checksum);

        Queue::assertPushed(ProcessSidecarSyncJob::class, function ($job) use ($reconciled) {
            return $job->sidecarSyncId === $reconciled->id;
        });

        // Verifikasi audit log
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'spreadsheet_sidecar_reconcile_triggered',
            'actor_user_id' => $this->admin->id,
            'subject_id'    => $reg->id,
        ]);
    }

    /**
     * 13. Scanner pemulihan background: scanAndDispatchPendingSidecarSyncs mengambil record due pending/retrying.
     */
    public function test_scan_and_dispatch_pending_sidecar_syncs(): void
    {
        $reg1 = $this->createVerifiedAcceptedRegistration();
        $mainSync1 = $this->syncEngine->queueSyncForRegistration($reg1);
        $this->syncEngine->processSyncRecord($mainSync1);

        $sidecar1 = $this->syncEngine->queueSidecarSyncForRegistration($reg1);
        $sidecar1->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_PENDING,
            'next_retry_at' => now()->subMinute(),
            'locked_at'     => null,
        ]);

        $reg2 = $this->createVerifiedAcceptedRegistration(['fullname' => 'Siti Sidecar']);
        $mainSync2 = $this->syncEngine->queueSyncForRegistration($reg2);
        $this->syncEngine->processSyncRecord($mainSync2);

        $sidecar2 = $this->syncEngine->queueSidecarSyncForRegistration($reg2);
        $sidecar2->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_RETRYING,
            'next_retry_at' => now()->subMinutes(2),
            'locked_at'     => null,
        ]);

        // Rekord yang belum waktunya (future next_retry_at) tidak boleh didispatch
        $reg3 = $this->createVerifiedAcceptedRegistration(['fullname' => 'Future Sidecar']);
        $mainSync3 = $this->syncEngine->queueSyncForRegistration($reg3);
        $this->syncEngine->processSyncRecord($mainSync3);

        $sidecar3 = $this->syncEngine->queueSidecarSyncForRegistration($reg3);
        $sidecar3->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_RETRYING,
            'next_retry_at' => now()->addHour(),
            'locked_at'     => null,
        ]);

        $dispatchedCount = $this->syncEngine->scanAndDispatchPendingSidecarSyncs(limit: 50);

        $this->assertEquals(2, $dispatchedCount);
        Queue::assertPushed(ProcessSidecarSyncJob::class, function ($job) use ($sidecar1) {
            return $job->sidecarSyncId === $sidecar1->id;
        });
        Queue::assertPushed(ProcessSidecarSyncJob::class, function ($job) use ($sidecar2) {
            return $job->sidecarSyncId === $sidecar2->id;
        });
    }

    /**
     * 14. Active processing lease cannot be stolen by another worker.
     */
    public function test_active_processing_lease_cannot_be_stolen(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $sidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $activeToken = 'worker-active-' . uniqid();

        // Worker A aktif memproses dengan locked_at sekarang (lease aktif)
        $sidecar->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $activeToken,
        ]);

        // Worker B mencoba mengklaim baris yang sedang aktif
        $claimedByB = $this->syncEngine->processSidecarSyncRecord($sidecar);
        $this->assertFalse($claimedByB, 'Worker B must NOT steal active lease!');

        // locked_by tetap milik Worker A
        $this->assertEquals($activeToken, $sidecar->fresh()->locked_by);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PROCESSING, $sidecar->fresh()->sync_status);
    }

    /**
     * 15. Expired processing lease can be reclaimed safely.
     */
    public function test_expired_processing_lease_can_be_reclaimed(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $sidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $staleToken = 'worker-dead-' . uniqid();

        // Worker lama mati dan meninggalkan locked_at kedaluwarsa (> 300 detik yang lalu)
        $sidecar->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now()->subSeconds(360),
            'locked_by'   => $staleToken,
        ]);

        // Worker baru harus berhasil mengklaim lease dan memproses hingga selesai
        $reclaimed = $this->syncEngine->processSidecarSyncRecord($sidecar);
        $this->assertTrue($reclaimed, 'Expired lease MUST be reclaimed!');

        $fresh = $sidecar->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $fresh->sync_status);
        $this->assertNull($fresh->locked_by);
        $this->assertNull($fresh->locked_at);
    }

    /**
     * 16. Two workers racing to reclaim expired lease: only one owner succeeds.
     */
    public function test_two_workers_racing_to_reclaim_only_allow_one_owner(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $sidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecar->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now()->subMinutes(10),
            'locked_by'   => 'dead-worker',
        ]);

        $tokenA = 'token-worker-A';
        $tokenB = 'token-worker-B';

        // Worker A mengklaim dengan fencing token A
        $claimedA = DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecar->id)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('sync_status', '!=', SpreadsheetSidecarSync::STATUS_PROCESSING)
                      ->where(function ($lq) {
                          $lq->whereNull('locked_at')
                             ->orWhere('locked_at', '<', now()->subSeconds(300));
                      });
                })
                ->orWhere(function ($q) {
                    $q->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
                      ->whereNotNull('locked_at')
                      ->where('locked_at', '<', now()->subSeconds(300));
                });
            })
            ->update([
                'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
                'locked_at'   => now(),
                'locked_by'   => $tokenA,
                'updated_at'  => now(),
            ]);

        // Worker B mencoba mengklaim tepat setelahnya
        $claimedB = DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecar->id)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('sync_status', '!=', SpreadsheetSidecarSync::STATUS_PROCESSING)
                      ->where(function ($lq) {
                          $lq->whereNull('locked_at')
                             ->orWhere('locked_at', '<', now()->subSeconds(300));
                      });
                })
                ->orWhere(function ($q) {
                    $q->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
                      ->whereNotNull('locked_at')
                      ->where('locked_at', '<', now()->subSeconds(300));
                });
            })
            ->update([
                'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
                'locked_at'   => now(),
                'locked_by'   => $tokenB,
                'updated_at'  => now(),
            ]);

        $this->assertEquals(1, $claimedA, 'Worker A must win the race');
        $this->assertEquals(0, $claimedB, 'Worker B must fail to claim');
        $this->assertEquals($tokenA, $sidecar->fresh()->locked_by);
    }

    /**
     * 17. Previous owner cannot finalize after lease takeover.
     */
    public function test_previous_owner_cannot_finalize_after_lease_takeover(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $sidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $staleToken = 'token-worker-old';
        $newToken = 'token-worker-new';

        // Worker baru telah mengambil alih lease
        $sidecar->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $newToken,
        ]);

        // Worker lama mencoba melakukan update berpagar token lama
        $reflection = new \ReflectionClass($this->syncEngine);
        $updateFenceMethod = $reflection->getMethod('updateSidecarGuardedByFence');
        $updateFenceMethod->setAccessible(true);

        $success = $updateFenceMethod->invoke(
            $this->syncEngine,
            $sidecar,
            $staleToken,
            ['sync_status' => SpreadsheetSidecarSync::STATUS_SYNCED]
        );

        $this->assertFalse($success, 'Old worker write MUST be rejected by fencing token mismatch!');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PROCESSING, $sidecar->fresh()->sync_status);
        $this->assertEquals($newToken, $sidecar->fresh()->locked_by);
    }

    /**
     * 18. Remote write succeeded but local update failed: retry does not create duplicate.
     */
    public function test_remote_write_succeeded_but_local_update_failed_retry_does_not_create_duplicate(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $sidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $syncId = $sidecar->external_sync_id;

        // Simulasikan baris sudah tertulis di remote Google Sheets
        $this->mockTransport->remoteSheets['2026_Custom_Fields'][$syncId] = [
            'SERTIF_SYNC_ID'         => $syncId,
            'SERTIF_REGISTRATION_ID' => (string) $reg->id,
            'FORM_SCHEMA_VERSION'    => '',
            'SERTIF_LAST_SYNC_AT'    => now()->toIso8601String(),
        ];

        $initialRowCount = $this->mockTransport->getRemoteRowCount('2026_Custom_Fields');
        $this->assertEquals(1, $initialRowCount);

        // Eksekusi retry
        $res = $this->syncEngine->processSidecarSyncRecord($sidecar);
        $this->assertTrue($res);

        // Baris remote tetap tepat 1 (update in-place, tidak membuat duplikat)
        $this->assertEquals(1, $this->mockTransport->getRemoteRowCount('2026_Custom_Fields'));
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecar->fresh()->sync_status);
    }

    /**
     * 19. Lease recovery does not bypass verified acceptance or main sync prerequisites.
     */
    public function test_lease_recovery_does_not_bypass_verified_acceptance_or_main_sync_prerequisites(): void
    {
        // a. Registrasi berstatus waiting (belum accepted) dengan lease expired
        $unacceptedReg = $this->createVerifiedAcceptedRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);
        $sidecar = SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $this->regConnection->id,
            'internship_registration_id' => $unacceptedReg->id,
            'external_sync_id'           => 'REG-UNACCEPTED-' . uniqid(),
            'main_sheet_tab'             => '2026',
            'extension_sheet_tab'        => '2026_Custom_Fields',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'                  => now()->subMinutes(10),
            'locked_by'                  => 'stale-worker',
        ]);

        $res = $this->syncEngine->processSidecarSyncRecord($sidecar);
        $this->assertFalse($res);
        $this->assertStringContainsString('MAIN_ROW_NOT_SYNCED', $sidecar->fresh()->error_message);

        // b. Rekonsiliasi manual juga menolak registrasi yang belum accepted
        $resReconcile = $this->syncEngine->reconcileSidecarForRegistration($unacceptedReg, $this->admin);
        $this->assertNull($resReconcile);
    }

    /**
     * 20. Year-based sidecar mapping: 2026 -> 2026_Custom_Fields, 2027 -> 2027_Custom_Fields, invalid -> fail-closed.
     */
    public function test_year_based_sidecar_mapping_2026_and_2027_and_invalid_fail_closed(): void
    {
        // 1. Tahun 2026
        $reg2026 = $this->createVerifiedAcceptedRegistration(['created_at' => \Illuminate\Support\Carbon::create(2026, 4, 1, 10, 0, 0)]);
        $mainSync2026 = $this->syncEngine->queueSyncForRegistration($reg2026);
        $this->syncEngine->processSyncRecord($mainSync2026);

        $sidecar2026 = $this->syncEngine->queueSidecarSyncForRegistration($reg2026);
        $this->assertNotNull($sidecar2026);
        $this->assertEquals('2026', $sidecar2026->main_sheet_tab);
        $this->assertEquals('2026_Custom_Fields', $sidecar2026->extension_sheet_tab);

        // 2. Tahun 2027
        // Siapkan schema version 2027
        $schema2026 = \App\Models\SpreadsheetSchemaVersion::where('year', 2026)->first();
        $schema2027 = \App\Models\SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'year'                      => 2027,
            'schema_version'            => 'v1',
            'sheet_tab_name'            => '2027',
            'is_active'                 => true,
            'notes'                     => '2027 Schema Contract',
        ]);
        foreach ($schema2026->columnMappings as $mapping) {
            $m = $mapping->replicate();
            $m->spreadsheet_schema_version_id = $schema2027->id;
            $m->public_uuid = (string) \Illuminate\Support\Str::uuid();
            $m->save();
        }

        $reg2027 = $this->createVerifiedAcceptedRegistration(['created_at' => \Illuminate\Support\Carbon::create(2027, 2, 10, 9, 0, 0)]);
        $mainSync2027 = $this->syncEngine->queueSyncForRegistration($reg2027);
        $this->syncEngine->processSyncRecord($mainSync2027);

        $sidecar2027 = $this->syncEngine->queueSidecarSyncForRegistration($reg2027);
        $this->assertNotNull($sidecar2027);
        $this->assertEquals('2027', $sidecar2027->main_sheet_tab);
        $this->assertEquals('2027_Custom_Fields', $sidecar2027->extension_sheet_tab);

        // Verifikasi main sheet tetap 29 kolom
        $outboundPayload = $this->mappingService->buildOutboundRowPayload($reg2027);
        $this->assertCount(29, $outboundPayload);

        // 3. Tanggal null atau tidak valid -> fail-closed (returns null)
        $invalidReg = $this->createVerifiedAcceptedRegistration(['created_at' => null]);
        $invalidReg->created_at = null;
        $invalidReg->saveQuietly();

        $sidecarInvalid = $this->syncEngine->queueSidecarSyncForRegistration($invalidReg);
        $this->assertNull($sidecarInvalid, 'Invalid/null created_at MUST fail-closed!');
    }

    /**
     * 21. SpreadsheetSyncRecoveryCommand supports --dry-run and --include-sidecar options safely.
     */
    public function test_spreadsheet_sync_recovery_command_with_dry_run_and_include_sidecar(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);

        $sidecar = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecar->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_PENDING,
            'next_retry_at' => now()->subMinute(),
            'locked_at'     => null,
        ]);

        // A. Mode --dry-run: simulasi tidak mendispatch job
        Queue::fake();

        $this->artisan('sertif:spreadsheet-sync:recover', [
            '--dry-run'         => true,
            '--include-sidecar' => true,
        ])
        ->expectsOutputToContain('[DRY-RUN]')
        ->assertExitCode(0);

        // Tidak boleh ada job yang didispatch saat dry-run
        Queue::assertNothingPushed();

        // B. Mode aktif dengan --include-sidecar: mendispatch job
        $this->artisan('sertif:spreadsheet-sync:recover', [
            '--include-sidecar' => true,
        ])
        ->assertExitCode(0);

        Queue::assertPushed(ProcessSidecarSyncJob::class, function ($job) use ($sidecar) {
            return $job->sidecarSyncId === $sidecar->id;
        });
    }

    /**
     * 22. R02 Fix 1, 2, 3: Invalid signature does not consume nonce, and replay protection is concurrency-safe.
     */
    public function test_r02_invalid_signature_does_not_consume_nonce_and_replay_protection_is_concurrency_safe(): void
    {
        $nodeScript = <<<'JS'
const crypto = require("crypto");
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const SECRET = "test_shared_secret_r02_key_987654321";

const cacheStore = {};
global.CacheService = {
  getScriptCache: () => ({
    get: (k) => cacheStore[k] || null,
    put: (k, v, ttl) => { cacheStore[k] = v; }
  })
};

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => (k === "SHARED_API_KEY" ? SECRET : null)
  })
};

let lockAcquiredCount = 0;
let lockReleasedCount = 0;
global.LockService = {
  getScriptLock: () => ({
    tryLock: () => { lockAcquiredCount++; return true; },
    waitLock: () => { lockAcquiredCount++; return true; },
    hasLock: () => true,
    releaseLock: () => { lockReleasedCount++; }
  })
};

global.Utilities = {
  Charset: { UTF_8: "UTF_8" },
  computeHmacSha256Signature: (data, key) => {
    const hmac = crypto.createHmac("sha256", key);
    hmac.update(data, "utf8");
    const digest = hmac.digest();
    return Array.from(digest).map(b => (b > 127 ? b - 256 : b));
  }
};

function signRequest(timestamp, nonce, payload, secret) {
  const canonicalData = timestamp + "." + nonce + "." + JSON.stringify(payload);
  const hmac = crypto.createHmac("sha256", secret);
  hmac.update(canonicalData, "utf8");
  return hmac.digest("hex");
}

const currentTs = Math.floor(Date.now() / 1000);
const nonceTest = "test-nonce-r02-unique";
const testPayload = { action: "ping", test: 123 };

// Step 1: Corrupted signature -> rejected, nonce NOT stored in cache
const badSigRes = appsScript.validateAuthentication({
  timestamp: currentTs,
  nonce: nonceTest,
  signature: "invalid_corrupted_signature_hex",
  payload: testPayload
});
const step1_ok = badSigRes && badSigRes.error_code === "INVALID_SIGNATURE";
const step1_nonce_not_consumed = (cacheStore["nonce_" + nonceTest] === undefined);

// Step 2: Valid signature with SAME nonce must succeed (proving nonce was NOT consumed by bad request)
const validSig = signRequest(currentTs, nonceTest, testPayload, SECRET);
const validRes = appsScript.validateAuthentication({
  timestamp: currentTs,
  nonce: nonceTest,
  signature: validSig,
  payload: testPayload
});
const step2_ok = (validRes === null);
const step2_nonce_consumed = (cacheStore["nonce_" + nonceTest] === "1");

// Step 3: Replaying the valid request with the SAME nonce must be rejected
const replayRes = appsScript.validateAuthentication({
  timestamp: currentTs,
  nonce: nonceTest,
  signature: validSig,
  payload: testPayload
});
const step3_ok = replayRes && replayRes.error_code === "REPLAYED_NONCE";

// Step 4: Concurrency lock was used
const step4_lock_ok = lockAcquiredCount > 0 && lockReleasedCount === lockAcquiredCount;

process.stdout.write(JSON.stringify({
  step1_ok,
  step1_nonce_not_consumed,
  step2_ok,
  step2_nonce_consumed,
  step3_ok,
  step4_lock_ok
}));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($output['step1_ok'], 'Invalid signature request MUST return INVALID_SIGNATURE.');
        $this->assertTrue($output['step1_nonce_not_consumed'], 'Invalid signature request MUST NOT consume or cache nonce.');
        $this->assertTrue($output['step2_ok'], 'Subsequent valid signature with same nonce MUST succeed.');
        $this->assertTrue($output['step2_nonce_consumed'], 'Authenticated request MUST cache nonce.');
        $this->assertTrue($output['step3_ok'], 'Replayed request with identical nonce MUST return REPLAYED_NONCE.');
        $this->assertTrue($output['step4_lock_ok'], 'LockService MUST be acquired and released during nonce verification.');
    }

    /**
     * 23. R02 Fix 4: Duplicate and identity mismatch checks on sidecar MUST execute before header modification.
     */
    public function test_r02_sidecar_duplicate_and_identity_mismatch_prevent_header_modification(): void
    {
        $nodeScript = <<<'JS'
const crypto = require("crypto");
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const SECRET = "test_shared_secret_r02_sidecar_key";
const REG_ID = "test-spreadsheet-id";

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => {
      if (k === "SHARED_API_KEY") return SECRET;
      if (k === "REGISTRATION_SPREADSHEET_ID") return REG_ID;
      return null;
    }
  })
};

global.LockService = {
  getScriptLock: () => ({
    tryLock: () => true,
    waitLock: () => true,
    hasLock: () => true,
    releaseLock: () => {}
  })
};

global.Utilities = {
  Charset: { UTF_8: "UTF_8" },
  computeHmacSha256Signature: (data, key) => {
    const hmac = crypto.createHmac("sha256", key);
    hmac.update(data, "utf8");
    const digest = hmac.digest();
    return Array.from(digest).map(b => (b > 127 ? b - 256 : b));
  }
};

let headersModified = false;
let rowValuesWritten = false;
let currentScenario = "dup";

global.SpreadsheetApp = {
  openById: () => ({
    getSheetByName: (tab) => {
      if (tab === "2026") {
        return {
          getLastRow: () => 2,
          getLastColumn: () => 27,
          getRange: (r, c, numR, numC) => {
            if (r === 1 && numR === 1) {
              const headers = [];
              for (let i = 1; i <= 25; i++) headers.push("COL_" + i);
              headers.push("SERTIF_SYNC_ID");
              headers.push("SERTIF_REGISTRATION_ID");
              return { getValues: () => [headers] };
            }
            if (r === 2 && c === 26) return { getValues: () => [["REG-SYNC-1"]], getValue: () => "REG-SYNC-1" };
            if (r === 2 && c === 27) return { getValue: () => "101" };
            return { getValues: () => [[]], getValue: () => "" };
          }
        };
      }
      if (tab === "2026_Custom_Fields") {
        return {
          getLastRow: () => (currentScenario === "dup" ? 3 : 2),
          getLastColumn: () => 4,
          getRange: (r, c, numR, numC) => {
            return {
              getValues: () => {
                if (r === 1) return [["SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "FORM_SCHEMA_VERSION", "SERTIF_LAST_SYNC_AT"]];
                if (r === 2 && c === 1) {
                  if (currentScenario === "dup") return [["REG-SYNC-1"], ["REG-SYNC-1"]];
                  return [["REG-SYNC-1"]];
                }
                return [[]];
              },
              getValue: () => {
                if (r >= 2 && c === 1) return "REG-SYNC-1";
                if (r >= 2 && c === 2) {
                  if (currentScenario === "mismatch") return "999";
                  return "101";
                }
                return "";
              },
              setValue: () => { headersModified = true; },
              setValues: () => { rowValuesWritten = true; }
            };
          }
        };
      }
      return null;
    }
  }),
  flush: () => {}
};

// Scenario A: Duplicate sidecar rows exist (>1 match)
currentScenario = "dup";
headersModified = false;
rowValuesWritten = false;
const resA = appsScript.handleUpsertExtensionRowAction({
  action: "upsert_extension_row",
  main_sheet_name: "2026",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "REG-SYNC-1",
  registration_id: "101",
  form_schema_version: "2026.v1",
  answers: { "unseen_header_col_a": "val_a" }
});
const a_rejected = resA.statusCode === 422 && resA.data.error_code === "DUPLICATE_SIDECAR_SYNC_ID";
const a_headers_intact = !headersModified;
const a_rows_intact = !rowValuesWritten;

// Scenario B: Identity mismatch on existing sidecar row (remote 999 vs payload 101)
currentScenario = "mismatch";
headersModified = false;
rowValuesWritten = false;
const resB = appsScript.handleUpsertExtensionRowAction({
  action: "upsert_extension_row",
  main_sheet_name: "2026",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "REG-SYNC-1",
  registration_id: "101",
  form_schema_version: "2026.v1",
  answers: { "unseen_header_col_b": "val_b" }
});
const b_rejected = resB.statusCode === 422 && resB.data.error_code === "IDENTITY_MISMATCH";
const b_headers_intact = !headersModified;
const b_rows_intact = !rowValuesWritten;

// Scenario C: Valid request with matching identity -> expands headers & updates row
currentScenario = "valid";
headersModified = false;
rowValuesWritten = false;
const resC = appsScript.handleUpsertExtensionRowAction({
  action: "upsert_extension_row",
  main_sheet_name: "2026",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "REG-SYNC-1",
  registration_id: "101",
  form_schema_version: "2026.v1",
  answers: { "unseen_header_col_c": "val_c" }
});
const c_success = resC.statusCode === 200 && resC.data.success === true;
const c_headers_expanded = headersModified;
const c_row_written = rowValuesWritten;

process.stdout.write(JSON.stringify({
  a_rejected,
  a_headers_intact,
  a_rows_intact,
  b_rejected,
  b_headers_intact,
  b_rows_intact,
  c_success,
  c_headers_expanded,
  c_row_written
}));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($output['a_rejected'], 'Duplicate sidecar sync ID MUST be rejected with 422 DUPLICATE_SIDECAR_SYNC_ID.');
        $this->assertTrue($output['a_headers_intact'], 'Duplicate rejection MUST NOT modify sidecar headers.');
        $this->assertTrue($output['a_rows_intact'], 'Duplicate rejection MUST NOT write any sidecar row values.');

        $this->assertTrue($output['b_rejected'], 'Sidecar identity mismatch MUST be rejected with 422 IDENTITY_MISMATCH.');
        $this->assertTrue($output['b_headers_intact'], 'Identity mismatch rejection MUST NOT modify sidecar headers.');
        $this->assertTrue($output['b_rows_intact'], 'Identity mismatch rejection MUST NOT write any sidecar row values.');

        $this->assertTrue($output['c_success'], 'Pre-requisite verified request MUST succeed with 200.');
        $this->assertTrue($output['c_headers_expanded'], 'Verified request MUST expand new headers.');
        $this->assertTrue($output['c_row_written'], 'Verified request MUST write row values.');
    }

    /**
     * S17.18G.6-A-R04: Full Lock Safety, Nonce Replay, and Time Window Matrix (T01 - T20).
     *
     * Models deterministic clock progression and TTL-based cache expiry.
     * Evaluates sequential replays, concurrency lock contention, future/past timestamp boundaries,
     * cache crashes, and replay protection across the aligned 900s retention window.
     */
    public function test_r04_full_lock_safety_nonce_replay_and_time_window_matrix_t01_to_t20(): void
    {
        $secret = 'test_shared_secret_r04_key_1122334455';
        $currentTs = 1000000;
        $canonicalData = $currentTs . '.nonce-t10.{"action":"ping","test":999}';
        $t10PhpSig = hash_hmac('sha256', $canonicalData, $secret);

        $nodeScript = <<<JS
const crypto = require('crypto');
const path = require('path');

let currentTime = 1000000; // deterministic epoch in seconds

const RealDate = Date;
global.Date = class extends RealDate {
  constructor(...args) {
    if (args.length) {
      super(...args);
    } else {
      super(currentTime * 1000);
    }
  }
  static now() {
    return currentTime * 1000;
  }
  getTime() {
    return currentTime * 1000;
  }
};

let cacheStore = {}; // key -> { value, expiresAt }
global.CacheService = {
  getScriptCache: () => ({
    get: (k) => {
      const entry = cacheStore[k];
      if (!entry) return null;
      if (currentTime >= entry.expiresAt) {
        delete cacheStore[k];
        return null;
      }
      return entry.value;
    },
    put: (k, v, ttl) => {
      cacheStore[k] = {
        value: v,
        expiresAt: currentTime + ttl
      };
    }
  })
};

const SECRET = '{$secret}';
global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => (k === 'SHARED_API_KEY' ? SECRET : null)
  })
};

let lockShouldSucceed = true;
let lockShouldThrow = false;
let lockCallCount = 0;
let lockReleaseCount = 0;

global.LockService = {
  getScriptLock: () => ({
    tryLock: (timeout) => {
      lockCallCount++;
      if (lockShouldThrow) throw new Error('Mocked Lock Error');
      return lockShouldSucceed;
    },
    waitLock: (timeout) => {
      lockCallCount++;
      if (lockShouldThrow) throw new Error('Mocked Lock Error');
      return lockShouldSucceed;
    },
    hasLock: () => lockShouldSucceed,
    releaseLock: () => { lockReleaseCount++; }
  })
};

global.Utilities = {
  Charset: { UTF_8: 'UTF_8' },
  computeHmacSha256Signature: (data, key) => {
    const hmac = crypto.createHmac('sha256', key);
    hmac.update(data, 'utf8');
    const digest = hmac.digest();
    return Array.from(digest).map(b => (b > 127 ? b - 256 : b));
  }
};

const appsScript = require(path.resolve('./resources/apps-script/SertifGoogleIntegration.js'));

function signRequest(timestamp, nonce, payload, secret) {
  const canonicalData = timestamp + '.' + nonce + '.' + JSON.stringify(payload);
  const hmac = crypto.createHmac('sha256', secret);
  hmac.update(canonicalData, 'utf8');
  return hmac.digest('hex');
}

const testPayload = { action: 'ping', test: 999 };

// --- T01 to T10 (Lock Safety & Baseline Interoperability Matrix) ---
// T01: Invalid signature rejected & nonce untouched
currentTime = 1000000;
cacheStore = {};
const t01_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t01', signature: 'invalid_sig', payload: testPayload });
const t01_ok = t01_res && t01_res.error_code === 'INVALID_SIGNATURE' && cacheStore['nonce_nonce-t01'] === undefined;

// T02: Valid signature authenticated & nonce consumed
cacheStore = {};
lockShouldSucceed = true; lockShouldThrow = false;
const t02_sig = signRequest(currentTime, 'nonce-t02', testPayload, SECRET);
const t02_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t02', signature: t02_sig, payload: testPayload });
const t02_ok = t02_res === null && cacheStore['nonce_nonce-t02'] !== undefined && cacheStore['nonce_nonce-t02'].value === '1';

// T03: Sequential replay of valid request rejected
const t03_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t02', signature: t02_sig, payload: testPayload });
const t03_ok = t03_res && t03_res.error_code === 'REPLAYED_NONCE';

// T04: Lock timeout fail-closed
cacheStore = {};
lockShouldSucceed = false; lockShouldThrow = false;
const t04_sig = signRequest(currentTime, 'nonce-t04', testPayload, SECRET);
const t04_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t04', signature: t04_sig, payload: testPayload });
const t04_ok = t04_res && t04_res.error_code === 'LOCK_TIMEOUT' && t04_res.is_retryable === true && cacheStore['nonce_nonce-t04'] === undefined;

// T05: Lock exception fail-closed
cacheStore = {};
lockShouldSucceed = true; lockShouldThrow = true;
const t05_sig = signRequest(currentTime, 'nonce-t05', testPayload, SECRET);
const t05_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t05', signature: t05_sig, payload: testPayload });
const t05_ok = t05_res && t05_res.error_code === 'LOCK_TIMEOUT' && t05_res.is_retryable === true && cacheStore['nonce_nonce-t05'] === undefined;

// T06: LockService unavailable fail-closed
const origLock = global.LockService;
global.LockService = null;
const t06_sig = signRequest(currentTime, 'nonce-t06', testPayload, SECRET);
const t06_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t06', signature: t06_sig, payload: testPayload });
const t06_ok = t06_res && t06_res.error_code === 'LOCK_SERVICE_UNAVAILABLE' && t06_res.is_retryable === true;
global.LockService = origLock;

// T07: Cache crash releases lock
const origCache = global.CacheService;
lockShouldSucceed = true; lockShouldThrow = false; lockCallCount = 0; lockReleaseCount = 0;
global.CacheService = { getScriptCache: () => { throw new Error('Cache crash'); } };
const t07_sig = signRequest(currentTime, 'nonce-t07', testPayload, SECRET);
const t07_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t07', signature: t07_sig, payload: testPayload });
const t07_ok = t07_res && t07_res.error_code === 'CACHE_SERVICE_UNAVAILABLE' && lockReleaseCount > 0 && lockReleaseCount === lockCallCount;
global.CacheService = origCache;

// T08: Nonce usable after previous lock failure
lockShouldSucceed = true; lockShouldThrow = false;
const t08_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t04', signature: t04_sig, payload: testPayload });
const t08_ok = t08_res === null && cacheStore['nonce_nonce-t04'] !== undefined;

// T09: Concurrency safety simulation (Worker 1 holds lock -> Worker 2 gets LOCK_TIMEOUT; Worker 1 commits; Worker 2 retries and gets REPLAYED_NONCE)
cacheStore = {};
const t09_sig = signRequest(currentTime, 'nonce-t09-concurrent', testPayload, SECRET);
lockShouldSucceed = true; lockShouldThrow = false;
const w1_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t09-concurrent', signature: t09_sig, payload: testPayload });
lockShouldSucceed = false;
const w2_contention_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t09-concurrent', signature: t09_sig, payload: testPayload });
lockShouldSucceed = true;
const w2_retry_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t09-concurrent', signature: t09_sig, payload: testPayload });
const t09_ok = w1_res === null && w2_contention_res.error_code === 'LOCK_TIMEOUT' && w2_retry_res.error_code === 'REPLAYED_NONCE';

// T10: PHP hash_hmac interoperability
lockShouldSucceed = true; lockShouldThrow = false;
const t10_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t10', signature: '{$t10PhpSig}', payload: testPayload });
const t10_ok = t10_res === null && cacheStore['nonce_nonce-t10'] !== undefined;

// --- T11 to T20 (R04 Replay Window & Time Alignment Matrix) ---
// T11: Timestamp masa depan +300 detik pada penerimaan pertama -> Request valid dapat diterima
currentTime = 1000000;
const t11_ts = currentTime + 300;
const t11_sig = signRequest(t11_ts, 'nonce-t11', testPayload, SECRET);
const t11_res = appsScript.validateAuthentication({ timestamp: t11_ts, nonce: 'nonce-t11', signature: t11_sig, payload: testPayload });
const t11_ok = t11_res === null && cacheStore['nonce_nonce-t11'] !== undefined && cacheStore['nonce_nonce-t11'].expiresAt === currentTime + 900;

// T12: Replay request yang sama setelah 361 detik -> REPLAYED_NONCE (fixes finding: timestamp still valid, nonce remains cached)
currentTime = 1000000 + 361;
const t12_res = appsScript.validateAuthentication({ timestamp: t11_ts, nonce: 'nonce-t11', signature: t11_sig, payload: testPayload });
const t12_ok = t12_res && t12_res.error_code === 'REPLAYED_NONCE';

// T13: Replay sebelum akhir freshness window (T+599s) -> REPLAYED_NONCE
currentTime = 1000000 + 599;
const t13_res = appsScript.validateAuthentication({ timestamp: t11_ts, nonce: 'nonce-t11', signature: t11_sig, payload: testPayload });
const t13_ok = t13_res && t13_res.error_code === 'REPLAYED_NONCE';

// T14: Request melewati akhir freshness window (+601s dari T=0, diff = 301s > 300s) -> EXPIRED_REQUEST
currentTime = 1000000 + 601;
const t14_res = appsScript.validateAuthentication({ timestamp: t11_ts, nonce: 'nonce-t11', signature: t11_sig, payload: testPayload });
const t14_ok = t14_res && t14_res.error_code === 'EXPIRED_REQUEST';

// T15: Request timestamp masa lalu pada batas -300 detik
currentTime = 1000000;
const t15_past_ts = currentTime - 300;
const t15_sig = signRequest(t15_past_ts, 'nonce-t15', testPayload, SECRET);
const t15_res = appsScript.validateAuthentication({ timestamp: t15_past_ts, nonce: 'nonce-t15', signature: t15_sig, payload: testPayload });
const t15_past_expired_ts = currentTime - 301;
const t15_expired_sig = signRequest(t15_past_expired_ts, 'nonce-t15-expired', testPayload, SECRET);
const t15_expired_res = appsScript.validateAuthentication({ timestamp: t15_past_expired_ts, nonce: 'nonce-t15-expired', signature: t15_expired_sig, payload: testPayload });
const t15_ok = t15_res === null && t15_expired_res.error_code === 'EXPIRED_REQUEST';

// T16: Request menggunakan nonce sama dengan payload berbeda -> Ditolak ketika nonce masih tercatat
currentTime = 1000000 + 200;
const tamperedPayload = { action: 'tampered', test: 123 };
const t16_sig = signRequest(currentTime, 'nonce-t15', tamperedPayload, SECRET);
const t16_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t15', signature: t16_sig, payload: tamperedPayload });
const t16_ok = t16_res && t16_res.error_code === 'REPLAYED_NONCE';

// T17: Request dengan invalid signature -> Tidak mengonsumsi nonce
currentTime = 1000000;
const t17_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t17', signature: 'tampered_sig_17', payload: testPayload });
const t17_ok = t17_res && t17_res.error_code === 'INVALID_SIGNATURE' && cacheStore['nonce_nonce-t17'] === undefined;

// T18: Lock acquisition gagal -> Tidak mengakses atau mencatat nonce
lockShouldSucceed = false; lockShouldThrow = false;
const t18_sig = signRequest(currentTime, 'nonce-t18', testPayload, SECRET);
const t18_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t18', signature: t18_sig, payload: testPayload });
const t18_ok = t18_res && t18_res.error_code === 'LOCK_TIMEOUT' && t18_res.is_retryable === true && cacheStore['nonce_nonce-t18'] === undefined;
lockShouldSucceed = true;

// T19: Cache error -> Fail-closed
global.CacheService = { getScriptCache: () => { throw new Error('Simulated cache crash'); } };
const t19_sig = signRequest(currentTime, 'nonce-t19', testPayload, SECRET);
const t19_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t19', signature: t19_sig, payload: testPayload });
const t19_ok = t19_res && t19_res.error_code === 'CACHE_SERVICE_UNAVAILABLE' && t19_res.is_retryable === true;
global.CacheService = origCache;

// T20: Request valid dengan nonce baru -> Berhasil setelah kondisi error pulih
const t20_sig = signRequest(currentTime, 'nonce-t20', testPayload, SECRET);
const t20_res = appsScript.validateAuthentication({ timestamp: currentTime, nonce: 'nonce-t20', signature: t20_sig, payload: testPayload });
const t20_ok = t20_res === null && cacheStore['nonce_nonce-t20'] !== undefined;

process.stdout.write(JSON.stringify({
  t01_ok, t02_ok, t03_ok, t04_ok, t05_ok, t06_ok, t07_ok, t08_ok, t09_ok, t10_ok,
  t11_ok, t12_ok, t13_ok, t14_ok, t15_ok, t16_ok, t17_ok, t18_ok, t19_ok, t20_ok
}));
JS;

        $process = new Process(['node']);
        $process->setInput($nodeScript);
        $process->mustRun();
        $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($output['t01_ok'], 'T01: Invalid signature must be rejected and must not mutate cache.');
        $this->assertTrue($output['t02_ok'], 'T02: Valid signature with acquired lock must authenticate and cache nonce.');
        $this->assertTrue($output['t03_ok'], 'T03: Sequential replay of valid request must be rejected with REPLAYED_NONCE.');
        $this->assertTrue($output['t04_ok'], 'T04: Lock timeout must fail-closed with retryable LOCK_TIMEOUT.');
        $this->assertTrue($output['t05_ok'], 'T05: Lock exception must fail-closed with retryable LOCK_TIMEOUT.');
        $this->assertTrue($output['t06_ok'], 'T06: Missing LockService must fail-closed with retryable LOCK_SERVICE_UNAVAILABLE.');
        $this->assertTrue($output['t07_ok'], 'T07: CacheService crash must release acquired lock and fail-closed.');
        $this->assertTrue($output['t08_ok'], 'T08: Failed lock attempt must not prevent subsequent valid request once lock is available.');
        $this->assertTrue($output['t09_ok'], 'T09: Concurrency contention must reject competing worker with LOCK_TIMEOUT and subsequent replay with REPLAYED_NONCE.');
        $this->assertTrue($output['t10_ok'], 'T10: PHP hash_hmac signature must interoperate with Apps Script validation.');
        $this->assertTrue($output['t11_ok'], 'T11: Future timestamp boundary (+300s) on initial request must be accepted.');
        $this->assertTrue($output['t12_ok'], 'T12: Replay after 361s within validity window must be rejected with REPLAYED_NONCE.');
        $this->assertTrue($output['t13_ok'], 'T13: Replay before end of freshness window must be rejected with REPLAYED_NONCE.');
        $this->assertTrue($output['t14_ok'], 'T14: Request past freshness window (+601s) must be rejected with EXPIRED_REQUEST.');
        $this->assertTrue($output['t15_ok'], 'T15: Past timestamp boundary (-300s accepted, -301s rejected) must enforce freshness contract.');
        $this->assertTrue($output['t16_ok'], 'T16: Request reusing existing nonce with different payload must be rejected with REPLAYED_NONCE.');
        $this->assertTrue($output['t17_ok'], 'T17: Request with invalid signature must not consume nonce in cache.');
        $this->assertTrue($output['t18_ok'], 'T18: Failed lock acquisition must not access or mutate cache.');
        $this->assertTrue($output['t19_ok'], 'T19: CacheService failure must fail-closed with CACHE_SERVICE_UNAVAILABLE.');
        $this->assertTrue($output['t20_ok'], 'T20: Valid request with new nonce must succeed after error recovery.');
    }

    /**
     * S17.18G.6-B Wave 2C-B: Comprehensive Queue, Worker Leasing, Retry & Idempotency Matrix (T01 - T20).
     */
    public function test_wave2cb_comprehensive_queue_worker_leasing_and_idempotency_matrix_t01_to_t20(): void
    {
        // -------------------------------------------------------------------------
        // SETUP: Base registration with verified prior sync & sidecar answers
        // -------------------------------------------------------------------------
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->assertNotNull($mainSync);
        $this->syncEngine->processSyncRecord($mainSync);
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 't_matrix_github',
            'field_type' => 'url',
            'label'      => 'GitHub Profile',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['t_matrix_github' => 'https://github.com/matrix-t01-t20'], $published, $this->admin);

        // -------------------------------------------------------------------------
        // T01: Concurrent lease claims (1 winner, 1 rejected)
        // -------------------------------------------------------------------------
        $sidecarT01 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $this->assertNotNull($sidecarT01);
        $tokenWinner = 'worker-winner-' . uniqid();
        $tokenLoser = 'worker-loser-' . uniqid();

        // Winner claims lease via processSidecarSyncRecord with requestedFenceToken
        $claimedWinner = $this->syncEngine->processSidecarSyncRecord($sidecarT01, requestedFenceToken: $tokenWinner);
        $this->assertTrue($claimedWinner, 'T01: Winner must successfully claim lease and sync.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecarT01->fresh()->sync_status);

        // Reset to pending for direct concurrent update test
        $sidecarT01->update(['sync_status' => SpreadsheetSidecarSync::STATUS_PENDING, 'locked_at' => null, 'locked_by' => null]);
        $claimedDirect1 = DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecarT01->id)
            ->where('sync_status', '!=', SpreadsheetSidecarSync::STATUS_PROCESSING)
            ->where(function ($q) {
                $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subSeconds(300));
            })
            ->update(['sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING, 'locked_at' => now(), 'locked_by' => $tokenWinner]);

        $claimedDirect2 = DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecarT01->id)
            ->where('sync_status', '!=', SpreadsheetSidecarSync::STATUS_PROCESSING)
            ->where(function ($q) {
                $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subSeconds(300));
            })
            ->update(['sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING, 'locked_at' => now(), 'locked_by' => $tokenLoser]);

        $this->assertEquals(1, $claimedDirect1, 'T01: First concurrent worker must acquire lease.');
        $this->assertEquals(0, $claimedDirect2, 'T01: Second concurrent worker must be rejected.');
        $this->assertEquals($tokenWinner, $sidecarT01->fresh()->locked_by);

        // -------------------------------------------------------------------------
        // T02: Stale worker loses lease and cannot overwrite new worker
        // -------------------------------------------------------------------------
        $tokenStale = 'worker-stale-' . uniqid();
        $tokenNew = 'worker-new-' . uniqid();
        $sidecarT01->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $tokenNew,
        ]);

        $reflection = new \ReflectionClass($this->syncEngine);
        $updateSidecarMethod = $reflection->getMethod('updateSidecarGuardedByFence');
        $updateSidecarMethod->setAccessible(true);

        $staleUpdateResult = $updateSidecarMethod->invoke(
            $this->syncEngine,
            $sidecarT01,
            $tokenStale,
            ['sync_status' => SpreadsheetSidecarSync::STATUS_SYNCED, 'error_message' => 'stale overwrite attempt']
        );
        $this->assertFalse($staleUpdateResult, 'T02: Stale worker update must be rejected by fence mismatch.');
        $this->assertEquals($tokenNew, $sidecarT01->fresh()->locked_by);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PROCESSING, $sidecarT01->fresh()->sync_status);

        // -------------------------------------------------------------------------
        // T03: Delayed failed() callback from old worker (tested using serialized/unserialized job payload) cannot wipe new worker's lease
        // -------------------------------------------------------------------------
        // Sub-case 3A: ProcessSidecarSyncJob
        $sidecarT03 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $jobA = new ProcessSidecarSyncJob($sidecarT03->id);
        $serializedJobA = serialize($jobA);
        $tokenJobA = $jobA->fenceToken;

        // Simulate Worker A was processing, but its lease expired (> 300s)
        $sidecarT03->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now()->subMinutes(10),
            'locked_by'   => $tokenJobA,
        ]);

        // Worker B arrives, reclaims lease with fresh token
        $tokenJobB = 'worker-b-active-' . uniqid();
        $sidecarT03->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $tokenJobB,
        ]);

        // Worker A's job finally fails in Laravel queue and triggers failed() on reconstructed instance
        /** @var ProcessSidecarSyncJob $reconstructedJobA */
        $reconstructedJobA = unserialize($serializedJobA);
        $this->assertEquals($tokenJobA, $reconstructedJobA->fenceToken, 'T03: Durable fence token must survive job deserialization.');

        $reconstructedJobA->failed(new \RuntimeException("Worker A timeout exception"));
        $freshSidecarT03 = $sidecarT03->fresh();
        $this->assertEquals($tokenJobB, $freshSidecarT03->locked_by, 'T03: Delayed failed() callback from Job A must NOT wipe Job B active lease.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PROCESSING, $freshSidecarT03->sync_status);
        $this->assertNull($freshSidecarT03->error_message, 'T03: Job A error message must not overwrite Job B record.');

        // Sub-case 3B: ProcessSpreadsheetRowSyncJob
        $rowJobA = new \App\Jobs\ProcessSpreadsheetRowSyncJob($mainSync->id);
        $serializedRowJobA = serialize($rowJobA);
        $tokenRowJobA = $rowJobA->fenceToken;

        $mainSync->update([
            'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'   => now()->subMinutes(10),
            'locked_by'   => $tokenRowJobA,
        ]);

        $tokenRowJobB = 'worker-row-b-' . uniqid();
        $mainSync->update([
            'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $tokenRowJobB,
        ]);

        /** @var \App\Jobs\ProcessSpreadsheetRowSyncJob $reconstructedRowJobA */
        $reconstructedRowJobA = unserialize($serializedRowJobA);
        $this->assertEquals($tokenRowJobA, $reconstructedRowJobA->fenceToken, 'T03: Row job fence token must survive deserialization.');
        $reconstructedRowJobA->failed(new \RuntimeException("Worker A row sync crash"));

        $freshMainSync = $mainSync->fresh();
        $this->assertEquals($tokenRowJobB, $freshMainSync->locked_by, 'T03: Delayed row failed() callback must not steal active lease.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $freshMainSync->sync_status);

        // Reset main sync to synced for subsequent tests
        $mainSync->update(['sync_status' => SpreadsheetRowSync::STATUS_SYNCED, 'locked_at' => null, 'locked_by' => null]);

        // -------------------------------------------------------------------------
        // T04: Worker crash and stale lease recovery (>300s)
        // -------------------------------------------------------------------------
        $sidecarT04 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT04->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now()->subSeconds(350),
            'locked_by'   => 'dead-crashed-worker-token',
        ]);

        $dispatchedCount = $this->syncEngine->scanAndDispatchPendingSidecarSyncs(limit: 10, dryRun: false);
        $this->assertGreaterThanOrEqual(1, $dispatchedCount, 'T04: Recovery scanner must detect expired lease for redispatch.');

        // Worker executes recovery
        $recovered = $this->syncEngine->processSidecarSyncRecord($sidecarT04);
        $this->assertTrue($recovered, 'T04: Stale lease must be reclaimed and processed to completion.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecarT04->fresh()->sync_status);
        $this->assertNull($sidecarT04->fresh()->locked_by);

        // -------------------------------------------------------------------------
        // T05: Dual scanner duplicate queue dispatch handled safely
        // -------------------------------------------------------------------------
        $sidecarT05 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT05->update(['sync_status' => SpreadsheetSidecarSync::STATUS_PENDING, 'payload_checksum' => null, 'locked_at' => null, 'locked_by' => null]);

        $tokenWorker1 = 'scanner-job-1';
        $tokenWorker2 = 'scanner-job-2';

        $run1 = $this->syncEngine->processSidecarSyncRecord($sidecarT05, requestedFenceToken: $tokenWorker1);
        $this->assertTrue($run1, 'T05: First dispatched job must succeed.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecarT05->fresh()->sync_status);

        // Second job arrives: record already SYNCED with identical checksum -> idempotent no-op returns true, makes 0 transport calls
        $mockCallsBefore = $this->mockTransport->callCount;
        $run2 = $this->syncEngine->processSidecarSyncRecord($sidecarT05, requestedFenceToken: $tokenWorker2);
        $this->assertTrue($run2, 'T05: Second dispatched job must be idempotent no-op.');
        $this->assertEquals($mockCallsBefore, $this->mockTransport->callCount, 'T05: Duplicate dispatch must not trigger remote transport.');

        // -------------------------------------------------------------------------
        // T06: Retry before next_retry_at held fail-closed (Direct call + Scanner)
        // -------------------------------------------------------------------------
        $sidecarT06 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT06->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_RETRYING,
            'next_retry_at' => now()->addMinutes(15),
            'locked_at'     => null,
            'locked_by'     => null,
            'attempt_count' => 1,
        ]);

        // Direct process call on future retry must be held fail-closed
        $directCallsBefore = $this->mockTransport->callCount;
        $directPrematureResult = $this->syncEngine->processSidecarSyncRecord($sidecarT06, force: false);
        $this->assertFalse($directPrematureResult, 'T06: Direct process on premature sidecar retry must be held fail-closed.');
        $this->assertEquals($directCallsBefore, $this->mockTransport->callCount, 'T06: Zero remote calls on premature retry.');
        $this->assertNull($sidecarT06->fresh()->locked_by, 'T06: Lease must not be acquired by premature worker.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $sidecarT06->fresh()->sync_status);

        // Direct process call on null next_retry_at must also be held fail-closed
        $sidecarT06->update(['next_retry_at' => null]);
        $nullRetryResult = $this->syncEngine->processSidecarSyncRecord($sidecarT06, force: false);
        $this->assertFalse($nullRetryResult, 'T06: RETRYING record with null next_retry_at must be held fail-closed.');
        $sidecarT06->update(['next_retry_at' => now()->addMinutes(15)]);

        // Scanner also does not dispatch premature retrying record
        Queue::fake();
        $scannedPremature = $this->syncEngine->scanAndDispatchPendingSidecarSyncs();
        $this->assertEquals(0, $scannedPremature, 'T06: Premature retry before next_retry_at must not be dispatched by scanner (fail-closed).');
        Queue::assertNothingPushed();

        // Same verification on SpreadsheetRowSync: direct call and scanner
        $mainSync->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at' => now()->addMinutes(15),
            'locked_at'     => null,
            'locked_by'     => null,
        ]);
        $rowDirectPremature = $this->syncEngine->processSyncRecord($mainSync, force: false);
        $this->assertFalse($rowDirectPremature, 'T06: Main row premature retry before next_retry_at must be held fail-closed on direct call.');

        $mainSync->update(['next_retry_at' => null]);
        $rowNullRetry = $this->syncEngine->processSyncRecord($mainSync, force: false);
        $this->assertFalse($rowNullRetry, 'T06: Main row RETRYING with null next_retry_at must be held fail-closed.');

        $mainSync->update(['next_retry_at' => now()->addMinutes(15)]);
        $rowScannedPremature = $this->syncEngine->scanAndDispatchPendingSyncs();
        $this->assertEquals(0, $rowScannedPremature, 'T06: Main row premature retry before next_retry_at must not be dispatched by scanner.');
        $mainSync->update(['sync_status' => SpreadsheetRowSync::STATUS_SYNCED, 'next_retry_at' => null]);

        // -------------------------------------------------------------------------
        // T07: Retry after next_retry_at processed (Scanner dispatches matured retry)
        // -------------------------------------------------------------------------
        $sidecarT06->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_RETRYING,
            'next_retry_at' => now()->subSeconds(30),
            'payload_checksum' => null,
            'locked_at'     => null,
            'locked_by'     => null,
        ]);
        $scannedMatured = $this->syncEngine->scanAndDispatchPendingSidecarSyncs();
        $this->assertGreaterThanOrEqual(1, $scannedMatured, 'T07: Matured retry after next_retry_at must be dispatched by scanner.');
        $maturedResult = $this->syncEngine->processSidecarSyncRecord($sidecarT06, force: false);
        $this->assertTrue($maturedResult, 'T07: Matured retry after next_retry_at must be processed.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecarT06->fresh()->sync_status);

        // -------------------------------------------------------------------------
        // T08: Exponential backoff schedule and max attempts
        // -------------------------------------------------------------------------
        $this->assertSame(30, SpreadsheetSidecarSync::calculateBackoffSeconds(1));
        $this->assertSame(120, SpreadsheetSidecarSync::calculateBackoffSeconds(2));
        $this->assertSame(600, SpreadsheetSidecarSync::calculateBackoffSeconds(3));
        $this->assertSame(1800, SpreadsheetSidecarSync::calculateBackoffSeconds(4));
        $this->assertSame(3600, SpreadsheetSidecarSync::calculateBackoffSeconds(5));

        $this->assertSame(60, SpreadsheetRowSync::calculateBackoffSeconds(1));
        $this->assertSame(300, SpreadsheetRowSync::calculateBackoffSeconds(2));
        $this->assertSame(900, SpreadsheetRowSync::calculateBackoffSeconds(3));
        $this->assertSame(3600, SpreadsheetRowSync::calculateBackoffSeconds(4));
        $this->assertSame(14400, SpreadsheetRowSync::calculateBackoffSeconds(5));

        // When attempt_count hits max_attempts (5), next failure transitions to STATUS_FAILED
        $sidecarT08 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT08->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_PENDING,
            'attempt_count' => 4,
            'max_attempts'  => 5,
            'locked_at'     => null,
            'locked_by'     => null,
        ]);
        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Simulated 5th failure',
            errorCode: 'TRANSPORT_ERROR',
            isRetryable: true
        );
        $this->syncEngine->processSidecarSyncRecord($sidecarT08, force: true);
        $this->mockTransport->forcedResponse = null;
        $freshT08 = $sidecarT08->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_FAILED, $freshT08->sync_status, 'T08: Exhausted attempts must transition to FAILED.');
        $this->assertEquals(5, $freshT08->attempt_count);
        $this->assertNull($freshT08->next_retry_at);

        // -------------------------------------------------------------------------
        // T09: Terminal errors halt without automatic retry
        // -------------------------------------------------------------------------
        $sidecarT09 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT09->update(['sync_status' => SpreadsheetSidecarSync::STATUS_PENDING, 'attempt_count' => 0, 'locked_at' => null, 'locked_by' => null]);
        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'HMAC signature verification rejected',
            errorCode: 'AUTH_FAILED',
            isRetryable: false
        );
        $resT09 = $this->syncEngine->processSidecarSyncRecord($sidecarT09, force: true);
        $this->mockTransport->forcedResponse = null;
        $this->assertFalse($resT09);
        $freshT09 = $sidecarT09->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_FAILED, $freshT09->sync_status, 'T09: Terminal AUTH_FAILED must halt without retry.');
        $this->assertNull($freshT09->next_retry_at);

        // -------------------------------------------------------------------------
        // T10: Transient errors transition to retrying with backoff
        // -------------------------------------------------------------------------
        $sidecarT10 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT10->update(['sync_status' => SpreadsheetSidecarSync::STATUS_PENDING, 'attempt_count' => 0, 'locked_at' => null, 'locked_by' => null]);
        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Google Apps Script read timeout',
            errorCode: 'TRANSPORT_TIMEOUT',
            isRetryable: true
        );
        $resT10 = $this->syncEngine->processSidecarSyncRecord($sidecarT10, force: true);
        $this->mockTransport->forcedResponse = null;
        $this->assertFalse($resT10);
        $freshT10 = $sidecarT10->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $freshT10->sync_status, 'T10: Transient error must transition to RETRYING.');
        $this->assertEquals(1, $freshT10->attempt_count);
        $this->assertNotNull($freshT10->next_retry_at);
        $this->assertNull($freshT10->locked_by);

        // -------------------------------------------------------------------------
        // T11: Remote success with HTTP timeout retries idempotently
        // -------------------------------------------------------------------------
        $sidecarT11 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $syncIdT11 = $sidecarT11->external_sync_id;
        // Pre-populate remote row as if remote Apps Script executed successfully before timeout
        $this->mockTransport->remoteSheets['2026_Custom_Fields'][$syncIdT11] = [
            'SERTIF_SYNC_ID'         => $syncIdT11,
            'SERTIF_REGISTRATION_ID' => (string) $reg->id,
            'FORM_SCHEMA_VERSION'    => '',
            'SERTIF_LAST_SYNC_AT'    => now()->toIso8601String(),
            't_matrix_github'        => 'https://github.com/matrix-t01-t20',
        ];
        $countBeforeT11 = $this->mockTransport->getRemoteRowCount('2026_Custom_Fields');

        // Retry should match existing remote row by SERTIF_SYNC_ID without inserting new row
        $resT11 = $this->syncEngine->processSidecarSyncRecord($sidecarT11, force: true);
        $this->assertTrue($resT11, 'T11: Retry after remote success must succeed.');
        $this->assertEquals($countBeforeT11, $this->mockTransport->getRemoteRowCount('2026_Custom_Fields'), 'T11: Zero duplicate remote rows on retry.');

        // -------------------------------------------------------------------------
        // T12: Worker crash after remote success recovers cleanly without duplicates
        // -------------------------------------------------------------------------
        $sidecarT12 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT12->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now()->subMinutes(10),
            'locked_by'   => 'crashed-after-remote-write',
        ]);
        $countBeforeT12 = $this->mockTransport->getRemoteRowCount('2026_Custom_Fields');
        $resT12 = $this->syncEngine->processSidecarSyncRecord($sidecarT12);
        $this->assertTrue($resT12, 'T12: Worker crash recovery must succeed.');
        $this->assertEquals($countBeforeT12, $this->mockTransport->getRemoteRowCount('2026_Custom_Fields'), 'T12: Zero duplicate rows on crash recovery.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecarT12->fresh()->sync_status);

        // -------------------------------------------------------------------------
        // T13: Identical payload retry produces zero duplicate remote rows
        // -------------------------------------------------------------------------
        $rowsBeforeT13 = $this->mockTransport->getRemoteRowCount('2026_Custom_Fields');
        $resT13 = $this->syncEngine->processSidecarSyncRecord($sidecarT12);
        $this->assertTrue($resT13, 'T13: Identical payload retry must be idempotent.');
        $this->assertEquals($rowsBeforeT13, $this->mockTransport->getRemoteRowCount('2026_Custom_Fields'), 'T13: Zero new rows created.');

        // -------------------------------------------------------------------------
        // T14: Payload changes update existing row accurately
        // -------------------------------------------------------------------------
        $this->formBuilder->saveCustomAnswers($reg, ['t_matrix_github' => 'https://github.com/matrix-UPDATED'], $published, $this->admin);
        $sidecarT14 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $resT14 = $this->syncEngine->processSidecarSyncRecord($sidecarT14, force: true);
        $this->assertTrue($resT14, 'T14: Payload update must succeed.');
        $remoteRowT14 = $this->mockTransport->getRemoteRow('2026_Custom_Fields', $sidecarT14->external_sync_id);
        $this->assertEquals('https://github.com/matrix-UPDATED', $remoteRowT14['t_matrix_github'], 'T14: Remote row must reflect updated payload.');

        // -------------------------------------------------------------------------
        // T15: Fencing token prevents stale database updates
        // -------------------------------------------------------------------------
        $sidecarT15 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT15->update(['sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING, 'locked_at' => now(), 'locked_by' => 'active-token-15']);
        $staleWrite = $updateSidecarMethod->invoke(
            $this->syncEngine,
            $sidecarT15,
            'stale-token-15',
            ['sync_status' => SpreadsheetSidecarSync::STATUS_SYNCED]
        );
        $this->assertFalse($staleWrite, 'T15: Update with wrong fencing token must fail-closed.');
        $this->assertEquals('active-token-15', $sidecarT15->fresh()->locked_by);

        // -------------------------------------------------------------------------
        // T16: Main row prerequisite unfulfilled halts sidecar (fail-closed)
        // -------------------------------------------------------------------------
        $regUnsynced = $this->createVerifiedAcceptedRegistration();
        $this->formBuilder->freezeSnapshot($regUnsynced, $published);
        $this->formBuilder->saveCustomAnswers($regUnsynced, ['t_matrix_github' => 'https://github.com/unsynced-reg'], $published, $this->admin);

        $sidecarT16 = $this->syncEngine->queueSidecarSyncForRegistration($regUnsynced);
        $callsBeforeT16 = $this->mockTransport->callCount;
        $resT16 = $this->syncEngine->processSidecarSyncRecord($sidecarT16);
        $this->assertFalse($resT16, 'T16: Unsynced main row must halt sidecar sync fail-closed.');
        $this->assertEquals($callsBeforeT16, $this->mockTransport->callCount, 'T16: Zero remote transport calls when main row unsynced.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $sidecarT16->fresh()->sync_status);
        $this->assertStringContainsString('MAIN_ROW_NOT_SYNCED', $sidecarT16->fresh()->error_message);

        // -------------------------------------------------------------------------
        // T17: Manual retry while worker is active is rejected safely
        // -------------------------------------------------------------------------
        $sidecarT17 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT17->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => 'active-running-worker',
        ]);
        $reconcileBlocked = $this->syncEngine->reconcileSidecarForRegistration($reg, $this->admin);
        $this->assertNull($reconcileBlocked, 'T17: Manual reconcile must be rejected while worker holds active lease.');
        $this->assertEquals('active-running-worker', $sidecarT17->fresh()->locked_by);

        // -------------------------------------------------------------------------
        // T18: Recovery dry-run performs zero mutations or job dispatches
        // -------------------------------------------------------------------------
        $sidecarT18 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecarT18->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_PENDING,
            'next_retry_at' => now()->subMinutes(5),
            'locked_at'     => null,
            'locked_by'     => null,
        ]);
        Queue::fake();
        $dryRunCount = $this->syncEngine->scanAndDispatchPendingSidecarSyncs(limit: 50, dryRun: true);
        $this->assertGreaterThanOrEqual(1, $dryRunCount, 'T18: Dry run must report eligible count.');
        Queue::assertNothingPushed();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PENDING, $sidecarT18->fresh()->sync_status);

        // -------------------------------------------------------------------------
        // T19: Zero unauthorized mutations on registrations/snapshots/custom answers
        // -------------------------------------------------------------------------
        $regCheck = $reg->fresh();
        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $regCheck->internship_status, 'T19: Registration status must remain intact.');
        $this->assertEquals($published->version_code, $regCheck->formSnapshot->form_schema_version, 'T19: Snapshot version must remain intact.');
        $answersMap = RegistrationCustomAnswer::where('registration_id', $reg->id)->pluck('answer_value', 'field_key')->toArray();
        $this->assertArrayHasKey('t_matrix_github', $answersMap);
        $this->assertEquals('https://github.com/matrix-UPDATED', $answersMap['t_matrix_github'], 'T19: Custom answers must remain intact.');

        // -------------------------------------------------------------------------
        // T20: Regression of Wave 2C-A HMAC, nonce, and transport contract
        // -------------------------------------------------------------------------
        $this->test_r04_full_lock_safety_nonce_replay_and_time_window_matrix_t01_to_t20();
    }

    /**
     * S17.18G.6-B-R01B: Mandatory Direct Atomic Retry Eligibility Matrix (Items 1 - 12).
     */
    public function test_r01b_mandatory_direct_atomic_retry_eligibility_matrix_items_1_to_12(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);
        $this->syncEngine->processSyncRecord($mainSync);
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        $draft = $this->formBuilder->getOrCreateDraft(2026, $this->admin);
        $this->formBuilder->addFieldToDraft($draft, [
            'field_key'  => 'r01b_github',
            'field_type' => 'url',
            'label'      => 'GitHub Profile',
        ]);
        $published = $this->formBuilder->publishVersion($draft, $this->admin);
        $this->formBuilder->freezeSnapshot($reg, $published);
        $this->formBuilder->saveCustomAnswers($reg, ['r01b_github' => 'https://github.com/r01b-test'], $published, $this->admin);

        // ---------------------------------------------------------------------
        // 1. RETRYING dengan future next_retry_at -> rejected
        // ---------------------------------------------------------------------
        $sidecar1 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecar1->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_RETRYING,
            'next_retry_at' => now()->addMinutes(30),
            'locked_at'     => null,
            'locked_by'     => null,
            'attempt_count' => 1,
        ]);
        $callsBefore1 = $this->mockTransport->callCount;
        $res1 = $this->syncEngine->processSidecarSyncRecord($sidecar1, force: false);
        $this->assertFalse($res1, 'Item 1: Direct processSidecarSyncRecord on future next_retry_at must be rejected.');
        $this->assertEquals($callsBefore1, $this->mockTransport->callCount, 'Item 1: Zero remote transport calls.');
        $this->assertNull($sidecar1->fresh()->locked_by, 'Item 1: Lock must not be acquired.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $sidecar1->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 2. RETRYING dengan null next_retry_at -> rejected
        // ---------------------------------------------------------------------
        $sidecar1->update(['next_retry_at' => null]);
        $res2 = $this->syncEngine->processSidecarSyncRecord($sidecar1, force: false);
        $this->assertFalse($res2, 'Item 2: Direct processSidecarSyncRecord with null next_retry_at must be rejected.');
        $this->assertNull($sidecar1->fresh()->locked_by);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $sidecar1->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 3. RETRYING dengan due next_retry_at -> permitted
        // ---------------------------------------------------------------------
        $sidecar1->update([
            'next_retry_at'    => now()->subSeconds(10),
            'payload_checksum' => null,
        ]);
        $res3 = $this->syncEngine->processSidecarSyncRecord($sidecar1, force: false);
        $this->assertTrue($res3, 'Item 3: Direct processSidecarSyncRecord with due next_retry_at must succeed.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecar1->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 4. PENDING tanpa jadwal (null) -> permitted
        // ---------------------------------------------------------------------
        $sidecar4 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecar4->update([
            'sync_status'      => SpreadsheetSidecarSync::STATUS_PENDING,
            'next_retry_at'    => null,
            'locked_at'        => null,
            'locked_by'        => null,
            'payload_checksum' => null,
        ]);
        $res4 = $this->syncEngine->processSidecarSyncRecord($sidecar4, force: false);
        $this->assertTrue($res4, 'Item 4: PENDING without schedule must be permitted.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecar4->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 5. FAILED tanpa manual authorization -> rejected
        // ---------------------------------------------------------------------
        $sidecar5 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecar5->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_FAILED,
            'locked_at'   => null,
            'locked_by'   => null,
        ]);
        $res5Sidecar = $this->syncEngine->processSidecarSyncRecord($sidecar5, force: false);
        $this->assertFalse($res5Sidecar, 'Item 5: FAILED sidecar without authorization must be rejected.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_FAILED, $sidecar5->fresh()->sync_status);

        $mainSync->update([
            'sync_status' => SpreadsheetRowSync::STATUS_FAILED,
            'locked_at'   => null,
            'locked_by'   => null,
        ]);
        $res5Main = $this->syncEngine->processSyncRecord($mainSync, force: false);
        $this->assertFalse($res5Main, 'Item 5: FAILED main-row without authorization must be rejected.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $mainSync->fresh()->sync_status);
        $mainSync->update(['sync_status' => SpreadsheetRowSync::STATUS_SYNCED]);

        // ---------------------------------------------------------------------
        // 6. SYNCED dengan checksum identik -> idempotent no-op
        // ---------------------------------------------------------------------
        $reg6 = $this->createVerifiedAcceptedRegistration();
        $this->formBuilder->freezeSnapshot($reg6, $published);
        $this->formBuilder->saveCustomAnswers($reg6, ['r01b_github' => 'https://github.com/r01b-test-6'], $published, $this->admin);
        $mainSync6 = $this->syncEngine->queueSyncForRegistration($reg6);
        $this->syncEngine->processSyncRecord($mainSync6);

        $sidecar6 = $this->syncEngine->queueSidecarSyncForRegistration($reg6);
        $this->syncEngine->processSidecarSyncRecord($sidecar6);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecar6->fresh()->sync_status);

        $callsBefore6 = $this->mockTransport->callCount;
        $res6 = $this->syncEngine->processSidecarSyncRecord($sidecar6->fresh(), force: false);
        $this->assertTrue($res6, 'Item 6: SYNCED with identical checksum must be idempotent no-op.');
        $this->assertEquals($callsBefore6, $this->mockTransport->callCount, 'Item 6: Zero new remote calls.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecar6->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 7. Sidecar stale PROCESSING lease (>300s) -> recoverable
        // ---------------------------------------------------------------------
        $sidecar7 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $sidecar7->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now()->subSeconds(350),
            'locked_by'   => 'dead-worker-7',
        ]);
        $res7 = $this->syncEngine->processSidecarSyncRecord($sidecar7);
        $this->assertTrue($res7, 'Item 7: Expired processing lease must be recoverable.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_SYNCED, $sidecar7->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 8. Sidecar active PROCESSING lease (<300s) -> rejected
        // ---------------------------------------------------------------------
        $sidecar8 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $token8 = 'active-worker-8';
        $sidecar8->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $token8,
        ]);
        $res8 = $this->syncEngine->processSidecarSyncRecord($sidecar8);
        $this->assertFalse($res8, 'Item 8: Active processing lease must not be stolen.');
        $this->assertEquals($token8, $sidecar8->fresh()->locked_by);
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PROCESSING, $sidecar8->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 9. Main-row PROCESSING -> tidak dapat diambil alih tanpa valid pre-claimed fence
        // ---------------------------------------------------------------------
        $mainSync->update([
            'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'   => now()->subMinutes(10), // stale lock
            'locked_by'   => 'crashed-main-worker',
        ]);
        $res9 = $this->syncEngine->processSyncRecord($mainSync);
        $this->assertFalse($res9, 'Item 9: Main-row PROCESSING cannot be taken over without valid preClaimedFence.');
        $this->assertEquals('crashed-main-worker', $mainSync->fresh()->locked_by);
        $mainSync->update(['sync_status' => SpreadsheetRowSync::STATUS_SYNCED, 'locked_at' => null, 'locked_by' => null]);

        // ---------------------------------------------------------------------
        // 10. Serialized job failed() -> tidak mengganggu worker pengganti
        // ---------------------------------------------------------------------
        $sidecar10 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $job10 = new \App\Jobs\ProcessSidecarSyncJob($sidecar10->id);
        $serialized10 = serialize($job10);
        $token10A = $job10->fenceToken;

        $sidecar10->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now()->subMinutes(10),
            'locked_by'   => $token10A,
        ]);
        $token10B = 'replacement-worker-10b';
        $sidecar10->update([
            'sync_status' => SpreadsheetSidecarSync::STATUS_PROCESSING,
            'locked_at'   => now(),
            'locked_by'   => $token10B,
        ]);

        /** @var \App\Jobs\ProcessSidecarSyncJob $reconstructed10 */
        $reconstructed10 = unserialize($serialized10);
        $this->assertEquals($token10A, $reconstructed10->fenceToken);
        $reconstructed10->failed(new \RuntimeException("Worker 10A timed out"));

        $this->assertEquals($token10B, $sidecar10->fresh()->locked_by, 'Item 10: Reconstructed failed() must not wipe replacement lease.');
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_PROCESSING, $sidecar10->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 11. Duplicate queued job -> tidak mempercepat retry
        // ---------------------------------------------------------------------
        $sidecar11 = $this->syncEngine->queueSidecarSyncForRegistration($reg);
        $futureRetryTime = now()->addMinutes(20);
        $sidecar11->update([
            'sync_status'   => SpreadsheetSidecarSync::STATUS_RETRYING,
            'next_retry_at' => $futureRetryTime,
            'locked_at'     => null,
            'locked_by'     => null,
            'attempt_count' => 2,
        ]);
        $duplicateJob = new \App\Jobs\ProcessSidecarSyncJob($sidecar11->id);
        $duplicateJob->handle($this->syncEngine);

        $fresh11 = $sidecar11->fresh();
        $this->assertEquals(SpreadsheetSidecarSync::STATUS_RETRYING, $fresh11->sync_status, 'Item 11: Status must remain RETRYING.');
        $this->assertEquals(2, $fresh11->attempt_count, 'Item 11: Attempts must not increment.');
        $this->assertNull($fresh11->locked_by, 'Item 11: Lease must not be acquired.');
        $this->assertEquals($futureRetryTime->timestamp, $fresh11->next_retry_at->timestamp, 'Item 11: Schedule must not be accelerated.');

        // ---------------------------------------------------------------------
        // 12. Regresi R01A dan Wave 2C-A -> tetap PASS
        // ---------------------------------------------------------------------
        $this->test_r04_full_lock_safety_nonce_replay_and_time_window_matrix_t01_to_t20();
    }

    /**
     * S17.18G.6-B-R01C: Mandatory Direct Main-Row and Scanner Eligibility Matrix.
     */
    public function test_r01c_mandatory_main_row_and_scanner_eligibility_matrix(): void
    {
        $reg = $this->createVerifiedAcceptedRegistration();
        $mainSync = $this->syncEngine->queueSyncForRegistration($reg);

        $customTransport = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();

        // ---------------------------------------------------------------------
        // 1. RETRYING future + default transport + force false -> rejected
        // ---------------------------------------------------------------------
        $futureSchedule = now()->addMinutes(25);
        $mainSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at'    => $futureSchedule,
            'locked_at'        => null,
            'locked_by'        => null,
            'attempt_count'    => 1,
            'payload_checksum' => null,
            'row_index'        => null,
        ]);
        $callsBefore1 = $this->mockTransport->callCount;
        $res1 = $this->syncEngine->processSyncRecord($mainSync, force: false);
        $this->assertFalse($res1, 'Item 1: RETRYING future + default transport must be rejected.');
        $this->assertEquals($callsBefore1, $this->mockTransport->callCount, 'Item 1: Zero remote transport calls.');
        $this->assertNull($mainSync->fresh()->locked_by, 'Item 1: Lease must not be acquired.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $mainSync->fresh()->sync_status);
        $this->assertEquals($futureSchedule->timestamp, $mainSync->fresh()->next_retry_at->timestamp);

        // ---------------------------------------------------------------------
        // 2. RETRYING future + custom transport + force false -> rejected (No Custom Transport Bypass)
        // ---------------------------------------------------------------------
        $callsBefore2 = $customTransport->callCount;
        $res2 = $this->syncEngine->processSyncRecord($mainSync, $customTransport, force: false);
        $this->assertFalse($res2, 'Item 2: RETRYING future + custom transport must be rejected (no bypass).');
        $this->assertEquals($callsBefore2, $customTransport->callCount, 'Item 2: Zero custom transport calls.');
        $this->assertNull($mainSync->fresh()->locked_by, 'Item 2: Lease must not be acquired.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $mainSync->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 3. RETRYING null schedule + custom transport + force false -> rejected
        // ---------------------------------------------------------------------
        $mainSync->update(['next_retry_at' => null]);
        $callsBefore3 = $customTransport->callCount;
        $res3 = $this->syncEngine->processSyncRecord($mainSync, $customTransport, force: false);
        $this->assertFalse($res3, 'Item 3: RETRYING null schedule + custom transport must be rejected.');
        $this->assertEquals($callsBefore3, $customTransport->callCount, 'Item 3: Zero custom transport calls.');
        $this->assertNull($mainSync->fresh()->locked_by);
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $mainSync->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 4. PENDING future + attempt_count 0 -> rejected (No First-Attempt Bypass)
        // ---------------------------------------------------------------------
        $futurePending = now()->addMinutes(15);
        $mainSync->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at' => $futurePending,
            'attempt_count' => 0,
            'locked_at'     => null,
            'locked_by'     => null,
        ]);
        // Default transport
        $res4A = $this->syncEngine->processSyncRecord($mainSync, force: false);
        $this->assertFalse($res4A, 'Item 4A: PENDING future with attempt_count 0 must be rejected.');
        $this->assertNull($mainSync->fresh()->locked_by);
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $mainSync->fresh()->sync_status);

        // Custom transport
        $callsBefore4B = $customTransport->callCount;
        $res4B = $this->syncEngine->processSyncRecord($mainSync, $customTransport, force: false);
        $this->assertFalse($res4B, 'Item 4B: PENDING future with attempt_count 0 and custom transport must be rejected.');
        $this->assertEquals($callsBefore4B, $customTransport->callCount);
        $this->assertNull($mainSync->fresh()->locked_by);
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $mainSync->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 5. PENDING null schedule -> permitted
        // ---------------------------------------------------------------------
        $mainSync->update([
            'next_retry_at'    => null,
            'payload_checksum' => null,
            'row_index'        => null,
        ]);
        $res5 = $this->syncEngine->processSyncRecord($mainSync, $customTransport, force: false);
        $this->assertTrue($res5, 'Item 5: PENDING with null schedule must be permitted.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);
        $this->assertNotNull($mainSync->fresh()->payload_checksum);
        $this->assertNotNull($mainSync->fresh()->row_index);

        // ---------------------------------------------------------------------
        // 6. RETRYING due (<= now()) -> permitted
        // ---------------------------------------------------------------------
        $mainSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at'    => now()->subSeconds(30),
            'attempt_count'    => 1,
            'payload_checksum' => null,
            'locked_at'        => null,
            'locked_by'        => null,
        ]);
        $res6 = $this->syncEngine->processSyncRecord($mainSync, $customTransport, force: false);
        $this->assertTrue($res6, 'Item 6: RETRYING with due schedule must be permitted.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 7. FAILED tanpa manual authorization (force: false) -> rejected
        // ---------------------------------------------------------------------
        $mainSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_FAILED,
            'locked_at'        => null,
            'locked_by'        => null,
            'next_retry_at'    => null,
            'payload_checksum' => null,
        ]);
        $res7 = $this->syncEngine->processSyncRecord($mainSync, $customTransport, force: false);
        $this->assertFalse($res7, 'Item 7: FAILED record without force must be rejected.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $mainSync->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 8. Legitimate manual retry path (retrySyncRecord) -> permitted
        // ---------------------------------------------------------------------
        $res8 = $this->syncEngine->retrySyncRecord($mainSync);
        $this->assertTrue($res8, 'Item 8: Legitimate manual retry must succeed.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $mainSync->fresh()->sync_status);

        // ---------------------------------------------------------------------
        // 9. Scanner Consistency (scanAndDispatchPendingSyncs & recovery command dry-run)
        // ---------------------------------------------------------------------
        // Reset state for clean scanner testing
        SpreadsheetRowSync::query()->update(['sync_status' => SpreadsheetRowSync::STATUS_SYNCED]);

        $regA = $this->createVerifiedAcceptedRegistration();
        $syncA = $this->syncEngine->queueSyncForRegistration($regA);
        $syncA->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at' => null, // RETRYING null schedule -> DO NOT DISPATCH
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        $regB = $this->createVerifiedAcceptedRegistration();
        $syncB = $this->syncEngine->queueSyncForRegistration($regB);
        $syncB->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at' => now()->addHours(2), // RETRYING future schedule -> DO NOT DISPATCH
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        $regC = $this->createVerifiedAcceptedRegistration();
        $syncC = $this->syncEngine->queueSyncForRegistration($regC);
        $syncC->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at' => null, // PENDING null schedule -> MUST DISPATCH
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        $regD = $this->createVerifiedAcceptedRegistration();
        $syncD = $this->syncEngine->queueSyncForRegistration($regD);
        $syncD->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_RETRYING,
            'next_retry_at' => now()->subMinutes(2), // RETRYING due schedule -> MUST DISPATCH
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        // A. Test recovery command in dry-run mode
        $this->artisan('sertif:spreadsheet-sync:recover', ['--dry-run' => true])
            ->expectsOutputToContain('Ditemukan 2 baris spreadsheet utama yang memenuhi syarat pemulihan.')
            ->assertExitCode(0);

        // B. Test scanner dispatch
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessSpreadsheetRowSyncJob::class]);
        $dispatchedCount = $this->syncEngine->scanAndDispatchPendingSyncs(50);
        $this->assertEquals(2, $dispatchedCount, 'Item 9: Scanner must dispatch exactly 2 records.');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessSpreadsheetRowSyncJob::class, function ($job) use ($syncC) {
            return $job->rowSyncId === $syncC->id;
        });
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessSpreadsheetRowSyncJob::class, function ($job) use ($syncD) {
            return $job->rowSyncId === $syncD->id;
        });
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\ProcessSpreadsheetRowSyncJob::class, function ($job) use ($syncA) {
            return $job->rowSyncId === $syncA->id;
        });
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\ProcessSpreadsheetRowSyncJob::class, function ($job) use ($syncB) {
            return $job->rowSyncId === $syncB->id;
        });

        // ---------------------------------------------------------------------
        // 10. Regresi Wave 2C-A, R01A, dan R01B tetap PASS
        // ---------------------------------------------------------------------
        $this->test_r04_full_lock_safety_nonce_replay_and_time_window_matrix_t01_to_t20();
    }

    /**
     * Wave 2C-C Comprehensive Test Matrix (C01 to C25)
     * Validates Year Tab Routing, FormSchema Preservation, Apps Script Row 12 Dynamic Resolution,
     * Case-Variant and Reserved Keys Protection, Partial Update Historical Answer Preservation,
     * and End-to-End Regression.
     */
    public function test_wave2cc_comprehensive_matrix_c01_to_c25(): void
    {
        // ---------------------------------------------------------------------
        // C01 & C02: Registration Year Tab Routing (2026 vs 2027)
        // ---------------------------------------------------------------------
        $reg2026 = $this->createVerifiedAcceptedRegistration();
        $reg2026->created_at = Carbon::create(2026, 3, 15, 10, 0, 0);
        $reg2026->save();
        RegistrationCustomAnswer::create([
            'registration_id' => $reg2026->id,
            'field_key'       => 'git_repo',
            'answer_value'    => 'https://github.com/test/repo-2026',
        ]);

        $mainSync2026 = $this->syncEngine->queueSyncForRegistration($reg2026);
        $this->assertNotNull($mainSync2026);
        $this->assertSame('2026', $mainSync2026->target_sheet_tab, 'C01: Main sync routes to 2026 tab.');

        $sidecarSync2026 = $this->syncEngine->queueSidecarSyncForRegistration($reg2026);
        $this->assertNotNull($sidecarSync2026);
        $this->assertSame('2026', $sidecarSync2026->main_sheet_tab, 'C01: Sidecar main tab is 2026.');
        $this->assertSame('2026_Custom_Fields', $sidecarSync2026->extension_sheet_tab, 'C01: Extension tab is 2026_Custom_Fields.');

        $reg2027 = $this->createVerifiedAcceptedRegistration();
        $reg2027->created_at = Carbon::create(2027, 4, 10, 8, 30, 0);
        $reg2027->save();
        RegistrationCustomAnswer::create([
            'registration_id' => $reg2027->id,
            'field_key'       => 'git_repo',
            'answer_value'    => 'https://github.com/test/repo-2027',
        ]);

        $mainSync2027 = $this->syncEngine->queueSyncForRegistration($reg2027);
        $this->assertNotNull($mainSync2027);
        $this->assertSame('2027', $mainSync2027->target_sheet_tab, 'C02: Main sync routes to 2027 tab.');

        $sidecarSync2027 = $this->syncEngine->queueSidecarSyncForRegistration($reg2027);
        $this->assertNotNull($sidecarSync2027);
        $this->assertSame('2027', $sidecarSync2027->main_sheet_tab, 'C02: Sidecar main tab is 2027.');
        $this->assertSame('2027_Custom_Fields', $sidecarSync2027->extension_sheet_tab, 'C02: Extension tab is 2027_Custom_Fields.');

        // ---------------------------------------------------------------------
        // C03: Invalid Year Rejected Fail-Closed (< 2020 or > 2099)
        // ---------------------------------------------------------------------
        $regTooOld = $this->createVerifiedAcceptedRegistration();
        $regTooOld->created_at = Carbon::create(2018, 1, 1);
        $regTooOld->save();
        RegistrationCustomAnswer::create([
            'registration_id' => $regTooOld->id,
            'field_key'       => 'git_repo',
            'answer_value'    => 'https://github.com/test/repo-old',
        ]);
        $this->assertNull($this->syncEngine->queueSyncForRegistration($regTooOld), 'C03: Main sync rejects year < 2020.');
        $this->assertNull($this->syncEngine->queueSidecarSyncForRegistration($regTooOld), 'C03: Sidecar sync rejects year < 2020.');

        $regTooFar = $this->createVerifiedAcceptedRegistration();
        RegistrationCustomAnswer::create([
            'registration_id' => $regTooFar->id,
            'field_key'       => 'git_repo',
            'answer_value'    => 'https://github.com/test/repo-far',
        ]);
        $regTooFar->created_at = Carbon::create(2105, 5, 5);
        $this->assertNull($this->syncEngine->queueSyncForRegistration($regTooFar), 'C03: Main sync rejects year > 2099.');
        $this->assertNull($this->syncEngine->queueSidecarSyncForRegistration($regTooFar), 'C03: Sidecar sync rejects year > 2099.');

        // ---------------------------------------------------------------------
        // C04: start_date vs created_at does NOT override registration year tab
        // ---------------------------------------------------------------------
        $regCrossYear = $this->createVerifiedAcceptedRegistration();
        $regCrossYear->created_at = Carbon::create(2026, 12, 1);
        $regCrossYear->start_date = Carbon::create(2027, 2, 1);
        $regCrossYear->save();
        RegistrationCustomAnswer::create([
            'registration_id' => $regCrossYear->id,
            'field_key'       => 'git_repo',
            'answer_value'    => 'https://github.com/test/repo-cross',
        ]);

        $crossMain = $this->syncEngine->queueSyncForRegistration($regCrossYear);
        $crossSidecar = $this->syncEngine->queueSidecarSyncForRegistration($regCrossYear);
        $this->assertSame('2026', $crossMain->target_sheet_tab, 'C04: Main tab derives strictly from created_at, ignoring start_date.');
        $this->assertSame('2026', $crossSidecar->main_sheet_tab, 'C04: Sidecar main tab derives strictly from created_at.');
        $this->assertSame('2026_Custom_Fields', $crossSidecar->extension_sheet_tab, 'C04: Sidecar extension tab derives strictly from created_at.');

        // ---------------------------------------------------------------------
        // C05: FormSchema matches year and snapshot
        // ---------------------------------------------------------------------
        $snapshot = RegistrationFormSnapshot::create([
            'registration_id'     => $reg2026->id,
            'form_schema_version' => 'v2026.1',
            'schema_snapshot'     => ['fields' => [['field_key' => 'git_repo', 'label' => 'Git Repository']]],
            'submitted_at'        => now(),
        ]);
        $payload = $this->mappingService->buildSidecarPayload($reg2026->fresh());
        $this->assertSame('v2026.1', $payload['form_schema_version'], 'C05: Sidecar payload reflects form snapshot schema version.');

        // ---------------------------------------------------------------------
        // C12, C13, C14: FormSchema Immutability, Labels, and Historical Data Preservation
        // ---------------------------------------------------------------------
        $schema = FormSchema::create([
            'year'         => 2026,
            'version_code' => 'v2026.2',
            'title'        => 'Formulir Pendaftaran 2026 v2',
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => true,
        ]);
        $field = FormField::create([
            'form_schema_id' => $schema->id,
            'field_key'      => 'project_notes',
            'label'          => 'Initial Notes',
            'field_type'     => 'textarea',
            'is_required'    => false,
            'is_active'      => true,
        ]);

        // C12: Field label update does not change field_key
        $field->update(['label' => 'Updated Project Notes']);
        $this->assertSame('project_notes', $field->fresh()->field_key, 'C12: Label updates preserve immutable field_key.');

        // C13: Archiving field retains existing custom answers
        $customAnswer = RegistrationCustomAnswer::create([
            'registration_id' => $reg2026->id,
            'field_key'       => 'project_notes',
            'answer_value'    => 'Historical custom value',
        ]);
        $field->delete();
        $this->assertTrue(RegistrationCustomAnswer::where('id', $customAnswer->id)->exists(), 'C13: Archived fields retain historical answers.');

        // C14: New schema version does not corrupt earlier snapshots
        $newSchema = FormSchema::create([
            'year'         => 2027,
            'version_code' => 'v2027.1',
            'title'        => 'Formulir Pendaftaran 2027 v1',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);
        $this->assertSame('v2026.1', $snapshot->fresh()->form_schema_version, 'C14: New schemas do not mutate prior snapshots.');

        // ---------------------------------------------------------------------
        // C06–C11, C15–C19: Apps Script Dynamic Header Resolution, Reserved Keys,
        // Case Invariance, and Partial Update Historical Value Preservation
        // ---------------------------------------------------------------------
        $nodeAppsScript = <<<'JS'
const crypto = require("crypto");
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const SECRET = "wave2cc_test_secret_key_123456789";
const REG_ID = "test-spreadsheet-id-2cc";

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => {
      if (k === "SHARED_API_KEY") return SECRET;
      if (k === "REGISTRATION_SPREADSHEET_ID") return REG_ID;
      return null;
    }
  })
};

global.LockService = {
  getScriptLock: () => ({
    tryLock: () => true,
    waitLock: () => true,
    hasLock: () => true,
    releaseLock: () => {}
  })
};

const cacheStore = {};
global.CacheService = {
  getScriptCache: () => ({
    get: (k) => cacheStore[k] || null,
    put: (k, v, ttl) => { cacheStore[k] = v; }
  })
};

global.Utilities = {
  Charset: { UTF_8: "UTF_8" },
  computeHmacSha256Signature: (data, key) => {
    const hmac = crypto.createHmac("sha256", key);
    hmac.update(data, "utf8");
    const digest = hmac.digest();
    return Array.from(digest).map(b => (b > 127 ? b - 256 : b));
  }
};

function signPayload(payload) {
  const ts = Math.floor(Date.now() / 1000);
  const nonce = "nonce_" + Math.random().toString(36).substring(2);
  const canonicalData = ts + "." + nonce + "." + JSON.stringify(payload);
  const sig = crypto.createHmac("sha256", SECRET).update(canonicalData, "utf8").digest("hex");
  return {
    postData: {
      contents: JSON.stringify({
        timestamp: ts,
        nonce: nonce,
        signature: sig,
        payload: payload
      })
    }
  };
}

// In-memory mock sheet store for dynamic verification
const sheetData = {
  "2026": {
    // Standard layout: Rows 1-11 are title/metadata. Row 12 has headers. Row 13 has data.
    rows: []
  },
  "2026_Custom_Fields": {
    rows: [
      ["SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "FORM_SCHEMA_VERSION", "SERTIF_LAST_SYNC_AT", "custom_portfolio_url", "custom_notes"],
      ["SYNC-2026-001", "101", "v2026.1", "2026-03-15T10:00:00Z", "https://github.com/myrepo", "Preserved Original Note"]
    ]
  }
};

// Initialize row 1-11 as metadata, row 12 as headers, row 13 as data
for (let r = 1; r <= 11; r++) {
  sheetData["2026"].rows.push(["TITLE_OR_METADATA_ROW_" + r]);
}
// Row 12: Headers (Col 1: NO, Col 2: NAMA, ..., Col 5: SERTIF_SYNC_ID, Col 6: SERTIF_REGISTRATION_ID)
sheetData["2026"].rows.push(["NO", "NAMA LENGKAP", "ASAL SEKOLAH", "EMAIL", "SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID"]);
// Row 13: Data row for registration 101
sheetData["2026"].rows.push(["1", "Budi Santoso", "SMK 1", "budi@example.com", "SYNC-2026-001", "101"]);

function getMockSheet(name) {
  if (!sheetData[name]) {
    sheetData[name] = { rows: [] };
  }
  const s = sheetData[name];
  return {
    getLastRow: () => s.rows.length,
    getLastColumn: () => {
      let maxCols = 0;
      for (const row of s.rows) {
        if (row.length > maxCols) maxCols = row.length;
      }
      return maxCols;
    },
    getRange: (r, c, numR, numC) => {
      numR = numR || 1;
      numC = numC || 1;
      return {
        getValues: () => {
          const res = [];
          for (let ro = 0; ro < numR; ro++) {
            const rowIndex = (r - 1) + ro;
            const rowArr = s.rows[rowIndex] || [];
            const rowSlice = [];
            for (let co = 0; co < numC; co++) {
              const colIndex = (c - 1) + co;
              rowSlice.push(rowArr[colIndex] !== undefined ? rowArr[colIndex] : "");
            }
            res.push(rowSlice);
          }
          return res;
        },
        getValue: () => {
          const rowArr = s.rows[r - 1] || [];
          return rowArr[c - 1] !== undefined ? rowArr[c - 1] : "";
        },
        setValue: (val) => {
          while (s.rows.length < r) s.rows.push([]);
          while (s.rows[r - 1].length < c) s.rows[r - 1].push("");
          s.rows[r - 1][c - 1] = val;
        },
        setValues: (vals) => {
          for (let ro = 0; ro < vals.length; ro++) {
            const targetRow = (r - 1) + ro;
            while (s.rows.length <= targetRow) s.rows.push([]);
            for (let co = 0; co < vals[ro].length; co++) {
              const targetCol = (c - 1) + co;
              while (s.rows[targetRow].length <= targetCol) s.rows[targetRow].push("");
              s.rows[targetRow][targetCol] = vals[ro][co];
            }
          }
        }
      };
    }
  };
}

global.SpreadsheetApp = {
  openById: () => ({
    getSheetByName: (n) => getMockSheet(n),
    insertSheet: (n) => getMockSheet(n)
  }),
  flush: () => {}
};

function getResponse(res) {
  if (res && res.data) return res.data;
  if (res && typeof res.getContent === "function") return JSON.parse(res.getContent());
  if (res && res.body) return JSON.parse(res.body);
  return res;
}

const results = {};

// C06 & C19: Main sheet header on row 12 resolved correctly & partial update preserves historical cell value
const partialUpdateReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "2026",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "SYNC-2026-001",
  registration_id: "101",
  form_schema_version: "v2026.1",
  answers: {
    // custom_notes is omitted (undefined) -> MUST preserve "Preserved Original Note"
    custom_portfolio_url: "https://github.com/myrepo-updated"
  }
});
const partialUpdateRes = getResponse(appsScript.doPost(partialUpdateReq));
results.c06_resolved = partialUpdateRes.success === true && partialUpdateRes.action === "updated";

// Check row 2 in 2026_Custom_Fields
const updatedRow = sheetData["2026_Custom_Fields"].rows[1];
// Header order: SERTIF_SYNC_ID, SERTIF_REGISTRATION_ID, FORM_SCHEMA_VERSION, SERTIF_LAST_SYNC_AT, custom_portfolio_url, custom_notes
results.c19_portfolio_updated = updatedRow[4] === "https://github.com/myrepo-updated";
results.c19_notes_preserved = updatedRow[5] === "Preserved Original Note";

// C08, C09, C10, C11: Ensure extension columns protection
const ensureReq1 = signPayload({
  action: "ensure_extension_columns",
  spreadsheet_id: REG_ID,
  sheet_name: "2026_Custom_Fields",
  columns: [
    "custom_extra_field",      // new column -> must add
    "custom_portfolio_url",    // already exists -> must NOT duplicate
    "Custom_Portfolio_Url",    // case-variant -> must NOT duplicate
    "sertif_sync_status",      // reserved key -> must NOT add
    "timestamp",               // reserved key -> must NOT add
    "upload_pdf",              // reserved key -> must NOT add
    "ket"                      // reserved key -> must NOT add
  ]
});
const ensureRes1 = getResponse(appsScript.doPost(ensureReq1));
results.c08_new_column_added = ensureRes1.success && ensureRes1.added_columns.includes("custom_extra_field");
results.c09_existing_not_duplicated = !ensureRes1.added_columns.includes("custom_portfolio_url");
results.c10_case_variant_not_duplicated = !ensureRes1.added_columns.includes("Custom_Portfolio_Url");
results.c11_reserved_filtered = !ensureRes1.added_columns.includes("sertif_sync_status")
  && !ensureRes1.added_columns.includes("timestamp")
  && !ensureRes1.added_columns.includes("upload_pdf")
  && !ensureRes1.added_columns.includes("ket");

// C07: Main-row identity verified without unsafe column fallback
sheetData["empty_headers_tab"] = {
  rows: [
    ["RANDOM_COL_1", "RANDOM_COL_2", "RANDOM_COL_3"],
    ["VAL_1", "VAL_2", "VAL_3"]
  ]
};
const missingHeaderReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "empty_headers_tab",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "SYNC-2026-001",
  registration_id: "101",
  answers: {}
});
const missingHeaderRes = getResponse(appsScript.doPost(missingHeaderReq));
results.c07_no_fallback_reject = missingHeaderRes.success === false
  && (missingHeaderRes.error_code === "MAIN_SYNC_ID_COLUMN_MISSING" || missingHeaderRes.error_code === "MAIN_HEADER_ROW_NOT_FOUND");

// C17 & C18: Upsert produces updated row, not duplicate
const c17RowBeforeCount = sheetData["2026_Custom_Fields"].rows.length;
const retryUpsertReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "2026",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "SYNC-2026-001",
  registration_id: "101",
  form_schema_version: "v2026.1",
  answers: {
    custom_portfolio_url: "https://github.com/myrepo-retry"
  }
});
const retryUpsertRes = getResponse(appsScript.doPost(retryUpsertReq));
const c17RowAfterCount = sheetData["2026_Custom_Fields"].rows.length;
results.c17_no_duplicate_row = retryUpsertRes.success === true
  && retryUpsertRes.action === "updated"
  && c17RowBeforeCount === c17RowAfterCount;

console.log(JSON.stringify(results));
JS;

        $process = new Process(['node']);
        $process->setInput($nodeAppsScript);
        $process->mustRun();
        $nodeOutput = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($nodeOutput['c06_resolved'], 'C06: Main sheet header on row 12 resolved correctly.');
        $this->assertTrue($nodeOutput['c19_portfolio_updated'], 'C19: Updated field value was written to sheet.');
        $this->assertTrue($nodeOutput['c19_notes_preserved'], 'C19: Historical unmentioned answer preserved on partial update.');
        $this->assertTrue($nodeOutput['c08_new_column_added'], 'C08: New extension column added exactly once.');
        $this->assertTrue($nodeOutput['c09_existing_not_duplicated'], 'C09: Existing column not re-added.');
        $this->assertTrue($nodeOutput['c10_case_variant_not_duplicated'], 'C10: Case-variant column prevented.');
        $this->assertTrue($nodeOutput['c11_reserved_filtered'], 'C11: Reserved columns prevented from addition.');
        $this->assertTrue($nodeOutput['c07_no_fallback_reject'], 'C07: Main-row identity verified without unsafe fallback.');
        $this->assertTrue($nodeOutput['c17_no_duplicate_row'], 'C17: Retry upsert does not create duplicate row.');

        // ---------------------------------------------------------------------
        // C15, C16, C20, C21, C22, C23, C24, C25: Suite-Level Integrations & Regressions
        // ---------------------------------------------------------------------
        // C15 & C16 verified by existing test:
        $this->test_r02_sidecar_duplicate_and_identity_mismatch_prevent_header_modification();

        // C20: Legacy placeholder policy
        $this->test_legacy_registration_creates_sidecar_placeholder_row();

        // C21: Sidecar held if main row not synced
        $this->mockTransport->callCount = 0;
        $this->test_sidecar_sync_held_fail_closed_if_main_row_not_synced();

        // C24: HMAC, nonce replay & window alignment
        $this->test_r04_full_lock_safety_nonce_replay_and_time_window_matrix_t01_to_t20();

        // C25: Unrelated year tabs untouched
        $this->assertDatabaseMissing('spreadsheet_row_syncs', [
            'syncable_id'      => $reg2026->id,
            'target_sheet_tab' => '2027',
        ]);
        $this->assertDatabaseMissing('spreadsheet_sidecar_syncs', [
            'internship_registration_id' => $reg2026->id,
            'main_sheet_tab'             => '2027',
        ]);
    }

    /**
     * SERTIF Wave 2C-C R02 Mandatory Test Matrix (Items 1 to 20)
     * Verifies:
     * 1. Main upsert 2026 accepted with valid tab and schema.
     * 2. Main upsert 2027 accepted with valid tab and schema.
     * 3. Main update-only 2027 accepted with valid row and identity.
     * 4. Non-numeric or invalid year tab name rejected.
     * 5. Missing tab not auto-created.
     * 6. Schema mismatch fails closed.
     * 7. 'Ukuran Kaos' and 'ukuran_kaos' safely reconciled without duplicate columns.
     * 8. Distinct historical fields not incorrectly merged.
     * 9. Reserved keys with spelling/case variations rejected.
     * 10. Swapped base columns A-D on existing sheet rejected without mutation.
     * 11. Base columns on new sheet created strictly to contract.
     * 12. Main header split across two rows fails closed without cross-row mixing.
     * 13. Complete header on Row 12 processed correctly.
     * 14. Complete header on Row 1 legacy processed to contract.
     * 15. Identity mismatch does not modify header or data.
     * 16. Duplicate sync ID rejected.
     * 17. Partial update preserves historical cell value.
     * 18. Remote retry remains idempotent.
     * 19. Security HMAC and nonce TTL 900s pass.
     * 20. Wave 2C-B regression remains passing.
     */
    public function test_wave2cc_r02_comprehensive_matrix_items_01_to_20(): void
    {
        $nodeTestScript = <<<'JS'
const crypto = require("crypto");
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const SECRET = "wave2cc_r02_matrix_secret_key_987654321";
const REG_ID = "test-spreadsheet-id-r02-matrix";

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => {
      if (k === "SHARED_API_KEY") return SECRET;
      if (k === "REGISTRATION_SPREADSHEET_ID") return REG_ID;
      return null;
    }
  })
};

global.LockService = {
  getScriptLock: () => ({
    tryLock: () => true,
    waitLock: () => true,
    hasLock: () => true,
    releaseLock: () => {}
  })
};

const cacheStore = {};
global.CacheService = {
  getScriptCache: () => ({
    get: (k) => cacheStore[k] || null,
    put: (k, v, ttl) => { cacheStore[k] = v; }
  })
};

global.Utilities = {
  Charset: { UTF_8: "UTF_8" },
  computeHmacSha256Signature: (data, key) => {
    const hmac = crypto.createHmac("sha256", key);
    hmac.update(data, "utf8");
    const digest = hmac.digest();
    return Array.from(digest).map(b => (b > 127 ? b - 256 : b));
  }
};

function signPayload(payload, tsOffset, customNonce) {
  const ts = Math.floor(Date.now() / 1000) + (tsOffset || 0);
  const nonce = customNonce || ("nonce_" + Math.random().toString(36).substring(2));
  const canonicalData = ts + "." + nonce + "." + JSON.stringify(payload);
  const sig = crypto.createHmac("sha256", SECRET).update(canonicalData, "utf8").digest("hex");
  return {
    postData: {
      contents: JSON.stringify({
        timestamp: ts,
        nonce: nonce,
        signature: sig,
        payload: payload
      })
    }
  };
}

function getResponse(res) {
  if (res && res.data) return res.data;
  if (res && typeof res.getContent === "function") return JSON.parse(res.getContent());
  if (res && res.body) return JSON.parse(res.body);
  return res;
}

// 29-column Canonical Registration Contract Headers
const regContract29Headers = [
  "Timestamp", "Nama Lengkap", "NIM (Nomor Induk Mahasiswa)", "Program Studi", "Email",
  "Jenis Kelamin", "No. HP Aktif yang bisa dihubungi dan chat WA", "Asal Sekolah/Kampus",
  "Nama Kota/Daerah tempat tinggal Anda saat ini", "Mengapa Anda ingin Magang/PKL disini?",
  "Jenis Magang apa yang Anda pilih?", "Status Anda saat ini",
  "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?",
  "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan",
  "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)",
  "Apakah ada laptop sendiri", "Jika YA, alat apa yang dimiliki/dibawa",
  "Kapan berencana mulai Magang/PKL", "Berapa lama akan Magang/PKL", "Sumber info magang",
  "Instagram", "Butuh info kost/kontrakan", "Program Magang/PKL yang diminati",
  "Upload PDF CV + scan KTM/KTP + portofolio max 10MB", "KET",
  "SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "SERTIF_SYNC_STATUS", "SERTIF_LAST_SYNC_AT"
];

function createValid29RowData(syncId, regId) {
  return {
    "Timestamp": "2026-03-15 10:00:00",
    "Nama Lengkap": "Test User " + regId,
    "NIM (Nomor Induk Mahasiswa)": "12345678",
    "Program Studi": "Teknik Informatika",
    "Email": "user" + regId + "@example.com",
    "Jenis Kelamin": "Laki-laki",
    "No. HP Aktif yang bisa dihubungi dan chat WA": "081234567890",
    "Asal Sekolah/Kampus": "Universitas Indonesia",
    "Nama Kota/Daerah tempat tinggal Anda saat ini": "Jakarta",
    "Mengapa Anda ingin Magang/PKL disini?": "Belajar industri",
    "Jenis Magang apa yang Anda pilih?": "Magang Reguler",
    "Status Anda saat ini": "Mahasiswa",
    "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?": "Siap",
    "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan": "Dosen Pembimbing",
    "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)": "Sanggup",
    "Apakah ada laptop sendiri": "Ada",
    "Jika YA, alat apa yang dimiliki/dibawa": "Laptop i7",
    "Kapan berencana mulai Magang/PKL": "1 Maret 2026",
    "Berapa lama akan Magang/PKL": "6 Bulan",
    "Sumber info magang": "Website",
    "Instagram": "@testuser",
    "Butuh info kost/kontrakan": "Tidak",
    "Program Magang/PKL yang diminati": "Web Development",
    "Upload PDF CV + scan KTM/KTP + portofolio max 10MB": "https://drive.google.com/cv.pdf",
    "KET": "Lengkap",
    "SERTIF_SYNC_ID": syncId,
    "SERTIF_REGISTRATION_ID": regId,
    "SERTIF_SYNC_STATUS": "SYNCED",
    "SERTIF_LAST_SYNC_AT": "2026-03-15T10:00:00Z"
  };
}

// In-Memory Workbook Store
const sheetStore = {};

function initSheet(name, initialRows) {
  sheetStore[name] = { rows: initialRows ? JSON.parse(JSON.stringify(initialRows)) : [] };
}

function getMockSheet(name) {
  if (!sheetStore[name]) {
    sheetStore[name] = { rows: [] };
  }
  const s = sheetStore[name];
  return {
    getLastRow: () => s.rows.length,
    getLastColumn: () => {
      let maxCols = 0;
      for (const row of s.rows) {
        if (row.length > maxCols) maxCols = row.length;
      }
      return maxCols;
    },
    getRange: (r, c, numR, numC) => {
      numR = numR || 1;
      numC = numC || 1;
      return {
        getValues: () => {
          const res = [];
          for (let ro = 0; ro < numR; ro++) {
            const rowIndex = (r - 1) + ro;
            const rowArr = s.rows[rowIndex] || [];
            const rowSlice = [];
            for (let co = 0; co < numC; co++) {
              const colIndex = (c - 1) + co;
              rowSlice.push(rowArr[colIndex] !== undefined ? rowArr[colIndex] : "");
            }
            res.push(rowSlice);
          }
          return res;
        },
        getValue: () => {
          const rowArr = s.rows[r - 1] || [];
          return rowArr[c - 1] !== undefined ? rowArr[c - 1] : "";
        },
        setValue: (val) => {
          while (s.rows.length < r) s.rows.push([]);
          while (s.rows[r - 1].length < c) s.rows[r - 1].push("");
          s.rows[r - 1][c - 1] = val;
        },
        setValues: (vals) => {
          for (let ro = 0; ro < vals.length; ro++) {
            const targetRow = (r - 1) + ro;
            while (s.rows.length <= targetRow) s.rows.push([]);
            for (let co = 0; co < vals[ro].length; co++) {
              const targetCol = (c - 1) + co;
              while (s.rows[targetRow].length <= targetCol) s.rows[targetRow].push("");
              s.rows[targetRow][targetCol] = vals[ro][co];
            }
          }
        }
      };
    }
  };
}

global.SpreadsheetApp = {
  openById: () => ({
    getSheetByName: (n) => sheetStore.hasOwnProperty(n) ? getMockSheet(n) : null,
    insertSheet: (n) => {
      if (!sheetStore[n]) sheetStore[n] = { rows: [] };
      return getMockSheet(n);
    }
  }),
  flush: () => {}
};

// Setup initial sheets in workbook
// Tab 2026: Rows 1-11 title, Row 12 headers, Row 13 empty
const rows2026 = [];
for (let r = 1; r <= 11; r++) rows2026.push(["METADATA_2026_ROW_" + r]);
rows2026.push(regContract29Headers.slice());
initSheet("2026", rows2026);

// Tab 2027: Rows 1-11 title, Row 12 headers, Row 13 empty
const rows2027 = [];
for (let r = 1; r <= 11; r++) rows2027.push(["METADATA_2027_ROW_" + r]);
rows2027.push(regContract29Headers.slice());
initSheet("2027", rows2027);

const results = {};

// =========================================================================
// 1. Main upsert 2026 diterima bila tab dan schema valid
// =========================================================================
const upsert2026Payload = createValid29RowData("SYNC-2026-M1", "202601");
const upsert2026Req = signPayload({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "2026",
  sync_id: "SYNC-2026-M1",
  spreadsheet_id: REG_ID,
  payload: upsert2026Payload
});
const upsert2026Res = getResponse(appsScript.doPost(upsert2026Req));
results.item1_main_upsert_2026 = upsert2026Res.success === true && upsert2026Res.row_index === 13;

// =========================================================================
// 2. Main upsert 2027 diterima bila tab dan schema valid
// =========================================================================
const upsert2027Payload = createValid29RowData("SYNC-2027-M1", "202701");
const upsert2027Req = signPayload({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "2027",
  sync_id: "SYNC-2027-M1",
  spreadsheet_id: REG_ID,
  payload: upsert2027Payload
});
const upsert2027Res = getResponse(appsScript.doPost(upsert2027Req));
results.item2_main_upsert_2027 = upsert2027Res.success === true && upsert2027Res.row_index === 13;

// =========================================================================
// 3. Main update-only 2027 diterima bila row dan identitas valid
// =========================================================================
const update2027Payload = createValid29RowData("SYNC-2027-M1", "202701");
update2027Payload["Nama Lengkap"] = "Test User 202701 Updated";
const update2027Req = signPayload({
  action: "update_row",
  connection_key: "registration",
  tab_name: "2027",
  sync_id: "SYNC-2027-M1",
  spreadsheet_id: REG_ID,
  payload: update2027Payload
});
const update2027Res = getResponse(appsScript.doPost(update2027Req));
const row13UpdatedName = sheetStore["2027"].rows[12][1]; // Col 2 is Nama Lengkap
results.item3_main_update_2027 = update2027Res.success === true
  && update2027Res.operation === "updated"
  && row13UpdatedName === "Test User 202701 Updated";

// =========================================================================
// 4. Nama tab nonnumerik atau tahun tidak valid ditolak
// =========================================================================
const invalidTabAlphaReq = signPayload({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "202a",
  sync_id: "SYNC-FAIL",
  spreadsheet_id: REG_ID,
  payload: { "SERTIF_SYNC_ID": "SYNC-FAIL" }
});
const invalidTabAlphaRes = getResponse(appsScript.doPost(invalidTabAlphaReq));

const invalidTabYearUnderReq = signPayload({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "2019",
  sync_id: "SYNC-FAIL",
  spreadsheet_id: REG_ID,
  payload: { "SERTIF_SYNC_ID": "SYNC-FAIL" }
});
const invalidTabYearUnderRes = getResponse(appsScript.doPost(invalidTabYearUnderReq));

const invalidTabYearOverReq = signPayload({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "2100",
  sync_id: "SYNC-FAIL",
  spreadsheet_id: REG_ID,
  payload: { "SERTIF_SYNC_ID": "SYNC-FAIL" }
});
const invalidTabYearOverRes = getResponse(appsScript.doPost(invalidTabYearOverReq));

results.item4_invalid_tab_rejected = invalidTabAlphaRes.error_code === "TARGET_MISMATCH"
  && invalidTabYearUnderRes.error_code === "TARGET_MISMATCH"
  && invalidTabYearOverRes.error_code === "TARGET_MISMATCH";

// =========================================================================
// 5. Tab tidak ditemukan -> tidak dibuat otomatis
// =========================================================================
const missingTabReq = signPayload({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "2028",
  sync_id: "SYNC-2028-FAIL",
  spreadsheet_id: REG_ID,
  payload: createValid29RowData("SYNC-2028-FAIL", "202801")
});
const missingTabRes = getResponse(appsScript.doPost(missingTabReq));
results.item5_missing_tab_not_created = missingTabRes.error_code === "TAB_NOT_FOUND"
  && !sheetStore.hasOwnProperty("2028");

// =========================================================================
// 6. Schema utama tidak cocok -> fail-closed
// =========================================================================
initSheet("2030", [
  ["T1"], ["T2"], ["T3"], ["T4"], ["T5"], ["T6"], ["T7"], ["T8"], ["T9"], ["T10"], ["T11"],
  ["COL_1", "COL_2", "COL_3", "SERTIF_SYNC_ID"] // Only 4 columns, not 29!
]);
const mismatchSchemaReq = signPayload({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "2030",
  sync_id: "SYNC-2030-FAIL",
  spreadsheet_id: REG_ID,
  payload: createValid29RowData("SYNC-2030-FAIL", "203001")
});
const mismatchSchemaRes = getResponse(appsScript.doPost(mismatchSchemaReq));
results.item6_schema_mismatch_fail_closed = mismatchSchemaRes.error_code === "SCHEMA_MISMATCH"
  && sheetStore["2030"].rows.length === 12; // zero rows inserted

// =========================================================================
// 7. Ukuran Kaos dan ukuran_kaos direkonsiliasi tanpa kolom ganda
// =========================================================================
initSheet("2026_Custom_Fields", [
  ["SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "FORM_SCHEMA_VERSION", "SERTIF_LAST_SYNC_AT", "Ukuran Kaos"]
]);
const ensureKaosReq = signPayload({
  action: "ensure_extension_columns",
  spreadsheet_id: REG_ID,
  sheet_name: "2026_Custom_Fields",
  columns: ["ukuran_kaos"]
});
const ensureKaosRes = getResponse(appsScript.doPost(ensureKaosReq));
const colsAfterEnsure = sheetStore["2026_Custom_Fields"].rows[0].length;

const upsertKaosReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "2026",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "SYNC-2026-M1",
  registration_id: "202601",
  form_schema_version: "v2026.1",
  answers: { "ukuran_kaos": "XL" }
});
const upsertKaosRes = getResponse(appsScript.doPost(upsertKaosReq));
const rowKaosValues = sheetStore["2026_Custom_Fields"].rows[1];
const finalKaosColCount = sheetStore["2026_Custom_Fields"].rows[0].length;

results.item7_kaos_reconciled = ensureKaosRes.success === true
  && ensureKaosRes.added_columns.length === 0
  && colsAfterEnsure === 5
  && upsertKaosRes.success === true
  && finalKaosColCount === 5
  && rowKaosValues[4] === "XL";

// =========================================================================
// 8. Field historis dengan identitas berbeda tidak digabung secara salah
// =========================================================================
const ensureDistinctReq = signPayload({
  action: "ensure_extension_columns",
  spreadsheet_id: REG_ID,
  sheet_name: "2026_Custom_Fields",
  columns: ["ukuran_sepatu", "custom_notes"]
});
const ensureDistinctRes = getResponse(appsScript.doPost(ensureDistinctReq));
const distinctColsCount = sheetStore["2026_Custom_Fields"].rows[0].length;
results.item8_distinct_fields_not_merged = ensureDistinctRes.added_columns.includes("ukuran_sepatu")
  && ensureDistinctRes.added_columns.includes("custom_notes")
  && distinctColsCount === 7;

// =========================================================================
// 9. Reserved key dengan variasi penulisan ditolak
// =========================================================================
const ensureReservedReq = signPayload({
  action: "ensure_extension_columns",
  spreadsheet_id: REG_ID,
  sheet_name: "2026_Custom_Fields",
  columns: [
    "Nama Lengkap", "nama_lengkap", "NAMA_LENGKAP",
    "No. HP", "no_hp", "SERTIF_SYNC_ID",
    "timestamp", "Upload PDF", "Kesiapan WFO"
  ]
});
const ensureReservedRes = getResponse(appsScript.doPost(ensureReservedReq));
results.item9_reserved_variations_rejected = ensureReservedRes.added_columns.length === 0;

// =========================================================================
// 10. Base columns A-D tertukar pada sheet existing ditolak tanpa mutasi
// =========================================================================
initSheet("swapped_base_ext", [
  ["SERTIF_REGISTRATION_ID", "SERTIF_SYNC_ID", "FORM_SCHEMA_VERSION", "SERTIF_LAST_SYNC_AT"], // A and B swapped!
  ["202601", "SYNC-2026-M1", "v1", "2026-03-15T10:00:00Z"]
]);
const swappedEnsureReq = signPayload({
  action: "ensure_extension_columns",
  spreadsheet_id: REG_ID,
  sheet_name: "swapped_base_ext",
  columns: ["any_new_column"]
});
const swappedEnsureRes = getResponse(appsScript.doPost(swappedEnsureReq));
const swappedHeaderCol1 = sheetStore["swapped_base_ext"].rows[0][0];

const swappedUpsertReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "2026",
  extension_sheet_name: "swapped_base_ext",
  sync_id: "SYNC-2026-M1",
  registration_id: "202601",
  answers: {}
});
const swappedUpsertRes = getResponse(appsScript.doPost(swappedUpsertReq));

results.item10_swapped_base_rejected_no_mutation = swappedEnsureRes.error_code === "BASE_COLUMNS_MISMATCH"
  && swappedUpsertRes.error_code === "BASE_COLUMNS_MISMATCH"
  && swappedHeaderCol1 === "SERTIF_REGISTRATION_ID"; // Header NOT mutated!

// =========================================================================
// 11. Base columns pada sheet baru dibuat sesuai kontrak
// =========================================================================
const brandNewEnsureReq = signPayload({
  action: "ensure_extension_columns",
  spreadsheet_id: REG_ID,
  sheet_name: "brand_new_ext_sheet",
  columns: ["initial_field"]
});
const brandNewEnsureRes = getResponse(appsScript.doPost(brandNewEnsureReq));
const brandNewHeaders = sheetStore["brand_new_ext_sheet"].rows[0];
results.item11_brand_new_base_columns = brandNewEnsureRes.success === true
  && brandNewHeaders[0] === "SERTIF_SYNC_ID"
  && brandNewHeaders[1] === "SERTIF_REGISTRATION_ID"
  && brandNewHeaders[2] === "FORM_SCHEMA_VERSION"
  && brandNewHeaders[3] === "SERTIF_LAST_SYNC_AT"
  && brandNewHeaders[4] === "initial_field";

// =========================================================================
// 12. Header utama tersebar di dua baris -> fail-closed
// =========================================================================
initSheet("split_header_main", [
  ["SERTIF_SYNC_ID", "COL_B"],
  ["DATA_1"], ["DATA_2"], ["DATA_3"], ["DATA_4"], ["DATA_5"],
  ["DATA_6"], ["DATA_7"], ["DATA_8"], ["DATA_9"], ["DATA_10"],
  ["COL_A", "SERTIF_REGISTRATION_ID"]
]);
const splitHeaderCheck = appsScript.resolveMainSheetHeaderAndIndices(getMockSheet("split_header_main"), "split_header_main");
const splitUpsertReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "split_header_main",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "SYNC-2026-M1",
  registration_id: "202601",
  answers: {}
});
const splitUpsertRes = getResponse(appsScript.doPost(splitUpsertReq));

results.item12_split_header_fail_closed = splitHeaderCheck.error_code === "MAIN_HEADER_ROW_NOT_FOUND"
  && splitUpsertRes.error_code === "MAIN_HEADER_ROW_NOT_FOUND";

// =========================================================================
// 13. Header lengkap Row 12 diproses dengan benar
// =========================================================================
const row12Check = appsScript.resolveMainSheetHeaderAndIndices(getMockSheet("2026"), "2026");
results.item13_row12_resolved = row12Check.headerRow === 12
  && row12Check.dataStartRow === 13
  && row12Check.syncIdCol === 26
  && row12Check.regIdCol === 27;

// =========================================================================
// 14. Header lengkap Row 1 legacy diproses sesuai kontrak
// =========================================================================
initSheet("flat_legacy_tab", [
  ["NO", "NAMA", "SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "KET"],
  ["1", "Legacy User", "SYNC-LEG-1", "901", "Ok"]
]);
const legacyRow1Check = appsScript.resolveMainSheetHeaderAndIndices(getMockSheet("flat_legacy_tab"), "flat_legacy_tab");
results.item14_row1_legacy_resolved = legacyRow1Check.headerRow === 1
  && legacyRow1Check.dataStartRow === 2
  && legacyRow1Check.syncIdCol === 3
  && legacyRow1Check.regIdCol === 4;

// =========================================================================
// 15. Identity mismatch tidak memodifikasi header atau data
// =========================================================================
const mismatchExtColsBefore = sheetStore["2026_Custom_Fields"].rows[0].length;
const mismatchExtRowsBefore = sheetStore["2026_Custom_Fields"].rows.length;
const identityMismatchReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "2026",
  extension_sheet_name: "2026_Custom_Fields",
  sync_id: "SYNC-2026-M1",
  registration_id: "WRONG_REG_ID_9999",
  answers: { "should_not_be_added": "Danger" }
});
const identityMismatchRes = getResponse(appsScript.doPost(identityMismatchReq));
const mismatchExtColsAfter = sheetStore["2026_Custom_Fields"].rows[0].length;
const mismatchExtRowsAfter = sheetStore["2026_Custom_Fields"].rows.length;

results.item15_identity_mismatch_no_mutation = identityMismatchRes.error_code === "IDENTITY_MISMATCH"
  && mismatchExtColsBefore === mismatchExtColsAfter
  && mismatchExtRowsBefore === mismatchExtRowsAfter;

// =========================================================================
// 16. Duplicate sync ID tetap ditolak
// =========================================================================
// Insert second row with same sync ID into 2026
sheetStore["2026"].rows.push(sheetStore["2026"].rows[12].slice());
const dupUpdateReq = signPayload({
  action: "update_row",
  connection_key: "registration",
  tab_name: "2026",
  sync_id: "SYNC-2026-M1",
  spreadsheet_id: REG_ID,
  payload: upsert2026Payload
});
const dupUpdateRes = getResponse(appsScript.doPost(dupUpdateReq));
results.item16_duplicate_sync_id_rejected = dupUpdateRes.error_code === "DUPLICATE_SYNC_ID";

// =========================================================================
// 17. Partial update mempertahankan nilai historis
// =========================================================================
initSheet("partial_test_ext", [
  ["SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "FORM_SCHEMA_VERSION", "SERTIF_LAST_SYNC_AT", "portfolio_url", "special_note"],
  ["SYNC-PARTIAL-1", "REG-P1", "v1", "2026-01-01", "https://old.url", "Original Historical Note"]
]);
// Setup main row for SYNC-PARTIAL-1
initSheet("2026_P_MAIN", [
  ["T1"], ["T2"], ["T3"], ["T4"], ["T5"], ["T6"], ["T7"], ["T8"], ["T9"], ["T10"], ["T11"],
  regContract29Headers.slice(),
  ["", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "SYNC-PARTIAL-1", "REG-P1", "SYNCED", "2026-01-01"]
]);
const partialReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "2026_P_MAIN",
  extension_sheet_name: "partial_test_ext",
  sync_id: "SYNC-PARTIAL-1",
  registration_id: "REG-P1",
  form_schema_version: "v1",
  answers: {
    portfolio_url: "https://new.updated.url"
    // special_note is omitted!
  }
});
const partialRes = getResponse(appsScript.doPost(partialReq));
const partialRow = sheetStore["partial_test_ext"].rows[1];
results.item17_partial_update_preserves_history = partialRes.success === true
  && partialRow[4] === "https://new.updated.url"
  && partialRow[5] === "Original Historical Note";

// =========================================================================
// 18. Remote retry tetap idempotent
// =========================================================================
const retryReq = signPayload({
  action: "upsert_extension_row",
  spreadsheet_id: REG_ID,
  main_sheet_name: "2026_P_MAIN",
  extension_sheet_name: "partial_test_ext",
  sync_id: "SYNC-PARTIAL-1",
  registration_id: "REG-P1",
  form_schema_version: "v1",
  answers: {
    portfolio_url: "https://new.updated.url"
  }
});
const retryRes = getResponse(appsScript.doPost(retryReq));
const finalPartialRowCount = sheetStore["partial_test_ext"].rows.length;
results.item18_retry_idempotent = retryRes.success === true
  && retryRes.action === "updated"
  && finalPartialRowCount === 2;

// =========================================================================
// 19. Security HMAC dan nonce TTL 900 detik
// =========================================================================
const nonce1 = "test_nonce_r02_matrix_001";
const hmacReqValid = signPayload({ action: "ping" }, 0, nonce1);
const hmacResValid = getResponse(appsScript.doPost(hmacReqValid));

// Replay with identical nonce
const hmacReqReplay = signPayload({ action: "ping" }, 0, nonce1);
const hmacResReplay = getResponse(appsScript.doPost(hmacReqReplay));

// Timestamp skew > 900s
const hmacReqSkew = signPayload({ action: "ping" }, 1000, "nonce_skew_001");
const hmacResSkew = getResponse(appsScript.doPost(hmacReqSkew));

results.item19_hmac_and_nonce_ttl = hmacResValid.success === true
  && hmacResReplay.error_code === "REPLAYED_NONCE"
  && hmacResSkew.error_code === "EXPIRED_REQUEST"
  && cacheStore["nonce_" + nonce1] === "1";

console.log(JSON.stringify(results));
JS;

        $process = new Process(['node']);
        $process->setInput($nodeTestScript);
        $process->mustRun();
        $res = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        // Assert all matrix items 1 to 19
        $this->assertTrue($res['item1_main_upsert_2026'], 'Item 1: Main upsert 2026 accepted when tab and schema valid.');
        $this->assertTrue($res['item2_main_upsert_2027'], 'Item 2: Main upsert 2027 accepted when tab and schema valid.');
        $this->assertTrue($res['item3_main_update_2027'], 'Item 3: Main update-only 2027 accepted when row and identity valid.');
        $this->assertTrue($res['item4_invalid_tab_rejected'], 'Item 4: Non-numeric and invalid year tab names rejected fail-closed.');
        $this->assertTrue($res['item5_missing_tab_not_created'], 'Item 5: Missing main tab returns TAB_NOT_FOUND without auto-creation.');
        $this->assertTrue($res['item6_schema_mismatch_fail_closed'], 'Item 6: Main schema mismatch fails closed without inserting data.');
        $this->assertTrue($res['item7_kaos_reconciled'], 'Item 7: Ukuran Kaos and ukuran_kaos reconciled safely without duplicate columns.');
        $this->assertTrue($res['item8_distinct_fields_not_merged'], 'Item 8: Distinct historical fields not incorrectly merged.');
        $this->assertTrue($res['item9_reserved_variations_rejected'], 'Item 9: Reserved keys with spelling/case variations rejected.');
        $this->assertTrue($res['item10_swapped_base_rejected_no_mutation'], 'Item 10: Swapped base columns A-D rejected without header relabeling.');
        $this->assertTrue($res['item11_brand_new_base_columns'], 'Item 11: Base columns on new sheet created strictly to contract.');
        $this->assertTrue($res['item12_split_header_fail_closed'], 'Item 12: Split main headers fail closed without cross-row index mixing.');
        $this->assertTrue($res['item13_row12_resolved'], 'Item 13: Complete header on Row 12 resolved correctly.');
        $this->assertTrue($res['item14_row1_legacy_resolved'], 'Item 14: Complete header on Row 1 legacy resolved to contract.');
        $this->assertTrue($res['item15_identity_mismatch_no_mutation'], 'Item 15: Identity mismatch rejects without modifying header or data.');
        $this->assertTrue($res['item16_duplicate_sync_id_rejected'], 'Item 16: Duplicate sync ID rejected.');
        $this->assertTrue($res['item17_partial_update_preserves_history'], 'Item 17: Partial update preserves historical cell value.');
        $this->assertTrue($res['item18_retry_idempotent'], 'Item 18: Remote retry remains idempotent.');
        $this->assertTrue($res['item19_hmac_and_nonce_ttl'], 'Item 19: Security HMAC and nonce TTL 900s pass.');

        // =====================================================================
        // 20. Regresi Wave 2C-B tetap lulus
        // =====================================================================
        $this->test_sidecar_sync_succeeds_when_main_row_is_verified_synced();
        $this->test_active_processing_lease_cannot_be_stolen();
        $this->test_expired_processing_lease_can_be_reclaimed();
    }
}


