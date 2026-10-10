<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase S9E: Add Google Drive synchronization fields to registration_attachments.
     */
    public function up(): void
    {
        Schema::table('registration_attachments', function (Blueprint $table) {
            $table->string('drive_file_id', 255)->nullable()->after('mime_type')->comment('Google Drive file ID');
            $table->string('drive_web_view_link', 500)->nullable()->after('drive_file_id')->comment('Restricted Google Drive web view URL');
            $table->string('drive_sync_status', 30)->default('pending')->after('drive_web_view_link')->comment('pending, processing, synced, failed');
            $table->timestamp('drive_synced_at')->nullable()->after('drive_sync_status');
            $table->text('drive_error_message')->nullable()->after('drive_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registration_attachments', function (Blueprint $table) {
            $table->dropColumn([
                'drive_file_id',
                'drive_web_view_link',
                'drive_sync_status',
                'drive_synced_at',
                'drive_error_message',
            ]);
        });
    }
};
