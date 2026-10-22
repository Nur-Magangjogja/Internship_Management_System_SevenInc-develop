<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationSpreadsheetMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadonlyViewInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $pemagangUser;
    protected Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Safety',
            'email'     => 'admin.safety@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagangUser = User::create([
            'name'      => 'Pemagang Readonly',
            'email'     => 'pemagang.readonly@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas Gadjah Mada',
            'is_active' => true,
        ]);
    }

    protected function createInternshipRegistration(array $overrides = []): InternshipRegistration
    {
        $inst = $this->institution;
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $inst->id, 'name' => 'Fakultas Teknik']
        );
        $program = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknik Informatika']
        );

        $createdAt = $overrides['created_at'] ?? Carbon::parse('2026-03-15 10:00:00');
        unset($overrides['created_at']);

        $defaults = [
            'user_id'          => $this->pemagangUser->id,
            'fullname'         => 'Test Pemagang ' . uniqid(),
            'institution_id'   => $inst->id,
            'faculty_id'       => $faculty->id,
            'study_program_id' => $program->id,
            'internship_status'=> 'new',
        ];

        $reg = InternshipRegistration::create(array_merge($defaults, $overrides));
        $reg->created_at = $createdAt;
        $reg->saveQuietly();

        return $reg;
    }

    /**
     * Helper to verify no mutating queries (INSERT, UPDATE, DELETE, ALTER, DROP, CREATE TABLE)
     * were executed during a callback.
     */
    protected function assertNoMutatingQueries(callable $callback): void
    {
        $executedQueries = [];
        DB::listen(function ($query) use (&$executedQueries) {
            $executedQueries[] = $query->sql;
        });

        $callback();

        $mutatingQueries = [];
        foreach ($executedQueries as $sql) {
            $trimmed = ltrim($sql);
            if (preg_match('/^(insert|update|delete|alter|drop|create\s+table)/i', $trimmed)) {
                $mutatingQueries[] = $sql;
            }
        }

        $this->assertEmpty(
            $mutatingQueries,
            "Mutating query detected during read-only operation:\n" . implode("\n", $mutatingQueries)
        );
    }

    /**
     * Test P1.1: GET index is pure read-only and does not mutate database or run DDL.
     */
    public function test_index_is_pure_read_only_and_does_not_mutate_db_or_run_ddl(): void
    {
        $initialCount = SpreadsheetConnection::count();

        $this->assertNoMutatingQueries(function () {
            $response = $this->actingAs($this->adminUser)
                ->get(route('admin.spreadsheet-connections.index'));

            $response->assertStatus(200);
            $response->assertViewIs('admin.spreadsheet-connections.index');
        });

        $this->assertSame($initialCount, SpreadsheetConnection::count(), 'Connection count changed during GET index');
    }

    /**
     * Test P1.1: GET show is pure read-only and does not rebuild schema or write remote.
     */
    public function test_show_is_pure_read_only_and_does_not_write_remote_or_mutate_db(): void
    {
        $connection = SpreadsheetConnection::create([
            'connection_key'           => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name'             => 'Spreadsheet Pendaftaran 2026',
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/test1234/edit',
            'spreadsheet_id'           => 'test1234',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST/exec',
            'is_enabled'               => true,
            'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        $schemaVersion = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $connection->id,
            'year'                      => 2026,
            'sheet_tab_name'            => '2026',
            'schema_version'            => 'v1',
            'is_active'                 => true,
        ]);

        $mapping = SpreadsheetColumnMapping::create([
            'public_uuid'                   => (string) Str::uuid(),
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'column_order'                  => 1,
            'sheet_header'                  => 'Nama Lengkap',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'fullname',
            'data_type'                     => 'string',
            'direction'                     => 'outbound',
            'is_required'                   => true,
        ]);

        $versionCountBefore = SpreadsheetSchemaVersion::count();
        $mappingCountBefore = SpreadsheetColumnMapping::count();
        $mappingIdBefore = $mapping->id;

        $this->assertNoMutatingQueries(function () use ($connection) {
            $response = $this->actingAs($this->adminUser)
                ->get(route('admin.spreadsheet-connections.show', $connection));

            $response->assertStatus(200);
            $response->assertViewIs('admin.spreadsheet-connections.show');
        });

        $this->assertSame($versionCountBefore, SpreadsheetSchemaVersion::count());
        $this->assertSame($mappingCountBefore, SpreadsheetColumnMapping::count());
        $this->assertSame($mappingIdBefore, SpreadsheetColumnMapping::first()->id);
    }

    /**
     * Test P1.1: GET edit is pure read-only and does not run DDL.
     */
    public function test_edit_is_pure_read_only_and_does_not_run_ddl(): void
    {
        $connection = SpreadsheetConnection::create([
            'connection_key'           => SpreadsheetConnection::KEY_FOLLOWUP,
            'display_name'             => 'Spreadsheet Follow-up',
            'is_enabled'               => false,
            'connection_status'        => SpreadsheetConnection::STATUS_UNTESTED,
        ]);

        $this->assertNoMutatingQueries(function () use ($connection) {
            $response = $this->actingAs($this->adminUser)
                ->get(route('admin.spreadsheet-connections.edit', $connection));

            $response->assertStatus(200);
            $response->assertViewIs('admin.spreadsheet-connections.edit');
        });
    }

    /**
     * Test P1.1: getSchemaForRegistration is pure query and does not alter schema or column mappings.
     */
    public function test_get_schema_for_registration_is_pure_query_and_does_not_alter_mappings(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $connection = SpreadsheetConnection::create([
            'connection_key'    => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name'      => 'Spreadsheet Pendaftaran 2026',
            'is_enabled'        => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        $schemaVersion = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $connection->id,
            'year'                      => 2026,
            'sheet_tab_name'            => '2026',
            'schema_version'            => 'v1',
            'is_active'                 => true,
        ]);

        $col1 = SpreadsheetColumnMapping::create([
            'public_uuid'                   => (string) Str::uuid(),
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'column_order'                  => 1,
            'sheet_header'                  => 'Nama Lengkap',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'fullname',
            'data_type'                     => 'string',
            'direction'                     => 'outbound',
            'is_required'                   => true,
        ]);

        $col2 = SpreadsheetColumnMapping::create([
            'public_uuid'                   => (string) Str::uuid(),
            'spreadsheet_schema_version_id' => $schemaVersion->id,
            'column_order'                  => 2,
            'sheet_header'                  => 'Email',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'email',
            'data_type'                     => 'string',
            'direction'                     => 'outbound',
            'is_required'                   => true,
        ]);

        $reg = $this->createInternshipRegistration([
            'created_at' => Carbon::parse('2026-05-10 08:30:00'),
        ]);

        $versionCountBefore = SpreadsheetSchemaVersion::count();
        $mappingCountBefore = SpreadsheetColumnMapping::count();
        $originalColIds = [$col1->id, $col2->id];
        $originalColUuids = [$col1->public_uuid, $col2->public_uuid];

        // Call getSchemaForRegistration 5 times repeatedly
        for ($i = 0; $i < 5; $i++) {
            $schema = $service->getSchemaForRegistration($reg);

            $this->assertNotNull($schema);
            $this->assertSame($schemaVersion->id, $schema->id);
            $this->assertSame(2026, $schema->year);
            $this->assertCount(2, $schema->columnMappings);
        }

        // Assert schema versions and column mappings counts are completely untouched
        $this->assertSame($versionCountBefore, SpreadsheetSchemaVersion::count());
        $this->assertSame($mappingCountBefore, SpreadsheetColumnMapping::count());

        $currentCols = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $schemaVersion->id)
            ->orderBy('column_order')
            ->get();

        $this->assertSame($originalColIds, $currentCols->pluck('id')->all(), 'Mapping IDs changed! Soft or hard delete occurred.');
        $this->assertSame($originalColUuids, $currentCols->pluck('public_uuid')->all(), 'Mapping UUIDs changed! Re-creation occurred.');
    }

    /**
     * Test P1.1: getSchemaForRegistration for historical year returns historical schema, not current year.
     */
    public function test_get_schema_for_registration_historical_year_returns_historical_schema(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $connection = SpreadsheetConnection::create([
            'connection_key'    => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name'      => 'Spreadsheet Pendaftaran',
            'is_enabled'        => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        $schema2024 = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $connection->id,
            'year'                      => 2024,
            'sheet_tab_name'            => '2024',
            'schema_version'            => 'v1',
            'is_active'                 => false,
        ]);

        $schema2026 = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $connection->id,
            'year'                      => 2026,
            'sheet_tab_name'            => '2026',
            'schema_version'            => 'v1',
            'is_active'                 => true,
        ]);

        $reg2024 = $this->createInternshipRegistration([
            'created_at' => Carbon::parse('2024-08-20 12:00:00'),
        ]);

        $schema = $service->getSchemaForRegistration($reg2024);

        $this->assertNotNull($schema);
        $this->assertSame($schema2024->id, $schema->id);
        $this->assertSame(2024, $schema->year);
    }

    /**
     * Test P1.1: getSchemaForRegistration returns null when no schema exists for year.
     */
    public function test_get_schema_for_registration_returns_null_when_no_schema_for_year(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $connection = SpreadsheetConnection::create([
            'connection_key'    => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name'      => 'Spreadsheet Pendaftaran',
            'is_enabled'        => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        $reg2020 = $this->createInternshipRegistration([
            'created_at' => Carbon::parse('2020-01-01 00:00:00'),
        ]);

        $schema = $service->getSchemaForRegistration($reg2020);

        $this->assertNull($schema);
    }
}
