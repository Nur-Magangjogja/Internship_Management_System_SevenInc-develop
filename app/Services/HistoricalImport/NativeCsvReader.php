<?php

namespace App\Services\HistoricalImport;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

class NativeCsvReader
{
    public const MAX_FILE_SIZE_BYTES = 25 * 1024 * 1024; // 25 MB

    /**
     * Parse file CSV secara aman dan streaming per baris.
     *
     * @param string $filePath Path fisik berkas CSV
     * @param callable(array<int, mixed>, int): void $rowCallback Callback($rowValues, $rowIndex)
     * @param string|null $delimiter Delimiter khusus jika diketahui (null = auto-detect)
     * @param int $maxRows Batas maksimum baris yang dibaca (0 = tanpa batas)
     * @return int Total baris yang berhasil diproses
     */
    public function read(string $filePath, callable $rowCallback, ?string $delimiter = null, int $maxRows = 0): int
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Berkas CSV tidak ditemukan: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new DomainException("Ukuran berkas CSV (" . round($fileSize / 1024 / 1024, 2) . " MB) melebihi batas aman 25 MB.");
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new RuntimeException("Gagal membuka stream berkas CSV.");
        }

        try {
            $effectiveDelimiter = $delimiter ?: $this->detectDelimiter($filePath);

            // Periksa dan lewati UTF-8 BOM jika ada
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $rowIndex = 1;
            $processedRows = 0;

            while (($rawRow = fgetcsv($handle, 0, $effectiveDelimiter, '"', '\\')) !== false) {
                // Abaikan baris kosong semata
                if (count($rawRow) === 1 && ($rawRow[0] === null || trim((string) $rawRow[0]) === '')) {
                    $rowIndex++;
                    continue;
                }

                $cleanRow = [];
                foreach ($rawRow as $colIdx => $val) {
                    $valStr = (string) ($val ?? '');
                    // Normalisasi encoding ke UTF-8 jika bukan UTF-8
                    if (!mb_check_encoding($valStr, 'UTF-8')) {
                        $valStr = mb_convert_encoding($valStr, 'UTF-8', 'ISO-8859-1, Windows-1252, ASCII');
                    }
                    $cleanRow[$colIdx] = trim($valStr);
                }

                $rowCallback($cleanRow, $rowIndex);
                $processedRows++;
                $rowIndex++;

                if ($maxRows > 0 && $processedRows >= $maxRows) {
                    break;
                }
            }

            return $processedRows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Deteksi delimiter CSV secara otomatis berdasarkan frekuensi sampel 10 baris pertama.
     */
    public function detectDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ',';
        }

        $delimiters = [',', ';', "\t", '|'];
        $scores = array_fill_keys($delimiters, 0);

        // Ambil sampel hingga 10 baris
        $linesSample = [];
        $count = 0;
        while (($line = fgets($handle, 8192)) !== false && $count < 10) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $linesSample[] = $trimmed;
                $count++;
            }
        }
        fclose($handle);

        if (empty($linesSample)) {
            return ',';
        }

        foreach ($delimiters as $delim) {
            $counts = [];
            foreach ($linesSample as $line) {
                $counts[] = substr_count($line, $delim);
            }

            // Jika count konsisten di semua baris sampel dan > 0, berikan bobot tinggi
            $uniqueCounts = array_unique($counts);
            if (count($uniqueCounts) === 1 && reset($uniqueCounts) > 0) {
                $scores[$delim] += reset($uniqueCounts) * 10;
            } else {
                $scores[$delim] += array_sum($counts);
            }
        }

        arsort($scores);
        $bestDelim = array_key_first($scores);

        return ($scores[$bestDelim] > 0) ? $bestDelim : ',';
    }
}
