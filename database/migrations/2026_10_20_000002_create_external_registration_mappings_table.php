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
        if (!Schema::hasTable('external_registration_mappings')) {
            Schema::create('external_registration_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('external_identity_mapping_id');
                $table->string('source_system', 64)->default('absen_djuragan');
                $table->string('external_registration_id', 191);
                $table->unsignedBigInteger('ims_registration_id');
                $table->timestamps();

                // Uniqueness 1: Satu external registration ID dari source system hanya boleh dipetakan ke 1 baris
                $table->unique(['source_system', 'external_registration_id'], 'uk_ext_reg_source_external');

                // Uniqueness 2: Satu registrasi IMS hanya boleh terhubung ke 1 registrasi eksternal per source system
                $table->unique(['source_system', 'ims_registration_id'], 'uk_ext_reg_source_ims');

                // Index lookup ke entitas induk identitas eksternal
                $table->index('external_identity_mapping_id', 'idx_ext_reg_identity_id');

                // Foreign keys dengan ON DELETE RESTRICT untuk integritas data historis
                $table->foreign('external_identity_mapping_id', 'fk_ext_reg_ext_id')
                    ->references('id')
                    ->on('external_identity_mappings')
                    ->onDelete('restrict');

                $table->foreign('ims_registration_id', 'fk_ext_reg_ims_id')
                    ->references('id')
                    ->on('internship_registrations')
                    ->onDelete('restrict');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_registration_mappings');
    }
};
