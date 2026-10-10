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
        Schema::create('spreadsheet_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('connection_key', 50)->unique()->comment('registration | followup');
            $table->string('display_name', 255);
            $table->string('spreadsheet_url', 500)->nullable();
            $table->string('spreadsheet_id', 255)->nullable();
            $table->string('apps_script_endpoint_url', 500)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->string('connection_status', 50)->default('untested')->comment('untested, not_configured, pending_transport, connected, error');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_successful_test_at')->nullable();
            $table->text('last_error_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_connections');
    }
};
