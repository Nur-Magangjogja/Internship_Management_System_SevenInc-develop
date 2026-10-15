<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Menggunakan pendekatan fail-fast: membatalkan eksekusi jika salah satu kolom GPS sudah ada sebelumnya,
     * sehingga memastikan down() hanya akan menghapus kolom yang benar-benar dibuat oleh migrasi ini.
     */
    public function up(): void
    {
        $existingGpsCols = array_filter(
            ['is_gps_support', 'is_gps_active', 'is_gps_activate'],
            fn($col) => Schema::hasColumn('users', $col)
        );

        if (!empty($existingGpsCols)) {
            throw new \RuntimeException(
                "Gagal migrasi: Kolom GPS [" . implode(', ', $existingGpsCols) . "] telah ada pada tabel 'users'. " .
                "Migrasi dibatalkan demi melindungi data pada kolom existing."
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_gps_support')->default(false)->after('is_banned');
            $table->boolean('is_gps_active')->default(false)->after('is_gps_support');
            $table->boolean('is_gps_activate')->default(false)->after('is_gps_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_gps_support', 'is_gps_active', 'is_gps_activate']);
        });
    }
};
