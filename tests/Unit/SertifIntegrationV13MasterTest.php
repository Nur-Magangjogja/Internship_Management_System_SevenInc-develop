<?php

namespace Tests\Unit;

use App\Models\CloudStorageSetting;
use App\Models\FormFieldRegistry;
use App\Models\InternshipRegistration;
use App\Services\ActiveSheetManualRowReconciliationService;
use App\Services\HistoricalImport\NativeCsvReader;
use App\Services\HistoricalImport\StreamingXlsxReader;
use App\Services\HistoricalImport\HistoricalImportPipelineService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use PHPUnit\Framework\TestCase;

class SertifIntegrationV13MasterTest extends TestCase
{
    /**
     * Skenario 1: Verifikasi kontrak baseline kanonikal 38 kolom.
     */
    public function test_canonical_38_column_baseline_contract(): void
    {
        $cols = Spreadsheet2026SchemaSeeder::getCanonicalColumns();

        $this->assertCount(38, $cols, 'Kontrak kanonikal baseline 2026 harus tepat 38 kolom.');

        // 33 kolom inti pertama
        $this->assertSame('Timestamp', $cols[0]['header']);
        $this->assertSame('Nama Lengkap', $cols[1]['header']);
        $this->assertSame('Email', $cols[4]['header']);

        // 5 kolom penutup sistem
        $this->assertSame('KET', $cols[33]['header']);
        $this->assertSame('display_only', $cols[33]['direction']);

        $this->assertSame('SERTIF_SYNC_ID', $cols[34]['header']);
        $this->assertSame('system', $cols[34]['direction']);

        $this->assertSame('SERTIF_REGISTRATION_ID', $cols[35]['header']);
        $this->assertSame('system', $cols[35]['direction']);

        $this->assertSame('SERTIF_SYNC_STATUS', $cols[36]['header']);
        $this->assertSame('system', $cols[36]['direction']);

        $this->assertSame('SERTIF_LAST_SYNC_AT', $cols[37]['header']);
        $this->assertSame('system', $cols[37]['direction']);
    }

    /**
     * Skenario 2: Verifikasi konstanta dan status katalog FormFieldRegistry untuk field historis.
     */
    public function test_form_field_registry_historical_constants_and_helpers(): void
    {
        $this->assertSame('historical_only', FormFieldRegistry::STATE_HISTORICAL_ONLY);
        $this->assertSame('form_field', FormFieldRegistry::STATE_FORM_FIELD);
        $this->assertSame('archived', FormFieldRegistry::STATE_ARCHIVED);

        $registry = new FormFieldRegistry([
            'field_key'                => 'custom_riwayat_organisasi',
            'field_type'               => 'text',
            'field_state'              => FormFieldRegistry::STATE_HISTORICAL_ONLY,
            'canonical_label'          => 'Riwayat Organisasi',
            'form_enabled'             => false,
            'history_storage_enabled'  => true,
            'spreadsheet_sync_enabled' => true,
        ]);

        $this->assertTrue($registry->isHistoricalOnly());
        $this->assertFalse($registry->isFormActive());

        // Setelah dipromosikan ke form field
        $registry->field_state = FormFieldRegistry::STATE_FORM_FIELD;
        $registry->form_enabled = true;
        $this->assertFalse($registry->isHistoricalOnly());
        $this->assertTrue($registry->isFormActive());
    }

    /**
     * Skenario 3: Penghapusan ketergantungan runtime terhadap CLOUD_STORAGE_PROVIDER.
     */
    public function test_cloud_storage_setting_active_provider_never_depends_on_cloud_storage_provider(): void
    {
        $setting = new CloudStorageSetting();
        $this->assertSame(CloudStorageSetting::PROVIDER_APPS_SCRIPT_DRIVE, $setting->getActiveProvider());
    }

    /**
     * Skenario 4: StreamingXlsxReader - Konversi koordinat sel sparse ke 0-based column index.
     */
    public function test_streaming_xlsx_reader_sparse_coordinate_conversion(): void
    {
        $reader = new StreamingXlsxReader();

        $this->assertSame(0, $reader->coordinateToColumnIndex('A1'));
        $this->assertSame(2, $reader->coordinateToColumnIndex('C5'));
        $this->assertSame(25, $reader->coordinateToColumnIndex('Z10'));
        $this->assertSame(26, $reader->coordinateToColumnIndex('AA1'));
        $this->assertSame(37, $reader->coordinateToColumnIndex('AL100')); // Kolom ke-38
    }

    /**
     * Skenario 5: StreamingXlsxReader - Konversi serial tanggal Excel 1900 dengan leap year bug fix.
     */
    public function test_streaming_xlsx_reader_excel_serial_date_conversion_with_1900_leap_year_bug(): void
    {
        // Serial 44927 = 2023-01-01 (1 Januari 2023)
        $dateStr = StreamingXlsxReader::excelSerialToDateTime(44927, false, 'Asia/Jakarta');
        $this->assertSame('2023-01-01', $dateStr);

        // Serial 45323 = 2024-02-01 (1 Februari 2024)
        $dateStr2 = StreamingXlsxReader::excelSerialToDateTime(45323, false, 'Asia/Jakarta');
        $this->assertSame('2024-02-01', $dateStr2);

        // Invalid serial
        $this->assertNull(StreamingXlsxReader::excelSerialToDateTime('bukan_angka'));
        $this->assertNull(StreamingXlsxReader::excelSerialToDateTime(-5));
    }

