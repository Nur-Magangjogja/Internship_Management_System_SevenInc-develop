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
                if (!Schema::hasColumn('spreadsheet_connections', 'default_integrated_status')) {
                    $table->string('default_integrated_status', 50)->default('waiting')->after('allow_custom_tab_naming')
                        ->comment('Status awal untuk pemagang baru dari spreadsheet: waiting, accepted, active');
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
                if (Schema::hasColumn('spreadsheet_connections', 'default_integrated_status')) {
                    $table->dropColumn('default_integrated_status');
                }
            });
        }
    }
};
