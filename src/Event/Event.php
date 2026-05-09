<?php

declare(strict_types=1);

namespace Mesh0\Event;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A single event ready to send to mesh0's `/v1/events` endpoint.
 *
 * Mirrors the wire shape exactly — see DATA_MODEL.md in the mesh0 core
 * repo. Only `timestamp` is required; everything else is optional and
 * server-defaulted. Domain-specific data goes into `attributes`
 * (queryable, promotable to typed columns) or `data` (opaque, only
 * shown on single-event drilldown — for big payloads).
 *
 * The wire decoder runs `DisallowUnknownFields`: any field outside the
 * set defined here is rejected with a 400. Add new top-level fields
 * only in lockstep with the backend `EventRow` struct.
 */
final readonly class Event
{
    /**
     * @param array<string, mixed>|null $attributes Queryable / promotable bin.
     * @param array<string, mixed>|null $data       Opaque bin (large payloads).
     */
    public function __construct(
        public DateTimeInterface $timestamp,
        public ?string $eventId = null,
        public ?float $durationMs = null,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $parentSpanId = null,
        public ?Status $status = null,
        public ?array $attributes = null,
        public ?array $data = null,
    ) {
    }

    public static function now(): EventBuilder
    {
        return new EventBuilder(new DateTimeImmutable());
    }

    public static function at(DateTimeInterface $when): EventBuilder
    {
        return new EventBuilder($when);
    }

    /**
     * Serialize to the wire format `/v1/events` accepts.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Server accepts ISO-8601 strings; format with millisecond precision UTC.
        // Re-zone any input timezone to UTC first — `format('…\\Z')` would
        // otherwise slap a literal `Z` on a local-time string and ship a
        // wrong instant.
        $utc = DateTimeImmutable::createFromInterface($this->timestamp)
            ->setTimezone(new DateTimeZone('UTC'));
        $out = [
            'timestamp' => $utc->format('Y-m-d\\TH:i:s.v\\Z'),
        ];

        if ($this->eventId !== null) {
            $out['event_id'] = $this->eventId;
        }
        if ($this->durationMs !== null) {
            $out['duration_ms'] = $this->durationMs;
        }
        if ($this->traceId !== null) {
            $out['trace_id'] = $this->traceId;
        }
        if ($this->spanId !== null) {
            $out['span_id'] = $this->spanId;
        }
        if ($this->parentSpanId !== null) {
            $out['parent_span_id'] = $this->parentSpanId;
        }
        if ($this->status !== null) {
            $out['status'] = $this->status->value;
        }
        if ($this->attributes !== null) {
            $out['attributes'] = $this->attributes;
        }
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
