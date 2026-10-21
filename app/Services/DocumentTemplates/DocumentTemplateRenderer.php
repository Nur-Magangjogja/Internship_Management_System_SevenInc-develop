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
     * Ekstraksi seluruh token variabel ({{token}} dan [token]) yang terdapat pada template.
     */
    public static function extractTokens(DocumentTemplate $template): array
    {
        $tokens = [];

        // 1. Ekstraksi dari content_html (Dokumen Surat A4)
        if (!empty($template->content_html)) {
            if (preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}|\[\s*([a-zA-Z0-9_\.]+)\s*\]/', $template->content_html, $matches)) {
                foreach ($matches[1] as $idx => $m1) {
                    $token = $m1 !== '' ? $m1 : ($matches[2][$idx] ?? '');
                    if ($token !== '') {
                        $tokens[] = $token;
                    }
                }
            }
        }

        // 2. Ekstraksi dari canvas_layout (Sertifikat Visual Canvas)
        if (!empty($template->canvas_layout) && is_array($template->canvas_layout)) {
            $elements = $template->canvas_layout['elements'] ?? [];
            foreach ($elements as $elem) {
                $fieldsToCheck = ['content', 'date_text', 'role_text', 'name_text', 'sig_image', 'stamp_image', 'src'];
                foreach ($fieldsToCheck as $f) {
                    if (!empty($elem[$f]) && is_string($elem[$f])) {
                        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}|\[\s*([a-zA-Z0-9_\.]+)\s*\]/', $elem[$f], $elemMatches)) {
                            foreach ($elemMatches[1] as $idx => $m1) {
                                $token = $m1 !== '' ? $m1 : ($elemMatches[2][$idx] ?? '');
                                if ($token !== '') {
                                    $tokens[] = $token;
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Render HTML lengkap untuk dokumen surat A4 (LOA, SKL, Penilaian, Rekomendasi).
     */
    public static function renderLetterHtml(DocumentTemplate $template, array $data = [], bool $isLiveIssuance = false, bool $isForPdf = false): string
    {
        $rawContent = $template->content_html ?? '';
        $resolvedHtml = MailMergePlaceholderContract::resolve($rawContent, $data, $isLiveIssuance);
        $sanitizedHtml = static::sanitizeHtml($resolvedHtml);
        $pageConfig = array_merge(DocumentTemplate::getDefaultPageConfig($template->type), $template->page_config ?? []);

        return static::wrapLetterDocumentHtml($sanitizedHtml, $pageConfig, $template->name, $isForPdf);
    }

    /**
     * Render HTML lengkap untuk sertifikat berbasis Canvas Layout.
     */
    public static function renderCertificateHtml(DocumentTemplate $template, array $data = [], bool $isLiveIssuance = false, bool $isForPdf = false): string
    {
        $layout = array_merge(DocumentTemplate::getDefaultCertificateCanvasLayout(), $template->canvas_layout ?? []);
        $pageConfig = array_merge(DocumentTemplate::getDefaultPageConfig('certificate'), $template->page_config ?? []);
        
        $renderedElementsHtml = static::renderCanvasElementsHtml($layout, $data, $isLiveIssuance);
        $rawBg = $layout['canvas']['background_image'] ?? ($pageConfig['background_image'] ?? '/images/certificate/certificate-bg.png');
        $bgImage = static::resolveImageToBase64($rawBg) ?? $rawBg;
        $bgColor = $layout['canvas']['background_color'] ?? '#ffffff';
        $orientation = strtolower((string) ($pageConfig['orientation'] ?? 'landscape'));

        return static::wrapCertificateDocumentHtml($renderedElementsHtml, $bgImage, $bgColor, $orientation, $template->name, $isForPdf);
    }

    /**
     * Generate Binary PDF menggunakan DomPDF.
     */
    public static function renderPdfBinary(DocumentTemplate $template, array $data = [], bool $isLiveIssuance = false): string
    {
        if ($template->type === 'certificate' || $template->editor_type === 'visual_canvas') {
            $html = static::renderCertificateHtml($template, $data, $isLiveIssuance, isForPdf: true);
            $paperSize = 'a4';
            $orientation = strtolower((string) ($template->page_config['orientation'] ?? 'landscape'));
        } else {
            $html = static::renderLetterHtml($template, $data, $isLiveIssuance, isForPdf: true);
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
    public static function renderCanvasElementsHtml(array $layout, array $data = [], bool $isLiveIssuance = false): string
    {
        $elements = $layout['elements'] ?? [];
        $out = '';

        foreach ($elements as $elem) {
            $type = $elem['type'] ?? 'text';
            $x = (float) ($elem['x_percent'] ?? 50.0);
            $y = (float) ($elem['y_percent'] ?? 50.0);
            $zIndex = (int) ($elem['z_index'] ?? 10);
            $align = $elem['align'] ?? 'center';
            $rotation = (float) ($elem['rotation'] ?? 0.0);
            $opacity = isset($elem['opacity']) ? (float) $elem['opacity'] : 1.0;

            $baseTransform = 'translate(-50%, -50%)';
            if ($align === 'left') {
                $baseTransform = 'translate(0, -50%)';
            } elseif ($align === 'right') {
                $baseTransform = 'translate(-100%, -50%)';
            }

            $transform = $baseTransform;
            if ($rotation !== 0.0) {
                $transform .= " rotate({$rotation}deg)";
            }

            $opacityStyle = $opacity < 1.0 ? "opacity: {$opacity};" : '';

            if ($type === 'text') {
                $rawContent = $elem['content'] ?? '';
                $resolved = MailMergePlaceholderContract::resolve($rawContent, $data, $isLiveIssuance);
                $fontFamily = htmlspecialchars($elem['font_family'] ?? 'Times New Roman', ENT_QUOTES, 'UTF-8');
                $fontSize = (int) ($elem['font_size_pt'] ?? 14);
                $fontWeight = htmlspecialchars($elem['font_weight'] ?? 'normal', ENT_QUOTES, 'UTF-8');
                $fontStyle = htmlspecialchars($elem['font_style'] ?? 'normal', ENT_QUOTES, 'UTF-8');
                $color = htmlspecialchars($elem['color'] ?? '#000000', ENT_QUOTES, 'UTF-8');
                $width = isset($elem['width_percent']) ? "width: {$elem['width_percent']}%;" : 'max-width: 90%;';
                $underline = !empty($elem['underline']) ? 'text-decoration: underline;' : '';
                $lineHeight = isset($elem['line_height']) ? "line-height: {$elem['line_height']};" : 'line-height: 1.3;';
                $letterSpacing = isset($elem['letter_spacing_px']) ? "letter-spacing: {$elem['letter_spacing_px']}px;" : '';

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; text-align: {$align}; font-family: '{$fontFamily}', serif; font-size: {$fontSize}pt; font-weight: {$fontWeight}; font-style: {$fontStyle}; color: {$color}; z-index: {$zIndex}; {$width} {$underline} {$lineHeight} {$letterSpacing} {$opacityStyle} word-wrap: break-word;\">\n";
                $out .= $resolved . "\n";
                $out .= "</div>\n";
            } elseif ($type === 'image') {
                $src = MailMergePlaceholderContract::resolve($elem['src'] ?? '', $data, $isLiveIssuance);
                // Strip tags if img tag was generated by contract
                if (preg_match('/src="([^"]+)"/i', $src, $m)) {
                    $src = $m[1];
                }
                $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                
                // Prioritaskan width_percent hasil kustomisasi visual studio, fallback ke width_px, lalu default 16.5% (~140px)
                $w = (isset($elem['width_percent']) && is_numeric($elem['width_percent']))
                    ? "{$elem['width_percent']}%" 
                    : (isset($elem['width_px']) ? ((int) $elem['width_px'] . 'px') : '16.5%');
                    
                $h = (isset($elem['height_percent']) && is_numeric($elem['height_percent']))
                    ? "{$elem['height_percent']}%" 
                    : (isset($elem['height_px']) ? ((int) $elem['height_px'] . 'px') : 'auto');

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; width: {$w}; height: {$h}; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; {$opacityStyle} box-sizing: border-box;\">\n";
                $out .= "<table style=\"width: 100%; height: 100%; border-collapse: collapse; margin: 0; padding: 0; border: none;\"><tr><td style=\"vertical-align: middle; text-align: {$align}; padding: 0; margin: 0; border: none;\">\n";
                $out .= "<img src=\"{$src}\" style=\"max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; display: inline-block; vertical-align: middle; margin: 0 auto;\" alt=\"Elemen Sertifikat\" />\n";
                $out .= "</td></tr></table>\n";
                $out .= "</div>\n";
            } elseif ($type === 'shape') {
                $bgColor = htmlspecialchars($elem['bg_color'] ?? '#f1f5f9', ENT_QUOTES, 'UTF-8');
                $borderColor = htmlspecialchars($elem['border_color'] ?? '#cbd5e1', ENT_QUOTES, 'UTF-8');
                $borderWidth = (int) ($elem['border_width_px'] ?? 1);
                $borderStyle = htmlspecialchars($elem['border_style'] ?? 'solid', ENT_QUOTES, 'UTF-8');
                $borderRadius = (int) ($elem['border_radius_px'] ?? 4);
                $w = isset($elem['width_px']) ? ((int) $elem['width_px'] . 'px') : (isset($elem['width_percent']) ? "{$elem['width_percent']}%" : '200px');
                $h = isset($elem['height_px']) ? ((int) $elem['height_px'] . 'px') : (isset($elem['height_percent']) ? "{$elem['height_percent']}%" : '80px');

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; width: {$w}; height: {$h}; transform: {$transform}; background-color: {$bgColor}; border: {$borderWidth}px {$borderStyle} {$borderColor}; border-radius: {$borderRadius}px; z-index: {$zIndex}; {$opacityStyle} box-sizing: border-box;\"></div>\n";
            } elseif ($type === 'line') {
                $lineColor = htmlspecialchars($elem['line_color'] ?? '#94a3b8', ENT_QUOTES, 'UTF-8');
                $thickness = (int) ($elem['thickness_px'] ?? 2);
                $lineStyle = htmlspecialchars($elem['line_style'] ?? 'solid', ENT_QUOTES, 'UTF-8');
                $w = isset($elem['width_px']) ? ((int) $elem['width_px'] . 'px') : (isset($elem['width_percent']) ? "{$elem['width_percent']}%" : '60%');

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; width: {$w}; height: 0px; transform: {$transform}; border-top: {$thickness}px {$lineStyle} {$lineColor}; z-index: {$zIndex}; {$opacityStyle}\"></div>\n";
            } elseif ($type === 'qr_code') {
                $rawQrData = $elem['qr_data'] ?? ($elem['content'] ?? '{{dokumen.nomor}}');
                $resolvedQrData = MailMergePlaceholderContract::resolve($rawQrData, $data, $isLiveIssuance);
                $sizePx = (int) ($elem['size_px'] ?? 90);
                $fgColor = $elem['fg_color'] ?? '#000000';
                $bgColor = $elem['bg_color'] ?? '#ffffff';

                $qrDataUri = OfflineQrCodeGenerator::renderDataUri($resolvedQrData, $sizePx, $fgColor, $bgColor);

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; {$opacityStyle}\">\n";
                $out .= "<img src=\"{$qrDataUri}\" style=\"width: {$sizePx}px; height: {$sizePx}px; display: block; margin: 0 auto;\" alt=\"QR Code Verifikasi\" />\n";
                $out .= "</div>\n";
            } elseif ($type === 'signature_group') {
                $dateText = MailMergePlaceholderContract::resolve($elem['date_text'] ?? '', $data, $isLiveIssuance);
                $roleText = MailMergePlaceholderContract::resolve($elem['role_text'] ?? '', $data, $isLiveIssuance);
                $nameText = MailMergePlaceholderContract::resolve($elem['name_text'] ?? '', $data, $isLiveIssuance);
                $sigImg = MailMergePlaceholderContract::resolve($elem['sig_image'] ?? '', $data, $isLiveIssuance);
                if (preg_match('/src="([^"]+)"/i', $sigImg, $m)) $sigImg = $m[1];
                $stampImg = MailMergePlaceholderContract::resolve($elem['stamp_image'] ?? '', $data, $isLiveIssuance);
                if (preg_match('/src="([^"]+)"/i', $stampImg, $m)) $stampImg = $m[1];

                $width = isset($elem['width_percent']) ? "width: {$elem['width_percent']}%;" : 'width: 45%;';

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; font-family: 'Times New Roman', serif; {$width} {$opacityStyle}\">\n";
                if ($dateText) $out .= "<p style=\"margin: 0; font-size: 11pt;\">{$dateText}</p>\n";
                if ($roleText) $out .= "<p style=\"margin: 2px 0 0 0; font-size: 11pt;\">{$roleText}</p>\n";
                $out .= "<div style=\"height: 70px; margin: 6px auto; position: relative; width: 220px;\">\n";
                if ($sigImg) $out .= "<img src=\"{$sigImg}\" style=\"height: 60px; width: auto; position: relative; z-index: 10; display: block; margin: 0 auto;\" alt=\"Tanda Tangan\" />\n";
                if ($stampImg) $out .= "<img src=\"{$stampImg}\" style=\"height: 125px; width: 125px; position: absolute; left: 15px; top: -10px; opacity: 0.78; z-index: 20; pointer-events: none;\" alt=\"Stempel\" />\n";
                $out .= "</div>\n";
                if ($nameText) $out .= "<p style=\"margin: 0; font-size: 12pt; font-weight: bold; text-decoration: underline; position: relative; z-index: 5;\">{$nameText}</p>\n";
                $out .= "</div>\n";
            }
        }

        return $out;
    }

    /**
     * Wrapper HTML untuk Dokumen Surat A4.
     */
    public static function wrapLetterDocumentHtml(string $bodyHtml, array $pageConfig, string $title = 'Dokumen', bool $isForPdf = false): string
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

        $headerHtml = !empty($pageConfig['header_html']) ? static::sanitizeHtml((string)$pageConfig['header_html']) : '';
        $footerHtml = !empty($pageConfig['footer_html']) ? static::sanitizeHtml((string)$pageConfig['footer_html']) : '';

        $headerBlock = '';
        if ($headerHtml !== '') {
            $headerBlock = '<div class="letter-running-header" style="position: ' . ($isForPdf ? 'fixed' : 'relative') . '; top: ' . ($isForPdf ? '-' . max(2, $mTop - 2) . 'mm' : '0') . '; left: 0; right: 0; overflow: hidden; margin-bottom: 4mm;">' . $headerHtml . '</div>';
        }
        $footerBlock = '';
        if ($footerHtml !== '') {
            $footerBlock = '<div class="letter-running-footer" style="position: ' . ($isForPdf ? 'fixed' : 'relative') . '; bottom: ' . ($isForPdf ? '-' . max(2, $mBtm - 2) . 'mm' : '0') . '; left: 0; right: 0; overflow: hidden; margin-top: 6mm;">' . $footerHtml . '</div>';
        }

        // Watermark support (Logo perusahaan tepat di tengah kertas, tidak menggeser teks)
        $showWatermark = (bool) ($pageConfig['show_watermark'] ?? true);
        $watermarkBlock = '';
        if ($showWatermark) {
            $compLogoPath = $pageConfig['company_logo'] ?? ($pageConfig['company_logo_url'] ?? '/images/mou/seven-inc-logo.png');
            $compLogoBase64 = static::resolveImageToBase64($compLogoPath) ?? $compLogoPath;
            $wmOpacity = (float) ($pageConfig['watermark_opacity'] ?? 0.05);
            $posStyle = $isForPdf 
                ? 'position: fixed; top: 32%; left: 0; right: 0; width: 100%; text-align: center; z-index: -1000; pointer-events: none;'
                : 'position: absolute; top: 32%; left: 0; right: 0; width: 100%; text-align: center; z-index: 1; pointer-events: none;';
            $watermarkBlock = '<div class="letter-watermark-center" style="' . $posStyle . '"><img src="' . htmlspecialchars($compLogoBase64, ENT_QUOTES, 'UTF-8') . '" style="width: 300px; height: auto; opacity: ' . $wmOpacity . '; margin: 0 auto; display: inline-block;" alt="Watermark"/></div>';
        }

        // Header single logo (1 logo perusahaan di kiri saja untuk LOA/surat resmi)
        $showHeaderLogos = (bool) ($pageConfig['show_header_logos'] ?? false);
        $headerLogoBlock = '';
        if ($showHeaderLogos && empty($headerHtml)) {
            $compLogoPath = $pageConfig['company_logo'] ?? ($pageConfig['company_logo_url'] ?? '/images/mou/seven-inc-logo.png');
            $compLogoBase64 = static::resolveImageToBase64($compLogoPath) ?? $compLogoPath;
            $posLogoStyle = $isForPdf
                ? 'position: fixed; top: -' . max(2, $mTop - 2) . 'mm; left: 0; height: ' . max(4, $mTop - 4) . 'mm; overflow: hidden;'
                : 'position: relative; left: 0; margin-bottom: 4mm;';
            $headerLogoBlock = '<div class="letter-fixed-header-logo" style="' . $posLogoStyle . '"><img src="' . htmlspecialchars($compLogoBase64, ENT_QUOTES, 'UTF-8') . '" style="height: 65px; width: auto;" alt="Logo Perusahaan"/></div>';
        }

        if ($isForPdf) {
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
            width: 100%;
        }
        .page-break {
            page-break-after: always;
            break-after: page;
        }
        /* Canonical Word Lists Styling in DomPDF */
        ol, ul {
            padding-left: 24pt !important;
            margin-top: 0 !important;
            margin-bottom: 6pt !important;
        }
        ol { list-style-type: decimal !important; }
        ol[type="a"] { list-style-type: lower-alpha !important; }
        ol[type="A"] { list-style-type: upper-alpha !important; }
        ol[type="i"] { list-style-type: lower-roman !important; }
        ol[type="I"] { list-style-type: upper-roman !important; }
        ul { list-style-type: disc !important; }
        ul ul { list-style-type: circle !important; }
        ul ul ul { list-style-type: square !important; }
        li { display: list-item !important; margin-bottom: 2pt !important; }
        li > p { display: inline !important; margin-bottom: 0 !important; }
    </style>
</head>
<body>
    {$watermarkBlock}
    {$headerBlock}
    {$headerLogoBlock}
    {$bodyHtml}
    {$footerBlock}
</body>
</html>
HTML;
        }

        // Screen Preview Layout with Authentically Sized & Padded A4 Paper Sheet
        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
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
        html {
            background-color: #f1f5f9;
            margin: 0;
            padding: 24px 12px;
            min-height: 100%;
        }
        body {
            margin: 0 auto;
            padding: 0;
            font-family: '{$fontFamily}', Times, serif;
            font-size: {$fontSize}pt;
            line-height: {$lineHeight};
            color: #000000;
            background: transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .letter-paper-sheet {
            width: 210mm;
            min-height: 297mm;
            max-width: 100%;
            background-color: #ffffff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.15), 0 2px 6px rgba(15, 23, 42, 0.08);
            border: 1px solid #cbd5e1;
            padding: {$mTop}mm {$mRgt}mm {$mBtm}mm {$mLft}mm;
            box-sizing: border-box;
            position: relative;
            word-wrap: break-word;
        }
        .letter-body-content {
            position: relative;
            z-index: 2;
            width: 100%;
        }
        img {
            max-width: 100%;
            height: auto;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        .page-break {
            page-break-after: always;
            break-after: page;
        }
        ol, ul {
            padding-left: 24pt !important;
            margin-top: 0 !important;
            margin-bottom: 6pt !important;
        }
        ol { list-style-type: decimal !important; }
        ul { list-style-type: disc !important; }
        li { display: list-item !important; margin-bottom: 2pt !important; }
        li > p { display: inline !important; margin-bottom: 0 !important; }
        @media print {
            html {
                background: #ffffff !important;
                padding: 0 !important;
            }
            body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                display: block !important;
            }
            .letter-paper-sheet {
                width: 100% !important;
                max-width: none !important;
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="letter-paper-sheet">
        {$watermarkBlock}
        {$headerBlock}
        {$headerLogoBlock}
        <div class="letter-body-content">
            {$bodyHtml}
        </div>
        {$footerBlock}
    </div>
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
        string $title = 'Sertifikat',
        bool $isForPdf = false
    ): string {
        $widthMm = $orientation === 'landscape' ? 297.0 : 210.0;
        $heightMm = $orientation === 'landscape' ? 210.0 : 297.0;

        if ($isForPdf) {
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

        // Screen Preview Layout with Fully-Scaled Visual Canvas
        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
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
        html {
            background-color: #0f172a;
            margin: 0;
            padding: 24px 12px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow-x: auto;
        }
        body {
            margin: auto;
            padding: 0;
            background: transparent;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .cert-canvas-container {
            position: relative;
            width: {$widthMm}mm;
            height: {$heightMm}mm;
            min-width: {$widthMm}mm;
            min-height: {$heightMm}mm;
            background-color: {$bgColor};
            background-image: url('{$bgImage}');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
            border-radius: 4px;
            overflow: hidden;
            flex-shrink: 0;
        }
        @media print {
            html, body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
                display: block !important;
            }
            .cert-canvas-container {
                box-shadow: none !important;
                border-radius: 0 !important;
            }
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

        // Auto-balance 2-column kop surat table so middle text is centered on the whole page
        $tables = $xpath->query('.//table', $root);
        foreach ($tables as $tbl) {
            $rows = $xpath->query('.//tr', $tbl);
            if ($rows->length === 1) {
                $cells = $xpath->query('.//td', $rows->item(0));
                if ($cells->length === 2) {
                    $img = $xpath->query('.//img', $cells->item(0));
                    if ($img->length > 0) {
                        $w1 = $cells->item(0)->getAttribute('style');
                        preg_match('/width\s*:\s*([^;]+)/i', $w1, $wm);
                        $widthStyle = !empty($wm[1]) ? trim($wm[1]) : '110px';
                        $thirdCell = $dom->createElement('td');
                        $thirdCell->setAttribute('style', "width: {$widthStyle}; vertical-align: middle;");
                        $rows->item(0)->appendChild($thirdCell);
                    }
                }
            }
        }

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
     * Konversi string referensi gambar (SystemAsset ID/preview, storage, public image) menjadi data URI Base64.
     */
    public static function resolveImageToBase64(?string $src): ?string
    {
        if (empty($src)) {
            return null;
        }

        if (str_starts_with($src, 'data:image/')) {
            return $src;
        }

        // Resolusi SystemAsset preview atau ID
        $assetId = null;
        if (is_numeric($src)) {
            $assetId = (int) $src;
        } elseif (preg_match('#(?:^|/)admin/system-assets/(\d+)/preview#i', $src, $m)) {
            $assetId = (int) $m[1];
        }

        if ($assetId && class_exists(\App\Services\SystemAssetService::class)) {
            try {
                $service = app(\App\Services\SystemAssetService::class);
                $b64 = $service->getAssetAsBase64DataUri($assetId);
                if ($b64) {
                    return $b64;
                }
            } catch (\Throwable) {
                // Fallback
            }
        }

        // Resolusi Storage disk public (/storage/... atau http://.../storage/...)
        if (preg_match('#(?:^|/)storage/(.+)$#i', $src, $sm)) {
            $rel = ltrim($sm[1], '/');
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($rel)) {
                $content = \Illuminate\Support\Facades\Storage::disk('public')->get($rel);
                $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($rel) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode($content);
            }
            $localPath = storage_path('app/public/' . $rel);
            if (file_exists($localPath)) {
                $mime = mime_content_type($localPath) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
            }
        }

        // Resolusi Public path (/images/... atau http://.../images/...)
        if (preg_match('#(?:^|/)images/(.+)$#i', $src, $im)) {
            $localPath = public_path('images/' . ltrim($im[1], '/'));
            if (file_exists($localPath)) {
                $mime = mime_content_type($localPath) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
            }
        }

        return null;
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

            // Cek jika elemen memiliki data-asset-id
            $combined = $prefix . ' ' . $suffix;
            if (preg_match('/\bdata-asset-id\s*=\s*["\'](\d+)["\']/i', $combined, $am)) {
                $b64 = static::resolveImageToBase64($am[1]);
                if ($b64) {
                    return "<img{$prefix}src=\"{$b64}\"{$suffix}>";
                }
            }

            $b64 = static::resolveImageToBase64($src);
            if ($b64) {
                return "<img{$prefix}src=\"{$b64}\"{$suffix}>";
            }

            return $matches[0];
        }, $html);
    }
}
