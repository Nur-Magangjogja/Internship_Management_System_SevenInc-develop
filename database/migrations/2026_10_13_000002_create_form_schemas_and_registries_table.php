<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * S17.18D.1: Create versioned form_schemas and permanent form_field_registries.
     */
    public function up(): void
    {
        if (!Schema::hasTable('form_schemas')) {
            Schema::create('form_schemas', function (Blueprint $table) {
                $table->id();
                $table->string('version_code', 50)->unique();
                $table->smallInteger('year')->default(2026);
                $table->string('title')->default('Formulir Pendaftaran Magang 2026');
                $table->string('status', 30)->default('draft'); // draft, published, archived
                $table->string('schema_hash', 64)->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['year', 'status', 'is_active']);
            });
        }

        if (!Schema::hasTable('form_field_registries')) {
            Schema::create('form_field_registries', function (Blueprint $table) {
                $table->id();
                $table->string('field_key', 100)->unique();
                $table->string('field_type', 50);
                $table->string('canonical_label');
                $table->string('first_published_version', 50)->nullable();
                $table->boolean('is_locked')->default(false);
                $table->json('allowed_options')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Fail-closed data-preservation guard (S17.18G.4R Fix 1)
        if (Schema::hasTable('form_schemas') && DB::table('form_schemas')->exists()) {
            $count = DB::table('form_schemas')->count();
            throw new \DomainException(
                "ROLLBACK_ABORTED_DATA_HAZARD: Tabel 'form_schemas' memuat {$count} definisi skema formulir. Rollback otomatis dibatalkan untuk mencegah kehilangan konfigurasi formulir. Gunakan strategi forward-fix."
            );
        }

        if (Schema::hasTable('form_field_registries') && DB::table('form_field_registries')->exists()) {
            $count = DB::table('form_field_registries')->count();
            throw new \DomainException(
                "ROLLBACK_ABORTED_DATA_HAZARD: Tabel 'form_field_registries' memuat {$count} registri field formulir. Rollback otomatis dibatalkan untuk mencegah kehilangan identitas field historis. Gunakan strategi forward-fix."
            );
        }

        Schema::dropIfExists('form_field_registries');
        Schema::dropIfExists('form_schemas');
    }
};
