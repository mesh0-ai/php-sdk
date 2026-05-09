<?php

declare(strict_types=1);

namespace Mesh0\Tests\Support;

use Mesh0\Event\Event;
use Mesh0\Event\EventBuilder;
use Mesh0\Event\EventSink;

/**
 * Test-only sink that records every event passed to {@see send()} as a built
 * {@see Event}. Lets Tracer tests assert on emitted spans without standing up
 * a UDP listener.
 */
final class InMemoryEventSink implements EventSink
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event|EventBuilder $event): void
    {
        $this->events[] = $event instanceof EventBuilder ? $event->build() : $event;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
