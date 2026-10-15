<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * S-R02 Modul 10: Tambahkan kolom category dan document_version ke registration_attachments secara aditif.
     */
    public function up(): void
    {
        Schema::table('registration_attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('registration_attachments', 'category')) {
                $table->string('category', 50)->nullable()->after('attachment_type')->comment('Kategori berkas kanonikal (cv, identity, portfolio, dll)');
            }
            if (!Schema::hasColumn('registration_attachments', 'document_version')) {
                $table->unsignedInteger('document_version')->default(1)->after('category')->comment('Versi berkas pendaftar');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registration_attachments', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('registration_attachments', 'category')) {
                $cols[] = 'category';
            }
            if (Schema::hasColumn('registration_attachments', 'document_version')) {
                $cols[] = 'document_version';
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
