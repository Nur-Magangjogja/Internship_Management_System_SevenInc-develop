<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ensure any existing TEMPLATE_TAHUN profiles that were defaulted to 1/2 are updated to 12/13.
     */
    public function up(): void
    {
        if (Schema::hasTable('spreadsheet_tab_profiles')) {
            DB::table('spreadsheet_tab_profiles')
                ->where('tab_name', 'TEMPLATE_TAHUN')
                ->where('header_row', 1)
                ->where('data_start_row', 2)
                ->update([
                    'header_row'     => 12,
                    'data_start_row' => 13,
                    'updated_at'     => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op to preserve explicit corrections
    }
};
