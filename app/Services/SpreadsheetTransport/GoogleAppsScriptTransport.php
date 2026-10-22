<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleAppsScriptTransport implements SpreadsheetSidecarTransportInterface
{
    protected ?string $endpointUrl;
    protected ?string $sharedSecret;
    protected int $timeoutSeconds;
    protected bool $enabled;

    public function __construct(array $config = [])
    {
        $cfg = array_merge(config('services.google_apps_script', []), $config);

        $this->enabled        = (bool) ($cfg['enabled'] ?? false);
        $this->endpointUrl    = $cfg['endpoint_url'] ?? null;
        $this->sharedSecret   = $cfg['shared_secret'] ?? null;
        $this->timeoutSeconds = (int) ($cfg['timeout_seconds'] ?? 60);
    }

    public function setTimeoutSeconds(int $seconds): self
    {
        $this->timeoutSeconds = max(5, $seconds);
        return $this;
    }

    /**
     * Check if Apps Script transport is fully configured.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && !empty($this->endpointUrl) && !empty($this->sharedSecret);
    }

    /**
     * Send an idempotent row upsert to Google Apps Script.
     */
    public function sendRowUpsert(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        // Sanitize formula injection on payload values
        $sanitizedPayload = $this->sanitizePayloadForFormulas($payload);

        $innerPayload = [
            'action'         => 'upsert_row',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'tab_name'       => $tabName,
            'sync_id'        => $syncId,
            'payload'        => $sanitizedPayload,
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, $syncId);
    }

    /**
     * Send an idempotent row update only to Google Apps Script.
     * Strictly fails without row insertion if the row does not already exist remotely.
     */
    public function sendRowUpdateOnly(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId,
        array $payload
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        // Sanitize formula injection on payload values
        $sanitizedPayload = $this->sanitizePayloadForFormulas($payload);

        $innerPayload = [
            'action'         => 'update_row',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'tab_name'       => $tabName,
            'sync_id'        => $syncId,
            'payload'        => $sanitizedPayload,
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, $syncId);
    }

    /**
     * Synchronize main sheet headers (Row 12) and realign existing data rows automatically via Apps Script.
     */
    public function syncSheetSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        array $headers
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $innerPayload = [
            'action'         => 'sync_sheet_schema',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'tab_name'       => $tabName,
            'headers'        => array_values($headers),
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, 'SYNC_SHEET_SCHEMA');
    }

    /**
     * Perform safe remote row lookup by sync_id via Apps Script.
     */
    public function lookupRow(
        SpreadsheetConnection $connection,
        string $tabName,
        string $syncId
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $innerPayload = [
            'action'         => 'lookup_row',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'tab_name'       => $tabName,
            'sync_id'        => $syncId,
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, $syncId);
    }

    /**
     * Ensure sidecar extension columns exist in Google Sheets.
     */
    public function ensureExtensionColumns(
        SpreadsheetConnection $connection,
        string $tabName,
        array $columns
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $innerPayload = [
            'action'         => 'ensure_extension_columns',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'sheet_name'     => $tabName,
            'columns'        => array_values($columns),
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, 'ENSURE_COLUMNS');
    }

    /**
     * Upsert a sidecar extension row in Google Sheets.
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
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $sanitizedAnswers = $this->sanitizePayloadForFormulas($answers);

        $innerPayload = [
            'action'               => 'upsert_extension_row',
            'connection_key'       => $connection->connection_key,
            'spreadsheet_id'       => $connection->spreadsheet_id,
            'main_sheet_name'      => $mainTabName,
            'extension_sheet_name' => $extensionTabName,
            'sync_id'              => $syncId,
            'registration_id'      => (string) $registrationId,
            'form_schema_version'  => (string) $formSchemaVersion,
            'last_sync_at'         => (string) $lastSyncAt,
            'answers'              => $sanitizedAnswers,
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, $syncId);
    }

    /**
     * Inspect alignment between main tab and sidecar extension tab.
     */
    public function inspectSidecarAlignment(
        SpreadsheetConnection $connection,
        string $mainTabName,
        string $extensionTabName,
        ?string $syncId = null
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $innerPayload = [
            'action'               => 'inspect_sidecar_alignment',
            'connection_key'       => $connection->connection_key,
            'spreadsheet_id'       => $connection->spreadsheet_id,
            'main_sheet_name'      => $mainTabName,
            'extension_sheet_name' => $extensionTabName,
            'sync_id'              => $syncId,
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, $syncId ?: 'ALIGNMENT');
    }

    /**
     * Upload an attachment file to Google Drive via Apps Script.
     * DEPRECATED per Master Plan V1 Addendum Final V4 (F6 Cutover, C27).
     * Seluruh penyimpanan berkas dialihkan eksklusif ke UniversalFileService (Laravel Google Drive API).
     */
    public function uploadAttachment(
        SpreadsheetConnection $connection,
        RegistrationAttachment $attachment,
        string $localFullPath
    ): SpreadsheetTransportResponse {
        Log::info("GoogleAppsScriptTransport::uploadAttachment dipanggil namun telah dipensiunkan (F6 Cutover). Mengembalikan ACTION_DEPRECATED_USE_DRIVE_GATEWAY.");

        return SpreadsheetTransportResponse::failure(
            errorMessage: 'Aksi upload_attachment pada Apps Script telah dipensiunkan. Gunakan UniversalFileService melalui Google Drive API Laravel.',
            errorCode: 'ACTION_DEPRECATED_USE_DRIVE_GATEWAY',
            isRetryable: false
        );
    }

    public function uploadAttachmentLegacy(
        SpreadsheetConnection $connection,
        RegistrationAttachment $attachment,
        string $localFullPath
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        if (!file_exists($localFullPath)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Berkas lampiran lokal tidak ditemukan.',
                errorCode: 'LOCAL_FILE_MISSING',
                isRetryable: false
            );
        }

        $fileSize = filesize($localFullPath);
        // Maximum 5 MB technical payload transfer limit for Apps Script base64 encoding
        if ($fileSize > 5 * 1024 * 1024) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Ukuran berkas melebihi batas transfer Apps Script (maksimum 5 MB).',
                errorCode: 'FILE_TOO_LARGE',
                isRetryable: false
            );
        }

        $fileData = file_get_contents($localFullPath);
        $base64Data = base64_encode($fileData);

        $attachment->loadMissing('registration');
        $reg = $attachment->registration;

        if (!$reg || empty($reg->id)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Data registrasi terkait lampiran tidak ditemukan atau tidak valid.',
                errorCode: 'INVALID_REGISTRATION',
                isRetryable: false
            );
        }

        if (!$reg->created_at) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Tanggal pendaftaran (created_at) tidak valid untuk penentuan tahun.',
                errorCode: 'INVALID_REGISTRATION_YEAR',
                isRetryable: false
            );
        }

        $createdAt = $reg->created_at instanceof \DateTimeInterface
            ? $reg->created_at
            : \Illuminate\Support\Carbon::parse($reg->created_at);
        $year = (string) $createdAt->format('Y');
        $fileChecksum = hash_file('sha256', $localFullPath);
        $contentVersion = substr($fileChecksum, 0, 16);
        $syncIdentity = 'ATT-' . $reg->id . '-' . $attachment->id . '-v' . $contentVersion;

        $innerPayload = [
            'action'          => 'upload_attachment',
            'connection_key'  => $connection->connection_key,
            'sync_id'         => $syncIdentity,
            'registration_id' => (string) $reg->id,
            'attachment_id'   => (string) $attachment->id,
            'applicant_name'  => (string) $reg->fullname,
            'category'        => (string) ($attachment->category ?? 'lampiran'),
            'year'            => $year,
            'file_name'       => $attachment->original_name,
            'mime_type'       => $attachment->mime_type,
            'file_content'    => $base64Data,
            'file_checksum'   => $fileChecksum,
            'content_version' => $contentVersion,
        ];

        if (!empty($connection->drive_folder_id)) {
            $innerPayload['drive_folder_id'] = (string) $connection->drive_folder_id;
        }

        if (!app()->runningUnitTests()) {
            $innerPayload['direct_applicant_folder'] = true;
        }

        return $this->dispatchSignedRequest($endpoint, $innerPayload, $syncIdentity);
    }

    /**
     * Test transport availability and connectivity without mutating data.
     */
    public function testConnection(SpreadsheetConnection $connection): bool
    {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return false;
        }

        $innerPayload = [
            'action'         => 'ping',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
        ];

        $response = $this->dispatchSignedRequest($endpoint, $innerPayload, 'PING');

        return $response->success;
    }

    /**
     * List all tabs in the remote spreadsheet (Read-Only).
     */
    public function listTabs(SpreadsheetConnection $connection): SpreadsheetTransportResponse
    {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $innerPayload = [
            'action'         => 'list_tabs',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, 'LIST_TABS');
    }

    /**
     * Inspect schema, headers, and masked sample data for a specific tab (Read-Only).
     */
    public function inspectSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $innerPayload = [
            'action'         => 'inspect_schema',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'tab_name'       => $tabName,
        ];

        if ($headerRow !== null) {
            $innerPayload['header_row'] = $headerRow;
        }

        return $this->dispatchSignedRequest($endpoint, $innerPayload, 'INSPECT_SCHEMA');
    }

    /**
     * Read a bounded page of rows from a specific tab (Read-Only).
     */
    public function readRowsPage(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        int $page = 1,
        int $pageSize = 20,
        bool $maskPii = true
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $clampedPage = max(1, $page);
        $clampedPageSize = min(100, max(1, $pageSize));

        $innerPayload = [
            'action'         => 'read_rows_page',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $connection->spreadsheet_id,
            'tab_name'       => $tabName,
            'page'           => $clampedPage,
            'page_size'      => $clampedPageSize,
            'mask_pii'       => $maskPii,
        ];

        if ($headerRow !== null) {
            $innerPayload['header_row'] = $headerRow;
        }

        return $this->dispatchSignedRequest($endpoint, $innerPayload, 'READ_ROWS');
    }

    /**
     * Perform an isolated, heavily audited safe deletion of a proven unused and completely empty remote column.
     * Strictly fails-closed if any cell contains data, formulas, merged cells, or if schema has drifted.
     */
    public function deleteEmptyColumn(
        SpreadsheetConnection $connection,
        int $sheetId,
        string $tabName,
        string $columnIdentity,
        string $expectedSchemaHash,
        ?string $idempotencyKey = null
    ): SpreadsheetTransportResponse {
        $endpoint = $connection->apps_script_endpoint_url ?: $this->endpointUrl;

        if (empty($endpoint) || empty($this->sharedSecret)) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Apps Script endpoint URL atau shared secret belum dikonfigurasi.',
                errorCode: 'TRANSPORT_NOT_CONFIGURED',
                isRetryable: false
            );
        }

        $innerPayload = [
            'action'               => 'delete_empty_column',
            'connection_key'       => $connection->connection_key,
            'spreadsheet_id'       => $connection->spreadsheet_id,
            'sheet_id'             => $sheetId,
            'tab_name'             => $tabName,
            'column_identity'      => $columnIdentity,
            'expected_schema_hash' => $expectedSchemaHash,
            'idempotency_key'      => $idempotencyKey ?: (string) Str::uuid(),
        ];

        return $this->dispatchSignedRequest($endpoint, $innerPayload, 'DELETE_COLUMN_' . $columnIdentity);
    }

    /**
     * Dispatch an authenticated HMAC-SHA256 signed request to Apps Script Web App.
     */
    public function dispatchSignedRequest(string $endpoint, array $innerPayload, string $syncId = ''): SpreadsheetTransportResponse
    {
        $timestamp = time();
        $nonce = (string) Str::uuid();

        // Calculate HMAC-SHA256 over canonical string: timestamp.nonce.json(innerPayload)
        try {
            $canonicalJson = json_encode($innerPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Gagal serialisasi JSON canonical payload: ' . $e->getMessage(),
                errorCode: 'JSON_ENCODE_ERROR',
                isRetryable: false
            );
        }

        $canonicalData = $timestamp . '.' . $nonce . '.' . $canonicalJson;
        $signature = hash_hmac('sha256', $canonicalData, $this->sharedSecret);

        $requestBody = [
            'payload'   => $innerPayload,
            'timestamp' => $timestamp,
            'nonce'     => $nonce,
            'signature' => $signature,
        ];

        try {
            $bodyJson = json_encode($requestBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);

            $httpResponse = Http::withBody($bodyJson, 'application/json')
                ->acceptJson()
                ->withOptions(['allow_redirects' => true])
                ->timeout($this->timeoutSeconds)
                ->post($endpoint);

            if ($httpResponse->status() === 401 || $httpResponse->status() === 403) {
                return SpreadsheetTransportResponse::failure(
                    errorMessage: 'Autentikasi Apps Script ditolak (401/403). Periksa shared secret.',
                    errorCode: 'AUTH_FAILED',
                    isRetryable: false,
                    context: ['status' => $httpResponse->status()]
                );
            }

            if ($httpResponse->status() === 429 || $httpResponse->status() >= 500) {
                return SpreadsheetTransportResponse::failure(
                    errorMessage: "Server Google Apps Script merespon status {$httpResponse->status()}.",
                    errorCode: 'GOOGLE_SERVER_ERROR',
                    isRetryable: true,
                    context: ['status' => $httpResponse->status()]
                );
            }

            $data = $httpResponse->json();

            if (!is_array($data)) {
                return SpreadsheetTransportResponse::failure(
                    errorMessage: 'Format respon dari Apps Script tidak berupa JSON valid.',
                    errorCode: 'INVALID_JSON_RESPONSE',
                    isRetryable: true,
                    context: ['body' => substr($httpResponse->body(), 0, 500)]
                );
            }

            if (($data['success'] ?? false) === true) {
                return SpreadsheetTransportResponse::ok(
                    rowIndex: $data['row_index'] ?? null,
                    context: $data
                );
            }

            // Remote returned structured failure
            return SpreadsheetTransportResponse::failure(
                errorMessage: $data['message'] ?? 'Apps Script mengembalikan error tidak diketahui.',
                errorCode: $data['error_code'] ?? 'REMOTE_APPS_SCRIPT_ERROR',
                isRetryable: (bool) ($data['is_retryable'] ?? false),
                context: $data
            );
        } catch (Throwable $e) {
            Log::warning("Apps Script HTTP request failed for syncId {$syncId}: " . $e->getMessage());

            return SpreadsheetTransportResponse::failure(
                errorMessage: 'Koneksi jaringan ke Apps Script gagal: ' . $this->sanitizeError($e->getMessage()),
                errorCode: 'NETWORK_TIMEOUT',
                isRetryable: true
            );
        }
    }

    /**
     * Prevent spreadsheet formula injection by sanitizing leading special characters.
     */
    public function sanitizePayloadForFormulas(array $payload): array
    {
        $sanitized = [];
        $dangerousLeading = ['=', '+', '-', '@', "\t", "\r"];

        foreach ($payload as $key => $value) {
            if (is_string($value) && !empty($value)) {
                $firstChar = $value[0];
                if (in_array($firstChar, $dangerousLeading, true)) {
                    // Prepend single quote so Google Sheets treats it as plaintext
                    $sanitized[$key] = "'" . $value;
                    continue;
                }
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Sanitize error message to ensure zero leaked credentials.
     */
    protected function sanitizeError(string $msg): string
    {
        if (!empty($this->sharedSecret)) {
            $msg = str_replace($this->sharedSecret, '***REDACTED***', $msg);
        }
        return preg_replace('/https?:\/\/[^\s]+/i', '[REDACTED_URL]', $msg);
    }
}
