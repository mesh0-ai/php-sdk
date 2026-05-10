<?php

declare(strict_types=1);

namespace Mesh0\Exception;

use Throwable;

/**
 * Thrown when the API returns a non-2xx response.
 *
 * The HTTP status code, parsed body (best-effort JSON), and a server-supplied
 * error id (when present) are all preserved so callers can log them or
 * surface them to support requests.
 */
class ApiException extends Mesh0Exception
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?array $body = null,
        public readonly ?string $errorId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
