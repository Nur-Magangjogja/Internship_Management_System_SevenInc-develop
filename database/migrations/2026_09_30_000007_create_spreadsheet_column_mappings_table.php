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
        Schema::create('spreadsheet_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('spreadsheet_schema_version_id');
            $table->unsignedSmallInteger('column_order')->comment('Urutan kolom di sheet (1-based)');
            $table->string('sheet_header', 255)->comment('Teks header persis di baris pertama spreadsheet');
            $table->string('target_entity', 100)->nullable()->comment('Entitas sumber data (misal: internship_registration)');
            $table->string('target_field', 100)->nullable()->comment('Field atau accessor sumber');
            $table->string('data_type', 50)->default('string')->comment('string, integer, date, boolean, url, text');
            $table->string('direction', 50)->default('outbound')->comment('outbound, display_only, system');
            $table->string('transform_key', 100)->nullable()->comment('Key transformer khusus untuk formatting nilai');
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->foreign('spreadsheet_schema_version_id', 'col_map_schema_ver_fk')
                ->references('id')
                ->on('spreadsheet_schema_versions')
                ->restrictOnDelete();

            $table->unique(['spreadsheet_schema_version_id', 'column_order'], 'map_ver_order_unique');
            $table->unique(['spreadsheet_schema_version_id', 'sheet_header'], 'map_ver_header_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_column_mappings');
    }
};
