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
        Schema::create('institution_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->onDelete('cascade');
            $table->string('name', 255);
            $table->string('position_title', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('normalized_phone', 30)->nullable()->index();
            $table->string('email', 100)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_contacts');
    }
};
