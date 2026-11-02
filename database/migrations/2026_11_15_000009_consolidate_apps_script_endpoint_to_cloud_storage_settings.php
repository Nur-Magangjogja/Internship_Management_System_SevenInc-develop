<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Konsolidasi 1 endpoint Google Apps Script Gateway ke tabel cloud_storage_settings.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('cloud_storage_settings', 'apps_script_endpoint_url')) {
            Schema::table('cloud_storage_settings', function (Blueprint $table) {
                $table->string('apps_script_endpoint_url', 500)->nullable()->after('canonical_parent_folder_id');
            });
        }

        // Migrasikan endpoint yang sudah ada dari spreadsheet_connections ke cloud_storage_settings
        try {
            $existingEndpoint = DB::table('spreadsheet_connections')
                ->whereNotNull('apps_script_endpoint_url')
                ->where('apps_script_endpoint_url', '!=', '')
                ->value('apps_script_endpoint_url')
                ?: (function_exists('config') ? config('services.google_apps_script.endpoint_url') : null);

            if ($existingEndpoint) {
                // Set endpoint terpadu pada cloud_storage_settings
                DB::table('cloud_storage_settings')
                    ->whereNull('apps_script_endpoint_url')
                    ->update(['apps_script_endpoint_url' => $existingEndpoint]);

                // Sinkronkan ke seluruh spreadsheet_connections agar konsisten menggunakan 1 endpoint
                DB::table('spreadsheet_connections')
                    ->update(['apps_script_endpoint_url' => $existingEndpoint]);
            }
        } catch (\Throwable) {
            // Silently pass jika tabel atau data belum siap
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safe rollback
    }
};
