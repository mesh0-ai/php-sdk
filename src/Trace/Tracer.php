<?php

declare(strict_types=1);

namespace Mesh0\Trace;

use DateTimeImmutable;
use Mesh0\Event\EventBuilder;
use Mesh0\Event\EventSink;
use Mesh0\Event\Status;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Builds a trace tree from nested span enter/exit calls and ships each span
 * to a sink as an independent event.
 *
 * Each span produces exactly one event on `exit()`, carrying `trace_id`,
 * `span_id`, `parent_span_id`, and `duration_ms`. Spans are independent on
 * the wire — there are no "session start"/"session end" markers; the trace
 * is reassembled server-side by `trace_id`.
 *
 * Closure form (recommended — exception-safe):
 *
 * ```php
 * $result = $tracer->span('block.if', ['block_id' => 'b_123'], function () use ($tracer) {
 *     return $tracer->span('block.http_request', ['url' => $url], fn () => $client->get($url));
 * });
 * ```
 *
 * Manual form (when a closure does not fit, e.g. block dispatchers that
 * resume across stack frames):
 *
 * ```php
 * $h = $tracer->enter('block.loop', ['block_id' => 'b_456']);
 * try {
 *     // run block
 *     $tracer->exit($h, attributes: ['iterations' => $n]);
 * } catch (\Throwable $e) {
 *     $tracer->exitWithException($h, $e);
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
        private readonly ?string $appId = null,
        private readonly ?string $environment = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Adopt an incoming W3C traceparent header for the next root span.
     *
     * Must be called before the first {@see enter()} of the trace; later calls
     * are ignored and return `false`. Format:
     * `00-<32 hex traceId>-<16 hex parentSpanId>-<2 hex flags>`.
     *
     * Returns `true` if the header was parsed and adopted.
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
     * Open a new span. The returned handle must be passed to {@see exit()} or
     * {@see exitWithException()} to close it.
     *
     * @param array<string, mixed> $attributes
     */
    public function enter(string $operation, array $attributes = []): SpanHandle
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
            operation: $operation,
            startedAt: new DateTimeImmutable(),
            startedHrTimeNs: \hrtime(true),
            attributes: $attributes,
        );
        $this->stack[] = $handle;
        return $handle;
    }

    /**
     * Close a span on the success path.
     *
     * @param array<string, mixed> $attributes Merged on top of the attributes passed to {@see enter()}.
     */
    public function exit(
        SpanHandle $handle,
        Status $status = Status::Success,
        array $attributes = [],
    ): void {
        $this->finish($handle, $status, $attributes, null, null);
    }

    /**
     * Close a span on the error path, capturing the exception type and message.
     *
     * @param array<string, mixed> $attributes Merged on top of the attributes passed to {@see enter()}.
     */
    public function exitWithException(SpanHandle $handle, Throwable $e, array $attributes = []): void
    {
        $this->finish($handle, Status::Error, $attributes, $e::class, $e->getMessage());
    }

    /**
     * Run `$fn` inside a new span. The span exits with `success` if `$fn`
     * returns, or `error` if it throws (the exception is re-thrown).
     *
     * @template T
     * @param array<string, mixed> $attributes
     * @param callable(): T $fn
     * @return T
     */
    public function span(string $operation, array $attributes, callable $fn): mixed
    {
        $h = $this->enter($operation, $attributes);
        try {
            $result = $fn();
        } catch (Throwable $e) {
            $this->exitWithException($h, $e);
            throw $e;
        }
        $this->exit($h);
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

    /**
     * @param array<string, mixed> $extraAttributes
     */
    private function finish(
        SpanHandle $handle,
        Status $status,
        array $extraAttributes,
        ?string $errorType,
        ?string $errorMessage,
    ): void {
        // Pop until we find the handle. In well-formed code this is just the
        // top of the stack; we tolerate exit-out-of-order so a single missed
        // exit further up does not wedge the rest of the trace.
        $found = false;
        for ($i = \count($this->stack) - 1; $i >= 0; --$i) {
            if ($this->stack[$i] === $handle) {
                $this->stack = \array_slice($this->stack, 0, $i);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $this->logger->warning('mesh0 Tracer::exit called with unknown span; ignoring', [
                'span_id' => $handle->spanId,
            ]);
            return;
        }

        $durationMs = (\hrtime(true) - $handle->startedHrTimeNs) / 1_000_000.0;
        $attributes = $extraAttributes === []
            ? $handle->attributes
            : \array_merge($handle->attributes, $extraAttributes);

        $builder = (new EventBuilder($handle->startedAt))
            ->withTraceId($handle->traceId)
            ->withSpan($handle->spanId, $handle->parentSpanId)
            ->withOperation($handle->operation)
            ->withDurationMs($durationMs)
            ->withStatus($status);

        if ($this->appId !== null || $this->environment !== null) {
            $builder = $builder->withApp($this->appId ?? '', $this->environment);
        }
        if ($attributes !== []) {
            $builder = $builder->withAttributes($attributes);
        }
        if ($errorType !== null && $errorMessage !== null) {
            $builder = $builder->withError($errorType, $errorMessage);
        }

        $this->sink->send($builder);
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
