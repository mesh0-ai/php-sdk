<?php

declare(strict_types=1);

namespace Mesh0\Exception;

/**
 * Thrown on 429.
 *
 * `retryAfter` is parsed from the `Retry-After` header (seconds) when present.
 */
final class RateLimitException extends ApiException
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        string $message,
        int $statusCode,
        ?array $body = null,
        ?string $errorId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $body, $errorId);
    }
}
