<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FollowUpCycle;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\SpreadsheetConnectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StabilizationPhaseS9ATest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $hrStaff;
    protected User $pemagang;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('s9a_', true);

        $this->admin = User::create([
            'name'      => 'Admin S9A ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR S9A ' . substr($this->unique, 0, 6),
            'email'     => 'hr_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang S9A ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->seed(\Database\Seeders\SpreadsheetConnectionSeeder::class);
    }

    /**
     * Test 1: Admin can view spreadsheet connections index page.
     */
    public function test_admin_can_view_spreadsheet_connections_index(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.spreadsheet-connections.index'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.spreadsheet-connections.index');
        $response->assertSee('Integrasi Google Spreadsheet');
        $response->assertSee('registration');
        $response->assertSee('followup');
    }

    /**
     * Test 2: HR staff cannot access spreadsheet connections (403 or redirect with error).
     */
    public function test_hr_staff_cannot_access_spreadsheet_connections(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);

        // JSON requests return 403 Forbidden
        $this->actingAs($this->hrStaff)
            ->getJson(route('admin.spreadsheet-connections.index'))
            ->assertStatus(403);

        $this->actingAs($this->hrStaff)
            ->putJson(route('admin.spreadsheet-connections.update', $conn), [
                'display_name' => 'Hacked Name',
            ])
            ->assertStatus(403);

        // Web requests redirect to HR dashboard with error flash
        $indexResponse = $this->actingAs($this->hrStaff)
            ->get(route('admin.spreadsheet-connections.index'));
        $indexResponse->assertRedirect(route('hr.dashboard'));
        $indexResponse->assertSessionHas('error');

        $editResponse = $this->actingAs($this->hrStaff)
            ->get(route('admin.spreadsheet-connections.edit', $conn));
        $editResponse->assertRedirect(route('hr.dashboard'));

        $toggleResponse = $this->actingAs($this->hrStaff)
            ->post(route('admin.spreadsheet-connections.toggle', $conn));
        $toggleResponse->assertRedirect(route('hr.dashboard'));

        $testResponse = $this->actingAs($this->hrStaff)
            ->post(route('admin.spreadsheet-connections.test', $conn));
        $testResponse->assertRedirect(route('hr.dashboard'));
    }

    /**
     * Test 3: Pemagang cannot access spreadsheet connections.
     */
    public function test_pemagang_cannot_access_spreadsheet_connections(): void
    {
        // JSON returns 403
        $this->actingAs($this->pemagang)
            ->getJson(route('admin.spreadsheet-connections.index'))
            ->assertStatus(403);

        // Web redirects to pemagang dashboard with error
        $response = $this->actingAs($this->pemagang)
            ->get(route('admin.spreadsheet-connections.index'));
        $response->assertRedirect(route('user.dashboard'));
        $response->assertSessionHas('error');
    }

    /**
     * Test 4: Guest is redirected to login.
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.spreadsheet-connections.index'))
            ->assertRedirect(route('user.login'));
    }

    /**
     * Test 5: Exactly two canonical connection identities are initialized.
     */
    public function test_canonical_connection_identities_initialized(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $regConn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $fuConn = $service->getConnectionByKey(SpreadsheetConnection::KEY_FOLLOWUP);

        $this->assertEquals(SpreadsheetConnection::KEY_REGISTRATION, $regConn->connection_key);
        $this->assertEquals(SpreadsheetConnection::KEY_FOLLOWUP, $fuConn->connection_key);
        $this->assertNotEmpty($regConn->public_uuid);
        $this->assertNotEmpty($fuConn->public_uuid);
    }

    /**
     * Test 6: Duplicate connection_key is rejected by unique constraint.
     */
    public function test_duplicate_connection_key_is_rejected(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('spreadsheet_connections')->insert([
            'public_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name' => 'Duplicate Connection',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test 7: Admin can update spreadsheet URL and spreadsheet ID is auto-extracted.
     */
    public function test_admin_can_update_connection_and_extracts_id(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);

        $validUrl = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit#gid=0';

        $response = $this->actingAs($this->admin)->put(route('admin.spreadsheet-connections.update', $conn), [
            'display_name' => 'Spreadsheet Pendaftaran 2026',
            'spreadsheet_url' => $validUrl,
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AKfycbxTest/exec',
        ]);

        $response->assertRedirect(route('admin.spreadsheet-connections.index'));
        $response->assertSessionHas('success');

        $conn->refresh();
        $this->assertEquals('Spreadsheet Pendaftaran 2026', $conn->display_name);
        $this->assertEquals($validUrl, $conn->spreadsheet_url);
        $this->assertEquals('1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms', $conn->spreadsheet_id);
        $this->assertEquals('https://script.google.com/macros/s/AKfycbxTest/exec', $conn->apps_script_endpoint_url);
        $this->assertEquals($this->admin->id, $conn->updated_by);
    }

    /**
     * Test 8: Admin can toggle enabled/disabled status.
     */
    public function test_admin_can_toggle_enabled_status(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->is_enabled = false;
        $conn->save();

        // Toggle to true
        $response = $this->actingAs($this->admin)->post(route('admin.spreadsheet-connections.toggle', $conn));
        $response->assertSessionHas('success');
        $conn->refresh();
        $this->assertTrue($conn->is_enabled);

        // Toggle back to false
        $this->actingAs($this->admin)->post(route('admin.spreadsheet-connections.toggle', $conn));
        $conn->refresh();
        $this->assertFalse($conn->is_enabled);
    }

    /**
     * Test 9: No secret credential columns exist in spreadsheet_connections table.
     */
    public function test_no_secret_credential_columns_exist_in_database(): void
    {
        $columns = Schema::getColumnListing('spreadsheet_connections');

        $forbiddenColumns = [
            'password',
            'secret',
            'api_key',
            'token',
            'access_token',
            'refresh_token',
            'auth_token',
            'private_key',
            'service_account',
            'credentials',
        ];

        foreach ($forbiddenColumns as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "Table spreadsheet_connections must not contain secret column '{$forbidden}'"
            );
        }
    }

    /**
     * Test 10: Connection test does NOT mutate any business data.
     */
    public function test_connection_test_does_not_mutate_business_data(): void
    {
        $regCountBefore = InternshipRegistration::count();
        $cycleCountBefore = FollowUpCycle::count();
        $mouCountBefore = Mou::count();

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->save();

        $this->actingAs($this->admin)->post(route('admin.spreadsheet-connections.test', $conn));

        $this->assertEquals($regCountBefore, InternshipRegistration::count());
        $this->assertEquals($cycleCountBefore, FollowUpCycle::count());
        $this->assertEquals($mouCountBefore, Mou::count());
    }

    /**
     * Test 11: Connection test without configured URL sets status to not_configured.
     */
    public function test_connection_test_without_url_sets_not_configured(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = null;
        $conn->spreadsheet_id = null;
        $conn->save();

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_NOT_CONFIGURED, $result['status']);
        $this->assertNotNull($conn->last_tested_at);
        $this->assertEquals(SpreadsheetConnection::STATUS_NOT_CONFIGURED, $conn->connection_status);
    }

    /**
     * Test 12: Unconfigured transport endpoint does not report fake success and sets not_configured.
     */
    public function test_unavailable_transport_does_not_report_fake_success(): void
    {
        config([
            'services.google_apps_script.endpoint_url' => 'https://script.google.com/macros/s/global_env_should_not_be_used_as_silent_fallback/exec',
            'services.google_apps_script.shared_secret' => 'valid_secret_123',
        ]);

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->spreadsheet_id = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';
        $conn->apps_script_endpoint_url = null; // Explicitly empty
        $conn->save();

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success'], 'Must not report fake success when connection endpoint is not configured');
        $this->assertEquals(SpreadsheetConnection::STATUS_NOT_CONFIGURED, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_NOT_CONFIGURED, $conn->fresh()->connection_status);
        $this->assertStringContainsString('belum dikonfigurasi pada koneksi', $conn->fresh()->last_error_summary);
    }

    /**
     * Test 13: Truncated or invalid spreadsheet ID reports error.
     */
    public function test_truncated_spreadsheet_id_reports_error(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_FOLLOWUP);
        // Truncated ID only 16 characters (e.g. 1Hyvbz8oY92-L4GW)
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1Hyvbz8oY92-L4GW/edit';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/AKfycbxTest/exec';
        $conn->save();

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $conn->fresh()->connection_status);
        $this->assertStringContainsString('minimal 25 karakter', $conn->fresh()->last_error_summary);
    }

    /**
     * Test 14: Non-Google URL or invalid host format reports error.
     */
    public function test_non_google_url_or_invalid_format_reports_error(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://evil-phishing.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/AKfycbxTest/exec';
        $conn->save();

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $conn->fresh()->connection_status);
    }

    /**
     * Test 15: Invalid / non-Google Apps Script endpoint reports error (SSRF protection).
     */
    public function test_fake_or_unauthorized_apps_script_endpoint_reports_error(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->apps_script_endpoint_url = 'https://arbitrary-attacker.com/macros/s/AKfycbxTest/exec';
        $conn->save();

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $conn->fresh()->connection_status);
        $this->assertStringContainsString('Harus berupa URL HTTPS resmi Google Apps Script', $conn->fresh()->last_error_summary);
    }

    /**
     * Test 16: Empty shared secret returns not_configured.
     */
    public function test_empty_shared_secret_returns_not_configured(): void
    {
        config(['services.google_apps_script.shared_secret' => null]);

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/AKfycbxTest/exec';
        $conn->save();

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_NOT_CONFIGURED, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_NOT_CONFIGURED, $conn->fresh()->connection_status);
        $this->assertStringContainsString('Shared Secret Apps Script belum dikonfigurasi', $conn->fresh()->last_error_summary);
    }

    /**
     * Test 17: Local validation fails before making remote HTTP call.
     */
    public function test_local_validation_fails_before_making_remote_http_call(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = null;
        $conn->apps_script_endpoint_url = null;
        $conn->save();

        $service->testConnection($conn, $this->admin);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * Test 18: Real HMAC Ping testConnection succeeds when Apps Script responds with ping success and matching identity.
     */
    public function test_hmac_ping_test_connection_succeeds_with_valid_mock_response(): void
    {
        config(['services.google_apps_script.shared_secret' => 'test_shared_secret_ping_123']);

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->spreadsheet_id = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/ping_test_gateway/exec';
        $conn->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://script.google.com/macros/s/ping_test_gateway/exec' => \Illuminate\Support\Facades\Http::response([
                'success'                  => true,
                'action'                   => 'ping',
                'connection_key'           => 'registration',
                'requested_spreadsheet_id' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
                'verified_spreadsheet_id'  => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
                'target_accessible'        => true,
                'id_matched'               => true,
                'status'                   => [
                    'registration_spreadsheet' => true,
                    'followup_spreadsheet'     => true,
                    'drive_folder'             => true,
                ],
                'message'                  => 'Koneksi Google Apps Script Gateway berhasil diverifikasi.',
            ], 200),
        ]);

        $result = $service->testConnection($conn, $this->admin);

        $this->assertTrue($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_CONNECTED, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_CONNECTED, $conn->fresh()->connection_status);
        $this->assertNotNull($conn->fresh()->last_successful_test_at);
        $this->assertNull($conn->fresh()->last_error_summary);
    }

    /**
     * Test 19: Real HMAC Ping testConnection reports error when target spreadsheet is inaccessible.
     */
    public function test_hmac_ping_test_connection_reports_error_when_spreadsheet_inaccessible(): void
    {
        config(['services.google_apps_script.shared_secret' => 'test_shared_secret_ping_123']);

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1InaccessibleSpreadsheetId2026/edit';
        $conn->spreadsheet_id = '1InaccessibleSpreadsheetId2026';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/ping_test_gateway/exec';
        $conn->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://script.google.com/macros/s/ping_test_gateway/exec' => \Illuminate\Support\Facades\Http::response([
                'success'                  => false,
                'action'                   => 'ping',
                'connection_key'           => 'registration',
                'target_accessible'        => false,
                'id_matched'               => true,
                'status'                   => [
                    'registration_spreadsheet' => false,
                    'followup_spreadsheet'     => true,
                    'drive_folder'             => true,
                ],
                'message'                  => 'Spreadsheet target tidak dapat dibuka.',
            ], 400),
        ]);

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $conn->fresh()->connection_status);
    }

    /**
     * Test 20: Mismatched spreadsheet ID from gateway target reports error.
     */
    public function test_mismatched_spreadsheet_id_from_gateway_target_reports_error(): void
    {
        config(['services.google_apps_script.shared_secret' => 'test_shared_secret_ping_123']);

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_FOLLOWUP);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1DifferentSpreadsheetId2026AAA/edit';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/ping_test_gateway/exec';
        $conn->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://script.google.com/macros/s/ping_test_gateway/exec' => \Illuminate\Support\Facades\Http::response([
                'success'                  => false,
                'action'                   => 'ping',
                'connection_key'           => 'followup',
                'target_accessible'        => true,
                'id_matched'               => false,
                'message'                  => 'Spreadsheet ID yang dikirim tidak sesuai dengan konfigurasi Apps Script Gateway.',
            ], 400),
        ]);

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $conn->fresh()->connection_status);
        $this->assertStringContainsString('tidak sesuai', $conn->fresh()->last_error_summary);
    }

    /**
     * Test 21: HMAC auth failure (401/403) reports error.
     */
    public function test_hmac_auth_failure_reports_error(): void
    {
        config(['services.google_apps_script.shared_secret' => 'wrong_secret']);

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/ping_test_gateway/exec';
        $conn->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://script.google.com/macros/s/ping_test_gateway/exec' => \Illuminate\Support\Facades\Http::response([
                'success'    => false,
                'error_code' => 'INVALID_SIGNATURE',
                'message'    => 'Tanda tangan HMAC-SHA256 tidak valid.',
            ], 401),
        ]);

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $conn->fresh()->connection_status);
        $this->assertStringContainsString('Autentikasi Apps Script ditolak', $conn->fresh()->last_error_summary);
    }

    /**
     * Test 22: HTTP 200 with invalid JSON reports error.
     */
    public function test_http_200_with_invalid_json_reports_error(): void
    {
        config(['services.google_apps_script.shared_secret' => 'test_secret']);

        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/ping_test_gateway/exec';
        $conn->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://script.google.com/macros/s/ping_test_gateway/exec' => \Illuminate\Support\Facades\Http::response(
                '<!DOCTYPE html><html><body>Error Page</body></html>',
                200
            ),
        ]);

        $result = $service->testConnection($conn, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $result['status']);
        $this->assertEquals(SpreadsheetConnection::STATUS_ERROR, $conn->fresh()->connection_status);
        $this->assertStringContainsString('JSON', $conn->fresh()->last_error_summary);
    }

    /**
     * Test 23: Configuration update from Connected resets connected state to untested or not_configured.
     */
    public function test_configuration_update_from_connected_resets_connected_state(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $conn->connection_status = SpreadsheetConnection::STATUS_CONNECTED;
        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->apps_script_endpoint_url = 'https://script.google.com/macros/s/ping_test_gateway/exec';
        $conn->save();

        $this->assertEquals(SpreadsheetConnection::STATUS_CONNECTED, $conn->connection_status);

        // Update with new URL
        $service->updateConnection($conn, [
            'display_name'             => 'New Registration Name',
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/1NewSpreadsheetId2026Testing/edit',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/ping_test_gateway/exec',
        ], $this->admin);

        $conn->refresh();
        $this->assertEquals(SpreadsheetConnection::STATUS_UNTESTED, $conn->connection_status);

        // Update with empty endpoint
        $service->updateConnection($conn, [
            'display_name'             => 'New Registration Name',
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/1NewSpreadsheetId2026Testing/edit',
            'apps_script_endpoint_url' => null,
        ], $this->admin);

        $conn->refresh();
        $this->assertEquals(SpreadsheetConnection::STATUS_NOT_CONFIGURED, $conn->connection_status);
    }

    /**
     * Test 24: Activity logs are created for update, toggle, and test actions without secrets.
     */
    public function test_activity_logs_created_without_secrets(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_FOLLOWUP);

        // 1. Update
        $service->updateConnection($conn, [
            'display_name' => 'Follow-Up Sheet Test',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1TestSpreadsheetIdFollowUp2026/edit',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AKfycbxTest/exec',
        ], $this->admin);

        $updateLog = ActivityLog::where('action', 'spreadsheet_connection_updated')->latest()->first();
        $this->assertNotNull($updateLog);
        $this->assertEquals($this->admin->id, $updateLog->actor_user_id);
        $this->assertStringNotContainsString('password', json_encode($updateLog->after_data));
        $this->assertStringNotContainsString('secret', json_encode($updateLog->after_data));

        // 2. Toggle
        $service->toggleStatus($conn, $this->admin);
        $toggleLog = ActivityLog::where('action', 'spreadsheet_connection_enabled')->latest()->first();
        $this->assertNotNull($toggleLog);

        // 3. Test
        $service->testConnection($conn, $this->admin);
        $testLog = ActivityLog::where('action', 'spreadsheet_connection_tested')->latest()->first();
        $this->assertNotNull($testLog);
    }

    /**
     * Test 25: URL with forbidden secret query parameters is rejected.
     */
    public function test_apps_script_url_with_secret_query_params_rejected(): void
    {
        $service = app(SpreadsheetConnectionService::class);
        $conn = $service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Dilarang menyertakan token/secret 'token' di dalam query parameter URL.");

        $service->updateConnection($conn, [
            'display_name' => 'Registration Sheet',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AKfycbxTest/exec?token=my_secret_token_123',
        ], $this->admin);
    }
}
