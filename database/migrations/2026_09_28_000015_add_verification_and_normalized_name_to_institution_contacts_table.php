<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('institution_contacts', function (Blueprint $table) {
            $table->string('normalized_name', 255)->nullable()->after('name')->index();
            $table->boolean('is_verified')->default(true)->after('is_active');
            $table->foreignId('verified_by')->nullable()->after('is_verified')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });

        // Backfill normalized_name untuk kontak yang sudah ada
        $contacts = DB::table('institution_contacts')->select('id', 'name')->get();
        foreach ($contacts as $contact) {
            $clean = mb_strtolower(trim($contact->name));
            $clean = preg_replace('/[.,\-_()\/]/', ' ', $clean);
            $clean = preg_replace('/\s+/', ' ', trim($clean));
            DB::table('institution_contacts')->where('id', $contact->id)->update([
                'normalized_name' => $clean,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institution_contacts', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['normalized_name', 'is_verified', 'verified_by', 'verified_at']);
        });
    }
};
