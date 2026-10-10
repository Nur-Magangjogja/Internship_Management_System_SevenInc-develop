<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Institution;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('normalized_name', 255)->nullable()->after('name')->index();
        });

        // Backfill normalized_name untuk semua data institusi yang ada
        $institutions = DB::table('institutions')->get();
        foreach ($institutions as $inst) {
            $normalized = Institution::normalizeName($inst->name);
            DB::table('institutions')->where('id', $inst->id)->update([
                'normalized_name' => $normalized,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropIndex(['normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
