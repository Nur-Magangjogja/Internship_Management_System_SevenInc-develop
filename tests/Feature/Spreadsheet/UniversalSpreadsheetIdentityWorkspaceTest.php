<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetTabProfile;
use App\Models\User;
use App\Services\ActiveSheetManualRowReconciliationService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalSpreadsheetIdentityWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected MockSpreadsheetTransport $mockTransport;
    protected SpreadsheetConnection $connectionJogja;
    protected SpreadsheetConnection $connectionAreaKerja;
    protected Brand $brandJogja;
    protected Brand $brandAreaKerja;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(\App\Services\SpreadsheetTransport\SpreadsheetTransportInterface::class, $this->mockTransport);

        $this->admin = User::create([
            'name'      => 'Admin Workspace Tester',
            'email'     => 'admin.ws.tester@seveninc.test',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->brandJogja = Brand::create([
            'name'              => 'Brand Jogja',
            'code'              => 'JOG',
            'slug'              => 'brand-jogja',
            'registration_host' => 'jogja.seveninc.test',
            'is_active'         => true,
        ]);

        $this->brandAreaKerja = Brand::create([
            'name'              => 'Brand Area Kerja',
            'code'              => 'AK',
            'slug'              => 'brand-areakerja',
            'registration_host' => 'areakerja.seveninc.test',
            'is_active'         => true,
        ]);

        $this->connectionJogja = SpreadsheetConnection::create([
            'connection_key'            => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'                  => $this->brandJogja->id,
            'display_name'              => 'Spreadsheet Pendaftaran Brand Jogja',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit',
            'spreadsheet_id'            => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
            'is_enabled'                => true,
            'is_yearly_cloning_enabled' => true,
            'tab_creation_mode'         => 'auto_clone',
            'yearly_tab_template_name'  => 'TEMPLATE_TAHUN',
            'allow_custom_tab_naming'   => true,
            'connection_status'         => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        $this->connectionAreaKerja = SpreadsheetConnection::create([
            'connection_key'            => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'                  => $this->brandAreaKerja->id,
            'display_name'              => 'Spreadsheet Pendaftaran Brand Area Kerja',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/2BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit',
            'spreadsheet_id'            => '2BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
            'is_enabled'                => true,
            'is_yearly_cloning_enabled' => false,
            'connection_status'         => SpreadsheetConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_jogja_fixture_ignores_legend_rows_and_inspects_row_10_headers(): void
    {
        $this->mockTransport->loadJogjaFixture();

        $mappingService = $this->app->make(RegistrationSpreadsheetMappingService::class);
        $service = new ActiveSheetManualRowReconciliationService($this->mockTransport, $mappingService);

        // Inspect columns on 'Form Pendaftaran Magang' with row 10 header
        $inspection = $service->inspectSheetColumns($this->connectionJogja, 'Form Pendaftaran Magang', 10);

        $this->assertTrue($inspection['success']);
        $this->assertEquals(10, $inspection['header_row']);
        $this->assertNotEmpty($inspection['columns']);

        // Assert duplicate KET headers (AM and AN) receive unique column identities
        $colLetters = array_column($inspection['columns'], 'col_letter');
        $this->assertContains('AM', $colLetters);
        $this->assertContains('AN', $colLetters);

        $amCol = collect($inspection['columns'])->firstWhere('col_letter', 'AM');
        $anCol = collect($inspection['columns'])->firstWhere('col_letter', 'AN');
        $this->assertNotNull($amCol);
        $this->assertNotNull($anCol);
        $this->assertNotEquals($amCol['column_identity'], $anCol['column_identity']);
        $this->assertEquals('AM_ket', $amCol['column_identity']);
        $this->assertEquals('AN_ket', $anCol['column_identity']);

        // Inspect rows starting at row 11
        $rowsData = $service->scanWorkspaceRows(
            connection: $this->connectionJogja,
            tabName: 'Form Pendaftaran Magang',
            startRow: 11,
            headerRow: 10,
            pageSize: 20
        );

        $this->assertTrue($rowsData['success']);
        $rows = $rowsData['rows'];
        $this->assertNotEmpty($rows);

        // Verify row #11 is an applicant and NOT the row 1-9 legend text
        $row11 = collect($rows)->firstWhere('row_number', 11);
        $this->assertNotNull($row11);
        $this->assertEquals('Budi Santoso Jogja', $row11['display_name']);
        $this->assertStringContainsString('budi.jogja@test.com', $row11['display_contact']);
    }

    public function test_areakerja_fixture_respects_row_7_headers_and_61_columns(): void
    {
        $this->mockTransport->loadAreaKerjaFixture();

        $mappingService = $this->app->make(RegistrationSpreadsheetMappingService::class);
        $service = new ActiveSheetManualRowReconciliationService($this->mockTransport, $mappingService);

        $inspection = $service->inspectSheetColumns($this->connectionAreaKerja, 'Form Responses 1', 7);

        $this->assertTrue($inspection['success']);
        $this->assertEquals(7, $inspection['header_row']);
        $this->assertEquals(61, $inspection['total_columns']);

        $rowsData = $service->scanWorkspaceRows(
            connection: $this->connectionAreaKerja,
            tabName: 'Form Responses 1',
            startRow: 8,
            headerRow: 7,
            pageSize: 20
        );

        $this->assertTrue($rowsData['success']);
        $rows = $rowsData['rows'];
        $this->assertNotEmpty($rows);

        $row8 = collect($rows)->firstWhere('row_number', 8);
        $this->assertNotNull($row8);
        $this->assertEquals('Rina AreaKerja', $row8['display_name']);
        $this->assertStringContainsString('pelamar.ak1@areakerja.test', $row8['display_contact']);
    }

    public function test_dry_run_reconcile_endpoint_reports_audit_without_database_mutations(): void
    {
        $this->mockTransport->loadJogjaFixture();

        $payload = [
            'tab_name' => 'Form Pendaftaran Magang',
            'header_row' => 10,
            'items' => [
                [
                    'row_index' => 11,
                    'status' => 'accepted',
                    'row_data' => [
                        'Nama Lengkap' => 'Ahmad Fauzan',
                        'Email'        => 'ahmad.fauzan@gmail.com',
                        'No WhatsApp'  => '081234567890',
                    ],
                    'expected_checksum' => null,
                    'registration_id'   => null,
                ],
                [
                    'row_index' => 12,
                    'status' => 'waiting',
                    'row_data' => [
                        'Nama Lengkap' => 'Siti Nurhaliza',
                        'Email'        => 'siti.nur@gmail.com',
                        'No WhatsApp'  => '081234567891',
                    ],
                    'expected_checksum' => null,
                    'registration_id'   => null,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.dry-run-reconcile', $this->connectionJogja),
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $report = $response->json('report');
        $this->assertEquals(2, $report['total_evaluated']);
        $this->assertGreaterThanOrEqual(1, $report['can_write_count']);
        $this->assertCount(2, $report['evaluated_rows']);

        // Crucial: Assert Dry-Run did NOT insert any records into database!
        $this->assertDatabaseMissing('internship_registrations', [
            'email' => 'ahmad.fauzan@gmail.com',
        ]);
        $this->assertDatabaseMissing('internship_registrations', [
            'email' => 'siti.nur@gmail.com',
        ]);
    }

    public function test_composite_column_parser_endpoint_delimiter_and_regex(): void
    {
        // 1. Delimiter Mode
        $delimResponse = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.test-composite-parser', $this->connectionJogja),
            [
                'sample_value' => 'Teknik Informatika - Budi Santoso - 6 Bulan',
                'rule_type'    => 'delimiter',
                'delimiter'    => ' - ',
                'target_keys'  => ['major', 'fullname', 'duration'],
            ]
        );

        $delimResponse->assertStatus(200);
        $delimResponse->assertJson([
            'success' => true,
            'parsed' => [
                'major'    => 'Teknik Informatika',
                'fullname' => 'Budi Santoso',
                'duration' => '6 Bulan',
            ],
        ]);

        // 2. Regex Mode (Named Groups)
        $regexResponse = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.test-composite-parser', $this->connectionJogja),
            [
                'sample_value'  => 'Batch 2026: Rina Wijaya (SMK 1)',
                'rule_type'     => 'regex',
                'regex_pattern' => '/^Batch\s*(?<tahun>\d+):\s*(?<nama>[^(]+)\s*\((?<sekolah>[^)]+)\)$/u',
                'target_keys'   => ['tahun', 'nama', 'sekolah'],
            ]
        );

        $regexResponse->assertStatus(200);
        $regexResponse->assertJson([
            'success' => true,
            'parsed' => [
                'tahun'   => '2026',
                'nama'    => 'Rina Wijaya',
                'sekolah' => 'SMK 1',
            ],
        ]);
    }

    public function test_pre_write_fingerprint_guard_prevents_concurrency_conflict(): void
    {
        $this->mockTransport->loadJogjaFixture();

        $mappingService = $this->app->make(RegistrationSpreadsheetMappingService::class);
        $service = new ActiveSheetManualRowReconciliationService($this->mockTransport, $mappingService);

        // Perform reconciliation with an outdated/stale expectedChecksum
        $result = $service->reconcileRowSyncId(
            connection: $this->connectionJogja,
            tabName: 'Form Pendaftaran Magang',
            rowIndex: 11,
            existingSyncId: null,
            registrationId: null,
            expectedChecksum: 'outdated_hash_abc123',
            rowData: [
                'Nama Lengkap' => 'Ahmad Fauzan',
                'Email'        => 'ahmad.fauzan@gmail.com',
            ],
            targetStatus: 'active'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('berubah secara konkuren', $result['message']);
    }

    public function test_strict_brand_isolation_does_not_leak_or_match_cross_brand_records(): void
    {
        // Create an existing registration strictly under Brand Area Kerja
        $regAreaKerja = InternshipRegistration::create([
            'brand_id'          => $this->brandAreaKerja->id,
            'fullname'          => 'Ahmad Fauzan',
            'email'             => 'ahmad.fauzan@gmail.com',
            'phone_number'      => '081234567890',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $this->mockTransport->loadJogjaFixture();

        $mappingService = $this->app->make(RegistrationSpreadsheetMappingService::class);
        $service = new ActiveSheetManualRowReconciliationService($this->mockTransport, $mappingService);

        // Attempt identity match from Brand Jogja's connection with the same email
        $matchJogja = $service->resolveIdentityMatch(
            connection: $this->connectionJogja,
            rowData: [
                'email' => 'ahmad.fauzan@gmail.com',
                'phone' => '081234567890',
            ]
        );

        // It must NOT match Brand Area Kerja's record due to strict brand isolation!
        $this->assertNotEquals('exact_registration_match', $matchJogja['match_type']);
        $this->assertNotEquals('exact_source_match', $matchJogja['match_type']);
        $this->assertNull($matchJogja['matched_registration_id']);
        $this->assertEquals('no_match', $matchJogja['match_type']);
    }

    public function test_tab_profile_default_suggestions_for_form_pendaftaran_returns_row_10_and_11(): void
    {
        $headerSuggestion = SpreadsheetTabProfile::defaultHeaderRowSuggestion('Form Pendaftaran Magang');
        $this->assertEquals(10, $headerSuggestion);

        $startSuggestion = SpreadsheetTabProfile::defaultDataStartRowSuggestion('Form Pendaftaran Magang');
        $this->assertEquals(11, $startSuggestion);

        // Year tab should return 12 and 13
        $this->assertEquals(12, SpreadsheetTabProfile::defaultHeaderRowSuggestion('2026'));
        $this->assertEquals(13, SpreadsheetTabProfile::defaultDataStartRowSuggestion('2026'));

        // Non-matching tab should return 1 and 2
        $this->assertEquals(1, SpreadsheetTabProfile::defaultHeaderRowSuggestion('Petunjuk'));
        $this->assertEquals(2, SpreadsheetTabProfile::defaultDataStartRowSuggestion('Petunjuk'));
    }

    public function test_save_brand_columns_registers_spreadsheet_tab_profile(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(
            route('admin.spreadsheet-connections.save-brand-columns', $this->connectionJogja),
            [
                'tab_name'   => 'Form Pendaftaran Magang',
                'header_row' => 10,
                'columns'    => [
                    [
                        'header_name'   => 'Timestamp',
                        'col_letter'    => 'A',
                        'category'      => 'standard',
                        'field_key'     => 'created_at',
                        'inferred_type' => 'datetime',
                    ],
                    [
                        'header_name'   => 'Nama Lengkap',
                        'col_letter'    => 'B',
                        'category'      => 'standard',
                        'field_key'     => 'full_name',
                        'inferred_type' => 'string',
                    ],
                ],
            ]
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Tab profile must be automatically created in database
        $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $this->connectionJogja->id)
            ->where('tab_name', 'Form Pendaftaran Magang')
            ->first();

        $this->assertNotNull($profile);
        $this->assertEquals(10, $profile->header_row);
        $this->assertEquals(11, $profile->data_start_row);
        $this->assertTrue($profile->is_active);
    }

    public function test_destroy_proposal_endpoint_removes_proposal_and_items(): void
    {
        $this->actingAs($this->admin);

        $snapshot = \App\Models\SheetDiscoverySnapshot::create([
            'public_uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'spreadsheet_connection_id' => $this->connectionJogja->id,
            'spreadsheet_id'            => $this->connectionJogja->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => 'Form Pendaftaran Magang',
            'header_row'                => 1,
            'data_start_row'            => 2,
            'schema_hash'               => 'test_hash_corrupted',
            'scanned_at'                => now(),
        ]);

        $proposal = \App\Models\SheetMappingProposal::create([
            'public_uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'spreadsheet_connection_id' => $this->connectionJogja->id,
            'snapshot_id'               => $snapshot->id,
            'expected_schema_hash'      => 'test_hash_corrupted',
            'year'                      => 2026,
            'status'                    => \App\Models\SheetMappingProposal::STATUS_DRAFT,
            'created_by'                => $this->admin->id,
        ]);

        \App\Models\SheetMappingProposalItem::create([
            'proposal_id'     => $proposal->id,
            'column_identity' => 'B_belum_di_follow_up',
            'col_letter'      => 'B',
            'physical_index'  => 1,
            'observed_label'  => 'belum di follow up',
            'action'          => \App\Models\SheetMappingProposalItem::ACTION_IGNORE,
        ]);

        $this->assertDatabaseHas('sheet_mapping_proposals', ['id' => $proposal->id]);
        $this->assertDatabaseHas('sheet_mapping_proposal_items', ['proposal_id' => $proposal->id]);

        $deleteResponse = $this->deleteJson(
            route('admin.spreadsheet-connections.proposals.destroy', [$this->connectionJogja, $proposal])
        );

        $deleteResponse->assertStatus(200);
        $deleteResponse->assertJson(['success' => true]);

        $this->assertDatabaseMissing('sheet_mapping_proposals', ['id' => $proposal->id]);
        $this->assertDatabaseMissing('sheet_mapping_proposal_items', ['proposal_id' => $proposal->id]);
    }
}
