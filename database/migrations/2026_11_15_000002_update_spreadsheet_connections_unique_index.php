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
        Schema::table('spreadsheet_connections', function (Blueprint $table) {
            $table->dropUnique('spreadsheet_connections_connection_key_unique');
            $table->index('connection_key');
            $table->unique(['connection_key', 'brand_id'], 'spreadsheet_connections_key_brand_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('spreadsheet_connections')) {
            return;
        }

        // Multi-brand data can contain multiple rows per connection_key. Restoring the old
        // single-column unique index in that state would fail with duplicate-key SQL errors.
        // Abort before changing any index so no schema/data is left half-migrated.
        $duplicateKeys = \Illuminate\Support\Facades\DB::table('spreadsheet_connections')
            ->select('connection_key')
            ->groupBy('connection_key')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->pluck('connection_key')
            ->all();

        if ($duplicateKeys !== []) {
            throw new RuntimeException(
                'Rollback spreadsheet_connections unique index dibatalkan karena data multi-brand '
                . 'memiliki connection_key ganda (' . implode(', ', $duplicateKeys) . '). '
                . 'Konsolidasikan/pindahkan koneksi secara eksplisit dan tanpa menghapus konfigurasi, '
                . 'lalu jalankan rollback kembali.'
            );
        }

        Schema::table('spreadsheet_connections', function (Blueprint $table) {
            $table->dropUnique('spreadsheet_connections_key_brand_unique');
            $table->dropIndex('spreadsheet_connections_connection_key_index');
            $table->unique('connection_key', 'spreadsheet_connections_connection_key_unique');
        });
    }
};
