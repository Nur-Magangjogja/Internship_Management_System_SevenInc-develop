<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Allow draft fields to be NULL when unanswered without fake defaults.
     */
    public function up(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            $table->string('phone_number', 20)->nullable()->change();
            $table->string('student_id', 50)->nullable()->change();
            $table->unsignedBigInteger('city_id')->nullable()->change();
            $table->unsignedBigInteger('division_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversible schema change
    }
};
