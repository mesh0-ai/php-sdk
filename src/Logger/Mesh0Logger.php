<?php

declare(strict_types=1);

namespace Mesh0\Logger;

use Mesh0\Client;
use Mesh0\Event\Event;
use Mesh0\Event\Status;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * PSR-3 logger that ships log records to mesh0 as events.
 *
 * Drop into any PSR-3 aware framework (Laravel, Symfony, Slim, …) and your
 * logs become first-class telemetry — searchable, queryable via TQL, and
 * tied to traces if you pass `trace_id` in the context.
 *
 * Records are buffered in memory and flushed when the buffer fills, when
 * `flush()` is called, or on shutdown. The shutdown handler is registered
 * lazily on first use so importing the class has no side effects.
 *
 * ### Special context keys
 *
 * The following keys are extracted from the PSR-3 context and mapped onto
 * the corresponding event fields rather than dumped into `attributes`:
 *
 * - `trace_id`, `span_id`, `parent_span_id` → trace correlation
 * - `user_id`, `session_id`                  → user attribution
 * - `operation`                              → event operation name
 * - `duration_ms`                            → event duration
 * - `exception` (Throwable)                  → mapped to error_type / error_message
 * - everything else                          → merged into `attributes`
 *
 * The PSR-3 message is interpolated per the spec (`{key}` placeholders) and
 * stored under `attributes.message`.
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

    /** @var list<Event> */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    /** @param array<string, mixed> $defaults */
    public function __construct(
        private readonly Client $client,
        private readonly ?string $appId = null,
        private readonly ?string $environment = null,
        private readonly int $bufferSize = 50,
        private readonly array $defaults = [],
        private readonly string $minimumLevel = LogLevel::DEBUG,
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

        $traceId = self::stringOrNull($context['trace_id'] ?? null);
        $spanId = self::stringOrNull($context['span_id'] ?? null);
        $parentSpanId = self::stringOrNull($context['parent_span_id'] ?? null);
        $userId = self::stringOrNull($context['user_id'] ?? null);
        $sessionId = self::stringOrNull($context['session_id'] ?? null);
        $operation = self::stringOrNull($context['operation'] ?? null) ?? 'log.' . $level;
        $duration = $context['duration_ms'] ?? null;
        $durationMs = is_int($duration) || is_float($duration) ? (float) $duration : null;

        $exception = $context['exception'] ?? null;
        $errorType = null;
        $errorMessage = null;
        if ($exception instanceof Throwable) {
            $errorType = $exception::class;
            $errorMessage = $exception->getMessage();
        }

        $isError = $errorType !== null
            || self::LEVEL_RANK[$level] >= self::LEVEL_RANK[LogLevel::ERROR];

        $reserved = ['trace_id', 'span_id', 'parent_span_id', 'user_id', 'session_id', 'operation', 'duration_ms', 'exception'];
        $attributes = $this->defaults;
        foreach ($context as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }
            $attributes[(string) $key] = $value;
        }
        $attributes['log.level'] = $level;
        $attributes['message'] = $message;

        $builder = Event::now()
            ->withOperation($operation)
            ->withStatus($isError ? Status::Error : Status::Success);

        if ($this->appId !== null || $this->environment !== null) {
            $builder = $builder->withApp($this->appId ?? '', $this->environment);
        }
        if ($traceId !== null) {
            $builder = $builder->withTraceId($traceId);
        }
        if ($spanId !== null) {
            $builder = $builder->withSpan($spanId, $parentSpanId);
        }
        if ($userId !== null) {
            $builder = $builder->withUser($userId);
        }
        if ($sessionId !== null) {
            $builder = $builder->withSession($sessionId);
        }
        if ($durationMs !== null) {
            $builder = $builder->withDurationMs($durationMs);
        }
        if ($errorType !== null && $errorMessage !== null) {
            $builder = $builder->withError($errorType, $errorMessage);
        }
        $builder = $builder->withAttributes($attributes);

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
