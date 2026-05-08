<?php

declare(strict_types=1);

namespace Mesh0\Exception;

/** Thrown on 5xx — mesh0 reported an internal error. `errorId` is usually populated. */
final class ServerException extends ApiException
{
}
