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
        if (!Schema::hasTable('brand_divisions')) {
            Schema::create('brand_divisions', function (Blueprint $table) {
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->foreignId('division_id')->constrained('divisions')->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['brand_id', 'division_id']);
                $table->index('brand_id');
                $table->index('division_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_divisions');
    }
};
