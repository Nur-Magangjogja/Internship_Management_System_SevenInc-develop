<?php

namespace App\Services\HistoricalImport;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use XMLReader;
use ZipArchive;

class StreamingXlsxReader
{
    public const MAX_FILE_SIZE_BYTES = 15 * 1024 * 1024; // 15 MB
    public const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024; // 100 MB
    public const MAX_ZIP_ENTRIES = 1000;

    /**
     * Parse file XLSX secara aman dan streaming per baris.
     *
     * @param string $filePath Path fisik berkas XLSX
     * @param callable(array<int, mixed>, int): void $rowCallback Callback($rowValues, $rowIndex)
     * @param int|null $sheetIndex Index lembar kerja (0-based, default 0)
     * @param int $maxRows Batas maksimum baris yang dibaca (0 = tanpa batas)
     * @return int Total baris yang berhasil diproses
     */
    public function read(string $filePath, callable $rowCallback, ?int $sheetIndex = 0, int $maxRows = 0): int
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Berkas XLSX tidak ditemukan: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new DomainException("Ukuran berkas (" . round($fileSize / 1024 / 1024, 2) . " MB) melebihi batas aman 15 MB.");
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($filePath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            throw new RuntimeException("Gagal membuka arsip XLSX (ZipArchive error code: {$openResult}).");
        }

        $tempExtractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sertif_xlsx_' . bin2hex(random_bytes(8));
        if (!mkdir($tempExtractDir, 0700, true) && !is_dir($tempExtractDir)) {
            $zip->close();
            throw new RuntimeException("Gagal membuat direktori ekstraksi sementara.");
        }

