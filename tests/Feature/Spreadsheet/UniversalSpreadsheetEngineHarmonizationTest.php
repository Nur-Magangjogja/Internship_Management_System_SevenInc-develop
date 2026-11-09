<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\InternshipRegistration;
use App\Models\SheetDiscoveredColumn;
use App\Models\SheetDiscoverySnapshot;
use App\Models\SheetMappingProposalItem;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\User;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetDiscoveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UniversalSpreadsheetEngineHarmonizationTest extends TestCase
{
    use DatabaseTransactions;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::first() ?? User::create([
            'name'      => 'Admin Test',
            'email'     => 'admin.test@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => 'registration_test'],
            [
                'display_name'             => 'Spreadsheet Test Harmonization',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test_harmonization/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_HARMONIZATION/exec',
                'spreadsheet_id'           => 'test_harmonization_id',
            ]
        );
    }

    /**
     * Test 1: Kolom protokol sistem SERTIF_SYNC_STATUS dan SERTIF_LAST_SYNC_AT dilindungi & EXACT.
     */
    public function test_system_protocol_columns_protected_and_exact(): void
    {
        $protoSyncId = SpreadsheetDiscoveryService::classifySystemProtocol('SERTIF_SYNC_ID');
        $this->assertNotNull($protoSyncId);
        $this->assertEquals('SERTIF_SYNC_ID', $protoSyncId['canonical_key']);
        $this->assertTrue($protoSyncId['is_system']);

        $protoRegId = SpreadsheetDiscoveryService::classifySystemProtocol('SERTIF_REGISTRATION_ID');
        $this->assertNotNull($protoRegId);
        $this->assertEquals('SERTIF_REGISTRATION_ID', $protoRegId['canonical_key']);

        $protoStatus = SpreadsheetDiscoveryService::classifySystemProtocol('SERTIF_SYNC_STATUS');
        $this->assertNotNull($protoStatus);
        $this->assertEquals('SERTIF_SYNC_STATUS', $protoStatus['canonical_key']);

        $protoSyncAt = SpreadsheetDiscoveryService::classifySystemProtocol('SERTIF_LAST_SYNC_AT');
        $this->assertNotNull($protoSyncAt);
        $this->assertEquals('SERTIF_LAST_SYNC_AT', $protoSyncAt['canonical_key']);

        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'           => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => 'Test Protocol Tab',
            'header_row'                => 1,
            'data_start_row'            => 2,
            'schema_hash'               => hash('sha256', 'test_proto'),
            'columns_count'             => 4,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        SheetDiscoveredColumn::create([
            'snapshot_id'        => $snapshot->id,
            'column_identity'    => SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'AP', 42, 'SERTIF_SYNC_STATUS'),
            'col_letter'         => 'AP',
            'physical_index'     => 42,
            'observed_label'     => 'SERTIF_SYNC_STATUS',
            'inferred_type'      => 'string',
            'has_formula'        => false,
            'sample_values'      => ['synced'],
            'is_duplicate_label' => false,
            'is_system_reserved' => true,
        ]);

        SheetDiscoveredColumn::create([
            'snapshot_id'        => $snapshot->id,
            'column_identity'    => SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'AQ', 43, 'SERTIF_LAST_SYNC_AT'),
            'col_letter'         => 'AQ',
            'physical_index'     => 43,
            'observed_label'     => 'SERTIF_LAST_SYNC_AT',
            'inferred_type'      => 'string',
            'has_formula'        => false,
            'sample_values'      => ['2026-11-09 10:00:00'],
            'is_duplicate_label' => false,
            'is_system_reserved' => true,
        ]);

        $discoveryService = app(SpreadsheetDiscoveryService::class);
        $proposal = $discoveryService->createMappingProposal($this->connection, $snapshot, 2026, null, $this->adminUser);

        $itemStatus = $proposal->items()->where('col_letter', 'AP')->first();
        $this->assertNotNull($itemStatus);
        $this->assertEquals(SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED, $itemStatus->action);
        $this->assertEquals('sertif_sync_status', $itemStatus->field_key);
        $this->assertEquals('EXACT', $itemStatus->confidence_level);
        $this->assertFalse((bool) $itemStatus->has_collision);

        $itemSyncAt = $proposal->items()->where('col_letter', 'AQ')->first();
        $this->assertNotNull($itemSyncAt);
        $this->assertEquals(SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED, $itemSyncAt->action);
        $this->assertEquals('sertif_last_sync_at', $itemSyncAt->field_key);
        $this->assertEquals('EXACT', $itemSyncAt->confidence_level);
        $this->assertFalse((bool) $itemSyncAt->has_collision);
    }

    /**
     * Test 2: Header kembar KET (AL & AM) dapat disimpan pada schema version yang sama dengan target berbeda.
     */
    public function test_duplicate_headers_allowed_in_schema_version_and_resolved_independently(): void
    {
        $schemaVersion = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'year'                      => 2026,
            'sheet_tab_name'            => 'Test Dual KET',
            'schema_version'            => 'v_test_' . uniqid(),
            'is_active'                 => false,
        ]);

        $cidAL = SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'AL', 38, 'KET');
        $cidAM = SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'AM', 39, 'KET');

        $this->assertNotEquals($cidAL, $cidAM);

        // Simpan kolom AL (KET #38)
        $mappingAL = SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'column_identity'               => $cidAL,
            'sheet_id'                      => 101,
            'column_order'                  => 38,
            'sheet_header'                  => 'KET',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'ket_derived',
            'data_type'                     => 'string',
            'direction'                     => 'display_only',
            'mapping_status'                => 'active',
        ]);

        // Simpan kolom AM (KET #39) dengan sheet_header identik
        $mappingAM = SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'column_identity'               => $cidAM,
            'sheet_id'                      => 101,
            'column_order'                  => 39,
            'sheet_header'                  => 'KET',
            'target_entity'                 => 'external_sheet',
            'target_field'                  => 'ket_notes',
            'data_type'                     => 'string',
            'direction'                     => 'display_only',
            'mapping_status'                => 'active',
        ]);

        $this->assertDatabaseHas('spreadsheet_column_mappings', [
            'id'              => $mappingAL->id,
            'column_identity' => $cidAL,
            'column_order'    => 38,
            'sheet_header'    => 'KET',
            'target_field'    => 'ket_derived',
        ]);

        $this->assertDatabaseHas('spreadsheet_column_mappings', [
            'id'              => $mappingAM->id,
            'column_identity' => $cidAM,
            'column_order'    => 39,
            'sheet_header'    => 'KET',
            'target_field'    => 'ket_notes',
        ]);
    }

    /**
     * Test 3: Outbound payload menghasilkan values_by_position untuk penulisan akurat per posisi fisik.
     */
    public function test_outbound_row_payload_generates_positional_columns(): void
    {
        $schemaVersion = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'year'                      => 2026,
            'sheet_tab_name'            => 'Test Positional Outbound',
            'schema_version'            => 'v_pos_' . uniqid(),
            'is_active'                 => true,
        ]);

        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'column_identity'               => SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'B', 2, 'Email'),
            'sheet_id'                      => 101,
            'column_order'                  => 2,
            'sheet_header'                  => 'Email',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'email',
            'data_type'                     => 'string',
            'direction'                     => 'two_way',
            'mapping_status'                => 'active',
        ]);

        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'column_identity'               => SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'AL', 38, 'KET'),
            'sheet_id'                      => 101,
            'column_order'                  => 38,
            'sheet_header'                  => 'KET',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'ket_al',
            'data_type'                     => 'string',
            'direction'                     => 'display_only',
            'mapping_status'                => 'active',
        ]);

        $registration = new InternshipRegistration([
            'email'             => 'positional_test@example.com',
            'fullname'          => 'Test Positional User',
            'is_draft'          => false,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'created_at'        => now(),
        ]);
        $registration->id = 999;
        $registration->created_at = now();

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration, $schemaVersion, null, true);

        $this->assertArrayHasKey('values_by_position', $payload);

        $itemB = collect($payload['values_by_position'])->firstWhere('col_index', 2);
        $this->assertNotNull($itemB);
        $this->assertEquals('positional_test@example.com', $itemB['val']);
        $this->assertEquals('B', $itemB['col_letter']);

        $itemAL = collect($payload['values_by_position'])->firstWhere('col_index', 38);
        $this->assertNotNull($itemAL);
        $this->assertEquals('AL', $itemAL['col_letter']);
        $this->assertEquals('KET', $itemAL['header']);
    }

    /**
     * Test 4: scanWorkspaceRows skips synced rows when skip_synced filter is active.
     */
    public function test_scan_workspace_rows_skips_synced_when_filter_enabled(): void
    {
        $mockTransport = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();
        $reconcileService = new \App\Services\ActiveSheetManualRowReconciliationService(
            transport: $mockTransport,
            mappingService: app(\App\Services\RegistrationSpreadsheetMappingService::class)
        );

        $mockTransport->setMockSheetData('Test Tab', [
            [
                'Nama'            => 'Budi Sudah Ada ID',
                'Email'           => 'budi@example.com',
                'SERTIF_SYNC_ID'  => 'SYNC-1111',
            ],
            [
                'Nama'            => 'Siti Belum Ada ID',
                'Email'           => 'siti@example.com',
                'SERTIF_SYNC_ID'  => '',
            ],
        ]);

        // When skipSynced is true, row 1 (Budi) should be skipped and only row 2 (Siti) returned
        $res = $reconcileService->scanWorkspaceRows(
            connection: $this->connection,
            tabName: 'Test Tab',
            headerRow: 1,
            skipSynced: true
        );

        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['rows']);
        $this->assertEquals('Siti Belum Ada ID', $res['rows'][0]['display_name']);
    }

    /**
     * Test 5: scanWorkspaceRows detects each unique color and column value exactly once
     * while candidate rows are filtered to only unsynced rows.
     */
    public function test_scan_workspace_rows_detects_all_distinct_status_sources_once_while_skipping_synced(): void
    {
        $mockTransport = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();
        $reconcileService = new \App\Services\ActiveSheetManualRowReconciliationService(
            transport: $mockTransport,
            mappingService: app(\App\Services\RegistrationSpreadsheetMappingService::class)
        );

        $mockTransport->setMockSheetData('Test Tab Status', [
            [
                '_row_number'     => 2,
                '_row_background' => '#00ff00',
                'Nama'            => 'Budi Terdaftar',
                'KET'             => 'Diterima',
                'SERTIF_SYNC_ID'  => 'SYNC-AAA',
            ],
            [
                '_row_number'     => 3,
                '_row_background' => '#ffff00',
                'Nama'            => 'Ani Terdaftar',
                'KET'             => 'Calon',
                'SERTIF_SYNC_ID'  => 'SYNC-BBB',
            ],
            [
                '_row_number'     => 4,
                '_row_background' => '#ff0000',
                'Nama'            => 'Candra Belum Ada ID',
                'KET'             => 'Tolak',
                'SERTIF_SYNC_ID'  => '',
            ],
        ]);

        $res = $reconcileService->scanWorkspaceRows(
            connection: $this->connection,
            tabName: 'Test Tab Status',
            headerRow: 1,
            skipSynced: true
        );

        $this->assertTrue($res['success']);

        // 1. Palette must contain all 3 distinct colors (each 1 time)
        $paletteHexes = array_column($res['palette'], 'hex');
        $this->assertContains('#00ff00', $paletteHexes);
        $this->assertContains('#ffff00', $paletteHexes);
        $this->assertContains('#ff0000', $paletteHexes);
        $this->assertCount(3, $res['palette']);

        // 2. Column distinct values must contain all 3 unique KET values (each 1 time)
        $this->assertArrayHasKey('KET', $res['column_distinct_values']);
        $this->assertArrayHasKey('Diterima', $res['column_distinct_values']['KET']);
        $this->assertArrayHasKey('Calon', $res['column_distinct_values']['KET']);
        $this->assertArrayHasKey('Tolak', $res['column_distinct_values']['KET']);
        $this->assertCount(3, array_keys($res['column_distinct_values']['KET']));

        // 3. Rows must only contain the 1 unsynced row
        $this->assertCount(1, $res['rows']);
        $this->assertEquals('Candra Belum Ada ID', $res['rows'][0]['display_name']);
        $this->assertEquals('#ff0000', $res['rows'][0]['row_background']);
    }

    /**
     * Test 6: queueSyncForRegistration resolves dynamic universal target tab from active schema version.
     */
    public function test_universal_target_tab_dynamically_resolved_from_schema_version(): void
    {
        $customTabName = 'Pendaftaran Brand ' . uniqid();

        $brand = \App\Models\Brand::create([
            'name' => 'Brand Test ' . uniqid(),
            'code' => 'B' . rand(10, 99),
        ]);

        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION, 'brand_id' => $brand->id],
            [
                'display_name'             => 'Spreadsheet Test Brand ' . $brand->id,
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test_brand_' . $brand->id . '/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_HARMONIZATION/exec',
                'spreadsheet_id'           => 'test_brand_' . $brand->id . '_id',
            ]
        );
        $conn->update(['is_enabled' => true, 'connection_status' => SpreadsheetConnection::STATUS_CONNECTED]);

        $schema = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $conn->id,
            'year'                      => 2026,
            'sheet_tab_name'            => $customTabName,
            'schema_version'            => 'v_univ_' . uniqid(),
            'is_active'                 => true,
        ]);

        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schema->id,
            'column_identity'               => SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'A', 1, 'Email'),
            'column_order'                  => 1,
            'sheet_header'                  => 'Email',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'email',
            'data_type'                     => 'string',
            'direction'                     => 'outbound',
            'mapping_status'                => 'active',
        ]);

        $reg = InternshipRegistration::create([
            'brand_id'          => $brand->id,
            'email'             => 'brand_univ@example.com',
            'fullname'          => 'Calon Pemagang Universal',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
            'created_at'        => now(),
        ]);
        $reg->created_at = now();
        $reg->save();

        $engine = app(\App\Services\SpreadsheetSyncEngineService::class);
        $sync = $engine->queueSyncForRegistration($reg, allowPendaftar: true);

        $this->assertNotNull($sync);
        $this->assertEquals($customTabName, $sync->target_sheet_tab);
    }

    /**
     * Test 7: Outbound row payload properly normalizes field_... slugs into standard data.
     */
    public function test_outbound_row_payload_normalizes_universal_slugs(): void
    {
        $schema = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'year'                      => 2026,
            'sheet_tab_name'            => 'Universal Normalization Tab',
            'schema_version'            => 'v_norm_' . uniqid(),
            'is_active'                 => true,
        ]);

        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schema->id,
            'column_identity'               => SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'A', 1, 'Nama Lengkap'),
            'column_order'                  => 1,
            'sheet_header'                  => 'Nama Lengkap',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'field_nama_lengkap',
            'data_type'                     => 'string',
            'direction'                     => 'display_only',
            'mapping_status'                => 'active',
        ]);

        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $schema->id,
            'column_identity'               => SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(101, 'B', 2, 'Email'),
            'column_order'                  => 2,
            'sheet_header'                  => 'Email',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'field_email',
            'data_type'                     => 'string',
            'direction'                     => 'display_only',
            'mapping_status'                => 'active',
        ]);

        $reg = new InternshipRegistration([
            'email'             => 'calon_norm@example.com',
            'fullname'          => 'Budi Normalisasi',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg->id = 777;
        $reg->created_at = now();

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($reg, $schema, null, true);

        $this->assertEquals('Budi Normalisasi', $payload['Nama Lengkap']);
        $this->assertEquals('calon_norm@example.com', $payload['Email']);
    }

    /**
     * Test 8: Identifikasi mendeteksi orphaned_remote_id saat baris spreadsheet sudah ber-ID
     * namun record pendaftaran lokal di database SertIF hilang/terhapus.
     */
    public function test_orphaned_remote_id_detected_when_registration_missing_in_db(): void
    {
        $mockTransport = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();
        $reconcileService = new \App\Services\ActiveSheetManualRowReconciliationService(
            transport: $mockTransport,
            mappingService: app(RegistrationSpreadsheetMappingService::class)
        );

        $rowData = [
            'Nama Lengkap'           => 'Calon Hilang di DB',
            'Email'                  => 'calon.hilang@example.com',
            'No HP'                  => '081299998888',
            'SERTIF_SYNC_ID'         => 'REG-9999-deadbeef',
            'SERTIF_REGISTRATION_ID' => '9999',
        ];

        // Pastikan tidak ada registrasi #9999 di database
        InternshipRegistration::where('id', 9999)->forceDelete();
        \App\Models\SpreadsheetRowSync::where('external_sync_id', 'REG-9999-deadbeef')->delete();

        $resolution = $reconcileService->resolveIdentityMatch($this->connection, $rowData);

        $this->assertEquals('orphaned_remote_id', $resolution['match_type']);
        $this->assertEquals('REG-9999-deadbeef', $resolution['sync_id']);
        $this->assertEquals(9999, $resolution['remote_registration_id']);
        $this->assertNull($resolution['matched_registration_id']);
        $this->assertTrue($resolution['is_recovery_candidate']);
    }

    /**
     * Test 9: recoverOrReingestExistingRow memulihkan data ke SertIF dengan mempertahankan
     * SERTIF_SYNC_ID asli tanpa membuat ID acak baru pada spreadsheet.
     */
    public function test_recover_or_reingest_existing_row_preserves_external_sync_id_and_creates_registration(): void
    {
        $mockTransport = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();
        $reconcileService = new \App\Services\ActiveSheetManualRowReconciliationService(
            transport: $mockTransport,
            mappingService: app(RegistrationSpreadsheetMappingService::class)
        );

        $syncId = 'REG-8888-c81e728d';
        $regId = 8888;

        InternshipRegistration::where('id', $regId)->forceDelete();
        \App\Models\SpreadsheetRowSync::where('external_sync_id', $syncId)->delete();

        $rowData = [
            'Nama Lengkap'           => 'Ahmad Pulih SertIF',
            'Email'                  => 'ahmad.pulih@example.com',
            'No HP'                  => '081377776666',
            'NIM'                    => '12345678',
            'Asal Sekolah'           => 'SMK Negeri 1 Yogyakarta',
            'Jurusan'                => 'Teknik Informatika',
            'SERTIF_SYNC_ID'         => $syncId,
            'SERTIF_REGISTRATION_ID' => (string) $regId,
        ];

        $res = $reconcileService->recoverOrReingestExistingRow(
            connection: $this->connection,
            tabName: 'Pendaftaran 2026',
            rowIndex: 15,
            existingSyncId: $syncId,
            remoteRegistrationId: $regId,
            rowData: $rowData,
            targetStatus: 'accepted'
        );

        $this->assertTrue($res['success']);
        $this->assertTrue($res['is_recovered']);
        $this->assertEquals($syncId, $res['sync_id']);

        // Verifikasi record berhasil dibuat di database lokal
        $restoredReg = InternshipRegistration::find($res['registration_id']);
        $this->assertNotNull($restoredReg);
        $this->assertEquals('Ahmad Pulih SertIF', $restoredReg->fullname);
        $this->assertEquals('ahmad.pulih@example.com', $restoredReg->email);
        $this->assertEquals('081377776666', $restoredReg->phone_number);
        $this->assertEquals('SMK Negeri 1 Yogyakarta', $restoredReg->raw_institution_name);
        $this->assertEquals('Teknik Informatika', $restoredReg->raw_study_program_name);
        $this->assertEquals('accepted', $restoredReg->internship_status);
        $this->assertEquals('recovered_sheet', $restoredReg->registration_provenance);
        $this->assertTrue((bool) $restoredReg->is_integrated);

        // Verifikasi SpreadsheetRowSync tertaut dengan external_sync_id asli
        $rowSync = \App\Models\SpreadsheetRowSync::where('spreadsheet_connection_id', $this->connection->id)
            ->where('external_sync_id', $syncId)
            ->first();

        $this->assertNotNull($rowSync);
        $this->assertEquals($restoredReg->id, $rowSync->syncable_id);
        $this->assertEquals(\App\Models\SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals(15, $rowSync->row_index);
    }

    /**
     * Test 10: Endpoint batch-recover memulihkan baris ber-ID secara massal melalui HTTP controller.
     */
    public function test_batch_recover_endpoint_reingests_rows_with_existing_ids(): void
    {
        $syncId = 'REG-7777-a1b2c3d4';
        InternshipRegistration::where('id', 7777)->forceDelete();
        \App\Models\SpreadsheetRowSync::where('external_sync_id', $syncId)->delete();

        $response = $this->actingAs($this->adminUser)->postJson(
            route('admin.spreadsheet-connections.batch-recover', $this->connection),
            [
                'tab_name' => 'Form Pendaftaran Magang',
                'items'    => [
                    [
                        'row_index'       => 20,
                        'sync_id'         => $syncId,
                        'registration_id' => 7777,
                        'status'          => 'active',
                        'row_data'        => [
                            'Nama Lengkap'   => 'Rina Batch Recover',
                            'Email'          => 'rina.batch@example.com',
                            'No HP'          => '081244443333',
                            'SERTIF_SYNC_ID' => $syncId,
                        ],
                    ],
                ],
            ]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'recovered_count' => 1,
                'failed_count'    => 0,
            ],
        ]);

        $this->assertDatabaseHas('internship_registrations', [
            'email'    => 'rina.batch@example.com',
            'fullname' => 'Rina Batch Recover',
        ]);

        $this->assertDatabaseHas('spreadsheet_row_syncs', [
            'spreadsheet_connection_id' => $this->connection->id,
            'external_sync_id'          => $syncId,
            'sync_status'               => 'synced',
        ]);
    }

    /**
     * Test 11: reconcileBatchRows secara cerdas mendeteksi baris yang sudah memiliki SERTIF_SYNC_ID
     * dan otomatis melakukan recovery tanpa merusak/mengganti ID pada baris spreadsheet.
     */
    public function test_reconcile_batch_rows_automatically_recovers_missing_rows_without_corrupting_remote_id(): void
    {
        $mockTransport = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();
        $reconcileService = new \App\Services\ActiveSheetManualRowReconciliationService(
            transport: $mockTransport,
            mappingService: app(RegistrationSpreadsheetMappingService::class)
        );

        $syncId = 'REG-6666-e5f6g7h8';
        InternshipRegistration::where('id', 6666)->forceDelete();
        \App\Models\SpreadsheetRowSync::where('external_sync_id', $syncId)->delete();

        $items = [
            [
                'row_index' => 25,
                'status'    => 'waiting',
                'sync_id'   => $syncId,
                'row_data'  => [
                    'Nama Lengkap'   => 'Dedi Auto Recover',
                    'Email'          => 'dedi.auto@example.com',
                    'SERTIF_SYNC_ID' => $syncId,
                ],
            ],
        ];

        $res = $reconcileService->reconcileBatchRows(
            connection: $this->connection,
            tabName: 'Pendaftaran 2026',
            items: $items
        );

        $this->assertEquals(1, $res['success_count']);
        $this->assertEquals(0, $res['failed_count']);
        $this->assertEquals($syncId, $res['results'][0]['sync_id']);

        // Verifikasi bahwa ID tidak berubah menjadi UUID baru melainkan tetap syncId asli
        $savedSync = \App\Models\SpreadsheetRowSync::where('spreadsheet_connection_id', $this->connection->id)
            ->where('external_sync_id', $syncId)
            ->first();

        $this->assertNotNull($savedSync);
        $this->assertEquals($syncId, $savedSync->external_sync_id);
    }
}
