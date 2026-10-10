<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->uuid('public_uuid')->nullable()->unique()->after('id');
            $table->text('address')->nullable()->after('name');
            $table->string('phone', 30)->nullable()->after('address');
            $table->string('email', 100)->nullable()->after('phone');
            $table->string('fax', 30)->nullable()->after('email');
            $table->string('correspondence_unit', 100)->nullable()->after('fax');
            $table->boolean('is_active')->default(true)->after('correspondence_unit')->index();
            $table->text('inactive_reason')->nullable()->after('is_active');
            $table->timestamps();
        });

        // Backfill public_uuid dan timestamps untuk baris existing
        $institutions = DB::table('institutions')->get();
        $now = now();
        foreach ($institutions as $inst) {
            DB::table('institutions')
                ->where('id', $inst->id)
                ->update([
                    'public_uuid' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'public_uuid',
                'address',
                'phone',
                'email',
                'fax',
                'correspondence_unit',
                'is_active',
                'inactive_reason',
                'created_at',
                'updated_at',
            ]);
        });
    }
};
