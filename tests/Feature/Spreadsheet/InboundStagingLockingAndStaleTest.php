<?php

namespace Tests\Feature\Spreadsheet;

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
use App\Services\SpreadsheetInboundSyncService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InboundStagingLockingAndStaleTest extends TestCase
{
    use RefreshDatabase;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;
    protected User $pemagangUser;
    protected MockSpreadsheetTransport $mockTransport;
    protected Institution $institution;
    protected Division $division;
    protected SpreadsheetInboundSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Staging Test',
            'email'     => 'admin.staging@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagangUser = User::create([
            'name'      => 'Pemagang Staging Test',
            'email'     => 'pemagang.staging@example.com',
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
                'spreadsheet_id'           => 'mock-spreadsheet-staging-locking',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-staging-locking/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_STAGING_LOCKING/exec',
            ]
        );

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);
        $this->service = app(SpreadsheetInboundSyncService::class);
    }

    protected function createRegistration(array $overrides = []): InternshipRegistration
    {
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $this->institution->id, 'name' => 'Fakultas Teknik']
        );
        $program = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknologi Informasi']
        );

        $defaults = [
            'user_id'          => $this->pemagangUser->id,
            'fullname'         => 'Siti Aminah',
            'institution_id'   => $this->institution->id,
            'faculty_id'       => $faculty->id,
            'study_program_id' => $program->id,
            'division_id'      => $this->division->id,
            'phone_number'     => '081234567890',
            'internship_status'=> InternshipRegistration::STATUS_ACCEPTED,
        ];

        return InternshipRegistration::create(array_merge($defaults, $overrides));
    }

    protected function setupRegistrationWithSync(string $syncId, array $regOverrides = []): InternshipRegistration
    {
        $reg = $this->createRegistration($regOverrides);

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

    /**
     * 1. Unknown header without mapping is classified as UNMAPPED and ignored from staging.
     */
    public function test_unknown_header_without_mapping_is_unmapped_and_not_staged(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-STALE-001');

        // Create an active schema version with mapping so activeMappings is not empty
        $schemaVersion = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'sheet_tab_name'            => '2026',
            'year'                      => 2026,
            'version'                   => 'v1',
            'is_active'                 => true,
            'schema_hash'               => 'hash-v1',
        ]);

        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'sheet_header'                  => 'Nama Lengkap',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'fullname',
            'direction'                     => 'bidirectional',
            'mapping_status'                => 'approved',
            'column_order'                  => 1,
            'column_identity'               => 'col_fullname',
        ]);

        $headers = ['SERTIF_SYNC_ID', 'Nama Lengkap', 'Catatan_Misterius_Tanpa_Mapping'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-STALE-001', [
            'SERTIF_SYNC_ID'                    => 'SYNC-STALE-001',
            'Nama Lengkap'                      => 'Siti Aminah',
            'Catatan_Misterius_Tanpa_Mapping'   => 'Data tidak dikenal yang tidak boleh masuk DB',
        ]);

        $res = $this->service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        // Only known mapped or system fields should be staged; unknown without mapping is skipped
        $staged = SheetInboundChangeStaging::where('sync_id', 'SYNC-STALE-001')
            ->where('header_name', 'Catatan_Misterius_Tanpa_Mapping')
            ->first();

        $this->assertNull($staged, 'Unknown header without active approved mapping must NOT be staged.');
    }

    /**
     * 2. Mapping with direction=outbound or ignore is forbidden from inbound staging.
     */
    public function test_outbound_direction_mapping_is_rejected_from_inbound_staging(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-STALE-002');

        $schemaVersion = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'sheet_tab_name'            => '2026',
            'year'                      => 2026,
            'version'                   => 'v1',
            'is_active'                 => true,
            'schema_hash'               => 'hash-v1-outbound',
        ]);

        // Mapping explicitly marked outbound only
        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'sheet_header'                  => 'Nilai Evaluasi Akhir',
            'target_entity'                 => 'registration_custom_answer',
            'target_field'                  => 'nilai_evaluasi',
            'direction'                     => 'outbound',
            'mapping_status'                => 'approved',
            'column_order'                  => 1,
            'column_identity'               => 'col_evaluasi',
        ]);

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'nilai_evaluasi',
            'answer_value'    => 'A',
        ]);

        $headers = ['SERTIF_SYNC_ID', 'Nilai Evaluasi Akhir'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-STALE-002', [
            'SERTIF_SYNC_ID'       => 'SYNC-STALE-002',
            'Nilai Evaluasi Akhir' => 'A+ (Diubah dari Sheet)',
        ]);

        $res = $this->service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);

        $this->assertTrue($res['success']);
        $staged = SheetInboundChangeStaging::where('sync_id', 'SYNC-STALE-002')
            ->where('header_name', 'Nilai Evaluasi Akhir')
            ->first();

        $this->assertNull($staged, 'Mapping with direction=outbound must NOT be staged inbound.');
        $this->assertEquals('A', $reg->customAnswers()->where('field_key', 'nilai_evaluasi')->first()->answer_value);
    }

    /**
     * 3. Stale conflict blocks approval if local database value was updated concurrently after scan.
     */
    public function test_stale_conflict_blocks_approval_when_local_db_updated_after_scan(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-STALE-003', [
            'fullname' => 'Nama Sebelum Scan',
        ]);

        // Stage a change from Google Sheet
        $staging = SheetInboundChangeStaging::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->connection->id,
            'tab_name'                  => '2026',
            'sheet_id'                  => 0,
            'sync_id'                   => 'SYNC-STALE-003',
            'registration_id'           => $reg->id,
            'column_identity'           => 'Nama Lengkap',
            'header_name'               => 'Nama Lengkap',
            'field_key'                 => 'fullname',
            'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE,
            'raw_remote_value'          => 'Nama Diusulkan Dari Sheet',
            'normalized_remote_value'   => 'Nama Diusulkan Dari Sheet',
            'current_db_value'          => 'Nama Sebelum Scan',
            'old_checksum'              => hash('sha256', 'Nama Sebelum Scan'),
            'new_checksum'              => hash('sha256', 'Nama Diusulkan Dari Sheet'),
            'conflict_status'           => SheetInboundChangeStaging::CONFLICT_CORE_REQUIRES_APPROVAL,
            'staging_status'            => SheetInboundChangeStaging::STATUS_STAGED,
            'resolution_policy'         => SheetInboundChangeStaging::POLICY_PENDING,
        ]);

        // SIMULATE CONCURRENT LOCAL UPDATE: Admin edits the registration directly in the web UI
        $reg->update(['fullname' => 'Nama Diedit Admin Langsung']);

        // Now Admin tries to approve the stale staging change
        $res = $this->service->applyStagedChange($staging, $this->adminUser);

        $this->assertFalse($res['success']);
        $this->assertEquals('STALE_DATA_CONFLICT', $res['error_code']);

        // Staging record must be updated to CONFLICT_DIVERGENT and remain unapplied
        $staging->refresh();
        $this->assertEquals(SheetInboundChangeStaging::STATUS_STAGED, $staging->staging_status);
        $this->assertEquals(SheetInboundChangeStaging::CONFLICT_DIVERGENT, $staging->conflict_status);
        $this->assertStringContainsString('stale approval terdeteksi', $staging->conflict_reason);

        // Local DB remains the fresh admin value, NOT overwritten by remote value
        $this->assertEquals('Nama Diedit Admin Langsung', $reg->fresh()->fullname);
    }

    /**
     * 4. Double apply is rejected fail-closed with ALREADY_APPLIED.
     */
    public function test_double_apply_is_prevented_fail_closed(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-STALE-004');

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'bahasa_pemrograman',
            'answer_value'    => 'Python',
        ]);

        $staging = SheetInboundChangeStaging::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->connection->id,
            'tab_name'                  => '2026',
            'sync_id'                   => 'SYNC-STALE-004',
            'registration_id'           => $reg->id,
            'column_identity'           => 'Bahasa_Pemrograman',
            'header_name'               => 'Bahasa_Pemrograman',
            'field_key'                 => 'bahasa_pemrograman',
            'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER,
            'raw_remote_value'          => 'Python & Rust',
            'normalized_remote_value'   => 'Python & Rust',
            'current_db_value'          => 'Python',
            'conflict_status'           => SheetInboundChangeStaging::CONFLICT_CLEAN_INBOUND,
            'staging_status'            => SheetInboundChangeStaging::STATUS_STAGED,
        ]);

        // First apply succeeds
        $res1 = $this->service->applyStagedChange($staging, $this->adminUser);
        $this->assertTrue($res1['success']);
        $this->assertEquals('Python & Rust', $reg->customAnswers()->where('field_key', 'bahasa_pemrograman')->first()->answer_value);

        // Second apply fails fail-closed
        $res2 = $this->service->applyStagedChange($staging, $this->adminUser);
        $this->assertFalse($res2['success']);
        $this->assertEquals('ALREADY_APPLIED', $res2['error_code']);
    }

    /**
     * 5. Unallowed core fields not on whitelist are rejected and fail-closed.
     */
    public function test_unallowed_core_field_not_on_whitelist_fails_closed(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-STALE-005', [
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        // Craft a staging record attempting to mutate an unallowed field via core administrative
        $staging = SheetInboundChangeStaging::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->connection->id,
            'tab_name'                  => '2026',
            'sync_id'                   => 'SYNC-STALE-005',
            'registration_id'           => $reg->id,
            'column_identity'           => 'internship_status',
            'header_name'               => 'Status Magang',
            'field_key'                 => 'internship_status',
            'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE,
            'raw_remote_value'          => 'completed',
            'normalized_remote_value'   => 'completed',
            'current_db_value'          => 'accepted',
            'conflict_status'           => SheetInboundChangeStaging::CONFLICT_CORE_REQUIRES_APPROVAL,
            'staging_status'            => SheetInboundChangeStaging::STATUS_STAGED,
        ]);

        $res = $this->service->applyStagedChange($staging, $this->adminUser);

        $this->assertFalse($res['success']);
        $this->assertEquals('CORE_FIELD_UPDATE_FAILED', $res['error_code']);
        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $reg->fresh()->internship_status);
    }

    /**
     * 6. Sensitive PII is masked in conflict reason and outbox event payload.
     */
    public function test_pii_masked_in_conflict_reason_and_outbox_event(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-STALE-006', [
            'phone_number' => '081234567890',
        ]);

        $headers = ['SERTIF_SYNC_ID', 'Nomor Telepon'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-STALE-006', [
            'SERTIF_SYNC_ID' => 'SYNC-STALE-006',
            'Nomor Telepon'  => '089876543210',
        ]);

        $res = $this->service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);
        $this->assertTrue($res['success']);

        $staging = SheetInboundChangeStaging::where('sync_id', 'SYNC-STALE-006')
            ->where('column_identity', 'Nomor Telepon')
            ->first();

        $this->assertNotNull($staging);
        // Verify phone number is masked in conflict_reason
        $this->assertStringNotContainsString('081234567890', $staging->conflict_reason);
        $this->assertStringNotContainsString('089876543210', $staging->conflict_reason);
        $this->assertStringContainsString('***', $staging->conflict_reason);

        // Apply change and verify outbox payload masks actor email and values
        $applyRes = $this->service->applyStagedChange($staging, $this->adminUser);
        $this->assertTrue($applyRes['success']);

        $outbox = IntegrationOutboxEvent::where('event_type', 'inbound_change_applied')
            ->where('aggregate_id', (string) $reg->id)
            ->latest()
            ->first();

        $this->assertNotNull($outbox);
        $payload = $outbox->payload;
        $this->assertStringNotContainsString('081234567890', (string) ($payload['old_value'] ?? ''));
        $this->assertStringNotContainsString('089876543210', (string) ($payload['new_value'] ?? ''));
        $this->assertStringContainsString('***@example.com', (string) ($payload['actor_email'] ?? ''));
    }

    /**
     * 7. Scanning does not overwrite staging that has already been applied or rejected.
     */
    public function test_scanning_does_not_overwrite_already_applied_or_rejected_staging(): void
    {
        $reg = $this->setupRegistrationWithSync('SYNC-STALE-007', [
            'fullname' => 'Nama Terdaftar Asli',
        ]);

        // Create an already applied staging record
        $staging = SheetInboundChangeStaging::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->connection->id,
            'tab_name'                  => '2026',
            'sheet_id'                  => 0,
            'sync_id'                   => 'SYNC-STALE-007',
            'registration_id'           => $reg->id,
            'column_identity'           => 'Nama Lengkap',
            'header_name'               => 'Nama Lengkap',
            'field_key'                 => 'fullname',
            'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE,
            'raw_remote_value'          => 'Nama Baru Sheet',
            'normalized_remote_value'   => 'Nama Baru Sheet',
            'current_db_value'          => 'Nama Terdaftar Asli',
            'conflict_status'           => SheetInboundChangeStaging::CONFLICT_NONE,
            'staging_status'            => SheetInboundChangeStaging::STATUS_APPLIED,
            'resolution_policy'         => SheetInboundChangeStaging::POLICY_ACCEPT_REMOTE,
            'reviewed_by'               => $this->adminUser->id,
            'reviewed_at'               => now()->subMinutes(10),
            'applied_at'                => now()->subMinutes(10),
        ]);

        $headers = ['SERTIF_SYNC_ID', 'Nama Lengkap'];
        $this->mockTransport->setMockHeaders('2026', $headers);
        $this->mockTransport->setMockRow('2026', 'SYNC-STALE-007', [
            'SERTIF_SYNC_ID' => 'SYNC-STALE-007',
            'Nama Lengkap'   => 'Nama Berbeda Lagi',
        ]);

        $res = $this->service->scanAndStageInboundChanges($this->connection, 0, '2026', $this->adminUser);
        $this->assertTrue($res['success']);

        // Staging record should remain STATUS_APPLIED, not reset to STATUS_STAGED
        $staging->refresh();
        $this->assertEquals(SheetInboundChangeStaging::STATUS_APPLIED, $staging->staging_status);
        $this->assertEquals('Nama Baru Sheet', $staging->normalized_remote_value);
    }
}
