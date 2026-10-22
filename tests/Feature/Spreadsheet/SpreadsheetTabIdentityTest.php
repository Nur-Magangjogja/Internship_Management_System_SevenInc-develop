<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\SheetDiscoverySnapshot;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetTabProfile;
use App\Models\User;
use App\Services\SpreadsheetDiscoveryService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpreadsheetTabIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected SpreadsheetConnection $connection;
    protected MockSpreadsheetTransport $mockTransport;
    protected SpreadsheetDiscoveryService $discoveryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Tab Identity',
            'email'     => 'admin.tab.identity@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Google Sheets Tab Identity 2026',
                'spreadsheet_id'           => 'mock-spreadsheet-identity-2026',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-identity-2026/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_IDENTITY/exec',
            ]
        );

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);
        $this->discoveryService = app(SpreadsheetDiscoveryService::class);
    }

    /**
     * Test P1.3: Tab with Sheet ID 0 is valid and treated as an authentic sheetId, not empty or defaulted.
     */
    public function test_tab_with_id_zero_is_valid_and_not_treated_as_empty(): void
    {
        // Setup remote mock tabs including sheetId = 0
        $this->mockTransport->setMockHeaders('MainTab0', ['Timestamp', 'Nama', 'Email']);
        $this->mockTransport->remoteSheets['MainTab0'] = [];

        // Save tab profile with sheet_id = 0
        $profile = $this->discoveryService->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 0,
            tabName: 'MainTab0',
            explicitHeaderRow: 1,
            explicitDataStartRow: 2
        );

        $this->assertSame(0, $profile->sheet_id);
        $this->assertSame('MainTab0', $profile->tab_name);
        $this->assertSame(1, $profile->header_row);
        $this->assertSame(2, $profile->data_start_row);

        // Save snapshot with sheet_id = 0
        $snapshot = $this->discoveryService->saveDiscoverySnapshot(
            $this->connection,
            tabName: 'MainTab0',
            headerRow: 1,
            sheetId: 0,
            user: $this->adminUser
        );

        $this->assertNotNull($snapshot);
        $this->assertSame(0, $snapshot->sheet_id);
        $this->assertSame('MainTab0', $snapshot->tab_name);
    }

    /**
     * Test P1.3: Null or missing sheet_id is rejected when saving snapshot.
     */
    public function test_null_sheet_id_is_rejected_on_snapshot_creation(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.snapshots.store', $this->connection), [
                'tab_name'   => 'TabWithoutId',
                'header_row' => 1,
                // sheet_id missing
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sheet_id']);
    }

    /**
     * Test P1.3: Non-existent remote sheet_id is rejected with INVALID_SHEET_IDENTITY.
     */
    public function test_unknown_remote_sheet_id_is_rejected(): void
    {
        $result = $this->discoveryService->inspectTabSchema(
            $this->connection,
            tabName: 'NonExistentTab',
            headerRow: 1,
            sheetId: 999999
        );

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_SHEET_IDENTITY', $result['error_code']);
    }

    /**
     * Test P1.3: Tab rename remotely preserves profile by stable sheet_id.
     */
    public function test_tab_rename_preserves_identity_by_sheet_id(): void
    {
        // First profile created for sheetId 555 with initial name "2026_Draft"
        $profile = $this->discoveryService->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 555,
            tabName: '2026_Draft',
            explicitHeaderRow: 12,
            explicitDataStartRow: 13
        );

        $this->assertSame(555, $profile->sheet_id);
        $this->assertSame('2026_Draft', $profile->tab_name);

        // Later, tab is renamed remotely to "2026_Final", but sheetId remains 555
        $updatedProfile = $this->discoveryService->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 555,
            tabName: '2026_Final'
        );

        $this->assertSame($profile->id, $updatedProfile->id, 'Profile identity preserved');
        $this->assertSame('2026_Final', $updatedProfile->tab_name, 'Tab name updated on rename');
        $this->assertSame(12, $updatedProfile->header_row, 'Explicit header row preserved');
        $this->assertSame(13, $updatedProfile->data_start_row, 'Explicit data start row preserved');
    }

    /**
     * Test P1.3: Changing data_start_row without changing header_row is persisted in database.
     */
    public function test_data_start_row_change_without_header_row_change_is_persisted(): void
    {
        $profile = $this->discoveryService->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 777,
            tabName: 'TabWithDataOffset',
            explicitHeaderRow: 12,
            explicitDataStartRow: 13
        );

        $this->assertSame(12, $profile->header_row);
        $this->assertSame(13, $profile->data_start_row);

        // User updates data_start_row to 16, keeping header_row unchanged at 12
        $updated = $this->discoveryService->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 777,
            tabName: 'TabWithDataOffset',
            explicitHeaderRow: 12,
            explicitDataStartRow: 16
        );

        $this->assertSame(12, $updated->header_row);
        $this->assertSame(16, $updated->data_start_row, 'data_start_row must be updated even when header_row does not change');

        $freshProfile = SpreadsheetTabProfile::find($profile->id);
        $this->assertSame(16, $freshProfile->data_start_row);
    }

    /**
     * Test P1.3: Invalid header_row (< 1) or data_start_row (<= header_row) is rejected with 422.
     */
    public function test_invalid_header_or_data_start_row_is_rejected_with_422(): void
    {
        // 1. data_start_row <= header_row via Controller
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.tab-profile.save', $this->connection), [
                'sheet_id'       => 888,
                'tab_name'       => 'InvalidProfile',
                'header_row'     => 12,
                'data_start_row' => 12, // Equal to header_row
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('message', 'data_start_row harus lebih besar dari header_row.');

        // 2. data_start_row < header_row
        $response2 = $this->actingAs($this->adminUser)
            ->postJson(route('admin.spreadsheet-connections.tab-profile.save', $this->connection), [
                'sheet_id'       => 888,
                'tab_name'       => 'InvalidProfile2',
                'header_row'     => 12,
                'data_start_row' => 10, // Less than header_row
            ]);

        $response2->assertStatus(422);
        $response2->assertJson(['success' => false]);
    }

    /**
     * Test: Default tab inference rules for 4-digit years, TEMPLATE_TAHUN, Petunjuk, and copied future year tabs (e.g. 2027).
     */
    public function test_tab_default_inference_rules(): void
    {
        // 1. 4-digit years => 12/13
        $this->assertSame(12, SpreadsheetTabProfile::defaultHeaderRowSuggestion('2026'));
        $this->assertSame(13, SpreadsheetTabProfile::defaultDataStartRowSuggestion('2026'));

        $this->assertSame(12, SpreadsheetTabProfile::defaultHeaderRowSuggestion('2027'));
        $this->assertSame(13, SpreadsheetTabProfile::defaultDataStartRowSuggestion('2027'));

        $this->assertSame(12, SpreadsheetTabProfile::defaultHeaderRowSuggestion('2028'));
        $this->assertSame(13, SpreadsheetTabProfile::defaultDataStartRowSuggestion('2028'));

        // 2. TEMPLATE_TAHUN => 12/13
        $this->assertSame(12, SpreadsheetTabProfile::defaultHeaderRowSuggestion('TEMPLATE_TAHUN'));
        $this->assertSame(13, SpreadsheetTabProfile::defaultDataStartRowSuggestion('TEMPLATE_TAHUN'));

        // 3. Petunjuk and other non-yearly tabs => 1/2
        $this->assertSame(1, SpreadsheetTabProfile::defaultHeaderRowSuggestion('Petunjuk'));
        $this->assertSame(2, SpreadsheetTabProfile::defaultDataStartRowSuggestion('Petunjuk'));

        $this->assertSame(1, SpreadsheetTabProfile::defaultHeaderRowSuggestion('Follow-Up'));
        $this->assertSame(2, SpreadsheetTabProfile::defaultDataStartRowSuggestion('Follow-Up'));

        $this->assertSame(1, SpreadsheetTabProfile::defaultHeaderRowSuggestion('Log'));
        $this->assertSame(2, SpreadsheetTabProfile::defaultDataStartRowSuggestion('Log'));
    }

    /**
     * Test: Discovery service listTabs, inspectSchema, snapshot, proposals, and preview consistently apply inference.
     */
    public function test_discovery_service_flow_for_template_tahun_and_yearly_tabs(): void
    {
        $this->mockTransport->setMockHeaders('2026', ['Nama', 'Email', 'No WA']);
        $this->mockTransport->setMockHeaders('TEMPLATE_TAHUN', ['Nama', 'Email', 'No WA']);
        $this->mockTransport->setMockHeaders('Petunjuk', ['Kolom', 'Keterangan']);
        $this->mockTransport->setMockHeaders('2027', ['Nama', 'Email', 'No WA']);

        $this->mockTransport->remoteSheets['2026'] = [
            ['Nama' => 'Peserta 1', 'Email' => 'p1@example.com', 'No WA' => '08123456789'],
        ];
        $this->mockTransport->remoteSheets['TEMPLATE_TAHUN'] = [];
        $this->mockTransport->remoteSheets['Petunjuk'] = [
            ['Kolom' => 'Nama', 'Keterangan' => 'Isikan nama lengkap'],
        ];
        $this->mockTransport->remoteSheets['2027'] = [];

        // 1. List Tabs
        $tabsResult = $this->discoveryService->listTabs($this->connection);
        $this->assertTrue($tabsResult['success']);
        
        $tabsByName = collect($tabsResult['tabs'])->keyBy('name');
        
        $this->assertEquals(12, $tabsByName['2026']['header_row']);
        $this->assertEquals(13, $tabsByName['2026']['data_start_row']);

        $this->assertEquals(12, $tabsByName['TEMPLATE_TAHUN']['header_row']);
        $this->assertEquals(13, $tabsByName['TEMPLATE_TAHUN']['data_start_row']);

        $this->assertEquals(1, $tabsByName['Petunjuk']['header_row']);
        $this->assertEquals(2, $tabsByName['Petunjuk']['data_start_row']);

        $this->assertEquals(12, $tabsByName['2027']['header_row']);
        $this->assertEquals(13, $tabsByName['2027']['data_start_row']);

        // 2. Inspect Tab Schema
        $templateSheetId = $tabsByName['TEMPLATE_TAHUN']['id'];
        $inspectTemplate = $this->discoveryService->inspectTabSchema($this->connection, 'TEMPLATE_TAHUN', null, $templateSheetId);
        $this->assertTrue($inspectTemplate['success']);
        $this->assertEquals(12, $inspectTemplate['header_row']);
        $this->assertEquals(13, $inspectTemplate['data_start_row']);

        $petunjukSheetId = $tabsByName['Petunjuk']['id'];
        $inspectPetunjuk = $this->discoveryService->inspectTabSchema($this->connection, 'Petunjuk', null, $petunjukSheetId);
        $this->assertTrue($inspectPetunjuk['success']);
        $this->assertEquals(1, $inspectPetunjuk['header_row']);
        $this->assertEquals(2, $inspectPetunjuk['data_start_row']);

        // 3. Save Discovery Snapshot
        $snapshot = $this->discoveryService->saveDiscoverySnapshot(
            $this->connection,
            'TEMPLATE_TAHUN',
            null,
            $templateSheetId,
            $this->adminUser
        );
        $this->assertEquals(12, $snapshot->header_row);
        $this->assertEquals(13, $snapshot->data_start_row);

        // 4. Create Mapping Proposal
        $proposal = $this->discoveryService->createMappingProposal($this->connection, $snapshot);
        $this->assertNotNull($proposal);
        $this->assertGreaterThan(0, $proposal->year, 'Proposal year must be valid and not 0');

        // 5. Preview Rows
        $preview = $this->discoveryService->previewRows($this->connection, 'TEMPLATE_TAHUN', null, 1, 10, $templateSheetId);
        $this->assertTrue($preview['success']);
        $this->assertEquals(12, $preview['header_row']);

        // 6. Resolve Profile
        $profile = $this->discoveryService->resolveTabHeaderProfile($this->connection, $templateSheetId, 'TEMPLATE_TAHUN');
        $this->assertEquals(12, $profile->header_row);
        $this->assertEquals(13, $profile->data_start_row);
    }

    /**
     * Test: Existing legacy profile for TEMPLATE_TAHUN (1/2) is updated/healed to 12/13, while 2026 and Petunjuk are preserved.
     */
    public function test_existing_template_tahun_profile_upgrade_preserves_others(): void
    {
        // Setup legacy explicit profile for TEMPLATE_TAHUN with header_row=1 and data_start_row=2
        $legacyTemplateProfile = SpreadsheetTabProfile::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'sheet_id'                  => 555111,
            'tab_name'                  => 'TEMPLATE_TAHUN',
            'header_row'                => 1,
            'data_start_row'            => 2,
            'is_active'                 => true,
        ]);

        // Setup profile for 2026 (12/13)
        $profile2026 = SpreadsheetTabProfile::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'sheet_id'                  => 555222,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'is_active'                 => true,
        ]);

        // Setup profile for Petunjuk (1/2)
        $profilePetunjuk = SpreadsheetTabProfile::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'sheet_id'                  => 555333,
            'tab_name'                  => 'Petunjuk',
            'header_row'                => 1,
            'data_start_row'            => 2,
            'is_active'                 => true,
        ]);

        // Resolve profile without explicit overrides
        $resolvedTemplate = SpreadsheetTabProfile::resolveProfile(
            $this->connection,
            555111,
            'TEMPLATE_TAHUN'
        );

        $this->assertSame(12, $resolvedTemplate->header_row);
        $this->assertSame(13, $resolvedTemplate->data_start_row);

        // Verify 2026 is unchanged
        $fresh2026 = $profile2026->fresh();
        $this->assertSame(12, $fresh2026->header_row);
        $this->assertSame(13, $fresh2026->data_start_row);

        // Verify Petunjuk is unchanged
        $freshPetunjuk = $profilePetunjuk->fresh();
        $this->assertSame(1, $freshPetunjuk->header_row);
        $this->assertSame(2, $freshPetunjuk->data_start_row);
    }
}
