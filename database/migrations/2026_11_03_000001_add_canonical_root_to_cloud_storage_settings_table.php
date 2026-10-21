<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add canonical parent root columns to cloud_storage_settings for Master Plan V1 Addendum Final V4.
     */
    public function up(): void
    {
        Schema::table('cloud_storage_settings', function (Blueprint $table) {
            $table->string('canonical_parent_folder_url', 500)->nullable()->after('provider');
            $table->string('canonical_parent_folder_id', 255)->nullable()->after('canonical_parent_folder_url');
            $table->boolean('is_verified')->default(false)->after('is_enabled');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
            $table->text('validation_error')->nullable()->after('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloud_storage_settings', function (Blueprint $table) {
            $table->dropColumn([
                'canonical_parent_folder_url',
                'canonical_parent_folder_id',
                'is_verified',
                'verified_at',
                'validation_error',
            ]);
        });
    }
};
