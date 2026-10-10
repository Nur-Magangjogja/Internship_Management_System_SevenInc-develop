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
        Schema::table('internship_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('internship_registrations', 'birth_place')) {
                $table->string('birth_place', 100)->nullable()->after('born_date');
            }
            if (!Schema::hasColumn('internship_registrations', 'religion')) {
                $table->string('religion', 50)->nullable()->after('gender');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('internship_registrations', 'birth_place')) {
                $table->dropColumn('birth_place');
            }
            if (Schema::hasColumn('internship_registrations', 'religion')) {
                $table->dropColumn('religion');
            }
        });
    }
};
