<?php

namespace Tests\Feature;

use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Models\City;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FollowUpStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $user;
    protected SpreadsheetConnection $regConnection;
    protected RegistrationLifecycleService $lifecycle;
    protected RegistrationSpreadsheetMappingService $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSpreadsheetRowSyncJob::class]);
        Mail::fake();

        $this->seed(Spreadsheet2026SchemaSeeder::class);

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

        $this->admin = User::create([
            'name'      => 'Admin FollowUp',
            'email'     => 'admin_fu_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->user = User::create([
            'name'      => 'Applicant User',
            'email'     => 'applicant_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'user',
            'is_banned' => false,
        ]);

        $this->lifecycle = app(RegistrationLifecycleService::class);
        $this->mapping = app(RegistrationSpreadsheetMappingService::class);
    }

    protected function createRegistration(string $internshipStatus = IR::STATUS_WAITING, ?string $followupStatus = null): IR
    {
        $institution = Institution::firstOrCreate(
            ['name' => 'Universitas Gadjah Mada'],
            ['type' => 'universitas']
        );
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $institution->id, 'name' => 'MIPA']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Ilmu Komputer']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        return IR::create([
            'user_id'                  => $this->user->id,
            'fullname'                 => 'Budi Test FollowUp',
            'born_date'                => '2002-05-10',
            'gender'                   => 'male',
            'phone_number'             => '081234567890',
            'institution_id'           => $institution->id,
            'faculty_id'               => $faculty->id,
            'study_program_id'         => $prodi->id,
            'city_id'                  => $city->id,
            'internship_type'          => 'campus',
            'internship_arrangement'   => 'onsite',
            'current_status'           => 'Student',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'has_laptop'               => true,
            'start_date'               => '2026-03-01',
            'planned_duration'         => '3 bulan',
            'end_date'                 => '2026-06-01',
            'internship_status'        => $internshipStatus,
            'followup_status'          => $followupStatus,
        ]);
    }

    /**
     * Test 1: Verify exact 8 stable codes and exact Indonesian labels.
     */
    public function test_eight_stable_followup_status_constants_and_exact_labels(): void
    {
        $expected = [
            IR::FOLLOWUP_NOT_FOLLOWED_UP       => 'Belum di-follow-up (belum dikirim surat penerimaan magang)',
            IR::FOLLOWUP_AWAITING_AGREEMENT    => 'Sudah di-follow-up, masih menunggu jawaban "setuju" dengan ketentuan, perlu dicek ulang',
            IR::FOLLOWUP_MOVED_TO_PARTICIPANTS => 'Sudah di-follow-up (sudah dinyatakan diterima, sudah dipindah ke daftar peserta)',
            IR::FOLLOWUP_INTERNSHIP_STARTED    => 'Sudah masuk magang',
            IR::FOLLOWUP_INTERNSHIP_CANCELLED  => 'Tidak jadi magang',
            IR::FOLLOWUP_NOT_YET_ACC           => 'Sudah di-follow-up, tetapi belum ACC, perlu dicek ulang',
            IR::FOLLOWUP_INTERNSHIP_COMPLETED  => 'Sudah selesai magang',
            IR::FOLLOWUP_AWAITING_DATES        => 'Mau magang, tetapi masih menunggu kepastian tanggal dari sekolah/kampus',
        ];

        $this->assertCount(8, IR::FOLLOWUP_STATUS_LABELS);
        $this->assertEquals($expected, IR::FOLLOWUP_STATUS_LABELS);
    }

    /**
     * Test 2: Only admin can update follow-up status; guest and non-admin receive 401/403.
     */
    public function test_authorization_strictly_admin_only_for_followup_status_update(): void
    {
        $reg = $this->createRegistration(IR::STATUS_ACCEPTED, IR::FOLLOWUP_NOT_FOLLOWED_UP);

        // Guest -> 401 / redirect to login
        $this->patchJson(route('admin.interns.followup.update', $reg), [
            'followup_status' => IR::FOLLOWUP_AWAITING_AGREEMENT,
        ])->assertUnauthorized();

        // Non-admin user -> 403 Forbidden
        $this->actingAs($this->user)
            ->patchJson(route('admin.interns.followup.update', $reg), [
                'followup_status' => IR::FOLLOWUP_AWAITING_AGREEMENT,
            ])->assertForbidden();

        // Admin -> 200 OK
        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $reg), [
                'followup_status' => IR::FOLLOWUP_AWAITING_AGREEMENT,
            ])
            ->assertOk()
            ->assertJson([
                'ok'              => true,
                'followup_status' => IR::FOLLOWUP_AWAITING_AGREEMENT,
            ]);

        $reg->refresh();
        $this->assertEquals(IR::FOLLOWUP_AWAITING_AGREEMENT, $reg->followup_status);
    }

    /**
     * Test 3: Status #1, #2, #3, #6, #8 require accepted status. Direct edit on waiting or active fails.
     */
    public function test_followup_status_consistency_validation_requires_accepted_lifecycle(): void
    {
        $waitingReg = $this->createRegistration(IR::STATUS_WAITING, null);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $waitingReg), [
                'followup_status' => IR::FOLLOWUP_AWAITING_AGREEMENT,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Status follow-up ini hanya dapat diberikan kepada peserta yang telah dinyatakan \'Diterima\' (accepted).',
            ]);

        $this->assertNull($waitingReg->fresh()->followup_status);
    }

    /**
     * Test 4: Direct edit to status #4 (Sudah masuk magang) or #7 (Sudah selesai) rejected without official lifecycle.
     */
    public function test_direct_edit_to_started_or_completed_rejected_with_actionable_guidance(): void
    {
        $acceptedReg = $this->createRegistration(IR::STATUS_ACCEPTED, IR::FOLLOWUP_NOT_FOLLOWED_UP);

        // Direct edit to #4 rejected
        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $acceptedReg), [
                'followup_status' => IR::FOLLOWUP_INTERNSHIP_STARTED,
            ])
            ->assertStatus(422);

        // Direct edit to #7 rejected
        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $acceptedReg), [
                'followup_status' => IR::FOLLOWUP_INTERNSHIP_COMPLETED,
            ])
            ->assertStatus(422);

        // Direct edit to #5 rejected (must use official cancellation)
        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $acceptedReg), [
                'followup_status' => IR::FOLLOWUP_INTERNSHIP_CANCELLED,
            ])
            ->assertStatus(422);
    }

    /**
     * Test 5: Official lifecycle transitions synchronize followup_status atomically.
     */
    public function test_official_lifecycle_transitions_synchronize_followup_status(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);

        // waiting -> accepted sets #1 (not_followed_up)
        $this->lifecycle->transition($reg, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Lolos berkas');
        $reg->refresh();
        $this->assertEquals(IR::STATUS_ACCEPTED, $reg->internship_status);
        $this->assertEquals(IR::FOLLOWUP_NOT_FOLLOWED_UP, $reg->followup_status);

        // accepted -> active sets #4 (internship_started)
        $this->lifecycle->transition($reg, IR::STATUS_ACTIVE, isAdmin: true, reason: 'Mulai orientasi');
        $reg->refresh();
        $this->assertEquals(IR::STATUS_ACTIVE, $reg->internship_status);
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_STARTED, $reg->followup_status);

        // active -> completed sets #7 (internship_completed)
        $this->lifecycle->transition($reg, IR::STATUS_COMPLETED, isAdmin: true, reason: 'Magang selesai');
        $reg->refresh();
        $this->assertEquals(IR::STATUS_COMPLETED, $reg->internship_status);
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_COMPLETED, $reg->followup_status);
    }

    /**
     * Test 6: Official post-acceptance cancellation (exited) sets followup_status to #5 (Tidak jadi magang).
     */
    public function test_post_acceptance_exited_sets_followup_status_to_cancelled(): void
    {
        $reg = $this->createRegistration(IR::STATUS_ACCEPTED, IR::FOLLOWUP_AWAITING_AGREEMENT);

        $this->lifecycle->transition($reg, IR::STATUS_EXITED, isAdmin: true, reason: 'Mengundurkan diri');
        $reg->refresh();

        $this->assertEquals(IR::STATUS_EXITED, $reg->internship_status);
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_CANCELLED, $reg->followup_status);
    }

    /**
     * Test 7: Historical NULL followup_status is fully preserved and visible in participant lists.
     */
    public function test_historical_null_followup_status_is_visible_and_preserved(): void
    {
        $regHistoricalActive = $this->createRegistration(IR::STATUS_ACTIVE, null);
        $regNewActive = $this->createRegistration(IR::STATUS_ACTIVE, IR::FOLLOWUP_INTERNSHIP_STARTED);

        $this->actingAs($this->admin);

        // Active scope returns BOTH records
        $response = $this->getJson(route('admin.interns.api', ['scope' => 'active']));
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($regHistoricalActive->id, $ids);
        $this->assertContains($regNewActive->id, $ids);

        // Historical record retains NULL followup_status
        $historicalItem = collect($response->json('data'))->firstWhere('id', $regHistoricalActive->id);
        $this->assertNull($historicalItem['followup_status']);
    }

    /**
     * Test 8: Peserta Administratif tab returns only accepted with moved_to_participants.
     */
    public function test_peserta_administratif_tab_filters_accepted_and_moved_to_participants(): void
    {
        $regAdminParticipant = $this->createRegistration(IR::STATUS_ACCEPTED, IR::FOLLOWUP_MOVED_TO_PARTICIPANTS);
        $regAcceptedWaitingAgreement = $this->createRegistration(IR::STATUS_ACCEPTED, IR::FOLLOWUP_AWAITING_AGREEMENT);
        $regActive = $this->createRegistration(IR::STATUS_ACTIVE, IR::FOLLOWUP_INTERNSHIP_STARTED);

        $this->actingAs($this->admin);

        $response = $this->getJson(route('admin.interns.api', ['scope' => 'participants']));
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($regAdminParticipant->id, $ids);
        $this->assertNotContains($regAcceptedWaitingAgreement->id, $ids);
        $this->assertNotContains($regActive->id, $ids);
    }

    /**
     * Test 9: Kolom Y (KET / status_label) mapping reflects canonical resolver output.
     */
    public function test_kolom_y_mapping_reflects_followup_status_label_with_status_label_fallback(): void
    {
        // 1. Accepted with follow-up status
        $regWithFollowup = $this->createRegistration(IR::STATUS_ACCEPTED, IR::FOLLOWUP_AWAITING_AGREEMENT);
        $row1 = $this->mapping->buildOutboundRowPayload($regWithFollowup);
        $this->assertEquals(
            'Sudah di-follow-up, masih menunggu jawaban "setuju" dengan ketentuan, perlu dicek ulang',
            $row1['KET']
        );

        // 2. Accepted with NULL follow-up status -> fallback to status_label ('Diterima')
        $regWithoutFollowup = $this->createRegistration(IR::STATUS_ACCEPTED, null);
        $row2 = $this->mapping->buildOutboundRowPayload($regWithoutFollowup);
        $this->assertEquals('Diterima', $row2['KET']);
    }

    /**
     * Test 10: Zero live HTTP, zero emails, zero background jobs executed during tests.
     */
    public function test_zero_live_http_and_zero_emails_during_lifecycle_and_followup_updates(): void
    {
        $reg = $this->createRegistration(IR::STATUS_ACCEPTED, IR::FOLLOWUP_NOT_FOLLOWED_UP);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $reg), [
                'followup_status' => IR::FOLLOWUP_AWAITING_DATES,
            ])
            ->assertOk();

        // Enqueued job is faked, not executed
        Queue::assertPushed(ProcessSpreadsheetRowSyncJob::class);
        Mail::assertNothingSent();
    }

    /**
     * Test 11: Case A — Accepted by admin, canceled before onboarding with positive proof yields label 'Tidak jadi magang'.
     */
    public function test_case_a_accepted_then_exited_before_start_yields_label_a_tidak_jadi_magang(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);

        // 1. Accepted by admin
        $this->lifecycle->transition($reg, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Pemberkasan lengkap');
        $reg->refresh();

        // 2. Exited before onboarding
        $this->lifecycle->transition($reg, IR::STATUS_EXITED, isAdmin: true, reason: 'Membatalkan karena diterima magang lain');
        $reg->refresh();

        $this->assertEquals(IR::STATUS_EXITED, $reg->internship_status);
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_CANCELLED, $reg->followup_status);
        $this->assertEquals('Tidak jadi magang', $reg->current_condition_label);
        $this->assertEquals('A', $reg->current_condition_case);

        // Kolom Y Spreadsheet payload (update-only with verified prior sync)
        $syncRecord = SpreadsheetRowSync::firstOrNew([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => $reg->getMorphClass(),
            'syncable_id'               => $reg->id,
        ]);
        $syncRecord->fill([
            'external_sync_id' => 'REG-' . $reg->id . '-sync',
            'target_sheet_tab' => '2026',
            'spreadsheet_id'   => 'test-reg-sheet',
            'tab_name'         => '2026',
            'row_index'        => 25,
            'sync_status'      => 'synced',
            'last_synced_at'   => now()->subDays(2),
            'payload_checksum' => 'checksum_case_a',
        ])->save();

        $payload = $this->mapping->buildOutboundRowPayload($reg, rowSync: $syncRecord);
        $this->assertEquals('Tidak jadi magang', $payload['KET']);
    }

    /**
     * Test 12: Case B — Active intern exited before completion yields label 'Keluar setelah mulai magang (belum selesai)' and retains #4.
     */
    public function test_case_b_active_then_exited_yields_label_b_keluar_setelah_mulai_magang_belum_selesai(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);

        // 1. Accepted
        $this->lifecycle->transition($reg, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Lolos seleksi');
        // 2. Active
        $this->lifecycle->transition($reg, IR::STATUS_ACTIVE, isAdmin: true, reason: 'Mulai program magang');
        $reg->refresh();
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_STARTED, $reg->followup_status);

        // 3. Exited before finishing
        $this->lifecycle->transition($reg, IR::STATUS_EXITED, isAdmin: true, reason: 'Kendala perkuliahan');
        $reg->refresh();

        $this->assertEquals(IR::STATUS_EXITED, $reg->internship_status);
        // Retains followup_status #4
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_STARTED, $reg->followup_status);
        $this->assertEquals('Keluar setelah mulai magang (belum selesai)', $reg->current_condition_label);
        $this->assertEquals('B', $reg->current_condition_case);

        $syncRecord = SpreadsheetRowSync::firstOrNew([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => $reg->getMorphClass(),
            'syncable_id'               => $reg->id,
        ]);
        $syncRecord->fill([
            'external_sync_id' => 'REG-' . $reg->id . '-sync',
            'target_sheet_tab' => '2026',
            'spreadsheet_id'   => 'test-reg-sheet',
            'tab_name'         => '2026',
            'row_index'        => 30,
            'sync_status'      => 'synced',
            'last_synced_at'   => now()->subDays(1),
            'payload_checksum' => 'checksum_case_b',
        ])->save();

        $payload = $this->mapping->buildOutboundRowPayload($reg, rowSync: $syncRecord);
        $this->assertEquals('Keluar setelah mulai magang (belum selesai)', $payload['KET']);
    }

    /**
     * Test 13: Case C — Completed intern has highest priority and is NEVER misclassified as unfinished (Case B) or cancelled (Case A).
     */
    public function test_case_c_completed_then_exited_yields_label_c_sudah_selesai_magang(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);

        $this->lifecycle->transition($reg, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Lolos berkas');
        $this->lifecycle->transition($reg, IR::STATUS_ACTIVE, isAdmin: true, reason: 'Mulai magang');
        $this->lifecycle->transition($reg, IR::STATUS_COMPLETED, isAdmin: true, reason: 'Menyelesaikan magang dengan predikat sangat baik');
        $reg->refresh();

        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_COMPLETED, $reg->followup_status);

        // Even if status is administratively corrected to exited, completion history takes priority 1!
        $this->lifecycle->transition($reg, IR::STATUS_EXITED, isAdmin: true, reason: 'Penyesuaian status penutupan sistem');
        $reg->refresh();

        $this->assertEquals(IR::STATUS_EXITED, $reg->internship_status);
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_COMPLETED, $reg->followup_status);
        $this->assertEquals('Sudah selesai magang', $reg->current_condition_label);
        $this->assertEquals('C', $reg->current_condition_case);

        $syncRecord = SpreadsheetRowSync::firstOrNew([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'syncable_type'             => $reg->getMorphClass(),
            'syncable_id'               => $reg->id,
        ]);
        $syncRecord->fill([
            'external_sync_id' => 'REG-' . $reg->id . '-sync',
            'target_sheet_tab' => '2026',
            'spreadsheet_id'   => 'test-reg-sheet',
            'tab_name'         => '2026',
            'row_index'        => 40,
            'sync_status'      => 'synced',
            'last_synced_at'   => now()->subDays(1),
            'payload_checksum' => 'checksum_case_c',
        ])->save();

        $payload = $this->mapping->buildOutboundRowPayload($reg, rowSync: $syncRecord);
        $this->assertEquals('Sudah selesai magang', $payload['KET']);
    }

    /**
     * Test 14: Case D — Legacy exited record without proof fails closed to 'Keluar — riwayat mulai perlu diverifikasi'.
     */
    public function test_case_d_legacy_exited_without_proof_yields_label_d_verifikasi_diperlukan(): void
    {
        // Legacy record without activity logs and without verified admin acceptance
        $regLegacy = $this->createRegistration(IR::STATUS_EXITED, null);

        $this->assertEquals('Keluar — riwayat mulai perlu diverifikasi', $regLegacy->current_condition_label);
        $this->assertEquals('D', $regLegacy->current_condition_case);
        $this->assertNull($regLegacy->followup_status);

        $canonical = $this->lifecycle->resolveCurrentCondition($regLegacy);
        $this->assertEquals('Keluar — riwayat mulai perlu diverifikasi', $canonical['condition_label']);
        $this->assertEquals('Keluar — riwayat mulai perlu diverifikasi', $canonical['spreadsheet_column_y_label']);
        $this->assertEquals('D', $canonical['case']);
        $this->assertFalse($canonical['is_verified']);

        // Fail-closed: unverified legacy exited record MUST NOT be synced to Google Sheets
        $this->assertFalse($this->mapping->isEligibleForSync($regLegacy));
    }

    /**
     * Test 15: Rejected before acceptance yields 'Ditolak' and is strictly ineligible (0 HTTP).
     */
    public function test_rejected_pre_acceptance_yields_ditolak_and_ineligible_for_sync(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);

        $this->lifecycle->transition($reg, IR::STATUS_REJECTED, isAdmin: true, reason: 'Kualifikasi belum sesuai');
        $reg->refresh();

        $this->assertEquals(IR::STATUS_REJECTED, $reg->internship_status);
        $this->assertNull($reg->followup_status);
        $this->assertEquals('Ditolak', $reg->current_condition_label);
        $this->assertEquals('REJECTED_PRE', $reg->current_condition_case);

        // Strictly ineligible for spreadsheet sync
        $this->assertFalse($this->mapping->isEligibleForSync($reg));
    }

    /**
     * Test 16: Rejected post-acceptance before start with valid proof yields 'Tidak jadi magang'.
     */
    public function test_rejected_post_acceptance_before_start_yields_tidak_jadi_magang(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);

        // 1. Accepted
        $this->lifecycle->transition($reg, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Penerimaan awal');
        // 2. Rejected/canceled post-acceptance before starting
        $this->lifecycle->transition($reg, IR::STATUS_REJECTED, isAdmin: true, reason: 'Batal oleh pemohon setelah penerimaan');
        $reg->refresh();

        $this->assertEquals(IR::STATUS_REJECTED, $reg->internship_status);
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_CANCELLED, $reg->followup_status);
        $this->assertEquals('Tidak jadi magang', $reg->current_condition_label);
        $this->assertEquals('A', $reg->current_condition_case);
    }

    /**
     * Test 17: Rejected post-acceptance with active history is NOT misclassified as 'Tidak jadi magang'.
     */
    public function test_rejected_post_acceptance_with_active_history_is_not_misclassified_as_tidak_jadi_magang(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);

        $this->lifecycle->transition($reg, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Lolos berkas');
        $this->lifecycle->transition($reg, IR::STATUS_ACTIVE, isAdmin: true, reason: 'Mulai orientasi');
        $this->lifecycle->transition($reg, IR::STATUS_REJECTED, isAdmin: true, reason: 'Pelanggaran disiplin berat');
        $reg->refresh();

        $this->assertEquals(IR::STATUS_REJECTED, $reg->internship_status);
        // Preserves started history #4, evaluated as Case B
        $this->assertEquals(IR::FOLLOWUP_INTERNSHIP_STARTED, $reg->followup_status);
        $this->assertEquals('Keluar setelah mulai magang (belum selesai)', $reg->current_condition_label);
        $this->assertEquals('B', $reg->current_condition_case);
    }

    /**
     * Test 18: Normal active registration maps Kolom Y to 'Sudah masuk magang'.
     */
    public function test_normal_active_registration_maps_kolom_y_to_sudah_masuk_magang(): void
    {
        $reg = $this->createRegistration(IR::STATUS_WAITING, null);
        $this->lifecycle->transition($reg, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Lolos berkas');
        $this->lifecycle->transition($reg, IR::STATUS_ACTIVE, isAdmin: true, reason: 'Mulai program magang');
        $reg->refresh();

        $this->assertEquals('Magang Aktif', $reg->current_condition_label);
        $this->assertEquals('ACTIVE', $reg->current_condition_case);

        $payload = $this->mapping->buildOutboundRowPayload($reg);
        $this->assertEquals('Sudah masuk magang', $payload['KET']);
    }

    /**
     * Test 19: Strict manual validation rejects assigning #5 to active or completed intern.
     */
    public function test_strict_validation_rejects_assigning_cancelled_to_active_or_completed_intern(): void
    {
        $regActive = $this->createRegistration(IR::STATUS_WAITING, null);
        $this->lifecycle->transition($regActive, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Lolos berkas');
        $this->lifecycle->transition($regActive, IR::STATUS_ACTIVE, isAdmin: true, reason: 'Mulai magang');
        $regActive->refresh();

        // 1. Cannot assign #5 on active intern
        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $regActive), [
                'followup_status' => IR::FOLLOWUP_INTERNSHIP_CANCELLED,
            ])
            ->assertStatus(422);

        // 2. Cannot assign #5 on intern transitioned from active to exited
        $this->lifecycle->transition($regActive, IR::STATUS_EXITED, isAdmin: true, reason: 'Keluar mandiri');
        $regActive->refresh();

        $this->actingAs($this->admin)
            ->patchJson(route('admin.interns.followup.update', $regActive), [
                'followup_status' => IR::FOLLOWUP_INTERNSHIP_CANCELLED,
            ])
            ->assertStatus(422);
    }

    /**
     * Test 20: Admin API endpoint preloads logs in batch to prevent N+1 queries.
     */
    public function test_admin_api_list_preloads_logs_in_batch_preventing_n_plus_one(): void
    {
        // Create 10 registrations with various statuses
        for ($i = 0; $i < 10; $i++) {
            $r = $this->createRegistration(IR::STATUS_WAITING, null);
            $this->lifecycle->transition($r, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Batch test');
        }

        $this->actingAs($this->admin);

        \Illuminate\Support\Facades\DB::enableQueryLog();

        $response = $this->getJson(route('admin.interns.api', ['per_page' => 10]));
        $response->assertOk();

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Count queries targeting activity_logs
        $activityLogQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'activity_logs');
        });

        // Exactly 1 batch query for activity_logs, NOT 10 individual queries!
        $this->assertLessThanOrEqual(2, count($activityLogQueries), 'Activity logs MUST be fetched in batch to prevent N+1 queries.');
    }
}
