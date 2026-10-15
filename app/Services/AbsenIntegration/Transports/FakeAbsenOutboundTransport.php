<?php

namespace App\Services\AbsenIntegration\Transports;

use PHPUnit\Framework\Assert;

class FakeAbsenOutboundTransport implements AbsenOutboundTransportInterface
{
    protected array $sentRequests = [];
    protected ?\Closure $handler = null;
    protected ?AbsenTransportResponse $nextResponse = null;

    /**
     * Kirim registrasi ke in-memory mock engine.
     */
    public function sendRegistration(array $payload, string $idempotencyKey): AbsenTransportResponse
    {
        $this->sentRequests[] = [
            'payload'         => $payload,
            'idempotency_key' => $idempotencyKey,
            'timestamp'       => now(),
        ];

        if ($this->handler !== null) {
            return ($this->handler)($payload, $idempotencyKey);
        }

        if ($this->nextResponse !== null) {
            return $this->nextResponse;
        }

        // Default mock success response
        return AbsenTransportResponse::success(200, [
            'absen_user_id' => 9999,
            'intern_id'     => 8888,
            'status'        => 'synced',
        ]);
    }

    public function willRespondWith(AbsenTransportResponse $response): self
    {
        $this->nextResponse = $response;
        return $this;
    }

    public function withHandler(callable $handler): self
    {
        $this->handler = \Closure::fromCallable($handler);
        return $this;
    }

    public function assertSentCount(int $expectedCount): void
    {
        $actualCount = count($this->sentRequests);
        Assert::assertSame($expectedCount, $actualCount, "Expected {$expectedCount} outbound requests, but {$actualCount} were sent.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sentRequests, 'Expected zero outbound requests to be sent.');
    }

    public function getSentRequests(): array
    {
        return $this->sentRequests;
    }

    public function getLastSentRequest(): ?array
    {
        if (empty($this->sentRequests)) {
            return null;
        }
        $last = end($this->sentRequests);
        reset($this->sentRequests);
        return $last;
    }

    public function reset(): void
    {
        $this->sentRequests = [];
        $this->handler = null;
        $this->nextResponse = null;
    }
}
