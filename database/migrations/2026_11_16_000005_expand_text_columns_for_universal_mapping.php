<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sheet_mapping_proposal_items')) {
            Schema::table('sheet_mapping_proposal_items', function (Blueprint $table) {
                $table->text('match_reason')->nullable()->change();
                $table->text('collision_detail')->nullable()->change();
                $table->text('observed_label')->change();
                $table->text('suggested_label')->nullable()->change();
            });
        }

        if (Schema::hasTable('sheet_discovered_columns')) {
            Schema::table('sheet_discovered_columns', function (Blueprint $table) {
                $table->text('observed_label')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sheet_discovered_columns')) {
            Schema::table('sheet_discovered_columns', function (Blueprint $table) {
                $table->string('observed_label', 255)->change();
            });
        }

        if (Schema::hasTable('sheet_mapping_proposal_items')) {
            Schema::table('sheet_mapping_proposal_items', function (Blueprint $table) {
                $table->string('match_reason', 255)->nullable()->change();
                $table->string('collision_detail', 255)->nullable()->change();
                $table->string('observed_label', 255)->change();
                $table->string('suggested_label', 255)->nullable()->change();
            });
        }
    }
};
