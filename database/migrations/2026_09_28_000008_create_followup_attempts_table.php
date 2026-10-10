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
        Schema::create('followup_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('followup_cycle_id')->constrained('followup_cycles')->onDelete('cascade');
            $table->unsignedTinyInteger('attempt_number');
            $table->foreignId('performed_by')->constrained('users')->onDelete('restrict');
            $table->dateTime('followed_up_at');
            $table->string('channel', 50)->default('whatsapp');
            $table->string('result', 50);
            $table->text('response_text')->nullable();
            $table->text('notes')->nullable();
            $table->date('next_followup_at')->nullable();
            $table->timestamps();

            $table->unique(['followup_cycle_id', 'attempt_number'], 'cycle_attempt_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('followup_attempts');
    }
};
