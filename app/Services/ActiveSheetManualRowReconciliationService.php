<?php

namespace App\Services;

use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ActiveSheetManualRowReconciliationService
{
    public function __construct(
        protected GoogleAppsScriptTransport $transport,
        protected RegistrationSpreadsheetMappingService $mappingService
    ) {}

    /**
     * Memindai halaman baris pada tab aktif Google Sheets untuk menemukan baris tanpa SERTIF_SYNC_ID.
     *
     * @return array{total_scanned: int, unsynced_rows: array<int, array<string, mixed>>}
     */
    public function scanUnsyncedRows(SpreadsheetConnection $connection, string $tabName, int $page = 1, int $pageSize = 50): array
    {
        $response = $this->transport->readRowsPage(
            connection: $connection,
            tabName: $tabName,
            headerRow: 1,
            page: $page,
            pageSize: $pageSize,
            maskPii: false
        );

        if (!$response->success) {
            throw new DomainException("Gagal memindai baris spreadsheet: " . $response->errorMessage);
        }

        $rows = $response->context['rows'] ?? [];
        $unsyncedRows = [];

        foreach ($rows as $row) {
            $rowIndex = $row['row_index'] ?? null;
            $data = $row['data'] ?? [];

            // Periksa apakah kolom SERTIF_SYNC_ID kosong atau '-'
            $syncId = $data['SERTIF_SYNC_ID'] ?? $data['sertif_sync_id'] ?? null;
            $isUnsynced = empty($syncId) || trim((string) $syncId) === '' || trim((string) $syncId) === '-';

            if ($isUnsynced && $rowIndex !== null) {
                // Hitung content checksum untuk mencegah pergeseran baris saat rekonsiliasi
                $checksum = $this->computeRowContentChecksum($data);
                $unsyncedRows[] = [
                    'row_index'        => (int) $rowIndex,
                    'data'             => $data,
                    'content_checksum' => $checksum,
                    'matched_local_id' => $this->findMatchingLocalRegistrationId($data),
                ];
            }
        }

        return [
            'total_scanned' => count($rows),
            'unsynced_rows' => $unsyncedRows,
        ];
    }

    /**
     * Memasangkan SERTIF_SYNC_ID secara atomik pada baris spreadsheet tanpa append/insert baris baru.
     */
    public function reconcileRowSyncId(
        SpreadsheetConnection $connection,
        string $tabName,
        int $rowIndex,
        ?string $existingSyncId = null,
        ?int $registrationId = null,
        ?string $expectedChecksum = null
    ): array {
        $syncId = $existingSyncId ?: (string) Str::uuid();

        // 1. Panggil attach_row_sync_id ke Apps Script
        $response = $this->transport->attachRowSyncId(
            connection: $connection,
            tabName: $tabName,
            rowIndex: $rowIndex,
            syncId: $syncId,
            registrationId: $registrationId ? (string) $registrationId : null,
            expectedChecksum: $expectedChecksum
        );

        if (!$response->success) {
            Log::warning("Reconciliation failed for row {$rowIndex} on tab {$tabName}: " . $response->errorMessage);
            return [
                'success'    => false,
                'row_index'  => $rowIndex,
                'error_code' => $response->errorCode,
                'message'    => $response->errorMessage,
            ];
        }

        // 2. Jika ada registrasi lokal terkait, catat atau perbarui SpreadsheetRowSync
        if ($registrationId) {
            SpreadsheetRowSync::updateOrCreate(
                [
                    'connection_key' => $connection->connection_key,
                    'syncable_type'  => InternshipRegistration::class,
                    'syncable_id'    => $registrationId,
                ],
                [
                    'external_sync_id' => $syncId,
                    'target_sheet_tab' => $tabName,
                    'sync_channel'     => 'manual_reconciliation',
                    'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
                    'last_synced_at'   => now(),
                ]
            );
        }

        return [
            'success'   => true,
            'row_index' => $rowIndex,
            'sync_id'   => $syncId,
            'message'   => "SERTIF_SYNC_ID berhasil direkonsiliasi pada baris {$rowIndex}.",
        ];
    }

    /**
     * Cari ID pendaftaran lokal yang cocok berdasarkan Email, NIM, atau Nama & No HP.
     */
    protected function findMatchingLocalRegistrationId(array $rowData): ?int
    {
        $email = null;
        $studentId = null;
        $fullname = null;
        $phone = null;

        foreach ($rowData as $k => $v) {
            $cleanK = strtolower(trim((string) $k));
            if (str_contains($cleanK, 'email') && !empty($v)) {
                $email = strtolower(trim((string) $v));
            } elseif ((str_contains($cleanK, 'nim') || str_contains($cleanK, 'nis')) && !empty($v)) {
                $studentId = trim((string) $v);
            } elseif (str_contains($cleanK, 'nama lengkap') && !empty($v)) {
                $fullname = trim((string) $v);
            } elseif (str_contains($cleanK, 'no. hp') || str_contains($cleanK, 'whatsapp')) {
                $phone = preg_replace('/\D/', '', (string) $v);
            }
        }

        if (!empty($email)) {
            $reg = InternshipRegistration::where('email', $email)->latest('id')->first();
            if ($reg) return $reg->id;
        }

        if (!empty($studentId)) {
            $reg = InternshipRegistration::where('student_id', $studentId)->latest('id')->first();
            if ($reg) return $reg->id;
        }

        if (!empty($fullname) && !empty($phone)) {
            $reg = InternshipRegistration::where('fullname', $fullname)
                ->where('phone_number', 'like', "%{$phone}%")
                ->latest('id')
                ->first();
            if ($reg) return $reg->id;
        }

        return null;
    }

    /**
     * Hitung checksum isi baris data mentah (mengabaikan kolom sistem).
     */
    protected function computeRowContentChecksum(array $rowData): string
    {
        $filtered = [];
        foreach ($rowData as $k => $v) {
            $cleanK = strtoupper(trim((string) $k));
            if (!in_array($cleanK, ['SERTIF_SYNC_ID', 'SERTIF_REGISTRATION_ID', 'SERTIF_SYNC_STATUS', 'SERTIF_LAST_SYNC_AT'], true)) {
                $filtered[$cleanK] = trim((string) ($v ?? ''));
            }
        }
        ksort($filtered);
        return hash('sha256', json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
