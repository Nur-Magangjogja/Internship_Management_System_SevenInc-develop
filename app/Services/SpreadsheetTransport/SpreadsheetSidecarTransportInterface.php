<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\SpreadsheetConnection;

interface SpreadsheetSidecarTransportInterface extends SpreadsheetTransportInterface
{
    /**
     * Ensure sidecar extension columns exist in Google Sheets.
     * Appends new field_key columns E+ without modifying/reordering existing headers.
     *
     * @param SpreadsheetConnection $connection
     * @param string $tabName (e.g. 2026_Custom_Fields)
     * @param array<string> $columns List of stable field_key column names
     * @return SpreadsheetTransportResponse
     */
    public function ensureExtensionColumns(
        SpreadsheetConnection $connection,
        string $tabName,
        array $columns
    ): SpreadsheetTransportResponse;

    /**
     * Upsert a sidecar extension row in Google Sheets.
     * Strictly verifies main row existence and identity before mutating sidecar.
     *
     * @param SpreadsheetConnection $connection
     * @param string $mainTabName (e.g. 2026)
     * @param string $extensionTabName (e.g. 2026_Custom_Fields)
     * @param string $syncId (Main.Z = Sidecar.A)
     * @param int|string $registrationId (Main.AA = Sidecar.B)
     * @param string $formSchemaVersion (Sidecar.C)
     * @param string $lastSyncAt (Sidecar.D)
     * @param array<string, mixed> $answers (Sidecar.E+ field_key => value)
     * @return SpreadsheetTransportResponse
     */
    public function upsertExtensionRow(
        SpreadsheetConnection $connection,
        string $mainTabName,
        string $extensionTabName,
        string $syncId,
        int|string $registrationId,
        string $formSchemaVersion,
        string $lastSyncAt,
        array $answers
    ): SpreadsheetTransportResponse;

    /**
     * Inspect alignment between main tab and sidecar extension tab.
     *
     * @param SpreadsheetConnection $connection
     * @param string $mainTabName
     * @param string $extensionTabName
     * @param string|null $syncId
     * @return SpreadsheetTransportResponse
     */
    public function inspectSidecarAlignment(
        SpreadsheetConnection $connection,
        string $mainTabName,
        string $extensionTabName,
        ?string $syncId = null
    ): SpreadsheetTransportResponse;
}
