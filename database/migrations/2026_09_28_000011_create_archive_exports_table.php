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
        Schema::create('archive_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->unsignedSmallInteger('archive_year')->index();

            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->json('file_manifest')->nullable();

            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed')->index();
            $table->text('failure_message')->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->useCurrent();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archive_exports');
    }
};
