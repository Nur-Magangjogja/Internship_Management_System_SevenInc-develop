<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NonDestructiveSchemaMappingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected SpreadsheetConnection $connection;
    protected RegistrationSpreadsheetMappingService $mappingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Mapping Test',
            'email'     => 'admin.mapping.test@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Google Sheets Pendaftaran 2026',
                'spreadsheet_id'           => 'mock-spreadsheet-mapping-2026',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-mapping-2026/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_MAPPING_GATEWAY/exec',
            ]
        );

        $this->mappingService = app(RegistrationSpreadsheetMappingService::class);
    }

    /**
     * Test: Idempotent rerun of syncSpreadsheetSchemaWithFormSchema preserves UUIDs and mapping records without deletion.
     */
    public function test_reconcile_idempotent_preserves_uuids_and_does_not_hard_delete(): void
    {
        $schemaVersion1 = $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026);
        $this->assertNotNull($schemaVersion1);

        $mappingsBefore = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $schemaVersion1->id)
            ->orderBy('column_order')
            ->get();
        $countBefore = $mappingsBefore->count();
        $idsBefore = $mappingsBefore->pluck('id')->all();
        $uuidsBefore = $mappingsBefore->pluck('public_uuid')->all();

        // Run sync again
        $schemaVersion2 = $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026);
        $this->assertSame($schemaVersion1->id, $schemaVersion2->id);

        $mappingsAfter = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $schemaVersion1->id)
            ->orderBy('column_order')
            ->get();
        $countAfter = $mappingsAfter->count();
        $idsAfter = $mappingsAfter->pluck('id')->all();
        $uuidsAfter = $mappingsAfter->pluck('public_uuid')->all();

        $this->assertSame($countBefore, $countAfter);
        $this->assertSame($idsBefore, $idsAfter, 'Mapping IDs should be preserved without deletion.');
        $this->assertSame($uuidsBefore, $uuidsAfter, 'Mapping UUIDs should be preserved without change.');
    }

    /**
     * Test: Swapping and reordering columns does not violate unique constraints and completes cleanly.
     */
    public function test_swap_column_order_does_not_fail_unique_constraints(): void
    {
        $service = app(FormBuilderService::class);
        $draft = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);

        // Add two custom fields
        $fieldA = FormField::create([
            'form_schema_id'   => $draft->id,
            'field_key'        => 'custom_field_alpha',
            'field_type'       => 'string',
            'label'            => 'Custom Field Alpha',
            'is_active'        => true,
            'is_archived'      => false,
            'is_required'      => false,
            'is_system'        => false,
            'sort_order'       => 100,
        ]);

        $fieldB = FormField::create([
            'form_schema_id'   => $draft->id,
            'field_key'        => 'custom_field_beta',
            'field_type'       => 'string',
            'label'            => 'Custom Field Beta',
            'is_active'        => true,
            'is_archived'      => false,
            'is_required'      => false,
            'is_system'        => false,
            'sort_order'       => 101,
        ]);

        // First reconcile: Alpha before Beta
        $version = $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026, $draft);
        $mapA = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $version->id)->where('target_field', 'custom_field_alpha')->first();
        $mapB = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $version->id)->where('target_field', 'custom_field_beta')->first();
        $this->assertTrue($mapA->column_order < $mapB->column_order);

        $uuidA = $mapA->public_uuid;
        $uuidB = $mapB->public_uuid;

        // Now swap their sort orders: Beta before Alpha
        $fieldA->update(['sort_order' => 101]);
        $fieldB->update(['sort_order' => 100]);

        // Second reconcile with swapped order
        $versionSwapped = $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026, $draft);
        $mapASwapped = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $version->id)->where('target_field', 'custom_field_alpha')->first();
        $mapBSwapped = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $version->id)->where('target_field', 'custom_field_beta')->first();

        // Verify order is successfully inverted without SQL unique constraint failure
        $this->assertTrue($mapBSwapped->column_order < $mapASwapped->column_order);
        $this->assertSame($uuidA, $mapASwapped->public_uuid, 'UUID Alpha preserved');
        $this->assertSame($uuidB, $mapBSwapped->public_uuid, 'UUID Beta preserved');
    }

    /**
     * Test: Removed custom fields are not hard-deleted; marked unmapped.
     */
    public function test_removed_custom_fields_are_marked_unmapped_not_hard_deleted(): void
    {
        $service = app(FormBuilderService::class);
        $draft = $service->ensureYearFormInitialized(2026, $this->adminUser, forceInTests: true);

        $field = FormField::create([
            'form_schema_id'   => $draft->id,
            'field_key'        => 'custom_field_temporary',
            'field_type'       => 'string',
            'label'            => 'Custom Field Temporary',
            'is_active'        => true,
            'is_archived'      => false,
            'is_required'      => false,
            'is_system'        => false,
            'sort_order'       => 105,
        ]);

        $version = $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026, $draft);
        $mapping = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $version->id)
            ->where('target_field', 'custom_field_temporary')
            ->first();
        $this->assertNotNull($mapping);
        $this->assertSame('active', $mapping->mapping_status);

        // 1. Deactivate the field (is_active = false)
        // Per requirement: deactivating a field must NOT delete the column or mark it unmapped;
        // the column remains active in the spreadsheet ("dibiarkan saja").
        $field->update(['is_active' => false]);
        $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026, $draft);
        $mapping->refresh();
        $this->assertSame('active', $mapping->mapping_status, 'Deactivated custom field column remains active in spreadsheet.');

        // 2. Archive the field (is_archived = true)
        // Archiving the field marks it unmapped without hard-deleting the record.
        $field->update(['is_archived' => true]);
        $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026, $draft);
        $mapping->refresh();
        $this->assertSame('unmapped', $mapping->mapping_status, 'Archived custom field mapping is marked unmapped.');
    }

    /**
     * Test: Older schema versions are immutable and not affected by new publications.
     */
    public function test_older_schema_version_mappings_are_not_mutated_by_new_publication(): void
    {
        $schema2025 = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->connection->id,
            'year'                      => 2025,
            'sheet_tab_name'            => '2025',
            'schema_version'            => 'v1',
            'is_active'                 => false,
        ]);

        $col2025 = SpreadsheetColumnMapping::create([
            'public_uuid'                   => (string) Str::uuid(),
            'spreadsheet_schema_version_id' => $schema2025->id,
            'column_order'                  => 1,
            'sheet_header'                  => '2025 Old Header',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'fullname',
            'data_type'                     => 'string',
            'direction'                     => 'outbound',
            'is_required'                   => true,
        ]);

        // Sync schema for 2026
        $this->mappingService->syncSpreadsheetSchemaWithFormSchema(2026);

        // Assert 2025 version and its mappings are completely untouched
        $col2025->refresh();
        $this->assertSame('2025 Old Header', $col2025->sheet_header);
        $this->assertSame(1, $col2025->column_order);
    }
}
