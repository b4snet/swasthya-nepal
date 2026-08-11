<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Typed API exception carrying the contract's error code, status, details,
 * and response headers (API_CONTRACTS.md §8). Domain code throws this with a
 * stable code; the mapper renders it into the standard error envelope —
 * internal details are never leaked to clients (MASTER_RULES.md §17.2).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $details  structured per-field/check details
     * @param  array<string, string>  $headers  extra response headers (e.g. Retry-After)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 500,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
