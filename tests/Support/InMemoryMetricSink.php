<?php

declare(strict_types=1);

namespace Mesh0\Tests\Support;

use Mesh0\Metrics\MetricSink;

/**
 * Captures every packet that would have been written to UDP, so tests can
 * assert on the exact wire bytes the SDK produces.
 */
final class InMemoryMetricSink implements MetricSink
{
    /** @var list<string> */
    public array $packets = [];

    public bool $closed = false;

    public function send(string $packet): void
    {
        $this->packets[] = $packet;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
