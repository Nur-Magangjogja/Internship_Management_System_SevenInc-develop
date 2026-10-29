<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('brands')) {
            return;
        }

        if (!Schema::hasColumn('brands', 'division_mode')) {
            Schema::table('brands', function (Blueprint $table) {
                // NULL preserves legacy global behavior until a mode is explicitly set.
                // Brand creation through Admin sets brand_specific unless explicitly configured otherwise.
                $table->string('division_mode', 32)->nullable()->after('name');
            });
        }

        // Existing records with explicit pivot mappings are brand-specific. Leave records
        // without mappings as NULL so the service can preserve their prior global fallback.
        // BrandSeeder will mark its mapped official brands as brand-specific after sync.
        if (Schema::hasTable('brand_divisions')) {
            $mappedBrandIds = DB::table('brand_divisions')->distinct()->pluck('brand_id')->all();
            if (!empty($mappedBrandIds)) {
                DB::table('brands')->whereIn('id', $mappedBrandIds)->whereNull('division_mode')->update([
                    'division_mode' => 'brand_specific',
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('brands') && Schema::hasColumn('brands', 'division_mode')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->dropColumn('division_mode');
            });
        }
    }
};
