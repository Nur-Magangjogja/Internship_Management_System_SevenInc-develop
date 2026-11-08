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
        if (!Schema::hasTable('form_submit_settings')) {
            Schema::create('form_submit_settings', function (Blueprint $table) {
                $table->id();
                $table->smallInteger('year')->default(2026)->index();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->string('section_title', 255)->default('Finalisasi & Submit');
                $table->string('section_subtitle', 255)->default('Tinjau ketentuan program magang dan kirim formulir pendaftaran Anda.');
                $table->boolean('show_notice_box')->default(true);
                $table->string('notice_title', 100)->default('Perhatian:');
                $table->text('notice_text')->nullable();
                $table->string('admin_wa_number', 50)->default('0895 2900 2944');
                $table->string('admin_wa_message', 255)->default('SAYA SUDAH ISI FORM');
                $table->string('submit_button_text', 100)->default('Kirim Pendaftaran');
                $table->boolean('save_draft_enabled')->default(true);
                $table->timestamps();

                $table->index(['year', 'brand_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_submit_settings');
    }
};
