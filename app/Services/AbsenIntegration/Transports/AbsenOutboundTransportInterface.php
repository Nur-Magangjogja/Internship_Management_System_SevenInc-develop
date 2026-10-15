<?php

namespace App\Services\AbsenIntegration\Transports;

interface AbsenOutboundTransportInterface
{
    /**
     * Mengirim payload kanonikal pendaftaran magang ke sistem Absen Djuragan.
     *
     * @param array $payload Payload kanonikal pendaftaran ter-whitelist
     * @param string $idempotencyKey Kunci idempotensi unik untuk pengiriman (format: ims-reg-{id}-v{ver}-{checksum8})
     * @return AbsenTransportResponse
     */
    public function sendRegistration(array $payload, string $idempotencyKey): AbsenTransportResponse;
}
