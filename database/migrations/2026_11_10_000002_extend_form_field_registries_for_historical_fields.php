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
        Schema::table('form_field_registries', function (Blueprint $table) {
            if (!Schema::hasColumn('form_field_registries', 'field_state')) {
                $table->string('field_state', 30)->default('form_field')->after('field_type');
            }
            if (!Schema::hasColumn('form_field_registries', 'form_enabled')) {
                $table->boolean('form_enabled')->default(true)->after('field_state');
            }
            if (!Schema::hasColumn('form_field_registries', 'history_storage_enabled')) {
                $table->boolean('history_storage_enabled')->default(true)->after('form_enabled');
            }
            if (!Schema::hasColumn('form_field_registries', 'spreadsheet_sync_enabled')) {
                $table->boolean('spreadsheet_sync_enabled')->default(false)->after('history_storage_enabled');
            }
            if (!Schema::hasColumn('form_field_registries', 'source_header_alias')) {
                $table->string('source_header_alias', 255)->nullable()->after('spreadsheet_sync_enabled');
            }
            if (!Schema::hasColumn('form_field_registries', 'notes')) {
                $table->text('notes')->nullable()->after('allowed_options');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_field_registries', function (Blueprint $table) {
            $table->dropColumn([
                'field_state',
                'form_enabled',
                'history_storage_enabled',
                'spreadsheet_sync_enabled',
                'source_header_alias',
                'notes',
            ]);
        });
    }
};
