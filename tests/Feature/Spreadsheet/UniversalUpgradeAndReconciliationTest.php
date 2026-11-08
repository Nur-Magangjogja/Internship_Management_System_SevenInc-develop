<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\InternshipRegistration;
use App\Models\SheetDiscoverySnapshot;
use App\Models\SheetMappingProposal;
use App\Models\SheetMappingProposalItem;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\ActiveSheetManualRowReconciliationService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetDiscoveryService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalUpgradeAndReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected MockSpreadsheetTransport $mockTransport;
    protected SpreadsheetConnection $connection;
    protected Brand $brand;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(\App\Services\SpreadsheetTransport\SpreadsheetTransportInterface::class, $this->mockTransport);

        $this->admin = User::create([
            'name'      => 'Admin Test',
            'email'     => 'admin.reconcile.test@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->brand = Brand::create([
            'name'              => 'Brand Test',
            'code'              => 'BT',
            'slug'              => 'brand-test',
            'registration_host' => 'brandtest.test',
            'is_active'         => true,
        ]);

        $this->connection = SpreadsheetConnection::create([
            'connection_key'            => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'                  => $this->brand->id,
            'display_name'              => 'Spreadsheet Pendaftaran Brand Test',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit',
            'spreadsheet_id'            => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
            'is_enabled'                => true,
            'is_yearly_cloning_enabled' => true,
            'tab_creation_mode'         => 'auto_clone',
            'yearly_tab_template_name'  => 'TEMPLATE_TAHUN',
            'allow_custom_tab_naming'   => true,
            'connection_status'         => SpreadsheetConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_newly_applied_proposal_fields_are_inactive_by_default(): void
    {
        $discoveryService = new SpreadsheetDiscoveryService($this->mockTransport);

        $draftSchema = FormSchema::create([
            'year'         => 2026,
            'version_code' => 'DRAFT-2026-TEST',
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => false,
            'created_by'   => $this->admin->id,
        ]);

        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'tab_name'                  => '2026',
            'sheet_id'                  => 101,
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_test_123',
            'columns_count'             => 1,
            'scan_metadata'             => [
                'raw_columns' => [
                    ['col_letter' => 'A', 'header_name' => 'Ukuran Baju Brand', 'col_index' => 1]
                ]
            ],
            'scanned_at'                => now(),
            'scanned_by'                => $this->admin->id,
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'               => $snapshot->id,
            'form_schema_id'            => $draftSchema->id,
            'year'                      => 2026,
            'status'                    => SheetMappingProposal::STATUS_DRAFT,
            'expected_schema_hash'      => 'hash_test_123',
            'version'                   => 1,
            'proposed_by'               => $this->admin->id,
        ]);

        SheetMappingProposalItem::create([
            'proposal_id'          => $proposal->id,
            'col_letter'           => 'A',
            'physical_index'       => 1,
            'observed_label'       => 'Ukuran Baju Brand',
            'suggested_label'      => 'Ukuran Baju Brand',
            'suggested_field_type' => 'text',
            'field_key'            => 'ukuran_baju_brand',
            'action'               => SheetMappingProposalItem::ACTION_CREATE_DRAFT,
            'confidence_score'     => 0.95,
            'confidence_level'     => 'HIGH',
            'has_collision'        => false,
        ]);

        $result = $discoveryService->applyProposalToDraft($proposal, $draftSchema, $this->admin);

        $this->assertTrue($result['success']);
        $field = FormField::where('form_schema_id', $draftSchema->id)->where('field_key', 'ukuran_baju_brand')->first();

        $this->assertNotNull($field);
        // Field harus dalam kondisi nonaktif (is_active = false) sesuai spesifikasi user
        $this->assertFalse($field->is_active, 'Kolom hasil scan spreadsheet harus bernilai is_active = false.');
    }

    public function test_duplicate_or_create_tab_via_transport(): void
    {
        $response = $this->mockTransport->duplicateOrCreateTab(
            $this->connection,
            'TEMPLATE_TAHUN',
            'Batch-2027'
        );

        $this->assertTrue($response->success);
        $this->assertEquals('Batch-2027', $response->context['tab_name']);
    }

    public function test_manual_row_reconciliation_creates_integrated_user_and_registration(): void
    {
        $mappingService = $this->app->make(RegistrationSpreadsheetMappingService::class);
        $service = new ActiveSheetManualRowReconciliationService($this->mockTransport, $mappingService);

        $rowData = [
            'Nama Lengkap' => 'Budi Santoso Eksternal',
            'Email'        => 'budi.eksternal@test.com',
            'No. HP'       => '081234567899',
            'NIM'          => '2026110001',
        ];

        $result = $service->reconcileRowSyncId(
            connection: $this->connection,
            tabName: '2026',
            rowIndex: 15,
            existingSyncId: null,
            registrationId: null,
            expectedChecksum: null,
            rowData: $rowData
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_integrated']);
        $this->assertNotEmpty($result['sync_id']);

        // Pastikan User dibuat otomatis
        $user = User::where('email', 'budi.eksternal@test.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Budi Santoso Eksternal', $user->name);
        $this->assertEquals('pemagang', $user->role);
        $this->assertEquals($this->brand->id, $user->brand_id);

        // Pastikan InternshipRegistration dibuat dengan tipe integrated
        $reg = InternshipRegistration::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('integrated', $reg->registration_type);
        $this->assertTrue($reg->is_integrated);
        $this->assertEquals($this->brand->id, $reg->brand_id);
    }

    public function test_duplicate_tab_endpoint_fails_when_yearly_cloning_disabled(): void
    {
        $this->connection->update(['is_yearly_cloning_enabled' => false]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.duplicate-tab', $this->connection),
            [
                'new_tab_name' => '2027',
            ]
        );

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Mode kloning tab tahunan dinonaktifkan untuk koneksi spreadsheet brand ini.',
        ]);
    }

    public function test_duplicate_tab_endpoint_rejects_custom_naming_when_disallowed(): void
    {
        $this->connection->update([
            'is_yearly_cloning_enabled' => true,
            'allow_custom_tab_naming'   => false,
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.duplicate-tab', $this->connection),
            [
                'new_tab_name' => 'Batch-Kustom-2027',
            ]
        );

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Penamaan tab kustom dinonaktifkan. Nama tab harus berupa 4 digit tahun (contoh: 2027).',
        ]);
    }

    public function test_manual_row_reconciliation_applies_chosen_internship_status(): void
    {
        $mappingService = $this->app->make(RegistrationSpreadsheetMappingService::class);
        $service = new ActiveSheetManualRowReconciliationService($this->mockTransport, $mappingService);

        $resultWaiting = $service->reconcileRowSyncId(
            connection: $this->connection,
            tabName: '2026',
            rowIndex: 16,
            existingSyncId: null,
            registrationId: null,
            expectedChecksum: null,
            rowData: [
                'Nama Lengkap' => 'Calon Peserta Baru',
                'Email'        => 'calon.baru@test.com',
                'No. HP'       => '081234567888',
            ],
            targetStatus: InternshipRegistration::STATUS_WAITING
        );

        $this->assertTrue($resultWaiting['success']);
        $regWaiting = InternshipRegistration::find($resultWaiting['registration_id']);
        $this->assertNotNull($regWaiting);
        $this->assertEquals(InternshipRegistration::STATUS_WAITING, $regWaiting->internship_status);

        $resultActive = $service->reconcileRowSyncId(
            connection: $this->connection,
            tabName: '2026',
            rowIndex: 17,
            existingSyncId: null,
            registrationId: null,
            expectedChecksum: null,
            rowData: [
                'Nama Lengkap' => 'Peserta Aktif Baru',
                'Email'        => 'peserta.aktif@test.com',
                'No. HP'       => '081234567877',
            ],
            targetStatus: InternshipRegistration::STATUS_ACTIVE
        );

        $this->assertTrue($resultActive['success']);
        $regActive = InternshipRegistration::find($resultActive['registration_id']);
        $this->assertNotNull($regActive);
        $this->assertEquals(InternshipRegistration::STATUS_ACTIVE, $regActive->internship_status);
    }

    public function test_inspect_columns_detects_system_headers_and_categorizes(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            route('admin.spreadsheet-connections.inspect-columns', [
                'spreadsheetConnection' => $this->connection,
                'tab_name' => '2026',
                'header_row' => 12,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'brand_id',
            'brand_name',
            'tab_name',
            'header_row',
            'total_columns',
            'has_system_headers',
            'missing_system_headers',
            'columns',
            'counts',
        ]);
    }

    public function test_inject_header_ids_appends_system_columns_and_readies_write_mode(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.inject-header-ids', $this->connection),
            [
                'tab_name' => '2026',
                'header_row' => 12,
            ]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // After injection, inspectColumns should report has_system_headers = true
        $inspectResponse = $this->actingAs($this->admin)->getJson(
            route('admin.spreadsheet-connections.inspect-columns', [
                'spreadsheetConnection' => $this->connection,
                'tab_name' => '2026',
                'header_row' => 12,
            ])
        );

        $inspectResponse->assertStatus(200);
        $inspectResponse->assertJson([
            'success' => true,
            'has_system_headers' => true,
        ]);
        $this->assertEmpty($inspectResponse->json('missing_system_headers'));
    }

    public function test_save_brand_columns_registers_custom_fields_and_persists_mapping(): void
    {
        $columns = [
            [
                'col_letter' => 'A',
                'header_name' => 'Nama Lengkap',
                'category' => 'standard',
                'field_key' => 'fullname',
                'inferred_type' => 'text',
            ],
            [
                'col_letter' => 'B',
                'header_name' => 'Email',
                'category' => 'standard',
                'field_key' => 'email',
                'inferred_type' => 'email',
            ],
            [
                'col_letter' => 'C',
                'header_name' => 'Akun TikTok Brand',
                'category' => 'custom_brand',
                'field_key' => 'akun_tiktok_brand',
                'inferred_type' => 'text',
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.spreadsheet-connections.save-brand-columns', $this->connection),
            [
                'tab_name' => '2026',
                'header_row' => 10,
                'columns' => $columns,
            ]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'registered_custom_fields' => 1,
        ]);

        $this->assertDatabaseHas('form_field_registries', [
            'field_key'       => 'akun_tiktok_brand',
            'canonical_label' => 'Akun TikTok Brand',
        ]);
    }

    public function test_reconciliation_and_intern_update_handles_custom_answers(): void
    {
        $mappingService = $this->app->make(RegistrationSpreadsheetMappingService::class);
        $service = new ActiveSheetManualRowReconciliationService($this->mockTransport, $mappingService);

        $result = $service->reconcileRowSyncId(
            connection: $this->connection,
            tabName: '2026',
            rowIndex: 18,
            existingSyncId: null,
            registrationId: null,
            expectedChecksum: null,
            rowData: [
                'Nama Lengkap'      => 'Intern Kustom',
                'Email'             => 'intern.kustom@test.com',
                'Akun TikTok Brand' => '@intern_tiktok_123',
            ],
            targetStatus: InternshipRegistration::STATUS_ACTIVE
        );

        $this->assertTrue($result['success']);
        $regId = $result['registration_id'];

        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $regId,
            'field_key'       => 'field_akun_tiktok_brand',
            'answer_value'    => '@intern_tiktok_123',
        ]);

        // Test update via InternController
        $updateResponse = $this->actingAs($this->admin)->patch(
            route('admin.interns.update', $regId),
            [
                'fullname'          => 'Intern Kustom Updated',
                'email'             => 'intern.kustom@test.com',
                'internship_status' => InternshipRegistration::STATUS_ACTIVE,
                'custom_answers'    => [
                    'field_akun_tiktok_brand' => '@intern_tiktok_updated',
                ],
            ]
        );

        $updateResponse->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $regId,
            'field_key'       => 'field_akun_tiktok_brand',
            'answer_value'    => '@intern_tiktok_updated',
        ]);
    }
}
