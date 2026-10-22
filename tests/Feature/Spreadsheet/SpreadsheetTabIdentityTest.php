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
}
