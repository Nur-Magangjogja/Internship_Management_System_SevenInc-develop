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
        // 1. spreadsheet_search_index_runs
        if (!Schema::hasTable('spreadsheet_search_index_runs')) {
            Schema::create('spreadsheet_search_index_runs', function (Blueprint $table) {
                $table->id();
                $table->char('public_uuid', 36)->unique();
                $table->unsignedBigInteger('spreadsheet_connection_id');
                $table->bigInteger('sheet_id')->nullable();
                $table->string('tab_name', 150);
                $table->string('table_identifier', 100)->nullable();
                $table->unsignedBigInteger('mapping_version_id')->nullable();
                $table->unsignedInteger('index_generation')->default(1);
                $table->string('status', 32)->default('pending'); // pending, processing, completed, failed, cancelled
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('processed_rows')->default(0);
                $table->unsignedInteger('indexed_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->unsignedInteger('current_page')->default(1);
                $table->unsignedInteger('page_size')->default(200);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->foreign('spreadsheet_connection_id')
                    ->references('id')
                    ->on('spreadsheet_connections')
                    ->cascadeOnDelete();

                $table->index(['spreadsheet_connection_id', 'tab_name', 'status'], 'idx_run_conn_tab_status');
            });
        }

        // 2. spreadsheet_search_documents
        if (!Schema::hasTable('spreadsheet_search_documents')) {
            Schema::create('spreadsheet_search_documents', function (Blueprint $table) {
                $table->id();
                $table->char('public_uuid', 36)->unique();
                $table->unsignedBigInteger('brand_id');
                $table->unsignedBigInteger('spreadsheet_connection_id');
                $table->bigInteger('sheet_id')->nullable();
                $table->string('tab_name', 150);
                $table->string('table_identifier', 100)->nullable();
                $table->unsignedBigInteger('row_sync_id')->nullable();
                $table->unsignedBigInteger('local_registration_id')->nullable();
                $table->string('source_record_key', 191)->nullable(); // SERTIF_SYNC_ID atau external ID
                $table->unsignedInteger('source_row_number')->nullable();
                $table->char('source_row_fingerprint', 64);
                $table->string('identity_type', 32)->default('provisional'); // stable, provisional, ambiguous
                $table->unsignedBigInteger('mapping_version_id')->nullable();
                $table->string('normalized_name', 255)->nullable();
                $table->string('normalized_email', 255)->nullable();
                $table->string('normalized_phone', 64)->nullable();
                $table->string('raw_phone', 64)->nullable();
                $table->mediumText('searchable_text');
                $table->json('searchable_payload');
                $table->timestamp('indexed_at')->useCurrent();
                $table->timestamp('source_updated_at')->nullable();
                $table->unsignedInteger('index_generation')->default(1);
                $table->timestamps();

                $table->foreign('brand_id')
                    ->references('id')
                    ->on('brands')
                    ->cascadeOnDelete();

                $table->foreign('spreadsheet_connection_id')
                    ->references('id')
                    ->on('spreadsheet_connections')
                    ->cascadeOnDelete();

                $table->foreign('local_registration_id')
                    ->references('id')
                    ->on('internship_registrations')
                    ->nullOnDelete();

                $table->foreign('row_sync_id')
                    ->references('id')
                    ->on('spreadsheet_row_syncs')
                    ->nullOnDelete();

                $table->index(['brand_id', 'spreadsheet_connection_id', 'index_generation'], 'idx_search_brand_conn_gen');
                $table->index(['spreadsheet_connection_id', 'tab_name', 'index_generation'], 'idx_search_conn_tab_gen');
                $table->index('source_record_key', 'idx_search_source_key');
                $table->index('normalized_name', 'idx_search_norm_name');
                $table->index('normalized_email', 'idx_search_norm_email');
                $table->index('normalized_phone', 'idx_search_norm_phone');
                $table->index('source_row_fingerprint', 'idx_search_row_fingerprint');
                $table->index('identity_type', 'idx_search_identity_type');
            });

            // MySQL specific FULLTEXT index
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE `spreadsheet_search_documents` ADD FULLTEXT KEY `ft_searchable_text` (`searchable_text`)');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_search_documents');
        Schema::dropIfExists('spreadsheet_search_index_runs');
    }
};
