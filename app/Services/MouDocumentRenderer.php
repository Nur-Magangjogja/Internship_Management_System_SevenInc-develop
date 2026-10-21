<?php

namespace App\Services;

use App\Models\Mou;
use App\Models\MouTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MouDocumentRenderer
{
    /**
     * Daftar elemen HTML yang diizinkan di dalam template MOU.
     */
    public const ALLOWED_TAGS = [
        'div', 'p', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark',
        'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'code', 'pre',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col',
        'ol', 'ul', 'li', 'img', 'a', 'figure', 'figcaption', 'section', 'header', 'footer',
    ];

    /**
     * Daftar atribut elemen yang diizinkan (mendukung penuh model Tiptap/ProseMirror V3 & atribut tabel/sel).
     */
    public const ALLOWED_ATTRIBUTES = [
        'class', 'style', 'align', 'valign', 'colspan', 'rowspan', 'colwidth',
        'width', 'height', 'type', 'start', 'src', 'alt', 'title', 'href', 'target', 'rel',
        'data-pasal', 'data-pasal-continuation', 'data-ayat', 'data-token',
        'data-page-break', 'data-table-style', 'data-table-continuation',
        'data-table-width', 'data-table-align', 'data-row-height',
        'data-colwidth', 'data-cell-width', 'data-cell-padding',
        'data-border-style', 'data-border-width', 'data-border-color', 'data-bg-color',
        'data-textbox', 'data-color',
        'data-logo-slot', 'data-snapshot-version', 'data-pagination-artifact',
        'data-asset-id', 'data-asset-code',
    ];

    /**
     * Daftar properti CSS inline yang diizinkan pada elemen dokumen.
     */
    public const ALLOWED_CSS_PROPERTIES = [
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-decoration',
        'text-align', 'text-transform', 'text-indent', 'color', 'background-color',
        'line-height', 'letter-spacing',
        'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
        'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
        'border', 'border-top', 'border-bottom', 'border-left', 'border-right',
        'border-style', 'border-width', 'border-color',
        'border-top-style', 'border-bottom-style', 'border-left-style', 'border-right-style',
        'border-top-width', 'border-bottom-width', 'border-left-width', 'border-right-width',
        'border-top-color', 'border-bottom-color', 'border-left-color', 'border-right-color',
        'border-collapse', 'border-spacing', 'table-layout', 'box-sizing',
        'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
        'vertical-align', 'list-style-type', 'display', 'float', 'clear',
        'page-break-before', 'page-break-after', 'page-break-inside',
        'break-before', 'break-after', 'break-inside',
    ];

    /**
     * Sanitasi konten Header / Footer kustom pada halaman A4.
     */
    public static function sanitizeHeaderFooterHtml(?string $html): string
    {
        return static::sanitizeEditableTemplate($html, false);
    }

    /**
     * Sanitasi dan normalisasi konten template yang masih dapat diedit (Editable Template).
     * Mempertahankan token [placeholder], path aset lokal tepercaya, serta memperbarui nomor Pasal & Ayat otomatis.
     */
    public static function sanitizeEditableTemplate(?string $html, bool $normalizeNumbering = true): string
    {
        if (empty(trim((string) $html))) {
            return '';
        }

        $cleaned = (string) $html;

        // 1. Bersihkan komentar MSO conditional dan tag berbahaya beserta isinya terlebih dahulu
        $cleaned = preg_replace('/<!--\[if[\s\S]*?<!\[endif\]-->/i', '', $cleaned);
        $cleaned = preg_replace('/<!--[\s\S]*?-->/', '', $cleaned);

        do {
            $prev = $cleaned;
            $cleaned = preg_replace('/<(script|style|iframe|object|embed|applet|form|svg|math|xml|head|meta|link|base)\b[^>]*>([\s\S]*?)<\/\1>/i', '', $cleaned);
            $cleaned = preg_replace('/<\/?(script|style|iframe|object|embed|applet|form|input|button|textarea|select|meta|link|base|xml|o:p)\b[^>]*\/?>/i', '', $cleaned);
        } while ($cleaned !== $prev);

        // 2. Parse menggunakan DOMDocument dengan wrapper UTF-8
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prevErrorState = libxml_use_internal_errors(true);

        $wrappedHtml = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="__mou_sanitize_root__">'
            . $cleaned
            . '</div></body></html>';

        $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrorState);

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="__mou_sanitize_root__"]')->item(0);

        if (!$root instanceof DOMElement) {
            return '';
        }

        // 3. Telusuri dan saring seluruh node secara rekursif
        static::sanitizeDomNode($root);

        // 3b. Bersihkan artefak pagination visual (.mou-a4-page, .mou-pasal-continuation, .mou-table-continuation)
        // agar HTML kanonis selalu murni dari wrapper halaman otomatis editor
        static::unwrapPaginationArtifactsDom($dom, $xpath, $root);

        // 4. Normalisasi penomoran otomatis Pasal & Ayat untuk blok terstruktur (.mou-pasal) jika ada
        if ($normalizeNumbering) {
            static::normalizePasalAndAyatDom($dom, $xpath, $root);
        }

        // 5. Ekstrak kembali innerHTML dari root
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Sanitasi rekursif node DOM berdasarkan allowlist tag, atribut, style CSS, sumber gambar, dan sinkronisasi dimensi tabel ProseMirror.
     */
    protected static function sanitizeDomNode(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            /** @var DOMElement $node */
            $tagName = strtolower($node->nodeName);

            // Hapus total elemen berbahaya jika masih tersisa
            if (in_array($tagName, ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'meta', 'link', 'base', 'svg', 'math'], true)) {
                $parent->removeChild($node);
                continue;
            }

            // Jika bukan tag yang diizinkan, unwrap anak-anaknya agar teks tidak hilang
            if (!in_array($tagName, self::ALLOWED_TAGS, true)) {
                static::sanitizeDomNode($node);
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            // Saring atribut pada elemen yang diizinkan
            $attrNames = [];
            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attr) {
                    $attrNames[] = $attr->name;
                }
            }

            foreach ($attrNames as $attrName) {
                $lowerAttr = strtolower($attrName);

                // Hapus semua event handler on* atau atribut di luar allowlist (termasuk contenteditable)
                if (str_starts_with($lowerAttr, 'on') || !in_array($lowerAttr, self::ALLOWED_ATTRIBUTES, true)) {
                    $node->removeAttribute($attrName);
                    continue;
                }

                $attrVal = trim($node->getAttribute($attrName));

                if ($lowerAttr === 'class') {
                    // Bersihkan class Mso* bawaan Word
                    $classes = preg_split('/\s+/', $attrVal) ?: [];
                    $cleanClasses = array_filter($classes, function ($cls) {
                        return $cls !== '' && !preg_match('/^Mso/i', $cls) && preg_match('/^[a-zA-Z0-9_\-\[\]:\/\.]+$/', $cls);
                    });
                    if (empty($cleanClasses)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('class', implode(' ', $cleanClasses));
                    }
                } elseif ($lowerAttr === 'style') {
                    $cleanStyle = static::sanitizeCssStyleString($attrVal);
                    if ($cleanStyle === '') {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('style', $cleanStyle);
                    }
                } elseif ($lowerAttr === 'src') {
                    if ($tagName !== 'img' || !static::isValidImageSource($attrVal)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('src', $attrVal);
                    }
                } elseif ($lowerAttr === 'href') {
                    if ($tagName !== 'a' || !preg_match('/^(https?:\/\/|mailto:|#|\/[a-zA-Z0-9_\-\/])/i', $attrVal)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('href', $attrVal);
                    }
                } elseif ($lowerAttr === 'colwidth' || $lowerAttr === 'data-colwidth') {
                    $nums = array_values(array_filter(array_map('intval', explode(',', $attrVal)), fn ($v) => $v > 0));
                    if (empty($nums)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute($lowerAttr, implode(',', $nums));
                    }
                }
            }

            // Sinkronisasi atribut tabel/baris/sel ProseMirror dengan inline CSS agar DomPDF & Re-open Editor identik
            static::synchronizeTableElementStyles($node, $tagName);

            // Jika tag <img> kehilangan src yang valid, hapus elemen img tersebut
            if ($tagName === 'img' && !$node->hasAttribute('src')) {
                $parent->removeChild($node);
                continue;
            }

            // Lanjutkan rekursi ke anak elemen
            static::sanitizeDomNode($node);
        }
    }

    /**
     * Sinkronkan atribut khusus tabel ProseMirror (data-table-width, data-row-height, colwidth, data-cell-width)
     * ke properti inline style CSS agar konsisten saat dirender oleh DomPDF maupun saat dibuka kembali di Tiptap.
     */
    protected static function synchronizeTableElementStyles(DOMElement $node, string $tagName): void
    {
        if (!in_array($tagName, ['table', 'col', 'tr', 'td', 'th'], true)) {
            return;
        }

        $styleMap = static::parseCssStyleToMap($node->getAttribute('style'));

        if ($tagName === 'table') {
            $dataWidth = trim($node->getAttribute('data-table-width'));
            if ($dataWidth !== '' && preg_match('/^\d+(\.\d+)?(px|pt|mm|cm|%)$/i', $dataWidth)) {
                $styleMap['width'] = $dataWidth;
            } elseif (!empty($styleMap['width']) && preg_match('/^\d+(\.\d+)?(px|pt|mm|cm|%)$/i', $styleMap['width'])) {
                $node->setAttribute('data-table-width', $styleMap['width']);
            }
            if (empty($styleMap['border-collapse'])) {
                $styleMap['border-collapse'] = 'collapse';
            }
        } elseif ($tagName === 'col') {
            $widthAttr = trim($node->getAttribute('width'));
            if (!empty($styleMap['width']) && preg_match('/^(\d+)(?:\.\d+)?px$/i', $styleMap['width'], $m)) {
                if ($widthAttr === '') {
                    $node->setAttribute('width', $m[1]);
                }
            } elseif ($widthAttr !== '' && preg_match('/^\d+$/', $widthAttr)) {
                $styleMap['width'] = $widthAttr . 'px';
            }
        } elseif ($tagName === 'tr') {
            $rowHeight = trim($node->getAttribute('data-row-height'));
            if ($rowHeight !== '' && preg_match('/^\d+(\.\d+)?(px|pt|mm|cm)$/i', $rowHeight)) {
                $styleMap['height'] = $rowHeight;
            } elseif (!empty($styleMap['height']) && preg_match('/^\d+(\.\d+)?(px|pt|mm|cm)$/i', $styleMap['height'])) {
                $node->setAttribute('data-row-height', $styleMap['height']);
            }
        } elseif ($tagName === 'td' || $tagName === 'th') {
            $colwidth = trim($node->getAttribute('colwidth') ?: $node->getAttribute('data-colwidth'));
            $cellWidth = trim($node->getAttribute('data-cell-width'));

            if ($colwidth !== '') {
                $nums = array_values(array_filter(array_map('intval', explode(',', $colwidth)), fn ($v) => $v > 0));
                if (!empty($nums)) {
                    $node->setAttribute('colwidth', implode(',', $nums));
                    // Jika tidak ada cellWidth persentase eksplisit atau user telah melakukan resize kolom (px), pastikan style width terisi
                    if ($cellWidth === '' || empty($styleMap['width'])) {
                        $styleMap['width'] = array_sum($nums) . 'px';
                    }
                }
            } elseif ($cellWidth !== '' && preg_match('/^\d+(\.\d+)?(px|pt|mm|cm|%)$/i', $cellWidth)) {
                if (empty($styleMap['width'])) {
                    $styleMap['width'] = $cellWidth;
                }
            } elseif (!empty($styleMap['width']) && preg_match('/^(\d+)(?:\.\d+)?px$/i', $styleMap['width'], $m)) {
                $node->setAttribute('colwidth', $m[1]);
            }

            // Wariskan tinggi baris (data-row-height) dari parent <tr> ke sel <td>/<th> jika belum memiliki height sendiri agar DomPDF merender tinggi baris secara akurat
            if (empty($styleMap['height']) && $node->parentNode instanceof DOMElement && strtolower($node->parentNode->nodeName) === 'tr') {
                $parentRowHeight = trim($node->parentNode->getAttribute('data-row-height'));
                if ($parentRowHeight !== '' && preg_match('/^\d+(\.\d+)?(px|pt|mm|cm)$/i', $parentRowHeight)) {
                    $styleMap['height'] = $parentRowHeight;
                }
            }
        }

        $rebuilt = [];
        foreach ($styleMap as $k => $v) {
            $rebuilt[] = $k . ': ' . $v;
        }
        $cleanStyle = static::sanitizeCssStyleString(implode('; ', $rebuilt));
        if ($cleanStyle !== '') {
            $node->setAttribute('style', $cleanStyle);
        }
    }

    /**
     * Parse string CSS inline menjadi array asosiatif terurut.
     */
    protected static function parseCssStyleToMap(string $style): array
    {
        $map = [];
        if (trim($style) === '') {
            return $map;
        }
        foreach (explode(';', $style) as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }
            [$p, $v] = explode(':', $decl, 2);
            $p = strtolower(trim($p));
            $v = trim($v);
            if ($p !== '' && $v !== '') {
                $map[$p] = $v;
            }
        }
        return $map;
    }

    /**
     * Sanitasi string inline CSS berdasarkan allowlist properti dan penolakan ekspresi berbahaya.
     */
    public static function sanitizeCssStyleString(string $style): string
    {
        if (trim($style) === '') {
            return '';
        }

        $declarations = explode(';', $style);
        $allowedPairs = [];

        foreach ($declarations as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }

            [$prop, $val] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $val = trim($val);

            if ($prop === '' || $val === '') {
                continue;
            }

            // Tolak properti mso-* atau properti di luar allowlist
            if (str_starts_with($prop, 'mso-') || !in_array($prop, self::ALLOWED_CSS_PROPERTIES, true)) {
                continue;
            }

            // Tolak nilai CSS berbahaya (url, expression, javascript, vbscript, import, behavior)
            if (preg_match('/(expression|javascript|vbscript|data:|url\s*\(|@import|behavior|-moz-binding)/i', $val)) {
                continue;
            }

            // Pastikan tidak ada karakter injeksi tag di dalam value CSS
            if (preg_match('/[<>\\\\]/', $val)) {
                continue;
            }

            $allowedPairs[$prop] = $prop . ': ' . $val;
        }

        return implode('; ', array_values($allowedPairs));
    }

    /**
     * Validasi apakah sumber gambar (src) berasal dari aset lokal tepercaya atau data URI gambar yang sah.
     */
    public static function isValidImageSource(string $src): bool
    {
        $src = trim($src);
        if ($src === '') {
            return false;
        }

        // Tolak protokol berbahaya secara eksplisit
        if (preg_match('/^(javascript|vbscript|file|ftp):/i', $src)) {
            return false;
        }

        // 1. Cek jika berupa data:image/(png|jpeg|jpg|webp);base64,...
        if (str_starts_with($src, 'data:image/')) {
            if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/i', $src, $matches)) {
                return false;
            }
            $decoded = base64_decode($matches[2], true);
            if ($decoded === false || strlen($decoded) > 2 * 1024 * 1024 || strlen($decoded) < 16) {
                return false;
            }
            $imgInfo = @getimagesizefromstring($decoded);
            if ($imgInfo === false) {
                return false;
            }
            return in_array($imgInfo['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true);
        }

        // 2. Cek jika berupa URL absolut ke aplikasi sendiri (konversi ke path lokal untuk validasi)
        $path = $src;
        if (preg_match('/^https?:\/\//i', $src)) {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $srcHost = parse_url($src, PHP_URL_HOST);
            if (!$srcHost || ($appHost && strtolower($srcHost) !== strtolower($appHost) && $srcHost !== '127.0.0.1' && $srcHost !== 'localhost')) {
                return false;
            }
            $path = (string) parse_url($src, PHP_URL_PATH);
        }

        // Cegah directory traversal
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }

        // Hanya izinkan direktori aset MOU resmi dan perpustakaan aset sistem
        $normalizedPath = '/' . ltrim($path, '/');
        if (
            str_starts_with($normalizedPath, '/images/mou/') ||
            str_starts_with($normalizedPath, '/images/logos/') ||
            str_starts_with($normalizedPath, '/images/signatures/') ||
            str_starts_with($normalizedPath, '/images/stamps/') ||
            str_starts_with($normalizedPath, '/images/brands/') ||
            str_starts_with($normalizedPath, '/storage/mou-assets/') ||
            str_starts_with($normalizedPath, '/storage/mou-templates/media/') ||
            str_starts_with($normalizedPath, '/storage/institutions/') ||
            str_starts_with($normalizedPath, '/storage/institutions/logos/') ||
            str_starts_with($normalizedPath, '/storage/brands/') ||
            str_starts_with($normalizedPath, '/admin/system-assets/')
        ) {
            return preg_match('/^\/[a-zA-Z0-9_\-\/\.]+(\.(png|jpg|jpeg|webp))?$/i', $normalizedPath) === 1;
        }

        return false;
    }

    /**
     * Bersihkan artefak pagination visual dari editor (.mou-a4-page, .mou-a4-page-header, .mou-a4-page-footer)
     * dan gabungkan kembali potongan lanjutan (.mou-pasal-continuation / .mou-table-continuation) ke elemen induk kanonisnya.
     */
    protected static function unwrapPaginationArtifactsDom(DOMDocument $dom, DOMXPath $xpath, DOMElement $root): void
    {
        // 1. Hapus header/footer visual kertas atau artefak pagination sementara
        $artifactNodes = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " mou-a4-page-header ") or contains(concat(" ", normalize-space(@class), " "), " mou-a4-page-footer ") or @data-pagination-artifact="true"]',
            $root
        );
        if ($artifactNodes) {
            $toRemove = [];
            foreach ($artifactNodes as $node) {
                $toRemove[] = $node;
            }
            foreach ($toRemove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // 2. Unwrap kontainer .mou-a4-page-body dan .mou-a4-page, serta normalisasi .mou-a4-pages-container menjadi .mou-document.mou-doc-canvas
        $stages = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " mou-a4-pages-container ")]',
            $root
        );
        if ($stages) {
            foreach ($stages as $stage) {
                if ($stage instanceof DOMElement) {
                    $stage->setAttribute('class', 'mou-document mou-doc-canvas');
                }
            }
        }

        $pageContainers = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " mou-a4-page-body ") or contains(concat(" ", normalize-space(@class), " "), " mou-a4-page ")]',
            $root
        );
        if ($pageContainers && $pageContainers->length > 0) {
            // Unwrap dari level terdalam ke terluar
            $containersList = [];
            foreach ($pageContainers as $c) {
                $containersList[] = $c;
            }
            foreach (array_reverse($containersList) as $container) {
                if (!$container->parentNode) {
                    continue;
                }
                while ($container->firstChild) {
                    $container->parentNode->insertBefore($container->firstChild, $container);
                }
                $container->parentNode->removeChild($container);
            }
        }

        // 3. Gabungkan kembali .mou-pasal-continuation ke .mou-pasal sebelumnya
        $continuationPasals = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " mou-pasal-continuation ") or @data-pasal-continuation="true"]',
            $root
        );
        if ($continuationPasals && $continuationPasals->length > 0) {
            $contList = [];
            foreach ($continuationPasals as $cp) {
                $contList[] = $cp;
            }
            foreach ($contList as $contNode) {
                if (!$contNode instanceof DOMElement || !$contNode->parentNode) {
                    continue;
                }

                // Cari elemen .mou-pasal sebelumnya pada level yang sama
                $prev = $contNode->previousSibling;
                while ($prev && $prev->nodeType !== XML_ELEMENT_NODE) {
                    $prev = $prev->previousSibling;
                }

                if ($prev instanceof DOMElement && (
                    str_contains(' ' . $prev->getAttribute('class') . ' ', ' mou-pasal ') ||
                    $prev->getAttribute('data-pasal') === 'true'
                )) {
                    $targetBody = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mou-pasal-body ")]', $prev)->item(0);
                    if (!$targetBody instanceof DOMElement) {
                        $targetBody = $prev;
                    }

                    $sourceBody = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mou-pasal-body ")]', $contNode)->item(0);
                    if (!$sourceBody instanceof DOMElement) {
                        $sourceBody = $contNode;
                    }

                    while ($sourceBody->firstChild) {
                        $child = $sourceBody->firstChild;
                        // Jika child adalah <ol class="mou-ayat-list"> dan elemen terakhir targetBody juga <ol class="mou-ayat-list">, gabungkan <li>-nya
                        if ($child instanceof DOMElement && strtolower($child->nodeName) === 'ol') {
                            $lastTargetEl = $targetBody->lastChild;
                            while ($lastTargetEl && $lastTargetEl->nodeType !== XML_ELEMENT_NODE) {
                                $lastTargetEl = $lastTargetEl->previousSibling;
                            }
                            if ($lastTargetEl instanceof DOMElement && strtolower($lastTargetEl->nodeName) === 'ol') {
                                while ($child->firstChild) {
                                    $lastTargetEl->appendChild($child->firstChild);
                                }
                                $sourceBody->removeChild($child);
                                continue;
                            }
                        }
                        $targetBody->appendChild($child);
                    }

                    $contNode->parentNode->removeChild($contNode);
                } else {
                    // Jika tidak ada .mou-pasal sebelumnya, jadikan .mou-pasal normal
                    $contNode->removeAttribute('data-pasal-continuation');
                    $cls = preg_replace('/\bmou-pasal-continuation\b/', '', $contNode->getAttribute('class'));
                    $contNode->setAttribute('class', trim(preg_replace('/\s+/', ' ', (string) $cls)));
                    $contNode->setAttribute('data-pasal', 'true');
                }
            }
        }

        // 4. Gabungkan kembali tabel yang terpecah (.mou-table-continuation) ke tabel sebelumnya
        $continuationTables = $xpath->query(
            './/table[contains(concat(" ", normalize-space(@class), " "), " mou-table-continuation ") or @data-table-continuation="true"]',
            $root
        );
        if ($continuationTables && $continuationTables->length > 0) {
            $tList = [];
            foreach ($continuationTables as $ct) {
                $tList[] = $ct;
            }
            foreach ($tList as $contTable) {
                if (!$contTable instanceof DOMElement || !$contTable->parentNode) {
                    continue;
                }
                $prev = $contTable->previousSibling;
                while ($prev && $prev->nodeType !== XML_ELEMENT_NODE) {
                    $prev = $prev->previousSibling;
                }
                if ($prev instanceof DOMElement && strtolower($prev->nodeName) === 'table') {
                    $targetTbody = $xpath->query('./tbody', $prev)->item(0) ?: $prev;
                    $sourceRows = $xpath->query('.//tr', $contTable);
                    if ($sourceRows) {
                        foreach ($sourceRows as $row) {
                            $targetTbody->appendChild($row);
                        }
                    }
                    $contTable->parentNode->removeChild($contTable);
                } else {
                    $contTable->removeAttribute('data-table-continuation');
                    $cls = preg_replace('/\bmou-table-continuation\b/', '', $contTable->getAttribute('class'));
                    $contTable->setAttribute('class', trim(preg_replace('/\s+/', ' ', (string) $cls)));
                }
            }
        }
    }

    /**
     * Normalisasi penomoran otomatis Pasal 1..N dan Ayat (1)..(M) pada blok semantik .mou-pasal / .mou-pasal-block.
     * Tidak mengubah template lama yang tidak memakai struktur .mou-pasal.
     */
    protected static function normalizePasalAndAyatDom(DOMDocument $dom, DOMXPath $xpath, DOMElement $root): void
    {
        $pasalNodes = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " mou-pasal ") or contains(concat(" ", normalize-space(@class), " "), " mou-pasal-block ") or @data-pasal="true"]',
            $root
        );
        if (!$pasalNodes || $pasalNodes->length === 0) {
            return;
        }

        $pasalIndex = 1;
        foreach ($pasalNodes as $pasalNode) {
            if (!$pasalNode instanceof DOMElement) {
                continue;
            }

            $pasalNode->setAttribute('data-pasal', 'true');

            // Update nomor Pasal pada elemen .mou-pasal-number, .mou-pasal-num, atau .mou-pasal-label
            $numberNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mou-pasal-number ")]', $pasalNode);
            $numNodes    = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mou-pasal-num ")]', $pasalNode);

            if ($numberNodes && $numberNodes->length > 0) {
                $numberNodes->item(0)->nodeValue = 'Pasal ' . $pasalIndex;
            } elseif ($numNodes && $numNodes->length > 0) {
                $numNodes->item(0)->nodeValue = (string) $pasalIndex;
            } else {
                $labelNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mou-pasal-label ")]', $pasalNode);
                if ($labelNodes && $labelNodes->length > 0) {
                    /** @var DOMElement $lbl */
                    $lbl = $labelNodes->item(0);
                    $lbl->nodeValue = 'Pasal ';
                    $span = $dom->createElement('span', (string) $pasalIndex);
                    $span->setAttribute('class', 'mou-pasal-num');
                    $lbl->appendChild($span);
                }
            }

            // Reset & urutkan ulang nomor Ayat (1), (2), (3) khusus di dalam Pasal ini
            $ayatItems = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mou-ayat-item ") or @data-ayat="true"]', $pasalNode);
            if ($ayatItems && $ayatItems->length > 0) {
                $ayatIndex = 1;
                foreach ($ayatItems as $ayatNode) {
                    if (!$ayatNode instanceof DOMElement) {
                        continue;
                    }
                    $ayatNode->setAttribute('data-ayat', 'true');
                    $ayatNumNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " mou-ayat-num ")]', $ayatNode);
                    if ($ayatNumNodes && $ayatNumNodes->length > 0) {
                        $ayatNumNodes->item(0)->nodeValue = '(' . $ayatIndex . ')';
                    }
                    $ayatIndex++;
                }
            }

            $pasalIndex++;
        }
    }

    /**
     * Hitung token tanggal Bahasa Indonesia secara deterministik berdasarkan agreement_date, effective_start, dan effective_end.
     */
    public static function computeDateAndDurationTokens(
        ?string $agreementDate,
        ?string $effectiveStart = null,
        ?string $effectiveEnd = null,
        ?string $defaultDuration = '5 (lima) tahun'
    ): array {
        $daysMap = [
            0 => 'Minggu',
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
        ];

        $monthsMap = [
            1  => 'Januari',
            2  => 'Februari',
            3  => 'Maret',
            4  => 'April',
            5  => 'Mei',
            6  => 'Juni',
            7  => 'Juli',
            8  => 'Agustus',
            9  => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        try {
            $dt = $agreementDate ? Carbon::parse($agreementDate) : now();
        } catch (\Throwable) {
            $dt = now();
        }

        $dayName = $daysMap[(int) $dt->dayOfWeek] ?? 'Senin';
        $dayNum  = (int) $dt->day;
        $monthNum = (int) $dt->month;
        $yearNum = (int) $dt->year;
        $monthName = $monthsMap[$monthNum] ?? 'Januari';

        $dayWords  = static::numberToWordsId($dayNum);
        $yearWords = static::numberToWordsId($yearNum);

        $dateWords = "Tanggal {$dayWords}, bulan {$monthName} tahun {$yearWords}";
        $dateShort = $dt->format('d-m-Y');
        $dateLong  = "{$dayNum} {$monthName} {$yearNum}";

        // Format effective_start & effective_end
        $formattedStart = '-';
        $formattedEnd   = '-';
        $startCarbon    = null;
        $endCarbon      = null;

        if (!empty($effectiveStart) && $effectiveStart !== '-') {
            try {
                $startCarbon = Carbon::parse($effectiveStart);
                $formattedStart = (int) $startCarbon->day . ' ' . ($monthsMap[(int) $startCarbon->month] ?? '') . ' ' . $startCarbon->year;
            } catch (\Throwable) {
                $formattedStart = (string) $effectiveStart;
            }
        }

        if (!empty($effectiveEnd) && $effectiveEnd !== '-') {
            try {
                $endCarbon = Carbon::parse($effectiveEnd);
                $formattedEnd = (int) $endCarbon->day . ' ' . ($monthsMap[(int) $endCarbon->month] ?? '') . ' ' . $endCarbon->year;
            } catch (\Throwable) {
                $formattedEnd = (string) $effectiveEnd;
            }
        }

        // Hitung durasi otomatis jika start & end valid
        $durationText = $defaultDuration ?: '5 (lima) tahun';
        if ($startCarbon && $endCarbon && $endCarbon->greaterThan($startCarbon)) {
            $diffYears = (int) round($startCarbon->floatDiffInYears($endCarbon));
            if ($diffYears >= 1) {
                $durationText = $diffYears . ' (' . static::numberToWordsId($diffYears) . ') tahun';
            } else {
                $diffMonths = max(1, (int) round($startCarbon->floatDiffInMonths($endCarbon)));
                $durationText = $diffMonths . ' (' . static::numberToWordsId($diffMonths) . ') bulan';
            }
        }

        return [
            'agreement_day'          => $dayName,
            'agreement_day_name'     => $dayName,
            'agreement_day_number'   => sprintf('%02d', $dayNum),
            'agreement_day_words'    => ucwords($dayWords),
            'agreement_month_name'   => $monthName,
            'agreement_month_number' => sprintf('%02d', $monthNum),
            'agreement_year_number'  => (string) $yearNum,
            'agreement_year_words'   => ucwords($yearWords),
            'agreement_date'         => $dateLong,
            'agreement_date_short'   => $dateShort,
            'agreement_date_words'   => $dateWords,
            'effective_start'        => $formattedStart,
            'effective_end'          => $formattedEnd,
            'agreement_duration'     => $durationText,
        ];
    }

    /**
     * Konversi bilangan bulat positif ke kata terbilang Bahasa Indonesia.
     */
    public static function numberToWordsId(int $number): string
    {
        $number = abs($number);
        $words = [
            '', 'satu', 'dua', 'tiga', 'empat', 'lima',
            'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
        ];

        if ($number < 12) {
            return $words[$number];
        }
        if ($number < 20) {
            return static::numberToWordsId($number - 10) . ' belas';
        }
        if ($number < 100) {
            $puluh = (int) floor($number / 10);
            $sisa  = $number % 10;
            return trim(static::numberToWordsId($puluh) . ' puluh ' . static::numberToWordsId($sisa));
        }
        if ($number < 200) {
            return trim('seratus ' . static::numberToWordsId($number - 100));
        }
        if ($number < 1000) {
            $ratus = (int) floor($number / 100);
            $sisa  = $number % 100;
            return trim(static::numberToWordsId($ratus) . ' ratus ' . static::numberToWordsId($sisa));
        }
        if ($number < 2000) {
            return trim('seribu ' . static::numberToWordsId($number - 1000));
        }
        if ($number < 1000000) {
            $ribu = (int) floor($number / 1000);
            $sisa = $number % 1000;
            return trim(static::numberToWordsId($ribu) . ' ribu ' . static::numberToWordsId($sisa));
        }

        return (string) $number;
    }

    /**
     * Render HTML tag gambar logo dari sumber tepercaya.
     * Mendukung opsi embed Base64 langsung untuk snapshot dokumen terbit & PDF.
     */
    public static function renderTrustedLogoTag(?string $logoPath, string $alt, int $heightPx = 85, bool $embedBase64 = false): string
    {
        if (empty($logoPath) || $logoPath === 'none') {
            return '';
        }

        if (!static::isValidImageSource($logoPath)) {
            return '';
        }

        $finalSrc = $logoPath;
        if ($embedBase64 && !str_starts_with($logoPath, 'data:image/')) {
            $base64 = static::convertLocalPathToBase64($logoPath);
            if ($base64) {
                $finalSrc = $base64;
            }
        }

        $safeAlt = e($alt);
        $safeSrc = e($finalSrc);

        return '<img src="' . $safeSrc . '" alt="' . $safeAlt . '" class="mou-header-logo" style="height: ' . $heightPx . 'px; width: auto; max-width: 130px; object-fit: contain; display: inline-block; vertical-align: middle;">';
    }

    /**
     * Ubah seluruh tag <img src="/images/mou/..."> atau <img src="/storage/mou-assets/..."> di dalam HTML menjadi data:image/...;base64.
     */
    public static function embedLocalImagesAsBase64(string $html): string
    {
        if (empty($html) || !str_contains($html, '<img')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<img\b([^>]*?)>/i',
            function (array $matches) {
                $attrsStr = $matches[1];
                if (!preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrsStr, $srcMatch)) {
                    return $matches[0];
                }
                $src = trim($srcMatch[1]);
                if (str_starts_with($src, 'data:image/')) {
                    return $matches[0];
                }

                // Resolusi data-asset-id atau URL preview /admin/system-assets/{id}/preview
                $assetId = null;
                if (preg_match('/\bdata-asset-id\s*=\s*["\'](\d+)["\']/i', $attrsStr, $assetMatch)) {
                    $assetId = (int) $assetMatch[1];
                } elseif (preg_match('#^/admin/system-assets/(\d+)/preview#i', $src, $urlMatch)) {
                    $assetId = (int) $urlMatch[1];
                }

                if ($assetId) {
                    try {
                        /** @var \App\Services\SystemAssetService $assetService */
                        $assetService = app(\App\Services\SystemAssetService::class);
                        $base64 = $assetService->getAssetAsBase64DataUri($assetId);
                        if ($base64) {
                            return preg_replace('/\bsrc\s*=\s*["\'][^"\']+["\']/i', 'src="' . $base64 . '"', $matches[0]);
                        }
                    } catch (\Throwable) {
                        // Fallback ke pengecekan path lokal
                    }
                }

                if (!static::isValidImageSource($src)) {
                    return '';
                }
                $base64 = static::convertLocalPathToBase64($src);
                if ($base64) {
                    return preg_replace('/\bsrc\s*=\s*["\'][^"\']+["\']/i', 'src="' . $base64 . '"', $matches[0]);
                }
                return $matches[0];
            },
            $html
        );
    }

    /**
     * Konversi path lokal (/images/mou/... atau /storage/mou-assets/...) menjadi string data URI Base64.
     */
    public static function convertLocalPathToBase64(string $src): ?string
    {
        if (str_starts_with($src, 'data:image/')) {
            return $src;
        }

        $path = $src;
        if (preg_match('/^https?:\/\//i', $src)) {
            $path = (string) parse_url($src, PHP_URL_PATH);
        }

        $normalized = '/' . ltrim($path, '/');
        if (str_contains($normalized, '..')) {
            return null;
        }

        $candidates = [
            public_path(ltrim($normalized, '/')),
        ];

        if (str_starts_with($normalized, '/storage/')) {
            $relStorage = substr($normalized, strlen('/storage/'));
            $candidates[] = Storage::disk('public')->path($relStorage);
            $candidates[] = storage_path('app/public/' . $relStorage);
        } else {
            $candidates[] = Storage::disk('public')->path(ltrim($normalized, '/'));
            $candidates[] = storage_path('app/public/' . ltrim($normalized, '/'));
        }

        foreach ($candidates as $filePath) {
            if ($filePath && is_file($filePath) && is_readable($filePath)) {
                $bytes = @file_get_contents($filePath);
                if ($bytes === false || strlen($bytes) > 3 * 1024 * 1024) {
                    continue;
                }
                $imgInfo = @getimagesizefromstring($bytes);
                $mime = $imgInfo['mime'] ?? File::mimeType($filePath);
                if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
            }
        }

        return null;
    }

    /**
     * Render konten template MOU dengan mengganti token teks (escaped), token tanggal terkomputasi, dan token logo.
     *
     * @param  MouTemplate  $template
     * @param  array        $data
     * @param  bool         $forPublishedSnapshot  Jika true, hasilkan snapshot mandiri dengan Base64 images & CSS tersemat.
     */
    public static function renderTemplate(MouTemplate $template, array $data = [], bool $forPublishedSnapshot = false): string
    {
        $sanitizedHtml = static::sanitizeEditableTemplate($template->template_html, true);
        if ($sanitizedHtml === '') {
            return '';
        }

        $defaults    = $template->getCompanyDefaultValues();
        $mediaAssets = $template->getMediaAssets();
        $pageConfig  = $template->getPageConfig();

        $merged = array_merge($defaults, $data);

        // Hitung token tanggal & durasi jika belum tersedia lengkap
        $computedDates = static::computeDateAndDurationTokens(
            $merged['raw_agreement_date'] ?? ($merged['agreement_date'] ?? null),
            $merged['raw_effective_start'] ?? ($merged['effective_start'] ?? null),
            $merged['raw_effective_end'] ?? ($merged['effective_end'] ?? null),
            $merged['agreement_duration'] ?? ($defaults['agreement_duration'] ?? '5 (lima) tahun')
        );

        foreach ($computedDates as $k => $v) {
            if (empty($data[$k])) {
                $merged[$k] = $v;
            }
        }

        // Pastikan nilai default untuk fax & correspondence unit terisi
        $merged['institution_fax']               = $merged['institution_fax'] ?? '-';
        $merged['institution_correspondence_unit'] = $merged['institution_correspondence_unit'] ?? 'Kepala Bidang Kerjasama Dalam Negeri';
        $merged['company_fax']                   = $merged['company_fax'] ?? ($defaults['company_fax'] ?? '-');
        $merged['company_correspondence_unit']   = $merged['company_correspondence_unit'] ?? ($defaults['company_correspondence_unit'] ?? 'CEO');

        // Resolusi token gambar logo (Koreksi Wajib 3: Jangan gunakan logo UMPO untuk institusi umum)
        $companyLogoPath = $merged['company_logo_path']
            ?? ($mediaAssets['company_logo'] ?? '/images/mou/seven-inc-logo.png');

        $institutionLogoPath = $merged['institution_logo_path']
            ?? ($mediaAssets['institution_logo'] ?? '/images/mou/institution-logo-placeholder.png');

        $companyLogoHtml = static::renderTrustedLogoTag(
            $companyLogoPath,
            (string) ($merged['company_name'] ?? 'SEVEN INC'),
            85,
            $forPublishedSnapshot
        );

        $institutionLogoHtml = static::renderTrustedLogoTag(
            $institutionLogoPath,
            (string) ($merged['institution_name'] ?? 'Institusi Mitra'),
            85,
            $forPublishedSnapshot
        );

        $rendered = $sanitizedHtml;

        // Normalisasi tabel logo baris lama agar logo tidak mengalir sebagai teks/tabel inline
        if (preg_match('/<table\b[^>]*class=["\'][^"\']*mou-logo-row-table[\s\S]*?<\/table>/i', $rendered)) {
            $rendered = preg_replace(
                '/<table\b[^>]*class=["\'][^"\']*mou-logo-row-table[\s\S]*?<\/table>/i',
                '<p style="text-align: center; margin-bottom: 6pt;"><strong>ANTARA</strong></p>',
                $rendered
            );
        }

        // 1. Ganti token gambar terlebih dahulu
        $imageTokens = [
            'company_logo'           => $companyLogoHtml,
            'institution_logo'       => $institutionLogoHtml,
            'asset.company.logo'     => $companyLogoHtml,
            'asset.institution.logo' => $institutionLogoHtml,
        ];

        foreach ($imageTokens as $token => $imgTag) {
            $rendered = str_replace([
                '[' . $token . ']',
                '{{' . $token . '}}',
                '{{ ' . $token . ' }}',
            ], $imgTag, $rendered);
        }

        // 2. Selesaikan seluruh variabel terdaftar dari VariableRegistry (termasuk alias & dotted tokens)
        $registryDefinitions = class_exists(\App\Services\MailMerge\VariableRegistry::class)
            ? \App\Services\MailMerge\VariableRegistry::getDefinitions()
            : [];

        foreach ($registryDefinitions as $mainToken => $def) {
            $val = null;
            if (array_key_exists($mainToken, $merged)) {
                $val = $merged[$mainToken];
            } else {
                foreach ($def['aliases'] ?? [] as $alias) {
                    if (array_key_exists($alias, $merged)) {
                        $val = $merged[$alias];
                        break;
                    }
                }
            }

            if ($val !== null && !isset($imageTokens[$mainToken])) {
                $safeVal = e((string) $val);
                $rendered = str_replace([
                    '{{' . $mainToken . '}}',
                    '{{ ' . $mainToken . ' }}',
                    '[' . $mainToken . ']',
                ], $safeVal, $rendered);

                foreach ($def['aliases'] ?? [] as $alias) {
                    $rendered = str_replace([
                        '[' . $alias . ']',
                        '{{' . $alias . '}}',
                        '{{ ' . $alias . ' }}',
                    ], $safeVal, $rendered);
                }
            }
        }

        // 3. Ganti seluruh token teks & tanggal template dengan HTML escaping ketat
        $allPlaceholders = array_unique(array_merge(
            array_keys(MouTemplate::getDefaultPlaceholders()),
            $template->allowed_placeholders ?? []
        ));

        foreach ($allPlaceholders as $rawPlaceholder) {
            $placeholder = trim((string) $rawPlaceholder, '[]{} ');
            if ($placeholder === '' || isset($imageTokens[$placeholder])) {
                continue;
            }

            if (array_key_exists($placeholder, $merged)) {
                $safeValue = e((string) $merged[$placeholder]);
                $rendered = str_replace([
                    '[' . $placeholder . ']',
                    '{{' . $placeholder . '}}',
                    '{{ ' . $placeholder . ' }}',
                ], $safeValue, $rendered);
            }
        }

        // 3. Normalisasi tabel korespondensi agar proporsional, rapat, dan rapi
        $rendered = static::normalizeCorrespondenceTablesHtml($rendered);

        // 4. Jika untuk snapshot terbit (Published MOU) atau PDF, konversi semua gambar lokal di dalam naskah ke Base64
        if ($forPublishedSnapshot) {
            $rendered = static::embedLocalImagesAsBase64($rendered);
            $inlineCss = static::getCanonicalDocumentCss($pageConfig);

            $showHeaderLogos = (bool) ($pageConfig['show_header_logos'] ?? true);
            $showWatermark   = (bool) ($pageConfig['show_watermark'] ?? false);

            $docType = strtolower((string) ($template->type ?? ($pageConfig['document_type'] ?? 'mou')));
            $isMou   = ($docType === 'mou');

            $watermarkHeaderHtml = '';
            if ($isMou && ($showHeaderLogos || $showWatermark)) {
                $instSrc = !str_starts_with($institutionLogoPath, 'data:') ? (static::convertLocalPathToBase64($institutionLogoPath) ?? $institutionLogoPath) : $institutionLogoPath;
                $compSrc = !str_starts_with($companyLogoPath, 'data:') ? (static::convertLocalPathToBase64($companyLogoPath) ?? $companyLogoPath) : $companyLogoPath;

                $watermarkHeaderHtml = '<div class="mou-watermark-layer" data-watermark-layer="true">';
                if ($showHeaderLogos) {
                    $watermarkHeaderHtml .= '<div class="mou-watermark-fixed-header">'
                        . '<img src="' . e($instSrc) . '" width="66" height="66" class="mou-watermark-logo-left" alt="Logo Institusi" />'
                        . '<img src="' . e($compSrc) . '" width="66" height="66" class="mou-watermark-logo-right" alt="Logo Seven Inc" />'
                        . '</div>';
                }
                if ($showWatermark) {
                    $watermarkHeaderHtml .= '<img src="' . e($compSrc) . '" class="mou-watermark-center" alt="Watermark" />';
                }
                $watermarkHeaderHtml .= '</div>';
            }

            return '<div class="mou-published-snapshot" data-snapshot-version="2">'
                . '<style class="mou-canonical-snapshot-style">' . $inlineCss . '</style>'
                . '<div class="mou-doc-canvas" style="position: relative;">'
                . $watermarkHeaderHtml
                . $rendered
                . '</div>'
                . '</div>';
        }

        return static::sanitizeEditableTemplate($rendered, false);
    }

    /**
     * Render konten Header atau Footer HTML dengan mengganti token teks, tanggal, dan logo secara aman.
     */
    public static function renderHeaderFooterHtml(
        MouTemplate $template,
        ?string $rawHtml,
        array $data = [],
        bool $forPublishedSnapshot = false
    ): string {
        $sanitized = static::sanitizeHeaderFooterHtml($rawHtml);
        if ($sanitized === '') {
            return '';
        }

        $defaults    = $template->getCompanyDefaultValues();
        $mediaAssets = $template->getMediaAssets();
        $merged      = array_merge($defaults, $data);

        $computedDates = static::computeDateAndDurationTokens(
            $merged['raw_agreement_date'] ?? ($merged['agreement_date'] ?? null),
            $merged['raw_effective_start'] ?? ($merged['effective_start'] ?? null),
            $merged['raw_effective_end'] ?? ($merged['effective_end'] ?? null),
            $merged['agreement_duration'] ?? ($defaults['agreement_duration'] ?? '5 (lima) tahun')
        );

        foreach ($computedDates as $k => $v) {
            if (empty($data[$k])) {
                $merged[$k] = $v;
            }
        }

        $companyLogoPath = $merged['company_logo_path']
            ?? ($mediaAssets['company_logo'] ?? '/images/mou/seven-inc-logo.png');
        $institutionLogoPath = $merged['institution_logo_path']
            ?? ($mediaAssets['institution_logo'] ?? '/images/mou/institution-logo-placeholder.png');

        $imageTokens = [
            'company_logo'           => static::renderTrustedLogoTag($companyLogoPath, (string) ($merged['company_name'] ?? 'SEVEN INC'), 48, $forPublishedSnapshot),
            'institution_logo'       => static::renderTrustedLogoTag($institutionLogoPath, (string) ($merged['institution_name'] ?? 'Institusi Mitra'), 48, $forPublishedSnapshot),
            'asset.company.logo'     => static::renderTrustedLogoTag($companyLogoPath, (string) ($merged['company_name'] ?? 'SEVEN INC'), 48, $forPublishedSnapshot),
            'asset.institution.logo' => static::renderTrustedLogoTag($institutionLogoPath, (string) ($merged['institution_name'] ?? 'Institusi Mitra'), 48, $forPublishedSnapshot),
        ];

        $rendered = $sanitized;
        foreach ($imageTokens as $token => $imgTag) {
            $rendered = str_replace(['[' . $token . ']', '{{' . $token . '}}', '{{ ' . $token . ' }}'], $imgTag, $rendered);
        }

        $registryDefinitions = class_exists(\App\Services\MailMerge\VariableRegistry::class)
            ? \App\Services\MailMerge\VariableRegistry::getDefinitions()
            : [];

        foreach ($registryDefinitions as $mainToken => $def) {
            $val = null;
            if (array_key_exists($mainToken, $merged)) {
                $val = $merged[$mainToken];
            } else {
                foreach ($def['aliases'] ?? [] as $alias) {
                    if (array_key_exists($alias, $merged)) {
                        $val = $merged[$alias];
                        break;
                    }
                }
            }

            if ($val !== null && !isset($imageTokens[$mainToken])) {
                $safeVal = e((string) $val);
                $rendered = str_replace([
                    '{{' . $mainToken . '}}',
                    '{{ ' . $mainToken . ' }}',
                    '[' . $mainToken . ']',
                ], $safeVal, $rendered);

                foreach ($def['aliases'] ?? [] as $alias) {
                    $rendered = str_replace([
                        '[' . $alias . ']',
                        '{{' . $alias . '}}',
                        '{{ ' . $alias . ' }}',
                    ], $safeVal, $rendered);
                }
            }
        }

        $allPlaceholders = array_unique(array_merge(
            array_keys(MouTemplate::getDefaultPlaceholders()),
            $template->allowed_placeholders ?? []
        ));

        foreach ($allPlaceholders as $rawPlaceholder) {
            $placeholder = trim((string) $rawPlaceholder, '[]{} ');
            if ($placeholder === '' || isset($imageTokens[$placeholder])) {
                continue;
            }
            if (array_key_exists($placeholder, $merged)) {
                $safeValue = e((string) $merged[$placeholder]);
                $rendered = str_replace([
                    '[' . $placeholder . ']',
                    '{{' . $placeholder . '}}',
                    '{{ ' . $placeholder . ' }}',
                ], $safeValue, $rendered);
            }
        }

        if ($forPublishedSnapshot) {
            $rendered = static::embedLocalImagesAsBase64($rendered);
        }

        return static::sanitizeHeaderFooterHtml($rendered);
    }

    /**
     * Render seluruh konfigurasi halaman (_page_config) beserta resolusi placeholder di dalam Header/Footer.
     */
    public static function renderPageConfig(
        MouTemplate $template,
        array $data = [],
        bool $forPublishedSnapshot = false
    ): array {
        $config = $template->getPageConfig();

        foreach (['header_html', 'footer_html', 'first_page_header_html', 'first_page_footer_html'] as $field) {
            if (!empty($config[$field])) {
                $config[$field] = static::renderHeaderFooterHtml($template, (string) $config[$field], $data, $forPublishedSnapshot);
            }
        }

        return $config;
    }

    /**
     * Estimasi tinggi blok Header atau Footer dalam satuan milimeter (mm) berdasarkan atribut tinggi baris/elemen,
     * agar ruang margin @page di DomPDF secara dinamis menyesuaikan ketika Tabel Paraf atau Header diperbesar.
     */
    public static function estimateBlockHeightMm(?string $html, float $defaultMm = 16.0): float
    {
        $clean = trim((string) $html);
        if ($clean === '') {
            return 0.0;
        }

        $toMm = static function (float $val, string $unit): float {
            return match (strtolower($unit)) {
                'mm' => $val,
                'cm' => $val * 10.0,
                'pt' => $val * 0.352778,
                'px' => $val * 0.264583,
                default => $val * 0.264583,
            };
        };

        $totalRowsMm = 0.0;

        // Jika terdapat tabel (seperti Tabel Paraf), hitung akumulasi tinggi setiap <tr>
        if (preg_match_all('/<tr\b([^>]*)>([\s\S]*?)<\/tr>/i', $clean, $rowMatches, PREG_SET_ORDER)) {
            foreach ($rowMatches as $rm) {
                $trAttrs = $rm[1];
                $trInner = $rm[2];
                $rowMm = 6.5; // tinggi default 1 baris tabel (~18pt)

                if (preg_match('/data-row-height\s*=\s*["\'](\d+(?:\.\d+)?)(mm|cm|pt|px)["\']/i', $trAttrs, $m)) {
                    $rowMm = max($rowMm, $toMm((float) $m[1], $m[2]));
                } elseif (preg_match('/\bheight\s*:\s*(\d+(?:\.\d+)?)(mm|cm|pt|px)/i', $trAttrs, $m)) {
                    $rowMm = max($rowMm, $toMm((float) $m[1], $m[2]));
                } elseif (preg_match('/\bheight\s*:\s*(\d+(?:\.\d+)?)(mm|cm|pt|px)/i', $trInner, $m)) {
                    $rowMm = max($rowMm, $toMm((float) $m[1], $m[2]));
                }

                $totalRowsMm += $rowMm + 1.5;
            }
        }

        // Cek juga jika ada gambar di dalam header/footer
        $maxImgMm = 0.0;
        if (preg_match_all('/<img\b[^>]*>/i', $clean, $imgMatches)) {
            foreach ($imgMatches[0] as $imgTag) {
                if (preg_match('/\bheight\s*:\s*(\d+(?:\.\d+)?)(mm|cm|pt|px)/i', $imgTag, $m)) {
                    $maxImgMm = max($maxImgMm, $toMm((float) $m[1], $m[2]) + 4.0);
                } elseif (preg_match('/\bheight\s*=\s*["\']?(\d+(?:\.\d+)?)/i', $imgTag, $m)) {
                    $maxImgMm = max($maxImgMm, ((float) $m[1]) * 0.264583 + 4.0);
                } else {
                    $maxImgMm = max($maxImgMm, 18.0);
                }
            }
        }

        // Hitung paragraf di luar tabel
        $withoutTables = (string) preg_replace('/<table\b[\s\S]*?<\/table>/i', '', $clean);
        $pCount = preg_match_all('/<(p|h1|h2|h3|h4|div)\b/i', $withoutTables);
        $textMm = $pCount > 0 ? ($pCount * 5.5) : 0.0;

        $estimated = max($defaultMm, $totalRowsMm + $textMm, $maxImgMm);
        return round(min(90.0, $estimated), 2);
    }

    /**
     * Alias instance/static untuk mengambil CSS kanonis dokumen A4.
     */
    public static function getCanonicalCss(array $pageConfig = []): string
    {
        return static::getCanonicalDocumentCss($pageConfig);
    }

    /**
     * Hasilkan CSS kanonis untuk tampilan dokumen MOU A4 (digunakan bersama di Editor, Preview, Print, Snapshot, dan PDF).
     */
    public static function getCanonicalDocumentCss(array $pageConfig = []): string
    {
        $fontFamily = $pageConfig['font_family'] ?? 'Times New Roman';
        $fontSizePt = (int) ($pageConfig['font_size_pt'] ?? 12);
        $lineHeight = (float) ($pageConfig['line_height'] ?? 1.15);

        return <<<CSS
.mou-doc-canvas, .mou-document {
    font-family: '{$fontFamily}', Times, serif;
    font-size: {$fontSizePt}pt;
    line-height: {$lineHeight};
    color: #000000;
    margin: 0;
    padding: 0;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.mou-doc-canvas p, .mou-document p {
    margin-top: 0;
    margin-bottom: 6pt;
    orphans: 2;
    widows: 2;
}
.mou-doc-canvas ul, .mou-document ul {
    list-style-type: disc !important;
    padding-left: 24pt !important;
    margin-top: 0 !important;
    margin-bottom: 6pt !important;
}
.mou-doc-canvas ul ul, .mou-document ul ul {
    list-style-type: circle !important;
}
.mou-doc-canvas ul ul ul, .mou-document ul ul ul {
    list-style-type: square !important;
}
.mou-doc-canvas ol, .mou-document ol {
    list-style-type: decimal !important;
    padding-left: 24pt !important;
    margin-top: 0 !important;
    margin-bottom: 6pt !important;
}
.mou-doc-canvas ol[type="a"], .mou-document ol[type="a"] {
    list-style-type: lower-alpha !important;
}
.mou-doc-canvas ol[type="A"], .mou-document ol[type="A"] {
    list-style-type: upper-alpha !important;
}
.mou-doc-canvas ol[type="i"], .mou-document ol[type="i"] {
    list-style-type: lower-roman !important;
}
.mou-doc-canvas ol[type="I"], .mou-document ol[type="I"] {
    list-style-type: upper-roman !important;
}
.mou-doc-canvas li, .mou-document li {
    display: list-item !important;
    margin-bottom: 2pt !important;
}
.mou-doc-canvas li > p, .mou-document li > p {
    display: inline !important;
    margin-bottom: 0 !important;
}
.mou-doc-canvas td p, .mou-document td p,
.mou-doc-canvas th p, .mou-document th p,
.mou-paraf-table td p, .mou-paraf-table th p,
.mou-pdf-running-header td p, .mou-pdf-running-footer td p {
    margin-top: 0;
    margin-bottom: 0;
}
.mou-doc-canvas table, .mou-document table {
    border-collapse: collapse;
    table-layout: fixed;
    box-sizing: border-box;
    width: 100%;
}
.mou-doc-canvas td, .mou-document td,
.mou-doc-canvas th, .mou-document th {
    vertical-align: top;
    padding: 3pt 6pt;
    box-sizing: border-box;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-all;
}
.mou-doc-canvas img, .mou-document img {
    max-width: 100%;
    height: auto;
}
.mou-doc-canvas img[align="left"], .mou-document img[align="left"] {
    float: left;
    margin-right: 12pt;
    margin-bottom: 6pt;
}
.mou-doc-canvas img[align="right"], .mou-document img[align="right"] {
    float: right;
    margin-left: 12pt;
    margin-bottom: 6pt;
}
.mou-doc-canvas img[align="center"], .mou-document img[align="center"] {
    display: block;
    margin-left: auto;
    margin-right: auto;
}
.mou-header-block {
    text-align: center;
    font-weight: bold;
    margin-bottom: 12pt;
    page-break-inside: avoid;
    break-inside: avoid;
}
/* Fixed Watermark & Fixed Logo Layer (stays in fixed position, does not flow with text) */
.mou-watermark-layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    user-select: none;
    z-index: 1;
    overflow: hidden;
    box-sizing: border-box;
}
.mou-watermark-fixed-header {
    position: absolute;
    top: 18mm;
    left: 25.4mm;
    right: 25.4mm;
    pointer-events: none;
    user-select: none;
    box-sizing: border-box;
    height: 86px;
}
.mou-watermark-logo-left {
    position: absolute;
    top: 0;
    left: 0;
    height: 78px;
    width: auto;
    max-width: 120px;
    object-fit: contain;
    pointer-events: none;
    user-select: none;
}
.mou-watermark-logo-right {
    position: absolute;
    top: 0;
    right: 0;
    height: 78px;
    width: auto;
    max-width: 120px;
    object-fit: contain;
    pointer-events: none;
    user-select: none;
}
.mou-watermark-center {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 320px;
    max-width: 70%;
    opacity: 0.05;
    pointer-events: none;
    user-select: none;
}
.mou-logo-row-table {
    width: 100%;
    border-collapse: collapse;
    border: none;
    margin: 4pt 0 8pt 0;
}
.mou-logo-row-table td {
    border: none;
    vertical-align: middle;
    padding: 2pt 4pt;
}
.mou-table-borderless {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    border: none;
    margin-top: 4pt;
    margin-bottom: 8pt;
}
.mou-table-borderless td, .mou-table-borderless th {
    border: none;
    padding: 2pt 4pt;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-all;
}
.mou-table-borderless td:first-child, .mou-table-borderless th:first-child {
    padding-right: 0 !important;
}
.mou-table-borderless td:nth-child(2), .mou-table-borderless th:nth-child(2) {
    padding-left: 2pt !important;
}
.mou-table-bordered {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    border: 1px solid #000000;
    margin-top: 6pt;
    margin-bottom: 10pt;
}
.mou-table-bordered td, .mou-table-bordered th {
    border: 1px solid #000000;
    padding: 3pt 6pt;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-all;
}
.mou-table-bordered tr, .mou-table-borderless tr {
    page-break-inside: avoid;
    break-inside: avoid;
}
.mou-correspondence-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin-top: 4pt;
    margin-bottom: 6pt;
}
.mou-correspondence-table tr {
    page-break-inside: avoid;
    break-inside: avoid;
}
.mou-correspondence-table td, .mou-correspondence-table th {
    border: 1px solid #000000;
    padding: 2.5pt 5pt;
    vertical-align: top;
}
.mou-pasal {
    margin-top: 10pt;
    margin-bottom: 10pt;
}
.mou-pasal-continuation {
    margin-top: 0;
    margin-bottom: 10pt;
}
.mou-pasal-heading {
    text-align: center;
    font-weight: bold;
    margin-bottom: 4pt;
    page-break-after: avoid;
    break-after: avoid;
    page-break-inside: avoid;
    break-inside: avoid;
}
.mou-pasal-label {
    font-weight: bold;
}
.mou-pasal-title {
    font-weight: bold;
    text-transform: uppercase;
}
.mou-ayat-list {
    list-style: none;
    margin: 0 0 6pt 0;
    padding: 0;
}
.mou-ayat-item {
    display: table;
    width: 100%;
    margin-bottom: 4pt;
    page-break-inside: avoid;
    break-inside: avoid;
}
.mou-ayat-num {
    display: table-cell;
    width: 28pt;
    font-weight: bold;
    vertical-align: top;
    padding-right: 4pt;
}
.mou-ayat-text {
    display: table-cell;
    vertical-align: top;
    text-align: justify;
    font-weight: normal;
}
.mou-signature-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    border: none;
    margin-top: 18pt;
    margin-bottom: 12pt;
    page-break-inside: avoid;
    break-inside: avoid;
}
.mou-signature-table td {
    border: none;
    vertical-align: top;
    padding: 4pt 8pt;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-all;
}
.mou-paraf-table {
    width: 220pt;
    border-collapse: collapse;
    table-layout: fixed;
    border: 1px solid #000000;
    font-size: 8.5pt;
    page-break-inside: avoid;
    break-inside: avoid;
}
.mou-paraf-table td, .mou-paraf-table th {
    border: 1px solid #000000;
    padding: 2pt 6pt;
    text-align: center;
}
.mou-textbox {
    border: 1px solid #000000;
    padding: 8pt 10pt;
    margin: 6pt 0;
    background-color: #ffffff;
    box-sizing: border-box;
}
.mou-page-break {
    page-break-before: always;
    break-before: page;
    height: 0;
    margin: 0;
    border: none;
}
CSS;
    }

    /**
     * Normalisasi tabel korespondensi Pasal 5 (3 kolom: label, titik dua, dan kontak):
     * 1. Menyatukan dua tabel korespondensi berurutan (PIHAK KESATU & PIHAK KEDUA) menjadi satu kesatuan rapi.
     * 2. Menetapkan proporsi kolom persentase presisi (22%, 4%, 74%) agar tidak terjadi jarak kosong lebar di DomPDF.
     * 3. Menerapkan page-break-inside: avoid per baris (bukan per tabel penuh) agar tidak memicu lompatan halaman kosong.
     */
    public static function normalizeCorrespondenceTablesHtml(string $html): string
    {
        if (!str_contains($html, 'mou-correspondence-table')) {
            return $html;
        }

        // 1. Jika ada dua tabel korespondensi berurutan (PIHAK KESATU & PIHAK KEDUA), satukan baris-barisnya ke tabel pertama
        $html = preg_replace_callback(
            '/(<table[^>]*class="[^"]*mou-correspondence-table[^"]*"[^>]*>[\s\S]*?<\/table>)\s*(<table[^>]*class="[^"]*mou-correspondence-table[^"]*"[^>]*>[\s\S]*?<\/table>)/i',
            function ($matches) {
                $t1 = $matches[1];
                $t2 = $matches[2];

                preg_match('/<tbody[^>]*>([\s\S]*?)<\/tbody>/i', $t1, $tb1);
                preg_match('/<tbody[^>]*>([\s\S]*?)<\/tbody>/i', $t2, $tb2);

                $rows1 = $tb1[1] ?? '';
                $rows2 = $tb2[1] ?? '';

                return '<table class="mou-table-bordered mou-correspondence-table" data-table-style="bordered" data-table-width="100%" style="width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 4pt; margin-bottom: 6pt;">' .
                       '<tbody>' . $rows1 . $rows2 . '</tbody>' .
                       '</table>';
            },
            $html
        );

        // 2. Normalisasi setiap tabel korespondensi: colgroup proporsional dan style sel per baris
        return preg_replace_callback('/<table[^>]*class="[^"]*mou-correspondence-table[^"]*"[^>]*>[\s\S]*?<\/table>/is', function ($matches) {
            $tableHtml = $matches[0];

            // Pasang colgroup proporsional 22%, 4%, 74%
            $newColgroup = '<colgroup><col style="width: 22%;"><col style="width: 4%;"><col style="width: 74%;"></colgroup>';
            if (preg_match('/<colgroup>[\s\S]*?<\/colgroup>/is', $tableHtml)) {
                $tableHtml = preg_replace('/<colgroup>[\s\S]*?<\/colgroup>/is', $newColgroup, $tableHtml);
            } else {
                $tableHtml = preg_replace('/(<table[^>]*>)/i', '$1' . $newColgroup, $tableHtml);
            }

            // Atur style tabel agar table-layout: fixed dan margin rapat
            $tableHtml = preg_replace_callback('/<table\b([^>]*)>/i', function ($tm) {
                $attrs = $tm[1];
                if (preg_match('/style="([^"]*)"/i', $attrs, $sm)) {
                    $newStyle = 'width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 4pt; margin-bottom: 6pt;';
                    $attrs = str_replace($sm[0], 'style="' . $newStyle . '"', $attrs);
                } else {
                    $attrs .= ' style="width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 4pt; margin-bottom: 6pt;"';
                }
                return '<table' . $attrs . '>';
            }, $tableHtml);

            // Format baris: header (colspan=3) dan 3 kolom data
            $tableHtml = preg_replace_callback('/<tr[^>]*>([\s\S]*?)<\/tr>/is', function ($rowMatches) {
                $rowContent = $rowMatches[1];
                if (preg_match_all('/<td\b([^>]*)>([\s\S]*?)<\/td>/is', $rowContent, $tdMatches)) {
                    if (count($tdMatches[0]) === 1 && stripos($tdMatches[1][0], 'colspan') !== false) {
                        return '<tr style="page-break-inside: avoid; break-inside: avoid;"><td colspan="3" style="border: 1px solid #000000; padding: 2.5pt 6pt; font-weight: bold; background-color: #f8fafc;">' . $tdMatches[2][0] . '</td></tr>';
                    }
                    if (count($tdMatches[0]) === 3) {
                        $c0 = trim($tdMatches[2][0]);
                        $c1 = trim($tdMatches[2][1]);
                        $c2 = trim($tdMatches[2][2]);

                        $td0 = '<td style="width: 22%; border: 1px solid #000000; padding: 2pt 5pt 2pt 14pt; vertical-align: top;">' . $c0 . '</td>';
                        $td1 = '<td style="width: 4%; border: 1px solid #000000; padding: 2pt 2pt; text-align: center; vertical-align: top;">' . $c1 . '</td>';
                        $td2 = '<td style="width: 74%; border: 1px solid #000000; padding: 2pt 5pt; vertical-align: top; word-break: break-word; overflow-wrap: break-word;">' . $c2 . '</td>';

                        return '<tr style="page-break-inside: avoid; break-inside: avoid;">' . $td0 . $td1 . $td2 . '</tr>';
                    }
                }
                return $rowMatches[0];
            }, $tableHtml);

            return $tableHtml;
        }, $html);
    }

    /**
     * Hasilkan binary PDF A4 menggunakan DomPDF dengan ruang Header & Footer dinamis (termasuk Tabel Paraf & Nomor Halaman).
     */
    public static function renderPdfBinary(
        string $renderedHtml,
        array $pageConfig = [],
        string $documentTitle = 'Nota Kesepahaman (MOU)'
    ): string {
        // Ekstraksi data logo institusi dari naskah snapshot jika ada sebelum tag dibersihkan
        $extractedInstitutionLogo = null;
        if (preg_match('/<img[^>]*class="[^"]*mou-watermark-logo-left[^"]*"[^>]*src="([^"]+)"/i', $renderedHtml, $logoMatches)) {
            $extractedInstitutionLogo = $logoMatches[1];
        }

        $htmlWithBase64 = static::embedLocalImagesAsBase64($renderedHtml);

        // DomPDF tidak mendukung CSS opacity; bersihkan elemen watermark tengah agar tidak menimpa teks naskah secara solid
        $htmlWithBase64 = preg_replace('/<img[^>]*class="[^"]*mou-watermark-center[^"]*"[^>]*>/i', '', $htmlWithBase64);

        // Bersihkan layer watermark / header logo bawaan snapshot agar digantikan oleh fixed first page logos yang presisi
        $htmlWithBase64 = preg_replace('/<div[^>]*class="[^"]*mou-watermark-layer[^"]*"[^>]*>[\s\S]*?<\/div>\s*<\/div>/i', '', $htmlWithBase64);
        $htmlWithBase64 = preg_replace('/<div[^>]*class="[^"]*mou-watermark-fixed-header[^"]*"[^>]*>[\s\S]*?<\/div>/i', '', $htmlWithBase64);
        $htmlWithBase64 = preg_replace('/<img[^>]*class="[^"]*mou-watermark-logo-(?:left|right)[^"]*"[^>]*>/i', '', $htmlWithBase64);

        // Cegah orphan heading (Pasal X dan Judul Pasal terpisah dari naskah) pada render DomPDF
        $htmlWithBase64 = preg_replace_callback(
            '/(<p[^>]*><strong>\s*Pasal\s+\d+[^<]*<\/strong><\/p>\s*<p[^>]*><strong>[^<]+<\/strong><\/p>)/iu',
            function ($matches) {
                return preg_replace('/style="([^"]*)"/i', 'style="$1; page-break-after: avoid; break-after: avoid;"', $matches[1]);
            },
            $htmlWithBase64
        );

        // Pastikan seluruh tabel memiliki table-layout: fixed agar kolom nama tidak menggeser kolom lain (misal titik dua atau pihak)
        $htmlWithBase64 = preg_replace_callback('/<table\b([^>]*)>/i', function ($matches) {
            $attrs = $matches[1];
            if (!preg_match('/table-layout\s*:\s*fixed/i', $attrs)) {
                if (preg_match('/style="([^"]*)"/i', $attrs, $sm)) {
                    $newStyle = rtrim(trim($sm[1]), ';') . '; table-layout: fixed; width: 100%;';
                    $attrs = str_replace($sm[0], 'style="' . $newStyle . '"', $attrs);
                } else {
                    $attrs .= ' style="table-layout: fixed; width: 100%;"';
                }
            }
            return '<table' . $attrs . '>';
        }, $htmlWithBase64);

        // Normalisasi tabel komparisi pihak (4 kolom: nomor, nama penandatangan, titik dua, dan jabatan/deskripsi)
        // agar proporsi lebar persen (3%, 33%, 2%, 62%) diterapkan secara presisi di DomPDF dan tidak berjarak jauh.
        $htmlWithBase64 = preg_replace_callback('/<table[^>]*class="[^"]*mou-table-borderless[^"]*"[^>]*>.*?<\/table>/is', function ($matches) {
            $tableHtml = $matches[0];
            if (preg_match('/<td[^>]*>\s*(?:<p[^>]*>)?\s*1\.\s*(?:<\/p>)?\s*<\/td>/iu', $tableHtml)) {
                // Ganti atau sisipkan colgroup proporsional
                if (preg_match('/<colgroup>.*?<\/colgroup>/is', $tableHtml)) {
                    $tableHtml = preg_replace('/<colgroup>.*?<\/colgroup>/is', '<colgroup><col style="width: 3%;"><col style="width: 33%;"><col style="width: 2%;"><col style="width: 62%;"></colgroup>', $tableHtml);
                } else {
                    $tableHtml = preg_replace('/(<table[^>]*>)/i', '$1<colgroup><col style="width: 3%;"><col style="width: 33%;"><col style="width: 2%;"><col style="width: 62%;"></colgroup>', $tableHtml);
                }

                // Format setiap baris menjadi 4 kolom yang proporsional dan rapat
                $tableHtml = preg_replace_callback('/<tr[^>]*>(.*?)<\/tr>/is', function ($rowMatches) {
                    $rowContent = $rowMatches[1];
                    if (preg_match_all('/<td\b([^>]*)>(.*?)<\/td>/is', $rowContent, $tdMatches)) {
                        if (count($tdMatches[0]) === 4) {
                            $newTd0 = '<td style="width: 3%; vertical-align: top; padding-right: 0;">' . $tdMatches[2][0] . '</td>';
                            $newTd1 = '<td style="width: 33%; vertical-align: top; padding-left: 2pt; word-break: break-all; word-wrap: break-word;">' . $tdMatches[2][1] . '</td>';
                            $newTd2 = '<td style="width: 2%; vertical-align: top; text-align: center; padding-left: 0; padding-right: 0;">' . $tdMatches[2][2] . '</td>';
                            $newTd3 = '<td style="width: 62%; vertical-align: top; text-align: justify; word-break: break-all; word-wrap: break-word;">' . $tdMatches[2][3] . '</td>';
                            return '<tr>' . $newTd0 . $newTd1 . $newTd2 . $newTd3 . '</tr>';
                        }
                    }
                    return $rowMatches[0];
                }, $tableHtml);
            }
            return $tableHtml;
        }, $htmlWithBase64);

        // Normalisasi tabel korespondensi Pasal 5 (3 kolom: label, titik dua, kontak)
        // agar proporsi lebar persen (22%, 4%, 74%) diterapkan secara presisi di DomPDF dan tidak berjarak jauh.
        $htmlWithBase64 = static::normalizeCorrespondenceTablesHtml($htmlWithBase64);

        $canonicalCss   = static::getCanonicalDocumentCss($pageConfig);

        $defaults = MouTemplate::getDefaultPageConfig();
        $config   = array_merge($defaults, $pageConfig);

        foreach (['header_html', 'footer_html', 'first_page_header_html', 'first_page_footer_html'] as $hfKey) {
            if (!empty($config[$hfKey])) {
                $config[$hfKey] = static::embedLocalImagesAsBase64(static::sanitizeHeaderFooterHtml((string) $config[$hfKey]));
            }
        }

        $showHeaderLogos = (bool) ($config['show_header_logos'] ?? true);
        $showWatermark   = (bool) ($config['show_watermark'] ?? false);

        $instLogoPath = $config['institution_logo'] ?? ($config['institution_logo_path'] ?? null);
        if (empty($instLogoPath) && !empty($extractedInstitutionLogo)) {
            $instLogoPath = $extractedInstitutionLogo;
        }
        if (empty($instLogoPath)) {
            $instLogoPath = '/images/mou/institution-logo-placeholder.png';
        }
        $compLogoPath = $config['company_logo'] ?? ($config['company_logo_path'] ?? null);
        if (empty($compLogoPath)) {
            $compLogoPath = '/images/mou/seven-inc-logo.png';
        }

        $institutionLogoBase64 = static::convertLocalPathToBase64($instLogoPath) ?? $instLogoPath;
        $companyLogoBase64     = static::convertLocalPathToBase64($compLogoPath) ?? $compLogoPath;

        $pdf = Pdf::loadView('hr.mou.pdf-document', [
            'documentTitle'         => $documentTitle,
            'renderedHtml'          => $htmlWithBase64,
            'canonicalCss'          => $canonicalCss,
            'pageConfig'            => $config,
            'showHeaderLogos'       => $showHeaderLogos,
            'showWatermark'         => $showWatermark,
            'institutionLogoBase64' => $institutionLogoBase64,
            'companyLogoBase64'     => $companyLogoBase64,
        ]);

        $paperSize = strtolower((string) ($config['paper_size'] ?? 'a4'));
        if (!in_array($paperSize, ['a4', 'letter', 'legal'], true)) {
            $paperSize = 'a4';
        }

        $orientation = strtolower((string) ($config['orientation'] ?? 'portrait')) === 'landscape'
            ? 'landscape'
            : 'portrait';

        $pdf->setPaper($paperSize, $orientation);
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', false);

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $showPageNum = (bool) ($config['show_page_number'] ?? $config['show_page_numbers'] ?? true);

        // Tambahkan penomoran halaman "Halaman X dari Y" di kanan bawah setiap halaman jika diaktifkan
        if ($showPageNum) {
            $canvas        = $dompdf->getCanvas();
            $fontMetrics   = $dompdf->getFontMetrics();
            $font          = $fontMetrics->getFont('Times-Roman', 'normal');
            $pageWidth     = $canvas->get_width();
            $pageHeight    = $canvas->get_height();
            $marginRightPt = ((float) ($config['margin_right_mm'] ?? 25.4)) * 2.83465;

            // Posisi kanan bawah sejajar dengan batas margin kanan dan baris Kotak Paraf
            $x = max(40.0, $pageWidth - $marginRightPt - 78.0);
            $y = $pageHeight - 36.0;

            $canvas->page_text($x, $y, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 9, [0, 0, 0]);
        }

        return $dompdf->output();
    }
}
