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
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('document_type', 50)->index(); // loa, skl, certificate, assessment, recommendation, mou
            $table->string('document_number', 100)->index();
            
            // Relasi Pemilik
            $table->foreignId('internship_registration_id')->nullable()->constrained('internship_registrations')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            
            // Template & Versioning
            $table->string('template_type', 100)->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedInteger('template_version')->nullable()->default(1);
            
            // Snapshots
            $table->json('variable_snapshot')->nullable();
            $table->json('asset_references')->nullable();
            
            // Physical PDF File
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('checksum_sha256', 64)->index();
            
            // Cloud Sync
            $table->string('drive_file_id', 255)->nullable()->index();
            $table->string('drive_folder_id', 255)->nullable();
            $table->string('drive_web_view_link', 500)->nullable();
            
            // Status Pipeline: DRAFT -> GENERATED -> SYNC_PENDING -> SYNCED, FAILED
            $table->string('status', 50)->default('DRAFT')->index();
            $table->string('idempotency_key', 255)->nullable()->unique();
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('sync_error_message')->nullable();
            $table->json('status_history')->nullable();
            
            $table->timestamp('published_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
