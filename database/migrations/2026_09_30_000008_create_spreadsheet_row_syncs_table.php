<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase S9D: Outbound Spreadsheet Row Sync Tracking.
     */
    public function up(): void
    {
        Schema::create('spreadsheet_row_syncs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('spreadsheet_connection_id')
                ->constrained('spreadsheet_connections')
                ->restrictOnDelete();

            $table->string('syncable_type', 100);
            $table->unsignedBigInteger('syncable_id');
            $table->string('external_sync_id', 100)->unique()->comment('Permanent, stable canonical sync ID (e.g. REG-123-abc456)');
            $table->string('target_sheet_tab', 100)->comment('Target sheet tab name (e.g. 2026 or Follow Up Guru Dosen)');
            $table->string('schema_version', 50)->default('v1');
            $table->string('sync_status', 30)->default('pending')->comment('pending, processing, synced, failed, retrying');
            $table->char('payload_checksum', 64)->nullable()->comment('SHA-256 hash of the outbound payload to detect changes');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('locked_at')->nullable()->comment('Mutex lease timestamp for worker concurrency safety');
            $table->string('locked_by', 100)->nullable()->comment('Worker fencing token to prevent lost updates');
            $table->text('error_message')->nullable()->comment('Sanitized error message without secrets');
            $table->unsignedInteger('row_index')->nullable()->comment('Informational Google Sheet row index (not identity)');
            $table->timestamps();

            $table->unique(['spreadsheet_connection_id', 'syncable_type', 'syncable_id'], 'row_sync_conn_syncable_unique');
            $table->index(['sync_status', 'next_retry_at'], 'row_sync_status_retry_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_row_syncs');
    }
};
