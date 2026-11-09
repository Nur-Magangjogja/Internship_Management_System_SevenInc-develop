<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivePushWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected MockSpreadsheetTransport $mockTransport;
    protected SpreadsheetConnection $connection;
    protected Brand $brand;
    protected string $secret = 'test-apps-script-secret-12345';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_apps_script.shared_secret' => $this->secret]);

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(\App\Services\SpreadsheetTransport\SpreadsheetTransportInterface::class, $this->mockTransport);

        $this->brand = Brand::create([
            'name'              => 'Seven Inc Live',
            'code'              => 'SI',
            'slug'              => 'seven-inc-live',
            'registration_host' => 'seveninc.test',
            'is_active'         => true,
        ]);

        $this->connection = SpreadsheetConnection::create([
            'connection_key'            => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'                  => $this->brand->id,
            'display_name'              => 'Live Push Test Connection',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/1LiveSpreadsheetId1234567890/edit',
            'spreadsheet_id'            => '1LiveSpreadsheetId1234567890',
            'is_enabled'                => true,
            'is_yearly_cloning_enabled' => true,
            'default_integrated_status' => 'waiting',
            'connection_status'         => SpreadsheetConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_unauthorized_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/spreadsheet/push-unsynced', [
            'spreadsheet_id' => $this->connection->spreadsheet_id,
            'tab_name'       => '2026',
            'rows'           => [
                [
                    'row_index' => 15,
                    'data'      => ['Nama Lengkap' => 'Tanpa Auth'],
                ]
            ]
        ], [
            'X-Shared-Secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
    }

    public function test_live_push_creates_user_and_integrated_registration_with_default_status(): void
    {
        $payload = [
            'spreadsheet_id' => $this->connection->spreadsheet_id,
            'tab_name'       => '2026',
            'rows'           => [
                [
                    'row_index' => 20,
                    'data'      => [
                        'Nama Lengkap' => 'Siswa Baru Dari Sheet',
                        'Email'        => 'siswa.sheet@demo.test',
                        'No. HP'       => '081299887766',
                        'NIM'          => '20261199',
                    ],
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/spreadsheet/push-unsynced', $payload, [
            'X-Shared-Secret' => $this->secret,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'count'   => 1,
        ]);

        $responseData = $response->json();
        $this->assertNotEmpty($responseData['processed']);
        $this->assertEquals(20, $responseData['processed'][0]['row_index']);
        $this->assertNotEmpty($responseData['processed'][0]['sync_id']);

        // Verifikasi User
        $user = User::where('email', 'siswa.sheet@demo.test')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Siswa Baru Dari Sheet', $user->name);
        $this->assertEquals('pemagang', $user->role);
        $this->assertEquals($this->brand->id, $user->brand_id);

        // Verifikasi Registration
        $reg = InternshipRegistration::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('waiting', $reg->internship_status); // default_integrated_status
        $this->assertEquals('integrated', $reg->registration_type);
        $this->assertTrue($reg->is_integrated);
    }

    public function test_live_push_respects_custom_status_in_row(): void
    {
        $payload = [
            'spreadsheet_id' => $this->connection->spreadsheet_id,
            'tab_name'       => '2026',
            'rows'           => [
                [
                    'row_index' => 21,
                    'status'    => 'active',
                    'data'      => [
                        'Nama Lengkap' => 'Peserta Aktif Live',
                        'Email'        => 'aktif.live@demo.test',
                        'No. HP'       => '081299887755',
                    ],
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/spreadsheet/push-unsynced', $payload, [
            'X-Shared-Secret' => $this->secret,
        ]);

        $response->assertStatus(200);
        $reg = InternshipRegistration::where('email', 'aktif.live@demo.test')
            ->orWhere('fullname', 'Peserta Aktif Live')
            ->first();

        $this->assertNotNull($reg);
        $this->assertEquals('active', $reg->internship_status);
    }
}
