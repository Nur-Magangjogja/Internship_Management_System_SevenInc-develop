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
        if (!Schema::hasTable('external_identity_mappings')) {
            Schema::create('external_identity_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 64)->default('absen_djuragan');
            $table->string('external_user_id', 191);
            $table->unsignedBigInteger('ims_user_id');
            $table->enum('verification_status', [
                'unverified',
                'pending_verification',
                'verified',
                'rejected',
                'conflict',
            ])->default('pending_verification');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamps();

            // Uniqueness: satu identitas eksternal per source system hanya dipetakan ke satu akun
            $table->unique(['source_system', 'external_user_id'], 'uk_ext_identity_source_user');

            // Index lookup berdasarkan ims_user_id dan source_system
            $table->index(['ims_user_id', 'source_system'], 'idx_ext_identity_ims_user');

            // Foreign keys dengan ON DELETE RESTRICT untuk integritas data historis
            $table->foreign('ims_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('verified_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_identity_mappings');
    }
};
