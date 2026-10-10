<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ubah DB default is_verified dari 1 (true) menjadi 0 (false) untuk mencegah kontak menjadi verified tanpa aksi HR/Admin.
     */
    public function up(): void
    {
        Schema::table('institution_contacts', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institution_contacts', function (Blueprint $table) {
            $table->boolean('is_verified')->default(true)->change();
        });
    }
};
