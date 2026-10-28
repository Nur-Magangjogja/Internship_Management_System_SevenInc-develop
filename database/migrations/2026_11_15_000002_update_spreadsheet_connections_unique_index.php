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
        Schema::table('spreadsheet_connections', function (Blueprint $table) {
            $table->dropUnique('spreadsheet_connections_key_brand_unique');
            $table->dropIndex(['connection_key']);
            $table->unique('connection_key', 'spreadsheet_connections_connection_key_unique');
        });
    }
};
