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
        Schema::create('registration_contact_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('internship_registration_id')->constrained('internship_registrations')->onDelete('cascade');
            $table->foreignId('institution_contact_id')->constrained('institution_contacts')->onDelete('cascade');
            $table->unsignedSmallInteger('relation_year')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->string('source', 50)->default('registration_form');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['internship_registration_id', 'institution_contact_id'], 'reg_contact_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_contact_links');
    }
};