        try {
            $this->validateAndExtractArchive($zip, $tempExtractDir);
            $zip->close();

            $sharedStrings = $this->loadSharedStrings($tempExtractDir);
            $is1904 = $this->detectWorkbookDateSystem($tempExtractDir);

            $sheetFile = $this->resolveSheetFilePath($tempExtractDir, $sheetIndex ?? 0);
            if (!file_exists($sheetFile)) {
                throw new DomainException("Worksheet pada index {$sheetIndex} tidak ditemukan dalam berkas XLSX.");
            }

            return $this->streamWorksheetRows($sheetFile, $sharedStrings, $is1904, $rowCallback, $maxRows);
        } finally {
            $this->cleanDirectory($tempExtractDir);
        }
    }

    /**
     * Dapatkan daftar nama sheet dalam workbook XLSX.
     *
     * @return array<int, array{index: int, name: string, sheet_id: string}>
     */
    public function listSheets(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Gagal membaca struktur sheet XLSX.");
        }

        $sheets = [];
        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if (!$xmlContent) {
            return [['index' => 0, 'name' => 'Sheet1', 'sheet_id' => '1']];
        }

        $reader = new XMLReader();
        $reader->XML($xmlContent, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
        $idx = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'sheet') {
                $name = $reader->getAttribute('name') ?: ('Sheet' . ($idx + 1));
                $sheetId = $reader->getAttribute('sheetId') ?: (string) ($idx + 1);
                $sheets[] = [
                    'index'    => $idx,
                    'name'     => $name,
                    'sheet_id' => $sheetId,
                ];
                $idx++;
            }
        }
        $reader->close();

        return !empty($sheets) ? $sheets : [['index' => 0, 'name' => 'Sheet1', 'sheet_id' => '1']];
    }

    /**
     * Validasi keamanan arsip zip terhadap Zip Bomb dan Zip Slip sebelum ekstraksi.
     */
    protected function validateAndExtractArchive(ZipArchive $zip, string $targetDir): void
    {
        $numFiles = $zip->numFiles;
        if ($numFiles > self::MAX_ZIP_ENTRIES) {
            throw new DomainException("Arsip XLSX memuat {$numFiles} entri, melebihi batas aman 1000 entri.");
        }

        $totalUncompressed = 0;
        $realTargetDir = realpath($targetDir) ?: $targetDir;

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;

            $entryName = $stat['name'];
            $totalUncompressed += $stat['size'];

            if ($totalUncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new DomainException("Ukuran dekompresi arsip melebihi batas aman 100 MB (terdeteksi indikasi Zip Bomb).");
            }

            // Proteksi Zip Slip (Directory Traversal)
            if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                throw new DomainException("Arsip XLSX memuat path berbahaya (Zip Slip attempt): {$entryName}");
            }

            // Hanya ekstrak entri XML & rels yang dibutuhkan parser
            if (!preg_match('#^xl/(workbook\.xml|sharedStrings\.xml|worksheets/sheet\d+\.xml|_rels/)#i', $entryName)) {
                continue;
            }

            $destPath = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryName);
            $parentDir = dirname($destPath);
            if (!is_dir($parentDir) && !mkdir($parentDir, 0700, true) && !is_dir($parentDir)) {
                throw new RuntimeException("Gagal membuat subdirektori ekstraksi.");
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($destPath, $content);
            }
        }
    }

    /**
     * Membaca tabel shared strings ke memori terstruktur.
     *
     * @return array<int, string>
     */
    protected function loadSharedStrings(string $baseDir): array
    {
        $sharedFile = $baseDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'sharedStrings.xml';
        if (!file_exists($sharedFile)) {
            return [];
        }

        $strings = [];
        $reader = new XMLReader();
        $reader->open($sharedFile, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);

        $currentString = '';
        $inStringItem = false;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->name === 'si') {
                    $inStringItem = true;
                    $currentString = '';
                } elseif ($inStringItem && $reader->name === 't') {
                    $currentString .= $reader->readString();
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'si') {
                $strings[] = $currentString;
                $inStringItem = false;
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * Deteksi sistem penanggalan Excel 1900 vs 1904.
     */
    protected function detectWorkbookDateSystem(string $baseDir): bool
    {
        $wbFile = $baseDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'workbook.xml';
        if (!file_exists($wbFile)) {
            return false;
        }

        $reader = new XMLReader();
        $reader->open($wbFile, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);

        $is1904 = false;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'workbookPr') {
                $date1904 = $reader->getAttribute('date1904');
                if ($date1904 === '1' || strtolower((string)$date1904) === 'true') {
                    $is1904 = true;
                }
                break;
            }
        }
        $reader->close();

        return $is1904;
    }

    /**
     * Tentukan path file worksheet berdasarkan index.
     */
    protected function resolveSheetFilePath(string $baseDir, int $sheetIndex): string
    {
        $sheetNum = $sheetIndex + 1;
        return $baseDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets' . DIRECTORY_SEPARATOR . "sheet{$sheetNum}.xml";
    }

    /**
     * Streaming baris worksheet dengan penanganan sparse cells dan konversi data aman.
     */
    protected function streamWorksheetRows(
        string $sheetFile,
        array $sharedStrings,
        bool $is1904,
        callable $rowCallback,
        int $maxRows
    ): int {
        $reader = new XMLReader();
        $reader->open($sheetFile, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);

        $processedRows = 0;
        $currentRowCells = [];
        $currentRowIndex = 1;
        $inRow = false;
        $currentCellRef = null;
        $currentCellType = null;
        $currentCellValue = null;
        $hasFormulaCachedVal = false;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->name === 'row') {
                    $inRow = true;
                    $currentRowCells = [];
                    $rAttr = $reader->getAttribute('r');
                    $currentRowIndex = $rAttr !== null ? (int) $rAttr : ($processedRows + 1);
                } elseif ($inRow && $reader->name === 'c') {
                    $currentCellRef = $reader->getAttribute('r');
                    $currentCellType = $reader->getAttribute('t');
                    $currentCellValue = null;
                    $hasFormulaCachedVal = false;
                } elseif ($inRow && $reader->name === 'v') {
                    $val = $reader->readString();
                    if ($currentCellType === 's') {
                        // Shared string lookup
                        $sIdx = (int) $val;
                        $currentCellValue = $sharedStrings[$sIdx] ?? $val;
                    } elseif ($currentCellType === 'b') {
                        // Boolean
                        $currentCellValue = ($val === '1' || strtolower($val) === 'true') ? 'TRUE' : 'FALSE';
                    } else {
                        $currentCellValue = $val;
                    }
                    $hasFormulaCachedVal = true;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($inRow && $reader->name === 'c') {
                    if ($currentCellRef) {
                        $colIdx = $this->coordinateToColumnIndex($currentCellRef);
                        $currentRowCells[$colIdx] = $currentCellValue;
                    }
                    $currentCellRef = null;
                    $currentCellType = null;
                    $currentCellValue = null;
                } elseif ($reader->name === 'row') {
                    $inRow = false;

                    if (!empty($currentRowCells)) {
                        $denseRow = $this->convertSparseToDenseRow($currentRowCells);
                        $rowCallback($denseRow, $currentRowIndex);
                        $processedRows++;

                        if ($maxRows > 0 && $processedRows >= $maxRows) {
                            break;
                        }
                    }
                }
            }
        }

        $reader->close();
        return $processedRows;
    }

    /**
     * Konversi koordinat sel Excel (misal "A1", "C5", "AA12") menjadi 0-based column index.
     */
    public function coordinateToColumnIndex(string $cellRef): int
    {
        if (preg_match('/^([A-Z]+)/i', $cellRef, $matches)) {
            $letters = strtoupper($matches[1]);
            $len = strlen($letters);
            $col = 0;
            for ($i = 0; $i < $len; $i++) {
                $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
            }
            return $col - 1; // 0-based
        }
        return 0;
    }

    /**
     * Ubah array sel sparse (indeks kolom meloncat) menjadi baris sekuensial rapat dengan nilai null pada kolom kosong.
     *
     * @param array<int, mixed> $sparseRow
     * @return array<int, mixed>
     */
    protected function convertSparseToDenseRow(array $sparseRow): array
    {
        if (empty($sparseRow)) {
            return [];
        }

        $maxCol = max(array_keys($sparseRow));
        $dense = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $dense[$c] = $sparseRow[$c] ?? null;
        }
        return $dense;
    }

    /**
     * Konversi angka serial tanggal Excel ke format string Y-m-d H:i:s atau Y-m-d.
     * Mengakomodasi 1900 Leap Year Bug (Excel salah menganggap 1900 tahun kabisat).
     */
    public static function excelSerialToDateTime(float|int|string $serial, bool $is1904 = false, ?string $timezone = 'Asia/Jakarta'): ?string
    {
        if (!is_numeric($serial)) {
            return null;
        }

        $val = (float) $serial;
        if ($val <= 0) {
            return null;
        }

        $tz = new DateTimeZone($timezone ?: 'Asia/Jakarta');

        if ($is1904) {
            // Sistem 1904: dimulai pada 1 Januari 1904
            $baseTimestamp = gmmktime(0, 0, 0, 1, 1, 1904);
            $seconds = (int) round($val * 86400);
            $dt = (new DateTimeImmutable("@" . ($baseTimestamp + $seconds)))->setTimezone($tz);
        } else {
            // Sistem 1900: dimulai pada 1 Januari 1900
            // Excel bug: hari ke-60 dianggap 29 Februari 1900 (padahal 1900 bukan kabisat)
            $dayOffset = $val;
            if ($dayOffset > 60) {
                $dayOffset -= 1; // Koreksi 1 hari karena leap year bug
            }

            // Timestamp 1899-12-31 00:00:00 UTC
            $baseTimestamp = -2209075200;
            $seconds = (int) round($dayOffset * 86400);
            $dt = (new DateTimeImmutable("@" . ($baseTimestamp + $seconds)))->setTimezone($tz);
        }

        // Jika tidak memiliki fraksi waktu (tepat 00:00:00), kembalikan format Y-m-d
        $fraction = fmod((float) $val, 1.0);
        if (abs($fraction) < 0.00001) {
            return $dt->format('Y-m-d');
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Bersihkan direktori sementara setelah parsing selesai.
     */
    protected function cleanDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->cleanDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
