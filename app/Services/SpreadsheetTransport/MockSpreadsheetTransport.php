<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\SpreadsheetConnection;

class MockSpreadsheetTransport implements SpreadsheetSidecarTransportInterface
{
    /**
     * In-memory simulated remote spreadsheet tabs: [ tabName => [ syncId => rowData ] ]
     */
    public array $remoteSheets = [];

    /**
     * Execution history: list of calls made
     */
    public array $callHistory = [];

    /**
     * Simulated behavior flags
     */
    public ?SpreadsheetTransportResponse $forcedResponse = null;
    public ?SpreadsheetTransportResponse $forcedEnsureResponse = null;
    public bool $simulateTimeoutAfterRemoteSuccess = false;
    public int $callCount = 0;

    public function sendRowUpsert(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse {
        $this->callCount++;
        $this->callHistory[] = [
            'action'  => 'upsert_row',
            'tab'     => $tabName,
            'sync_id' => $syncId,
            'payload' => $payload,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        // Simulate remote atomic upsert
        if (!isset($this->remoteSheets[$tabName])) {
            $this->remoteSheets[$tabName] = [];
        }

        // Overwrite or insert by syncId
        $this->remoteSheets[$tabName][$syncId] = $payload;
        $rowIndex = array_search($syncId, array_keys($this->remoteSheets[$tabName]), true) + 2; // 1-based + 1 header row

        if ($this->simulateTimeoutAfterRemoteSuccess) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received',
                errorCode: 'HTTP_TIMEOUT',
                isRetryable: true
            );
        }

        return SpreadsheetTransportResponse::success(
            rowIndex: $rowIndex,
            context: ['remote_rows_count' => count($this->remoteSheets[$tabName])]
        );
    }

