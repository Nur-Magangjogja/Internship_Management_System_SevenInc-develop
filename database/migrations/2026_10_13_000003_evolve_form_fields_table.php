<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * S17.18D.1: Evolve existing form_fields table to support versioned form_schemas.
     */
    public function up(): void
    {
        if (Schema::hasTable('form_fields')) {
            Schema::table('form_fields', function (Blueprint $table) {
                if (!Schema::hasColumn('form_fields', 'form_schema_id')) {
                    $table->foreignId('form_schema_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('form_schemas')
                        ->onDelete('cascade');
                }

                if (!Schema::hasColumn('form_fields', 'is_archived')) {
                    $table->boolean('is_archived')->default(false)->after('is_active');
                }

                if (!Schema::hasColumn('form_fields', 'validation_rules')) {
                    $table->json('validation_rules')->nullable()->after('options');
                }

                if (!Schema::hasColumn('form_fields', 'deleted_at')) {
                    $table->softDeletes()->after('updated_at');
                }
            });

            // Safely drop the old unique index on field_key if it exists and add composite unique
            $indexes = collect(DB::select("SHOW INDEXES FROM form_fields"))->pluck('Key_name')->unique();
            if ($indexes->contains('form_fields_field_key_unique')) {
                Schema::table('form_fields', function (Blueprint $table) {
                    $table->dropUnique('form_fields_field_key_unique');
                });
            }

            $updatedIndexes = collect(DB::select("SHOW INDEXES FROM form_fields"))->pluck('Key_name')->unique();
            if (!$updatedIndexes->contains('form_fields_form_schema_id_field_key_unique')) {
                Schema::table('form_fields', function (Blueprint $table) {
                    $table->unique(['form_schema_id', 'field_key']);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('form_fields')) {
            // Pre-flight data safety check:
            // Jika data berversi sudah memiliki duplicate field_key di berbagai schema,
            // pemulihan global unique constraint pada field_key pasti melanggar integritas (Error 1062).
            // Tolak rollback sebelum mengubah DDL apapun agar state database tidak korup sebagian.
            $duplicateKeys = DB::table('form_fields')
                ->select('field_key', DB::raw('COUNT(*) as total'))
                ->groupBy('field_key')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('field_key')
                ->all();

            if (!empty($duplicateKeys)) {
                $keysStr = implode(', ', $duplicateKeys);
                throw new \DomainException(
                    "ROLLBACK_ABORTED_DATA_HAZARD: Tidak dapat me-rollback migration 000003 karena terdapat duplikasi field_key pada beberapa skema versi form ({$keysStr}). Pemulihan unique index global akan menyebabkan data loss atau kegagalan DDL. Gunakan strategi fix-forward atau pemulihan backup database resmi."
                );
            }

            // 1. Drop foreign key constraint first so MySQL/InnoDB does not block dropping composite index (Fix Error 1553)
            if (Schema::hasColumn('form_fields', 'form_schema_id')) {
                $foreignKeys = collect(DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.TABLE_CONSTRAINTS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'form_fields' 
                      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                "))->pluck('CONSTRAINT_NAME');

                if ($foreignKeys->contains('form_fields_form_schema_id_foreign')) {
                    Schema::table('form_fields', function (Blueprint $table) {
                        $table->dropForeign(['form_schema_id']);
                    });
                }
            }

            // 2. Drop composite unique index once foreign key is removed
            $indexes = collect(DB::select("SHOW INDEXES FROM form_fields"))->pluck('Key_name')->unique();
            if ($indexes->contains('form_fields_form_schema_id_field_key_unique')) {
                Schema::table('form_fields', function (Blueprint $table) {
                    $table->dropUnique('form_fields_form_schema_id_field_key_unique');
                });
            }

            // 3. Restore original unique constraint on field_key as in legacy schema
            $indexesAfter = collect(DB::select("SHOW INDEXES FROM form_fields"))->pluck('Key_name')->unique();
            if (!$indexesAfter->contains('form_fields_field_key_unique')) {
                Schema::table('form_fields', function (Blueprint $table) {
                    $table->unique('field_key');
                });
            }

            // 4. Drop evolved columns safely
            Schema::table('form_fields', function (Blueprint $table) {
                if (Schema::hasColumn('form_fields', 'form_schema_id')) {
                    $table->dropColumn('form_schema_id');
                }
                if (Schema::hasColumn('form_fields', 'is_archived')) {
                    $table->dropColumn('is_archived');
                }
                if (Schema::hasColumn('form_fields', 'validation_rules')) {
                    $table->dropColumn('validation_rules');
                }
                if (Schema::hasColumn('form_fields', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }
};
