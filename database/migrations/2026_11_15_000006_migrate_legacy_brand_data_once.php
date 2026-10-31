<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move legacy SVII/ALB ownership references to SI once, outside the regular seeder.
     *
     * This migration deliberately refuses ambiguous spreadsheet connection merges rather
     * than deleting either configuration. Review/merge conflicting connections manually,
     * then rerun this migration.
     */
    public function up(): void
    {
        if (!Schema::hasTable('brands')) {
            return;
        }

        DB::transaction(function (): void {
            $brandRows = DB::table('brands')
                ->whereIn('code', ['SI', 'SVII', 'ALB'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $target = $brandRows->firstWhere('code', 'SI');

            // Backward-compatible recovery if only the historical SEVEN INC code exists.
            if (!$target) {
                $legacySevenInc = $brandRows->firstWhere('code', 'SVII');
                if (!$legacySevenInc) {
                    // Do not guess where ALB data should go if there is no canonical SI brand.
                    return;
                }

                DB::table('brands')->where('id', $legacySevenInc->id)->update(['code' => 'SI']);
                $target = (object) array_merge((array) $legacySevenInc, ['code' => 'SI']);
            }

            $targetBrandId = (int) $target->id;
            $legacyRows = DB::table('brands')
                ->whereIn('code', ['SVII', 'ALB'])
                ->where('id', '!=', $targetBrandId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'code', 'is_default']);

            if ($legacyRows->isEmpty()) {
                return;
            }

            $legacyIds = $legacyRows->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Preflight first: never discard or overwrite conflicting per-brand connections.
            if (Schema::hasTable('spreadsheet_connections') && Schema::hasColumn('spreadsheet_connections', 'brand_id')) {
                $legacyConnections = DB::table('spreadsheet_connections')
                    ->whereIn('brand_id', $legacyIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'connection_key', 'brand_id']);

                $keysToMove = [];
                foreach ($legacyConnections as $connection) {
                    $key = (string) $connection->connection_key;
                    if (isset($keysToMove[$key])) {
                        throw new RuntimeException(
                            "Migrasi brand historis dibatalkan: lebih dari satu koneksi legacy memiliki connection_key '{$key}'. Konsolidasikan koneksi secara manual tanpa menghapus konfigurasi."
                        );
                    }

                    $targetConnectionExists = DB::table('spreadsheet_connections')
                        ->where('connection_key', $key)
                        ->where('brand_id', $targetBrandId)
                        ->exists();

                    if ($targetConnectionExists) {
                        throw new RuntimeException(
                            "Migrasi brand historis dibatalkan: Brand SI sudah memiliki koneksi '{$key}'. Bandingkan konfigurasi SI dan brand legacy lalu pindahkan data secara terencana."
                        );
                    }

                    $keysToMove[$key] = true;
                }
            }

            $legacyWasDefault = Schema::hasColumn('brands', 'is_default')
                && DB::table('brands')->whereIn('id', $legacyIds)->where('is_default', true)->exists();

            // Remap known brand_id references without overwriting registration_host audit history.
            $referenceTables = [
                'internship_registrations',
                'users',
                'webinars',
                'intern_extras',
                'system_assets',
                'mous',
            ];

            foreach ($referenceTables as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'brand_id')) {
                    DB::table($table)
                        ->whereIn('brand_id', $legacyIds)
                        ->update(['brand_id' => $targetBrandId]);
                }
            }

            if (Schema::hasTable('spreadsheet_connections') && Schema::hasColumn('spreadsheet_connections', 'brand_id')) {
                DB::table('spreadsheet_connections')
                    ->whereIn('brand_id', $legacyIds)
                    ->update(['brand_id' => $targetBrandId]);
            }

            if ($legacyWasDefault && Schema::hasColumn('brands', 'is_default')) {
                DB::table('brands')->where('id', $targetBrandId)->update(['is_default' => true]);
            }

            // brand_divisions is defined with cascadeOnDelete; obsolete mappings disappear
            // with legacy brand records. Official SI mappings are controlled by BrandSeeder.
            DB::table('brands')->whereIn('id', $legacyIds)->delete();
        });
    }

    /**
     * This is a data-consolidation migration and cannot be safely reversed automatically.
     * A no-op avoids inventing legacy brand identities or undoing historical ownership updates.
     */
    public function down(): void
    {
        // Intentionally irreversible; see the class-level migration notes.
    }
};