    public function sendRowUpdateOnly(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse {
        $this->callCount++;
        $this->callHistory[] = [
            'action'  => 'update_row',
            'tab'     => $tabName,
            'sync_id' => $syncId,
            'payload' => $payload,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        if (!isset($this->remoteSheets[$tabName])) {
            $this->remoteSheets[$tabName] = [];
        }

        if (!array_key_exists($syncId, $this->remoteSheets[$tabName])) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: "Baris dengan SERTIF_SYNC_ID '{$syncId}' tidak ditemukan pada tab sheet. Operasi update-only menolak penambahan baris baru.",
                errorCode: 'ROW_NOT_FOUND',
                isRetryable: false
            );
        }

        if ($this->simulateTimeoutAfterRemoteSuccess) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received',
                errorCode: 'HTTP_TIMEOUT',
                isRetryable: true
            );
        }

        $this->remoteSheets[$tabName][$syncId] = $payload;
        $rowIndex = array_search($syncId, array_keys($this->remoteSheets[$tabName]), true) + 2;

        return SpreadsheetTransportResponse::success(
            rowIndex: $rowIndex,
            context: [
                'action'    => 'update_row',
                'operation' => 'updated',
                'remote_rows_count' => count($this->remoteSheets[$tabName])
            ]
        );
    }

    public function lookupRow(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId
    ): SpreadsheetTransportResponse {
        $this->callCount++;
        $this->callHistory[] = [
            'action'  => 'lookup_row',
            'tab'     => $tabName,
            'sync_id' => $syncId,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        if (isset($this->remoteSheets[$tabName][$syncId])) {
            $rowIndex = array_search($syncId, array_keys($this->remoteSheets[$tabName]), true) + 2;
            return SpreadsheetTransportResponse::ok(
                rowIndex: $rowIndex,
                context: ['found' => true, 'sync_id' => $syncId]
            );
        }

        return SpreadsheetTransportResponse::ok(
            rowIndex: null,
            context: ['found' => false, 'sync_id' => $syncId]
        );
    }

    public function ensureExtensionColumns(
        SpreadsheetConnection $connection,
        string $tabName,
        array $columns
    ): SpreadsheetTransportResponse {
        $this->callCount++;
        $this->callHistory[] = [
            'action'  => 'ensure_extension_columns',
            'tab'     => $tabName,
            'columns' => $columns,
        ];

        if ($this->forcedEnsureResponse !== null) {
            return $this->forcedEnsureResponse;
        }

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        if (!isset($this->remoteHeaders[$tabName])) {
            $this->remoteHeaders[$tabName] = ['SERTIF_SYNC_ID', 'SERTIF_REGISTRATION_ID', 'FORM_SCHEMA_VERSION', 'SERTIF_LAST_SYNC_AT'];
        }

        foreach ($columns as $col) {
            if (!in_array($col, $this->remoteHeaders[$tabName], true)) {
                $this->remoteHeaders[$tabName][] = $col;
            }
        }

        return SpreadsheetTransportResponse::success(
            rowIndex: 1,
            context: ['columns' => $this->remoteHeaders[$tabName]]
        );
    }

    public function upsertExtensionRow(
        SpreadsheetConnection $connection,
        string $mainTabName,
        string $extensionTabName,
        string $syncId,
        int|string $registrationId,
        string $formSchemaVersion,
        string $lastSyncAt,
        array $answers
    ): SpreadsheetTransportResponse {
        $this->callCount++;
        $this->callHistory[] = [
            'action'             => 'upsert_extension_row',
            'main_tab'           => $mainTabName,
            'extension_tab'      => $extensionTabName,
            'sync_id'            => $syncId,
            'registration_id'    => (string) $registrationId,
            'form_schema_version'=> $formSchemaVersion,
            'last_sync_at'       => $lastSyncAt,
            'answers'            => $answers,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        // 1. Verifikasi remote keberadaan baris utama
        if (!isset($this->remoteSheets[$mainTabName]) || !isset($this->remoteSheets[$mainTabName][$syncId])) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: "Baris utama dengan Sync ID '{$syncId}' tidak ditemukan di tab {$mainTabName}. Menolak pembuatan baris sidecar.",
                errorCode: 'MAIN_ROW_NOT_FOUND',
                isRetryable: false
            );
        }

        // 2. Verifikasi kesesuaian Registration ID
        $mainRow = $this->remoteSheets[$mainTabName][$syncId];
        $mainRegId = (string) ($mainRow['SERTIF_REGISTRATION_ID'] ?? $mainRow['sertif_registration_id'] ?? $mainRow['registration_id'] ?? '');
        if ($mainRegId === '') {
            return SpreadsheetTransportResponse::failure(
                errorMessage: "Kolom SERTIF_REGISTRATION_ID pada baris utama kosong. Identitas baris utama tidak lengkap.",
                errorCode: 'REMOTE_IDENTITY_INCOMPLETE',
                isRetryable: false
            );
        }
        if ($mainRegId !== (string) $registrationId) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: "Registration ID pada baris utama ({$mainRegId}) tidak cocok dengan sidecar payload (" . (string)$registrationId . ").",
                errorCode: 'IDENTITY_MISMATCH',
                isRetryable: false
            );
        }

        // 3. Upsert pada tab ekstensi
        if (!isset($this->remoteSheets[$extensionTabName])) {
            $this->remoteSheets[$extensionTabName] = [];
        }

        $isUpdate = isset($this->remoteSheets[$extensionTabName][$syncId]);
        $existing = $this->remoteSheets[$extensionTabName][$syncId] ?? [];

        $merged = array_merge($existing, [
            'SERTIF_SYNC_ID'         => $syncId,
            'SERTIF_REGISTRATION_ID' => (string) $registrationId,
            'FORM_SCHEMA_VERSION'    => $formSchemaVersion,
            'SERTIF_LAST_SYNC_AT'    => $lastSyncAt,
        ], $answers);

        $this->remoteSheets[$extensionTabName][$syncId] = $merged;
        $rowIndex = array_search($syncId, array_keys($this->remoteSheets[$extensionTabName]), true) + 2;

        if ($this->simulateTimeoutAfterRemoteSuccess) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received',
                errorCode: 'HTTP_TIMEOUT',
                isRetryable: true
            );
        }

        return SpreadsheetTransportResponse::success(
            rowIndex: $rowIndex,
            context: [
                'action'    => $isUpdate ? 'updated' : 'inserted',
                'sync_id'   => $syncId,
                'row_index' => $rowIndex,
            ]
        );
    }

    public function inspectSidecarAlignment(
        SpreadsheetConnection $connection,
        string $mainTabName,
        string $extensionTabName,
        ?string $syncId = null
    ): SpreadsheetTransportResponse {
        $this->callCount++;
        $this->callHistory[] = [
            'action'        => 'inspect_sidecar_alignment',
            'main_tab'      => $mainTabName,
            'extension_tab' => $extensionTabName,
            'sync_id'       => $syncId,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        $mainExists = isset($this->remoteSheets[$mainTabName][$syncId]);
        $sidecarExists = isset($this->remoteSheets[$extensionTabName][$syncId]);

        if ($mainExists && $sidecarExists) {
            return SpreadsheetTransportResponse::success(
                rowIndex: 2,
                context: ['status' => 'aligned', 'sync_id' => $syncId]
            );
        } elseif (!$mainExists && $sidecarExists) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: "Orphaned sidecar row found without main row for Sync ID '{$syncId}'.",
                errorCode: 'ORPHANED_SIDECAR',
                isRetryable: false
            );
        } elseif ($mainExists && !$sidecarExists) {
            return SpreadsheetTransportResponse::success(
                rowIndex: 0,
                context: ['status' => 'missing_sidecar', 'sync_id' => $syncId]
            );
        }

        return SpreadsheetTransportResponse::failure(
            errorMessage: "Neither main nor sidecar row exists for Sync ID '{$syncId}'.",
            errorCode: 'ROW_NOT_FOUND',
            isRetryable: false
        );
    }

    public function testConnection(SpreadsheetConnection $connection): bool
    {
        return $connection->isConfigured();
    }

    public function getRemoteRowCount(string $tabName): int
    {
        return isset($this->remoteSheets[$tabName]) ? count($this->remoteSheets[$tabName]) : 0;
    }

    public function getRemoteRow(string $tabName, string $syncId): ?array
    {
        return $this->remoteSheets[$tabName][$syncId] ?? null;
    }

    public function setRemoteRow(string $tabName, string $syncId, array $payload): void
    {
        if (!isset($this->remoteSheets[$tabName])) {
            $this->remoteSheets[$tabName] = [];
        }
        $this->remoteSheets[$tabName][$syncId] = $payload;
    }

    public array $remoteHeaders = [];

    public function reset(): void
    {
        $this->remoteSheets = [];
        $this->remoteHeaders = [];
        $this->callHistory = [];
        $this->forcedResponse = null;
        $this->simulateTimeoutAfterRemoteSuccess = false;
        $this->callCount = 0;
    }
}
