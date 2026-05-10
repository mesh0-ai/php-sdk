<?php

declare(strict_types=1);

namespace Mesh0\Exception;

use RuntimeException;

/**
 * Base class for every exception thrown by the mesh0 SDK.
 *
 * Catch this to handle any SDK error in a single block; catch the more
 * specific subclasses to react to particular failure modes.
 */
class Mesh0Exception extends RuntimeException
{
}
