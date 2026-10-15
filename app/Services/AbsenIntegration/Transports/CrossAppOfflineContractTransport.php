<?php

namespace App\Services\AbsenIntegration\Transports;

use App\Services\AbsenIntegration\AbsenCanonicalPayloadBuilder;

class CrossAppOfflineContractTransport implements AbsenOutboundTransportInterface
{
    protected string $scratchAbsenPath;
    protected string $apiKey;

    public function __construct(
        ?string $scratchAbsenPath = null,
        ?string $apiKey = null,
        protected ?AbsenCanonicalPayloadBuilder $payloadBuilder = null
    ) {
        $defaultAbsenPath = is_dir('d:\\laragon\\www\\Absen-Djuragan')
            ? 'd:\\laragon\\www\\Absen-Djuragan'
            : 'C:\\Users\\Nur Rahman\\.gemini\\antigravity-ide\\brain\\06d61ef3-ad02-46e4-98bd-bd533970576b\\scratch\\absen_djuragan_c3b2c1';
        $this->scratchAbsenPath = $scratchAbsenPath ?: $defaultAbsenPath;
        $this->apiKey           = $apiKey ?: (string) config('services.absen_djuragan.outbound_api_key', 'test_ims_secret_key_2026');
        $this->payloadBuilder   = $payloadBuilder ?? app(AbsenCanonicalPayloadBuilder::class);
    }

    public function getApiKey(): string
    {
        return $this->apiKey ?: (string) config('services.absen_djuragan.outbound_api_key', 'test_absen_secret_key_2026');
    }

    /**
     * Mengirim payload kanonikal secara offline melalui process bridge ke Scratch Absen Djuragan.
     * Tidak membuka port atau soket jaringan apapun (Zero Network HTTP).
     */
    public function sendRegistration(array $payload, string $idempotencyKey): AbsenTransportResponse
    {
        $apiKey = $this->getApiKey();

        // 1. Hitung Checksum Kanonikal Remote Sesuai Kontrak Absen
        $checksum = $this->payloadBuilder->calculateRemoteChecksum($payload);

        $packet = [
            'headers' => [
                'X-API-KEY'          => $apiKey,
                'X-Idempotency-Key'  => $idempotencyKey,
                'X-Payload-Checksum' => $checksum,
            ],
            'body' => $payload,
        ];

        $command = 'php artisan external:contract-bridge --env=testing --api-key=' . escapeshellarg($apiKey);

        $descriptors = [
            0 => ['pipe', 'r'], // STDIN
            1 => ['pipe', 'w'], // STDOUT
            2 => ['pipe', 'w'], // STDERR
        ];

        $rawEnv = array_merge($_SERVER, [
            'APP_ENV'          => 'testing',
            'DB_DATABASE'      => 'absen_djuragan_testing',
            'DB_PORT'          => '3300',
            'DB_HOST'          => '127.0.0.1',
            'DB_USERNAME'      => 'root',
            'DB_PASSWORD'      => '',
            'EXTERNAL_API_KEY' => $apiKey,
        ]);

        $env = [];
        foreach ($rawEnv as $k => $v) {
            if (is_scalar($v)) {
                $env[$k] = (string) $v;
            }
        }

        $process = proc_open($command, $descriptors, $pipes, $this->scratchAbsenPath, $env);

        if (!is_resource($process)) {
            return AbsenTransportResponse::serverError(500, 'Gagal membuka proses bridge scratch Absen Djuragan.');
        }

        fwrite($pipes[0], json_encode($packet));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if (empty($stdout)) {
            return AbsenTransportResponse::serverError(500, 'Bridge response kosong. STDERR: ' . $stderr);
        }

        $result = json_decode($stdout, true);
        if (!is_array($result) || !isset($result['status'])) {
            return AbsenTransportResponse::serverError(500, 'Format response bridge tidak valid: ' . $stdout);
        }

        $statusCode   = (int) $result['status'];
        $responseBody = $result['body'] ?? [];
        $rawBody      = $result['raw'] ?? json_encode($responseBody);

        if ($statusCode >= 200 && $statusCode < 300) {
            $data = is_array($responseBody) ? ($responseBody['data'] ?? $responseBody) : [];
            return AbsenTransportResponse::success($statusCode, $data, $rawBody);
        }

        if ($statusCode === 401) {
            return AbsenTransportResponse::unauthorized(
                401,
                $responseBody['message'] ?? 'Unauthorized',
                $rawBody
            );
        }

        if ($statusCode === 409) {
            return AbsenTransportResponse::conflict(
                409,
                $responseBody['message'] ?? 'Conflict',
                is_array($responseBody) ? ($responseBody['data'] ?? null) : null,
                $rawBody
            );
        }

        if ($statusCode === 422) {
            return AbsenTransportResponse::validationError(
                422,
                $responseBody['message'] ?? 'Validation error',
                is_array($responseBody) ? ($responseBody['errors'] ?? $responseBody['data'] ?? null) : null,
                $rawBody
            );
        }

        return AbsenTransportResponse::serverError(
            $statusCode,
            $responseBody['message'] ?? 'Remote server error',
            $rawBody
        );
    }
}
