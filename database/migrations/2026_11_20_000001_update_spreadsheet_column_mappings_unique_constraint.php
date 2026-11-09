<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 1: Drop header unique constraint to allow duplicate headers (e.g. multi-KET),
     * backfill legacy column_identity with unique physical anchors, and add unique constraint on column_identity.
     */
    public function up(): void
    {
        // 1. Backfill and disambiguate legacy records so column_identity is physical & strictly unique per schema version
        $allRows = DB::table('spreadsheet_column_mappings')->orderBy('id')->get();
        $seen = [];

        foreach ($allRows as $row) {
            $verId = $row->spreadsheet_schema_version_id;
            $currentIdentity = trim((string) $row->column_identity);
            $cleanHeader = Str::slug(trim($row->sheet_header ?: 'col'), '_');
            $canonicalPhysicalId = 'cid_' . substr(hash('sha256', "col_{$verId}_{$row->column_order}_{$cleanHeader}"), 0, 16);

            $key = "{$verId}:{$currentIdentity}";

            // If empty, or already seen within this schema version (e.g. shared target field key), replace with physical ID
            if ($currentIdentity === '' || isset($seen[$key])) {
                DB::table('spreadsheet_column_mappings')
                    ->where('id', $row->id)
                    ->update([
                        'column_identity' => $canonicalPhysicalId,
                        'mapping_status'  => $row->mapping_status ?: 'provisional',
                    ]);
                $key = "{$verId}:{$canonicalPhysicalId}";
            }

            $seen[$key] = true;
        }

        // 2. Safely modify database constraints
        $existingIndexes = collect(DB::select("SHOW INDEXES FROM spreadsheet_column_mappings"))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('spreadsheet_column_mappings', function (Blueprint $table) use ($existingIndexes) {
            if (in_array('map_ver_header_unique', $existingIndexes, true)) {
                $table->dropUnique('map_ver_header_unique');
            }

            if (!in_array('map_ver_col_identity_unique', $existingIndexes, true)) {
                $table->unique(['spreadsheet_schema_version_id', 'column_identity'], 'map_ver_col_identity_unique');
            }

            if (!in_array('map_ver_sheet_header_idx', $existingIndexes, true)) {
                $table->index(['spreadsheet_schema_version_id', 'sheet_header'], 'map_ver_sheet_header_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $existingIndexes = collect(DB::select("SHOW INDEXES FROM spreadsheet_column_mappings"))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('spreadsheet_column_mappings', function (Blueprint $table) use ($existingIndexes) {
            if (in_array('map_ver_sheet_header_idx', $existingIndexes, true)) {
                $table->dropIndex('map_ver_sheet_header_idx');
            }

            if (in_array('map_ver_col_identity_unique', $existingIndexes, true)) {
                $table->dropUnique('map_ver_col_identity_unique');
            }

            if (!in_array('map_ver_header_unique', $existingIndexes, true)) {
                // Check if any schema version has duplicate sheet_headers before restoring unique
                $hasDuplicates = DB::table('spreadsheet_column_mappings')
                    ->select('spreadsheet_schema_version_id', 'sheet_header', DB::raw('count(*) as c'))
                    ->groupBy('spreadsheet_schema_version_id', 'sheet_header')
                    ->having('c', '>', 1)
                    ->exists();

                if (!$hasDuplicates) {
                    $table->unique(['spreadsheet_schema_version_id', 'sheet_header'], 'map_ver_header_unique');
                }
            }
        });
    }
};
