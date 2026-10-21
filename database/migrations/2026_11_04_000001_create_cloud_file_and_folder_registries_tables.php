<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Master Plan V1 Fase 5: Create Cloud File Registry and Cloud Folder Registry.
     */
    public function up(): void
    {
        Schema::create('cloud_folder_registry', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('canonical_root_id', 255)->index();
            $table->string('owner_type', 100)->nullable()->index(); // registration, brand, institution, system
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('category', 100)->index(); // REGISTRASI, SYSTEM-ASSETS, GENERATED-DOCUMENTS, etc.
            $table->string('folder_path', 500); // e.g. REGISTRASI/2026/REG-12/CV
            $table->string('drive_folder_id', 255)->index();
            $table->boolean('is_verified')->default(true);
            $table->timestamps();

            $table->unique(['canonical_root_id', 'folder_path'], 'idx_folder_root_path_unique');
        });

        Schema::create('cloud_file_registry', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('owner_type', 100)->index(); // registration, brand, institution, issued_document, user
            $table->unsignedBigInteger('owner_id')->index();
            $table->string('category', 100)->index(); // cv, identity, portfolio, recommendation_letter, proposal, brand_logo, institution_logo, brand_signature, institution_signature, issued_document
            $table->string('logical_filename', 255);
            $table->string('provider', 50)->default('google_drive');
            $table->string('canonical_root_id', 255)->nullable()->index();
            $table->string('drive_folder_id', 255)->nullable()->index();
            $table->string('drive_file_id', 255)->nullable()->index();
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->string('mime_type', 150)->default('application/octet-stream');
            $table->string('checksum_sha256', 64)->nullable()->index();
            $table->string('provider_version', 100)->nullable();
            $table->boolean('is_current')->default(true);
            $table->string('sync_status', 50)->default('queued'); // queued, uploading, verified, failed, archived
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('generated_from_issuance_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Portofolio multi-file diperbolehkan untuk satu owner_id + category
            $table->index(['owner_type', 'owner_id', 'category'], 'idx_file_owner_category');
            $table->index(['provider', 'drive_file_id'], 'idx_file_provider_remote_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloud_file_registry');
        Schema::dropIfExists('cloud_folder_registry');
    }
};
