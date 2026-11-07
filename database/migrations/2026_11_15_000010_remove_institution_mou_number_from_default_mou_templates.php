<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('mou_templates')) {
            return;
        }

        $templates = DB::table('mou_templates')
            ->where('template_html', 'LIKE', '%[institution_mou_number]%')
            ->get(['id', 'template_html']);

        foreach ($templates as $tpl) {
            $updatedHtml = preg_replace(
                '/<p[^>]*>\s*<strong>\s*NOMOR:\s*\[institution_mou_number\]\s*<\/strong>\s*<\/p>\s*/i',
                '',
                $tpl->template_html
            );

            if ($updatedHtml !== null && $updatedHtml !== $tpl->template_html) {
                DB::table('mou_templates')
                    ->where('id', $tpl->id)
                    ->update([
                        'template_html' => $updatedHtml,
                        'updated_at'    => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversal is not strictly necessary as this is a content sanitation migration.
    }
};
