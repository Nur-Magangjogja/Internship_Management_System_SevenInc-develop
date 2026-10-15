<?php

namespace App\Services\AbsenIntegration\Transports;

class AbsenTransportResponse
{
    public const TYPE_SUCCESS          = 'success';
    public const TYPE_TIMEOUT          = 'timeout';
    public const TYPE_VALIDATION_ERROR = 'validation_error';
    public const TYPE_CONFLICT         = 'conflict';
    public const TYPE_UNAUTHORIZED     = 'unauthorized';
    public const TYPE_SERVER_ERROR     = 'server_error';
    public const TYPE_DISABLED         = 'disabled';

    public function __construct(
        protected string $type,
        protected int $statusCode,
        protected ?array $data = null,
        protected ?string $errorMessage = null,
        protected ?string $rawBody = null
    ) {}

    public static function success(int $statusCode = 200, ?array $data = null, ?string $rawBody = null): self
    {
        return new self(self::TYPE_SUCCESS, $statusCode, $data, null, $rawBody);
    }

    public static function timeout(string $errorMessage = 'Connection timeout', ?string $rawBody = null): self
    {
        return new self(self::TYPE_TIMEOUT, 408, null, $errorMessage, $rawBody);
    }

    public static function validationError(int $statusCode = 422, string $errorMessage = 'Validation error', ?array $data = null, ?string $rawBody = null): self
    {
        return new self(self::TYPE_VALIDATION_ERROR, $statusCode, $data, $errorMessage, $rawBody);
    }

    public static function conflict(int $statusCode = 409, string $errorMessage = 'Conflict: resource already exists', ?array $data = null, ?string $rawBody = null): self
    {
        return new self(self::TYPE_CONFLICT, $statusCode, $data, $errorMessage, $rawBody);
    }

    public static function unauthorized(int $statusCode = 401, string $errorMessage = 'Unauthorized', ?string $rawBody = null): self
    {
        return new self(self::TYPE_UNAUTHORIZED, $statusCode, null, $errorMessage, $rawBody);
    }

    public static function serverError(int $statusCode = 500, string $errorMessage = 'Internal server error', ?string $rawBody = null): self
    {
        return new self(self::TYPE_SERVER_ERROR, $statusCode, null, $errorMessage, $rawBody);
    }

    public static function disabled(string $errorMessage = 'Outbound transport is disabled.'): self
    {
        return new self(self::TYPE_DISABLED, 503, null, $errorMessage, null);
    }

    public function isSuccess(): bool
    {
        return $this->type === self::TYPE_SUCCESS;
    }

    public function isTimeout(): bool
    {
        return $this->type === self::TYPE_TIMEOUT;
    }

    public function isValidationError(): bool
    {
        return $this->type === self::TYPE_VALIDATION_ERROR;
    }

    public function isConflict(): bool
    {
        return $this->type === self::TYPE_CONFLICT;
    }

    public function isUnauthorized(): bool
    {
        return $this->type === self::TYPE_UNAUTHORIZED;
    }

    public function isDisabled(): bool
    {
        return $this->type === self::TYPE_DISABLED;
    }

    public function isRetryable(): bool
    {
        return in_array($this->type, [self::TYPE_TIMEOUT, self::TYPE_SERVER_ERROR], true);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
