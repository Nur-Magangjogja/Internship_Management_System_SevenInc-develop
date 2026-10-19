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
        Schema::create('system_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('name', 255);
            $table->string('asset_code', 100)->nullable()->index(); // e.g. company_logo, hr_signature
            $table->string('category', 50)->index(); // logo, letterhead, signature, stamp, background, attachment, other
            $table->string('scope', 50)->default('global')->index(); // global, brand, institution
            
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('checksum_sha256', 64)->index();
            $table->string('local_path', 500); // Private storage path
            
            $table->string('drive_file_id', 255)->nullable()->index();
            $table->string('drive_folder_id', 255)->nullable();
            $table->string('drive_web_view_link', 500)->nullable();
            
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            
            $table->string('sync_status', 50)->default('pending')->index(); // pending, synced, failed
            $table->timestamp('drive_synced_at')->nullable();
            $table->text('sync_error_message')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_assets');
    }
};
