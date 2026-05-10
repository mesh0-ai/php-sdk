<?php

declare(strict_types=1);

namespace Mesh0\Exception;

/** Thrown on 400 / 413 / 415 — payload was rejected by validation. */
final class BadRequestException extends ApiException
{
}
