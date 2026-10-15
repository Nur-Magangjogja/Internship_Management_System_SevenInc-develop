<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * S17.18G.6-D: Add durable leasing, retry, and checksum fields to registration_attachments table.
     */
    public function up(): void
    {
        Schema::table('registration_attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('registration_attachments', 'drive_locked_by')) {
                $table->string('drive_locked_by', 255)->nullable()->after('drive_sync_status')->comment('Fencing token of current active worker');
            }
            if (!Schema::hasColumn('registration_attachments', 'drive_lock_expires_at')) {
                $table->timestamp('drive_lock_expires_at')->nullable()->after('drive_locked_by')->comment('Lease expiry timestamp');
            }
            if (!Schema::hasColumn('registration_attachments', 'drive_attempt_count')) {
                $table->unsignedInteger('drive_attempt_count')->default(0)->after('drive_lock_expires_at')->comment('Sync attempt count');
            }
            if (!Schema::hasColumn('registration_attachments', 'drive_next_retry_at')) {
                $table->timestamp('drive_next_retry_at')->nullable()->after('drive_attempt_count')->comment('Backoff retry schedule');
            }
            if (!Schema::hasColumn('registration_attachments', 'drive_file_checksum')) {
                $table->string('drive_file_checksum', 64)->nullable()->after('drive_next_retry_at')->comment('SHA256 checksum of physical file for remote idempotency');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registration_attachments', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['drive_locked_by', 'drive_lock_expires_at', 'drive_attempt_count', 'drive_next_retry_at', 'drive_file_checksum'] as $col) {
                if (Schema::hasColumn('registration_attachments', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
