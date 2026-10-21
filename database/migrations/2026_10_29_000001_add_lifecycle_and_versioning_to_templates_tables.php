<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (Additive only: Lifecycle, State, Versioning & Default Consistency).
     */
    public function up(): void
    {
        // 1. Tambah kolom lifecycle pada document_templates jika belum ada
        if (Schema::hasTable('document_templates')) {
            Schema::table('document_templates', function (Blueprint $table) {
                if (!Schema::hasColumn('document_templates', 'state')) {
                    $table->string('state', 30)->default('published')->after('is_default');
                }
                if (!Schema::hasColumn('document_templates', 'published_version')) {
                    $table->unsignedInteger('published_version')->nullable()->after('version_number');
                }
                if (!Schema::hasColumn('document_templates', 'published_at')) {
                    $table->timestamp('published_at')->nullable()->after('published_version');
                }
                if (!Schema::hasColumn('document_templates', 'content_sha256')) {
                    $table->string('content_sha256', 64)->nullable()->after('published_at');
                }
                if (!Schema::hasColumn('document_templates', 'lock_version')) {
                    $table->unsignedInteger('lock_version')->default(1)->after('content_sha256');
                }
            });
        }

        // 2. Tambah kolom default & lifecycle pada mou_templates jika belum ada
        if (Schema::hasTable('mou_templates')) {
            Schema::table('mou_templates', function (Blueprint $table) {
                if (!Schema::hasColumn('mou_templates', 'is_default')) {
                    $table->boolean('is_default')->default(false)->after('is_active');
                }
                if (!Schema::hasColumn('mou_templates', 'state')) {
                    $table->string('state', 30)->default('published')->after('is_default');
                }
                if (!Schema::hasColumn('mou_templates', 'published_version')) {
                    $table->unsignedInteger('published_version')->nullable()->after('version_number');
                }
                if (!Schema::hasColumn('mou_templates', 'published_at')) {
                    $table->timestamp('published_at')->nullable()->after('published_version');
                }
                if (!Schema::hasColumn('mou_templates', 'content_sha256')) {
                    $table->string('content_sha256', 64)->nullable()->after('published_at');
                }
                if (!Schema::hasColumn('mou_templates', 'lock_version')) {
                    $table->unsignedInteger('lock_version')->default(1)->after('content_sha256');
                }
            });
        }

        // 3. Tambah kolom hash & state pada document_template_versions jika belum ada
        if (Schema::hasTable('document_template_versions')) {
            Schema::table('document_template_versions', function (Blueprint $table) {
                if (!Schema::hasColumn('document_template_versions', 'content_sha256')) {
                    $table->string('content_sha256', 64)->nullable()->after('change_summary');
                }
                if (!Schema::hasColumn('document_template_versions', 'state')) {
                    $table->string('state', 30)->default('published')->after('content_sha256');
                }
            });
        }
    }

    /**
     * Reverse the migrations (Non-destructive rollback).
     */
    public function down(): void
    {
        if (Schema::hasTable('document_template_versions')) {
            Schema::table('document_template_versions', function (Blueprint $table) {
                $columns = ['content_sha256', 'state'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('document_template_versions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('mou_templates')) {
            Schema::table('mou_templates', function (Blueprint $table) {
                $columns = ['is_default', 'state', 'published_version', 'published_at', 'content_sha256', 'lock_version'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('mou_templates', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('document_templates')) {
            Schema::table('document_templates', function (Blueprint $table) {
                $columns = ['state', 'published_version', 'published_at', 'content_sha256', 'lock_version'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('document_templates', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
