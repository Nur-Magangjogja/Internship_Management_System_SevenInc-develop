<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('internship_registrations')) {
            // 1. Pastikan data pendaftaran mandiri langsung dari formulir web SertIF tetap berlabel 'web' dan tidak terintegrasi
            DB::table('internship_registrations')
                ->where('registration_type', 'web')
                ->orWhere('registration_provenance', 'direct_web')
                ->update([
                    'is_integrated'           => false,
                    'registration_type'       => 'web',
                    'registration_provenance' => 'direct_web',
                ]);

            // 2. Berikan label dan flag is_integrated = true HANYA untuk pendaftar hasil integrasi/impor/pemberian ID dari spreadsheet
            DB::table('internship_registrations')
                ->where(function ($query) {
                    $query->where('registration_type', 'integrated')
                        ->orWhere('is_outsider', true)
                        ->orWhere('registration_provenance', 'reconciled_sheet')
                        ->orWhere('registration_provenance', 'historical_import');
                })
                ->where(function ($query) {
                    $query->where('registration_type', '!=', 'web')
                        ->orWhereNull('registration_type');
                })
                ->where(function ($query) {
                    $query->where('registration_provenance', '!=', 'direct_web')
                        ->orWhereNull('registration_provenance');
                })
                ->update([
                    'is_integrated'           => true,
                    'registration_type'       => 'integrated',
                    'registration_provenance' => DB::raw("COALESCE(registration_provenance, 'reconciled_sheet')"),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down needed as this is a non-destructive data integrity sync
    }
};
