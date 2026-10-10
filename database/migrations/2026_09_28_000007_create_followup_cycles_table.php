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
        Schema::create('followup_cycles', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->onDelete('cascade');
            $table->foreignId('institution_contact_id')->constrained('institution_contacts')->onDelete('cascade');
            $table->unsignedSmallInteger('cycle_year')->index();
            $table->string('status', 50)->default('open')->index();
            $table->string('final_result', 50)->nullable()->index();
            $table->text('final_notes')->nullable();
            $table->unsignedTinyInteger('current_attempt_count')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('lock_expires_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'institution_contact_id', 'cycle_year'], 'cycle_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('followup_cycles');
    }
};
