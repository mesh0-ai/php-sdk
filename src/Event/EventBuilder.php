<?php

declare(strict_types=1);

namespace Mesh0\Event;

use DateTimeInterface;

/**
 * Fluent builder for {@see Event}.
 *
 * Each `with*` method returns a new builder, so the builder is safe to
 * share and reuse — no hidden mutation. Surface mirrors the wire shape:
 * identity (event/trace/span ids), time/duration, status, plus the two
 * open bins (`attributes`, `data`). Anything else — model, usage, user,
 * environment, operation, error info, etc. — belongs inside
 * `attributes` (queryable) or `data` (opaque). Pick keys that match your
 * project's TQL aliases / promoted fields.
 */
final readonly class EventBuilder
{
    /**
     * @param array<string, mixed>|null $attributes
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        private DateTimeInterface $timestamp,
        private ?string $eventId = null,
        private ?float $durationMs = null,
        private ?string $traceId = null,
        private ?string $spanId = null,
        private ?string $parentSpanId = null,
        private ?Status $status = null,
        private ?array $attributes = null,
        private ?array $data = null,
    ) {
    }

    public function withEventId(string $id): self
    {
        return $this->copy(eventId: $id);
    }

    public function withDurationMs(float $durationMs): self
    {
        return $this->copy(durationMs: $durationMs);
    }

    public function withTraceId(string $traceId): self
    {
        return $this->copy(traceId: $traceId);
    }

    public function withSpan(string $spanId, ?string $parentSpanId = null): self
    {
        return $this->copy(spanId: $spanId, parentSpanId: $parentSpanId);
    }

    public function withStatus(Status $status): self
    {
        return $this->copy(status: $status);
    }

    /** @param array<string, mixed> $attributes */
    public function withAttributes(array $attributes): self
    {
        $merged = $this->attributes === null ? $attributes : array_merge($this->attributes, $attributes);
        return $this->copy(attributes: $merged);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        return $this->withAttributes([$key => $value]);
    }

    /** @param array<string, mixed> $data */
    public function withData(array $data): self
    {
        return $this->copy(data: $data);
    }

    public function build(): Event
    {
        return new Event(
            timestamp: $this->timestamp,
            eventId: $this->eventId,
            durationMs: $this->durationMs,
            traceId: $this->traceId,
            spanId: $this->spanId,
            parentSpanId: $this->parentSpanId,
            status: $this->status,
            attributes: $this->attributes,
            data: $this->data,
        );
    }

    /**
     * @param array<string, mixed>|null $attributes
     * @param array<string, mixed>|null $data
     */
    private function copy(
        ?DateTimeInterface $timestamp = null,
        ?string $eventId = null,
        ?float $durationMs = null,
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $parentSpanId = null,
        ?Status $status = null,
        ?array $attributes = null,
        ?array $data = null,
    ): self {
        return new self(
            timestamp: $timestamp ?? $this->timestamp,
            eventId: $eventId ?? $this->eventId,
            durationMs: $durationMs ?? $this->durationMs,
            traceId: $traceId ?? $this->traceId,
            spanId: $spanId ?? $this->spanId,
            parentSpanId: $parentSpanId ?? $this->parentSpanId,
            status: $status ?? $this->status,
            attributes: $attributes ?? $this->attributes,
            data: $data ?? $this->data,
        );
    }
}
