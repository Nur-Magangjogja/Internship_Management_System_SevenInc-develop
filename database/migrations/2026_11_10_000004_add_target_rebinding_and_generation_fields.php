<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * HOTFIX 1 & 2: Target Rebinding, Generation Fencing, and Target Audit Tracking.
     */
    public function up(): void
    {
        // 1. spreadsheet_connections
        if (Schema::hasTable('spreadsheet_connections')) {
            Schema::table('spreadsheet_connections', function (Blueprint $table) {
                if (!Schema::hasColumn('spreadsheet_connections', 'active_spreadsheet_id')) {
                    $table->string('active_spreadsheet_id', 255)->nullable()->after('spreadsheet_id');
                }
                if (!Schema::hasColumn('spreadsheet_connections', 'previous_spreadsheet_id')) {
                    $table->string('previous_spreadsheet_id', 255)->nullable()->after('active_spreadsheet_id');
                }
                if (!Schema::hasColumn('spreadsheet_connections', 'target_generation')) {
                    $table->unsignedInteger('target_generation')->default(1)->after('previous_spreadsheet_id');
                }
            });
        }

        // 2. spreadsheet_row_syncs
        if (Schema::hasTable('spreadsheet_row_syncs')) {
            Schema::table('spreadsheet_row_syncs', function (Blueprint $table) {
                if (!Schema::hasColumn('spreadsheet_row_syncs', 'target_spreadsheet_id')) {
                    $table->string('target_spreadsheet_id', 255)->nullable()->after('target_sheet_tab');
                }
                if (!Schema::hasColumn('spreadsheet_row_syncs', 'target_generation')) {
                    $table->unsignedInteger('target_generation')->default(1)->after('target_spreadsheet_id');
                }
            });
        }

        // 3. spreadsheet_sidecar_syncs
        if (Schema::hasTable('spreadsheet_sidecar_syncs')) {
            Schema::table('spreadsheet_sidecar_syncs', function (Blueprint $table) {
                if (!Schema::hasColumn('spreadsheet_sidecar_syncs', 'target_spreadsheet_id')) {
                    $table->string('target_spreadsheet_id', 255)->nullable()->after('extension_sheet_tab');
                }
                if (!Schema::hasColumn('spreadsheet_sidecar_syncs', 'target_generation')) {
                    $table->unsignedInteger('target_generation')->default(1)->after('target_spreadsheet_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('spreadsheet_sidecar_syncs')) {
            Schema::table('spreadsheet_sidecar_syncs', function (Blueprint $table) {
                if (Schema::hasColumn('spreadsheet_sidecar_syncs', 'target_generation')) {
                    $table->dropColumn('target_generation');
                }
                if (Schema::hasColumn('spreadsheet_sidecar_syncs', 'target_spreadsheet_id')) {
                    $table->dropColumn('target_spreadsheet_id');
                }
            });
        }

        if (Schema::hasTable('spreadsheet_row_syncs')) {
            Schema::table('spreadsheet_row_syncs', function (Blueprint $table) {
                if (Schema::hasColumn('spreadsheet_row_syncs', 'target_generation')) {
                    $table->dropColumn('target_generation');
                }
                if (Schema::hasColumn('spreadsheet_row_syncs', 'target_spreadsheet_id')) {
                    $table->dropColumn('target_spreadsheet_id');
                }
            });
        }

        if (Schema::hasTable('spreadsheet_connections')) {
            Schema::table('spreadsheet_connections', function (Blueprint $table) {
                if (Schema::hasColumn('spreadsheet_connections', 'target_generation')) {
                    $table->dropColumn('target_generation');
                }
                if (Schema::hasColumn('spreadsheet_connections', 'previous_spreadsheet_id')) {
                    $table->dropColumn('previous_spreadsheet_id');
                }
                if (Schema::hasColumn('spreadsheet_connections', 'active_spreadsheet_id')) {
                    $table->dropColumn('active_spreadsheet_id');
                }
            });
        }
    }
};
