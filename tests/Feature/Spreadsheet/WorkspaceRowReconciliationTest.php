<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceRowReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Brand $brand;
    protected SpreadsheetConnection $connection;
    protected MockSpreadsheetTransport $mockTransport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin Workspace Test',
            'email'    => 'admin.ws@test.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $this->brand = Brand::create([
            'name'        => 'Seven Inc Workspace',
            'code'        => 'SIWS',
            'slug'        => 'seven-inc-ws',
            'is_active'   => true,
            'is_default'  => true,
        ]);

        $this->connection = SpreadsheetConnection::create([
            'brand_id'                  => $this->brand->id,
            'connection_key'            => 'registration',
            'display_name'              => 'Spreadsheet Workspace Pendaftaran',
            'spreadsheet_id'            => 'ws_test_sheet_123',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/ws_test_sheet_123/edit',
            'connection_status'         => 'connected',
            'is_enabled'                => true,
            'is_yearly_cloning_enabled' => true,
            'default_integrated_status' => 'waiting',
        ]);

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);
    }

    public function test_workspace_endpoint_returns_rows_with_colors_and_palette(): void
    {
        $this->mockTransport->setMockSheetData('2026', [
            [
                'Nama'            => 'Calon Satu',
                'Email'           => 'calon1@ws.test',
                '_row_background' => '#fff2cc', // Kuning (Calon)
            ],
            [
                'Nama'            => 'Diterima Satu',
                'Email'           => 'diterima1@ws.test',
                '_row_background' => '#d9ead3', // Hijau (Diterima)
            ],
            [
                'Nama'            => 'Ditolak Satu',
                'Email'           => 'ditolak1@ws.test',
                '_row_background' => '#f4cccc', // Merah (Ditolak)
            ],
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('admin.spreadsheet-connections.workspace-rows', [
                'spreadsheetConnection' => $this->connection,
                'tab_name'              => '2026',
                'start_row'             => 14,
            ])
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        $data = $response->json('data');
        $this->assertCount(3, $data['rows']);
        $paletteHexes = array_column($data['palette'], 'hex');
        $this->assertContains('#fff2cc', $paletteHexes);
        $this->assertContains('#d9ead3', $paletteHexes);
        $this->assertContains('#f4cccc', $paletteHexes);

        // Status awal harus NULL (belum ditentukan) karena sistem tidak berasumsi
        $this->assertNull($data['rows'][0]['status']);
        $this->assertNull($data['rows'][1]['status']);
        $this->assertNull($data['rows'][2]['status']);
    }

    public function test_workspace_endpoint_applies_color_mapping_and_range_rules(): void
    {
        $this->mockTransport->setMockSheetData('2026', [
            [
                'Nama'            => 'Calon Kuning',
                'Email'           => 'kuning@ws.test',
                '_row_background' => '#fff2cc',
            ],
            [
                'Nama'            => 'Diterima Hijau',
                'Email'           => 'hijau@ws.test',
                '_row_background' => '#d9ead3',
            ],
            [
                'Nama'            => 'Rentang Khusus',
                'Email'           => 'rentang@ws.test',
                '_row_background' => '#ffffff',
            ],
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('admin.spreadsheet-connections.workspace-rows', [
                'spreadsheetConnection' => $this->connection,
                'tab_name'              => '2026',
                'start_row'             => 14,
                'color_mapping'         => [
                    '#fff2cc' => 'waiting',
                    '#d9ead3' => 'accepted',
                ],
                'range_mapping'         => [
                    [
                        'from'   => 16,
                        'to'     => 16,
                        'status' => 'active',
                    ],
                ],
            ])
        );

        $response->assertOk();
        $rows = $response->json('data.rows');

        // Baris 14: Kuning -> waiting
        $this->assertEquals('waiting', $rows[0]['status']);
        // Baris 15: Hijau -> accepted
        $this->assertEquals('accepted', $rows[1]['status']);
        // Baris 16: Rentang 16-16 -> active
        $this->assertEquals('active', $rows[2]['status']);
    }

    public function test_workspace_endpoint_sorts_rows_by_custom_column(): void
    {
        $this->mockTransport->setMockSheetData('2026', [
            ['Nama' => 'Budi Santoso', 'Email' => 'budi@ws.test'],
            ['Nama' => 'Andi Pratama', 'Email' => 'andi@ws.test'],
            ['Nama' => 'Citra Dewi',   'Email' => 'citra@ws.test'],
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('admin.spreadsheet-connections.workspace-rows', [
                'spreadsheetConnection' => $this->connection,
                'tab_name'              => '2026',
                'start_row'             => 14,
                'sort_by'               => 'display_name',
                'sort_dir'              => 'asc',
            ])
        );

        $response->assertOk();
        $rows = $response->json('data.rows');

        $this->assertEquals('Andi Pratama', $rows[0]['display_name']);
        $this->assertEquals('Budi Santoso', $rows[1]['display_name']);
        $this->assertEquals('Citra Dewi', $rows[2]['display_name']);
    }

    public function test_batch_reconcile_creates_users_and_registrations_for_selected_rows(): void
    {
        $items = [
            [
                'row_index' => 14,
                'status'    => 'waiting',
                'row_data'  => [
                    'Nama Lengkap' => 'Calon Batch Satu',
                    'Email'        => 'calon.batch1@ws.test',
                ],
            ],
            [
                'row_index' => 15,
                'status'    => 'accepted',
                'row_data'  => [
                    'Nama Lengkap' => 'Diterima Batch Dua',
                    'Email'        => 'diterima.batch2@ws.test',
                ],
            ],
            [
                'row_index' => 16,
                'status'    => 'rejected',
                'row_data'  => [
                    'Nama Lengkap' => 'Ditolak Batch Tiga',
                    'Email'        => 'ditolak.batch3@ws.test',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.batch-reconcile', $this->connection),
            [
                'tab_name' => '2026',
                'items'    => $items,
            ]
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data'    => [
                'total'         => 3,
                'success_count' => 3,
                'failed_count'  => 0,
            ],
        ]);

        // Verifikasi database User & Registrasi
        $user1 = User::where('email', 'calon.batch1@ws.test')->first();
        $this->assertNotNull($user1);
        $this->assertEquals('Calon Batch Satu', $user1->name);
        $this->assertEquals('pemagang', $user1->role);

        $reg1 = InternshipRegistration::where('user_id', $user1->id)->first();
        $this->assertNotNull($reg1);
        $this->assertEquals('waiting', $reg1->internship_status);
        $this->assertEquals('integrated', $reg1->registration_type);
        $this->assertTrue((bool) $reg1->is_integrated);

        $user2 = User::where('email', 'diterima.batch2@ws.test')->first();
        $reg2 = InternshipRegistration::where('user_id', $user2->id)->first();
        $this->assertEquals('accepted', $reg2->internship_status);

        $user3 = User::where('email', 'ditolak.batch3@ws.test')->first();
        $reg3 = InternshipRegistration::where('user_id', $user3->id)->first();
        $this->assertEquals('rejected', $reg3->internship_status);
    }

    public function test_batch_reconcile_supports_pending_status_and_connection_setting(): void
    {
        // 1. Uji koneksi dapat menyimpan default_integrated_status = 'pending'
        $validSpreadsheetUrl = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $updateResp = $this->actingAs($this->admin)->put(
            route('admin.spreadsheet-connections.update', $this->connection),
            [
                'display_name'              => $this->connection->display_name,
                'spreadsheet_url'           => $validSpreadsheetUrl,
                'brand_id'                  => $this->brand->id,
                'default_integrated_status' => 'pending',
            ]
        );
        $updateResp->assertRedirect(route('admin.spreadsheet-connections.index'));
        $this->connection->refresh();
        $this->assertEquals('pending', $this->connection->default_integrated_status);

        // 2. Batch reconcile dengan status pending
        $items = [
            [
                'row_index' => 17,
                'status'    => 'pending',
                'row_data'  => [
                    'Nama Lengkap' => 'Pending Pemagang Empat',
                    'Email'        => 'pending.batch4@ws.test',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.batch-reconcile', $this->connection),
            [
                'tab_name' => '2026',
                'items'    => $items,
            ]
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data'    => [
                'total'         => 1,
                'success_count' => 1,
            ],
        ]);

        $user = User::where('email', 'pending.batch4@ws.test')->first();
        $this->assertNotNull($user);
        $reg = InternshipRegistration::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals(InternshipRegistration::STATUS_PENDING, $reg->internship_status);
    }
}
