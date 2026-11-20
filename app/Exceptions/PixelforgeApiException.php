<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PixelforgeApiException extends RuntimeException
{
    private const array RETRYABLE_CODES = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
    ];

    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $statusCode = 0,
        public readonly ?int $retryAfter = null,
        public readonly bool $retryable = false,
        public readonly bool $uncertain = false,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Pixelforge request failed.';
        $status = $response->status();

        $retryAfter = ctype_digit((string) $response->header('Retry-After'))
            ? (int) $response->header('Retry-After')
            : null;

        $retryable = in_array($code, self::RETRYABLE_CODES, true) || $status >= 500;
        $uncertain = $status >= 500 && $code !== 'overloaded';

        return new self(
            message: $message,
            errorCode: $code,
            statusCode: $status,
            retryAfter: $retryAfter,
            retryable: $retryable,
            uncertain: $uncertain,
        );
    }

    public static function missingApiKey(): self
    {
        return new self(
            message: 'Pixelforge API key is not configured.',
            errorCode: 'invalid_api_key',
            statusCode: 401,
            retryable: false,
        );
    }

    public static function connectionFailure(Throwable $previous): self
    {
        return new self(
            message: 'Pixelforge request failed: '.$previous->getMessage(),
            retryable: true,
            uncertain: true,
        );
    }

    public static function invalidResponse(): self
    {
        return new self(
            message: 'Pixelforge returned an unexpected response.',
            errorCode: 'invalid_response',
            retryable: true,
            uncertain: true,
        );
    }
}
