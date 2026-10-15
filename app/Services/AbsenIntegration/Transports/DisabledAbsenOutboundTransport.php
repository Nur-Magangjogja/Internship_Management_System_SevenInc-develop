<?php

namespace App\Services\AbsenIntegration\Transports;

class DisabledAbsenOutboundTransport implements AbsenOutboundTransportInterface
{
    /**
     * Fail-closed transport implementation: menolak seluruh pengiriman jaringan nyata.
     * Digunakan secara default pada sistem produksi & pengujian sebelum aktivasi resmi.
     */
    public function sendRegistration(array $payload, string $idempotencyKey): AbsenTransportResponse
    {
        return AbsenTransportResponse::disabled('Pengiriman HTTP outbound Absen Djuragan dinonaktifkan (outbound_enabled = false).');
    }
}
