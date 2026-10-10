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
        // Tambahkan metadata sinkronisasi Drive pada tabel mous
        Schema::table('mous', function (Blueprint $table) {
            $table->string('drive_file_id', 255)->nullable()->after('signed_file_path');
            $table->string('drive_web_view_link', 500)->nullable()->after('drive_file_id');
            $table->timestamp('drive_synced_at')->nullable()->after('drive_web_view_link');
        });

        // Tambahkan metadata sinkronisasi Drive pada tabel archive_exports
        Schema::table('archive_exports', function (Blueprint $table) {
            $table->string('drive_file_id', 255)->nullable()->after('file_path');
            $table->string('drive_web_view_link', 500)->nullable()->after('drive_file_id');
            $table->timestamp('drive_synced_at')->nullable()->after('drive_web_view_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archive_exports', function (Blueprint $table) {
            $table->dropColumn(['drive_file_id', 'drive_web_view_link', 'drive_synced_at']);
        });

        Schema::table('mous', function (Blueprint $table) {
            $table->dropColumn(['drive_file_id', 'drive_web_view_link', 'drive_synced_at']);
        });
    }
};
