<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\DocumentTemplate;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $templates = DocumentTemplate::all();
        foreach ($templates as $t) {
            $changed = false;
            $html = $t->content_html;
            if ($html && str_contains($html, 'perusahaan.nama') && str_contains($html, 'asset.company.logo')) {
                // If kop surat has only 2 td, balance it with the 3rd td
                $newHtml = preg_replace_callback('/<table[^>]*>[\s\S]*?<\/table>/i', function ($matches) {
                    $table = $matches[0];
                    if (str_contains($table, 'perusahaan.nama') && substr_count($table, '<td') === 2) {
                        return preg_replace('/(<\/td>\s*<\/tr>)/i', '$1' . "\n        <td style=\"width: 110px; vertical-align: middle;\">&nbsp;</td>\n    </tr>", substr($table, 0, strrpos($table, '</tr>')));
                    }
                    return $table;
                }, $html);

                if ($newHtml !== $html) {
                    $t->content_html = $newHtml;
                    $changed = true;
                }
            }

            // Also ensure show_watermark is true and show_header_logos is false
            $cfg = $t->page_config ?? [];
            if (($cfg['show_watermark'] ?? null) !== true || ($cfg['show_header_logos'] ?? null) !== false) {
                $cfg['show_watermark'] = true;
                $cfg['show_header_logos'] = false;
                $cfg['watermark_opacity'] = 0.05;
                $t->page_config = $cfg;
                $changed = true;
            }

            if ($changed) {
                $t->saveQuietly();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
