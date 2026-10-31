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
        
        $renderedElementsHtml = static::renderCanvasElementsHtml($layout, $data, $isLiveIssuance, $isForPdf);

        $defaultBg = '/images/certificate/certificate-bg.png';
        $layoutBg = $layout['canvas']['background_image'] ?? ($pageConfig['background_image'] ?? $defaultBg);

        // Resolusi placeholder jika layout background mengandung tag (misal {{asset.background}})
        if (str_contains($layoutBg, '{{') || str_contains($layoutBg, '[')) {
            $layoutBg = MailMergePlaceholderContract::resolve($layoutBg, $data, $isLiveIssuance);
        }

        // Tentukan background image:
        // 1. Jika force_brand_background aktif atau (live issuance dan template menggunakan default / tag dinamis), gunakan aset brand
        if (!empty($data['asset.background']) && !empty($data['force_brand_background'])) {
            $rawBg = $data['asset.background'];
        } elseif ($isLiveIssuance && !empty($data['asset.background']) && ($layoutBg === $defaultBg || str_ends_with($layoutBg, 'certificate-bg.png') || empty($layoutBg) || str_contains($layoutBg, 'asset.background'))) {
            $rawBg = $data['asset.background'];
        } elseif (!$isLiveIssuance && !empty($layoutBg)) {
            // Mode preview/desain template admin: hormati latar belakang kanvas yang dipilih (misal Border Emas Klasik bawaan)
            $rawBg = $layoutBg;
        } else {
            $rawBg = !empty($layoutBg) ? $layoutBg : (!empty($data['asset.background']) ? $data['asset.background'] : $defaultBg);
        }

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
        $isCanvas = ($template->type === 'certificate' || $template->editor_type === 'visual_canvas');
        $paperSize = strtolower((string) ($template->page_config['paper_size'] ?? 'a4'));
        $orientation = strtolower((string) ($template->page_config['orientation'] ?? ($isCanvas ? 'landscape' : 'portrait')));

        // 1. Coba render PDF berbasis Chromium (Browsershot) untuk presisi dan kualitas visual 100% identik dengan browser
        try {
            if (class_exists(\Spatie\Browsershot\Browsershot::class)) {
                $screenHtml = $isCanvas
                    ? static::renderCertificateHtml($template, $data, $isLiveIssuance, isForPdf: false)
                    : static::renderLetterHtml($template, $data, $isLiveIssuance, isForPdf: false);

                $htmlWithBase64 = static::embedLocalImagesAsBase64($screenHtml);
                $browsershot = \Spatie\Browsershot\Browsershot::html($htmlWithBase64);

                $chromeCandidates = [
                    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
                    '/usr/bin/google-chrome',
                    '/usr/bin/chromium-browser',
                ];
                foreach ($chromeCandidates as $candidate) {
                    if (file_exists($candidate)) {
                        $browsershot->setChromePath($candidate);
                        break;
                    }
                }

                $browsershot->setOption('no-sandbox', true)
                    ->setOption('args', ['--disable-setuid-sandbox', '--font-render-hinting=none'])
                    ->emulateMedia('print')
                    ->format($paperSize)
                    ->landscape($orientation === 'landscape')
                    ->margins(0, 0, 0, 0)
                    ->showBackground();

                $pdfBinary = $browsershot->pdf();
                if (!empty($pdfBinary) && strlen($pdfBinary) > 1000) {
                    return $pdfBinary;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Browsershot generation failed, falling back to DomPDF: " . $e->getMessage());
        }

        // 2. Fallback aman ke DomPDF jika Browsershot tidak tersedia
        if ($isCanvas) {
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
    public static function renderCanvasElementsHtml(array $layout, array $data = [], bool $isLiveIssuance = false, bool $isForPdf = false): string
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
                $fontFamilyCss = static::formatFontFamily($elem['font_family'] ?? 'Times New Roman');
                $fontSize = (int) ($elem['font_size_pt'] ?? 14);
                $fontWeight = htmlspecialchars($elem['font_weight'] ?? 'normal', ENT_QUOTES, 'UTF-8');
                $fontStyle = htmlspecialchars($elem['font_style'] ?? 'normal', ENT_QUOTES, 'UTF-8');
                $color = htmlspecialchars($elem['color'] ?? '#000000', ENT_QUOTES, 'UTF-8');
                $width = isset($elem['width_percent']) ? "width: {$elem['width_percent']}%;" : 'max-width: 90%;';
                $underline = !empty($elem['underline']) ? 'text-decoration: underline;' : '';
                $lineHeight = isset($elem['line_height']) ? "line-height: {$elem['line_height']};" : 'line-height: 1.3;';
                $letterSpacing = isset($elem['letter_spacing_px']) ? "letter-spacing: {$elem['letter_spacing_px']}px;" : '';

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; text-align: {$align}; font-family: {$fontFamilyCss}; font-size: {$fontSize}pt; font-weight: {$fontWeight}; font-style: {$fontStyle}; color: {$color}; z-index: {$zIndex}; {$width} {$underline} {$lineHeight} {$letterSpacing} {$opacityStyle} word-wrap: break-word;\">\n";
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
                $wPercent = (isset($elem['width_percent']) && is_numeric($elem['width_percent']))
                    ? (float) $elem['width_percent'] 
                    : (isset($elem['width_px']) ? round(((float) $elem['width_px'] / 840) * 100, 2) : 16.5);
                $w = "{$wPercent}%";
                    
                $hPercent = (isset($elem['height_percent']) && is_numeric($elem['height_percent']))
                    ? (float) $elem['height_percent'] 
                    : (isset($elem['height_px']) ? round(((float) $elem['height_px'] / 594) * 100, 2) : 10.0);
                $h = "{$hPercent}%";

                // Bounding box maksimal dalam milimeter (A4 Landscape: 297mm x 210mm) untuk isolasi DomPDF
                $maxWMm = round(($wPercent / 100) * 297, 2);
                $maxHMm = round(($hPercent / 100) * 210, 2);

                if ($isForPdf) {
                    $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; width: {$w}; height: {$h}; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; {$opacityStyle} box-sizing: border-box;\">\n";
                    $out .= "<table style=\"width: 100%; height: 100%; border-collapse: collapse; margin: 0; padding: 0; border: none;\"><tr><td style=\"vertical-align: middle; text-align: {$align}; padding: 0; margin: 0; border: none;\">\n";
                    $out .= "<img src=\"{$src}\" style=\"max-width: {$maxWMm}mm; max-height: {$maxHMm}mm; width: auto; height: auto; object-fit: contain; display: inline-block; vertical-align: middle; margin: 0 auto;\" alt=\"Elemen Sertifikat\" />\n";
                    $out .= "</td></tr></table>\n";
                    $out .= "</div>\n";
                } else {
                    $justify = match ($align) {
                        'left'  => 'flex-start',
                        'right' => 'flex-end',
                        default => 'center',
                    };
                    $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; width: {$w}; height: {$h}; transform: {$transform}; z-index: {$zIndex}; {$opacityStyle} box-sizing: border-box; display: flex; align-items: center; justify-content: {$justify};\">\n";
                    $out .= "<img src=\"{$src}\" style=\"max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; display: block; margin: 0 auto;\" alt=\"Elemen Sertifikat\" />\n";
                    $out .= "</div>\n";
                }
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
                $fgColor = htmlspecialchars($elem['fg_color'] ?? '#000000', ENT_QUOTES, 'UTF-8');
                $bgColor = htmlspecialchars($elem['bg_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8');

                $w = (isset($elem['width_percent']) && is_numeric($elem['width_percent']))
                    ? "{$elem['width_percent']}%" 
                    : (isset($elem['width_px']) ? ((int) $elem['width_px'] . 'px') : '12%');
                $h = (isset($elem['height_percent']) && is_numeric($elem['height_percent']))
                    ? "{$elem['height_percent']}%" 
                    : (isset($elem['height_px']) ? ((int) $elem['height_px'] . 'px') : '15%');

                $hasBox = !empty($bgColor) && $bgColor !== 'transparent';
                $boxStyle = $hasBox 
                    ? "background-color: {$bgColor}; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px;" 
                    : "";

                $qrDataUri = OfflineQrCodeGenerator::renderDataUri($resolvedQrData, 300, $fgColor, 'transparent');

                if ($isForPdf) {
                    $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; width: {$w}; height: {$h}; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; {$boxStyle} box-sizing: border-box; {$opacityStyle}\">\n";
                    $out .= "<table style=\"width: 100%; height: 100%; border-collapse: collapse; margin: 0; padding: 0; border: none;\"><tr><td style=\"vertical-align: middle; text-align: center; padding: 0; margin: 0; border: none;\">\n";
                    $out .= "<img src=\"{$qrDataUri}\" style=\"width: 100%; height: 100%; max-width: 100%; max-height: 100%; object-fit: contain; display: block; margin: 0 auto;\" alt=\"QR Code Verifikasi\" />\n";
                    $out .= "</td></tr></table>\n";
                    $out .= "</div>\n";
                } else {
                    $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; width: {$w}; height: {$h}; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; {$boxStyle} box-sizing: border-box; display: flex; flex-direction: column; align-items: center; justify-content: center; {$opacityStyle}\">\n";
                    $out .= "<img src=\"{$qrDataUri}\" style=\"width: 100%; height: 100%; max-width: 100%; max-height: 100%; object-fit: contain; display: block; margin: 0 auto;\" alt=\"QR Code Verifikasi\" />\n";
                    $out .= "</div>\n";
                }
            } elseif ($type === 'signature_group') {
                $certNoText = !empty($elem['cert_no_text']) ? MailMergePlaceholderContract::resolve($elem['cert_no_text'], $data, $isLiveIssuance) : '';
                $dateText = MailMergePlaceholderContract::resolve($elem['date_text'] ?? '', $data, $isLiveIssuance);
                $roleText = MailMergePlaceholderContract::resolve($elem['role_text'] ?? '', $data, $isLiveIssuance);
                $nameText = MailMergePlaceholderContract::resolve($elem['name_text'] ?? '', $data, $isLiveIssuance);
                $sigImg = MailMergePlaceholderContract::resolve($elem['sig_image'] ?? '', $data, $isLiveIssuance);
                if (preg_match('/src="([^"]+)"/i', $sigImg, $m)) $sigImg = $m[1];
                $stampImg = MailMergePlaceholderContract::resolve($elem['stamp_image'] ?? '', $data, $isLiveIssuance);
                if (preg_match('/src="([^"]+)"/i', $stampImg, $m)) $stampImg = $m[1];

                $width = isset($elem['width_percent']) ? "width: {$elem['width_percent']}%;" : 'width: 45%;';
                $sigFontCss = static::formatFontFamily($elem['font_family'] ?? 'Times New Roman');
                $color = htmlspecialchars($elem['color'] ?? '#000000', ENT_QUOTES, 'UTF-8');

                $stackMargin = match ($align) {
                    'left'  => 'margin: 4px 0;',
                    'right' => 'margin: 4px 0 4px auto;',
                    default => 'margin: 4px auto;',
                };
                $sigMargin = match ($align) {
                    'left'  => 'margin: 0;',
                    'right' => 'margin: 0 0 0 auto;',
                    default => 'margin: 0 auto;',
                };
                $stampSize = isset($elem['stamp_size_px']) && is_numeric($elem['stamp_size_px']) ? (int) $elem['stamp_size_px'] : 120;
                $sigHeight = isset($elem['sig_height_px']) && is_numeric($elem['sig_height_px']) ? (int) $elem['sig_height_px'] : 50;
                $stampX = isset($elem['stamp_offset_x']) && is_numeric($elem['stamp_offset_x']) ? (int) $elem['stamp_offset_x'] : 10;
                $stampY = isset($elem['stamp_offset_y']) && is_numeric($elem['stamp_offset_y']) ? (int) $elem['stamp_offset_y'] : -35;
                $stampStyle = match ($align) {
                    'left'  => "position: absolute; left: {$stampX}px; top: {$stampY}px;",
                    'right' => "position: absolute; right: {$stampX}px; top: {$stampY}px;",
                    default => "position: absolute; left: {$stampX}px; top: {$stampY}px;",
                };

                $out .= "<div style=\"position: absolute; left: {$x}%; top: {$y}%; transform: {$transform}; z-index: {$zIndex}; text-align: {$align}; font-family: {$sigFontCss}; color: {$color}; {$width} {$opacityStyle}\">\n";
                if ($certNoText) $out .= "<p style=\"margin: 0 0 2px 0; font-size: 11pt; line-height: 1.3;\">{$certNoText}</p>\n";
                if ($dateText) $out .= "<p style=\"margin: 0; font-size: 11pt; line-height: 1.3;\">{$dateText}</p>\n";
                if ($roleText) $out .= "<p style=\"margin: 2px 0 0 0; font-size: 11pt; line-height: 1.3;\">{$roleText}</p>\n";
                $out .= "<div style=\"height: {$sigHeight}px; {$stackMargin} position: relative; width: 220px;\">\n";
                if ($stampImg) $out .= "<img src=\"{$stampImg}\" style=\"height: {$stampSize}px; width: {$stampSize}px; {$stampStyle} opacity: 0.70; mix-blend-mode: multiply; z-index: 5; pointer-events: none;\" alt=\"Stempel\" />\n";
                if ($sigImg) $out .= "<img src=\"{$sigImg}\" style=\"height: {$sigHeight}px; width: auto; position: relative; z-index: 10; display: block; {$sigMargin}\" alt=\"Tanda Tangan\" />\n";
                $out .= "</div>\n";
                if ($nameText) $out .= "<p style=\"margin: 2px 0 0 0; font-size: 12pt; font-weight: bold; text-decoration: underline; position: relative; z-index: 10; line-height: 1.3;\">{$nameText}</p>\n";
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
        table td, table th {
            vertical-align: top;
        }
        table td p, table th p {
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.25 !important;
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
        table td, table th {
            vertical-align: top;
        }
        table td p, table th p {
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.25 !important;
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
            @page {
                size: {$pSize} {$orient};
                margin: {$mTop}mm {$mRgt}mm {$mBtm}mm {$mLft}mm;
            }
            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: auto !important;
                display: block !important;
            }
            .letter-paper-sheet {
                width: 100% !important;
                max-width: none !important;
                min-height: auto !important;
                height: auto !important;
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
    <script>
        function autoScaleSheet() {
            var sheet = document.querySelector('.letter-paper-sheet');
            if (!sheet) return;
            var availableW = window.innerWidth - 24;
            var targetW = 794;
            if (availableW < targetW) {
                var scale = availableW / targetW;
                sheet.style.transform = 'scale(' + scale + ')';
                sheet.style.transformOrigin = 'top center';
                var diff = (sheet.getBoundingClientRect().height) - sheet.offsetHeight;
                sheet.style.marginBottom = diff + 'px';
            } else {
                sheet.style.transform = '';
                sheet.style.transformOrigin = '';
                sheet.style.marginBottom = '';
            }
        }
        window.addEventListener('resize', autoScaleSheet);
        window.addEventListener('DOMContentLoaded', autoScaleSheet);
        setTimeout(autoScaleSheet, 50);
    </script>
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Allura&family=Cinzel:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Oswald:wght@400;600;700&family=Parisienne&family=Pinyon+Script&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Poppins:wght@400;500;600;700&family=Prata&family=Raleway:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Alex+Brush&family=Allura&family=Cinzel:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Oswald:wght@400;600;700&family=Parisienne&family=Pinyon+Script&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Poppins:wght@400;500;600;700&family=Prata&family=Raleway:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap');
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Allura&family=Cinzel:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Oswald:wght@400;600;700&family=Parisienne&family=Pinyon+Script&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Poppins:wght@400;500;600;700&family=Prata&family=Raleway:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Alex+Brush&family=Allura&family=Cinzel:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Oswald:wght@400;600;700&family=Parisienne&family=Pinyon+Script&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Poppins:wght@400;500;600;700&family=Prata&family=Raleway:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap');
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
     * Format CSS font-family secara aman dengan generic fallback tanpa broken nested quotes.
     */
    public static function formatFontFamily(?string $fontFamily): string
    {
        if (empty($fontFamily)) {
            return "'Times New Roman', serif";
        }

        $fontFamily = trim(html_entity_decode((string) $fontFamily, ENT_QUOTES, 'UTF-8'));
        if ($fontFamily === '') {
            return "'Times New Roman', serif";
        }

        // Jika sudah memuat fallback dengan koma, misal "'Playfair Display', serif" atau "Arial, sans-serif"
        if (str_contains($fontFamily, ',')) {
            return $fontFamily;
        }

        $clean = trim($fontFamily, "'\" ");
        $scripts = ['Great Vibes', 'Alex Brush', 'Pinyon Script', 'Allura', 'Dancing Script', 'Parisienne'];
        $sans = ['Montserrat', 'Poppins', 'Inter', 'Raleway', 'Oswald', 'Arial', 'Helvetica'];
        $monos = ['Space Mono', 'Courier New', 'Courier'];

        if (in_array($clean, $scripts, true)) {
            return "'{$clean}', cursive";
        }
        if (in_array($clean, $sans, true)) {
            return "'{$clean}', sans-serif";
        }
        if (in_array($clean, $monos, true)) {
            return "'{$clean}', monospace";
        }

        return "'{$clean}', serif";
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

        if (str_starts_with($src, '/storage/images/')) {
            $src = substr($src, strlen('/storage'));
        }

        if (str_starts_with($src, 'data:image/')) {
            return $src;
        }

        if (file_exists($src) && is_file($src)) {
            $mime = mime_content_type($src) ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($src));
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

        // Resolusi Storage disk public (/storage/... atau relative path disk public)
        $relStorage = null;
        if (preg_match('#(?:^|/)storage/(.+)$#i', $src, $sm)) {
            $relStorage = ltrim($sm[1], '/');
        } elseif (\Illuminate\Support\Facades\Storage::disk('public')->exists($src)) {
            $relStorage = ltrim($src, '/');
        } elseif (file_exists(storage_path('app/public/' . ltrim($src, '/')))) {
            $relStorage = ltrim($src, '/');
        }

        if ($relStorage !== null) {
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($relStorage)) {
                $content = \Illuminate\Support\Facades\Storage::disk('public')->get($relStorage);
                $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($relStorage) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode($content);
            }
            $localPath = storage_path('app/public/' . $relStorage);
            if (file_exists($localPath) && is_file($localPath)) {
                $mime = mime_content_type($localPath) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
            }
        }

        // Resolusi Public path (/images/... atau http://.../images/...)
        if (preg_match('#(?:^|/)images/(.+)$#i', $src, $im)) {
            $localPath = public_path('images/' . ltrim($im[1], '/'));
            if (file_exists($localPath) && is_file($localPath)) {
                $mime = mime_content_type($localPath) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
            }
        }

        $pubDirect = public_path(ltrim($src, '/'));
        if (file_exists($pubDirect) && is_file($pubDirect)) {
            $mime = mime_content_type($pubDirect) ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($pubDirect));
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
