<?php

declare(strict_types=1);

namespace Mesh0\Trace;

use DateTimeImmutable;
use Mesh0\Event\EventBuilder;
use Mesh0\Event\EventSink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Builds a trace tree from nested span enter/exit calls and ships each
 * span to a sink as an independent event.
 *
 * Each span produces exactly one event on `exit()`, carrying
 * `trace_id`, `span_id`, and `parent_span_id`. Everything else is just
 * attributes — the Tracer never injects keys on the caller's behalf.
 * By convention (per DATA_MODEL.md) callers set
 * `attributes["span.name"]`, and on the error path
 * `attributes["error.type"]` / `attributes["error.message"]`. Callers
 * that want span duration or status as queryable signals should set
 * them as attributes themselves (e.g. `attributes["duration_ms"]`,
 * `attributes["status"]`) and alias/promote them on the project schema.
 *
 * Closure form (recommended — exception-safe):
 *
 * ```php
 * $result = $tracer->span(['span.name' => 'block.if', 'block_id' => 'b_123'], function () use ($tracer) {
 *     return $tracer->span(['span.name' => 'block.http_request', 'url' => $url], fn () => $client->get($url));
 * });
 * ```
 *
 * Manual form (when a closure does not fit, e.g. block dispatchers that
 * resume across stack frames):
 *
 * ```php
 * $h = $tracer->enter(['span.name' => 'block.loop', 'block_id' => 'b_456']);
 * try {
 *     // run block
 *     $tracer->exit($h, attributes: ['iterations' => $n]);
 * } catch (\Throwable $e) {
 *     $tracer->exit($h, [
 *         'status'        => 'error',
 *         'error.type'    => $e::class,
 *         'error.message' => $e->getMessage(),
 *     ]);
 *     throw $e;
 * }
 * ```
 *
 * Long-lived workers (FrankenPHP, RoadRunner, Swoole) MUST call {@see reset()}
 * between requests so per-request trace state does not leak across them.
 *
 * Trace IDs are 32 hex characters (16 bytes) and span IDs are 16 hex
 * characters (8 bytes), matching the W3C / OTLP encoding so an incoming
 * `traceparent` header can be adopted via {@see startTrace()} without
 * translation.
 */
final class Tracer
{
    private ?string $traceId = null;

    /** Parent span id from an adopted W3C traceparent, applied to the next root enter(). */
    private ?string $incomingParent = null;

    /** @var list<SpanHandle> */
    private array $stack = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EventSink $sink,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Adopt an incoming W3C traceparent header for the next root span.
     *
     * Must be called before the first {@see enter()} of the trace; later calls
     * are ignored and return `false`. Format:
     * `00-<32 hex traceId>-<16 hex parentSpanId>-<2 hex flags>`. The flags byte
     * is accepted as-is per W3C (unknown flags must be ignored, not rejected).
     *
     * Returns `true` if the header was parsed and adopted. The adopted parent
     * id is consumed by the next {@see enter()}; if no `enter()` follows (the
     * request short-circuits), the next call to {@see reset()} clears it. In
     * long-lived workers, always pair `startTrace()` with a `reset()` at the
     * end of the request to avoid leaking the parent id into the next one.
     */
    public function startTrace(?string $traceparent = null): bool
    {
        if ($this->traceId !== null || $this->stack !== []) {
            return false;
        }
        if ($traceparent === null) {
            return false;
        }
        $parts = \explode('-', $traceparent);
        if (\count($parts) !== 4) {
            return false;
        }
        [$version, $tid, $pid, $_flags] = $parts;
        if ($version !== '00'
            || \preg_match('/^[0-9a-f]{32}$/', $tid) !== 1
            || \preg_match('/^[0-9a-f]{16}$/', $pid) !== 1
            || $tid === \str_repeat('0', 32)
            || $pid === \str_repeat('0', 16)
        ) {
            return false;
        }
        $this->traceId = $tid;
        $this->incomingParent = $pid;
        return true;
    }

    /** Current trace_id, or null if no span is open and none has been started. */
    public function currentTraceId(): ?string
    {
        return $this->traceId;
    }

    /** Top-of-stack span_id, or null if no span is currently open. */
    public function currentSpanId(): ?string
    {
        $top = \end($this->stack);
        return $top === false ? null : $top->spanId;
    }

    /** True if at least one span is currently open. */
    public function hasOpenSpan(): bool
    {
        return $this->stack !== [];
    }

    /**
     * Open a new span. The returned handle must be passed to {@see exit()}
     * to close it.
     *
     * The `trace_id` is generated lazily on the first `enter()` and persists
     * until {@see reset()} is called — consecutive root spans (siblings opened
     * after the previous root has fully exited) share the same `trace_id` and
     * appear as multiple parentless roots in one trace. Customers wanting one
     * trace per logical execution must call `reset()` between them; long-lived
     * workers (FrankenPHP, RoadRunner, Swoole) must do this anyway.
     *
     * @param array<string, mixed> $attributes
     */
    public function enter(array $attributes = []): SpanHandle
    {
        $traceId = $this->traceId ??= self::generateTraceId();
        $spanId = self::generateSpanId();

        $top = \end($this->stack);
        if ($top !== false) {
            $parentSpanId = $top->spanId;
        } elseif ($this->incomingParent !== null) {
            $parentSpanId = $this->incomingParent;
            $this->incomingParent = null;
        } else {
            $parentSpanId = null;
        }

        $handle = new SpanHandle(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            startedAt: new DateTimeImmutable(),
            attributes: $attributes,
        );
        $this->stack[] = $handle;
        return $handle;
    }