    /**
     * Skenario 6: StreamingXlsxReader - Konversi serial tanggal Excel sistem 1904.
     */
    public function test_streaming_xlsx_reader_excel_serial_date_conversion_1904_system(): void
    {
        // Pada sistem 1904, hari ke-0 adalah 1 Januari 1904, hari ke-1 adalah 2 Januari 1904
        $dateStr = StreamingXlsxReader::excelSerialToDateTime(1, true, 'Asia/Jakarta');
        $this->assertSame('1904-01-02', $dateStr);
    }

    /**
     * Skenario 7: NativeCsvReader - Auto detect delimiter.
     */
    public function test_native_csv_reader_auto_detect_delimiter(): void
    {
        $reader = new NativeCsvReader();

        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tmpFile, "Header1;Header2;Header3\nVal1;Val2;Val3\nVal4;Val5;Val6");

        try {
            $delim = $reader->detectDelimiter($tmpFile);
            $this->assertSame(';', $delim);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Skenario 8: NativeCsvReader - Streaming dan stripping BOM UTF-8.
     */
    public function test_native_csv_reader_streaming_and_bom_stripping(): void
    {
        $reader = new NativeCsvReader();

        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_bom_');
        // File CSV dengan UTF-8 BOM
        file_put_contents($tmpFile, "\xEF\xBB\xBFNama Lengkap,Email,Kota\nBudi Santoso,budi@example.com,Yogyakarta\nSiti Rahma,siti@example.com,Sleman");

        try {
            $rows = [];
            $total = $reader->read($tmpFile, function ($rowValues, $rowIndex) use (&$rows) {
                $rows[$rowIndex] = $rowValues;
            });

            $this->assertSame(3, $total);
            // Header baris 1 tidak boleh memiliki karakter BOM aneh
            $this->assertSame('Nama Lengkap', $rows[1][0]);
            $this->assertSame('Email', $rows[1][1]);
            $this->assertSame('Budi Santoso', $rows[2][0]);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Skenario 9: Idempotensi peristiwa pendaftaran (Composite Event Fingerprint).
     */
    public function test_historical_import_event_fingerprint_idempotency(): void
    {
        // Dummy pipeline service mock
        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $this->createMock(\App\Services\SpreadsheetTransport\GoogleAppsScriptTransport::class)
        );

        $mappedData1 = [
            'fullname'     => 'Anisa Rahmawati',
            'email'        => 'anisa@example.com',
            'student_id'   => '2021001',
            'phone_number' => '08123456789',
        ];

        $mappedData2Identical = [
            'fullname'     => '  anisa  rahmawati  ', // Spasi berbeda, huruf kecil/besar
            'email'        => 'ANISA@EXAMPLE.COM',      // Case insensitive
            'student_id'   => '2021-001',               // Format tanda hubung
            'phone_number' => '+628123456789',          // Prefix internasional
        ];

        $fp1 = $service->computeEventFingerprint($mappedData1, '2026-02-01 10:00:00', 'source_a.xlsx', 5);
        $fp2 = $service->computeEventFingerprint($mappedData2Identical, '2026-02-01 10:00:00', 'source_a.xlsx', 5);

        // Peristiwa yang sama persis menghasilkan hash identik
        $this->assertSame($fp1, $fp2, 'Normalisasi data pendaftaran harus menghasilkan event fingerprint identik.');

        // Orang yang sama tapi peristiwa pendaftaran berbeda (misal tahun berbeda) menghasilkan fingerprint berbeda
        $fpDifferentPeriod = $service->computeEventFingerprint($mappedData1, '2027-02-01 10:00:00', 'source_b.xlsx', 10);
        $this->assertNotSame($fp1, $fpDifferentPeriod, 'Pendaftaran pada periode berbeda oleh orang yang sama harus menghasilkan fingerprint berbeda.');
    }

    /**
     * Skenario 10: Outsider - getEmailAttribute fallback ke atribut registrasi ketika relasi user null.
     */
    public function test_outsider_model_email_accessor_fallback(): void
    {
        $reg = new InternshipRegistration();
        $reg->setRawAttributes([
            'user_id' => null,
            'email'   => 'outsider@pendaftar.com',
        ]);

        $this->assertSame('outsider@pendaftar.com', $reg->email);
    }

    /**
     * Skenario 11: Outsider - getInstitutionNameAttribute fallback ke raw_institution_name.
     */
    public function test_outsider_model_institution_accessor_fallback(): void
    {
        $reg = new InternshipRegistration();
        $reg->setRawAttributes([
            'institution_id'       => null,
            'raw_institution_name' => 'Universitas Indonesia Mandiri',
        ]);

        $this->assertSame('Universitas Indonesia Mandiri', $reg->institution_name);
    }

    /**
     * Skenario 12: Outsider - getStudyProgramAttribute fallback ke raw_study_program_name.
     */
    public function test_outsider_model_study_program_accessor_fallback(): void
    {
        $reg = new InternshipRegistration();
        $reg->setRawAttributes([
            'study_program_id'       => null,
            'raw_study_program_name' => 'Teknologi Informasi & Data',
        ]);

        $this->assertSame('Teknologi Informasi & Data', $reg->study_program);
    }

    /**
     * Skenario 13: Active Sheet Manual Row Reconciliation - Content checksum mengabaikan kolom sistem.
     */
    public function test_active_sheet_reconciliation_content_checksum_ignores_system_columns(): void
    {
        $service = new ActiveSheetManualRowReconciliationService(
            $this->createMock(\App\Services\SpreadsheetTransport\GoogleAppsScriptTransport::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class)
        );

        $rowBeforeSync = [
            'Timestamp'            => '2026-02-01 10:00:00',
            'Nama Lengkap'         => 'Rian Pratama',
            'Email'                => 'rian@example.com',
            'SERTIF_SYNC_ID'       => '',
            'SERTIF_SYNC_STATUS'   => '',
            'SERTIF_LAST_SYNC_AT'  => '',
        ];

        $rowAfterSync = [
            'Timestamp'            => '2026-02-01 10:00:00',
            'Nama Lengkap'         => 'Rian Pratama',
            'Email'                => 'rian@example.com',
            'SERTIF_SYNC_ID'       => 'b59de079-04fa-4d14-8f4b-0123456789ab',
            'SERTIF_SYNC_STATUS'   => 'synced',
            'SERTIF_LAST_SYNC_AT'  => '2026-02-01T10:05:00+07:00',
        ];

        $ref = new \ReflectionMethod($service, 'computeRowContentChecksum');
        $checksumBefore = $ref->invoke($service, $rowBeforeSync);
        $checksumAfter = $ref->invoke($service, $rowAfterSync);

        $this->assertSame($checksumBefore, $checksumAfter, 'Perubahan kolom sistem (SYNC_ID, STATUS, LAST_SYNC_AT) tidak boleh mengubah content checksum baris data.');
    }

    /**
     * Skenario 14: Sanitasi formula injection (CWE-1236) pada nilai spreadsheet.
     */
    public function test_formula_injection_sanitization(): void
    {
        $safeText = RegistrationSpreadsheetMappingService::escapeFormulaInjection('Nama Normal');
        $this->assertSame('Nama Normal', $safeText);

        $formulaEqual = RegistrationSpreadsheetMappingService::escapeFormulaInjection('=SUM(A1:A10)');
        $this->assertSame("'=SUM(A1:A10)", $formulaEqual);

        $formulaPlus = RegistrationSpreadsheetMappingService::escapeFormulaInjection('+628123456');
        $this->assertSame("'+628123456", $formulaPlus);

        $formulaMinus = RegistrationSpreadsheetMappingService::escapeFormulaInjection('-1000');
        $this->assertSame("'-1000", $formulaMinus);

        $formulaAt = RegistrationSpreadsheetMappingService::escapeFormulaInjection('@command()');
        $this->assertSame("'@command()", $formulaAt);
    }

    /**
     * Skenario 15: Deteksi error JSON terstruktur dari Google Apps Script Transport.
     */
    public function test_google_apps_script_transport_response_failure_detection(): void
    {
        $failure = SpreadsheetTransportResponse::failure(
            errorMessage: 'Target spreadsheet berizin legacy_source hanya diizinkan untuk operasi baca (read-only).',
            errorCode: 'LEGACY_SOURCE_READONLY',
            isRetryable: false,
            context: ['code' => 403]
        );

        $this->assertFalse($failure->success);
        $this->assertSame('LEGACY_SOURCE_READONLY', $failure->errorCode);
        $this->assertFalse($failure->isRetryable);
        $this->assertStringContainsString('legacy_source', $failure->errorMessage);
    }

    /**
     * Skenario 16: Google Apps Script Drive Driver - Fail-closed saat belum terkonfigurasi.
     */
    public function test_google_drive_driver_fail_closed_on_unconfigured(): void
    {
        $driver = new \App\Services\CloudStorage\GoogleAppsScriptDriveDriver();
        // Tanpa endpoint dan secret, isConfigured harus bernilai false
        $this->assertFalse($driver->isConfigured());
        $this->assertSame('google_apps_script_drive', $driver->getDriverName());
    }

    /**
     * Skenario 17: Validasi baris staging jika kontak tidak lengkap (Warning).
     */
    public function test_historical_import_staging_row_validation_missing_contacts(): void
    {
        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $this->createMock(\App\Services\SpreadsheetTransport\GoogleAppsScriptTransport::class)
        );

        $ref = new \ReflectionMethod($service, 'validateMappedRow');

        // Kontak kosong
        $valEmpty = $ref->invoke($service, ['fullname' => 'Test Saja', 'email' => '', 'phone_number' => '']);
        $this->assertSame('warning', $valEmpty['status']);
        $this->assertNotEmpty($valEmpty['messages']);

        // Lengkap
        $valOk = $ref->invoke($service, ['fullname' => 'Test Saja', 'email' => 'test@example.com']);
        $this->assertSame('valid', $valOk['status']);
    }

    /**
     * Skenario 18: Mock Spreadsheet Transport - Method baru target enrollment & attachRowSyncId.
     */
    public function test_mock_spreadsheet_transport_target_enrollment_and_attach_sync_id(): void
    {
        $mock = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();
        $conn = new \App\Models\SpreadsheetConnection(['connection_key' => 'registration']);

        $resInspect = $mock->inspectTarget($conn, 'spreadsheet', 'target_sheet_123');
        $this->assertTrue($resInspect->success);
        $this->assertSame('target_sheet_123', $resInspect->context['target_id']);

        $resActivate = $mock->activateAuthorizedTarget($conn, 'spreadsheet', 'target_sheet_123', 'registration');
        $this->assertTrue($resActivate->success);

        $resAttach = $mock->attachRowSyncId($conn, '2026', 15, 'sync-uuid-xyz');
        $this->assertTrue($resAttach->success);
        $this->assertSame(15, $resAttach->rowIndex);
    }

    /**
     * Skenario 19: Batas aman Zip Slip dan Zip Bomb pada StreamingXlsxReader.
     */
    public function test_xlsx_reader_zip_slip_and_bomb_thresholds(): void
    {
        $this->assertSame(15 * 1024 * 1024, StreamingXlsxReader::MAX_FILE_SIZE_BYTES);
        $this->assertSame(100 * 1024 * 1024, StreamingXlsxReader::MAX_UNCOMPRESSED_BYTES);
        $this->assertSame(1000, StreamingXlsxReader::MAX_ZIP_ENTRIES);
    }

    /**
     * Skenario 20: Batas ukuran aman pada NativeCsvReader.
     */
    public function test_csv_reader_file_size_threshold(): void
    {
        $this->assertSame(25 * 1024 * 1024, NativeCsvReader::MAX_FILE_SIZE_BYTES);
    }

    /**
     * Skenario 21: Model HistoricalImportBatch casts dan status constants.
     */
    public function test_historical_import_batch_model_constants(): void
    {
        $this->assertSame('staged', \App\Models\HistoricalImportBatch::STATUS_STAGED);
        $this->assertSame('committed', \App\Models\HistoricalImportBatch::STATUS_COMMITTED);
        $this->assertSame('xlsx', \App\Models\HistoricalImportBatch::SOURCE_XLSX);
        $this->assertSame('csv', \App\Models\HistoricalImportBatch::SOURCE_CSV);
    }

    /**
     * Skenario 22: Model HistoricalImportRow validation status constants.
     */
    public function test_historical_import_row_model_constants(): void
    {
        $this->assertSame('pending', \App\Models\HistoricalImportRow::STATUS_PENDING);
        $this->assertSame('valid', \App\Models\HistoricalImportRow::STATUS_VALID);
        $this->assertSame('warning', \App\Models\HistoricalImportRow::STATUS_WARNING);
        $this->assertSame('error', \App\Models\HistoricalImportRow::STATUS_ERROR);
    }

    /**
     * Skenario 23: Google Sheets read-only preview via analyzeGoogleSheet (Mock Transport).
     */
    public function test_analyze_google_sheet_read_only_with_mock_transport(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->forcedPreviewResponse = SpreadsheetTransportResponse::success(null, [
            'success'    => true,
            'tab_name'   => '2024',
            'header_row' => 1,
            'total_rows' => 45,
            'has_more'   => false,
            'rows'       => [
                [
                    '_row_number'   => 2,
                    'Timestamp'     => '15/08/2024 10:15:00',
                    'Nama Lengkap'  => 'Budi Prasetyo',
                    'Email'         => 'budi@example.com',
                    'No. Telepon'   => '081234567890',
                    'Status Magang' => 'active',
                ]
            ]
        ]);

        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $mockTransport
        );

        $result = $service->analyzeGoogleSheet('spreadsheet-id-test-123', '2024');

        $this->assertTrue($result['success']);
        $this->assertSame('spreadsheet-id-test-123', $result['spreadsheet_id']);
        $this->assertSame('2024', $result['tab_name']);
        $this->assertContains('Timestamp', $result['headers']);
        $this->assertContains('Nama Lengkap', $result['headers']);
        $this->assertContains('Email', $result['headers']);
        $this->assertSame(45, $result['total_rows']);
        $this->assertFalse($result['needs_tab_select']);
    }

