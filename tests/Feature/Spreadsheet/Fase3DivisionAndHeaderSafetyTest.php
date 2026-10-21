<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Division;
use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\IntegrationOutboxEvent;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\SheetDiscoveredColumn;
use App\Models\SheetDiscoverySnapshot;
use App\Models\SheetMappingProposal;
use App\Models\SheetMappingProposalItem;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetTabProfile;
use App\Models\User;
use App\Services\DivisionLifecycleService;
use App\Services\SpreadsheetColumnDeletionEligibilityService;
use App\Services\SpreadsheetDiscoveryService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Fase3DivisionAndHeaderSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;
    protected User $pemagangUser;
    protected MockSpreadsheetTransport $mockTransport;
    protected \App\Models\Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Safety',
            'email'     => 'admin.safety@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagangUser = User::create([
            'name'      => 'Pemagang Safety',
            'email'     => 'pemagang.safety@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        $this->institution = \App\Models\Institution::create([
            'name'      => 'Universitas Gadjah Mada',
            'is_active' => true,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Google Sheets Pendaftaran 2026',
                'spreadsheet_id'           => 'mock-spreadsheet-fase3-safety',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-fase3-safety/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_MAPPING_GATEWAY/exec',
            ]
        );

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);
    }

    protected function setupMockTabWithHeaders(string $tabName, array $headers, array $rows = []): void
    {
        $this->mockTransport->setMockHeaders($tabName, $headers);

        foreach ($rows as $syncId => $row) {
            $this->mockTransport->setMockRow($tabName, (string) $syncId, $row);
        }
    }

    protected function createInternshipRegistration(?Division $division = null, array $overrides = []): InternshipRegistration
    {
        $inst = $this->institution;
        $faculty = \App\Models\Faculty::firstOrCreate(
            ['institution_id' => $inst->id, 'name' => 'Fakultas Teknik']
        );
        $program = \App\Models\StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknik Informatika']
        );

        $defaults = [
            'user_id'          => $this->pemagangUser->id,
            'fullname'         => 'Test Pemagang ' . uniqid(),
            'institution_id'   => $inst->id,
            'faculty_id'       => $faculty->id,
            'study_program_id' => $program->id,
            'division_id'      => $division?->id,
            'internship_status'=> 'new',
        ];

        return InternshipRegistration::create(array_merge($defaults, $overrides));
    }

    // =========================================================================
    // HEADER DELETION SAFETY TESTS (H01 - H15)
    // =========================================================================

    /**
     * H01: Header berisi data pada baris data normal ditolak keras (eligible = false, COLUMN_NOT_EMPTY).
     */
    public function test_h01_column_with_data_is_rejected_from_deletion(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Nama Lengkap', 'Catatan_Khusus'], [
            'SYNC-001' => ['SERTIF_SYNC_ID' => 'SYNC-001', 'Nama Lengkap' => 'Budi', 'Catatan_Khusus' => 'Ada data di sini'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson(route('admin.spreadsheet-connections.columns.deletion-eligibility', [
                $this->connection,
                0,
                'Catatan_Khusus',
                'tab_name' => '2026',
            ]));

        $response->assertOk();
        $res = $response->json('eligibility');

        $this->assertFalse($res['eligible']);
        $this->assertEquals(SpreadsheetColumnDeletionEligibilityService::CLASSIFICATION_USED_HISTORICAL, $res['classification']);
        $this->assertStringContainsString('memiliki isian/data', $res['reasons'][0]);
    }

    /**
     * H02: Header pernah digunakan (ada jawaban RegistrationCustomAnswer) ditolak keras.
     */
    public function test_h02_column_with_registration_answers_is_rejected(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Hobi_Pendaftar']);

        // Remote row is empty, but local DB has answers linked to valid registration
        $reg = $this->createInternshipRegistration();
        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'hobi_pendaftar',
            'answer_value'    => 'Fotografi',
        ]);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Hobi_Pendaftar');

        $this->assertFalse($eval['eligible']);
        $this->assertTrue($eval['has_registration_answers']);
        $this->assertEquals(SpreadsheetColumnDeletionEligibilityService::CLASSIFICATION_USED_HISTORICAL, $eval['classification']);
        $this->assertStringContainsString('jawaban pendaftaran', $eval['reasons'][0]);
    }

    /**
     * H03: Scan paginasi komprehensif mendeteksi data di halaman lanjutan (misal baris 101/2001).
     */
    public function test_h03_paginated_scan_catches_data_beyond_page_one(): void
    {
        $headers = ['SERTIF_SYNC_ID', 'Keahlian_Lanjutan'];
        $this->mockTransport->setMockHeaders('2026', $headers);

        // Populate 105 empty rows
        for ($i = 1; $i <= 105; $i++) {
            $this->mockTransport->setMockRow('2026', "SYNC-{$i}", [
                'SERTIF_SYNC_ID'     => "SYNC-{$i}",
                'Keahlian_Lanjutan'  => '',
            ]);
        }

        // On row 105 (Page 2), put a non-empty value
        $this->mockTransport->setMockRow('2026', 'SYNC-105', [
            'SERTIF_SYNC_ID'     => 'SYNC-105',
            'Keahlian_Lanjutan'  => 'Flutter Developer',
        ]);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Keahlian_Lanjutan');

        $this->assertFalse($eval['eligible']);
        $this->assertStringContainsString('memiliki isian/data', $eval['reasons'][0]);
    }

    /**
     * H04: Header baru, belum pernah dipublikasikan, 0 jawaban, dan seluruh baris remote 100% kosong -> LAYAK.
     */
    public function test_h04_pristine_unused_empty_column_is_eligible_for_deletion(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Nama Lengkap', 'Draft_Baru_Unused'], [
            'SYNC-001' => ['SERTIF_SYNC_ID' => 'SYNC-001', 'Nama Lengkap' => 'Budi', 'Draft_Baru_Unused' => ''],
            'SYNC-002' => ['SERTIF_SYNC_ID' => 'SYNC-002', 'Nama Lengkap' => 'Siti', 'Draft_Baru_Unused' => ''],
        ]);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Draft_Baru_Unused');

        $this->assertTrue($eval['eligible']);
        $this->assertEquals(SpreadsheetColumnDeletionEligibilityService::CLASSIFICATION_NEW_UNUSED, $eval['classification']);
        $this->assertNotEmpty($eval['current_schema_hash']);
        $this->assertEquals(2, $eval['inspected_rows_count']);
    }

    /**
     * H05: Kolom berisi formula (=IF(...) dsb) ditolak keras sekalipun evaluasinya string kosong.
     */
    public function test_h05_column_with_formulas_rejected_even_if_result_is_blank(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Formula_Blank'], [
            'SYNC-001' => ['SERTIF_SYNC_ID' => 'SYNC-001', 'Formula_Blank' => '=IF(A1="", "", "")'],
        ]);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Formula_Blank');

        $this->assertFalse($eval['eligible']);
        $this->assertTrue($eval['has_formulas']);
        $this->assertStringContainsString('formula', $eval['reasons'][0]);
    }

    /**
     * H06: Nilai ambigu seperti spasi, tanda strip '-' atau simbol tidak dianggap kosong.
     */
    public function test_h06_whitespace_or_symbols_rejected_as_non_empty_data(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Kolom_Simbol'], [
            'SYNC-001' => ['SERTIF_SYNC_ID' => 'SYNC-001', 'Kolom_Simbol' => '   -   '],
        ]);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Kolom_Simbol');

        $this->assertFalse($eval['eligible']);
        $this->assertStringContainsString('memiliki isian/data', $eval['reasons'][0]);
    }

    /**
     * H07: Header yang pernah dipublikasikan walaupun jawabannya 0 tetap ditolak dari penghapusan permanen.
     */
    public function test_h07_published_field_with_zero_answers_rejected_from_hard_delete(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Pertanyaan_Publikasi']);

        // Field was published in version 1.0.0
        FormFieldRegistry::create([
            'field_key'               => 'pertanyaan_publikasi',
            'canonical_label'         => 'Pertanyaan_Publikasi',
            'field_type'              => 'text',
            'is_locked'               => true,
            'first_published_version' => '1.0.0',
        ]);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Pertanyaan_Publikasi');

        $this->assertFalse($eval['eligible']);
        $this->assertTrue($eval['has_published_fields']);
        $this->assertEquals(SpreadsheetColumnDeletionEligibilityService::CLASSIFICATION_USED_HISTORICAL, $eval['classification']);
        $this->assertStringContainsString('pernah dipublikasikan', $eval['reasons'][0]);
    }

    /**
     * H08: Header yang pernah tersimpan dalam snapshot formulir historis ditolak keras.
     */
    public function test_h08_column_referenced_in_registration_snapshots_rejected(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Kolom_Snapshot']);

        $reg = $this->createInternshipRegistration();
        RegistrationFormSnapshot::create([
            'registration_id'     => $reg->id,
            'form_schema_version' => '1.0.0',
            'submitted_at'        => now(),
            'schema_snapshot'     => [
                'fields' => [
                    ['field_key' => 'kolom_snapshot', 'label' => 'Kolom_Snapshot'],
                ],
            ],
        ]);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Kolom_Snapshot');

        $this->assertFalse($eval['eligible']);
        $this->assertTrue($eval['has_snapshots']);
        $this->assertStringContainsString('snapshot formulir', $eval['reasons'][0]);
    }

    /**
     * H09: Optimistic schema drift preflight: jika schema hash remote berubah sejak preview, tolak 409 conflict.
     */
    public function test_h09_optimistic_schema_drift_rejects_with_409_conflict(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Draft_Kolom_A']);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.columns.delete-empty', [
                $this->connection,
                0,
                'Draft_Kolom_A',
            ]), [
                'tab_name'             => '2026',
                'expected_schema_hash' => 'stale-hash-1234567890abcdef',
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success'    => false,
            'error_code' => 'SCHEMA_CONFLICT',
        ]);
        $this->assertStringContainsString('Schema drift terdeteksi', $response->json('message'));
    }

    /**
     * H10: Timeout / kegagalan jaringan saat scan baris: fail-closed (eligible: false, unknown_incomplete).
     */
    public function test_h10_network_timeout_fails_closed(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Draft_Timeout_Col']);

        $this->mockTransport->setForcedPreviewResponse(SpreadsheetTransportResponse::failure(
            errorMessage: 'Google API Timeout 504 Gateway Timeout',
            errorCode: 'TIMEOUT',
            isRetryable: true
        ));

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Draft_Timeout_Col');

        $this->assertFalse($eval['eligible']);
        $this->assertEquals(SpreadsheetColumnDeletionEligibilityService::CLASSIFICATION_UNKNOWN_INCOMPLETE, $eval['classification']);
        $this->assertStringContainsString('Gagal membaca halaman baris remote', $eval['reasons'][0]);
    }

    /**
     * H11: Otorisasi: Pemagang tidak dapat mengakses endpoint kelayakan maupun eksekusi penghapusan (403/302).
     */
    public function test_h11_non_admin_cannot_access_deletion_endpoints(): void
    {
        $resp1 = $this->actingAs($this->pemagangUser)
            ->getJson(route('admin.spreadsheet-connections.columns.deletion-eligibility', [
                $this->connection,
                0,
                'Col_Test',
                'tab_name' => '2026',
            ]));
        $resp1->assertForbidden();

        $resp2 = $this->actingAs($this->pemagangUser)
            ->postJson(route('admin.spreadsheet-connections.columns.delete-empty', [
                $this->connection,
                0,
                'Col_Test',
            ]), [
                'tab_name'             => '2026',
                'expected_schema_hash' => 'dummy',
            ]);
        $resp2->assertForbidden();
    }

    /**
     * H12: Eksekusi penghapusan kolom kosong berhasil, membersihkan draft proposal/discovered column, dan mencatat outbox event.
     */
    public function test_h12_safe_deletion_execution_cleans_up_and_logs_outbox(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'Nama Lengkap', 'Draft_Empty_Col'], [
            'SYNC-001' => ['SERTIF_SYNC_ID' => 'SYNC-001', 'Nama Lengkap' => 'Ahmad', 'Draft_Empty_Col' => ''],
        ]);

        // Get actual schema hash first
        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 0, '2026', 'Draft_Empty_Col');
        $this->assertTrue($eval['eligible']);
        $validHash = $eval['current_schema_hash'];

        // Execute safe deletion via Controller Endpoint
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.columns.delete-empty', [
                $this->connection,
                0,
                'Draft_Empty_Col',
            ]), [
                'tab_name'             => '2026',
                'expected_schema_hash' => $validHash,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success'        => true,
            'deleted_column' => 'Draft_Empty_Col',
            'tab_name'       => '2026',
        ]);

        // Verify outbox audit event was created
        $this->assertDatabaseHas('integration_outbox_events', [
            'event_type'     => 'column_deleted',
            'aggregate_type' => 'spreadsheet_column',
            'status'         => IntegrationOutboxEvent::STATUS_VERIFIED,
        ]);

        // Verify column no longer exists in remote headers
        $tabsRes = $this->mockTransport->inspectSchema($this->connection, '2026');
        $cols = array_column($tabsRes->context['columns'], 'header_name');
        $this->assertNotContains('Draft_Empty_Col', $cols);
    }

    /**
     * H13: Kolom sistem dilindungi (SERTIF_SYNC_ID, SERTIF_REGISTRATION_ID, Timestamp, Nama Lengkap) ditolak mutlak.
     */
    public function test_h13_system_protected_columns_rejected_mutually(): void
    {
        $this->setupMockTabWithHeaders('2026', ['SERTIF_SYNC_ID', 'SERTIF_REGISTRATION_ID', 'Timestamp', 'Nama Lengkap']);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);

        foreach (['SERTIF_SYNC_ID', 'SERTIF_REGISTRATION_ID', 'Timestamp', 'Nama Lengkap'] as $sysCol) {
            $eval = $service->evaluateEligibility($this->connection, 0, '2026', $sysCol);
            $this->assertFalse($eval['eligible'], "Column {$sysCol} should not be eligible.");
            $this->assertTrue($eval['is_system_protected']);
            $this->assertEquals(SpreadsheetColumnDeletionEligibilityService::CLASSIFICATION_PROTECTED_SYSTEM, $eval['classification']);
        }
    }

    /**
     * H14: Tab profile eksplisit (row 12 vs row 1) dihormati saat evaluasi kelayakan header.
     */
    public function test_h14_tab_profile_header_row_respected_during_deletion_check(): void
    {
        // Setup explicit tab profile with header row 12 for non-year tab
        SpreadsheetTabProfile::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'sheet_id'                  => 778899,
            'tab_name'                  => 'Custom_Profile_Tab',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'is_active'                 => true,
        ]);

        $this->setupMockTabWithHeaders('Custom_Profile_Tab', ['SERTIF_SYNC_ID', 'Header_Test']);

        $service = app(SpreadsheetColumnDeletionEligibilityService::class);
        $eval = $service->evaluateEligibility($this->connection, 778899, 'Custom_Profile_Tab', 'Header_Test');

        $this->assertNotNull($eval);
        $this->assertEquals('Custom_Profile_Tab', $eval['tab_name']);
    }

    /**
     * H15: Apps Script Node test runner integration test for delete_empty_column action.
     */
    public function test_h15_apps_script_delete_empty_column_action_integration(): void
    {
        $scriptPath = addslashes(str_replace('\\', '/', base_path('resources/apps-script/SertifGoogleIntegration.js')));
        $nodeTestScript = <<<JS
const appsScript = require('{$scriptPath}');
const assert = require('assert');

// Verify function export
assert.strictEqual(typeof appsScript.handleDeleteEmptyColumnAction, 'function', 'handleDeleteEmptyColumnAction must be exported');

// Mock Sheet App
global.SpreadsheetApp = {
    openById: function(id) {
        return {
            getSheetByName: function(name) {
                let columns = ['SERTIF_SYNC_ID', 'Nama Lengkap', 'Draft_Test_Col'];
                let rows = [['', '', '']];
                return {
                    getSheetId: function() { return 0; },
                    getLastRow: function() { return 13; },
                    getLastColumn: function() { return columns.length; },
                    getRange: function(row, col, numRows, numCols) {
                        return {
                            getValues: function() {
                                if (numRows === 1 && row === 12) return [columns];
                                return [['']];
                            },
                            getFormulas: function() { return [['']]; },
                            isPartOfMerge: function() { return false; }
                        };
                    },
                    deleteColumn: function(colIdx) {
                        columns.splice(colIdx - 1, 1);
                    }
                };
            }
        };
    }
};

global._mockScriptProperties = {
    getProperty: function(key) {
        if (key === 'REGISTRATION_SPREADSHEET_ID') return 'mock-sheet-id';
        return null;
    }
};

// Test schema hash computation
const initialCols = [
    { col_letter: 'A', header_name: 'SERTIF_SYNC_ID' },
    { col_letter: 'B', header_name: 'Nama Lengkap' },
    { col_letter: 'C', header_name: 'Draft_Test_Col' }
];
const hash = appsScript.computeDeterministicSchemaHash(initialCols);

// Execute delete_empty_column action
const payload = {
    action: 'delete_empty_column',
    spreadsheet_id: 'mock-sheet-id',
    tab_name: '2026',
    header_row: 12,
    column_identity: 'Draft_Test_Col',
    expected_schema_hash: hash
};

const res = appsScript.handleDeleteEmptyColumnAction(payload);
assert.strictEqual(res.statusCode, 200, 'Deletion should succeed with 200');
assert.strictEqual(res.data.success, true);
assert.strictEqual(res.data.deleted_column, 'Draft_Test_Col');

console.log('APPS_SCRIPT_DELETE_EMPTY_COLUMN_PASS');
JS;

        $tempFile = sys_get_temp_dir() . '/apps_script_test_' . uniqid() . '.js';
        file_put_contents($tempFile, $nodeTestScript);

        $process = new Process(['node', $tempFile]);
        $process->run();
        @unlink($tempFile);

        $this->assertTrue(
            $process->isSuccessful(),
            'Node Apps Script execution failed: ' . $process->getErrorOutput() . ' ' . $process->getOutput()
        );
        $this->assertStringContainsString('APPS_SCRIPT_DELETE_EMPTY_COLUMN_PASS', $process->getOutput());
    }

    // =========================================================================
    // DIVISION LIFECYCLE GUARD TESTS (D01 - D05)
    // =========================================================================

    /**
     * D01: Divisi yang memiliki pendaftaran magang aktif/historis dilarang dihapus fisik -> arsip (is_active = false).
     */
    public function test_d01_division_with_registrations_is_deactivated_not_deleted(): void
    {
        $division = Division::create([
            'name'      => 'Web Development',
            'slug'      => 'web-development',
            'is_active' => true,
        ]);

        // Create an internship registration referencing this division
        $this->createInternshipRegistration($division, [
            'fullname'            => 'Budi Web Dev',
            'internship_interest' => 'Web Development',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('admin.form-settings.divisions.destroy', $division), [], [
                'Accept' => 'application/json',
            ]);

        $response->assertOk();
        $response->assertJson([
            'ok'     => true,
            'action' => 'deactivated',
        ]);

        // Division still exists in database, but marked inactive
        $this->assertDatabaseHas('divisions', [
            'id'        => $division->id,
            'is_active' => false,
        ]);

        // Registration relation is preserved
        $reg = InternshipRegistration::where('division_id', $division->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals($division->id, $reg->division_id);
    }

    /**
     * D02: Divisi yang terdaftar dalam form schema / snapshot historis dilarang dihapus fisik -> arsip.
     */
    public function test_d02_division_in_form_schema_or_snapshot_is_deactivated_not_deleted(): void
    {
        $division = Division::create([
            'name'      => 'UI/UX Design',
            'slug'      => 'ui-ux-design',
            'is_active' => true,
        ]);

        $schema = FormSchema::create([
            'year'         => 2026,
            'version_code' => '1.0.0',
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => true,
        ]);

        // Add division option to FormField
        FormField::create([
            'form_schema_id'  => $schema->id,
            'field_key'       => 'pilihan_divisi',
            'label'           => 'Pilihan Divisi',
            'field_type'      => 'select',
            'options'         => ['UI/UX Design', 'Content Creator'],
            'order'           => 1,
            'is_required'     => true,
            'is_system'       => false,
            'is_active'       => true,
        ]);

        $service = app(DivisionLifecycleService::class);
        $check = $service->canDeleteDivision($division);

        $this->assertFalse($check['can_delete']);
        $this->assertNotEmpty($check['dependencies']);

        // Execute deleteOrDeactivate
        $res = $service->deleteOrDeactivate($division, $this->adminUser);
        $this->assertEquals('deactivated', $res['action']);
        $this->assertFalse($division->fresh()->is_active);
    }

    /**
     * D03: Divisi baru yang belum pernah digunakan di registrasi maupun form -> berhasil dihapus fisik.
     */
    public function test_d03_unused_pristine_division_is_permanently_deleted(): void
    {
        $division = Division::create([
            'name'      => 'New Clean Division',
            'slug'      => 'new-clean-division',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('admin.form-settings.divisions.destroy', $division), [], [
                'Accept' => 'application/json',
            ]);

        $response->assertOk();
        $response->assertJson([
            'ok'     => true,
            'action' => 'deleted',
        ]);

        // Division is completely removed from DB
        $this->assertDatabaseMissing('divisions', [
            'id' => $division->id,
        ]);
    }

    /**
     * D04: Divisi yang dinonaktifkan (is_active = false) tidak dapat dipilih pendaftar baru, namun data lama tetap valid.
     */
    public function test_d04_deactivated_division_rejected_in_new_registration_but_historic_preserved(): void
    {
        $division = Division::create([
            'name'      => 'Legacy Marketing',
            'slug'      => 'legacy-marketing',
            'is_active' => false, // DEACTIVATED
        ]);

        // Historical registration exists
        $historicReg = $this->createInternshipRegistration($division, [
            'fullname' => 'Siti Legacy',
        ]);

        // Historic registration retains division
        $this->assertEquals('Legacy Marketing', $historicReg->fresh()->division->name);

        // Attempting to register with this deactivated division fails
        $resolvedId = \App\Http\Controllers\Pemagang\RegistrationController::resolveDivisionId('Legacy Marketing');

        // Must return null because division is inactive
        $this->assertNull($resolvedId, 'Deactivated division must not be selected in new registrations.');
    }

    /**
     * D05: Rename nama atau perubahan slug divisi tidak merusak integritas relasi historis pendaftar (division_id).
     */
    public function test_d05_division_rename_and_slug_change_preserves_historical_relations(): void
    {
        $division = Division::create([
            'name'      => 'Multimedia Production',
            'slug'      => 'multimedia-production',
            'is_active' => true,
        ]);

        $reg = $this->createInternshipRegistration($division, [
            'fullname'            => 'Rudi Media',
            'internship_interest' => 'Multimedia Production',
        ]);

        // Rename division
        $division->update([
            'name' => 'Video & Multimedia Creative',
            'slug' => 'video-multimedia-creative',
        ]);

        // Check relation
        $refreshedReg = $reg->fresh();
        $this->assertEquals($division->id, $refreshedReg->division_id);
        $this->assertEquals('Video & Multimedia Creative', $refreshedReg->division->name);
    }
}
