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
        Schema::create('spreadsheet_schema_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('spreadsheet_connection_id')->constrained('spreadsheet_connections')->restrictOnDelete();
            $table->unsignedSmallInteger('year')->comment('Tahun pendaftaran, contoh: 2026');
            $table->string('sheet_tab_name', 100)->comment('Nama tab/sheet di spreadsheet, contoh: 2026');
            $table->string('schema_version', 50)->default('v1')->comment('Versi schema, contoh: v1, v2');
            $table->boolean('is_active')->default(true)->comment('Menandakan versi schema aktif untuk tahun terkait');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['spreadsheet_connection_id', 'year', 'schema_version'], 'schema_conn_year_version_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_schema_versions');
    }
};
