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
        if (Schema::hasTable('spreadsheet_connections')) {
            Schema::table('spreadsheet_connections', function (Blueprint $table) {
                if (!Schema::hasColumn('spreadsheet_connections', 'drive_folder_url')) {
                    $table->string('drive_folder_url', 500)->nullable()->after('apps_script_endpoint_url');
                }
                if (!Schema::hasColumn('spreadsheet_connections', 'drive_folder_id')) {
                    $table->string('drive_folder_id', 255)->nullable()->after('drive_folder_url');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('spreadsheet_connections')) {
            Schema::table('spreadsheet_connections', function (Blueprint $table) {
                $cols = [];
                if (Schema::hasColumn('spreadsheet_connections', 'drive_folder_url')) {
                    $cols[] = 'drive_folder_url';
                }
                if (Schema::hasColumn('spreadsheet_connections', 'drive_folder_id')) {
                    $cols[] = 'drive_folder_id';
                }
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
