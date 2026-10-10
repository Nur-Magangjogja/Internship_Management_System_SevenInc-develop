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
     * Test transport availability and connectivity without mutating data.
     *
     * @param SpreadsheetConnection $connection
     * @return bool
     */
    public function testConnection(SpreadsheetConnection $connection): bool;
}
