<?php

declare(strict_types=1);

namespace Mesh0\Logger;

use Mesh0\Client;
use Mesh0\Event\Event;
use Mesh0\Event\Status;
use Mesh0\Trace\Tracer;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * PSR-3 logger that ships log records to mesh0 as events.
 *
 * Drop into any PSR-3 aware framework (Laravel, Symfony, Slim, …) and
 * your logs become first-class telemetry — searchable, queryable via
 * TQL, and tied to traces if you pass `trace_id` in the context.
 *
 * Records are buffered in memory and flushed when the buffer fills,
 * when `flush()` is called, or on shutdown. The shutdown handler is
 * registered lazily on first use so importing the class has no side
 * effects.
 *
 * ### Mapping
 *
 * The following PSR-3 context keys are lifted onto the corresponding
 * wire-level fields:
 *
 * - `event_id`                                 → top-level `event_id`
 * - `trace_id`, `span_id`, `parent_span_id`    → trace correlation
 * - `duration_ms`                              → top-level `duration_ms`
 *
 * Status is `error` if an `exception` is supplied or the level is
 * `error` or higher; otherwise `success`. Everything else — including
 * `level`, the interpolated message, the `defaults` map, and any
 * remaining context keys — is merged into `attributes`. Conventional
 * keys used: `log.level`, `message`, and (when an exception is
 * supplied) `error.type` / `error.message`.
 */
final class Mesh0Logger extends AbstractLogger
{
    /** PSR-3 levels in ascending severity. */
    private const LEVEL_RANK = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** Context keys lifted to top-level wire fields. */
    private const RESERVED_CONTEXT_KEYS = [
        'event_id',
        'trace_id',
        'span_id',
        'parent_span_id',
        'duration_ms',
        'exception',
    ];

    /** @var list<Event> */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    /** @param array<string, mixed> $defaults */
    public function __construct(
        private readonly Client $client,
        private readonly int $bufferSize = 50,
        private readonly array $defaults = [],
        private readonly string $minimumLevel = LogLevel::DEBUG,
        private readonly ?Tracer $tracer = null,
    ) {
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::LEVEL_RANK[$level])) {
            $level = LogLevel::INFO;
        }
        if (self::LEVEL_RANK[$level] < (self::LEVEL_RANK[$this->minimumLevel] ?? 0)) {
            return;
        }

        $event = $this->toEvent($level, (string) $message, $context);
        $this->buffer[] = $event;

        if (count($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }
        $this->ensureShutdownFlush();
    }

    /** Force-flush buffered records. Safe to call repeatedly. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $batch = $this->buffer;
        $this->buffer = [];
        try {
            $this->client->events->sendMany($batch);
        } catch (Throwable) {
            // Logger must not throw — silently drop the batch. Callers who
            // need delivery guarantees should use `events->send` directly.
        }
    }

    /** @param array<string, mixed> $context */
    private function toEvent(string $level, string $rawMessage, array $context): Event
    {
        $message = $this->interpolate($rawMessage, $context);

        $eventId = self::stringOrNull($context['event_id'] ?? null);
        $traceId = self::stringOrNull($context['trace_id'] ?? null);
        $spanId = self::stringOrNull($context['span_id'] ?? null);
        $parentSpanId = self::stringOrNull($context['parent_span_id'] ?? null);

        // Fall back to the active span on the bound Tracer when the caller
        // didn't supply trace context explicitly. Logs inside a $tracer->span()
        // closure then auto-correlate without per-call boilerplate.
        if ($this->tracer !== null) {
            $traceId ??= $this->tracer->currentTraceId();
            $spanId ??= $this->tracer->currentSpanId();
        }

        $duration = $context['duration_ms'] ?? null;
        $durationMs = is_int($duration) || is_float($duration) ? (float) $duration : null;

        $exception = $context['exception'] ?? null;
        $isError = $exception instanceof Throwable
            || self::LEVEL_RANK[$level] >= self::LEVEL_RANK[LogLevel::ERROR];

        $attributes = $this->defaults;
        foreach ($context as $key => $value) {
            if (in_array($key, self::RESERVED_CONTEXT_KEYS, true)) {
                continue;
            }
            $attributes[(string) $key] = $value;
        }
        $attributes['log.level'] = $level;
        $attributes['message'] = $message;
        if ($exception instanceof Throwable) {
            $attributes['error.type'] = $exception::class;
            $attributes['error.message'] = $exception->getMessage();
        }

        $builder = Event::now()
            ->withStatus($isError ? Status::Error : Status::Success)
            ->withAttributes($attributes);

        if ($eventId !== null) {
            $builder = $builder->withEventId($eventId);
        }
        if ($traceId !== null) {
            $builder = $builder->withTraceId($traceId);
        }
        if ($spanId !== null) {
            $builder = $builder->withSpan($spanId, $parentSpanId);
        }
        if ($durationMs !== null) {
            $builder = $builder->withDurationMs($durationMs);
        }

        return $builder->build();
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replacements);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }

    private function ensureShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            // Bound the flush to a single attempt; the transport's own retry
            // policy still applies, so this is at-least-once with the same
            // upper bound as a regular send.
            $this->flush();
        });
    }

    public function __destruct()
    {
        $this->flush();
    }
}
