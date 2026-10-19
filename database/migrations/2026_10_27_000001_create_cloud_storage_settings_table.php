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
        Schema::create('cloud_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('name')->default('Penyimpanan Google Drive Sistem');
            $table->string('provider', 50)->default('google_drive');
            
            // Root Folders
            $table->string('system_assets_folder_url', 500)->nullable();
            $table->string('system_assets_folder_id', 255)->nullable();
            
            $table->string('document_templates_folder_url', 500)->nullable();
            $table->string('document_templates_folder_id', 255)->nullable();
            
            $table->string('generated_docs_folder_url', 500)->nullable();
            $table->string('generated_docs_folder_id', 255)->nullable();
            
            $table->string('archives_folder_url', 500)->nullable();
            $table->string('archives_folder_id', 255)->nullable();
            
            // Connection & Sync Status
            $table->boolean('is_enabled')->default(true);
            $table->string('connection_status', 50)->default('untested'); // untested, connected, error
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_successful_test_at')->nullable();
            $table->text('last_error_summary')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloud_storage_settings');
    }
};
