<?php

declare(strict_types=1);

namespace Mesh0\Exception;

/** Thrown on 401 / 403 — the API key is missing, malformed, or revoked. */
final class AuthenticationException extends ApiException
{
}
