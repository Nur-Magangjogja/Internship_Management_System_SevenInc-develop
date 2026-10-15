<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRollbackDefectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Test isolated rollback (down()) of migration 000003 without MySQL Error 1553.
     */
    public function test_migration_000003_down_drops_foreign_key_before_composite_index(): void
    {
        // Pastikan tabel form_schemas dan form_fields sudah ada
        $this->assertTrue(Schema::hasTable('form_schemas'));
        $this->assertTrue(Schema::hasTable('form_fields'));
        $this->assertTrue(Schema::hasColumn('form_fields', 'form_schema_id'));

        $migrationFile = database_path('migrations/2026_10_13_000003_evolve_form_fields_table.php');
        $migration = require $migrationFile;

        // Eksekusi DOWN()
        // Pada kode lama, langkah ini akan melempar SQLSTATE[HY000]: General error: 1553
        $migration->down();

        // Verifikasi hasil rollback:
        // a. Kolom form_schema_id sudah terhapus
        $this->assertFalse(Schema::hasColumn('form_fields', 'form_schema_id'));
        $this->assertFalse(Schema::hasColumn('form_fields', 'is_archived'));

        // b. Composite unique index sudah terhapus
        $indexes = collect(DB::select("SHOW INDEXES FROM form_fields"))->pluck('Key_name')->unique();
        $this->assertFalse($indexes->contains('form_fields_form_schema_id_field_key_unique'));

        // c. Original unique index pada field_key berhasil dipulihkan
        $this->assertTrue($indexes->contains('form_fields_field_key_unique'));

        // d. Eksekusi UP() kembali berhasil (idempotent re-apply)
        $migration->up();

        $this->assertTrue(Schema::hasColumn('form_fields', 'form_schema_id'));
        $indexesReapplied = collect(DB::select("SHOW INDEXES FROM form_fields"))->pluck('Key_name')->unique();
        $this->assertTrue($indexesReapplied->contains('form_fields_form_schema_id_field_key_unique'));
    }

    /**
     * 2. Test kompatibilitas model FormField setelah migrasi.
     */
    public function test_form_field_model_compatibility(): void
    {
        $admin = User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin_formfield_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $schema = FormSchema::create([
            'version_code' => 'v1.0.0-test',
            'year'         => 2026,
            'title'        => 'Test Schema',
            'status'       => 'draft',
            'is_active'    => false,
            'created_by'   => $admin->id,
        ]);

        $field = FormField::create([
            'form_schema_id'   => $schema->id,
            'field_key'        => 'custom_test_note',
            'field_type'       => 'textarea',
            'label'            => 'Catatan Tambahan',
            'is_active'        => true,
            'is_required'      => false,
            'is_archived'      => false,
            'order'            => 1,
            'validation_rules' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertNotNull($field->id);
        $this->assertEquals($schema->id, $field->form_schema_id);
        $this->assertEquals('custom_test_note', $field->field_key);

        // Verifikasi relasi schema
        $this->assertInstanceOf(FormSchema::class, $field->schema);
        $this->assertEquals($schema->id, $field->schema->id);

        // Verifikasi soft deletes
        $field->delete();
        $this->assertSoftDeleted('form_fields', ['id' => $field->id]);
        $this->assertNotNull($field->fresh()?->deleted_at ?? FormField::withTrashed()->find($field->id)->deleted_at);
    }

    /**
     * 3. Test rollback aborted fail-closed when multiple schemas contain duplicate field_key entries.
     */
    public function test_migration_000003_down_aborts_safely_when_versioned_duplicates_exist(): void
    {
        $admin = User::create([
            'name'     => 'Admin Version Test',
            'email'    => 'admin_version_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $schema1 = FormSchema::create([
            'version_code' => 'v1.0.0-dup',
            'year'         => 2026,
            'title'        => 'Schema 1',
            'status'       => 'published',
            'is_active'    => true,
            'created_by'   => $admin->id,
        ]);

        $schema2 = FormSchema::create([
            'version_code' => 'v2.0.0-dup',
            'year'         => 2026,
            'title'        => 'Schema 2',
            'status'       => 'draft',
            'is_active'    => false,
            'created_by'   => $admin->id,
        ]);

        // Kedua schema memiliki field_key yang sama: 'shared_portfolio'
        FormField::create([
            'form_schema_id' => $schema1->id,
            'field_key'      => 'shared_portfolio',
            'field_type'     => 'url',
            'label'          => 'Portfolio 1',
            'is_active'      => true,
            'is_required'    => false,
            'order'          => 1,
        ]);

        FormField::create([
            'form_schema_id' => $schema2->id,
            'field_key'      => 'shared_portfolio',
            'field_type'     => 'url',
            'label'          => 'Portfolio 2',
            'is_active'      => true,
            'is_required'    => false,
            'order'          => 1,
        ]);

        $migrationFile = database_path('migrations/2026_10_13_000003_evolve_form_fields_table.php');
        $migration = require $migrationFile;

        // Rollback WAJIB melempar \DomainException sebelum mengubah DDL apapun
        try {
            $migration->down();
            $this->fail('Expected \DomainException was not thrown on duplicate field_key rollback hazard.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ROLLBACK_ABORTED_DATA_HAZARD', $e->getMessage());
            $this->assertStringContainsString('shared_portfolio', $e->getMessage());
        }

        // Verifikasi bahwa DDL tetap utuh dan tidak rusak (foreign key, composite index, kolom tetap ada)
        $this->assertTrue(Schema::hasColumn('form_fields', 'form_schema_id'));
        $indexes = collect(DB::select("SHOW INDEXES FROM form_fields"))->pluck('Key_name')->unique();
        $this->assertTrue($indexes->contains('form_fields_form_schema_id_field_key_unique'));
    }
}
