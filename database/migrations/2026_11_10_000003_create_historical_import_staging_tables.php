<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('historical_import_batches')) {
            Schema::create('historical_import_batches', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->string('source_type', 40); // xlsx, csv, google_sheets_readonly
                $table->string('source_filename', 255)->nullable();
                $table->string('source_identifier', 255)->nullable(); // sheet_id or file identifier
                $table->string('source_tab_name', 100)->nullable();
                $table->string('source_file_hash', 64)->nullable();
                $table->unsignedInteger('total_rows_detected')->default(0);
                $table->unsignedInteger('total_rows_imported')->default(0);
                $table->unsignedInteger('total_rows_skipped')->default(0);
                $table->unsignedInteger('total_rows_failed')->default(0);
                $table->unsignedSmallInteger('target_year')->nullable();
                $table->json('column_mapping_config')->nullable();
                $table->string('status', 40)->default('staged'); // staged, verified, committed, failed
                $table->timestamp('committed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'target_year']);
            });
        }

        if (!Schema::hasTable('historical_import_rows')) {
            Schema::create('historical_import_rows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('historical_import_batches')->cascadeOnDelete();
                $table->unsignedInteger('row_index');
                $table->string('event_fingerprint', 64)->index(); // SHA-256 event-level idempotency
                $table->json('raw_data');
                $table->json('mapped_data')->nullable();
                $table->dateTime('submission_timestamp')->nullable()->index();
                $table->unsignedSmallInteger('target_year')->nullable()->index();
                $table->string('lifecycle_status', 30)->default('waiting'); // waiting, accepted, active, completed, exited, rejected
                $table->string('validation_status', 30)->default('pending'); // pending, valid, warning, error
                $table->json('validation_messages')->nullable();
                $table->boolean('is_duplicate_event')->default(false);
                $table->boolean('is_existing_applicant')->default(false);
                $table->unsignedBigInteger('internship_registration_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['batch_id', 'row_index'], 'uniq_batch_row');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historical_import_rows');
        Schema::dropIfExists('historical_import_batches');
    }
};
