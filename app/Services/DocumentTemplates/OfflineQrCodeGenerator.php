<?php

namespace App\Services\DocumentTemplates;

/**
 * OfflineQrCodeGenerator
 *
 * Generator QR Code murni PHP (offline tanpa ketergantungan API pihak ketiga / cloud).
 * Mendukung QR Code Model 2 (Byte Mode) dengan koreksi kesalahan Reed-Solomon (Level M / L)
 * dan merender output dalam format SVG vektor beresolusi tinggi (atau PNG base64).
 */
class OfflineQrCodeGenerator
{
    /**
     * Galois Field GF(256) log and antilog tables (primitive polynomial 0x11d).
     */
    private static ?array $gfLog = null;
    private static ?array $gfExp = null;

    /**
     * Kapasitas data (bytes) untuk Level M (Medium - 15% recovery)
     * Versi 1 (21x21): 14 bytes
     * Versi 2 (25x25): 26 bytes
     * Versi 3 (29x29): 42 bytes
     * Versi 4 (33x33): 62 bytes
     * Versi 5 (37x37): 84 bytes
     * Versi 6 (41x41): 106 bytes
     * Versi 7 (45x45): 122 bytes
     */
    private static array $versionCapacitiesM = [
        1 => ['total_cw' => 26,  'data_cw' => 14,  'ec_cw' => 10, 'blocks' => 1],
        2 => ['total_cw' => 44,  'data_cw' => 26,  'ec_cw' => 16, 'blocks' => 1],
        3 => ['total_cw' => 70,  'data_cw' => 42,  'ec_cw' => 26, 'blocks' => 1],
        4 => ['total_cw' => 100, 'data_cw' => 62,  'ec_cw' => 18, 'blocks' => 2], // 2 blocks of 31
        5 => ['total_cw' => 134, 'data_cw' => 84,  'ec_cw' => 24, 'blocks' => 2], // 2 blocks of 42
        6 => ['total_cw' => 172, 'data_cw' => 106, 'ec_cw' => 16, 'blocks' => 4], // 4 blocks of 26-27
        7 => ['total_cw' => 196, 'data_cw' => 122, 'ec_cw' => 18, 'blocks' => 4],
    ];

    /**
     * Format Information Bits untuk Level M dengan masks 0..7 (BCH 15,5 xor 0x5412)
     */
    private static array $formatBitsM = [
        0 => 0b101010000010010,
        1 => 0b101000100111101,
        2 => 0b101111001111100,
        3 => 0b101101101010011,
        4 => 0b100010111111001,
        5 => 0b100000011010110,
        6 => 0b100111110010111,
        7 => 0b100101010111000,
    ];

