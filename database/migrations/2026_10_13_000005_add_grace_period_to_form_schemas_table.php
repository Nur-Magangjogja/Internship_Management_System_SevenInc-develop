<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * S17.18D.2: Add grace_period_days to form_schemas.
     */
    public function up(): void
    {
        if (Schema::hasTable('form_schemas')) {
            Schema::table('form_schemas', function (Blueprint $table) {
                if (!Schema::hasColumn('form_schemas', 'grace_period_days')) {
                    $table->unsignedSmallInteger('grace_period_days')->default(7)->after('schema_hash');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('form_schemas')) {
            // Fail-closed data-preservation guard (S17.18G.5 Wave 2A Fix 1)
            // 1. Cek skema published / archived
            $publishedOrArchivedCount = DB::table('form_schemas')
                ->whereIn('status', ['published', 'archived'])
                ->count();
            if ($publishedOrArchivedCount > 0) {
                throw new \DomainException(
                    "ROLLBACK_ABORTED_DATA_HAZARD: Tabel 'form_schemas' memuat {$publishedOrArchivedCount} skema formulir published/archived. Menghapus kolom 'grace_period_days' akan merusak riwayat kebijakan versi formulir. Gunakan strategi forward-fix."
                );
            }

            // 2. Cek apakah ada record skema yang memiliki nilai grace_period_days tersimpan (termasuk draf yang disesuaikan)
            if (Schema::hasColumn('form_schemas', 'grace_period_days')) {
                $hasCustomGrace = DB::table('form_schemas')
                    ->whereNotNull('grace_period_days')
                    ->exists();
                if ($hasCustomGrace) {
                    $count = DB::table('form_schemas')->whereNotNull('grace_period_days')->count();
                    throw new \DomainException(
                        "ROLLBACK_ABORTED_DATA_HAZARD: Tabel 'form_schemas' memuat {$count} konfigurasi kebijakan 'grace_period_days'. Rollback dibatalkan untuk mencegah kehilangan kebijakan grace period. Gunakan strategi forward-fix."
                    );
                }
            }

            // 3. Cek referensi snapshot historis jika tabel snapshot ada
            if (Schema::hasTable('registration_form_snapshots') && DB::table('registration_form_snapshots')->exists()) {
                $snapshotCount = DB::table('registration_form_snapshots')->count();
                throw new \DomainException(
                    "ROLLBACK_ABORTED_DATA_HAZARD: Terdeteksi {$snapshotCount} snapshot formulir pendaftaran historis yang bergantung pada skema formulir. Menghapus kolom 'grace_period_days' dibatalkan."
                );
            }

            Schema::table('form_schemas', function (Blueprint $table) {
                if (Schema::hasColumn('form_schemas', 'grace_period_days')) {
                    $table->dropColumn('grace_period_days');
                }
            });
        }
    }
};
