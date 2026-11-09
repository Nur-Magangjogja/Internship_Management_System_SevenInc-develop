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
        // Drop projection tables in correct dependency order
        Schema::dropIfExists('spreadsheet_search_documents');
        Schema::dropIfExists('spreadsheet_search_index_runs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tables were intentionally removed as unused feature
    }
};