    /**
     * Close a span. Caller-supplied `$attributes` are merged on top of the
     * attributes passed to {@see enter()}.
     *
     * @param array<string, mixed> $attributes
     */
    public function exit(
        SpanHandle $handle,
        array $attributes = [],
    ): void {
        // Pop until we find the handle. In well-formed code this is just the
        // top of the stack; we tolerate exit-out-of-order so a single missed
        // exit further up does not wedge the rest of the trace, but we warn
        // because any frames above the match get dropped without their span
        // events — that is silent data loss otherwise.
        $found = false;
        $top = \count($this->stack) - 1;
        for ($i = $top; $i >= 0; --$i) {
            if ($this->stack[$i] === $handle) {
                if ($i < $top) {
                    $dropped = [];
                    for ($j = $i + 1; $j <= $top; ++$j) {
                        $dropped[] = $this->stack[$j]->spanId;
                    }
                    $this->logger->warning('mesh0 Tracer::exit closed a non-top span; dropping inner frames', [
                        'closed_span_id' => $handle->spanId,
                        'dropped_count' => $top - $i,
                        'dropped_span_ids' => $dropped,
                        'trace_id' => $handle->traceId,
                    ]);
                }
                $this->stack = \array_slice($this->stack, 0, $i);
                $found = true;
                break;
            }
        }
        if (!$found) {
            // Double-exit, exit on a stale handle from a previous request, or
            // exit on a handle from a different Tracer instance. The span event
            // is dropped — make the misuse traceable so the pattern is visible
            // in long-lived workers.
            $this->logger->warning('mesh0 Tracer::exit called with unknown span; ignoring', [
                'span_id' => $handle->spanId,
                'trace_id' => $handle->traceId,
                'started_at' => $handle->startedAt->format('Y-m-d\\TH:i:s.v\\Z'),
            ]);
            return;
        }

        $merged = \array_merge($handle->attributes, $attributes);

        $builder = (new EventBuilder($handle->startedAt))
            ->withTraceId($handle->traceId)
            ->withSpan($handle->spanId, $handle->parentSpanId);
        if ($merged !== []) {
            $builder = $builder->withAttributes($merged);
        }

        try {
            $this->sink->send($builder);
        } catch (Throwable $e) {
            // The stack invariant is the load-bearing thing — once we've popped
            // the handle, we cannot let a sink failure unwind out of exit()
            // and corrupt outer spans. Surface the loss instead.
            $this->logger->error('mesh0 Tracer sink send failed; span event lost', [
                'exception' => $e,
                'trace_id' => $handle->traceId,
                'span_id' => $handle->spanId,
            ]);
        }
    }

    /**
     * Run `$fn` inside a new span. The span emits an event regardless of
     * whether `$fn` returns or throws; on throw the exception is
     * re-thrown unchanged.
     *
     * No attributes are added on the error path — if you want a
     * `status` or `error.type` / `error.message` recorded, use the
     * manual form ({@see enter()} + {@see exit()}) and pass them
     * yourself.
     *
     * @template T
     * @param array<string, mixed> $attributes
     * @param callable(): T $fn
     * @return T
     */
    public function span(array $attributes, callable $fn): mixed
    {
        $h = $this->enter($attributes);
        try {
            $result = $fn();
        } catch (Throwable $e) {
            // Don't let a throwing exit() mask the original — the span is
            // best-effort, the user code's exception is the real story.
            try {
                $this->exit($h);
            } catch (Throwable $exitErr) {
                $this->logger->error('mesh0 Tracer::exit failed; span event lost', [
                    'exception' => $exitErr,
                    'trace_id' => $h->traceId,
                    'span_id' => $h->spanId,
                ]);
            }
            throw $e;
        }
        try {
            $this->exit($h);
        } catch (Throwable $exitErr) {
            $this->logger->error('mesh0 Tracer::exit failed; span event lost', [
                'exception' => $exitErr,
                'trace_id' => $h->traceId,
                'span_id' => $h->spanId,
            ]);
        }
        return $result;
    }

    /**
     * Drop all in-flight trace state. Long-lived workers MUST call this
     * between requests. Warns through the configured PSR-3 logger if the
     * stack was non-empty (a missed `exit()` somewhere upstream).
     */
    public function reset(): void
    {
        if ($this->stack !== []) {
            $this->logger->warning('mesh0 Tracer reset with non-empty stack; dropping in-flight spans', [
                'depth' => \count($this->stack),
                'trace_id' => $this->traceId,
            ]);
        }
        $this->stack = [];
        $this->traceId = null;
        $this->incomingParent = null;
    }

    private static function generateTraceId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    private static function generateSpanId(): string
    {
        return \bin2hex(\random_bytes(8));
    }
}
