<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase S17.18C: Add followup_status to internship_registrations.
     */
    public function up(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            $table->string('followup_status', 50)->nullable()->default(null)->after('internship_status')->index()->comment('8 stable follow-up status codes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            $table->dropIndex(['followup_status']);
            $table->dropColumn('followup_status');
        });
    }
};
