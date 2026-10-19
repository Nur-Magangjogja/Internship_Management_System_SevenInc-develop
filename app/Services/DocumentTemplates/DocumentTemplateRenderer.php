<?php

namespace App\Services\DocumentTemplates;

use App\Models\DocumentTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class DocumentTemplateRenderer
{
    /**
     * Render HTML lengkap untuk dokumen surat A4 (LOA, SKL, Penilaian, Rekomendasi).
     */
    public static function renderLetterHtml(DocumentTemplate $template, array $data = []): string
    {
        $rawContent = $template->content_html ?? '';
        $resolvedHtml = MailMergePlaceholderContract::resolve($rawContent, $data);
        $sanitizedHtml = static::sanitizeHtml($resolvedHtml);
        $pageConfig = array_merge(DocumentTemplate::getDefaultPageConfig($template->type), $template->page_config ?? []);

        return static::wrapLetterDocumentHtml($sanitizedHtml, $pageConfig, $template->name);
    }

    /**
     * Render HTML lengkap untuk sertifikat berbasis Canvas Layout.
     */
    public static function renderCertificateHtml(DocumentTemplate $template, array $data = []): string
    {
        $layout = array_merge(DocumentTemplate::getDefaultCertificateCanvasLayout(), $template->canvas_layout ?? []);
        $pageConfig = array_merge(DocumentTemplate::getDefaultPageConfig('certificate'), $template->page_config ?? []);
        
        $renderedElementsHtml = static::renderCanvasElementsHtml($layout, $data);
        $bgImage = $layout['canvas']['background_image'] ?? ($pageConfig['background_image'] ?? '/images/certificate/certificate-bg.png');
        $bgColor = $layout['canvas']['background_color'] ?? '#ffffff';
        $orientation = strtolower((string) ($pageConfig['orientation'] ?? 'landscape'));

        return static::wrapCertificateDocumentHtml($renderedElementsHtml, $bgImage, $bgColor, $orientation, $template->name);
    }

    /**
     * Generate Binary PDF menggunakan DomPDF.
     */
    public static function renderPdfBinary(DocumentTemplate $template, array $data = []): string
    {
        if ($template->type === 'certificate' || $template->editor_type === 'visual_canvas') {
            $html = static::renderCertificateHtml($template, $data);
            $paperSize = 'a4';
            $orientation = strtolower((string) ($template->page_config['orientation'] ?? 'landscape'));
        } else {
            $html = static::renderLetterHtml($template, $data);
            $paperSize = strtolower((string) ($template->page_config['paper_size'] ?? 'a4'));
            $orientation = strtolower((string) ($template->page_config['orientation'] ?? 'portrait'));
        }

        $htmlWithBase64 = static::embedLocalImagesAsBase64($html);

        $pdf = Pdf::loadHTML($htmlWithBase64);
        $pdf->setPaper($paperSize, $orientation);
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', false);

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Render elemen-elemen visual canvas sertifikat menjadi layer CSS absolut.
     */
    public static function renderCanvasElementsHtml(array $layout, array $data = []): string
    {
        $elements = $layout['elements'] ?? [];
        $out = '';

        foreach ($elements as $elem) {
            $type = $elem['type'] ?? 'text';
            $x = (float) ($elem['x_percent'] ?? 50.0);
            $y = (float) ($elem['y_percent'] ?? 50.0);
            $zIndex = (int) ($elem['z_index'] ?? 10);
            $align = $elem['align'] ?? 'center';

            $transform = 'translate(-50%, -50%)';
            if ($align === 'left') {
                $transform = 'translate(0, -50%)';
            } elseif ($align === 'right') {
                $transform = 'translate(-100%, -50%)';
            }

            if ($type === 'text') {
                $rawContent = $elem['content'] ?? '';
                $resolved = MailMergePlaceholderContract::resolve($rawContent, $data);
                $fontFamily = htmlspecialchars($elem['font_family'] ?? 'Times New Roman', ENT_QUOTES, 'UTF-8');
                $fontSize = (int) ($elem['font_size_pt'] ?? 14);
                $fontWeight = htmlspecialchars($elem['font_weight'] ?? 'normal', ENT_QUOTES, 'UTF-8');
                $fontStyle = htmlspecialchars($elem['font_style'] ?? 'normal', ENT_QUOTES, 'UTF-8');
                $color = htmlspecialchars($elem['color'] ?? '#000000', ENT_QUOTES, 'UTF-8');
                $width = isset($elem['width_percent']) ? "width: {$elem['width_percent']}%;" : 'max-width: 90%;';
                $underline = !empty($elem['underline']) ? 'text-decoration: underline;' : '';
                $lineHeight = isset($elem['line_height']) ? "line-height: {$elem['line_height']};" : 'line-height: 1.3;';
                $letterSpacing = isset($elem['letter_spacing_px']) ? "letter-spacing: {$elem['letter_spacing_px']}px;" : '';

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; text-align: {$align}; font-family: '{$fontFamily}', serif; font-size: {$fontSize}pt; font-weight: {$fontWeight}; font-style: {$fontStyle}; color: {$color}; z-index: {$zIndex}; {$width} {$underline} {$lineHeight} {$letterSpacing} word-wrap: break-word;\">\n";
                $out .= $resolved . "\n";
                $out .= "</div>\n";
            } elseif ($type === 'image') {
                $src = MailMergePlaceholderContract::resolve($elem['src'] ?? '', $data);
                // Strip tags if img tag was generated by contract
                if (preg_match('/src="([^"]+)"/i', $src, $m)) {
                    $src = $m[1];
                }
                $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                $w = (int) ($elem['width_px'] ?? 120);
                $h = isset($elem['height_px']) ? ((int) $elem['height_px'] . 'px') : 'auto';

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; z-index: {$zIndex}; text-align: {$align};\">\n";
                $out .= "<img src=\"{$src}\" style=\"width: {$w}px; height: {$h}; max-width: 100%; object-fit: contain; display: block; margin: 0 auto;\" alt=\"Elemen Sertifikat\" />\n";
                $out .= "</div>\n";
            } elseif ($type === 'signature_group') {
                $dateText = MailMergePlaceholderContract::resolve($elem['date_text'] ?? '', $data);
                $roleText = MailMergePlaceholderContract::resolve($elem['role_text'] ?? '', $data);
                $nameText = MailMergePlaceholderContract::resolve($elem['name_text'] ?? '', $data);
                $sigImg = MailMergePlaceholderContract::resolve($elem['sig_image'] ?? '', $data);
                if (preg_match('/src="([^"]+)"/i', $sigImg, $m)) $sigImg = $m[1];
                $stampImg = MailMergePlaceholderContract::resolve($elem['stamp_image'] ?? '', $data);
                if (preg_match('/src="([^"]+)"/i', $stampImg, $m)) $stampImg = $m[1];

                $width = isset($elem['width_percent']) ? "width: {$elem['width_percent']}%;" : 'width: 45%;';

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; font-family: 'Times New Roman', serif; {$width}\">\n";
                if ($dateText) $out .= "<p style=\"margin: 0; font-size: 11pt;\">{$dateText}</p>\n";
                if ($roleText) $out .= "<p style=\"margin: 2px 0 0 0; font-size: 11pt;\">{$roleText}</p>\n";
                $out .= "<div style=\"height: 70px; margin: 6px auto; position: relative; width: 220px;\">\n";
                if ($sigImg) $out .= "<img src=\"{$sigImg}\" style=\"height: 60px; width: auto; position: relative; z-index: 10; display: block; margin: 0 auto;\" alt=\"Tanda Tangan\" />\n";
                if ($stampImg) $out .= "<img src=\"{$stampImg}\" style=\"height: 65px; width: auto; position: absolute; left: 15px; top: -5px; opacity: 0.85; z-index: 20;\" alt=\"Stempel\" />\n";
                $out .= "</div>\n";
                if ($nameText) $out .= "<p style=\"margin: 0; font-size: 12pt; font-weight: bold; text-decoration: underline;\">{$nameText}</p>\n";
                $out .= "</div>\n";
            }
        }

        return $out;
    }

    /**
     * Wrapper HTML untuk Dokumen Surat A4.
     */
    public static function wrapLetterDocumentHtml(string $bodyHtml, array $pageConfig, string $title = 'Dokumen'): string
    {
        $mTop = (float) ($pageConfig['margin_top_mm'] ?? 20.0);
        $mBtm = (float) ($pageConfig['margin_bottom_mm'] ?? 20.0);
        $mLft = (float) ($pageConfig['margin_left_mm'] ?? 20.0);
        $mRgt = (float) ($pageConfig['margin_right_mm'] ?? 20.0);
        $pSize = strtolower((string) ($pageConfig['paper_size'] ?? 'a4'));
        $orient = strtolower((string) ($pageConfig['orientation'] ?? 'portrait'));
        $fontFamily = htmlspecialchars($pageConfig['font_family'] ?? 'Times New Roman', ENT_QUOTES, 'UTF-8');
        $fontSize = (int) ($pageConfig['font_size_pt'] ?? 11);
        $lineHeight = (float) ($pageConfig['line_height'] ?? 1.3);

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{$title}</title>
    <style>
        @page {
            size: {$pSize} {$orient};
            margin: {$mTop}mm {$mRgt}mm {$mBtm}mm {$mLft}mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: '{$fontFamily}', Times, serif;
            font-size: {$fontSize}pt;
            line-height: {$lineHeight};
            color: #000000;
            background-color: #ffffff;
        }
        img {
            max-width: 100%;
            height: auto;
        }
        table {
            border-collapse: collapse;
        }
        .page-break {
            page-break-after: always;
            break-after: page;
        }
    </style>
</head>
<body>
    {$bodyHtml}
</body>
</html>
HTML;
    }

    /**
     * Wrapper HTML untuk Dokumen Sertifikat Visual.
     */
    public static function wrapCertificateDocumentHtml(
        string $canvasElementsHtml,
        string $bgImage,
        string $bgColor,
        string $orientation = 'landscape',
        string $title = 'Sertifikat'
    ): string {
        $widthMm = $orientation === 'landscape' ? 297.0 : 210.0;
        $heightMm = $orientation === 'landscape' ? 210.0 : 297.0;

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{$title}</title>
    <style>
        @page {
            size: a4 {$orientation};
            margin: 0;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        html, body {
            margin: 0;
            padding: 0;
            width: {$widthMm}mm;
            height: {$heightMm}mm;
            overflow: hidden;
            background-color: {$bgColor};
        }
        .cert-canvas-container {
            position: relative;
            width: {$widthMm}mm;
            height: {$heightMm}mm;
            background-color: {$bgColor};
            background-image: url('{$bgImage}');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="cert-canvas-container">
        {$canvasElementsHtml}
    </div>
</body>
</html>
HTML;
    }

    /**
     * Sanitasi HTML untuk keamanan dari XSS dan tag berbahaya.
     */
    public static function sanitizeHtml(?string $html): string
    {
        if (empty($html)) return '';

        $cleaned = (string) $html;
        $cleaned = preg_replace('/<!--\[if[\s\S]*?<!\[endif\]-->/i', '', $cleaned);
        $cleaned = preg_replace('/<!--[\s\S]*?-->/', '', $cleaned);

        do {
            $prev = $cleaned;
            $cleaned = preg_replace('/<(script|style|iframe|object|embed|applet|form|svg|math|xml|head|meta|link|base)\b[^>]*>([\s\S]*?)<\/\1>/i', '', $cleaned);
            $cleaned = preg_replace('/<\/?(script|style|iframe|object|embed|applet|form|input|button|textarea|select|meta|link|base|xml)\b[^>]*\/?>/i', '', $cleaned);
        } while ($cleaned !== $prev);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $prevErr = libxml_use_internal_errors(true);
        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="__sanitize_root__">'
            . $cleaned . '</div></body></html>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="__sanitize_root__"]')->item(0);
        if (!$root instanceof DOMElement) return '';

        static::sanitizeDomNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }

    protected static function sanitizeDomNode(DOMNode $parent): void
    {
        $allowedTags = [
            'p', 'div', 'span', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup',
            'ul', 'ol', 'li',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'img', 'a',
        ];

        $children = [];
        foreach ($parent->childNodes as $child) $children[] = $child;

        foreach ($children as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($node->nodeName);
                if (!in_array($tag, $allowedTags, true)) {
                    $parent->removeChild($node);
                    continue;
                }

                if ($node->hasAttributes()) {
                    $attrs = [];
                    foreach ($node->attributes as $attr) $attrs[] = $attr;
                    foreach ($attrs as $attr) {
                        $name = strtolower($attr->name);
                        $val = trim($attr->value);

                        if (str_starts_with($name, 'on')) {
                            $node->removeAttribute($name);
                            continue;
                        }
                        if (in_array($name, ['href', 'src'], true)) {
                            $lowerVal = strtolower($val);
                            if (str_starts_with($lowerVal, 'javascript:') || str_starts_with($lowerVal, 'vbscript:')) {
                                $node->removeAttribute($name);
                                continue;
                            }
                        }
                    }
                }

                static::sanitizeDomNode($node);
            }
        }
    }

    /**
     * Konversi URL gambar lokal menjadi base64 URI agar DomPDF dapat merender secara offline tanpa dependensi remote network.
     */
    public static function embedLocalImagesAsBase64(string $html): string
    {
        return preg_replace_callback('/<img\b([^>]*?)\bsrc=["\']([^"\']+)["\']([^>]*?)>/i', function ($matches) {
            $prefix = $matches[1];
            $src    = $matches[2];
            $suffix = $matches[3];

            if (str_starts_with($src, 'data:image/')) {
                return $matches[0];
            }

            $localPath = null;
            if (str_starts_with($src, '/storage/')) {
                $rel = str_replace('/storage/', '', $src);
                $localPath = storage_path('app/public/' . ltrim($rel, '/'));
            } elseif (str_starts_with($src, '/images/')) {
                $localPath = public_path(ltrim($src, '/'));
            }

            if ($localPath && file_exists($localPath)) {
                $mime = mime_content_type($localPath) ?: 'image/png';
                $base64 = base64_encode(file_get_contents($localPath));
                $dataUri = "data:{$mime};base64,{$base64}";
                return "<img{$prefix}src=\"{$dataUri}\"{$suffix}>";
            }

            return $matches[0];
        }, $html);
    }
}
