<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_gps_support')) {
                $table->boolean('is_gps_support')->default(false)->after('is_banned');
            }
            if (!Schema::hasColumn('users', 'is_gps_active')) {
                $table->boolean('is_gps_active')->default(false)->after('is_gps_support');
            }
            if (!Schema::hasColumn('users', 'is_gps_activate')) {
                $table->boolean('is_gps_activate')->default(false)->after('is_gps_active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(['is_gps_support', 'is_gps_active', 'is_gps_activate'], fn($col) => Schema::hasColumn('users', $col));
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
