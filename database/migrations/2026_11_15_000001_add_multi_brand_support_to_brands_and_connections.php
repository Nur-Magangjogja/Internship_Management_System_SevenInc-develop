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
        // 1. Tambah kolom registration_host & primary_domain pada tabel brands
        Schema::table('brands', function (Blueprint $table) {
            if (!Schema::hasColumn('brands', 'registration_host')) {
                $table->string('registration_host', 150)->nullable()->after('name')->index();
            }
            if (!Schema::hasColumn('brands', 'primary_domain')) {
                $table->string('primary_domain', 150)->nullable()->after('registration_host')->index();
            }
        });

        // 2. Tambah kolom registration_host pada tabel internship_registrations
        Schema::table('internship_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('internship_registrations', 'registration_host')) {
                $table->string('registration_host', 150)->nullable()->after('brand_id')->index();
            }
        });

        // 3. Tambah kolom brand_id pada tabel spreadsheet_connections (relasi opsional per-brand)
        Schema::table('spreadsheet_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('spreadsheet_connections', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('connection_key')->constrained('brands')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spreadsheet_connections', function (Blueprint $table) {
            if (Schema::hasColumn('spreadsheet_connections', 'brand_id')) {
                $table->dropForeign(['brand_id']);
                $table->dropColumn('brand_id');
            }
        });

        Schema::table('internship_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('internship_registrations', 'registration_host')) {
                $table->dropColumn('registration_host');
            }
        });

        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'primary_domain')) {
                $table->dropColumn('primary_domain');
            }
            if (Schema::hasColumn('brands', 'registration_host')) {
                $table->dropColumn('registration_host');
            }
        });
    }
};
