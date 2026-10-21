<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. document_templates: certificate_type ('magang', 'webinar')
        if (Schema::hasTable('document_templates') && !Schema::hasColumn('document_templates', 'certificate_type')) {
            Schema::table('document_templates', function (Blueprint $table) {
                $table->string('certificate_type', 30)->nullable()->default('magang')->after('type');
                $table->index(['type', 'certificate_type']);
            });
            DB::table('document_templates')
                ->where('type', 'certificate')
                ->whereNull('certificate_type')
                ->update(['certificate_type' => 'magang']);
        }

        // 2. document_template_versions
        if (Schema::hasTable('document_template_versions') && !Schema::hasColumn('document_template_versions', 'certificate_type')) {
            Schema::table('document_template_versions', function (Blueprint $table) {
                $table->string('certificate_type', 30)->nullable()->default('magang')->after('name');
            });
            DB::table('document_template_versions')
                ->whereNull('certificate_type')
                ->update(['certificate_type' => 'magang']);
        }

        // 3. webinars: certificate_source, certificate_template_id, gdrive_certificate_link
        if (Schema::hasTable('webinars')) {
            Schema::table('webinars', function (Blueprint $table) {
                if (!Schema::hasColumn('webinars', 'certificate_source')) {
                    $table->string('certificate_source', 20)->default('template')->after('brand_id');
                }
                if (!Schema::hasColumn('webinars', 'certificate_template_id')) {
                    $table->foreignId('certificate_template_id')->nullable()->after('certificate_source')->constrained('document_templates')->nullOnDelete();
                }
                if (!Schema::hasColumn('webinars', 'gdrive_certificate_link')) {
                    $table->string('gdrive_certificate_link', 1000)->nullable()->after('certificate_template_id');
                }
            });
        }

        // 4. webinar_certificates: document_template_id, gdrive_link
        if (Schema::hasTable('webinar_certificates')) {
            Schema::table('webinar_certificates', function (Blueprint $table) {
                if (!Schema::hasColumn('webinar_certificates', 'document_template_id')) {
                    $table->foreignId('document_template_id')->nullable()->after('attendance_id')->constrained('document_templates')->nullOnDelete();
                }
                if (!Schema::hasColumn('webinar_certificates', 'gdrive_link')) {
                    $table->string('gdrive_link', 1000)->nullable()->after('document_template_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('webinar_certificates')) {
            Schema::table('webinar_certificates', function (Blueprint $table) {
                if (Schema::hasColumn('webinar_certificates', 'document_template_id')) {
                    $table->dropForeign(['document_template_id']);
                    $table->dropColumn('document_template_id');
                }
                if (Schema::hasColumn('webinar_certificates', 'gdrive_link')) {
                    $table->dropColumn('gdrive_link');
                }
            });
        }

        if (Schema::hasTable('webinars')) {
            Schema::table('webinars', function (Blueprint $table) {
                if (Schema::hasColumn('webinars', 'certificate_template_id')) {
                    $table->dropForeign(['certificate_template_id']);
                    $table->dropColumn('certificate_template_id');
                }
                if (Schema::hasColumn('webinars', 'certificate_source')) {
                    $table->dropColumn('certificate_source');
                }
                if (Schema::hasColumn('webinars', 'gdrive_certificate_link')) {
                    $table->dropColumn('gdrive_certificate_link');
                }
            });
        }

        if (Schema::hasTable('document_template_versions') && Schema::hasColumn('document_template_versions', 'certificate_type')) {
            Schema::table('document_template_versions', function (Blueprint $table) {
                $table->dropColumn('certificate_type');
            });
        }

        if (Schema::hasTable('document_templates') && Schema::hasColumn('document_templates', 'certificate_type')) {
            Schema::table('document_templates', function (Blueprint $table) {
                $table->dropIndex(['type', 'certificate_type']);
                $table->dropColumn('certificate_type');
            });
        }
    }
};
