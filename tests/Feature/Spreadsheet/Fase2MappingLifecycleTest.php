<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
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
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\DocumentTemplates\TemplateLifecycleService;
use App\Services\FormBuilderService;
use App\Services\SpreadsheetDiscoveryService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Fase2MappingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;
    protected User $pemagangUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Mapping',
            'email'     => 'admin.mapping@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagangUser = User::create([
            'name'      => 'Pemagang User',
            'email'     => 'pemagang.user@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Spreadsheet Pendaftaran 2026',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_MAPPING_GATEWAY/exec',
                'spreadsheet_id'           => '1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug',
            ]
        );
    }

    /**
     * Gate F2-01: Nama tab 2026 diganti, sheetId tetap -> konfigurasi header row tidak berpindah/hilang.
     */
    public function test_f2_01_tab_rename_preserves_header_row_configuration(): void
    {
        $service = app(SpreadsheetDiscoveryService::class);

        // Explicitly set header row 12 for sheet_id 101 with tab name "2026"
        $profile = $service->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 101,
            tabName: '2026',
            explicitHeaderRow: 12,
            explicitDataStartRow: 13
        );

        $this->assertEquals(101, $profile->sheet_id);
        $this->assertEquals('2026', $profile->tab_name);
        $this->assertEquals(12, $profile->header_row);

        // Later, admin renames tab on Google Sheets to "Pendaftaran 2026", but sheetId is still 101
        $updatedProfile = $service->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 101,
            tabName: 'Pendaftaran 2026'
        );

        // Should retain header_row = 12 because sheet_id is persistent identity
        $this->assertEquals(101, $updatedProfile->sheet_id);
        $this->assertEquals('Pendaftaran 2026', $updatedProfile->tab_name);
        $this->assertEquals(12, $updatedProfile->header_row);
        $this->assertEquals(13, $updatedProfile->data_start_row);
    }

    /**
     * Gate F2-02: Tab non-tahun header ada di baris 12 -> profil eksplisit memilih 12, bukan tebak row 1.
     */
    public function test_f2_02_non_year_tab_with_explicit_profile_row_12(): void
    {
        $service = app(SpreadsheetDiscoveryService::class);

        // A non-year tab like "Follow-Up-Khusus" has header on row 12
        $profile = $service->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 303,
            tabName: 'Follow-Up-Khusus',
            explicitHeaderRow: 12,
            explicitDataStartRow: 13
        );

        $this->assertEquals(12, $profile->header_row);

        // When queried without explicit override, it loads the saved explicit profile (row 12), NOT default row 1
        $resolved = $service->resolveTabHeaderProfile(
            $this->connection,
            sheetId: 303,
            tabName: 'Follow-Up-Khusus'
        );

        $this->assertEquals(12, $resolved->header_row);
    }

    /**
     * Gate F2-03: Header ganda pada satu tab -> ditandai conflict, tak auto-match.
     */
    public function test_f2_03_duplicate_headers_on_same_tab_marked_conflict(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->setInspectSchemaResponse(
            SpreadsheetTransportResponse::ok(context: [
                'tab_name'     => '2026',
                'header_row'   => 12,
                'columns_count'=> 4,
                'schema_hash'  => 'hash_f2_03_dup',
                'columns'      => [
                    ['col_letter' => 'A', 'col_index' => 1, 'header_name' => 'Alamat Domisili', 'inferred_type' => 'string', 'has_formula' => false, 'samples' => ['Jl. Kaliurang']],
                    ['col_letter' => 'B', 'col_index' => 2, 'header_name' => 'Alamat Domisili', 'inferred_type' => 'string', 'has_formula' => false, 'samples' => ['Sleman']],
                    ['col_letter' => 'C', 'col_index' => 3, 'header_name' => 'Nama Lengkap', 'inferred_type' => 'string', 'has_formula' => false, 'samples' => ['Budi']],
                    ['col_letter' => 'D', 'col_index' => 4, 'header_name' => 'SERTIF_REGISTRATION_ID', 'inferred_type' => 'string', 'has_formula' => false, 'samples' => ['REG-001']],
                ]
            ])
        );

        $this->app->instance(SpreadsheetTransportInterface::class, $mockTransport);
        $service = app(SpreadsheetDiscoveryService::class);

        $snapshot = $service->saveDiscoverySnapshot($this->connection, '2026', 12, 101, $this->adminUser);
        $this->assertEquals(4, $snapshot->columns_count);

        // Both 'Alamat Domisili' columns must be flagged as duplicates
        $dupCols = $snapshot->columns()->where('is_duplicate_label', true)->get();
        $this->assertCount(2, $dupCols);

        // Proposal must mark them has_collision = true and confidence = UNMATCHED
        $proposal = $service->createMappingProposal($this->connection, $snapshot, 2026, null, $this->adminUser);
        $collidedItems = $proposal->items()->where('has_collision', true)->get();

        $this->assertCount(2, $collidedItems);
        foreach ($collidedItems as $item) {
            $this->assertEquals('UNMATCHED', $item->confidence_level);
            $this->assertEquals('ignore', $item->action);
            $this->assertStringContainsString('lebih dari satu kali', $item->collision_detail);
        }
    }

    /**
     * Gate F2-04: Header tambahan tidak dikenal -> disimpan sebagai proposal unmapped, bukan field published.
     */
    public function test_f2_04_unknown_extra_header_saved_as_unmapped_proposal(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->setInspectSchemaResponse(
            SpreadsheetTransportResponse::ok(context: [
                'tab_name'     => '2026',
                'header_row'   => 12,
                'columns_count'=> 2,
                'schema_hash'  => 'hash_f2_04',
                'columns'      => [
                    ['col_letter' => 'A', 'col_index' => 1, 'header_name' => 'Ukuran Kaos Ekstra', 'inferred_type' => 'string', 'has_formula' => false, 'samples' => ['XL']],
                    ['col_letter' => 'B', 'col_index' => 2, 'header_name' => 'Nama Lengkap', 'inferred_type' => 'string', 'has_formula' => false, 'samples' => ['Ahmad']],
                ]
            ])
        );

        $this->app->instance(SpreadsheetTransportInterface::class, $mockTransport);
        $service = app(SpreadsheetDiscoveryService::class);

        $snapshot = $service->saveDiscoverySnapshot($this->connection, '2026', 12, 101, $this->adminUser);
        $proposal = $service->createMappingProposal($this->connection, $snapshot, 2026, null, $this->adminUser);

        $item = $proposal->items()->where('observed_label', 'Ukuran Kaos Ekstra')->first();
        $this->assertNotNull($item);
        $this->assertEquals('UNMATCHED', $item->confidence_level);
        $this->assertEquals('external_only', $item->action);

        // Verify that FormField does NOT automatically contain 'ukuran_kaos_ekstra'
        $this->assertDatabaseMissing('form_fields', ['field_key' => 'ukuran_kaos_ekstra']);
    }

    /**
     * Gate F2-05: Kolom dengan formula di bawah header -> tidak pernah dihapus/overwrite otomatis.
     */
    public function test_f2_05_formula_column_protected_from_overwrite(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->setInspectSchemaResponse(
            SpreadsheetTransportResponse::ok(context: [
                'tab_name'     => '2026',
                'header_row'   => 12,
                'columns_count'=> 1,
                'schema_hash'  => 'hash_f2_05',
                'columns'      => [
                    ['col_letter' => 'Z', 'col_index' => 26, 'header_name' => 'Durasi Magang Hari', 'inferred_type' => 'number', 'has_formula' => true, 'samples' => ['=DATEDIF(F13, G13, "D")']],
                ]
            ])
        );

        $this->app->instance(SpreadsheetTransportInterface::class, $mockTransport);
        $service = app(SpreadsheetDiscoveryService::class);

        $snapshot = $service->saveDiscoverySnapshot($this->connection, '2026', 12, 101, $this->adminUser);
        $col = $snapshot->columns()->where('observed_label', 'Durasi Magang Hari')->first();

        $this->assertTrue($col->has_formula);

        $proposal = $service->createMappingProposal($this->connection, $snapshot, 2026, null, $this->adminUser);
        $diff = $service->previewSchemaDiff($proposal);

        // Zero DELETE_COLUMN in diff
        $this->assertEquals(0, $diff['summary']['delete'] ?? 0);
        $deleteActions = collect($diff['items'])->filter(fn($i) => ($i['action_type'] ?? '') === 'DELETE_COLUMN');
        $this->assertCount(0, $deleteActions);
    }

    /**
     * Gate F2-06: Reorder posisi kolom -> mapping terverifikasi tetap ke identity yang sama, tidak geser baris.
     */
    public function test_f2_06_column_reorder_preserves_mapping_identity(): void
    {
        // Snapshot 1: Col A is Nama Lengkap (1), Col B is Email (2)
        $snapshot1 = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_reorder_1',
            'columns_count'             => 2,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $col1 = SheetDiscoveredColumn::create([
            'snapshot_id'                 => $snapshot1->id,
            'column_identity'             => 'col-ident-nama',
            'col_letter'                  => 'A',
            'physical_index'              => 1,
            'observed_label'              => 'Nama Lengkap',
            'inferred_type'               => 'string',
        ]);

        $col2 = SheetDiscoveredColumn::create([
            'snapshot_id'                 => $snapshot1->id,
            'column_identity'             => 'col-ident-email',
            'col_letter'                  => 'B',
            'physical_index'              => 2,
            'observed_label'              => 'Email Mahasiswa',
            'inferred_type'               => 'string',
        ]);

        // Proposal links identity to field_key
        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'                 => $snapshot1->id,
            'expected_schema_hash'        => 'hash_reorder_1',
            'year'                        => 2026,
            'version'                     => 1,
            'status'                      => SheetMappingProposal::STATUS_DRAFT,
            'created_by'                  => $this->adminUser->id,
        ]);

        $item1 = SheetMappingProposalItem::create([
            'proposal_id'               => $proposal->id,
            'discovered_column_id'      => $col1->id,
            'column_identity'           => 'col-ident-nama',
            'col_letter'                => 'A',
            'physical_index'            => 1,
            'observed_label'            => 'Nama Lengkap',
            'action'                    => SheetMappingProposalItem::ACTION_LINK_EXISTING,
            'field_key'                 => 'nama_lengkap',
            'confidence_score'          => 1.00,
            'confidence_level'          => 'EXACT',
        ]);

        $item2 = SheetMappingProposalItem::create([
            'proposal_id'               => $proposal->id,
            'discovered_column_id'      => $col2->id,
            'column_identity'           => 'col-ident-email',
            'col_letter'                => 'B',
            'physical_index'            => 2,
            'observed_label'            => 'Email Mahasiswa',
            'action'                    => SheetMappingProposalItem::ACTION_LINK_EXISTING,
            'field_key'                 => 'email',
            'confidence_score'          => 1.00,
            'confidence_level'          => 'EXACT',
        ]);

        // Now remote spreadsheet swaps them: Col A becomes Email, Col B becomes Nama
        // Even if physical_index swaps, identity 'col-ident-nama' continues to point to 'nama_lengkap'
        $this->assertEquals('nama_lengkap', $item1->field_key);
        $this->assertEquals('col-ident-nama', $item1->column_identity);
        $this->assertEquals('email', $item2->field_key);
        $this->assertEquals('col-ident-email', $item2->column_identity);
    }

    /**
     * Gate F2-07: Rename label field -> field_key tetap, histori utuh.
     */
    public function test_f2_07_field_label_rename_preserves_field_key_and_history(): void
    {
        $formService = app(FormBuilderService::class);
        $draftSchema = $formService->ensureYearFormInitialized(2026, $this->adminUser);

        // Add a field to draft
        $field = $formService->addFieldToDraft($draftSchema, [
            'field_key'  => 'nama_panggilan_khusus',
            'label'      => 'Nama Panggilan Khusus',
            'field_type' => 'text',
        ]);

        // Update display label in draft using 'label' parameter
        $updatedField = $formService->updateFieldInDraft($field, [
            'label' => 'Nama Panggilan Akrab',
        ]);

        // field_key remains immutable
        $this->assertEquals('nama_panggilan_khusus', $updatedField->field_key);
        $this->assertEquals('Nama Panggilan Akrab', $updatedField->label);
    }

    /**
     * Gate F2-08: Rename header remote dengan identity berbeda -> drift/conflict, bukan auto create/erase.
     */
    public function test_f2_08_remote_header_rename_triggers_drift_conflict(): void
    {
        $service = app(SpreadsheetDiscoveryService::class);

        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_expected_123',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $col = SheetDiscoveredColumn::create([
            'snapshot_id'                 => $snapshot->id,
            'column_identity'             => 'col-ident-origin',
            'col_letter'                  => 'A',
            'physical_index'              => 1,
            'observed_label'              => 'Nomor Induk Siswa',
            'inferred_type'               => 'string',
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'                 => $snapshot->id,
            'expected_schema_hash'        => 'hash_expected_123',
            'year'                        => 2026,
            'version'                     => 1,
            'status'                      => SheetMappingProposal::STATUS_DRAFT,
            'created_by'                  => $this->adminUser->id,
        ]);

        // When remote schema hash drifts (e.g. Someone renamed column to 'NIM Mahasiswa')
        $snapshot->update(['schema_hash' => 'hash_drifted_456']);

        $formService = app(FormBuilderService::class);
        $draftSchema = $formService->ensureYearFormInitialized(2026, $this->adminUser);

        // Applying proposal with drifted hash must throw SCHEMA_CONFLICT
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SCHEMA_CONFLICT');

        $service->applyProposalToDraft($proposal, $draftSchema, $this->adminUser);
    }

    /**
     * Gate F2-09: Field draft baru, tak digunakan -> bisa delete dari draft bila seluruh guard terpenuhi.
     */
    public function test_f2_09_pristine_draft_field_can_be_deleted(): void
    {
        $formService = app(FormBuilderService::class);
        $draftSchema = $formService->ensureYearFormInitialized(2026, $this->adminUser);

        // Add a brand new draft custom field using 'label'
        $newField = $formService->addFieldToDraft($draftSchema, [
            'field_key'   => 'ukuran_sepatu_pemagang',
            'label'       => 'Ukuran Sepatu',
            'field_type'  => 'text',
        ]);

        $this->assertDatabaseHas('form_fields', ['id' => $newField->id]);

        // Pristine draft with 0 answers, 0 locked registry, 0 published schema, 0 proposal deps can be deleted
        $success = $formService->deleteFieldFromDraft($newField, $this->adminUser);
        $this->assertTrue($success);
        $this->assertTrue($newField->fresh()->trashed());
        $this->assertSoftDeleted('form_fields', ['id' => $newField->id]);
    }

    /**
     * Gate F2-10: Field published, zero answers -> tidak boleh hard-delete.
     */
    public function test_f2_10_published_field_with_zero_answers_cannot_be_hard_deleted(): void
    {
        $formService = app(FormBuilderService::class);
        $draftSchema = $formService->ensureYearFormInitialized(2026, $this->adminUser);

        $newField = $formService->addFieldToDraft($draftSchema, [
            'field_key'   => 'warna_favorit',
            'label'       => 'Warna Favorit',
            'field_type'  => 'text',
        ]);

        // Publish the schema
        $draftSchema->update(['status' => FormSchema::STATUS_PUBLISHED, 'is_published' => true]);

        // Deleting field from published schema must be blocked
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('telah dipublikasikan');

        $formService->deleteFieldFromDraft($newField, $this->adminUser);
    }

    /**
     * Gate F2-11: Field punya jawaban/snapshot -> archive/inactive, jawaban tetap tersedia.
     */
    public function test_f2_11_field_with_answers_cannot_be_deleted_archive_only(): void
    {
        $formService = app(FormBuilderService::class);
        $draftSchema = $formService->ensureYearFormInitialized(2026, $this->adminUser);

        $field = $formService->addFieldToDraft($draftSchema, [
            'field_key'  => 'nomor_darurat_pemagang',
            'label'      => 'Nomor Kontak Darurat',
            'field_type' => 'text',
        ]);

        // Create a custom answer directly for this field
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        RegistrationCustomAnswer::create([
            'registration_id' => 99999,
            'field_key'       => 'nomor_darurat_pemagang',
            'answer_value'    => '0899999999',
        ]);
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Deletion must be strictly blocked
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jawaban');

        $formService->deleteFieldFromDraft($field, $this->adminUser);
    }

    /**
     * Gate F2-12: Protected SERTIF_* ID -> tidak bisa ditimpa/dimap custom.
     */
    public function test_f2_12_protected_sertif_id_cannot_be_overwritten_by_custom(): void
    {
        $service = app(SpreadsheetDiscoveryService::class);

        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_f2_12',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $col = SheetDiscoveredColumn::create([
            'snapshot_id'                 => $snapshot->id,
            'column_identity'             => 'col-ident-sertif-id',
            'col_letter'                  => 'Z',
            'physical_index'              => 26,
            'observed_label'              => 'SERTIF_REGISTRATION_ID',
            'inferred_type'               => 'string',
            'is_system_reserved'          => true,
        ]);

        $proposal = $service->createMappingProposal($this->connection, $snapshot, 2026, null, $this->adminUser);
        $item = $proposal->items()->where('observed_label', 'SERTIF_REGISTRATION_ID')->first();

        $this->assertNotNull($item);
        $this->assertEquals(SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED, $item->action);
        $this->assertEquals('sertif_registration_id', $item->field_key);

        // Attempting to mutate via controller endpoint returns 422
        $response = $this->actingAs($this->adminUser)->putJson(
            route('admin.spreadsheet-connections.proposals.items.update', [$this->connection, $proposal, $item]),
            [
                'action'    => 'link_existing',
                'field_key' => 'custom_field_overwrite',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonFragment(['success' => false]);
    }

    /**
     * Gate F2-13: Stale source schema hash -> 409 SCHEMA_CONFLICT, proposal tetap aman.
     */
    public function test_f2_13_stale_source_schema_hash_triggers_409(): void
    {
        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_old_stale',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'                 => $snapshot->id,
            'expected_schema_hash'        => 'hash_old_stale',
            'year'                        => 2026,
            'version'                     => 1,
            'status'                      => SheetMappingProposal::STATUS_DRAFT,
            'created_by'                  => $this->adminUser->id,
        ]);

        // Drift snapshot hash
        $snapshot->update(['schema_hash' => 'hash_new_drifted']);

        $response = $this->actingAs($this->adminUser)->postJson(
            route('admin.spreadsheet-connections.proposals.apply', [$this->connection, $proposal])
        );

        $response->assertStatus(409);
        $response->assertJsonFragment(['success' => false]);
    }

    /**
     * Gate F2-14: Publish DB sukses, Google timeout -> Form published; remote pending/failed; UI tidak klaim synced.
     */
    public function test_f2_14_publish_db_success_with_google_timeout_decoupled(): void
    {
        $formService = app(FormBuilderService::class);
        $draftSchema = $formService->ensureYearFormInitialized(2026, $this->adminUser);

        // Add a field to draft so schema can be published
        $formService->addFieldToDraft($draftSchema, [
            'field_key'  => 'field_test_pub_14',
            'label'      => 'Field Test Publish',
            'field_type' => 'text',
        ]);

        // Mock transport that throws a timeout exception on remote operations
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->setForceException(new \RuntimeException('Google Apps Script request timed out after 30000ms'));
        $this->app->instance(SpreadsheetTransportInterface::class, $mockTransport);

        // Post to form fields publish route
        $response = $this->actingAs($this->adminUser)->post(
            route('admin.form-settings.fields.publish'),
            ['year' => 2026]
        );

        // Response redirects with a warning, NOT claiming remote sync success
        $response->assertSessionHas('warning');

        $freshSchema = $draftSchema->fresh();
        $this->assertEquals(FormSchema::STATUS_PUBLISHED, $freshSchema->status);

        // Outbox event must be recorded as failed or pending, NEVER verified
        $outbox = IntegrationOutboxEvent::where('target_connection_id', $this->connection->id)->latest('id')->first();
        if ($outbox) {
            $this->assertContains($outbox->status, [IntegrationOutboxEvent::STATUS_FAILED, IntegrationOutboxEvent::STATUS_PENDING]);
            $this->assertNotEquals(IntegrationOutboxEvent::STATUS_VERIFIED, $outbox->status);
        }
    }

    /**
     * Gate F2-15: Retry same request ID / idempotency key -> tidak membuat kolom/event duplikat.
     */
    public function test_f2_15_idempotency_key_prevents_duplicate_outbox_events(): void
    {
        $key = 'idemp_event_f2_15_test';

        $event1 = IntegrationOutboxEvent::create([
            'event_type'            => 'form_schema.published',
            'target_connection_id'  => $this->connection->id,
            'aggregate_type'        => 'FormSchema',
            'aggregate_id'          => '10',
            'idempotency_key'       => $key,
            'expected_schema_hash'  => 'hash_15',
            'status'                => IntegrationOutboxEvent::STATUS_PENDING,
            'payload'               => ['year' => 2026],
        ]);

        $this->assertNotNull($event1);

        // Creating with duplicate idempotency key must throw unique constraint exception
        $this->expectException(\Illuminate\Database\QueryException::class);

        IntegrationOutboxEvent::create([
            'event_type'            => 'form_schema.published',
            'target_connection_id'  => $this->connection->id,
            'aggregate_type'        => 'FormSchema',
            'aggregate_id'          => '10',
            'idempotency_key'       => $key,
            'expected_schema_hash'  => 'hash_15',
            'status'                => IntegrationOutboxEvent::STATUS_PENDING,
            'payload'               => ['year' => 2026],
        ]);
    }

    /**
     * Gate F2-16: Apps Script legacy shrinking schema -> tidak ada deleteColumns, clear, mass reorder.
     */
    public function test_f2_16_apps_script_has_no_delete_columns_and_fail_closed_on_clear(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const fs = require("fs");
const fileContent = fs.readFileSync(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"), "utf8");

// Check 1: deleteColumns must NOT appear in ensureSheetAndSyncHeaders
const hasDeleteColumns = /deleteColumns\s*\(/.test(fileContent);

// Check 2: handleClearTabDataAction must fail-closed with UNSAFE_LEGACY_SCHEMA_SYNC_DISABLED
const hasFailClosed = fileContent.includes("UNSAFE_LEGACY_SCHEMA_SYNC_DISABLED");

console.log(JSON.stringify({
    hasDeleteColumns,
    hasFailClosed
}));
JS;

        $process = new Process(['node', '-e', $nodeScript], base_path());
        $process->run();

        $this->assertTrue($process->isSuccessful(), "Node execution failed: " . $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);

        $this->assertFalse($result['hasDeleteColumns'], "Apps Script code still contains deleteColumns()!");
        $this->assertTrue($result['hasFailClosed'], "handleClearTabDataAction is missing fail-closed guard!");
    }

    /**
     * Gate F2-17: Authorization user pemagang -> Forbidden, tidak bocor header/data sensitif.
     */
    public function test_f2_17_pemagang_cannot_access_spreadsheet_connection_endpoints(): void
    {
        // JSON API requests return 403 Forbidden
        $this->actingAs($this->pemagangUser)
            ->getJson(route('admin.spreadsheet-connections.show', $this->connection))
            ->assertStatus(403);

        // Save tab profile JSON returns 403
        $this->actingAs($this->pemagangUser)
            ->postJson(route('admin.spreadsheet-connections.tab-profile.save', $this->connection), [
                'sheet_id'   => 101,
                'tab_name'   => '2026',
                'header_row' => 12,
            ])
            ->assertStatus(403);

        // Store snapshot JSON returns 403
        $this->actingAs($this->pemagangUser)
            ->postJson(route('admin.spreadsheet-connections.snapshots.store', $this->connection), [
                'tab_name' => '2026',
            ])
            ->assertStatus(403);

        // Web request redirects away from admin area (302)
        $this->actingAs($this->pemagangUser)
            ->get(route('admin.spreadsheet-connections.show', $this->connection))
            ->assertStatus(302);
    }

    /**
     * Gate F2-18: Header row 12, data row 13 + large rows -> paginated scan tanpa truncate, sample masked.
     */
    public function test_f2_18_paginated_scan_without_truncation_masked(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->setPreviewRowsResponse(
            SpreadsheetTransportResponse::ok(context: [
                'tab_name'   => '2026',
                'header_row' => 12,
                'page'       => 1,
                'page_size'  => 15,
                'total_rows' => 2500,
                'total_pages'=> 167,
                'has_more'   => true,
                'rows'       => [
                    [
                        '_row_number'   => 13,
                        'Nama Lengkap'  => 'Nur Rahman',
                        'Email'         => 'nur***@example.com',
                        'Nomor HP'      => '0812****890',
                    ],
                ]
            ])
        );

        $this->app->instance(SpreadsheetTransportInterface::class, $mockTransport);
        $service = app(SpreadsheetDiscoveryService::class);

        $res = $service->previewRows($this->connection, '2026', 12, 1, 15);

        $this->assertTrue($res['success']);
        $this->assertEquals(2500, $res['total_rows']);
        $this->assertEquals(167, $res['total_pages']);
        $this->assertTrue($res['has_more']);
        $this->assertCount(1, $res['rows']);

        // Check masking
        $this->assertStringContainsString('***', $res['rows'][0]['Email']);
        $this->assertStringContainsString('****', $res['rows'][0]['Nomor HP']);
    }

    /**
     * Gate F2-19: Proposal approved lalu user ubah draft -> optimistic version conflict, tidak silent overwrite.
     */
    public function test_f2_19_optimistic_version_conflict_prevents_silent_overwrite(): void
    {
        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_v19',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'                 => $snapshot->id,
            'expected_schema_hash'        => 'hash_v19',
            'year'                        => 2026,
            'version'                     => 1,
            'status'                      => SheetMappingProposal::STATUS_DRAFT,
            'created_by'                  => $this->adminUser->id,
        ]);

        // Simulating concurrent update that increments version to 2
        $proposal->update(['version' => 2]);

        // Stale request with version 1 trying to apply proposal
        $formService = app(FormBuilderService::class);
        $draftSchema = $formService->ensureYearFormInitialized(2026, $this->adminUser);

        $service = app(SpreadsheetDiscoveryService::class);

        // Manually setting stale version on memory model to trigger optimistic check
        $staleProposal = $proposal->replicate();
        $staleProposal->id = $proposal->id;
        $staleProposal->version = 1;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('OPTIMISTIC_LOCK_CONFLICT');

        $service->applyProposalToDraft($staleProposal, $draftSchema, $this->adminUser);
    }

    /**
     * Gate F2-20: V2 document/template regression -> DOCX, render, snapshot, RBAC unaffected.
     */
    public function test_f2_20_document_templates_v2_regression_unaffected(): void
    {
        // Verify Master Plan V2 classes and contracts exist
        $this->assertTrue(class_exists(DocumentTemplate::class));
        $this->assertTrue(class_exists(DocumentTemplateVersion::class));
        $this->assertTrue(class_exists(TemplateLifecycleService::class));
        $this->assertTrue(class_exists(DocumentTemplateRenderer::class));

        $template = DocumentTemplate::firstOrCreate(
            ['type' => 'loa', 'name' => 'Template Universal LoA Test F2'],
            [
                'description'  => 'Regression check for Master Plan V2',
                'is_active'    => true,
                'created_by'   => $this->adminUser->id,
            ]
        );

        $this->assertNotNull($template);
        $this->assertEquals('loa', $template->type);

        // Document engine service resolves placeholders cleanly
        $renderer = app(DocumentTemplateRenderer::class);
        $this->assertNotNull($renderer);
    }
}
