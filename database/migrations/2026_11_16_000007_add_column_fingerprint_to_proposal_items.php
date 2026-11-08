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
        if (Schema::hasTable('sheet_mapping_proposal_items')) {
            Schema::table('sheet_mapping_proposal_items', function (Blueprint $table) {
                if (!Schema::hasColumn('sheet_mapping_proposal_items', 'column_fingerprint')) {
                    $table->string('column_fingerprint', 64)->nullable()->after('column_identity');
                    $table->index('column_fingerprint', 'item_col_fingerprint_idx');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sheet_mapping_proposal_items')) {
            Schema::table('sheet_mapping_proposal_items', function (Blueprint $table) {
                if (Schema::hasColumn('sheet_mapping_proposal_items', 'column_fingerprint')) {
                    $table->dropIndex('item_col_fingerprint_idx');
                    $table->dropColumn('column_fingerprint');
                }
            });
        }
    }
};
