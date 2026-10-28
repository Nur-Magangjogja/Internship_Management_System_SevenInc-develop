<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambah is_default pada tabel brands
        if (Schema::hasTable('brands') && !Schema::hasColumn('brands', 'is_default')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('primary_domain');
            });
        }

        // 2. Tambah brand_id dan registration_host pada tabel users (untuk mengunci brand pemagang)
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'brand_id')) {
                    $table->foreignId('brand_id')->nullable()->after('role')->constrained('brands')->nullOnDelete();
                }
                if (!Schema::hasColumn('users', 'registration_host')) {
                    $table->string('registration_host')->nullable()->after('brand_id');
                }
            });
        }

        // 3. Set SEVEN INC (SI) sebagai Brand Default awal
        $siBrandId = DB::table('brands')->where('code', 'SI')->value('id');
        if ($siBrandId) {
            DB::table('brands')->where('id', $siBrandId)->update(['is_default' => true]);

            // Hubungkan koneksi pendaftaran global lama (ID 1) ke SEVEN INC
            $globalConn = DB::table('spreadsheet_connections')
                ->where('connection_key', 'registration')
                ->whereNull('brand_id')
                ->first();

            if ($globalConn) {
                // Hapus duplikat placeholder SEVEN INC yang belum dikonfigurasi (jika ada)
                DB::table('spreadsheet_connections')
                    ->where('connection_key', 'registration')
                    ->where('brand_id', $siBrandId)
                    ->where('id', '!=', $globalConn->id)
                    ->delete();

                DB::table('spreadsheet_connections')
                    ->where('id', $globalConn->id)
                    ->update([
                        'brand_id'     => $siBrandId,
                        'display_name' => 'Spreadsheet Pendaftaran (SEVEN INC)',
                    ]);
            }
        }

        // 4. Backfill users.brand_id dari internship_registrations yang sudah ada
        DB::statement("
            UPDATE users u
            INNER JOIN internship_registrations ir ON ir.user_id = u.id
            SET u.brand_id = ir.brand_id,
                u.registration_host = ir.registration_host
            WHERE u.brand_id IS NULL AND ir.brand_id IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'brand_id')) {
                    $table->dropForeign(['brand_id']);
                    $table->dropColumn('brand_id');
                }
                if (Schema::hasColumn('users', 'registration_host')) {
                    $table->dropColumn('registration_host');
                }
            });
        }

        if (Schema::hasTable('brands') && Schema::hasColumn('brands', 'is_default')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
