<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\SpreadsheetConnection;

class NullSpreadsheetTransport implements SpreadsheetTransportInterface
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

    public function testConnection(SpreadsheetConnection $connection): bool
    {
        return false;
    }
}
