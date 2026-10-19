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
        if (!Schema::hasTable('document_templates')) {
            Schema::create('document_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('type', 40); // loa, skl, certificate, assessment, recommendation
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('editor_type', 30)->default('visual_doc'); // visual_doc, visual_canvas
                $table->longText('content_html')->nullable();
                $table->json('editor_json')->nullable();
                $table->json('canvas_layout')->nullable();
                $table->json('page_config')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('version_number')->default(1);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['type', 'is_active']);
                $table->index(['type', 'is_default']);
            });
        }

        if (!Schema::hasTable('document_template_versions')) {
            Schema::create('document_template_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
                $table->unsignedInteger('version_number');
                $table->string('name', 255);
                $table->longText('content_html')->nullable();
                $table->json('editor_json')->nullable();
                $table->json('canvas_layout')->nullable();
                $table->json('page_config')->nullable();
                $table->text('change_summary')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['document_template_id', 'version_number'], 'doc_tpl_ver_id_num_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
    }
};
