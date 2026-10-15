<?php

namespace Tests\Feature;

use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\RegistrationLifecycleService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AcceptanceGatedRegistrationSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pemagang;
    protected SpreadsheetConnection $regConnection;
    protected MockSpreadsheetTransport $mockTransport;
    protected RegistrationSpreadsheetMappingService $mappingService;
    protected SpreadsheetSyncEngineService $syncEngine;
    protected RegistrationLifecycleService $lifecycleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(Spreadsheet2026SchemaSeeder::class);

        $this->regConnection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Spreadsheet Pendaftaran Magang',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_REG_GATEWAY/exec',
                'spreadsheet_id'           => '1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug',
            ]
        );
        $this->regConnection->update([
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug/edit',
            'is_enabled'      => true,
        ]);

        $this->admin = User::create([
            'name'      => 'Admin SertIF',
            'email'     => 'admin_' . uniqid() . '@sertif.test',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Calon Pemagang',
            'email'     => 'pemagang_' . uniqid() . '@sertif.test',
            'password'  => bcrypt('password123'),
            'role'      => 'user',
            'is_banned' => false,
        ]);

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->mappingService = app(RegistrationSpreadsheetMappingService::class);
        $this->syncEngine = app(SpreadsheetSyncEngineService::class);
        $this->lifecycleService = app(RegistrationLifecycleService::class);
    }

    protected function createRegistration(array $attributes = []): InternshipRegistration
    {
        $institution = Institution::firstOrCreate(
            ['name' => 'Universitas Gadjah Mada'],
            ['type' => 'universitas']
        );
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $institution->id, 'name' => 'Fakultas MIPA']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Informatika']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        $reg = InternshipRegistration::create(array_merge([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $institution->id,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $prodi->id,
            'city_id'           => $city->id,
            'fullname'          => 'Budi Test',
            'student_id'        => '12345678',
            'gender'            => 'Laki-laki',
            'phone_number'      => '081234567890',
            'internship_reason' => 'Belajar Industri',
            'internship_type'   => 'Mandiri',
            'current_status'    => 'Mahasiswa',
            'wfo_readiness'     => 'Siap WFO',
            'has_laptop'        => true,
            'start_date'        => '2026-07-01',
            'planned_duration'  => '3 Bulan',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'is_draft'          => false,
        ], $attributes));

        if (!isset($attributes['created_at'])) {
            $reg->created_at = Carbon::create(2026, 3, 15, 10, 0, 0);
            $reg->save();
        }

        return $reg;
    }

    /**
     * 1. Pendaftaran baru berstatus waiting TIDAK mengantrekan sinkronisasi (0 sync record, 0 job).
     */
    public function test_waiting_registration_submission_does_not_queue_sync(): void
    {
        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);

        $this->assertFalse($this->mappingService->isEligibleForSync($reg));

        $syncRecord = $this->syncEngine->queueSyncForRegistration($reg);

        $this->assertNull($syncRecord);
        $this->assertEquals(0, SpreadsheetRowSync::count());
        Queue::assertNothingPushed();
    }

    /**
     * 2. Keputusan admin menerima pendaftaran (accepted) membuat sync record dan mendispatch job.
     */
    public function test_admin_transition_to_accepted_creates_sync_record(): void
    {
        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $reg = $this->createRegistration(['internship_status' => InternshipRegistration::STATUS_WAITING]);

        // Admin mengubah status ke accepted
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $reg->refresh();
        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $reg->internship_status);
        $this->assertTrue($this->mappingService->isEligibleForSync($reg));

        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $syncRecord->sync_status);
        $this->assertEquals('2026', $syncRecord->target_sheet_tab);

        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class);
    }

    /**
     * 3. Pendaftar accepted dengan konfirmasi sekolah/kampus masih menunggu jawaban tetap eligible.
     */
    public function test_accepted_registration_with_pending_school_confirmation_remains_eligible(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $this->assertTrue($this->mappingService->isEligibleForSync($reg));

        $payload = $this->mappingService->buildOutboundRowPayload($reg);
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload);
    }

    /**
     * 4. Pendaftar accepted dengan follow-up kampus belum ACC tetap eligible untuk sinkronisasi.
     */
    public function test_accepted_registration_with_school_not_yet_acc_remains_eligible(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        // Verifikasi tidak ada field follow up yang membatalkan status accepted
        $this->assertTrue($this->mappingService->isEligibleForSync($reg));
        $this->assertTrue($this->mappingService->hasVerifiedAdminAcceptance($reg));
    }

    /**
     * 5. Pendaftar yang ditolak sebelum diterima (rejected murni) strictly tidak eligible.
     */
    public function test_pure_rejected_registration_is_strictly_ineligible(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_REJECTED,
        ]);

        $this->assertFalse($this->mappingService->hasVerifiedAdminAcceptance($reg));
        $this->assertFalse($this->mappingService->isEligibleForSync($reg));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('NOT_ELIGIBLE_UNACCEPTED');
        $this->mappingService->buildOutboundRowPayload($reg);
    }

    /**
     * 6. Exited atau pembatalan pasca-acceptance dengan bukti sync lama eligible di bawah S17.18B update-only.
     */
    public function test_exited_or_cancelled_after_acceptance_with_prior_sync_is_eligible_under_update_only(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        // 1. Diterima oleh admin
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        // 2. Berjalan menjadi active
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACTIVE, isAdmin: true);

        // 3. Simulasikan pendaftar telah disinkronkan ke spreadsheet saat status accepted/active
        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);
        $syncRecord->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 14,
            'last_synced_at'   => now(),
            'payload_checksum' => 'checksum_abc_prior',
        ]);

        // 4. Kemudian keluar (exited)
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Pindah kota');

        $reg->refresh();
        $this->assertEquals(InternshipRegistration::STATUS_EXITED, $reg->internship_status);

        // Memiliki bukti riwayat admin acceptance yang valid dan bukti prior sync
        $this->assertTrue($this->mappingService->hasVerifiedAdminAcceptance($reg));
        $this->assertTrue($this->mappingService->hasVerifiedPriorSync($reg, $syncRecord));

        // S17.18B: Dengan jaminan remote update-only dan riwayat sync terverifikasi, eligible untuk sinkronisasi pembaruan status
        $this->assertTrue($this->mappingService->isEligibleForSync($reg, $syncRecord));

        $payload = $this->mappingService->buildOutboundRowPayload($reg, rowSync: $syncRecord);
        $this->assertNotEmpty($payload);
        $this->assertEquals('Keluar setelah mulai magang (belum selesai)', $payload['KET']);
    }

    /**
     * 7. Status active, completed, atau exited tanpa bukti riwayat penerimaan admin gagal-aman (ineligible).
     */
    public function test_active_or_completed_without_acceptance_proof_is_ineligible_fail_closed(): void
    {
        // Bypass data anomali: status active langsung di DB tanpa log penerimaan
        $regActive = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_ACTIVE,
        ]);
        $this->assertFalse($this->mappingService->hasVerifiedAdminAcceptance($regActive));
        $this->assertFalse($this->mappingService->isEligibleForSync($regActive));

        // Bypass data anomali: status completed langsung di DB tanpa log penerimaan
        $regCompleted = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);
        $this->assertFalse($this->mappingService->hasVerifiedAdminAcceptance($regCompleted));
        $this->assertFalse($this->mappingService->isEligibleForSync($regCompleted));

        // Bypass data anomali: status exited langsung di DB tanpa log penerimaan
        $regExited = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_EXITED,
        ]);
        $this->assertFalse($this->mappingService->hasVerifiedAdminAcceptance($regExited));
        $this->assertFalse($this->mappingService->isEligibleForSync($regExited));
    }

    /**
     * 8. Record lama dengan atribut synced/row_index tanpa bukti penerimaan tetap strictly ineligible.
     */
    public function test_legacy_synced_record_without_acceptance_proof_is_ineligible(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-legacy',
            'target_sheet_tab'          => '2026',
            'row_index'                 => 15,
            'last_synced_at'            => now()->subDays(5),
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            'payload_checksum'          => 'legacy_dummy_hash',
        ]);

        $this->assertFalse($this->mappingService->hasVerifiedAdminAcceptance($reg));
        $this->assertFalse($this->mappingService->isEligibleForSync($reg, $syncRecord));
    }

    /**
     * 9. Background job lama untuk pendaftar waiting langsung gagal sebelum transport HTTP.
     */
    public function test_stray_job_for_waiting_registration_fails_closed_before_http(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-stray',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at'             => now(),
        ]);

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertStringContainsString('NOT_ELIGIBLE_UNACCEPTED', (string) $syncRecord->error_message);
        $this->assertNull($syncRecord->next_retry_at);
        $this->assertNull($syncRecord->locked_at);
        $this->assertNull($syncRecord->locked_by);

        // Verifikasi transport HTTP sama sekali tidak dipanggil (0 outbound call)
        $this->assertEmpty($this->mockTransport->callHistory);
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * 10. Manual retry oleh Admin pada record waiting ditolak dengan aman tanpa HTTP.
     */
    public function test_manual_retry_on_waiting_registration_is_safely_rejected_without_http(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-retry',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => 'Old error',
        ]);

        $retried = $this->syncEngine->retrySyncRecord($syncRecord, $this->admin, $this->mockTransport);

        $this->assertFalse($retried);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertStringContainsString('NOT_ELIGIBLE_UNACCEPTED', (string) $syncRecord->error_message);
        $this->assertNull($syncRecord->next_retry_at);
        $this->assertNull($syncRecord->locked_at);
        $this->assertNull($syncRecord->locked_by);

        $this->assertEmpty($this->mockTransport->callHistory);
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * 11. Penolakan terpagar (guarded by fence) tidak menimpa lease milik worker lain.
     */
    public function test_rejection_guarded_by_fence_does_not_wipe_other_worker_lease(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $otherFence = (string) Str::uuid();

        // Worker lain sedang mengunci record
        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-fenced',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'                 => now(),
            'locked_by'                 => $otherFence,
        ]);

        // Worker baru mencoba memproses
        $result = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($result);

        $syncRecord->refresh();
        // Lease worker lain tetap terjaga, tidak terhapus
        $this->assertEquals($otherFence, $syncRecord->locked_by);
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $syncRecord->sync_status);
        $this->assertNotNull($syncRecord->locked_at);
    }

    /**
     * 12. Acceptance transition recovery: saat admin menerima pendaftar yang sebelumnya deferred/failed,
     * sistem otomatis me-reset ke pending, menjadwalkan ulang, dan berhasil sinkronisasi.
     */
    public function test_acceptance_transition_recovers_deferred_sync_record_cleanly(): void
    {
        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        // Simulasikan sync record yang sebelumnya gagal karena not-eligible
        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-recovery',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => 'NOT_ELIGIBLE_UNACCEPTED: Registration is not eligible for sync without verified admin acceptance.',
            'next_retry_at'             => null,
        ]);

        $this->mockTransport->forcedResponse = SpreadsheetTransportResponse::success(
            rowIndex: 14,
            context: ['row_index' => 14]
        );

        // Admin secara resmi menerima pendaftar
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class, function ($job) use ($syncRecord) {
            return $job->rowSyncId === $syncRecord->id;
        });

        $syncRecord->refresh();
        // Record otomatis dipulihkan ke PENDING tanpa intervensi manual SQL
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $syncRecord->sync_status);
        $this->assertNull($syncRecord->error_message);
        $this->assertNotNull($syncRecord->next_retry_at);

        // Background worker mengeksekusi sinkronisasi
        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertTrue($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $syncRecord->sync_status);
        $this->assertEquals(14, $syncRecord->row_index);
        $this->assertNotNull($syncRecord->last_synced_at);
        $this->assertNotNull($syncRecord->payload_checksum);
        $this->assertCount(1, $this->mockTransport->callHistory);
        $this->assertEquals(1, $this->mockTransport->callCount);
    }

    /**
     * 13. Exited/cancelled pasca-acceptance TANPA riwayat sync sebelumnya strictly ineligible
     * untuk mencegah penambahan baris baru (accidental insert) ke spreadsheet (S17.17 Req 3).
     */
    public function test_exited_after_acceptance_without_prior_sync_is_ineligible_preventing_accidental_insert(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        // 1. Diterima oleh admin
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        // 2. Berjalan menjadi active
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACTIVE, isAdmin: true);

        // 3. Kemudian keluar (exited) tanpa pernah berhasil disinkronkan ke spreadsheet sebelumnya
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Mundur sebelum mulai');

        $reg->refresh();
        $this->assertEquals(InternshipRegistration::STATUS_EXITED, $reg->internship_status);

        // Memiliki bukti penerimaan admin, tetapi TIDAK memiliki riwayat sinkronisasi (remote presence)
        $this->assertTrue($this->mappingService->hasVerifiedAdminAcceptance($reg));
        $this->assertFalse($this->mappingService->hasVerifiedPriorSync($reg));
        $this->assertFalse($this->mappingService->isEligibleForSync($reg));

        // Ambil sync record yang otomatis dibuat saat transisi ke accepted (belum pernah berhasil disinkronkan)
        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);
        $this->assertNull($syncRecord->last_synced_at);
        $this->assertNull($syncRecord->row_index);
        $syncRecord->update(['next_retry_at' => now()->subMinute()]);

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);

        $this->assertFalse($processed);
        $syncRecord->refresh();

        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertStringContainsString('NOT_ELIGIBLE_UPDATE_ONLY_HELD', (string) $syncRecord->error_message);
        $this->assertNull($syncRecord->next_retry_at);
        $this->assertNull($syncRecord->locked_at);
        $this->assertNull($syncRecord->locked_by);

        // Verifikasi ZERO HTTP outbound requests
        $this->assertEmpty($this->mockTransport->callHistory);
        $this->assertEquals(0, $this->mockTransport->callCount);
    }

    /**
     * 14. Exited pasca-acceptance dengan metadata row_index saja tanpa remote presence terverifikasi
     * gagal-aman (fail-closed, strictly ineligible) (S17.17 Req 3).
     */
    public function test_exited_after_acceptance_with_unverified_metadata_row_index_fails_closed(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);

        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Batal');

        // Ambil sync record yang ada dan simulasikan row_index unverified/palsu tetapi last_synced_at null
        $syncRecord = SpreadsheetRowSync::where('syncable_id', $reg->id)->first();
        $this->assertNotNull($syncRecord);
        $syncRecord->update([
            'row_index'        => 25, // Unverified metadata
            'last_synced_at'   => null,
            'payload_checksum' => null,
            'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at'    => null,
        ]);

        $this->assertFalse($this->mappingService->hasVerifiedPriorSync($reg, $syncRecord));
        $this->assertFalse($this->mappingService->isEligibleForSync($reg, $syncRecord));

        $processed = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);
        $this->assertFalse($processed);

        $syncRecord->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $syncRecord->sync_status);
        $this->assertStringContainsString('NOT_ELIGIBLE_UPDATE_ONLY_HELD', (string) $syncRecord->error_message);
        $this->assertEmpty($this->mockTransport->callHistory);
    }

    /**
     * 15. Membuka profil bersifat strictly read-only: tidak menaikkan status 'waiting' menjadi 'active'
     * dan tidak membuat job sinkronisasi (S17.17 Final Hardening).
     */
    public function test_visiting_profile_does_not_escalate_waiting_registration_to_active(): void
    {
        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $reg = $this->createRegistration([
            'user_id'           => $this->pemagang->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'start_date'        => Carbon::today()->toDateString(),
        ]);

        $this->actingAs($this->pemagang);

        $controller = app(\App\Http\Controllers\UserController::class);
        $request = new \Illuminate\Http\Request();

        // Panggil index() dan showProfile()
        $responseIndex = $controller->index($request);
        $responseShow = $controller->showProfile($request);

        $reg->refresh();
        // Status HARUS TETAP 'waiting' dan TIDAK BOLEH naik menjadi 'active'
        $this->assertEquals(InternshipRegistration::STATUS_WAITING, $reg->internship_status);

        // Tidak boleh ada sync record atau job yang dibuat
        $this->assertEquals(0, SpreadsheetRowSync::where('syncable_id', $reg->id)->count());
        Queue::assertNothingPushed();
    }

    /**
     * 16. Membuka profil bersifat strictly read-only: status 'accepted' dengan start_date hari ini
     * TIDAK dimutasi melalui HTTP GET dan tidak membuat sync job (S17.17 Final Hardening).
     */
    public function test_visiting_profile_does_not_mutate_accepted_registration_status(): void
    {
        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $reg = $this->createRegistration([
            'user_id'           => $this->pemagang->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'start_date'        => Carbon::today()->toDateString(),
        ]);

        // Diterima resmi oleh admin
        $this->lifecycleService->transition($reg, InternshipRegistration::STATUS_ACCEPTED, isAdmin: true);

        $reg->refresh();
        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $reg->internship_status);

        // Reset queue fake untuk memverifikasi GET profile tidak memicu job baru
        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $this->actingAs($this->pemagang);

        $controller = app(\App\Http\Controllers\UserController::class);
        $request = new \Illuminate\Http\Request();

        $controller->showProfile($request);

        $reg->refresh();
        // Status HARUS TETAP 'accepted' (STRICTLY READ-ONLY GET)
        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $reg->internship_status);
        Queue::assertNothingPushed();
    }

    /**
     * 17. Artisan command app:update-internship-status hanya menaikkan status 'accepted' menjadi 'active',
     * dan tidak pernah menaikkan status 'waiting' (S17.17 Req 1).
     */
    public function test_command_update_internship_status_only_activates_accepted_registrations(): void
    {
        $regWaiting = $this->createRegistration([
            'fullname'          => 'Waiting User',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'start_date'        => Carbon::today()->toDateString(),
        ]);

        $regAccepted = $this->createRegistration([
            'fullname'          => 'Accepted User',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'start_date'        => Carbon::today()->toDateString(),
        ]);

        // Jalankan console command
        $this->artisan('app:update-internship-status')
            ->assertExitCode(0);

        $regWaiting->refresh();
        $regAccepted->refresh();

        // Status waiting tetap waiting!
        $this->assertEquals(InternshipRegistration::STATUS_WAITING, $regWaiting->internship_status);

        // Status accepted menjadi active!
        $this->assertEquals(InternshipRegistration::STATUS_ACTIVE, $regAccepted->internship_status);
    }

    /**
     * 18. Reaktivasi record yang sudah ada TIDAK menghapus lock aktif atau mengambil alih record processing milik worker lain (S17.17 Final Hardening).
     */
    public function test_requeuing_existing_sync_record_does_not_wipe_active_worker_processing_lease(): void
    {
        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);

        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $activeFence = 'FENCE-ACTIVE-WORKER-' . Str::uuid();

        // Worker lain sedang mengunci dan memproses record ini
        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-lease-protect',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'                 => now(),
            'locked_by'                 => $activeFence,
        ]);

        // Admin memicu queueSyncForRegistration kembali (misal karena edit data pendaftaran)
        $result = $this->syncEngine->queueSyncForRegistration($reg);

        $syncRecord->refresh();
        // Lease aktif worker lain HARUS TETAP UTUH (tidak di-reset ke pending, lock tidak dihapus)
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $syncRecord->sync_status);
        $this->assertEquals($activeFence, $syncRecord->locked_by);
        $this->assertNotNull($syncRecord->locked_at);

        // Tidak mendispatch job ganda saat worker lain sedang aktif memproses
        Queue::assertNothingPushed();
    }

    /**
     * 19. Concurrency lock contention dan fencing protection pada pemrosesan paralel (S17.17 Final Hardening).
     */
    public function test_concurrency_lock_contention_and_fencing_protection(): void
    {
        $reg = $this->createRegistration([
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $syncRecord = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id . '-concurrency',
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
        ]);

        $worker1Fence = (string) Str::uuid();

        // Worker 1 berhasil mengklaim lease
        $claimed = DB::table('spreadsheet_row_syncs')
            ->where('id', $syncRecord->id)
            ->where('sync_status', '!=', SpreadsheetRowSync::STATUS_PROCESSING)
            ->whereNull('locked_at')
            ->update([
                'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
                'locked_at'   => now(),
                'locked_by'   => $worker1Fence,
            ]);

        $this->assertEquals(1, $claimed);

        // Worker 2 mencoba memproses record yang sama saat masih dikunci Worker 1 -> gagal-aman
        $worker2Result = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport);
        $this->assertFalse($worker2Result);

        // Worker 1 memproses dengan fence token worker1Fence, tetapi jika di tengah jalan lock token telah diubah oleh pihak lain:
        $tamperedFence = (string) Str::uuid();
        DB::table('spreadsheet_row_syncs')->where('id', $syncRecord->id)->update(['locked_by' => $tamperedFence]);

        // Guardrail 1: processSyncRecord mendeteksi fence token tidak cocok dan membuang penulisan usang (discard stale write)
        $worker1Result = $this->syncEngine->processSyncRecord($syncRecord, $this->mockTransport, preClaimedFence: $worker1Fence);
        $this->assertFalse($worker1Result);

        $syncRecord->refresh();
        $this->assertNotEquals(SpreadsheetRowSync::STATUS_SYNCED, $syncRecord->sync_status);
        $this->assertEquals($tamperedFence, $syncRecord->locked_by);
    }
}
