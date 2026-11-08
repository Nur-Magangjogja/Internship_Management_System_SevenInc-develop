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
        if (Schema::hasTable('historical_import_batches')) {
            Schema::table('historical_import_batches', function (Blueprint $table) {
                if (!Schema::hasColumn('historical_import_batches', 'brand_id')) {
                    $table->foreignId('brand_id')
                        ->nullable()
                        ->after('target_year')
                        ->constrained('brands')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn('historical_import_batches', 'spreadsheet_connection_id')) {
                    $table->foreignId('spreadsheet_connection_id')
                        ->nullable()
                        ->after('brand_id')
                        ->constrained('spreadsheet_connections')
                        ->nullOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('historical_import_batches')) {
            Schema::table('historical_import_batches', function (Blueprint $table) {
                if (Schema::hasColumn('historical_import_batches', 'spreadsheet_connection_id')) {
                    $table->dropForeign(['spreadsheet_connection_id']);
                    $table->dropColumn('spreadsheet_connection_id');
                }

                if (Schema::hasColumn('historical_import_batches', 'brand_id')) {
                    $table->dropForeign(['brand_id']);
                    $table->dropColumn('brand_id');
                }
            });
        }
    }
};
