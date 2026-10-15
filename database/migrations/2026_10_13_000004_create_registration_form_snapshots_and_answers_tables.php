<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * S17.18D.1: Create immutable registration_form_snapshots and mutable registration_custom_answers.
     */
    public function up(): void
    {
        if (!Schema::hasTable('registration_form_snapshots')) {
            Schema::create('registration_form_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('registration_id')
                    ->constrained('internship_registrations')
                    ->onDelete('cascade');
                $table->foreignId('form_schema_id')
                    ->nullable()
                    ->constrained('form_schemas')
                    ->nullOnDelete();
                $table->string('form_schema_version', 50);
                $table->string('schema_hash', 64)->nullable();
                $table->json('schema_snapshot');
                $table->timestamp('submitted_at');
                $table->timestamps();

                $table->unique('registration_id');
                $table->index('form_schema_version');
            });
        }

        if (!Schema::hasTable('registration_custom_answers')) {
            Schema::create('registration_custom_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('registration_id')
                    ->constrained('internship_registrations')
                    ->onDelete('cascade');
                $table->string('field_key', 100);
                $table->text('answer_value')->nullable();
                $table->string('answer_label')->nullable();
                $table->timestamps();

                $table->unique(['registration_id', 'field_key']);
                $table->index('field_key');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Fail-closed data-preservation guard (S17.18G.4R Fix 1)
        if (Schema::hasTable('registration_custom_answers') && DB::table('registration_custom_answers')->exists()) {
            $count = DB::table('registration_custom_answers')->count();
            throw new \DomainException(
                "ROLLBACK_ABORTED_DATA_HAZARD: Tabel 'registration_custom_answers' memuat {$count} data jawaban pendaftaran historis. Rollback otomatis dibatalkan untuk mencegah kehilangan data pendaftar. Gunakan strategi forward-fix."
            );
        }

        if (Schema::hasTable('registration_form_snapshots') && DB::table('registration_form_snapshots')->exists()) {
            $count = DB::table('registration_form_snapshots')->count();
            throw new \DomainException(
                "ROLLBACK_ABORTED_DATA_HAZARD: Tabel 'registration_form_snapshots' memuat {$count} snapshot formulir historis. Rollback otomatis dibatalkan untuk mencegah kehilangan data pendaftar. Gunakan strategi forward-fix."
            );
        }

        Schema::dropIfExists('registration_custom_answers');
        Schema::dropIfExists('registration_form_snapshots');
    }
};

