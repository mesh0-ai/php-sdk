<?php

declare(strict_types=1);

namespace Mesh0\Event;

/**
 * Anything that accepts a finished {@see Event} and ships it onward.
 *
 * The default implementation is {@see AgentEventSink}, which fires a
 * single UDS-DGRAM datagram to the local mesh0 metrics-agent. Tests and
 * alternative transports (in-memory queues, file spools) implement this
 * interface.
 */
interface EventSink
{
    /** Send a single event. Implementations must not throw on transport failure. */
    public function send(Event|EventBuilder $event): void;
}
