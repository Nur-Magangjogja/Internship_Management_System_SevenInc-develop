<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\Institution;
use App\Models\IntegrationOutboxEvent;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\SheetInboundChangeStaging;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\SpreadsheetTabProfile;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetInboundSyncService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Fase4ControlledInboundSyncTest extends TestCase
{
    use RefreshDatabase;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;
    protected User $pemagangUser;
    protected MockSpreadsheetTransport $mockTransport;
    protected Institution $institution;
    protected Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Inbound Sync',
            'email'     => 'admin.inbound@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagangUser = User::create([
            'name'      => 'Pemagang Test',
            'email'     => 'pemagang.inbound@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas Gadjah Mada',
            'is_active' => true,
        ]);

        $this->division = Division::create([
            'name'      => 'Backend Development',
            'slug'      => 'backend-development',
            'is_active' => true,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Google Sheets Pendaftaran 2026',
                'spreadsheet_id'           => 'mock-spreadsheet-fase4-inbound',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-fase4-inbound/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_MAPPING_GATEWAY/exec',
            ]
        );

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);
    }

    protected function createInternshipRegistration(array $overrides = []): InternshipRegistration
    {
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $this->institution->id, 'name' => 'Fakultas Teknik']
        );
        $program = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknologi Informasi']
        );

        $defaults = [
            'user_id'          => $this->pemagangUser->id,
            'fullname'         => 'Budi Santoso',
            'institution_id'   => $this->institution->id,
            'faculty_id'       => $faculty->id,
            'study_program_id' => $program->id,
            'division_id'      => $this->division->id,
            'phone_number'     => '081234567890',
            'internship_status'=> InternshipRegistration::STATUS_ACCEPTED,
        ];

        return InternshipRegistration::create(array_merge($defaults, $overrides));
    }

    protected function setupRegistrationWithSync(string $syncId = 'SYNC-2026-001', array $regOverrides = []): InternshipRegistration
    {
        $reg = $this->createInternshipRegistration($regOverrides);

        SpreadsheetRowSync::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => $syncId,
            'target_sheet_tab'          => '2026',
            'schema_version'            => 'v1',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            'last_synced_at'            => now()->subHour(),
        ]);

        return $reg;
    }

    // =========================================================================
    // FASE 4 TESTS: CONTROLLED BIDIRECTIONAL SYNC ENGINE & RECONCILIATION
    // =========================================================================

    /**
     * F4-01: Perubahan custom field yang diizinkan masuk ke staging terlebih dahulu (tidak langsung update DB).
     */
    public function test_f4_01_inbound_scan_stages_clean_custom_field_change(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-001');

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'hobi_pendaftar',
            'answer_value'    => 'Fotografi',
        ]);

        // Tandai waktu sinkronisasi terakhir setelah pembuatan data awal
        SpreadsheetRowSync::where('external_sync_id', 'SYNC-2026-001')->update([
            'last_synced_at' => now()->addMinutes(5),
        ]);

        $headers = ['SERTIF_SYNC_ID', 'SERTIF_REGISTRATION_ID', 'Nama Lengkap', 'Hobi_Pendaftar'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-2026-001', [
            'SERTIF_SYNC_ID'          => 'SYNC-2026-001',
            'SERTIF_REGISTRATION_ID'  => (string) $reg->id,
            'Nama Lengkap'            => 'Budi Santoso',
            'Hobi_Pendaftar'          => 'Robotik & Drone', // Nilai baru di sheet
        ]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals(1, $res['staged_changes_count']);
        $this->assertEquals(1, $res['clean_inbound_count']);

        // Verifikasi record tertampung di staging
        $staging = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-001')
            ->where('column_identity', 'Hobi_Pendaftar')
            ->first();

        $this->assertNotNull($staging);
        $this->assertEquals('Robotik & Drone', $staging->normalized_remote_value);
        $this->assertEquals('Fotografi', $staging->current_db_value);
        $this->assertEquals(SheetInboundChangeStaging::STATUS_STAGED, $staging->staging_status);
        $this->assertEquals(SheetInboundChangeStaging::CONFLICT_CLEAN_INBOUND, $staging->conflict_status);

        // Database lokal BELUM terupdate (tetap Fotografi)
        $this->assertEquals('Fotografi', $reg->customAnswers()->where('field_key', 'hobi_pendaftar')->first()->answer_value);
    }

    /**
     * F4-02: Perubahan field sistem terlindungi (status, role, ID) DITOLAK KERAS dan DB tetap terlindungi.
     */
    public function test_f4_02_system_protected_field_mutation_is_rejected_and_db_unaffected(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-002', [
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $headers = ['SERTIF_SYNC_ID', 'Status', 'Nama Lengkap'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-2026-002', [
            'SERTIF_SYNC_ID' => 'SYNC-2026-002',
            'Status'         => 'completed', // Mencoba ubah status secara ilegal dari sheet
            'Nama Lengkap'   => 'Budi Santoso',
        ]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals(1, $res['protected_rejected_count']);

        // Verifikasi tertampung dengan status ditolak dan alasan proteksi
        $staging = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-002')
            ->where('column_identity', 'Status')
            ->first();

        $this->assertNotNull($staging);
        $this->assertEquals(SheetInboundChangeStaging::CONFLICT_REJECTED_PROTECTED, $staging->conflict_status);
        $this->assertEquals(SheetInboundChangeStaging::STATUS_REJECTED, $staging->staging_status);
        $this->assertStringContainsString('terlindungi', $staging->conflict_reason);

        // Database lokal TETAP 'accepted', tidak tertimpa
        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $reg->fresh()->internship_status);
    }

    /**
     * F4-03: Perubahan data inti (Nama Lengkap / Telepon) memerlukan persetujuan eksplisit Admin (conflict_core_field_requires_approval).
     */
    public function test_f4_03_core_administrative_field_change_requires_explicit_admin_approval(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-003', [
            'fullname' => 'Budi Santoso Asli',
        ]);

        $headers = ['SERTIF_SYNC_ID', 'Nama Lengkap'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-2026-003', [
            'SERTIF_SYNC_ID' => 'SYNC-2026-003',
            'Nama Lengkap'   => 'Budi Santoso S.Kom', // Edit nama di spreadsheet
        ]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals(1, $res['conflicts_count']);

        $staging = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-003')
            ->where('column_identity', 'Nama Lengkap')
            ->first();

        $this->assertNotNull($staging);
        $this->assertEquals(SheetInboundChangeStaging::CONFLICT_CORE_REQUIRES_APPROVAL, $staging->conflict_status);
        $this->assertEquals(SheetInboundChangeStaging::STATUS_STAGED, $staging->staging_status);
        $this->assertStringContainsString('memerlukan review dan persetujuan Admin', $staging->conflict_reason);

        // Database belum berubah
        $this->assertEquals('Budi Santoso Asli', $reg->fresh()->fullname);
    }

    /**
     * F4-04: Persetujuan Admin (Approve) menerapkan perubahan staging ke database via domain service dan mencatat outbox event.
     */
    public function test_f4_04_admin_approval_applies_change_via_domain_service_with_outbox_audit(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-004');

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'keahlian_khusus',
            'answer_value'    => 'PHP Laravel',
        ]);

        $staging = SheetInboundChangeStaging::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'tab_name'                  => '2026',
            'sync_id'                   => 'SYNC-2026-004',
            'registration_id'           => $reg->id,
            'column_identity'           => 'Keahlian_Khusus',
            'header_name'               => 'Keahlian_Khusus',
            'field_key'                 => 'keahlian_khusus',
            'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER,
            'raw_remote_value'          => 'PHP Laravel & Golang',
            'normalized_remote_value'   => 'PHP Laravel & Golang',
            'current_db_value'          => 'PHP Laravel',
            'conflict_status'           => SheetInboundChangeStaging::CONFLICT_CLEAN_INBOUND,
            'staging_status'            => SheetInboundChangeStaging::STATUS_STAGED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.inbound.staging.approve', [
                $this->connection,
                $staging,
            ]));

        $response->assertOk();
        $response->assertJson([
            'success'       => true,
            'applied_value' => 'PHP Laravel & Golang',
        ]);

        // Verifikasi database sekarang sudah terupdate
        $this->assertEquals('PHP Laravel & Golang', $reg->customAnswers()->where('field_key', 'keahlian_khusus')->first()->answer_value);

        // Verifikasi staging record berstatus 'applied'
        $this->assertEquals(SheetInboundChangeStaging::STATUS_APPLIED, $staging->fresh()->staging_status);

        // Verifikasi audit event tercatat pada outbox
        $this->assertDatabaseHas('integration_outbox_events', [
            'event_type'     => 'inbound_change_applied',
            'aggregate_type' => 'internship_registration',
            'aggregate_id'   => (string) $reg->id,
            'status'         => IntegrationOutboxEvent::STATUS_VERIFIED,
        ]);
    }

    /**
     * F4-05: Penolakan Admin (Reject) mempertahankan data lokal database sebagai sumber kebenaran.
     */
    public function test_f4_05_admin_rejection_preserves_database_as_source_of_truth(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-005');

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'alamat_domisili',
            'answer_value'    => 'Jl. Kaliurang KM 5',
        ]);

        $staging = SheetInboundChangeStaging::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'tab_name'                  => '2026',
            'sync_id'                   => 'SYNC-2026-005',
            'registration_id'           => $reg->id,
            'column_identity'           => 'Alamat_Domisili',
            'header_name'               => 'Alamat_Domisili',
            'field_key'                 => 'alamat_domisili',
            'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER,
            'raw_remote_value'          => 'Jl. Malioboro 10',
            'normalized_remote_value'   => 'Jl. Malioboro 10',
            'current_db_value'          => 'Jl. Kaliurang KM 5',
            'conflict_status'           => SheetInboundChangeStaging::CONFLICT_CLEAN_INBOUND,
            'staging_status'            => SheetInboundChangeStaging::STATUS_STAGED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.inbound.staging.reject', [
                $this->connection,
                $staging,
            ]), [
                'reason' => 'Alamat di spreadsheet tidak sesuai dengan KTP pemagang.',
            ]);

        $response->assertOk();

        // Staging record berstatus rejected dengan policy keep_local
        $refreshed = $staging->fresh();
        $this->assertEquals(SheetInboundChangeStaging::STATUS_REJECTED, $refreshed->staging_status);
        $this->assertEquals(SheetInboundChangeStaging::POLICY_KEEP_LOCAL, $refreshed->resolution_policy);
        $this->assertStringContainsString('KTP pemagang', $refreshed->conflict_reason);

        // Database lokal tetap utuh
        $this->assertEquals('Jl. Kaliurang KM 5', $reg->customAnswers()->where('field_key', 'alamat_domisili')->first()->answer_value);
    }

    /**
     * F4-06: Field sistem terlindungi tidak dapat dipaksa apply meskipun oleh Admin (fail-closed).
     */
    public function test_f4_06_protected_field_cannot_be_force_applied_even_by_admin(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-006');

        $staging = SheetInboundChangeStaging::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'tab_name'                  => '2026',
            'sync_id'                   => 'SYNC-2026-006',
            'registration_id'           => $reg->id,
            'column_identity'           => 'Status',
            'header_name'               => 'Status',
            'field_key'                 => 'status',
            'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_SYSTEM_PROTECTED,
            'raw_remote_value'          => 'exited',
            'normalized_remote_value'   => 'exited',
            'current_db_value'          => 'accepted',
            'conflict_status'           => SheetInboundChangeStaging::CONFLICT_REJECTED_PROTECTED,
            'staging_status'            => SheetInboundChangeStaging::STATUS_REJECTED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.inbound.staging.approve', [
                $this->connection,
                $staging,
            ]));

        $response->assertStatus(422);
        $response->assertJson([
            'success'    => false,
            'error_code' => 'CANNOT_APPLY_PROTECTED_FIELD',
        ]);

        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $reg->fresh()->internship_status);
    }

    /**
     * F4-07: Pencegahan echo loop: baris yang checksum-nya cocok dengan outbound sync terakhir dilewati (tidak distage ulang).
     */
    public function test_f4_07_echo_loop_prevention_skips_staging_when_row_matches_outbound_checksum(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-007');

        $headers = ['SERTIF_SYNC_ID', 'SERTIF_REGISTRATION_ID', 'Nama Lengkap'];
        $this->mockTransport->setMockHeaders('2026', $headers);

        $rowData = [
            'SERTIF_SYNC_ID'         => 'SYNC-2026-007',
            'SERTIF_REGISTRATION_ID' => (string) $reg->id,
            'Nama Lengkap'           => 'Budi Santoso',
        ];
        $this->mockTransport->setMockRow('2026', 'SYNC-2026-007', $rowData);

        // Hitung checksum outbound yang sama persis
        $expectedChecksum = hash('sha256', json_encode([
            'SERTIF_SYNC_ID'         => 'SYNC-2026-007',
            'SERTIF_REGISTRATION_ID' => (string) $reg->id,
            'Nama Lengkap'           => 'Budi Santoso',
        ]));

        SpreadsheetRowSync::where('external_sync_id', 'SYNC-2026-007')->update([
            'payload_checksum' => $expectedChecksum,
            'last_synced_at'   => now(),
        ]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals(1, $res['echo_skipped_count']);
        $this->assertEquals(0, $res['staged_changes_count']);
    }

    /**
     * F4-08: Normalisasi nilai sel: string kosong, spasi berlebih, dan tanda strip '-' dinormalisasi konsisten.
     */
    public function test_f4_08_cell_value_normalization_treats_empty_null_dash_and_whitespace_consistently(): void
    {
        $service = app(SpreadsheetInboundSyncService::class);

        $this->assertNull($service->normalizeCellValue(''));
        $this->assertNull($service->normalizeCellValue('   '));
        $this->assertNull($service->normalizeCellValue('-'));
        $this->assertNull($service->normalizeCellValue('  --  '));
        $this->assertNull($service->normalizeCellValue('null'));
        $this->assertNull($service->normalizeCellValue('N/A'));

        $this->assertEquals('Yogyakarta', $service->normalizeCellValue('  Yogyakarta  '));
    }

    /**
     * F4-09: Pencegahan formula injection (CWE-1236) pada saat outbound write ke Spreadsheet.
     */
    public function test_f4_09_formula_injection_guard_on_outbound_escapes_leading_symbols(): void
    {
        $this->assertEquals("'=SUM(A1:A10)", RegistrationSpreadsheetMappingService::escapeFormulaInjection('=SUM(A1:A10)'));
        $this->assertEquals("'+62812345678", RegistrationSpreadsheetMappingService::escapeFormulaInjection('+62812345678'));
        $this->assertEquals("'-100", RegistrationSpreadsheetMappingService::escapeFormulaInjection('-100'));
        $this->assertEquals("'@admin", RegistrationSpreadsheetMappingService::escapeFormulaInjection('@admin'));
        $this->assertEquals("Safe Normal Text", RegistrationSpreadsheetMappingService::escapeFormulaInjection('Safe Normal Text'));
    }

    /**
     * F4-10: Formula inbound terdeteksi dan ditandai (is_formula: true), prefix formula dibersihkan saat apply.
     */
    public function test_f4_10_inbound_formula_detected_and_flagged_without_server_execution(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-010');

        $headers = ['SERTIF_SYNC_ID', 'Catatan_Formula'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-2026-010', [
            'SERTIF_SYNC_ID'  => 'SYNC-2026-010',
            'Catatan_Formula' => '=cmd|calc!A0', // Dangerous formula string
        ]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $staging = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-010')
            ->where('column_identity', 'Catatan_Formula')
            ->first();

        $this->assertNotNull($staging);
        $this->assertTrue($staging->is_formula);

        // Ketika diapprove, formula prefix dibersihkan
        $applyRes = $service->applyStagedChange($staging, $this->adminUser);
        $this->assertTrue($applyRes['success']);
        $this->assertEquals('cmd|calc!A0', $applyRes['applied_value']);
    }

    /**
     * F4-11: Duplicate row ID dalam sheet Google Spreadsheet ditolak keras dan ditandai sebagai conflict_duplicate_row_id.
     */
    public function test_f4_11_duplicate_row_sync_id_in_spreadsheet_flagged_as_conflict(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-DUPLICATE');

        $headers = ['SERTIF_SYNC_ID', 'Nama Lengkap'];
        $this->mockTransport->setMockHeaders('2026', $headers);

        // Pasang 2 baris berbeda dengan ID sinkronisasi yang sama
        $this->mockTransport->setMockRow('2026', 'SYNC-ROW-1', [
            '_row_number'    => 13,
            'SERTIF_SYNC_ID' => 'SYNC-2026-DUPLICATE',
            'Nama Lengkap'   => 'Budi Versi 1',
        ]);
        $this->mockTransport->setMockRow('2026', 'SYNC-ROW-2', [
            '_row_number'    => 14,
            'SERTIF_SYNC_ID' => 'SYNC-2026-DUPLICATE',
            'Nama Lengkap'   => 'Budi Versi 2',
        ]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);

        $dupStaging = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-DUPLICATE')
            ->where('conflict_status', SheetInboundChangeStaging::CONFLICT_DUPLICATE_ROW_ID)
            ->first();

        $this->assertNotNull($dupStaging);
        $this->assertEquals(SheetInboundChangeStaging::STATUS_REJECTED, $dupStaging->staging_status);
        $this->assertStringContainsString('duplikasi baris', $dupStaging->conflict_reason);
    }

    /**
     * F4-12: Schema drift preflight: jika hash remote berubah dari expected_schema_hash, scan berhenti dengan 409 conflict.
     */
    public function test_f4_12_schema_drift_halts_inbound_sync_with_409_conflict(): void
    {
        $headers = ['SERTIF_SYNC_ID', 'Nama Lengkap'];
        $this->mockTransport->setMockHeaders('2026', $headers);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.inbound.scan', [
                $this->connection,
                0,
            ]), [
                'tab_name'             => '2026',
                'expected_schema_hash' => 'outdated-schema-hash-xyz',
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success'    => false,
            'error_code' => 'SCHEMA_CONFLICT',
        ]);
    }

    /**
     * F4-13: Konflik divergen terdeteksi jika database lokal diperbarui setelah waktu sinkronisasi terakhir.
     */
    public function test_f4_13_concurrent_divergent_update_detected(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-013');

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'proyek_magang',
            'answer_value'    => 'Website Toko Online',
        ]);

        // Simulasikan database diperbarui setelah last_synced_at
        $reg->update(['updated_at' => now()->addMinutes(10)]);

        $headers = ['SERTIF_SYNC_ID', 'Proyek_Magang'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-2026-013', [
            'SERTIF_SYNC_ID' => 'SYNC-2026-013',
            'Proyek_Magang'  => 'Aplikasi Mobile Flutter',
        ]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $staging = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-013')
            ->where('column_identity', 'Proyek_Magang')
            ->first();

        $this->assertNotNull($staging);
        $this->assertEquals(SheetInboundChangeStaging::CONFLICT_DIVERGENT, $staging->conflict_status);
        $this->assertStringContainsString('Data lokal database telah diperbarui', $staging->conflict_reason);
    }

    /**
     * F4-14: Otorisasi RBAC: Pemagang dan publik dilarang mengakses endpoint pemindaian maupun persetujuan staging.
     */
    public function test_f4_14_non_admin_cannot_access_inbound_sync_endpoints(): void
    {
        $resp1 = $this->actingAs($this->pemagangUser)
            ->postJson(route('admin.spreadsheet-connections.inbound.scan', [
                $this->connection,
                0,
            ]), ['tab_name' => '2026']);
        $resp1->assertForbidden();

        $resp2 = $this->actingAs($this->pemagangUser)
            ->getJson(route('admin.spreadsheet-connections.inbound.staging', $this->connection));
        $resp2->assertForbidden();
    }

    /**
     * F4-15: Paginasi komprehensif memindai seluruh halaman baris tanpa silent truncation.
     */
    public function test_f4_15_paginated_scan_fetches_multiple_pages_completely(): void
    {
        $headers = ['SERTIF_SYNC_ID', 'Catatan_Paging'];
        $this->mockTransport->setMockHeaders('2026', $headers);

        // Buat 55 baris (melebihi pageSize 50 -> memicu 2 halaman)
        for ($i = 1; $i <= 55; $i++) {
            $syncId = "SYNC-PAGE-{$i}";
            $this->setupRegistrationWithSync($syncId);
            $this->mockTransport->setMockRow('2026', $syncId, [
                'SERTIF_SYNC_ID' => $syncId,
                'Catatan_Paging' => "Data baris ke-{$i}",
            ]);
        }

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals(55, $res['scanned_rows_count']);
        $this->assertEquals(55, $res['staged_changes_count']);
    }

    /**
     * F4-16: Koneksi Spreadsheet yang dinonaktifkan (is_enabled = false) fail-closed.
     */
    public function test_f4_16_disabled_connection_fails_closed(): void
    {
        $this->connection->update(['is_enabled' => false]);

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertFalse($res['success']);
        $this->assertEquals('CONNECTION_DISABLED', $res['error_code']);
    }

    /**
     * F4-17: Kolom unmapped / external-only diabaikan dari penulisan ke database.
     */
    public function test_f4_17_unmapped_and_external_columns_ignored_from_database_sync(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-017');

        $service = app(SpreadsheetInboundSyncService::class);
        $cls = $service->classifyField('Catatan Internal HRD', 'catatan_internal_hrd');

        // Field unmapped/external tidak boleh menimpa DB
        $this->assertContains($cls, [
            SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER,
            SheetInboundChangeStaging::CLASSIFICATION_EXTERNAL_ONLY,
            SheetInboundChangeStaging::CLASSIFICATION_UNMAPPED,
        ]);
    }

    /**
     * F4-18: Sinkronisasi data inbound tidak pernah menghapus, membersihkan, atau mengubah header Spreadsheet.
     */
    public function test_f4_18_headers_are_strictly_preserved_during_sync_zero_mutations(): void
    {
        $headers = ['SERTIF_SYNC_ID', 'Nama Lengkap', 'Header_Tetap_Utuh'];
        $this->mockTransport->setMockHeaders('2026', $headers);

        $service = app(SpreadsheetInboundSyncService::class);
        $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        // Periksa headers setelah operasi scan inbound
        $schemaRes = $this->mockTransport->inspectSchema($this->connection, '2026');
        $afterCols = array_column($schemaRes->context['columns'], 'header_name');

        $this->assertEquals($headers, $afterCols, 'Headers remote tidak boleh bermutasi akibat sync inbound.');
    }

    /**
     * F4-19: Idempotency: memindai ulang baris yang sama memperbarui staging record yang ada, bukan membuat record duplikat.
     */
    public function test_f4_19_idempotency_of_staged_records_avoids_duplicate_staging_entries(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-2026-019');

        $headers = ['SERTIF_SYNC_ID', 'Pilihan_Hobi'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-2026-019', [
            'SERTIF_SYNC_ID' => 'SYNC-2026-019',
            'Pilihan_Hobi'   => 'Bermain Musik',
        ]);

        $service = app(SpreadsheetInboundSyncService::class);

        // Scan pertama
        $res1 = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);
        $this->assertTrue($res1['success']);
        $count1 = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-019')->count();
        $this->assertEquals(1, $count1);

        // Scan kedua (dengan data identik)
        $res2 = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);
        $this->assertTrue($res2['success']);
        $count2 = SheetInboundChangeStaging::where('sync_id', 'SYNC-2026-019')->count();
        $this->assertEquals(1, $count2, 'Idempotensi wajib mencegah penggandaan record staging.');
    }

    /**
     * F4-20: Kegagalan jaringan / timeout pada Apps Script saat scan baris ditangani fail-closed.
     */
    public function test_f4_20_network_timeout_during_inbound_scan_fails_closed(): void
    {
        $headers = ['SERTIF_SYNC_ID', 'Nama Lengkap'];
        $this->mockTransport->setMockHeaders('2026', $headers);

        $this->mockTransport->setForcedPreviewResponse(SpreadsheetTransportResponse::failure(
            errorMessage: 'Google Apps Script 504 Gateway Timeout',
            errorCode: 'TIMEOUT',
            isRetryable: true
        ));

        $service = app(SpreadsheetInboundSyncService::class);
        $res = $service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertFalse($res['success']);
        $this->assertContains($res['error_code'], ['TIMEOUT', 'READ_ROWS_FAILED']);
    }
}
