<?php

declare(strict_types=1);

namespace Mesh0\Trace;

use DateTimeInterface;

/**
 * Opaque handle returned by {@see Tracer::enter()} and consumed by
 * {@see Tracer::exit()}.
 *
 * Carries the trace identifiers and start time captured at enter time so
 * the matching exit call does not have to recompute them. Treat this as
 * opaque — its shape is not part of the public API.
 *
 * @internal Implementation detail of the Tracer; constructed only by the Tracer.
 */
final readonly class SpanHandle
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public DateTimeInterface $startedAt,
        public array $attributes,
    ) {
    }
}
