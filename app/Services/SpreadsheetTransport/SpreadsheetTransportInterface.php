<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\SpreadsheetConnection;

interface SpreadsheetTransportInterface
{
    /**
     * Perform an idempotent remote row upsert based on stable syncId.
     *
     * @param SpreadsheetConnection $connection
     * @param string $tabName
     * @param string $syncId Permanent canonical SERTIF_SYNC_ID
     * @param array<string, mixed> $payload Exact column key-value pairs
     * @return SpreadsheetTransportResponse
     */
    public function sendRowUpsert(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse;

    /**
     * Perform an idempotent remote row update only based on stable syncId.
     * Strictly fails without row insertion if the row does not already exist.
     *
     * @param SpreadsheetConnection $connection
     * @param string $tabName
     * @param string $syncId Permanent canonical SERTIF_SYNC_ID
     * @param array<string, mixed> $payload Exact column key-value pairs
     * @return SpreadsheetTransportResponse
     */
    public function sendRowUpdateOnly(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse;


    /**
     * Test transport availability and connectivity without mutating data.
     *
     * @param SpreadsheetConnection $connection
     * @return bool
     */
    public function testConnection(SpreadsheetConnection $connection): bool;

    /**
     * List all tabs in the remote spreadsheet (Read-Only).
     *
     * @param SpreadsheetConnection $connection
     * @return SpreadsheetTransportResponse
     */
    public function listTabs(SpreadsheetConnection $connection): SpreadsheetTransportResponse;

    /**
     * Inspect schema, headers, and masked sample data for a specific tab (Read-Only).
     *
     * @param SpreadsheetConnection $connection
     * @param string $tabName
     * @param int|null $headerRow
     * @return SpreadsheetTransportResponse
     */
    public function inspectSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null
    ): SpreadsheetTransportResponse;

    /**
     * Read a bounded page of rows from a specific tab (Read-Only).
     *
     * @param SpreadsheetConnection $connection
     * @param string $tabName
     * @param int|null $headerRow
     * @param int $page
     * @param int $pageSize (1 to 100)
     * @return SpreadsheetTransportResponse
     */
    public function readRowsPage(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        int $page = 1,
        int $pageSize = 20,
        bool $maskPii = true
    ): SpreadsheetTransportResponse;

    /**
     * Synchronize main sheet headers (Row 12) and realign existing data rows automatically via Apps Script.
     *
     * @param SpreadsheetConnection $connection
     * @param string $tabName
     * @param array<string> $headers
     * @return SpreadsheetTransportResponse
     */
    public function syncSheetSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        array $headers
    ): SpreadsheetTransportResponse;

    /**
     * Perform an isolated, heavily audited safe deletion of a proven unused and completely empty remote column.
     * Strictly fails-closed if any cell contains data, formulas, merged cells, or if schema has drifted.
     *
     * @param SpreadsheetConnection $connection
     * @param int $sheetId
     * @param string $tabName
     * @param string $columnIdentity
     * @param string $expectedSchemaHash
     * @param string|null $idempotencyKey
     * @return SpreadsheetTransportResponse
     */
    public function deleteEmptyColumn(
        SpreadsheetConnection $connection,
        int $sheetId,
        string $tabName,
        string $columnIdentity,
        string $expectedSchemaHash,
        ?string $idempotencyKey = null
    ): SpreadsheetTransportResponse;

    /**
     * Inspect Google target accessibility and metadata (Read-Only).
     */
    public function inspectTarget(
        SpreadsheetConnection $connection,
        string $targetType,
        string $targetId,
        string $permission = 'registration'
    ): SpreadsheetTransportResponse;

    /**
     * Activate verified Google target into Apps Script authorized registry.
     */
    public function activateAuthorizedTarget(
        SpreadsheetConnection $connection,
        string $targetType,
        string $targetId,
        string $permission = 'registration',
        bool $confirmPromoteLegacy = false
    ): SpreadsheetTransportResponse;

    /**
     * Attach SERTIF_SYNC_ID to an existing row in Google Sheets.
     */
    public function attachRowSyncId(
        SpreadsheetConnection $connection,
        string $tabName,
        int $rowIndex,
        string $syncId,
        ?string $registrationId = null,
        ?string $expectedChecksum = null
    ): SpreadsheetTransportResponse;
}
