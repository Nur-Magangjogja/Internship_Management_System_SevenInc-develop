<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\SpreadsheetDiscoveryService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\NullSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UniversalSpreadsheetReaderTest extends TestCase
{
    use RefreshDatabase;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;
    protected string $sharedSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->sharedSecret = 'sertif_test_secret_for_universal_reader_12345';

        $this->adminUser = User::create([
            'name'      => 'Admin Reader',
            'email'     => 'admin.reader@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Spreadsheet Pendaftaran Magang 2026',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_READER_GATEWAY/exec',
                'spreadsheet_id'           => '1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug',
            ]
        );
    }

    /**
     * Test 1: Node.js execution of Apps Script list_tabs action with row 12 detection.
     */
    public function test_apps_script_list_tabs_action_via_node(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global._mockScriptProperties = {
  getProperty: (k) => {
    if (k === "REGISTRATION_SPREADSHEET_ID") return "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug";
    if (k === "FOLLOWUP_SPREADSHEET_ID") return "1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4";
    return null;
  }
};

const mockSheets = [
  {
    getName: () => "2026",
    getMaxRows: () => 1000,
    getMaxColumns: () => 40,
    getLastRow: () => 50,
    getLastColumn: () => 40,
    isSheetHidden: () => false,
    getSheetId: () => 101
  },
  {
    getName: () => "TEMPLATE_TAHUN",
    getMaxRows: () => 1000,
    getMaxColumns: () => 40,
    getLastRow: () => 50,
    getLastColumn: () => 40,
    isSheetHidden: () => false,
    getSheetId: () => 102
  },
  {
    getName: () => "Petunjuk",
    getMaxRows: () => 100,
    getMaxColumns: () => 10,
    getLastRow: () => 20,
    getLastColumn: () => 10,
    isSheetHidden: () => false,
    getSheetId: () => 103
  },
  {
    getName: () => "Follow-Up",
    getMaxRows: () => 500,
    getMaxColumns: () => 21,
    getLastRow: () => 30,
    getLastColumn: () => 21,
    isSheetHidden: () => false,
    getSheetId: () => 202
  }
];

global.SpreadsheetApp = {
  openById: (id) => ({
    getSheets: () => mockSheets
  })
};

const payload = {
  action: "list_tabs",
  spreadsheet_id: "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug",
  connection_key: "registration"
};

const res = appsScript.handleListTabsAction(payload);
process.stdout.write(typeof res.getData === "function" ? res.getData() : JSON.stringify(res.data));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode($process->getOutput(), true);

        $this->assertTrue($output['success']);
        $this->assertCount(4, $output['tabs']);

        // First tab is '2026' -> header_row must be 12
        $this->assertEquals('2026', $output['tabs'][0]['name']);
        $this->assertEquals(12, $output['tabs'][0]['header_row']);

        // Second tab is 'TEMPLATE_TAHUN' -> header_row must be 12
        $this->assertEquals('TEMPLATE_TAHUN', $output['tabs'][1]['name']);
        $this->assertEquals(12, $output['tabs'][1]['header_row']);

        // Third tab is 'Petunjuk' -> header_row must be 1
        $this->assertEquals('Petunjuk', $output['tabs'][2]['name']);
        $this->assertEquals(1, $output['tabs'][2]['header_row']);

        // Fourth tab is 'Follow-Up' -> header_row must be 1
        $this->assertEquals('Follow-Up', $output['tabs'][3]['name']);
        $this->assertEquals(1, $output['tabs'][3]['header_row']);
    }

    /**
     * Test 2: Node.js execution of inspect_schema with PII masking and schema hash.
     */
    public function test_apps_script_inspect_schema_with_pii_masking_and_formulas(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global._mockScriptProperties = {
  getProperty: (k) => {
    if (k === "REGISTRATION_SPREADSHEET_ID") return "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug";
    return null;
  }
};

const headers = ["Nama Lengkap", "Email", "No WhatsApp", "NIK", "Formula Total"];
const sampleValues = [
  ["Budi Santoso", "budi.santoso@example.com", "081234567890", "3301020304050001", 100],
  ["Siti Rahma", "siti.rahma@domain.id", "089876543210", "3301020304050002", 200]
];
const sampleFormulas = [
  ["", "", "", "", "=SUM(A13:D13)"],
  ["", "", "", "", "=SUM(A14:D14)"]
];

const mockSheet = {
  getLastRow: () => 14,
  getLastColumn: () => 5,
  getRange: (row, col, numRows, numCols) => {
    if (row === 12 && numRows === 1) {
      return { getValues: () => [headers] };
    }
    return {
      getValues: () => sampleValues,
      getFormulas: () => sampleFormulas
    };
  }
};

global.SpreadsheetApp = {
  openById: (id) => ({
    getSheetByName: (name) => (name === "2026" ? mockSheet : null)
  })
};

const payload = {
  action: "inspect_schema",
  spreadsheet_id: "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug",
  tab_name: "2026",
  header_row: 12
};

const res = appsScript.handleInspectSchemaAction(payload);
process.stdout.write(typeof res.getData === "function" ? res.getData() : JSON.stringify(res.data));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode($process->getOutput(), true);

        $this->assertTrue($output['success']);
        $this->assertEquals('2026', $output['tab_name']);
        $this->assertEquals(12, $output['header_row']);
        $this->assertEquals(5, $output['columns_count']);
        $this->assertNotEmpty($output['schema_hash']);

        // Check columns
        $cols = $output['columns'];
        // Col A (Nama Lengkap) - masked name
        $this->assertEquals('A', $cols[0]['col_letter']);
        $this->assertEquals('Nama Lengkap', $cols[0]['header_name']);
        $this->assertEquals('B*** S***', $cols[0]['samples'][0]);

        // Col B (Email) - masked email
        $this->assertEquals('B', $cols[1]['col_letter']);
        $this->assertStringContainsString('***', $cols[1]['samples'][0]);
        $this->assertStringContainsString('@example.com', $cols[1]['samples'][0]);

        // Col C (No WhatsApp) - masked phone
        $this->assertEquals('C', $cols[2]['col_letter']);
        $this->assertMatchesRegularExpression('/^0812\*{4}890$/', $cols[2]['samples'][0]);

        // Col D (NIK) - masked numeric id
        $this->assertEquals('D', $cols[3]['col_letter']);
        $this->assertMatchesRegularExpression('/^3301?\*{4}001$/', $cols[3]['samples'][0]);

        // Col E (Formula) - formula detected
        $this->assertEquals('E', $cols[4]['col_letter']);
        $this->assertTrue($cols[4]['has_formula']);
        $this->assertEquals('formula', $cols[4]['inferred_type']);
    }

    /**
     * Test 3: Node.js execution of read_rows_page with bounded pagination and zero remote writes.
     */
    public function test_apps_script_read_rows_page_with_bounded_pagination(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global._mockScriptProperties = {
  getProperty: (k) => "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug"
};

const headers = ["Nama", "Email", "Divisi"];
const rowsData = [
  ["Ahmad", "ahmad@gmail.com", "Web Development"],
  ["Bima", "bima@gmail.com", "Graphic Design"]
];

let writeAttempted = false;

const mockSheet = {
  getLastRow: () => 14,
  getLastColumn: () => 3,
  getRange: (row, col, numRows, numCols) => {
    if (row === 12 && numRows === 1) {
      return { getValues: () => [headers] };
    }
    return {
      getValues: () => rowsData,
      setValues: () => { writeAttempted = true; },
      setValue: () => { writeAttempted = true; },
      clear: () => { writeAttempted = true; }
    };
  },
  appendRow: () => { writeAttempted = true; },
  deleteRow: () => { writeAttempted = true; },
  deleteRows: () => { writeAttempted = true; }
};

global.SpreadsheetApp = {
  openById: (id) => ({
    getSheetByName: (name) => mockSheet
  })
};

// Request page 1 with 200 items (should clamp to 100)
const payload = {
  action: "read_rows_page",
  spreadsheet_id: "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug",
  tab_name: "2026",
  header_row: 12,
  page: 1,
  page_size: 200
};

const res = appsScript.handleReadRowsPageAction(payload);
const data = typeof res.getData === "function" ? JSON.parse(res.getData()) : res.data;
data.write_attempted = writeAttempted;
process.stdout.write(JSON.stringify(data));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode($process->getOutput(), true);

        $this->assertTrue($output['success']);
        // Bounded strictly to 100
        $this->assertEquals(100, $output['page_size']);
        $this->assertCount(2, $output['rows']);
        $this->assertEquals(13, $output['rows'][0]['_row_number']);
        $this->assertEquals(14, $output['rows'][1]['_row_number']);
        // Zero writes
        $this->assertFalse($output['write_attempted'], 'Reader MUST NOT attempt any remote spreadsheet writes.');
    }

    /**
     * Test 4: Allowlist rejection in Apps Script.
     */
    public function test_apps_script_rejects_non_allowlisted_spreadsheet_id(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

global._mockScriptProperties = {
  getProperty: (k) => {
    if (k === "REGISTRATION_SPREADSHEET_ID") return "ALLOWED_SPREADSHEET_123";
    return null;
  }
};

const payload = {
  action: "list_tabs",
  spreadsheet_id: "UNAUTHORIZED_MALICIOUS_SPREADSHEET_999"
};

const res = appsScript.handleListTabsAction(payload);
process.stdout.write(typeof res.getData === "function" ? res.getData() : JSON.stringify(res.data));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode($process->getOutput(), true);

        $this->assertFalse($output['success']);
        $this->assertEquals('SPREADSHEET_NOT_ALLOWLISTED', $output['error_code']);
    }

    /**
     * Test 5: GoogleAppsScriptTransport signed requests for read-only actions.
     */
    public function test_google_apps_script_transport_dispatches_signed_read_actions(): void
    {
        Http::fake([
            'https://script.google.com/macros/s/TEST_READER_GATEWAY/exec' => function (\Illuminate\Http\Client\Request $request) {
                $body = json_decode($request->body(), true);
                $this->assertArrayHasKey('signature', $body);
                $this->assertArrayHasKey('nonce', $body);
                $this->assertArrayHasKey('timestamp', $body);

                $action = $body['payload']['action'] ?? '';
                if ($action === 'list_tabs') {
                    return Http::response([
                        'success'        => true,
                        'spreadsheet_id' => $body['payload']['spreadsheet_id'],
                        'tabs'           => [
                            ['name' => '2026', 'header_row' => 12, 'row_count' => 100],
                        ],
                    ], 200);
                }

                if ($action === 'inspect_schema') {
                    return Http::response([
                        'success'       => true,
                        'tab_name'      => $body['payload']['tab_name'],
                        'header_row'    => $body['payload']['header_row'] ?? 12,
                        'columns_count' => 2,
                        'columns'       => [
                            ['col_index' => 1, 'col_letter' => 'A', 'header_name' => 'Nama Lengkap', 'inferred_type' => 'string', 'samples' => ['B*** S***']],
                            ['col_index' => 2, 'col_letter' => 'B', 'header_name' => 'Email', 'inferred_type' => 'string', 'samples' => ['b***@example.com']],
                        ],
                        'schema_hash'   => 'abc123hash',
                    ], 200);
                }

                return Http::response(['success' => false, 'error_code' => 'UNKNOWN'], 400);
            },
        ]);

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => 'https://script.google.com/macros/s/TEST_READER_GATEWAY/exec',
            'shared_secret' => $this->sharedSecret,
        ]);

        $tabsResp = $transport->listTabs($this->connection);
        $this->assertTrue($tabsResp->success);
        $this->assertEquals('2026', $tabsResp->context['tabs'][0]['name']);

        $schemaResp = $transport->inspectSchema($this->connection, '2026', 12);
        $this->assertTrue($schemaResp->success);
        $this->assertEquals('abc123hash', $schemaResp->context['schema_hash']);
    }

    /**
     * Test 6: MockSpreadsheetTransport read-only simulation.
     */
    public function test_mock_spreadsheet_transport_read_operations(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->remoteSheets['2026'] = [
            'SYNC_001' => ['Nama Lengkap' => 'Alita Battle', 'Email' => 'alita@gmail.com', 'No WhatsApp' => '081234567890'],
            'SYNC_002' => ['Nama Lengkap' => 'Cypher Rage', 'Email' => 'cypher@gmail.com', 'No WhatsApp' => '089876543210'],
        ];

        // 1. List tabs
        $tabsResp = $mockTransport->listTabs($this->connection);
        $this->assertTrue($tabsResp->success);
        $this->assertEquals('2026', $tabsResp->context['tabs'][0]['name']);
        $this->assertEquals(12, $tabsResp->context['tabs'][0]['header_row']);

        // 2. Inspect schema
        $schemaResp = $mockTransport->inspectSchema($this->connection, '2026');
        $this->assertTrue($schemaResp->success);
        $this->assertEquals(3, $schemaResp->context['columns_count']);
        $this->assertEquals('A', $schemaResp->context['columns'][0]['col_letter']);
        $this->assertEquals('Nama Lengkap', $schemaResp->context['columns'][0]['header_name']);
        $this->assertEquals('alita@gmail.com', $mockTransport->remoteSheets['2026']['SYNC_001']['Email']);
        // Verify PII masking in samples
        $this->assertStringContainsString('***', $schemaResp->context['columns'][1]['samples'][0]);

        // 3. Read rows page
        $rowsResp = $mockTransport->readRowsPage($this->connection, '2026', 12, 1, 10);
        $this->assertTrue($rowsResp->success);
        $this->assertCount(2, $rowsResp->context['rows']);
        $this->assertEquals(13, $rowsResp->context['rows'][0]['_row_number']);
        $this->assertStringContainsString('***', $rowsResp->context['rows'][0]['Email']);
    }

    /**
     * Test 7: NullSpreadsheetTransport reports unavailable fail-closed.
     */
    public function test_null_spreadsheet_transport_fails_closed(): void
    {
        $nullTransport = new NullSpreadsheetTransport();
        $this->assertFalse($nullTransport->listTabs($this->connection)->success);
        $this->assertFalse($nullTransport->inspectSchema($this->connection, '2026')->success);
        $this->assertFalse($nullTransport->readRowsPage($this->connection, '2026')->success);
    }

    /**
     * Test 8: SpreadsheetDiscoveryService draft mapping proposals without database mutations.
     */
    public function test_discovery_service_draft_mapping_proposals_without_mutations(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->remoteHeaders['2026'] = [
            'Nama Lengkap',
            'Email Mahasiswa',
            'Nomor WA',
            'Asal Kampus',
            'Program Studi',
            'Pilihan Divisi',
            'Field Custom Eksternal'
        ];

        $discoveryService = new SpreadsheetDiscoveryService($mockTransport);

        $proposals = $discoveryService->generateDraftMappingProposals($this->connection, '2026', 12);

        $this->assertTrue($proposals['success']);
        $this->assertEquals(7, $proposals['total_scanned']);
        $this->assertGreaterThanOrEqual(5, $proposals['matched_count']);

        // Check exact match on Nama Lengkap
        $this->assertEquals('name', $proposals['proposals'][0]['suggested_field_key']);
        $this->assertEquals(SpreadsheetDiscoveryService::CONFIDENCE_EXACT, $proposals['proposals'][0]['confidence_level']);

        // Check synonym match on Email Mahasiswa -> email
        $this->assertEquals('email', $proposals['proposals'][1]['suggested_field_key']);

        // Check synonym match on Nomor WA -> phone
        $this->assertEquals('phone', $proposals['proposals'][2]['suggested_field_key']);

        // Check synonym match on Asal Kampus -> institution
        $this->assertEquals('institution', $proposals['proposals'][3]['suggested_field_key']);

        // Check unmatched column
        $lastCol = $proposals['proposals'][6];
        $this->assertEquals('Field Custom Eksternal', $lastCol['scanned_header']);
        $this->assertEquals(SpreadsheetDiscoveryService::CONFIDENCE_NONE, $lastCol['confidence_level']);
    }

    /**
     * Test 9: Web HTTP endpoints in SpreadsheetConnectionController.
     */
    public function test_spreadsheet_connection_controller_read_only_routes(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->remoteSheets['2026'] = [
            'SYNC_001' => ['Nama Lengkap' => 'Jane Doe', 'Email' => 'jane@example.com'],
        ];

        $this->app->instance(SpreadsheetTransportInterface::class, $mockTransport);

        // 1. GET /tabs
        $respTabs = $this->actingAs($this->adminUser)
            ->getJson(route('admin.spreadsheet-connections.tabs', $this->connection));
        $respTabs->assertOk();
        $respTabs->assertJsonPath('success', true);
        $respTabs->assertJsonPath('tabs.0.name', '2026');

        // 2. GET /inspect-schema
        $respInspect = $this->actingAs($this->adminUser)
            ->getJson(route('admin.spreadsheet-connections.inspect-schema', [
                'spreadsheetConnection' => $this->connection,
                'tab_name'              => '2026',
                'header_row'            => 12,
            ]));
        $respInspect->assertOk();
        $respInspect->assertJsonPath('success', true);
        $respInspect->assertJsonPath('columns.0.header_name', 'Nama Lengkap');

        // 3. GET /preview-rows
        $respPreview = $this->actingAs($this->adminUser)
            ->getJson(route('admin.spreadsheet-connections.preview-rows', [
                'spreadsheetConnection' => $this->connection,
                'tab_name'              => '2026',
                'page'                  => 1,
                'page_size'             => 10,
            ]));
        $respPreview->assertOk();
        $respPreview->assertJsonPath('success', true);
        $respPreview->assertJsonPath('rows.0._row_number', 13);

        // 4. GET /draft-mapping-proposals
        $respProposals = $this->actingAs($this->adminUser)
            ->getJson(route('admin.spreadsheet-connections.draft-mapping-proposals', [
                'spreadsheetConnection' => $this->connection,
                'tab_name'              => '2026',
            ]));
        $respProposals->assertOk();
        $respProposals->assertJsonPath('success', true);
        $this->assertNotEmpty($respProposals->json('proposals'));
    }

    /**
     * Test 10: Guardrail test - division with historical registrations cannot be hard deleted and is archived.
     */
    public function test_division_with_registrations_is_archived_instead_of_hard_deleted(): void
    {
        $division = \App\Models\Division::create([
            'name'      => 'Divisi Test Guardrail',
            'slug'      => 'divisi_test_guardrail',
            'is_active' => true,
        ]);

        $city = \App\Models\City::firstOrCreate(['name' => 'Yogyakarta']);
        $inst = \App\Models\Institution::firstOrCreate(
            ['name' => 'Universitas Guardrail', 'normalized_name' => 'universitas guardrail'],
            ['is_active' => true]
        );
        $fac = \App\Models\Faculty::firstOrCreate(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = \App\Models\StudyProgram::firstOrCreate(['name' => 'Teknik Informatika', 'faculty_id' => $fac->id]);

        \App\Models\InternshipRegistration::create([
            'user_id'                 => $this->adminUser->id,
            'fullname'                => 'Test Intern Candidate',
            'student_id'              => '12345678',
            'born_date'               => '2002-01-01',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'institution_id'          => $inst->id,
            'faculty_id'              => $fac->id,
            'study_program_id'        => $prog->id,
            'city_id'                 => $city->id,
            'division_id'             => $division->id,
            'internship_status'       => 'accepted',
            'start_date'              => '2026-06-01',
            'end_date'                => '2026-08-31',
            'internship_type'         => 'WFO',
            'internship_arrangement'  => 'Mandiri',
            'internship_interest'     => 'Programmer',
            'created_at'              => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson(route('admin.form-settings.divisions.destroy', $division));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('archived', true);
        $response->assertJsonPath('is_active', false);

        $this->assertFalse((bool) $division->fresh()->is_active);
    }
}
