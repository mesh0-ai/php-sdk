<?php

declare(strict_types=1);

namespace Mesh0\Logger;

use Mesh0\Client;
use Mesh0\Event\Event;
use Mesh0\Event\Status;
use Mesh0\Trace\Tracer;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
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
 * when `flush()` is called, or on shutdown. Delivery is at-most-once:
 * records buffered when the process exits via `pcntl` signal, OOM, or
 * a fatal in a C extension are not flushed. Provide a `$fallback`
 * PSR-3 logger if you want visibility into delivery failures.
 *
 * The shutdown handler is registered lazily on first use so importing
 * the class has no side effects.
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
 * `parent_span_id` is only emitted when `span_id` is also present —
 * a parent without a self-span is meaningless on the wire.
 *
 * Status is `error` if an `exception` is supplied or the level is
 * `error` or higher; otherwise it is left unset (so dashboards
 * filtering for `status=success` aren't polluted by debug/info logs).
 *
 * The `defaults` map and any non-reserved context keys are merged into
 * `attributes`. Per-call context wins over `defaults`. Conventional
 * keys used: `log.level`, `message`, and (when an exception is
 * supplied) `error.type` / `error.message`. These four are written
 * last and will overwrite same-named entries from caller context.
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

    private readonly LoggerInterface $fallback;

    /**
     * @param array<string, mixed> $defaults
     * @param LoggerInterface|null $fallback PSR-3 logger that receives diagnostics about
     *   swallowed delivery errors and malformed caller input. Defaults to NullLogger so
     *   misconfigured callers stay silent unless they opt in.
     */
    public function __construct(
        private readonly Client $client,
        private readonly int $bufferSize = 50,
        private readonly array $defaults = [],
        private readonly string $minimumLevel = LogLevel::DEBUG,
        private readonly ?Tracer $tracer = null,
        ?LoggerInterface $fallback = null,
    ) {
        $this->fallback = $fallback ?? new NullLogger();
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::LEVEL_RANK[$level])) {
            // PSR-3 §1.1: implementations MUST throw on unknown levels.
            throw new InvalidArgumentException(sprintf(
                'Invalid PSR-3 log level: %s',
                is_string($level) ? "'{$level}'" : get_debug_type($level),
            ));
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
        } catch (Throwable $e) {
            // Logger must not throw — a delivery failure becomes a fallback log
            // instead so operators can see why telemetry vanished. Callers who
            // need delivery guarantees should use `events->send` directly.
            $this->fallback->error('mesh0 logger flush dropped batch', [
                'exception' => $e,
                'count' => count($batch),
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function toEvent(string $level, string $rawMessage, array $context): Event
    {
        $message = $this->interpolate($rawMessage, $context);

        $eventId = $this->reservedString($context, 'event_id');
        $traceId = $this->reservedString($context, 'trace_id');
        $spanId = $this->reservedString($context, 'span_id');
        $parentSpanId = $this->reservedString($context, 'parent_span_id');

        // Fall back to the active span on the bound Tracer when the caller
        // didn't supply trace context explicitly. Logs inside a $tracer->span()
        // closure then auto-correlate without per-call boilerplate.
        if ($this->tracer !== null) {
            $traceId ??= $this->tracer->currentTraceId();
            $spanId ??= $this->tracer->currentSpanId();
        }

        $duration = $context['duration_ms'] ?? null;
        $durationMs = is_int($duration) || is_float($duration) ? (float) $duration : null;
        if ($duration !== null && $durationMs === null) {
            $this->fallback->warning('mesh0 logger: dropping malformed duration_ms', [
                'expected' => 'int|float',
                'got' => get_debug_type($duration),
            ]);
        }

        $rawException = $context['exception'] ?? null;
        $exception = $rawException instanceof Throwable ? $rawException : null;
        if ($rawException !== null && $exception === null) {
            $this->fallback->warning('mesh0 logger: dropping malformed exception context value', [
                'expected' => Throwable::class,
                'got' => get_debug_type($rawException),
            ]);
        }
        $isError = $exception !== null
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
        if ($exception !== null) {
            $attributes['error.type'] = $exception::class;
            $attributes['error.message'] = $exception->getMessage();
        }

        $builder = Event::now()->withAttributes($attributes);
        if ($isError) {
            $builder = $builder->withStatus(Status::Error);
        }

        if ($eventId !== null) {
            $builder = $builder->withEventId($eventId);
        }
        if ($traceId !== null) {
            $builder = $builder->withTraceId($traceId);
        }
        if ($spanId !== null) {
            $builder = $builder->withSpan($spanId, $parentSpanId);
        } elseif ($parentSpanId !== null) {
            // A parent without a self-span is undeliverable — surface so
            // callers can spot the misuse instead of silently losing it.
            $this->fallback->warning('mesh0 logger: dropping parent_span_id with no span_id', [
                'parent_span_id' => $parentSpanId,
            ]);
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
                continue;
            }
            // Make non-stringable values visible in the rendered message
            // instead of silently leaving the placeholder text behind, which
            // looks like the renderer is broken.
            $replacements['{' . $key . '}'] = '<non-stringable ' . get_debug_type($value) . '>';
        }
        return strtr($message, $replacements);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reservedString(array $context, string $key): ?string
    {
        if (!array_key_exists($key, $context)) {
            return null;
        }
        $value = $context[$key];
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }
        $this->fallback->warning('mesh0 logger: dropping malformed reserved context key', [
            'key' => $key,
            'expected' => 'non-empty string',
            'got' => get_debug_type($value),
        ]);
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
            // upper bound as a regular send. Wrap the closure body so a
            // throwable raised by the underlying client during shutdown
            // (e.g. a finalised PSR-18 client) becomes a fallback log
            // instead of a fatal during request teardown.
            try {
                $this->flush();
            } catch (Throwable $e) {
                $this->fallback->error('mesh0 logger shutdown flush failed', ['exception' => $e]);
            }
        });
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (Throwable $e) {
            $this->fallback->error('mesh0 logger destructor flush failed', ['exception' => $e]);
        }
    }
}
