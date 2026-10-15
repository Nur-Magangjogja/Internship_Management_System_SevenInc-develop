<?php

namespace App\Services\AbsenIntegration\Transports;

use App\Services\AbsenIntegration\AbsenCanonicalPayloadBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AbsenHttpOutboundTransport implements AbsenOutboundTransportInterface
{
    public function __construct(
        protected ?AbsenCanonicalPayloadBuilder $payloadBuilder = null
    ) {
        $this->payloadBuilder = $payloadBuilder ?? app(AbsenCanonicalPayloadBuilder::class);
    }

    /**
     * Mengirim payload kanonikal pendaftaran magang ke endpoint receiver Absen Djuragan.
     */
    public function sendRegistration(array $payload, string $idempotencyKey): AbsenTransportResponse
    {
        // 1. Feature Flag Gate: Tolak eksekusi jika outbound dinonaktifkan
        $isEnabled = (bool) config('services.absen_djuragan.outbound_enabled', false);
        if (!$isEnabled) {
            return AbsenTransportResponse::disabled('HTTP Outbound transport is disabled by configuration.');
        }

        // 2. Persiapan URL dan Kredensial
        $baseUrl  = rtrim((string) config('services.absen_djuragan.url', 'http://absen-djuragan.test/api/v1/external'), '/');
        $endpoint = $baseUrl . '/register-sync';
        $apiKey   = (string) config('services.absen_djuragan.outbound_api_key', '');
        $timeout  = (int) config('services.absen_djuragan.sync_timeout', 15);

        // 3. Perhitungan Checksum Kanonikal Remote
        $checksum = $this->payloadBuilder->calculateRemoteChecksum($payload);

        // 4. Eksekusi HTTP Request
        try {
            $response = Http::withHeaders([
                'X-Api-Key'          => $apiKey,
                'X-Idempotency-Key'  => $idempotencyKey,
                'X-Payload-Checksum' => $checksum,
                'Accept'             => 'application/json',
                'Content-Type'       => 'application/json',
            ])
            ->timeout($timeout)
            ->post($endpoint, $payload);

            $statusCode = $response->status();
            $body       = $response->json();
            $rawBody    = $response->body();

            // Tangani respons berdasarkan status code
            if ($response->successful()) { // 200, 201
                $data = is_array($body) ? ($body['data'] ?? $body) : [];
                return AbsenTransportResponse::success($statusCode, $data, $rawBody);
            }

            if ($statusCode === 401) {
                return AbsenTransportResponse::unauthorized(
                    401,
                    $body['message'] ?? 'Autentikasi ditolak oleh receiver Absen Djuragan.',
                    $rawBody
                );
            }

            if ($statusCode === 409) {
                return AbsenTransportResponse::conflict(
                    409,
                    $body['message'] ?? 'Konflik idempotensi atau identitas pada receiver Absen Djuragan.',
                    is_array($body) ? ($body['data'] ?? null) : null,
                    $rawBody
                );
            }

            if ($statusCode === 422) {
                return AbsenTransportResponse::validationError(
                    422,
                    $body['message'] ?? 'Validasi payload pendaftaran gagal pada receiver.',
                    is_array($body) ? ($body['errors'] ?? $body['data'] ?? null) : null,
                    $rawBody
                );
            }

            if ($statusCode === 429) {
                return AbsenTransportResponse::serverError(
                    429,
                    'Rate limited oleh receiver Absen Djuragan: ' . ($body['message'] ?? 'Terlalu banyak permintaan.'),
                    $rawBody
                );
            }

            // Status 5xx atau status HTTP lainnya
            return AbsenTransportResponse::serverError(
                $statusCode,
                $body['message'] ?? "Remote server error ({$statusCode}).",
                $rawBody
            );

        } catch (ConnectionException $e) {
            // Network timeout atau kegagalan koneksi
            return AbsenTransportResponse::timeout(
                'Koneksi timeout ke receiver Absen Djuragan: ' . $e->getMessage()
            );
        } catch (Throwable $e) {
            Log::error('AbsenHttpOutboundTransport unexpected error', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                // API key TIDAK dibocorkan ke log
            ]);

            return AbsenTransportResponse::serverError(
                500,
                'Terjadi kesalahan saat memproses transport HTTP: ' . $e->getMessage()
            );
        }
    }
}
