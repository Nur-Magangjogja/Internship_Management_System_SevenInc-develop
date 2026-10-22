<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Master Plan V1 Fase 4: Controlled Inbound Row Staging and Change Reconciliation.
     */
    public function up(): void
    {
        if (!Schema::hasTable('sheet_inbound_change_staging')) {
            Schema::create('sheet_inbound_change_staging', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->foreignId('spreadsheet_connection_id')
                    ->constrained('spreadsheet_connections')
                    ->restrictOnDelete();
                $table->string('tab_name', 100);
                $table->unsignedBigInteger('sheet_id')->default(0);
                $table->unsignedInteger('row_index')->nullable()->comment('Informational row number in Google Sheet');
                $table->string('sync_id', 100)->index()->comment('SERTIF_SYNC_ID canonical key');
                $table->foreignId('registration_id')
                    ->nullable()
                    ->constrained('internship_registrations')
                    ->nullOnDelete();
                $table->string('column_identity', 100);
                $table->string('header_name', 255);
                $table->string('field_key', 100)->nullable();
                $table->string('field_classification', 50)->default('custom_answer')
                    ->comment('system_protected, core_administrative, custom_answer, external_only, unmapped');
                $table->text('raw_remote_value')->nullable();
                $table->text('normalized_remote_value')->nullable();
                $table->text('current_db_value')->nullable();
                $table->char('old_checksum', 64)->nullable();
                $table->char('new_checksum', 64)->nullable();
                $table->string('source_revision', 100)->nullable();
                $table->string('conflict_status', 50)->default('clean_inbound')
                    ->comment('none, clean_inbound, conflict_divergent, conflict_core_field_requires_approval, rejected_protected_field, rejected_validation, applied, rejected_by_admin');
                $table->text('conflict_reason')->nullable();
                $table->string('staging_status', 50)->default('staged')
                    ->comment('staged, approved, rejected, applied, ignored');
                $table->string('resolution_policy', 50)->default('pending')
                    ->comment('pending, admin_override, keep_local, accept_remote');
                $table->boolean('is_formula')->default(false);
                $table->foreignId('reviewed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->dateTime('reviewed_at')->nullable();
                $table->dateTime('applied_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['spreadsheet_connection_id', 'tab_name', 'sync_id'], 'inbound_conn_tab_sync_idx');
                $table->index(['staging_status', 'conflict_status'], 'inbound_status_conflict_idx');
                $table->index(['sync_id', 'column_identity'], 'inbound_sync_col_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sheet_inbound_change_staging');
    }
};
