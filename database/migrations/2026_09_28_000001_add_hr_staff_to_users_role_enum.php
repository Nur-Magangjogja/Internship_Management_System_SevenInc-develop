<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'hr_staff' to the users.role ENUM definition
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin', 'pemagang', 'user', 'hr_staff') NOT NULL DEFAULT 'pemagang'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original ENUM definition without 'hr_staff'
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin', 'pemagang', 'user') NOT NULL DEFAULT 'pemagang'");
    }
};
