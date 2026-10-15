<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabel 'absen_sync_states' mengimplementasikan transactional outbox & durable sync state
     * untuk sinkronisasi outbound IMS -> Absen Djuragan (Phase C.2).
     */
    public function up(): void
    {
        if (!Schema::hasTable('absen_sync_states')) {
            Schema::create('absen_sync_states', function (Blueprint $table) {
                $table->id();

                // 1. Relasi ke pendaftaran magang IMS (1-to-1 durable sync state per registrasi)
                $table->unsignedBigInteger('registration_id');

                // 2. Status sinkronisasi & versi payload
                $table->string('sync_status', 30)->default('pending');
                $table->unsignedInteger('current_payload_version')->default(1);
                $table->string('current_payload_checksum', 64)->nullable();

                // 3. Worker in-flight tracking (fencing token & lease)
                $table->unsignedInteger('in_flight_payload_version')->nullable();
                $table->string('in_flight_payload_checksum', 64)->nullable();
                $table->string('lease_owner', 191)->nullable();
                $table->string('fencing_token', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable();

                // 4. Riwayat konfirmasi sinkronisasi terakhir yang berhasil diakui remote
                $table->unsignedInteger('last_synced_payload_version')->nullable();
                $table->string('last_synced_checksum', 64)->nullable();
                $table->timestamp('last_synced_at')->nullable();

                // 5. Attempt, retry, & backoff tracking
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedInteger('max_attempts')->default(5);
                $table->timestamp('last_attempted_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();

                // 6. Sanitized response & error diagnostics
                $table->unsignedSmallInteger('last_response_status')->nullable();
                $table->text('last_error_message')->nullable();
                $table->json('last_error_context')->nullable();

                $table->timestamps();

                // Constraints & Indices
                $table->unique('registration_id', 'uk_absen_sync_reg_id');

                // Foreign key dengan ON DELETE RESTRICT untuk mempertahankan audit trail historis
                $table->foreign('registration_id', 'fk_absen_sync_reg_id')
                    ->references('id')
                    ->on('internship_registrations')
                    ->onDelete('restrict');

                // Indeks untuk worker polling pending/retrying
                $table->index(['sync_status', 'next_retry_at'], 'idx_absen_sync_status_retry');

                // Indeks untuk deteksi lease kedaluwarsa (stale in-flight worker recovery)
                $table->index(['sync_status', 'lease_expires_at'], 'idx_absen_sync_lease_expiry');

                // Indeks untuk scheduling rekonsiliasi dan audit berkala
                $table->index(['sync_status', 'last_attempted_at'], 'idx_absen_sync_reconciliation');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absen_sync_states');
    }
};
