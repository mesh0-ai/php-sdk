<?php

declare(strict_types=1);

namespace Mesh0\Metrics;

/**
 * Transport for a serialized metric packet.
 *
 * Implementations must be fire-and-forget: a metric send must never throw on
 * the request hot path or block longer than a syscall. Failures should be
 * swallowed (a datagram that doesn't make it is, by design, a non-event).
 */
interface MetricSink
{
    /**
     * Send a single statsd / DogStatsD packet.
     *
     * Multiple metrics may be combined in one packet using `\n` as the
     * separator; the agent's parser splits on newlines.
     */
    public function send(string $packet): void;

    /**
     * Release any underlying resources (e.g. the UDS-DGRAM socket). Optional —
     * sinks must remain reusable after construction without an explicit
     * `open()` call.
     */
    public function close(): void;
}
