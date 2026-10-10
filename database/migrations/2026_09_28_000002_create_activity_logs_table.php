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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();

            // Actor Snapshot — tetap tersimpan meski user di-soft delete atau dihapus
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot', 150);
            $table->string('actor_email_snapshot', 150)->nullable();
            $table->string('actor_role_snapshot', 50);

            // Aksi dan Objek
            $table->string('action', 100)->index();
            $table->string('subject_type', 150)->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('subject_public_ref', 100)->nullable();
            $table->text('description')->nullable();

            // Data State Before & After serta Metadata (aman dari field sensitif)
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->json('metadata')->nullable();

            // Client Request Info
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Append-only timestamp (tidak ada updated_at)
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
