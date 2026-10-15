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
        if (!Schema::hasTable('user_account_activations')) {
            Schema::create('user_account_activations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('last_notification_requested_at')->nullable();
                $table->unsignedInteger('resend_count')->default(0);
                $table->timestamps();

                // Unique: Satu akun user hanya memiliki satu baris state aktivasi
                $table->unique('user_id', 'uk_user_act_user_id');

                // Foreign key dengan ON DELETE RESTRICT untuk integritas akun
                $table->foreign('user_id', 'fk_user_act_user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('restrict');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_account_activations');
    }
};
