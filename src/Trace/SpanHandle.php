<?php

declare(strict_types=1);

namespace Mesh0\Trace;

use DateTimeInterface;

/**
 * Opaque handle returned by {@see Tracer::enter()} and consumed by
 * {@see Tracer::exit()} / {@see Tracer::exitWithException()}.
 *
 * Carries the trace identifiers and start markers captured at enter time so
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
        public string $operation,
        public DateTimeInterface $startedAt,
        public int $startedHrTimeNs,
        public array $attributes,
    ) {
    }
}
