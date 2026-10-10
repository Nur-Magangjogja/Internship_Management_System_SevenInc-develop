<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\SpreadsheetConnection;

class MockSpreadsheetTransport implements SpreadsheetTransportInterface
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

    public function reset(): void
    {
        $this->remoteSheets = [];
        $this->callHistory = [];
        $this->forcedResponse = null;
        $this->simulateTimeoutAfterRemoteSuccess = false;
        $this->callCount = 0;
    }
}
