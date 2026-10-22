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
    public ?SpreadsheetTransportResponse $forcedInspectResponse = null;
    public ?SpreadsheetTransportResponse $forcedPreviewResponse = null;
    public ?\Throwable $forcedException = null;
    public bool $simulateTimeoutAfterRemoteSuccess = false;
    public int $callCount = 0;
    public ?array $allowlist = null;

    public function setAllowlist(array $allowlist): self
    {
        $this->allowlist = $allowlist;
        return $this;
    }

    public function checkAllowlist(SpreadsheetConnection $connection): ?SpreadsheetTransportResponse
    {
        if ($this->allowlist !== null) {
            if (empty($this->allowlist)) {
                return SpreadsheetTransportResponse::failure(
                    errorMessage: 'Tidak ada Spreadsheet ID yang terdaftar dalam allowlist script (SPREADSHEET_ALLOWLIST kosong).',
                    errorCode: 'SPREADSHEET_ALLOWLIST_EMPTY',
                    isRetryable: false
                );
            }

            if (!in_array($connection->spreadsheet_id, $this->allowlist, true)) {
                return SpreadsheetTransportResponse::failure(
                    errorMessage: "Spreadsheet ID ({$connection->spreadsheet_id}) tidak diizinkan (tidak terdaftar dalam allowlist script).",
                    errorCode: 'SPREADSHEET_NOT_ALLOWLISTED',
                    isRetryable: false
                );
            }
        }

        return null;
    }

    public function simulateSecurityCheck(array $params): array
    {
        if (!($params['hmac_valid'] ?? true)) {
            return ['success' => false, 'error_code' => 'INVALID_HMAC_SIGNATURE', 'message' => 'Tanda tangan HMAC tidak valid.'];
        }

        if (($params['nonce_used'] ?? false)) {
            return ['success' => false, 'error_code' => 'NONCE_REPLAY_DETECTED', 'message' => 'Nonce telah digunakan sebelumnya (replay attempt).'];
        }

        if (!($params['timestamp_valid'] ?? true)) {
            return ['success' => false, 'error_code' => 'TIMESTAMP_EXPIRED', 'message' => 'Timestamp request telah kedaluwarsa.'];
        }

        return ['success' => true];
    }

    public function setInspectSchemaResponse(SpreadsheetTransportResponse $response): self
    {
        $this->forcedInspectResponse = $response;
        return $this;
    }

    public function setPreviewRowsResponse(SpreadsheetTransportResponse $response): self
    {
        $this->forcedPreviewResponse = $response;
        return $this;
    }

    public function setForceException(\Throwable $exception): self
    {
        $this->forcedException = $exception;
        return $this;
    }

    public function syncSheetSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        array $headers
    ): SpreadsheetTransportResponse {
        $allowlistCheck = $this->checkAllowlist($connection);
        if ($allowlistCheck !== null) {
            return $allowlistCheck;
        }

        if ($this->forcedException !== null) {
            throw $this->forcedException;
        }

        $this->callCount++;
        $this->callHistory[] = [
            'action'  => 'sync_sheet_schema',
            'tab'     => $tabName,
            'headers' => $headers,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        $this->remoteHeaders[$tabName] = $headers;

        return SpreadsheetTransportResponse::ok(context: [
            'success' => true,
            'tab'     => $tabName,
            'headers' => $headers,
        ]);
    }

    public function sendRowUpsert(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse {
        $allowlistCheck = $this->checkAllowlist($connection);
        if ($allowlistCheck !== null) {
            return $allowlistCheck;
        }

        if ($this->forcedException !== null) {
            throw $this->forcedException;
        }

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

    public function setMockHeaders(string $tabName, array $headers): void
    {
        $this->remoteHeaders[$tabName] = $headers;
    }

    public function setMockRow(string $tabName, string $syncId, array $payload): void
    {
        $this->setRemoteRow($tabName, $syncId, $payload);
    }

    public function setForcedPreviewResponse(?SpreadsheetTransportResponse $res): void
    {
        $this->forcedPreviewResponse = $res;
    }

    /**
     * Configurable mock tabs list
     */
    public ?array $mockTabs = null;

    public function listTabs(SpreadsheetConnection $connection): SpreadsheetTransportResponse
    {
        $allowlistCheck = $this->checkAllowlist($connection);
        if ($allowlistCheck !== null) {
            return $allowlistCheck;
        }

        $this->callCount++;
        $this->callHistory[] = [
            'action' => 'list_tabs',
            'connection_key' => $connection->connection_key,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        if ($this->mockTabs !== null) {
            return SpreadsheetTransportResponse::ok(context: [
                'success' => true,
                'spreadsheet_id' => $connection->spreadsheet_id,
                'tabs_count' => count($this->mockTabs),
                'tabs' => $this->mockTabs,
            ]);
        }

        $sheetNames = !empty($this->remoteSheets) ? array_keys($this->remoteSheets) : ($connection->connection_key === SpreadsheetConnection::KEY_FOLLOWUP ? ['Follow-Up Guru/Dosen'] : ['2026', '2025', '2026_Custom_Fields']);
        $tabs = [];
        foreach ($sheetNames as $idx => $name) {
            $isYearlyOrTemplate = preg_match('/^\d{4}$/', (string) $name) || (string) $name === 'TEMPLATE_TAHUN';
            $headerRow = $isYearlyOrTemplate ? 12 : 1;
            $tabs[] = [
                'id' => $idx,
                'name' => (string) $name,
                'index' => $idx,
                'row_count' => 1000,
                'column_count' => 40,
                'last_row' => $headerRow + count($this->remoteSheets[$name] ?? []),
                'last_column' => 40,
                'is_hidden' => false,
                'header_row' => $headerRow,
            ];
        }

        return SpreadsheetTransportResponse::ok(context: [
            'success' => true,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'tabs_count' => count($tabs),
            'tabs' => $tabs,
        ]);
    }

    public function inspectSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null
    ): SpreadsheetTransportResponse {
        $allowlistCheck = $this->checkAllowlist($connection);
        if ($allowlistCheck !== null) {
            return $allowlistCheck;
        }

        if ($this->forcedException !== null) {
            throw $this->forcedException;
        }

        $this->callCount++;
        $this->callHistory[] = [
            'action' => 'inspect_schema',
            'tab' => $tabName,
            'header_row' => $headerRow,
        ];

        if ($this->forcedInspectResponse !== null) {
            return $this->forcedInspectResponse;
        }

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        $isYearlyOrTemplate = preg_match('/^\d{4}$/', $tabName) || $tabName === 'TEMPLATE_TAHUN';
        $effectiveHeaderRow = $headerRow ?? ($isYearlyOrTemplate ? 12 : 1);

        $headers = $this->remoteHeaders[$tabName] ?? [];
        if (empty($headers)) {
            if (!empty($this->remoteSheets[$tabName])) {
                $firstRow = reset($this->remoteSheets[$tabName]);
                $headers = array_keys($firstRow);
            } else {
                $headers = ['Nama Lengkap', 'Email', 'No WhatsApp', 'Asal Sekolah/Universitas', 'Jurusan'];
            }
        }

        $columns = [];
        foreach ($headers as $cIdx => $hName) {
            $colLetter = $this->mockIndexToLetter($cIdx + 1);
            $samples = [];
            if (!empty($this->remoteSheets[$tabName])) {
                $sampleCount = 0;
                foreach ($this->remoteSheets[$tabName] as $row) {
                    if (isset($row[$hName]) && $row[$hName] !== '') {
                        $samples[] = $this->mockMaskPii((string) $row[$hName]);
                        $sampleCount++;
                        if ($sampleCount >= 3) break;
                    }
                }
            }
            $columns[] = [
                'col_index' => $cIdx + 1,
                'col_letter' => $colLetter,
                'header_name' => $hName,
                'inferred_type' => 'string',
                'has_formula' => false,
                'samples' => $samples,
                'is_empty_header' => empty(trim($hName)),
            ];
        }

        $hashParts = [];
        foreach ($columns as $c) {
            $hashParts[] = $c['col_letter'] . ':' . $c['header_name'];
        }
        $schemaHash = hash('sha256', implode('|', $hashParts));

        return SpreadsheetTransportResponse::ok(context: [
            'success' => true,
            'tab_name' => $tabName,
            'header_row' => $effectiveHeaderRow,
            'last_row' => $effectiveHeaderRow + count($this->remoteSheets[$tabName] ?? []),
            'last_column' => count($columns),
            'columns_count' => count($columns),
            'columns' => $columns,
            'schema_hash' => $schemaHash,
            'has_data' => !empty($this->remoteSheets[$tabName]),
        ]);
    }

    public function readRowsPage(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        int $page = 1,
        int $pageSize = 20,
        bool $maskPii = true
    ): SpreadsheetTransportResponse {
        $allowlistCheck = $this->checkAllowlist($connection);
        if ($allowlistCheck !== null) {
            return $allowlistCheck;
        }

        if ($this->forcedException !== null) {
            throw $this->forcedException;
        }

        $this->callCount++;
        $clampedPage = max(1, $page);
        $clampedSize = min(100, max(1, $pageSize));

        $this->callHistory[] = [
            'action' => 'read_rows_page',
            'tab' => $tabName,
            'header_row' => $headerRow,
            'page' => $clampedPage,
            'page_size' => $clampedSize,
        ];

        if ($this->forcedPreviewResponse !== null) {
            return $this->forcedPreviewResponse;
        }

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        $isYearlyOrTemplate = preg_match('/^\d{4}$/', $tabName) || $tabName === 'TEMPLATE_TAHUN';
        $effectiveHeaderRow = $headerRow ?? ($isYearlyOrTemplate ? 12 : 1);

        $allRows = array_values($this->remoteSheets[$tabName] ?? []);
        $totalRows = count($allRows);
        $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $clampedSize) : 0;

        $offset = ($clampedPage - 1) * $clampedSize;
        $slice = array_slice($allRows, $offset, $clampedSize);

        $mappedRows = [];
        foreach ($slice as $idx => $row) {
            $rowNum = $effectiveHeaderRow + 1 + $offset + $idx;
            $rowWithNum = ['_row_number' => $rowNum];
            foreach ($row as $k => $v) {
                $rowWithNum[$k] = $maskPii ? $this->mockMaskPii((string) $v) : (string) $v;
            }
            $mappedRows[] = $rowWithNum;
        }

        $hasMore = $clampedPage < $totalPages;

        return SpreadsheetTransportResponse::ok(context: [
            'success' => true,
            'tab_name' => $tabName,
            'header_row' => $effectiveHeaderRow,
            'page' => $clampedPage,
            'page_size' => $clampedSize,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'has_more' => $hasMore,
            'next_page' => $hasMore ? ($clampedPage + 1) : null,
            'rows' => $mappedRows,
        ]);
    }

    /**
     * Perform safe deletion of an unused and completely empty remote column in mock.
     */
    public function deleteEmptyColumn(
        SpreadsheetConnection $connection,
        int $sheetId,
        string $tabName,
        string $columnIdentity,
        string $expectedSchemaHash,
        ?string $idempotencyKey = null
    ): SpreadsheetTransportResponse {
        $this->callCount++;
        $this->callHistory[] = [
            'action' => 'delete_empty_column',
            'sheet_id' => $sheetId,
            'tab' => $tabName,
            'column_identity' => $columnIdentity,
            'expected_schema_hash' => $expectedSchemaHash,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($this->forcedResponse !== null) {
            return $this->forcedResponse;
        }

        // Schema drift check simulation
        $currentHeaders = $this->remoteHeaders[$tabName] ?? [];
        $hashParts = [];
        foreach ($currentHeaders as $idx => $hName) {
            $colLetter = $this->mockIndexToLetter($idx + 1);
            $hashParts[] = $colLetter . ':' . $hName;
        }
        $currentHash = hash('sha256', implode('|', $hashParts));
        if (!empty($expectedSchemaHash) && $expectedSchemaHash !== $currentHash) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: "Schema drift terdeteksi pada tab {$tabName}. Hash remote ({$currentHash}) tidak cocok dengan hash preview ({$expectedSchemaHash}).",
                errorCode: 'SCHEMA_CONFLICT',
                isRetryable: false,
                context: ['current_hash' => $currentHash, 'expected_hash' => $expectedSchemaHash]
            );
        }

        // Check if column exists
        $colIndex = array_search($columnIdentity, $currentHeaders, true);
        if ($colIndex === false) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: "Kolom {$columnIdentity} tidak ditemukan pada tab {$tabName}.",
                errorCode: 'COLUMN_NOT_FOUND',
                isRetryable: false
            );
        }

        // Check remote sheet rows for non-empty data
        if (isset($this->remoteSheets[$tabName])) {
            foreach ($this->remoteSheets[$tabName] as $rowSyncId => $row) {
                if (isset($row[$columnIdentity])) {
                    $val = trim((string) $row[$columnIdentity]);
                    if ($val !== '') {
                        return SpreadsheetTransportResponse::failure(
                            errorMessage: "Kolom {$columnIdentity} memiliki data pada baris dengan sync_id '{$rowSyncId}'. Penghapusan ditolak.",
                            errorCode: 'COLUMN_NOT_EMPTY',
                            isRetryable: false,
                            context: ['sync_id' => $rowSyncId, 'value' => $val]
                        );
                    }
                }
            }
        }

        // Remove column from headers
        array_splice($this->remoteHeaders[$tabName], $colIndex, 1);

        // Remove column from rows
        if (isset($this->remoteSheets[$tabName])) {
            foreach ($this->remoteSheets[$tabName] as $syncId => $row) {
                unset($this->remoteSheets[$tabName][$syncId][$columnIdentity]);
            }
        }

        $newHashParts = [];
        foreach ($this->remoteHeaders[$tabName] as $idx => $hName) {
            $colLetter = $this->mockIndexToLetter($idx + 1);
            $newHashParts[] = $colLetter . ':' . $hName;
        }
        $newHash = hash('sha256', implode('|', $newHashParts));

        return SpreadsheetTransportResponse::ok(context: [
            'success' => true,
            'deleted_column' => $columnIdentity,
            'tab_name' => $tabName,
            'remaining_columns' => count($this->remoteHeaders[$tabName]),
            'new_schema_hash' => $newHash,
        ]);
    }

    protected function mockIndexToLetter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $temp = ($col - 1) % 26;
            $letter = chr($temp + 65) . $letter;
            $col = (int) floor(($col - $temp - 1) / 26);
        }
        return $letter;
    }

    protected function mockMaskPii(string $str): string
    {
        $str = trim($str);
        if ($str === '') return '';
        if (filter_var($str, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $str);
            $local = $parts[0];
            $masked = strlen($local) > 2 ? $local[0] . '***' . substr($local, -1) : $local[0] . '***';
            return $masked . '@' . $parts[1];
        }
        $digits = preg_replace('/\D/', '', $str);
        if (strlen($digits) >= 10 && (str_starts_with($str, '08') || str_starts_with($str, '+62') || str_starts_with($str, '62') || strlen($digits) >= 11)) {
            return substr($digits, 0, 4) . '****' . substr($digits, -3);
        }
        if (preg_match('/^\d{6,}$/', $str)) {
            return substr($str, 0, 3) . '****' . substr($str, -3);
        }
        return $str;
    }

    public function reset(): void
    {
        $this->remoteSheets = [];
        $this->remoteHeaders = [];
        $this->mockTabs = null;
        $this->callHistory = [];
        $this->forcedResponse = null;
        $this->forcedInspectResponse = null;
        $this->forcedPreviewResponse = null;
        $this->forcedException = null;
        $this->simulateTimeoutAfterRemoteSuccess = false;
        $this->callCount = 0;
    }
}