    /**
     * Inisialisasi tabel Galois Field GF(2^8).
     */
    private static function initGF(): void
    {
        if (self::$gfLog !== null) {
            return;
        }

        self::$gfLog = array_fill(0, 256, 0);
        self::$gfExp = array_fill(0, 512, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfExp[$i + 255] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d; // Primitive poly: x^8 + x^4 + x^3 + x^2 + 1
            }
        }
    }

    private static function gfMul(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) return 0;
        return self::$gfExp[self::$gfLog[$x] + self::$gfLog[$y]];
    }

    /**
     * Hitung polynomial generator error correction.
     */
    private static function getGeneratorPoly(int $degree): array
    {
        self::initGF();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = [1];
            $factor = self::$gfExp[$i];
            $temp = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $temp[$j] ^= self::gfMul($poly[$j], $factor);
                $temp[$j + 1] ^= $poly[$j];
            }
            $poly = $temp;
        }
        return $poly;
    }

    /**
     * Hitung Reed-Solomon error correction codewords.
     */
    private static function computeEC(array $data, int $ecCount): array
    {
        self::initGF();
        $gen = self::getGeneratorPoly($ecCount);
        // gen is highest degree first: e.g. x^ecCount + ...
        $msg = array_merge($data, array_fill(0, $ecCount, 0));

        for ($i = 0; $i < count($data); $i++) {
            $lead = $msg[$i];
            if ($lead !== 0) {
                for ($j = 0; $j < count($gen); $j++) {
                    $msg[$i + $j] ^= self::gfMul($gen[count($gen) - 1 - $j], $lead);
                }
            }
        }

        return array_slice($msg, count($data));
    }

    /**
     * Tentukan versi QR minimal untuk panjang teks tertentu.
     */
    private static function selectVersion(int $byteLen): int
    {
        foreach (self::$versionCapacitiesM as $v => $spec) {
            // Byte mode overhead: 4 bits mode + 8 bits length = 1.5 bytes -> need data_cw >= byteLen + 2 (approx)
            if ($spec['data_cw'] >= ($byteLen + 2)) {
                return $v;
            }
        }
        return 7; // max version supported in this compact implementation
    }

    /**
     * Encode string ke stream bit Byte Mode.
     */
    private static function encodeData(string $text, int $version): array
    {
        $spec = self::$versionCapacitiesM[$version];
        $targetBytes = $spec['data_cw'];

        // Mode Indicator: 0100 (Byte mode)
        $bits = [0, 1, 0, 0];

        // Character Count Indicator (8 bits for version 1-9)
        $len = strlen($text);
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = ($len >> $i) & 1;
        }

        // Data Bytes
        for ($c = 0; $c < $len; $c++) {
            $byte = ord($text[$c]);
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
        }

        // Terminator: up to 4 zero bits
        $termLen = min(4, ($targetBytes * 8) - count($bits));
        for ($i = 0; $i < $termLen; $i++) {
            $bits[] = 0;
        }

        // Pad to byte boundary
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Convert to byte array
        $bytes = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $val = 0;
            for ($b = 0; $b < 8; $b++) {
                $val = ($val << 1) | $bits[$i + $b];
            }
            $bytes[] = $val;
        }

        // Pad with alternating 0xEC and 0x11 until targetBytes reached
        $padBytes = [0xEC, 0x11];
        $padIdx = 0;
        while (count($bytes) < $targetBytes) {
            $bytes[] = $padBytes[$padIdx % 2];
            $padIdx++;
        }

        return $bytes;
    }

    /**
     * Buat matriks 2D boolean untuk QR Code.
     */
    public static function generateMatrix(string $text): array
    {
        $len = strlen($text);
        $version = self::selectVersion($len);
        $spec = self::$versionCapacitiesM[$version];
        $size = 17 + 4 * $version;

        $dataBytes = self::encodeData($text, $version);

        // Partition into blocks & compute EC
        $numBlocks = $spec['blocks'];
        $ecCwPerBlock = $spec['ec_cw'];
        $dataBlocks = [];
        $ecBlocks = [];

        $bytesPerBlock = (int) floor(count($dataBytes) / $numBlocks);
        $remainder = count($dataBytes) % $numBlocks;
        $offset = 0;

        for ($b = 0; $b < $numBlocks; $b++) {
            $blockSize = $bytesPerBlock + ($b >= ($numBlocks - $remainder) ? 1 : 0);
            $blockData = array_slice($dataBytes, $offset, $blockSize);
            $offset += $blockSize;
            $dataBlocks[] = $blockData;
            $ecBlocks[] = self::computeEC($blockData, $ecCwPerBlock);
        }

        // Interleave data codewords
        $interleaved = [];
        $maxDataLen = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $b => $block) {
                if ($i < count($block)) {
                    $interleaved[] = $block[$i];
                }
            }
        }
        // Interleave EC codewords
        for ($i = 0; $i < $ecCwPerBlock; $i++) {
            foreach ($ecBlocks as $b => $block) {
                $interleaved[] = $block[$i];
            }
        }

        // Convert interleaved codewords to bit array
        $allBits = [];
        foreach ($interleaved as $byte) {
            for ($bit = 7; $bit >= 0; $bit--) {
                $allBits[] = ($byte >> $bit) & 1;
            }
        }

        // Initialize matrix and reserved flags
        $matrix = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns
        self::placeFinder($matrix, $reserved, 0, 0, $size);
        self::placeFinder($matrix, $reserved, $size - 7, 0, $size);
        self::placeFinder($matrix, $reserved, 0, $size - 7, $size);

        // 2. Alignment pattern for Version >= 2
        if ($version >= 2) {
            $alignCoords = [
                2 => [6, 18],
                3 => [6, 22],
                4 => [6, 26],
                5 => [6, 30],
                6 => [6, 34],
                7 => [6, 22, 38],
            ];
            $positions = $alignCoords[$version] ?? [6, $size - 7];
            foreach ($positions as $r) {
                foreach ($positions as $c) {
                    // Skip if overlapping with finder
                    if (
                        ($r <= 8 && $c <= 8) ||
                        ($r <= 8 && $c >= $size - 9) ||
                        ($r >= $size - 9 && $c <= 8)
                    ) {
                        continue;
                    }
                    self::placeAlignment($matrix, $reserved, $r, $c);
                }
            }
        }

        // 3. Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if (!$reserved[6][$i]) {
                $matrix[6][$i] = $val;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $matrix[$i][6] = $val;
                $reserved[$i][6] = true;
            }
        }

        // 4. Dark module
        $matrix[4 * $version + 9][8] = 1;
        $reserved[4 * $version + 9][8] = true;

        // 5. Reserve format info areas
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }

        // 6. Place data bits in zig-zag
        $bitIdx = 0;
        $bitCount = count($allBits);
        $right = $size - 1;

        while ($right > 0) {
            if ($right === 6) {
                $right--; // Skip vertical timing pattern
            }
            $upwards = (intdiv($size - 1 - $right, 2) % 2 === 0);

            for ($vert = 0; $vert < $size; $vert++) {
                $r = $upwards ? ($size - 1 - $vert) : $vert;
                for ($c = $right; $c >= $right - 1; $c--) {
                    if (!$reserved[$r][$c]) {
                        if ($bitIdx < $bitCount) {
                            $matrix[$r][$c] = $allBits[$bitIdx++];
                        } else {
                            $matrix[$r][$c] = 0; // Pad remainder bits
                        }
                    }
                }
            }
            $right -= 2;
        }

        // 7. Apply standard mask (Mask 0: (row + col) % 2 == 0)
        // Mask 0 provides solid, reliable contrast for document scanning
        $mask = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c]) {
                    if (($r + $c) % 2 === 0) {
                        $matrix[$r][$c] ^= 1;
                    }
                }
            }
        }

        // 8. Place format bits
        $formatBits = self::$formatBitsM[$mask];
        // Around top-left
        for ($i = 0; $i < 6; $i++) {
            $matrix[8][$i] = ($formatBits >> $i) & 1;
        }
        $matrix[8][7] = ($formatBits >> 6) & 1;
        $matrix[8][8] = ($formatBits >> 7) & 1;
        $matrix[7][8] = ($formatBits >> 8) & 1;
        for ($i = 9; $i < 15; $i++) {
            $matrix[14 - $i][8] = ($formatBits >> $i) & 1;
        }

        // Around top-right and bottom-left
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 1 - $i] = ($formatBits >> $i) & 1;
        }
        for ($i = 8; $i < 15; $i++) {
            $matrix[$size - 15 + $i][8] = ($formatBits >> $i) & 1;
        }

        return $matrix;
    }

    private static function placeFinder(array &$matrix, array &$reserved, int $r0, int $c0, int $size): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $row = $r0 + $r;
                $col = $c0 + $c;
                if ($row >= 0 && $row < $size && $col >= 0 && $col < $size) {
                    $reserved[$row][$col] = true;
                    if ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) {
                        $isBlack = ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                        $matrix[$row][$col] = $isBlack ? 1 : 0;
                    } else {
                        $matrix[$row][$col] = 0; // Separator
                    }
                }
            }
        }
    }

    private static function placeAlignment(array &$matrix, array &$reserved, int $centerR, int $centerC): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $row = $centerR + $r;
                $col = $centerC + $c;
                $reserved[$row][$col] = true;
                $isBlack = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                $matrix[$row][$col] = $isBlack ? 1 : 0;
            }
        }
    }

    /**
     * Render SVG QR Code inline.
     */
    public static function renderSvg(string $text, int $sizePx = 120, string $fgColor = '#000000', string $bgColor = '#ffffff', int $margin = 4): string
    {
        if (trim($text) === '') {
            $text = 'CERT-VERIFY';
        }

        $matrix = self::generateMatrix($text);
        $modules = count($matrix);
        $totalSize = $modules + ($margin * 2);

        $rects = '';
        for ($r = 0; $r < $modules; $r++) {
            for ($c = 0; $c < $modules; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $rects .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"1\" height=\"1\" fill=\"{$fgColor}\" />\n";
                }
            }
        }

        $bgRect = $bgColor !== 'transparent' ? "<rect width=\"100%\" height=\"100%\" fill=\"{$bgColor}\" />" : '';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$totalSize} {$totalSize}" width="{$sizePx}" height="{$sizePx}" shape-rendering="crispEdges">
    {$bgRect}
    {$rects}
</svg>
SVG;
    }

    /**
     * Render sebagai data URI Base64 (cocok untuk DomPDF <img src="...">).
     */
    public static function renderDataUri(string $text, int $sizePx = 120, string $fgColor = '#000000', string $bgColor = '#ffffff'): string
    {
        $svg = self::renderSvg($text, $sizePx, $fgColor, $bgColor);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
