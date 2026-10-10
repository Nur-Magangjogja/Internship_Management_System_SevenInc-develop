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
        Schema::create('mous', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();

            // Relasi Institusi & Siklus
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('followup_cycle_id')->nullable()->constrained('followup_cycles')->nullOnDelete();
            $table->foreignId('mou_template_id')->nullable()->constrained('mou_templates')->nullOnDelete();

            // Nomor Registrasi Perjanjian
            $table->string('company_mou_number', 100)->unique();
            $table->string('institution_mou_number', 100)->nullable();

            // Snapshot Pihak Pertama (Institusi)
            $table->string('first_party_institution_name', 255);
            $table->string('first_party_signatory_name', 255);
            $table->string('first_party_signatory_title', 255);
            $table->text('first_party_address')->nullable();
            $table->string('first_party_phone', 100)->nullable();
            $table->string('first_party_email', 150)->nullable();
            $table->string('first_party_correspondence_unit', 255)->nullable();

            // Snapshot Pihak Kedua (Seven Inc)
            $table->string('second_party_company_name', 255);
            $table->string('second_party_signatory_name', 255);
            $table->string('second_party_signatory_title', 255);
            $table->text('second_party_address')->nullable();
            $table->string('second_party_phone', 100)->nullable();
            $table->string('second_party_email', 150)->nullable();

            // Tempat, Tanggal, & Masa Berlaku
            $table->string('agreement_place', 100)->default('Yogyakarta');
            $table->date('agreement_date');
            $table->date('effective_start')->nullable();
            $table->date('effective_end')->nullable();

            // Status Lifecycle
            $table->enum('status', [
                'draft',
                'generated',
                'delivered',
                'active',
                'expired',
                'superseded',
                'cancelled'
            ])->default('generated')->index();

            // Berkas Dokumen & Konten HTML Tersimpan
            $table->longText('rendered_document_html')->nullable();
            $table->string('generated_file_path', 500)->nullable();
            $table->string('signed_file_path', 500)->nullable();

            // Lifecycle Timestamps
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('signed_at')->nullable();

            // Detail Pengiriman / Serah Terima
            $table->string('delivery_method', 50)->nullable();
            $table->string('delivery_recipient', 255)->nullable();
            $table->text('delivery_notes')->nullable();

            // Aktor Pengguna
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Metadata & Pergantian Dokumen (Supersede)
            $table->json('render_data_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->text('supersede_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mous');
    }
};
