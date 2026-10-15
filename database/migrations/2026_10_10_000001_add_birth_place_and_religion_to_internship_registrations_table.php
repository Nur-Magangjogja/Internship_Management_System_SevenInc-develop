<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Menggunakan pendekatan fail-fast: membatalkan eksekusi jika kolom sudah ada sebelumnya,
     * sehingga memastikan down() hanya akan menghapus kolom yang benar-benar dibuat oleh migrasi ini.
     */
    public function up(): void
    {
        if (Schema::hasColumn('internship_registrations', 'birth_place') || Schema::hasColumn('internship_registrations', 'religion')) {
            throw new \RuntimeException(
                "Gagal migrasi: Kolom 'birth_place' atau 'religion' telah ada pada tabel 'internship_registrations'. " .
                "Migrasi dibatalkan demi melindungi data pada kolom existing."
            );
        }

        Schema::table('internship_registrations', function (Blueprint $table) {
            $table->string('birth_place', 100)->nullable()->after('born_date');
            $table->string('religion', 50)->nullable()->after('gender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            $table->dropColumn(['birth_place', 'religion']);
        });
    }
};
