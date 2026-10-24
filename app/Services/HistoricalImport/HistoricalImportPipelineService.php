<?php

namespace App\Services\HistoricalImport;

use App\Models\FormFieldRegistry;
use App\Models\HistoricalImportBatch;
use App\Models\HistoricalImportRow;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class HistoricalImportPipelineService
{
    public function __construct(
        protected StreamingXlsxReader $xlsxReader,
        protected NativeCsvReader $csvReader,
        protected RegistrationSpreadsheetMappingService $mappingService,
        protected SpreadsheetSyncEngineService $syncEngine,
        protected SpreadsheetTransportInterface $transport
    ) {}

    /**
     * Menganalisis berkas sumber (XLSX/CSV) untuk mengekstrak header dan baris sampel.
     *
     * @return array{headers: array<int, string>, sample_rows: array<int, array<int, mixed>>, total_rows: int, sheets: array<int, mixed>}
     */
    public function analyzeSourceFile(string $sourceType, string $filePath, ?int $sheetIndex = 0): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Berkas sumber tidak ditemukan: {$filePath}");
        }

        $headers = [];
        $sampleRows = [];
        $totalRows = 0;
        $sheets = [];

        if ($sourceType === HistoricalImportBatch::SOURCE_XLSX) {
            $sheets = $this->xlsxReader->listSheets($filePath);
            $this->xlsxReader->read($filePath, function ($rowValues, $rowIndex) use (&$headers, &$sampleRows, &$totalRows) {
                if ($rowIndex === 1) {
                    $headers = array_map(fn($v) => trim((string) ($v ?? '')), $rowValues);
                } else {
                    if (count($sampleRows) < 5) {
                        $sampleRows[] = $rowValues;
                    }
                    $totalRows++;
                }
            }, $sheetIndex);
        } elseif ($sourceType === HistoricalImportBatch::SOURCE_CSV) {
            $sheets = [['index' => 0, 'name' => 'CSV Data', 'sheet_id' => '0']];
            $this->csvReader->read($filePath, function ($rowValues, $rowIndex) use (&$headers, &$sampleRows, &$totalRows) {
                if ($rowIndex === 1) {
                    $headers = array_map(fn($v) => trim((string) ($v ?? '')), $rowValues);
                } else {
                    if (count($sampleRows) < 5) {
                        $sampleRows[] = $rowValues;
                    }
                    $totalRows++;
                }
            });
        } else {
            throw new InvalidArgumentException("Tipe sumber tidak didukung: {$sourceType}");
        }

        return [
            'headers'     => $headers,
            'sample_rows' => $sampleRows,
            'total_rows'  => $totalRows,
            'sheets'      => $sheets,
        ];
    }

    /**
     * Menganalisis sumber Google Sheets (Read-Only) melalui Apps Script transport.
     * Mengembalikan daftar tab (jika tabName belum dipilih) atau header dan sampel baris.
     *
     * @return array{success: bool, spreadsheet_id: string, spreadsheet_name?: string, tab_name?: string, tabs?: array, headers?: array, sample_rows?: array, total_rows?: int, needs_tab_select?: bool}
     */
    public function analyzeGoogleSheet(
        string $spreadsheetId,
        ?string $tabName = null,
        ?SpreadsheetConnection $connection = null
    ): array {
        $conn = $this->resolveRegistrationConnection($connection, $spreadsheetId);

        $targetConn = clone $conn;
        $targetConn->spreadsheet_id = $spreadsheetId;

        // Jika belum memilih tab, cari daftar tab yang tersedia
        if (empty($tabName)) {
            $resp = $this->transport->inspectTarget($targetConn, 'spreadsheet', $spreadsheetId, 'legacy_source');
            if (!$resp->isSuccess()) {
                throw new DomainException("Gagal mengakses Spreadsheet Google: " . $resp->getErrorMessage());
            }
            $targetData = $resp->getData();
            $tabs = $targetData['tabs'] ?? [];

            return [
                'success'          => true,
                'spreadsheet_id'   => $spreadsheetId,
                'spreadsheet_name' => $targetData['name'] ?? "Google Sheet ({$spreadsheetId})",
                'tabs'             => $tabs,
                'needs_tab_select' => true,
            ];
        }

        // Jika tab sudah ditentukan, baca header dan baris sampel (Read-Only)
        $resp = $this->transport->readRowsPage(
            connection: $targetConn,
            tabName: $tabName,
            headerRow: 1,
            page: 1,
            pageSize: 5,
            maskPii: false
        );

        if (!$resp->isSuccess()) {
            throw new DomainException("Gagal membaca tab '{$tabName}' dari Google Sheets: " . $resp->getErrorMessage());
        }

        $pageData = $resp->getData();
        $sampleRows = $pageData['rows'] ?? [];
        $headers = [];

        if (!empty($sampleRows)) {
            $firstRow = $sampleRows[0];
            foreach ($firstRow as $k => $v) {
                if ($k !== '_row_number') {
                    $headers[] = (string) $k;
                }
            }
        } else {
            // Coba periksa via inspectSchema jika baris belum ada
            $schemaResp = $this->transport->inspectSchema($targetConn, $tabName, 1);
            if ($schemaResp->isSuccess()) {
                $cols = $schemaResp->getData()['columns'] ?? [];
                foreach ($cols as $c) {
                    $headers[] = $c['header_name'] ?? ('COL_' . ($c['col_letter'] ?? ''));
                }
            }
        }

        // Format sampel baris agar sesuai dengan preview tabular
        $formattedSamples = [];
        foreach ($sampleRows as $r) {
            $rowArr = [];
            foreach ($headers as $h) {
                $rowArr[] = $r[$h] ?? null;
            }
            $formattedSamples[] = $rowArr;
        }

        return [
            'success'          => true,
            'spreadsheet_id'   => $spreadsheetId,
            'tab_name'         => $tabName,
            'headers'          => $headers,
            'sample_rows'      => $formattedSamples,
            'total_rows'       => $pageData['total_rows'] ?? count($sampleRows),
            'needs_tab_select' => false,
        ];
    }

    /**
     * Membuat batch staging dari sumber berkas (XLSX/CSV).
     */
    public function createStagingBatch(
        string $sourceType,
        string $filePath,
        string $sourceFilename,
        array $columnMappingConfig,
        ?string $defaultStatus = InternshipRegistration::STATUS_WAITING,
        ?int $sheetIndex = 0,
        ?string $sourceIdentifier = null,
        ?string $sourceTabName = null,
        ?int $userId = null,
        ?string $startDateFilter = null,
        ?string $endDateFilter = null
    ): HistoricalImportBatch {
        $fileHash = file_exists($filePath) ? hash_file('sha256', $filePath) : null;

        $batch = HistoricalImportBatch::create([
            'source_type'           => $sourceType,
            'source_filename'       => $sourceFilename,
            'source_identifier'     => $sourceIdentifier ?: basename($filePath),
            'source_tab_name'       => $sourceTabName,
            'source_file_hash'      => $fileHash,
            'column_mapping_config' => $columnMappingConfig,
            'status'                => HistoricalImportBatch::STATUS_STAGED,
            'created_by'            => $userId,
        ]);

        $filterStart = $startDateFilter ? $this->parseFilterDate($startDateFilter) : null;
        $filterEnd = $endDateFilter ? $this->parseFilterDate($endDateFilter, true) : null;

        $headers = [];
        $totalDetected = 0;
        $totalStaged = 0;

        $rowHandler = function ($rowValues, $rowIndex) use (
            $batch,
            &$headers,
            &$totalDetected,
            &$totalStaged,
            $columnMappingConfig,
            $defaultStatus,
            $filterStart,
            $filterEnd
        ) {
            if ($rowIndex === 1) {
                $headers = array_map(fn($v) => trim((string) ($v ?? '')), $rowValues);
                return;
            }

            $totalDetected++;
            $rawAssoc = [];
            foreach ($headers as $cIdx => $hName) {
                if ($hName !== '') {
                    $rawAssoc[$hName] = $rowValues[$cIdx] ?? null;
                }
            }

            // Ekstrak mapped data
            $mappedData = $this->mapRowValues($rawAssoc, $columnMappingConfig);
            $submissionDt = $this->extractSubmissionTimestamp($rawAssoc, $mappedData);

            // Filter rentang timestamp jika ditentukan
            if ($submissionDt) {
                if ($filterStart && $submissionDt < $filterStart) {
                    return;
                }
                if ($filterEnd && $submissionDt > $filterEnd) {
                    return;
                }
            } elseif ($filterStart || $filterEnd) {
                // Lewati baris tanpa timestamp apabila filter rentang waktu aktif
                return;
            }

            $targetYear = $submissionDt ? (int) $submissionDt->format('Y') : (int) date('Y');

            $fingerprint = $this->computeEventFingerprint(
                $mappedData,
                $submissionDt?->format('Y-m-d H:i:s'),
                $batch->source_identifier,
                $rowIndex
            );

            // Periksa duplikasi peristiwa pendaftaran yang identik
            $isDuplicateEvent = HistoricalImportRow::where('event_fingerprint', $fingerprint)->exists()
                || InternshipRegistration::where('event_fingerprint', $fingerprint)->exists();

            // Periksa pendaftar yang sudah pernah terdaftar pada periode/tahun lain
            $email = $mappedData['email'] ?? null;
            $isExistingApplicant = false;
            if (!empty($email)) {
                $isExistingApplicant = InternshipRegistration::where('email', $email)->exists();
            }

            // Validasi baris dasar
            $validation = $this->validateMappedRow($mappedData);
            $rowStatus = self::normalizeLifecycleStatus($mappedData['status'] ?? null, $defaultStatus ?? InternshipRegistration::STATUS_WAITING);

            HistoricalImportRow::create([
                'batch_id'              => $batch->id,
                'row_index'             => $rowIndex,
                'event_fingerprint'     => $fingerprint,
                'raw_data'              => $rawAssoc,
                'mapped_data'           => $mappedData,
                'submission_timestamp'  => $submissionDt?->format('Y-m-d H:i:s'),
                'target_year'           => $targetYear,
                'lifecycle_status'      => $rowStatus,
                'validation_status'     => $validation['status'],
                'validation_messages'   => $validation['messages'],
                'is_duplicate_event'    => $isDuplicateEvent,
                'is_existing_applicant' => $isExistingApplicant,
            ]);

            $totalStaged++;
        };

        if ($sourceType === HistoricalImportBatch::SOURCE_XLSX) {
            $this->xlsxReader->read($filePath, $rowHandler, $sheetIndex);
        } else {
            $this->csvReader->read($filePath, $rowHandler);
        }

        $batch->update([
            'total_rows_detected' => $totalDetected,
            'total_rows_imported' => 0,
            'total_rows_skipped'  => 0,
            'total_rows_failed'   => 0,
        ]);

        return $batch->fresh(['rows']);
    }

    /**
     * Membuat batch staging dari sumber Google Sheets lama secara read-only.
     */
    public function createStagingBatchFromGoogleSheets(
        string $spreadsheetId,
        string $tabName,
        array $columnMappingConfig,
        ?string $defaultStatus = InternshipRegistration::STATUS_WAITING,
        ?string $startDateFilter = null,
        ?string $endDateFilter = null,
        ?int $userId = null,
        ?int $maxRows = null,
        ?SpreadsheetConnection $connection = null
    ): HistoricalImportBatch {
        $conn = $this->resolveRegistrationConnection($connection, $spreadsheetId);

        $targetConn = clone $conn;
        $targetConn->spreadsheet_id = $spreadsheetId;

        $batch = HistoricalImportBatch::create([
            'source_type'           => HistoricalImportBatch::SOURCE_GOOGLE_SHEETS,
            'source_filename'       => "Google Sheet [{$tabName}] - {$spreadsheetId}",
            'source_identifier'     => $spreadsheetId,
            'source_tab_name'       => $tabName,
            'source_file_hash'      => hash('sha256', "GS|{$spreadsheetId}|{$tabName}"),
            'column_mapping_config' => $columnMappingConfig,
            'status'                => HistoricalImportBatch::STATUS_STAGED,
            'created_by'            => $userId,
        ]);

        $filterStart = $startDateFilter ? $this->parseFilterDate($startDateFilter) : null;
        $filterEnd = $endDateFilter ? $this->parseFilterDate($endDateFilter, true) : null;

        $page = 1;
        $pageSize = 50;
        $totalDetected = 0;
        $totalStaged = 0;
        $hasMore = true;
        $fallbackRowIndex = 1;

        while ($hasMore) {
            $resp = $this->transport->readRowsPage(
                connection: $targetConn,
                tabName: $tabName,
                headerRow: 1,
                page: $page,
                pageSize: $pageSize,
                maskPii: false
            );

            if (!$resp->isSuccess()) {
                throw new DomainException("Gagal membaca halaman {$page} dari Google Sheets: " . $resp->getErrorMessage());
            }

            $pageData = $resp->getData();
            $rows = $pageData['rows'] ?? [];
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $remoteRow) {
                $sheetRowNumber = $remoteRow['_row_number'] ?? (++$fallbackRowIndex);
                unset($remoteRow['_row_number']);
                $totalDetected++;

                $mappedData = $this->mapRowValues($remoteRow, $columnMappingConfig);
                $submissionDt = $this->extractSubmissionTimestamp($remoteRow, $mappedData);

                // Filter rentang timestamp jika ditentukan
                if ($submissionDt) {
                    if ($filterStart && $submissionDt < $filterStart) {
                        continue;
                    }
                    if ($filterEnd && $submissionDt > $filterEnd) {
                        continue;
                    }
                } elseif ($filterStart || $filterEnd) {
                    // Lewati baris tanpa timestamp apabila filter rentang waktu aktif
                    continue;
                }

                $targetYear = $submissionDt ? (int) $submissionDt->format('Y') : (int) date('Y');

                $fingerprint = $this->computeEventFingerprint(
                    $mappedData,
                    $submissionDt?->format('Y-m-d H:i:s'),
                    $batch->source_identifier,
                    $sheetRowNumber
                );

                $isDuplicateEvent = HistoricalImportRow::where('event_fingerprint', $fingerprint)->exists()
                    || InternshipRegistration::where('event_fingerprint', $fingerprint)->exists();

                $email = $mappedData['email'] ?? null;
                $isExistingApplicant = false;
                if (!empty($email)) {
                    $isExistingApplicant = InternshipRegistration::where('email', $email)->exists();
                }

                $validation = $this->validateMappedRow($mappedData);
                $rowStatus = self::normalizeLifecycleStatus($mappedData['status'] ?? null, $defaultStatus ?? InternshipRegistration::STATUS_WAITING);

                HistoricalImportRow::create([
                    'batch_id'              => $batch->id,
                    'row_index'             => $sheetRowNumber,
                    'event_fingerprint'     => $fingerprint,
                    'raw_data'              => $remoteRow,
                    'mapped_data'           => $mappedData,
                    'submission_timestamp'  => $submissionDt?->format('Y-m-d H:i:s'),
                    'target_year'           => $targetYear,
                    'lifecycle_status'      => $rowStatus,
                    'validation_status'     => $validation['status'],
                    'validation_messages'   => $validation['messages'],
                    'is_duplicate_event'    => $isDuplicateEvent,
                    'is_existing_applicant' => $isExistingApplicant,
                ]);

                $totalStaged++;
                if ($maxRows !== null && $totalStaged >= $maxRows) {
                    $hasMore = false;
                    break;
                }
            }

            $hasMore = $hasMore && !empty($pageData['has_more']) && !empty($pageData['next_page']);
            $page++;
        }

        $batch->update([
            'total_rows_detected' => $totalDetected,
            'total_rows_imported' => 0,
            'total_rows_skipped'  => 0,
            'total_rows_failed'   => 0,
        ]);

        return $batch->fresh(['rows']);
    }

    /**
     * Komit batch staging ke database operasional dan siapkan sinkronisasi spreadsheet.
     */
    public function commitStagingBatch(
        HistoricalImportBatch $batch,
        ?SpreadsheetConnection $connection = null
    ): array {
        if ($batch->status === HistoricalImportBatch::STATUS_COMMITTED) {
            throw new DomainException("Batch impor #{$batch->id} sudah pernah dikomit sebelumnya.");
        }

        $conn = $connection ?: SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$conn) {
            throw new DomainException("Koneksi Spreadsheet Pendaftaran resmi belum dikonfigurasi.");
        }

        // 1. Eksekusi pra-pendaftaran field historis nonaktif ke FormFieldRegistry dan Spreadsheet Schema
        $this->provisionHistoricalFields($batch->columnMappingConfig ?? [], $conn);

        $importedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $createdRegistrations = [];

        $rows = $batch->rows()->where('validation_status', '!=', HistoricalImportRow::STATUS_ERROR)->get();

        foreach ($rows as $stagedRow) {
            // Lewati jika terbukti duplikat peristiwa yang sama
            if ($stagedRow->is_duplicate_event) {
                $skippedCount++;
                continue;
            }

            try {
                DB::transaction(function () use ($stagedRow, $batch, $conn, &$importedCount, &$createdRegistrations) {
                    $mapped = $stagedRow->mapped_data ?? [];
                    $targetYear = $stagedRow->target_year ?: (int) date('Y');

                    // Buat pendaftaran outsider aman tanpa membuat akun login
                    $reg = InternshipRegistration::create([
                        'public_uuid'                 => (string) Str::uuid(),
                        'user_id'                     => null, // Outsider pendaftar mandiri
                        'email'                       => $mapped['email'] ?? null,
                        'fullname'                    => $mapped['fullname'] ?? '-',
                        'student_id'                  => $mapped['student_id'] ?? null,
                        'phone_number'                => $mapped['phone_number'] ?? '-',
                        'raw_institution_name'        => $mapped['raw_institution_name'] ?? ($mapped['institution_name'] ?? null),
                        'raw_faculty_name'            => $mapped['raw_faculty_name'] ?? null,
                        'raw_study_program_name'      => $mapped['raw_study_program_name'] ?? ($mapped['study_program'] ?? null),
                        'internship_type'             => $mapped['internship_type'] ?? 'regular',
                        'gender'                      => $mapped['gender'] ?? 'Laki-laki',
                        'current_status'              => $mapped['current_status'] ?? 'Mahasiswa',
                        'wfo_readiness'               => $mapped['wfo_readiness'] ?? 'Siap',
                        'schedule_shift_readiness'    => $mapped['schedule_shift_readiness'] ?? 'Siap',
                        'internship_status'           => $stagedRow->lifecycle_status ?: InternshipRegistration::STATUS_WAITING,
                        'is_outsider'                 => true,
                        'registration_provenance'     => 'historical_import',
                        'historical_import_batch_id'  => $batch->id,
                        'event_fingerprint'           => $stagedRow->event_fingerprint,
                        'created_at'                  => $stagedRow->submission_timestamp ?: now(),
                    ]);

                    // Simpan custom answers dan field historis
                    $customAnswers = $mapped['custom_answers'] ?? [];
                    foreach ($customAnswers as $fKey => $fVal) {
                        if ($fVal !== null && $fVal !== '') {
                            RegistrationCustomAnswer::create([
                                'registration_id' => $reg->id,
                                'field_key'       => $fKey,
                                'answer_value'    => is_array($fVal) ? json_encode($fVal) : (string) $fVal,
                            ]);
                        }
                    }

                    // Buat antrean sinkronisasi SpreadsheetRowSync khusus channel approved_historical_backfill
                    $syncId = (string) Str::uuid();
                    $tabName = (string) $targetYear;

                    SpreadsheetRowSync::create([
                        'public_uuid'               => (string) Str::uuid(),
                        'spreadsheet_connection_id' => $conn->id,
                        'external_sync_id'          => $syncId,
                        'syncable_type'             => InternshipRegistration::class,
                        'syncable_id'               => $reg->id,
                        'target_sheet_tab'          => $tabName,
                        'sync_channel'              => 'approved_historical_backfill',
                        'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
                        'requires_sidecar_sync'     => !empty($customAnswers),
                        'max_attempts'              => 5,
                    ]);

                    $stagedRow->update([
                        'internship_registration_id' => $reg->id,
                        'validation_status'          => HistoricalImportRow::STATUS_VALID,
                    ]);

                    $importedCount++;
                    $createdRegistrations[] = $reg;
                });
            } catch (\Throwable $e) {
                Log::error("Historical import commit failed on staged row #{$stagedRow->id}: " . $e->getMessage());
                $stagedRow->update([
                    'validation_status'   => HistoricalImportRow::STATUS_ERROR,
                    'validation_messages' => ['exception' => $e->getMessage()],
                ]);
                $failedCount++;
            }
        }

        $batch->update([
            'status'              => HistoricalImportBatch::STATUS_COMMITTED,
            'committed_at'        => now(),
            'total_rows_imported' => $importedCount,
            'total_rows_skipped'  => $skippedCount,
            'total_rows_failed'   => $failedCount,
        ]);

        return [
            'batch_id'       => $batch->id,
            'imported_count' => $importedCount,
            'skipped_count'  => $skippedCount,
            'failed_count'   => $failedCount,
        ];
    }

    /**
     * Daftarkan field historis nonaktif ke katalog FormFieldRegistry dan skema kolom Spreadsheet.
     */
    protected function provisionHistoricalFields(array $mappingConfig, SpreadsheetConnection $connection): void
    {
        foreach ($mappingConfig as $item) {
            $mode = $item['action'] ?? 'ignore'; // ignore, map_existing, historical_only
            if ($mode !== 'historical_only') {
                continue;
            }

            $fieldKey = $item['field_key'] ?? null;
            $header = $item['header'] ?? null;
            $type = $item['data_type'] ?? 'string';

            if (empty($fieldKey) || empty($header)) {
                continue;
            }

            // Pastikan terdaftar di FormFieldRegistry
            $reg = FormFieldRegistry::where('field_key', $fieldKey)->first();
            if (!$reg) {
                FormFieldRegistry::create([
                    'field_key'                => $fieldKey,
                    'field_type'               => $type,
                    'field_state'              => FormFieldRegistry::STATE_HISTORICAL_ONLY,
                    'canonical_label'          => $header,
                    'form_enabled'             => false, // Nonaktif pada formulir publik
                    'history_storage_enabled'  => true,  // Tersimpan di database
                    'spreadsheet_sync_enabled' => true,  // Disinkronkan ke tab Spreadsheet terkait
                    'source_header_alias'      => $header,
                    'is_locked'                => true,
                ]);
            }
        }
    }

    /**
     * Hitung event fingerprint stabil untuk mencegah impor ganda berkas/peristiwa yang sama.
     * Menggunakan identitas komposit pendaftaran: timestamp, email, NIM/NIS, nama, nomor HP normalisasi.
     */
    public function computeEventFingerprint(
        array $mappedData,
        ?string $submissionTimestamp,
        ?string $sourceIdentifier = null,
        int $rowIndex = 0
    ): string {
        $email = strtolower(trim((string) ($mappedData['email'] ?? '')));
        $nim = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($mappedData['student_id'] ?? ''));
        $name = strtolower(preg_replace('/\s+/', ' ', trim((string) ($mappedData['fullname'] ?? ''))));
        $phone = preg_replace('/\D/', '', (string) ($mappedData['phone_number'] ?? ''));
        if (str_starts_with($phone, '628')) {
            $phone = '08' . substr($phone, 3);
        }
        $ts = $submissionTimestamp ?: '';

        // Jika pendaftar memiliki identitas bisnis (email/nim/phone) dan timestamp pengajuan:
        // Hash tingkat peristiwa murni (terbukti mendeteksi duplikat lintas file/sumber impor berbeda)
        if (!empty($ts) && (!empty($email) || !empty($nim) || !empty($phone))) {
            return hash('sha256', "EVENT|{$ts}|{$email}|{$nim}|{$phone}|{$name}");
        }

        // Fallback jika tidak ada timestamp atau minim identitas
        return hash('sha256', "EVENT|{$sourceIdentifier}|{$ts}|{$email}|{$nim}|{$phone}|{$name}|{$rowIndex}");
    }

    /**
     * Parse string tanggal filter menjadi DateTimeImmutable Asia/Jakarta.
     */
    protected function parseFilterDate(string $dateStr, bool $endOfDay = false): ?DateTimeImmutable
    {
        try {
            $dt = new DateTimeImmutable(trim($dateStr), new DateTimeZone('Asia/Jakarta'));
            return $endOfDay ? $dt->setTime(23, 59, 59) : $dt->setTime(0, 0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalisasi status magang 6 lifecycle status standar SERTIF.
     */
    public static function normalizeLifecycleStatus(?string $rawStatus, string $fallback = InternshipRegistration::STATUS_WAITING): string
    {
        if (empty($rawStatus)) {
            return $fallback;
        }
        $clean = strtolower(trim($rawStatus));
        return match (true) {
            str_contains($clean, 'aktif') || str_contains($clean, 'active') => InternshipRegistration::STATUS_ACTIVE,
            str_contains($clean, 'terima') || str_contains($clean, 'accept') || str_contains($clean, 'lolos') => InternshipRegistration::STATUS_ACCEPTED,
            str_contains($clean, 'selesai') || str_contains($clean, 'complet') || str_contains($clean, 'alumni') || str_contains($clean, 'lulus') => InternshipRegistration::STATUS_COMPLETED,
            str_contains($clean, 'keluar') || str_contains($clean, 'exit') || str_contains($clean, 'mundur') || str_contains($clean, 'undur') || str_contains($clean, 'drop') => InternshipRegistration::STATUS_EXITED,
            str_contains($clean, 'tolak') || str_contains($clean, 'reject') => InternshipRegistration::STATUS_REJECTED,
            str_contains($clean, 'tunggu') || str_contains($clean, 'wait') || str_contains($clean, 'pending') => InternshipRegistration::STATUS_WAITING,
            default => in_array($clean, [
                InternshipRegistration::STATUS_WAITING,
                InternshipRegistration::STATUS_ACCEPTED,
                InternshipRegistration::STATUS_ACTIVE,
                InternshipRegistration::STATUS_COMPLETED,
                InternshipRegistration::STATUS_EXITED,
                InternshipRegistration::STATUS_REJECTED,
            ], true) ? $clean : $fallback,
        };
    }

    /**
     * Mapping data baris mentah berdasarkan konfigurasi kolom admin.
     */
    protected function mapRowValues(array $rawAssoc, array $mappingConfig): array
    {
        $mapped = [
            'custom_answers' => [],
        ];

        foreach ($mappingConfig as $rule) {
            $srcHeader = $rule['header'] ?? null;
            $action = $rule['action'] ?? 'ignore';
            $val = $rawAssoc[$srcHeader] ?? null;

            if ($val === null || $action === 'ignore') {
                continue;
            }

            if ($action === 'map_existing') {
                $targetField = $rule['target_field'] ?? null;
                if ($targetField) {
                    $mapped[$targetField] = $val;
                }
            } elseif ($action === 'historical_only') {
                $fieldKey = $rule['field_key'] ?? null;
                if ($fieldKey) {
                    $mapped['custom_answers'][$fieldKey] = $val;
                }
            }
        }

        return $mapped;
    }

    /**
     * Ekstrak timestamp pendaftaran dari nilai tanggal/timestamp.
     */
    protected function extractSubmissionTimestamp(array $rawAssoc, array $mappedData): ?DateTimeImmutable
    {
        $candidate = $mappedData['created_at'] ?? $mappedData['timestamp'] ?? null;
        if (!$candidate) {
            foreach ($rawAssoc as $k => $v) {
                if (preg_match('/timestamp|waktu|tanggal\s+daftar/i', $k) && !empty($v)) {
                    $candidate = $v;
                    break;
                }
            }
        }

        if (empty($candidate)) {
            return null;
        }

        // Jika angka numerik serial Excel
        if (is_numeric($candidate)) {
            $dtStr = StreamingXlsxReader::excelSerialToDateTime((float) $candidate);
            if ($dtStr) {
                return new DateTimeImmutable($dtStr, new DateTimeZone('Asia/Jakarta'));
            }
        }

        $str = trim((string) $candidate);

        // Format d/m/Y atau d-m-Y (dengan atau tanpa jam:menit:detik) standar formulir Indonesia
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/', $str, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            $hour = isset($m[4]) ? (int) $m[4] : 0;
            $minute = isset($m[5]) ? (int) $m[5] : 0;
            $second = isset($m[6]) ? (int) $m[6] : 0;
            if (checkdate($month, $day, $year)) {
                return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))
                    ->setDate($year, $month, $day)
                    ->setTime($hour, $minute, $second);
            }
        }

        try {
            return new DateTimeImmutable($str, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validasi kelayakan pendaftaran baris.
     */
    protected function validateMappedRow(array $mapped): array
    {
        $messages = [];
        if (empty($mapped['fullname'])) {
            $messages[] = 'Nama lengkap kosong.';
        }
        if (empty($mapped['email']) && empty($mapped['phone_number'])) {
            $messages[] = 'Kontak pendaftar (Email atau No. HP) wajib tersedia setidaknya satu.';
        }

        $status = empty($messages) ? HistoricalImportRow::STATUS_VALID : HistoricalImportRow::STATUS_WARNING;

        return [
            'status'   => $status,
            'messages' => $messages,
        ];
    }

    /**
     * Resolusi koneksi spreadsheet registrasi dengan fallback aman untuk pengujian.
     */
    protected function resolveRegistrationConnection(?SpreadsheetConnection $connection = null, ?string $fallbackSheetId = null): ?SpreadsheetConnection
    {
        if ($connection) {
            return $connection;
        }

        try {
            $conn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
            if ($conn) {
                return $conn;
            }
        } catch (\Throwable) {
            // Pengujian standalone tanpa koneksi DB aktif
        }

        if ($fallbackSheetId) {
            return new SpreadsheetConnection([
                'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
                'spreadsheet_id' => $fallbackSheetId,
            ]);
        }

        return null;
    }
}
