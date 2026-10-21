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
        if (!Schema::hasColumn('institutions', 'logo')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->string('logo', 500)->nullable()->after('correspondence_unit');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('institutions', 'logo')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->dropColumn('logo');
            });
        }
    }
};
