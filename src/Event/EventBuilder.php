<?php

declare(strict_types=1);

namespace Mesh0\Event;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Fluent builder for {@see Event}.
 *
 * Each `with*` method returns a new builder, so the builder is safe to
 * share and reuse — no hidden mutation. Surface mirrors the wire shape:
 * identity (event/trace/span ids), time, plus the two open bins
 * (`attributes`, `data`). Anything outside this surface — status,
 * duration_ms, model/usage tags, user identity, environment, operation
 * name, error metadata, etc. — belongs in `attributes` (queryable) or
 * `data` (opaque). Pick keys that match your project's TQL aliases /
 * promoted fields.
 *
 * The builder is set-only: `with*` methods accept non-null values and
 * accumulate state. There is currently no way to clear a previously-set
 * field — start from a fresh `Event::now()`/`Event::at()` if you need
 * to reset.
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
        private ?string $traceId = null,
        private ?string $spanId = null,
        private ?string $parentSpanId = null,
        private ?array $attributes = null,
        private ?array $data = null,
    ) {
    }

    public function withEventId(string $id): self
    {
        self::requireNonEmpty('event_id', $id);
        return $this->copy(eventId: $id);
    }

    public function withTraceId(string $traceId): self
    {
        self::requireNonEmpty('trace_id', $traceId);
        return $this->copy(traceId: $traceId);
    }

    public function withSpan(string $spanId, ?string $parentSpanId = null): self
    {
        self::requireNonEmpty('span_id', $spanId);
        if ($parentSpanId !== null) {
            self::requireNonEmpty('parent_span_id', $parentSpanId);
        }
        return $this->copy(spanId: $spanId, parentSpanId: $parentSpanId);
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
            traceId: $this->traceId,
            spanId: $this->spanId,
            parentSpanId: $this->parentSpanId,
            attributes: $this->attributes,
            data: $this->data,
        );
    }

    private static function requireNonEmpty(string $field, string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("{$field} must be a non-empty string");
        }
    }

    /**
     * Copy-on-write helper. Note: `null` arguments mean "leave unchanged",
     * not "clear this field" — see the class-level note about the builder
     * being set-only.
     *
     * @param array<string, mixed>|null $attributes
     * @param array<string, mixed>|null $data
     */
    private function copy(
        ?DateTimeInterface $timestamp = null,
        ?string $eventId = null,
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $parentSpanId = null,
        ?array $attributes = null,
        ?array $data = null,
    ): self {
        return new self(
            timestamp: $timestamp ?? $this->timestamp,
            eventId: $eventId ?? $this->eventId,
            traceId: $traceId ?? $this->traceId,
            spanId: $spanId ?? $this->spanId,
            parentSpanId: $parentSpanId ?? $this->parentSpanId,
            attributes: $attributes ?? $this->attributes,
            data: $data ?? $this->data,
        );
    }
}
