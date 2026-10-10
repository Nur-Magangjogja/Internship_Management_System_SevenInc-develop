<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * History-Safe Schema Evolution for 2026 Registration Contract.
     */
    public function up(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            // 1. Relax legacy and draft columns to nullable without fake defaults
            $table->date('born_date')->nullable()->change();
            $table->string('english_book_ability', 50)->nullable()->change();
            $table->string('internship_arrangement', 50)->nullable()->change();
            $table->string('internship_type', 50)->nullable()->change();
            $table->string('current_status', 100)->nullable()->change();
            $table->string('boarding_info', 50)->nullable()->change();
            $table->string('gender', 20)->nullable()->change();
            $table->text('internship_reason')->nullable()->change();

            // 2. Add new 2026 contract storage fields
            $table->string('wfo_readiness', 100)->nullable()->after('current_status');
            $table->string('schedule_shift_readiness', 100)->nullable()->after('wfo_readiness');
            $table->boolean('has_laptop')->nullable()->after('schedule_shift_readiness');
            $table->string('planned_duration', 50)->nullable()->after('start_date');
        });

        // 3. Create normalized registration_attachments table for local persistence
        // Uses restrictOnDelete to strictly preserve attachment history (No cascade delete)
        Schema::create('registration_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_registration_id')
                  ->constrained('internship_registrations')
                  ->restrictOnDelete();
            $table->string('attachment_type', 50);
            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 100);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_attachments');

        Schema::table('internship_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'wfo_readiness',
                'schedule_shift_readiness',
                'has_laptop',
                'planned_duration',
            ]);
        });
    }
};
