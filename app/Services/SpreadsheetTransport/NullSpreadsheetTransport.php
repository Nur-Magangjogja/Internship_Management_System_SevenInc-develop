<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\SpreadsheetConnection;

class NullSpreadsheetTransport implements SpreadsheetSidecarTransportInterface
{
    /**
     * In S9D, without active S9E live Apps Script transport,
     * NullSpreadsheetTransport reports unavailable failure without fake success.
     */
    public function sendRowUpsert(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif (tersedia pada Phase S9E).',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function sendRowUpdateOnly(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif (tersedia pada Phase S9E).',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function lookupRow(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif (tersedia pada Phase S9E).',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function ensureExtensionColumns(
        SpreadsheetConnection $connection,
        string $tabName,
        array $columns
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif (tersedia pada Phase S9E).',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
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
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif (tersedia pada Phase S9E).',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function inspectSidecarAlignment(
        SpreadsheetConnection $connection,
        string $mainTabName,
        string $extensionTabName,
        ?string $syncId = null
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif (tersedia pada Phase S9E).',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function testConnection(SpreadsheetConnection $connection): bool
    {
        return false;
    }

    public function listTabs(SpreadsheetConnection $connection): SpreadsheetTransportResponse
    {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif.',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function inspectSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif.',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function readRowsPage(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        int $page = 1,
        int $pageSize = 20,
        bool $maskPii = true
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif.',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }

    public function deleteEmptyColumn(
        SpreadsheetConnection $connection,
        int $sheetId,
        string $tabName,
        string $columnIdentity,
        string $expectedSchemaHash,
        ?string $idempotencyKey = null
    ): SpreadsheetTransportResponse {
        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Transport Spreadsheet Google Apps Script belum aktif.',
            errorCode: 'TRANSPORT_UNAVAILABLE',
            isRetryable: true,
            context: ['connection_key' => $connection->connection_key]
        );
    }
}
