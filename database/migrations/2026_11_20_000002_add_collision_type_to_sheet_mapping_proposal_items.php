<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 1 / Phase 3: Add collision categorization and resolution audit columns to proposal items.
     */
    public function up(): void
    {
        Schema::table('sheet_mapping_proposal_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sheet_mapping_proposal_items', 'collision_type')) {
                $table->string('collision_type', 50)->nullable()->after('has_collision')
                    ->comment('duplicate_header, target_field_collision, protocol_collision');
            }

            if (!Schema::hasColumn('sheet_mapping_proposal_items', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('collision_detail');
            }

            if (!Schema::hasColumn('sheet_mapping_proposal_items', 'resolved_by')) {
                $table->foreignId('resolved_by')->nullable()->after('resolved_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sheet_mapping_proposal_items', function (Blueprint $table) {
            if (Schema::hasColumn('sheet_mapping_proposal_items', 'resolved_by')) {
                $table->dropForeign(['resolved_by']);
                $table->dropColumn('resolved_by');
            }
            if (Schema::hasColumn('sheet_mapping_proposal_items', 'resolved_at')) {
                $table->dropColumn('resolved_at');
            }
            if (Schema::hasColumn('sheet_mapping_proposal_items', 'collision_type')) {
                $table->dropColumn('collision_type');
            }
        });
    }
};
