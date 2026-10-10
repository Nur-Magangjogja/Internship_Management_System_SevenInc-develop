<?php

namespace App\Services\SpreadsheetTransport;

class SpreadsheetTransportResponse
{
    public ?array $rawResponse = null;

    public function __construct(
        public bool $success,
        public ?int $rowIndex = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public bool $isRetryable = true,
        public array $context = []
    ) {
        $this->rawResponse = $context;
    }

    public static function ok(?int $rowIndex = null, array $context = []): self
    {
        return self::success($rowIndex, $context);
    }

    public static function success(?int $rowIndex = null, array $context = []): self
    {
        return new self(
            success: true,
            rowIndex: $rowIndex,
            isRetryable: false,
            context: $context
        );
    }

    public static function failure(string $errorMessage, ?string $errorCode = null, bool $isRetryable = true, array $context = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            isRetryable: $isRetryable,
            context: $context
        );
    }
}