    /**
     * Skenario 24: Google Sheets tab discovery ketika tab_name kosong.
     */
    public function test_analyze_google_sheet_tab_discovery_when_tab_empty(): void
    {
        $mockTransport = new MockSpreadsheetTransport();
        $mockTransport->forcedResponse = SpreadsheetTransportResponse::success(null, [
            'success'     => true,
            'target_type' => 'spreadsheet',
            'target_id'   => 'spreadsheet-legacy-abc',
            'name'        => 'Spreadsheet Pendaftar Lama 2023-2025',
            'tabs'        => [
                ['name' => '2023', 'sheet_id' => 0, 'last_row' => 120, 'last_column' => 38],
                ['name' => '2024', 'sheet_id' => 12345, 'last_row' => 200, 'last_column' => 38],
                ['name' => '2025', 'sheet_id' => 67890, 'last_row' => 150, 'last_column' => 38],
            ]
        ]);

        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $mockTransport
        );

        $result = $service->analyzeGoogleSheet('spreadsheet-legacy-abc', null);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['needs_tab_select']);
        $this->assertCount(3, $result['tabs']);
        $this->assertSame('2023', $result['tabs'][0]['name']);
        $this->assertSame('2024', $result['tabs'][1]['name']);
    }

    /**
     * Skenario 25: Normalisasi 6 Lifecycle Status standar SERTIF.
     */
    public function test_lifecycle_status_normalization_six_statuses(): void
    {
        // 1. Waiting
        $this->assertSame('waiting', HistoricalImportPipelineService::normalizeLifecycleStatus('Menunggu Review'));
        $this->assertSame('waiting', HistoricalImportPipelineService::normalizeLifecycleStatus('WAITING'));
        $this->assertSame('waiting', HistoricalImportPipelineService::normalizeLifecycleStatus('pending'));

        // 2. Accepted
        $this->assertSame('accepted', HistoricalImportPipelineService::normalizeLifecycleStatus('Diterima'));
        $this->assertSame('accepted', HistoricalImportPipelineService::normalizeLifecycleStatus('Lolos Magang'));
        $this->assertSame('accepted', HistoricalImportPipelineService::normalizeLifecycleStatus('accepted'));

        // 3. Active
        $this->assertSame('active', HistoricalImportPipelineService::normalizeLifecycleStatus('Aktif'));
        $this->assertSame('active', HistoricalImportPipelineService::normalizeLifecycleStatus('Sedang Aktif Magang'));
        $this->assertSame('active', HistoricalImportPipelineService::normalizeLifecycleStatus('ACTIVE'));

        // 4. Completed
        $this->assertSame('completed', HistoricalImportPipelineService::normalizeLifecycleStatus('Selesai'));
        $this->assertSame('completed', HistoricalImportPipelineService::normalizeLifecycleStatus('Lulus'));
        $this->assertSame('completed', HistoricalImportPipelineService::normalizeLifecycleStatus('Alumni'));

        // 5. Exited
        $this->assertSame('exited', HistoricalImportPipelineService::normalizeLifecycleStatus('Keluar'));
        $this->assertSame('exited', HistoricalImportPipelineService::normalizeLifecycleStatus('Mengundurkan Diri'));
        $this->assertSame('exited', HistoricalImportPipelineService::normalizeLifecycleStatus('Mundur'));

        // 6. Rejected
        $this->assertSame('rejected', HistoricalImportPipelineService::normalizeLifecycleStatus('Ditolak'));
        $this->assertSame('rejected', HistoricalImportPipelineService::normalizeLifecycleStatus('reject'));

        // Fallback default
        $this->assertSame('waiting', HistoricalImportPipelineService::normalizeLifecycleStatus(null));
        $this->assertSame('waiting', HistoricalImportPipelineService::normalizeLifecycleStatus(''));
    }

    /**
     * Skenario 26: Ekstraksi dan perutean tahun target berdasarkan Timestamp asli (2023, 2024, 2025, 2026).
     */
    public function test_submission_timestamp_extraction_and_year_routing(): void
    {
        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $this->createMock(SpreadsheetTransportInterface::class)
        );

        $ref = new \ReflectionClass($service);
        $extractMethod = $ref->getMethod('extractSubmissionTimestamp');
        $extractMethod->setAccessible(true);

        // Format d/m/Y H:i:s (Indonesia)
        $dt2023 = $extractMethod->invoke($service, ['Timestamp' => '17/08/2023 09:30:00'], []);
        $this->assertNotNull($dt2023);
        $this->assertSame('2023', $dt2023->format('Y'));
        $this->assertSame('2023-08-17 09:30:00', $dt2023->format('Y-m-d H:i:s'));

        // Format ISO 2024
        $dt2024 = $extractMethod->invoke($service, ['created_at' => '2024-05-10 14:00:00'], ['created_at' => '2024-05-10 14:00:00']);
        $this->assertNotNull($dt2024);
        $this->assertSame('2024', $dt2024->format('Y'));

        // Format d-m-Y 2025
        $dt2025 = $extractMethod->invoke($service, ['Waktu Pendaftaran' => '25-12-2025 18:20:00'], []);
        $this->assertNotNull($dt2025);
        $this->assertSame('2025', $dt2025->format('Y'));

        // Serial Excel 2026 (46042 = 2026-01-20)
        $dt2026 = $extractMethod->invoke($service, ['timestamp' => '46042'], ['timestamp' => '46042']);
        $this->assertNotNull($dt2026);
        $this->assertSame('2026', $dt2026->format('Y'));
    }

    /**
     * Skenario 27: Idempotensi lintas berkas (Cross-Source Event Fingerprint Idempotency).
     */
    public function test_cross_source_composite_event_fingerprint_idempotency(): void
    {
        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $this->createMock(SpreadsheetTransportInterface::class)
        );

        $applicantData = [
            'fullname'     => 'Fajar Nugroho',
            'email'        => 'fajar@example.com',
            'student_id'   => '18001234',
            'phone_number' => '081298765432',
        ];

        // Diunggah dari berkas A pada baris 5
        $fpFromSourceA = $service->computeEventFingerprint($applicantData, '2023-09-01 08:30:00', 'berkas_lama_2023.xlsx', 5);

        // Diunggah ulang dari berkas B (misal ekspor ulang) pada baris 102
        $fpFromSourceB = $service->computeEventFingerprint([
            'fullname'     => '  FAJAR   NUGROHO  ',
            'email'        => 'FAJAR@EXAMPLE.COM',
            'student_id'   => '1800-1234',
            'phone_number' => '+6281298765432',
        ], '2023-09-01 08:30:00', 're_export_all.csv', 102);

        // Keduanya mewakili peristiwa pendaftaran yang sama persis
        $this->assertSame($fpFromSourceA, $fpFromSourceB, 'Peristiwa pendaftaran identik dari sumber berbeda harus menghasilkan fingerprint sama.');
    }

    /**
     * Skenario 28: Verifikasi parser tanggal filter dan logika pemfilteran rentang tanggal pendaftaran.
     */
    public function test_filter_date_parser_and_range_validation(): void
    {
        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $this->createMock(SpreadsheetTransportInterface::class)
        );

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('parseFilterDate');
        $method->setAccessible(true);

        $start = $method->invoke($service, '2024-01-01', false);
        $end = $method->invoke($service, '2024-12-31', true);

        $this->assertSame('2024-01-01 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2024-12-31 23:59:59', $end->format('Y-m-d H:i:s'));

        // Baris tahun 2023 berada sebelum batas mulai
        $dt2023 = new \DateTimeImmutable('2023-11-15 10:00:00', new \DateTimeZone('Asia/Jakarta'));
        $this->assertTrue($dt2023 < $start);

        // Baris tahun 2024 berada dalam rentang
        $dt2024 = new \DateTimeImmutable('2024-06-15 14:30:00', new \DateTimeZone('Asia/Jakarta'));
        $this->assertTrue($dt2024 >= $start && $dt2024 <= $end);

        // Baris tahun 2025 berada setelah batas selesai
        $dt2025 = new \DateTimeImmutable('2025-01-05 09:00:00', new \DateTimeZone('Asia/Jakarta'));
        $this->assertTrue($dt2025 > $end);
    }

    /**
     * Skenario 29: Verifikasi konfigurasi aksi pemetaan kolom sumber (Ignore, Map Existing, Historical Only).
     */
    public function test_column_mapping_rule_structure_and_resolution(): void
    {
        $service = new HistoricalImportPipelineService(
            $this->createMock(StreamingXlsxReader::class),
            $this->createMock(NativeCsvReader::class),
            $this->createMock(RegistrationSpreadsheetMappingService::class),
            $this->createMock(\App\Services\SpreadsheetSyncEngineService::class),
            $this->createMock(SpreadsheetTransportInterface::class)
        );

        $ref = new \ReflectionClass($service);
        $mapMethod = $ref->getMethod('mapRowValues');
        $mapMethod->setAccessible(true);

        $rawRow = [
            'Nama Lengkap'   => 'Rina Astuti',
            'Email Akun'     => 'rina@domain.ac.id',
            'Catatan Khusus' => 'Pernah ikut bootcamp AI 2023',
            'Kolom Sampah'   => 'tidak terpakai',
        ];

        $mappingConfig = [
            [
                'header'       => 'Nama Lengkap',
                'action'       => 'map_existing',
                'target_field' => 'fullname',
            ],
            [
                'header'       => 'Email Akun',
                'action'       => 'map_existing',
                'target_field' => 'email',
            ],
            [
                'header'          => 'Catatan Khusus',
                'action'          => 'historical_only',
                'field_key'       => 'hist_catatan_khusus_abc1',
                'canonical_label' => 'Catatan Khusus',
                'data_type'       => 'text',
            ],
            [
                'header' => 'Kolom Sampah',
                'action' => 'ignore',
            ],
        ];

        $mapped = $mapMethod->invoke($service, $rawRow, $mappingConfig);

        $this->assertSame('Rina Astuti', $mapped['fullname']);
        $this->assertSame('rina@domain.ac.id', $mapped['email']);
        $this->assertArrayNotHasKey('Kolom Sampah', $mapped);
        $this->assertArrayHasKey('custom_answers', $mapped);
        $this->assertSame('Pernah ikut bootcamp AI 2023', $mapped['custom_answers']['hist_catatan_khusus_abc1']);
    }

    /**
     * Skenario 30: Verifikasi kontrak method inspectTarget dan activateAuthorizedTarget pada GoogleAppsScriptTransport.
     */
    public function test_google_apps_script_transport_inspect_and_activate_payload_contracts(): void
    {
        $transport = new \App\Services\SpreadsheetTransport\GoogleAppsScriptTransport([
            'enabled' => true,
            'endpoint_url' => 'https://script.google.com/macros/s/AKfycbtest/exec',
            'shared_secret' => 'test-secret-key-1234567890',
        ]);

        $this->assertTrue(method_exists($transport, 'inspectTarget'));
        $this->assertTrue(method_exists($transport, 'activateAuthorizedTarget'));
    }

    /**
     * Skenario 31: Verifikasi kecocokan konfigurasi autentikasi dual secret dan perlindungan read-only pada Apps Script.
     */
    public function test_apps_script_source_code_contract_compliance(): void
    {
        $filePath = __DIR__ . '/../../resources/apps-script/SertifGoogleIntegration.js';
        $gasCode = file_get_contents($filePath);

        // 1. Dual secret support
        $this->assertStringContainsString('SHARED_SECRET', $gasCode, 'Apps Script harus mendukung konfigurasi SHARED_SECRET');
        $this->assertStringContainsString('SHARED_API_KEY', $gasCode, 'Apps Script harus mendukung fallback SHARED_API_KEY');

        // 2. Read-only guard for legacy sources
        $this->assertStringContainsString('LEGACY_SOURCE_READONLY', $gasCode, 'Apps Script harus menolak penulisan pada LEGACY_SOURCES dengan kode LEGACY_SOURCE_READONLY');

        // 3. Dynamic target activation
        $this->assertStringContainsString('handleActivateAuthorizedTargetAction', $gasCode, 'Apps Script harus menyediakan handler aktivasi target dinamis');
        $this->assertStringContainsString('handleInspectTargetAction', $gasCode, 'Apps Script harus menyediakan handler inspeksi target');

        // 4. Drive root exemption from subtree check
        $this->assertStringContainsString('permission !== "drive_root"', $gasCode, 'Drive root target harus dikecualikan dari pemeriksaan hierarki folder subtree');

        // 5. Legacy permanent read-only guardrail (HOTFIX 3: promotion is prohibited fail-closed)
        $this->assertStringContainsString('LEGACY_SOURCE_READONLY', $gasCode, 'Apps Script harus menolak promosi legacy source menjadi target tulis dengan LEGACY_SOURCE_READONLY');

        // 6. Unauthorized target rejection
        $this->assertStringContainsString('TARGET_NOT_AUTHORIZED', $gasCode, 'Apps Script harus menolak penulisan pada target yang belum diotorisasi');
    }

    /**
     * Skenario 32: Pemisahan fungsi inspectTarget dan activateAuthorizedTarget.
     */
    public function test_separation_of_inspect_and_activate_target(): void
    {
        $transport = new \App\Services\SpreadsheetTransport\MockSpreadsheetTransport();

        $conn = new \App\Models\SpreadsheetConnection([
            'connection_key'           => 'registration',
            'spreadsheet_id'           => '1lY3UhhViRCOstvZZeJtEEW2Yol8eFIIFgkCqt3haQSU',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AKfycbtest/exec',
        ]);

        // inspectTarget can be called independently without activating
        $inspectRes = $transport->inspectTarget($conn, 'spreadsheet', $conn->spreadsheet_id, 'registration');
        $this->assertTrue($inspectRes->isSuccess());

        // activateAuthorizedTarget explicitly requires separate call with optional confirmPromoteLegacy
        $activateRes = $transport->activateAuthorizedTarget($conn, 'spreadsheet', $conn->spreadsheet_id, 'registration', true);
        $this->assertTrue($activateRes->isSuccess());
    }

    /**
     * Skenario 33: Perlindungan snapshot target job sinkronisasi terhadap perpindahan Spreadsheet.
     */
    public function test_job_target_snapshot_divergence_protection(): void
    {
        $job = new \App\Jobs\ProcessSpreadsheetRowSyncJob(
            rowSyncId: 42,
            fenceToken: 'fence-123',
            snapshotSpreadsheetId: 'original-sheet-2026',
            snapshotTabName: '2026',
            snapshotSchemaVersion: 'v1.3',
            snapshotConnectionId: 1
        );

        $this->assertSame('original-sheet-2026', $job->snapshotSpreadsheetId);
        $this->assertSame('2026', $job->snapshotTabName);
        $this->assertSame('v1.3', $job->snapshotSchemaVersion);
        $this->assertSame(1, $job->snapshotConnectionId);

        $sidecarJob = new \App\Jobs\ProcessSidecarSyncJob(
            sidecarSyncId: 99,
            fenceToken: 'fence-456',
            snapshotSpreadsheetId: 'sidecar-sheet-2026',
            snapshotSheetName: '2026_Custom_Fields'
        );

        $this->assertSame('sidecar-sheet-2026', $sidecarJob->snapshotSpreadsheetId);
        $this->assertSame('2026_Custom_Fields', $sidecarJob->snapshotSheetName);
    }

    /**
     * Skenario 34: Root Drive yang belum terverifikasi tidak boleh fallback diam-diam ke folder lama (Fail-Closed).
     */
    public function test_unverified_drive_root_fails_closed_without_silent_fallback(): void
    {
        $setting = new CloudStorageSetting([
            'canonical_parent_folder_id' => 'new-unverified-folder-999',
            'is_verified'                => false,
            'connection_status'          => CloudStorageSetting::STATUS_UNTESTED,
        ]);

        // Belum terverifikasi -> WAJIB return null, TIDAK BOLEH fallback diam-diam ke env atau folder lama!
        $this->assertNull(
            $setting->getEffectiveParentFolderId(),
            'Folder root yang belum diverifikasi harus return null (fail-closed) dan tidak fallback ke folder lama.'
        );

        // Setelah diverifikasi resmi oleh Admin -> Baru boleh digunakan
        $setting->is_verified = true;
        $setting->connection_status = CloudStorageSetting::STATUS_CONNECTED;

        $this->assertSame(
            'new-unverified-folder-999',
            $setting->getEffectiveParentFolderId(),
            'Folder root yang sudah diverifikasi resmi harus dikembalikan sebagai root aktif.'
        );
    }

    /**
     * Skenario 35: Status paused_target_rebind dan helper isPausedForTargetRebind (HOTFIX 1).
     */
    public function test_paused_target_rebind_status_and_helpers(): void
    {
        $this->assertSame('paused_target_rebind', \App\Models\SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND);
        $this->assertSame('paused_target_rebind', \App\Models\SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND);

        $rowSync = new \App\Models\SpreadsheetRowSync([
            'sync_status' => \App\Models\SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
        ]);
        $this->assertTrue($rowSync->isPausedForTargetRebind());

        $rowSync->sync_status = \App\Models\SpreadsheetRowSync::STATUS_PENDING;
        $this->assertFalse($rowSync->isPausedForTargetRebind());

        $sidecarSync = new \App\Models\SpreadsheetSidecarSync([
            'sync_status' => \App\Models\SpreadsheetSidecarSync::STATUS_PAUSED_TARGET_REBIND,
        ]);
        $this->assertTrue($sidecarSync->isPausedForTargetRebind());
    }

    /**
     * Skenario 36: Deteksi pergantian target Spreadsheet pada model SpreadsheetConnection (HOTFIX 1 & 2).
     */
    public function test_spreadsheet_connection_target_change_detection(): void
    {
        $conn = new \App\Models\SpreadsheetConnection([
            'spreadsheet_id'        => 'sheet-target-b',
            'active_spreadsheet_id' => 'sheet-target-a',
        ]);

        $this->assertTrue($conn->hasTargetChanged(), 'Koneksi harus mendeteksi perubahan jika spreadsheet_id berbeda dengan active_spreadsheet_id.');
        $this->assertFalse($conn->isTargetActive(), 'Target belum boleh dianggap aktif jika berbeda dengan active_spreadsheet_id.');

        $conn->active_spreadsheet_id = 'sheet-target-b';
        $this->assertFalse($conn->hasTargetChanged(), 'Koneksi tidak boleh mendeteksi perubahan jika spreadsheet_id sama dengan active_spreadsheet_id.');
        $this->assertTrue($conn->isTargetActive(), 'Target harus dianggap aktif jika spreadsheet_id sama dengan active_spreadsheet_id.');
    }

    /**
     * Skenario 37: Rekonsiliasi antrean dengan rebind_new_target dan remote deduplication check (HOTFIX 1).
     */
    public function test_reconciliation_rebind_with_remote_deduplication(): void
    {
        $transport = new MockSpreadsheetTransport();
        $reconciliationService = new \App\Services\SpreadsheetQueueReconciliationService($transport);

        $conn = new \App\Models\SpreadsheetConnection([
            'connection_key'           => 'registration',
            'spreadsheet_id'           => 'new-sheet-2026',
            'active_spreadsheet_id'    => 'new-sheet-2026',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AKfycbtest/exec',
        ]);
        $conn->id = 1;

        // Siapkan record row sync yang dibekukan
        $rowSync = new \App\Models\SpreadsheetRowSync([
            'spreadsheet_connection_id' => 1,
            'external_sync_id'          => 'REG-101-abcdef',
            'target_sheet_tab'          => '2026',
            'sync_status'               => \App\Models\SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
        ]);
        $rowSync->id = 55;
        $rowSync->setRelation('spreadsheetConnection', $conn);

        // Simulasi A: Baris SUDAH ADA di Google Sheets baru (baris 2)
        $transport->remoteSheets['2026']['REG-101-abcdef'] = ['Nama' => 'Kandidat A'];

        $resultA = $reconciliationService->reconcileRowSync($rowSync, 'rebind_new_target');

        $this->assertTrue($resultA['success']);
        $this->assertSame('deduplicated_synced', $resultA['action']);
        $this->assertSame(\App\Models\SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertSame(2, $rowSync->row_index);
        $this->assertSame('new-sheet-2026', $rowSync->target_spreadsheet_id);

        // Simulasi B: Baris BELUM ADA di Google Sheets baru
        $rowSyncB = new \App\Models\SpreadsheetRowSync([
            'spreadsheet_connection_id' => 1,
            'external_sync_id'          => 'REG-102-ghijkl',
            'target_sheet_tab'          => '2026',
            'sync_status'               => \App\Models\SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
        ]);
        $rowSyncB->id = 56;
        $rowSyncB->setRelation('spreadsheetConnection', $conn);

        $resultB = $reconciliationService->reconcileRowSync($rowSyncB, 'rebind_new_target');

        $this->assertTrue($resultB['success']);
        $this->assertSame('rebound_queued', $resultB['action']);
        $this->assertSame(\App\Models\SpreadsheetRowSync::STATUS_PENDING, $rowSyncB->sync_status);
        $this->assertSame('new-sheet-2026', $rowSyncB->target_spreadsheet_id);
    }

    /**
     * Skenario 38: Rekonsiliasi antrean dengan cancel_sync mempertahankan data pendaftar (HOTFIX 1).
     */
    public function test_reconciliation_cancel_sync_preserves_registration(): void
    {
        $transport = new MockSpreadsheetTransport();
        $reconciliationService = new \App\Services\SpreadsheetQueueReconciliationService($transport);

        $conn = new \App\Models\SpreadsheetConnection([
            'connection_key' => 'registration',
            'spreadsheet_id' => 'target-sheet-x',
        ]);
        $conn->id = 1;

        $rowSync = new \App\Models\SpreadsheetRowSync([
            'spreadsheet_connection_id' => 1,
            'external_sync_id'          => 'REG-200-cancelme',
            'target_sheet_tab'          => '2026',
            'sync_status'               => \App\Models\SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
        ]);
        $rowSync->id = 77;
        $rowSync->setRelation('spreadsheetConnection', $conn);

        $result = $reconciliationService->reconcileRowSync($rowSync, 'cancel_sync');

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $result['action']);
        $this->assertSame(\App\Models\SpreadsheetRowSync::STATUS_FAILED, $rowSync->sync_status);
        $this->assertStringContainsString('dibatalkan oleh Admin', $rowSync->error_message);
    }

    /**
     * Skenario 39: Retry manual ditolak jika antrean sedang dalam status paused_target_rebind (Fail-Closed).
     */
    public function test_manual_retry_rejected_when_paused_for_rebind(): void
    {
        $syncEngine = new \App\Services\SpreadsheetSyncEngineService();

        $rowSync = new \App\Models\SpreadsheetRowSync([
            'external_sync_id' => 'REG-999-paused',
            'sync_status'      => \App\Models\SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('dibekukan');

        $syncEngine->retrySyncRecord($rowSync);
    }

    /**
     * Skenario 40: Target legacy tetap read-only permanen dan tidak dapat dipromosikan (HOTFIX 3).
     */
    public function test_legacy_spreadsheet_permanently_read_only(): void
    {
        $transport = new MockSpreadsheetTransport();

        $conn = new \App\Models\SpreadsheetConnection([
            'connection_key'           => 'registration',
            'spreadsheet_id'           => 'legacy-source-sheet-id-2024',
            'apps_script_endpoint_url' => 'https://script.google.com/macros/s/AKfycbtest/exec',
        ]);

        // Simulasikan forced failure dari gateway Apps Script untuk target legacy
        $transport->forcedResponse = SpreadsheetTransportResponse::failure(
            errorMessage: 'Target adalah sumber legacy arsip dan bersifat permanen read-only.',
            errorCode: 'LEGACY_SOURCE_READONLY',
            isRetryable: false,
            context: ['status_code' => 403]
        );

        $res = $transport->activateAuthorizedTarget($conn, 'spreadsheet', $conn->spreadsheet_id, 'registration', confirmPromoteLegacy: true);

        $this->assertFalse($res->isSuccess());
        $this->assertSame('LEGACY_SOURCE_READONLY', $res->errorCode);
    }
}
