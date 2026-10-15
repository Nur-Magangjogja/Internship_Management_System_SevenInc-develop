<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * S17.18D.3: Outbound Sidecar (Extension Tab) Row Sync Tracking for 2026_Custom_Fields.
     */
    public function up(): void
    {
        if (!Schema::hasTable('spreadsheet_sidecar_syncs')) {
            Schema::create('spreadsheet_sidecar_syncs', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->foreignId('spreadsheet_connection_id')
                    ->constrained('spreadsheet_connections')
                    ->restrictOnDelete();
                $table->foreignId('internship_registration_id')
                    ->constrained('internship_registrations')
                    ->cascadeOnDelete();

                $table->string('external_sync_id', 100)->unique()->comment('Identical to Main.Z (Sidecar.A)');
                $table->string('main_sheet_tab', 100)->default('2026');
                $table->string('extension_sheet_tab', 100)->default('2026_Custom_Fields');
                $table->string('form_schema_version', 50)->nullable()->comment('Derived from snapshot (Sidecar.C)');
                $table->string('sync_status', 30)->default('pending')->comment('pending, processing, synced, failed, retrying');
                $table->char('payload_checksum', 64)->nullable()->comment('SHA-256 hash of custom answers payload');
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->unsignedSmallInteger('max_attempts')->default(5);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->dateTime('next_retry_at')->nullable();
                $table->dateTime('locked_at')->nullable()->comment('Mutex lease timestamp for worker concurrency safety');
                $table->string('locked_by', 100)->nullable()->comment('Worker fencing token to prevent lost updates');
                $table->text('error_message')->nullable()->comment('Sanitized error message without secrets');
                $table->unsignedInteger('row_index')->nullable()->comment('Informational sidecar row index');
                $table->timestamps();

                $table->unique(['spreadsheet_connection_id', 'internship_registration_id'], 'sidecar_sync_conn_reg_unique');
                $table->index(['sync_status', 'next_retry_at'], 'sidecar_sync_status_retry_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Fail-closed data-preservation guard (S17.18G.4R Fix 1)
        if (Schema::hasTable('spreadsheet_sidecar_syncs') && DB::table('spreadsheet_sidecar_syncs')->exists()) {
            $count = DB::table('spreadsheet_sidecar_syncs')->count();
            throw new \DomainException(
                "ROLLBACK_ABORTED_DATA_HAZARD: Tabel 'spreadsheet_sidecar_syncs' memuat {$count} record sinkronisasi sidecar historis. Rollback otomatis dibatalkan untuk mencegah inkonsistensi sync state. Gunakan strategi forward-fix."
            );
        }

        Schema::dropIfExists('spreadsheet_sidecar_syncs');
    }
};
