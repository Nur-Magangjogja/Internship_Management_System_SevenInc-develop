<?php

namespace App\Services\SpreadsheetTransport;

use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleAppsScriptTransport implements SpreadsheetTransportInterface
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
        $this->timeoutSeconds = (int) ($cfg['timeout_seconds'] ?? 30);
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
     * Upload an attachment file to Google Drive via Apps Script.
     */
    public function uploadAttachment(
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

        $reg = $attachment->registration;
        $syncIdentity = 'ATT-' . $attachment->internship_registration_id . '-' . $attachment->id;

        $innerPayload = [
            'action'          => 'upload_attachment',
            'connection_key'  => $connection->connection_key,
            'sync_id'         => $syncIdentity,
            'registration_id' => (string) $attachment->internship_registration_id,
            'applicant_name'  => $reg ? $reg->fullname : 'Pemagang',
            'year'            => $reg && $reg->created_at ? (string) $reg->created_at->year : (string) date('Y'),
            'file_name'       => $attachment->original_name,
            'mime_type'       => $attachment->mime_type,
            'file_content'    => $base64Data,
        ];

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
     * Dispatch an authenticated HMAC-SHA256 signed request to Apps Script Web App.
     */
    public function dispatchSignedRequest(string $endpoint, array $innerPayload, string $syncId = ''): SpreadsheetTransportResponse
    {
        $timestamp = time();
        $nonce = (string) Str::uuid();

        // Calculate HMAC-SHA256 over canonical string: timestamp.nonce.json(innerPayload)
        $canonicalData = $timestamp . '.' . $nonce . '.' . json_encode($innerPayload);
        $signature = hash_hmac('sha256', $canonicalData, $this->sharedSecret);

        $requestBody = [
            'payload'   => $innerPayload,
            'timestamp' => $timestamp,
            'nonce'     => $nonce,
            'signature' => $signature,
        ];

        try {
            $httpResponse = Http::asJson()
                ->acceptJson()
                ->withOptions(['allow_redirects' => true])
                ->timeout($this->timeoutSeconds)
                ->post($endpoint, $requestBody);

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
