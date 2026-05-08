<?php

declare(strict_types=1);

namespace Mesh0\Event;

enum Status: string
{
    case Success = 'success';
    case Error = 'error';
}
