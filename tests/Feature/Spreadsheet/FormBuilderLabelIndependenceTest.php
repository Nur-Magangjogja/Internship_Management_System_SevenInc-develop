<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\SheetDiscoverySnapshot;
use App\Models\SheetMappingProposal;
use App\Models\SheetMappingProposalItem;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\SpreadsheetDiscoveryService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormBuilderLabelIndependenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected SpreadsheetConnection $connection;
    protected MockSpreadsheetTransport $mockTransport;
    protected SpreadsheetDiscoveryService $discoveryService;
    protected FormBuilderService $formBuilderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin P14',
            'email'     => 'admin.p14@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Google Sheets P1.4 Test',
                'spreadsheet_id'           => 'mock-spreadsheet-p14',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-p14/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_P14/exec',
            ]
        );

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);
        $this->discoveryService = app(SpreadsheetDiscoveryService::class);
        $this->formBuilderService = app(FormBuilderService::class);
    }

    /**
     * Test P1.4: link_existing only links to field_key and does NOT overwrite Form Builder field label.
     */
    public function test_link_existing_does_not_modify_form_builder_field_label(): void
    {
        $draft = $this->formBuilderService->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);

        // Create or update a field with canonical Form Builder label
        $field = FormField::create([
            'form_schema_id'   => $draft->id,
            'field_key'        => 'custom_hobi',
            'field_type'       => 'text',
            'label'            => 'Hobi Utama Pemagang (Label Asli SertIF)',
            'is_active'        => true,
            'is_archived'      => false,
            'is_required'      => false,
            'is_system'        => false,
            'sort_order'       => 99,
        ]);

        $originalLabel = $field->label;

        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_p14_test',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'               => $snapshot->id,
            'expected_schema_hash'      => 'hash_p14_test',
            'year'                      => 2026,
            'version'                   => 1,
            'status'                    => SheetMappingProposal::STATUS_DRAFT,
            'proposed_by'               => $this->adminUser->id,
        ]);

        $item = SheetMappingProposalItem::create([
            'proposal_id'          => $proposal->id,
            'col_letter'           => 'A',
            'physical_index'       => 1,
            'observed_label'       => 'What is your hobby? (Wild Spreadsheet Header)',
            'action'               => SheetMappingProposalItem::ACTION_LINK_EXISTING,
            'field_key'            => 'custom_hobi',
            'suggested_label'      => 'What is your hobby? (Wild Spreadsheet Header)',
            'confidence_score'     => 1.00,
            'confidence_level'     => 'EXACT',
        ]);

        // Apply proposal to draft
        $result = $this->discoveryService->applyProposalToDraft($proposal, $draft, $this->adminUser);

        $this->assertTrue($result['success']);

        // Assert field label is UNCHANGED in database
        $field->refresh();
        $this->assertSame($originalLabel, $field->label, 'Form Builder label must not be modified by link_existing');
    }

    /**
     * Test P1.4: previewSchemaDiff reports LINK_EXISTING and not RENAME_DISPLAY.
     */
    public function test_preview_schema_diff_reports_link_existing_not_rename_display(): void
    {
        $draft = $this->formBuilderService->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);

        FormField::create([
            'form_schema_id'   => $draft->id,
            'field_key'        => 'custom_catatan',
            'field_type'       => 'text',
            'label'            => 'Catatan Tambahan',
            'is_active'        => true,
            'is_archived'      => false,
            'is_required'      => false,
            'is_system'        => false,
            'sort_order'       => 99,
        ]);

        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_diff_test',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'               => $snapshot->id,
            'expected_schema_hash'      => 'hash_diff_test',
            'year'                      => 2026,
            'version'                   => 1,
            'status'                    => SheetMappingProposal::STATUS_DRAFT,
            'proposed_by'               => $this->adminUser->id,
        ]);

        SheetMappingProposalItem::create([
            'proposal_id'          => $proposal->id,
            'col_letter'           => 'A',
            'physical_index'       => 1,
            'observed_label'       => 'Notes From Google Sheets (Different Label)',
            'action'               => SheetMappingProposalItem::ACTION_LINK_EXISTING,
            'field_key'            => 'custom_catatan',
            'confidence_score'     => 0.90,
            'confidence_level'     => 'HIGH',
        ]);

        $diff = $this->discoveryService->previewSchemaDiff($proposal);

        $this->assertSame(0, $diff['summary']['rename'] ?? 0, 'rename count must be 0');
        $this->assertSame(1, $diff['summary']['link'] ?? 0, 'link count must be 1');

        $linkItem = collect($diff['items'])->firstWhere('diff_type', 'LINK_EXISTING');
        $this->assertNotNull($linkItem);
        $this->assertSame('custom_catatan', $linkItem['field_key']);
    }

    /**
     * Test P1.4: updateProposalItem validates suggested_field_type against supportedTypes and persists it.
     */
    public function test_update_proposal_item_validates_and_persists_field_type(): void
    {
        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_update_test',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'               => $snapshot->id,
            'expected_schema_hash'      => 'hash_update_test',
            'year'                      => 2026,
            'version'                   => 1,
            'status'                    => SheetMappingProposal::STATUS_DRAFT,
            'proposed_by'               => $this->adminUser->id,
        ]);

        $item = SheetMappingProposalItem::create([
            'proposal_id'          => $proposal->id,
            'col_letter'           => 'C',
            'physical_index'       => 3,
            'observed_label'       => 'Nilai IPK',
            'action'               => SheetMappingProposalItem::ACTION_CREATE_DRAFT,
            'suggested_label'      => 'Nilai IPK',
            'suggested_field_type' => 'text',
        ]);

        // 1. Valid update with supported type 'number'
        $response = $this->actingAs($this->adminUser)
            ->putJson(route('admin.spreadsheet-connections.proposals.items.update', [$this->connection, $proposal, $item]), [
                'action'               => 'create_draft_field',
                'field_key'            => 'gpa_score',
                'suggested_label'      => 'Nilai IPK Mahasiswa',
                'suggested_field_type' => 'number',
            ]);

        $response->assertStatus(200);
        $item->refresh();
        $this->assertSame('number', $item->suggested_field_type);
        $this->assertSame('gpa_score', $item->field_key);
        $this->assertSame('Nilai IPK Mahasiswa', $item->suggested_label);

        // 2. Invalid field_type is rejected with 422
        $responseInvalid = $this->actingAs($this->adminUser)
            ->putJson(route('admin.spreadsheet-connections.proposals.items.update', [$this->connection, $proposal, $item]), [
                'action'               => 'create_draft_field',
                'suggested_field_type' => 'invalid_unsupported_type',
            ]);

        $responseInvalid->assertStatus(422);
        $responseInvalid->assertJsonValidationErrors(['suggested_field_type']);
    }

    /**
     * Test P1.4: create_draft_field only creates field in draft and respects suggested_field_type.
     */
    public function test_create_draft_field_only_creates_in_draft_with_correct_type(): void
    {
        $draft = $this->formBuilderService->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);

        $snapshot = SheetDiscoverySnapshot::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'spreadsheet_id'            => $this->connection->spreadsheet_id,
            'sheet_id'                  => 101,
            'tab_name'                  => '2026',
            'header_row'                => 12,
            'data_start_row'            => 13,
            'schema_hash'               => 'hash_create_draft_test',
            'columns_count'             => 1,
            'scanned_by'                => $this->adminUser->id,
            'scanned_at'                => now(),
        ]);

        $proposal = SheetMappingProposal::create([
            'spreadsheet_connection_id' => $this->connection->id,
            'snapshot_id'               => $snapshot->id,
            'expected_schema_hash'      => 'hash_create_draft_test',
            'year'                      => 2026,
            'version'                   => 1,
            'status'                    => SheetMappingProposal::STATUS_DRAFT,
            'proposed_by'               => $this->adminUser->id,
        ]);

        SheetMappingProposalItem::create([
            'proposal_id'          => $proposal->id,
            'col_letter'           => 'D',
            'physical_index'       => 4,
            'observed_label'       => 'Link LinkedIn Mahasiswa',
            'action'               => SheetMappingProposalItem::ACTION_CREATE_DRAFT,
            'field_key'            => 'linkedin_profile_url',
            'suggested_label'      => 'Profil LinkedIn',
            'suggested_field_type' => 'url',
        ]);

        $result = $this->discoveryService->applyProposalToDraft($proposal, $draft, $this->adminUser);
        $this->assertTrue($result['success']);

        $newField = $draft->fields()->where('field_key', 'linkedin_profile_url')->first();
        $this->assertNotNull($newField);
        $this->assertSame('url', $newField->field_type);
        $this->assertSame('Profil LinkedIn', $newField->label);
        $this->assertFalse($newField->is_system);
    }
}
