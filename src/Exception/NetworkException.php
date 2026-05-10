<?php

declare(strict_types=1);

namespace Mesh0\Exception;

/** Thrown when the HTTP request never reaches the server (DNS, TLS, timeout, …). */
final class NetworkException extends Mesh0Exception
{
}
