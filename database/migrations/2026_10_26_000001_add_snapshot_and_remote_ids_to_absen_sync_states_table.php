<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Menambahkan kolom payload_snapshot, absen_user_id, intern_id, dan confirmed_payload_version
     * pada tabel absen_sync_states untuk mendukung persistensi snapshot pengiriman dan hasil remote.
     */
    public function up(): void
    {
        if (Schema::hasTable('absen_sync_states')) {
            Schema::table('absen_sync_states', function (Blueprint $table) {
                if (!Schema::hasColumn('absen_sync_states', 'payload_snapshot')) {
                    $table->json('payload_snapshot')->nullable()->after('in_flight_payload_checksum');
                }
                if (!Schema::hasColumn('absen_sync_states', 'absen_user_id')) {
                    $table->unsignedBigInteger('absen_user_id')->nullable()->after('last_synced_at');
                }
                if (!Schema::hasColumn('absen_sync_states', 'intern_id')) {
                    $table->unsignedBigInteger('intern_id')->nullable()->after('absen_user_id');
                }
                if (!Schema::hasColumn('absen_sync_states', 'confirmed_payload_version')) {
                    $table->unsignedInteger('confirmed_payload_version')->nullable()->after('intern_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('absen_sync_states')) {
            Schema::table('absen_sync_states', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('absen_sync_states', 'confirmed_payload_version')) {
                    $columnsToDrop[] = 'confirmed_payload_version';
                }
                if (Schema::hasColumn('absen_sync_states', 'intern_id')) {
                    $columnsToDrop[] = 'intern_id';
                }
                if (Schema::hasColumn('absen_sync_states', 'absen_user_id')) {
                    $columnsToDrop[] = 'absen_user_id';
                }
                if (Schema::hasColumn('absen_sync_states', 'payload_snapshot')) {
                    $columnsToDrop[] = 'payload_snapshot';
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
