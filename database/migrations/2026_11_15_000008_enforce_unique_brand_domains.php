<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('brands')
            || !Schema::hasColumn('brands', 'registration_host')
            || !Schema::hasColumn('brands', 'primary_domain')) {
            return;
        }

        foreach (['registration_host', 'primary_domain'] as $column) {
            // Empty form submissions from historical rows mean "not configured".
            // Convert blank values to NULL before a unique index is added, since some
            // database engines treat repeated empty strings as duplicate values.
            DB::table('brands')
                ->whereNotNull($column)
                ->whereRaw("TRIM({$column}) = ''")
                ->update([$column => null]);

            $duplicates = DB::table('brands')
                ->select($column)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->limit(10)
                ->pluck($column)
                ->all();

            if ($duplicates !== []) {
                throw new RuntimeException(
                    "Migrasi domain brand dibatalkan: nilai {$column} duplikat ditemukan ("
                    . implode(', ', $duplicates)
                    . '). Rapikan pemetaan domain lebih dahulu; tidak ada data yang dihapus.'
                );
            }
        }

        // Unique indexes per column protect concurrent writes. Cross-column collisions
        // (one brand's host matching another brand's primary domain) are also preflighted.
        $rows = DB::table('brands')->get(['id', 'registration_host', 'primary_domain']);
        $owners = [];
        foreach ($rows as $row) {
            foreach ([$row->registration_host, $row->primary_domain] as $host) {
                $host = $host === null ? null : strtolower(rtrim(trim((string) $host), '.'));
                if (!$host) {
                    continue;
                }
                if (isset($owners[$host]) && $owners[$host] !== (int) $row->id) {
                    throw new RuntimeException(
                        "Migrasi domain brand dibatalkan: host '{$host}' digunakan oleh lebih dari satu brand. "
                        . 'Tinjau registration_host dan primary_domain sebelum menambahkan unique index.'
                    );
                }
                $owners[$host] = (int) $row->id;
            }
        }

        $indexes = $this->existingIndexNames();
        Schema::table('brands', function (Blueprint $table) use ($indexes): void {
            if (in_array('brands_registration_host_index', $indexes, true)) {
                $table->dropIndex('brands_registration_host_index');
            }
            if (in_array('brands_primary_domain_index', $indexes, true)) {
                $table->dropIndex('brands_primary_domain_index');
            }
        });

        $indexesAfterDrop = $this->existingIndexNames();
        Schema::table('brands', function (Blueprint $table) use ($indexesAfterDrop): void {
            if (!in_array('brands_registration_host_unique', $indexesAfterDrop, true)) {
                $table->unique('registration_host', 'brands_registration_host_unique');
            }
            if (!in_array('brands_primary_domain_unique', $indexesAfterDrop, true)) {
                $table->unique('primary_domain', 'brands_primary_domain_unique');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('brands')) {
            return;
        }

        $indexes = $this->existingIndexNames();
        Schema::table('brands', function (Blueprint $table) use ($indexes): void {
            if (in_array('brands_registration_host_unique', $indexes, true)) {
                $table->dropUnique('brands_registration_host_unique');
            }
            if (in_array('brands_primary_domain_unique', $indexes, true)) {
                $table->dropUnique('brands_primary_domain_unique');
            }
        });

        $indexesAfterDrop = $this->existingIndexNames();
        Schema::table('brands', function (Blueprint $table) use ($indexesAfterDrop): void {
            if (!in_array('brands_registration_host_index', $indexesAfterDrop, true)) {
                $table->index('registration_host', 'brands_registration_host_index');
            }
            if (!in_array('brands_primary_domain_index', $indexesAfterDrop, true)) {
                $table->index('primary_domain', 'brands_primary_domain_index');
            }
        });
    }

    /** @return array<int, string> */
    private function existingIndexNames(): array
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            return array_values(array_unique(array_map(
                fn ($index) => (string) $index->Key_name,
                DB::select('SHOW INDEX FROM brands')
            )));
        }

        if ($driver === 'sqlite') {
            return array_values(array_unique(array_map(
                fn ($index) => (string) $index->name,
                DB::select("PRAGMA index_list('brands')")
            )));
        }

        return [];
    }
};
