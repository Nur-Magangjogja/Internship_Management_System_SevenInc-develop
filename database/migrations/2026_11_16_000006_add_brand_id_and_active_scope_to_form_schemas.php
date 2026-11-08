<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('form_schemas')) {
            Schema::table('form_schemas', function (Blueprint $table) {
                if (!Schema::hasColumn('form_schemas', 'brand_id')) {
                    $table->foreignId('brand_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('brands')
                        ->restrictOnDelete();
                }

                if (!Schema::hasColumn('form_schemas', 'source_schema_id')) {
                    $table->foreignId('source_schema_id')
                        ->nullable()
                        ->after('brand_id')
                        ->constrained('form_schemas')
                        ->nullOnDelete();
                }
            });

            if (!Schema::hasColumn('form_schemas', 'active_brand_scope')) {
                if (DB::getDriverName() === 'mysql') {
                    DB::statement("ALTER TABLE form_schemas ADD COLUMN active_brand_scope VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN status = 'published' AND is_active = 1 THEN COALESCE(CAST(brand_id AS CHAR), 'GLOBAL') ELSE NULL END) VIRTUAL");
                } else {
                    Schema::table('form_schemas', function (Blueprint $table) {
                        $table->string('active_brand_scope', 50)->nullable();
                    });
                }

                Schema::table('form_schemas', function (Blueprint $table) {
                    $table->unique(['year', 'active_brand_scope'], 'form_schemas_unique_active_scope_idx');
                    $table->index(['year', 'brand_id', 'status', 'is_active'], 'form_schemas_brand_year_status_idx');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('form_schemas')) {
            Schema::table('form_schemas', function (Blueprint $table) {
                if (Schema::hasColumn('form_schemas', 'active_brand_scope')) {
                    $table->dropUnique('form_schemas_unique_active_scope_idx');
                    $table->dropIndex('form_schemas_brand_year_status_idx');
                    $table->dropColumn('active_brand_scope');
                }

                if (Schema::hasColumn('form_schemas', 'source_schema_id')) {
                    $table->dropForeign(['source_schema_id']);
                    $table->dropColumn('source_schema_id');
                }

                if (Schema::hasColumn('form_schemas', 'brand_id')) {
                    $table->dropForeign(['brand_id']);
                    $table->dropColumn('brand_id');
                }
            });
        }
    }
};
