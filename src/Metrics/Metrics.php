<?php

declare(strict_types=1);

namespace Mesh0\Metrics;

use Mesh0\Exception\ConfigurationException;

/**
 * Thin statsd / DogStatsD client targeting the mesh0 metrics-agent.
 *
 * The agent (see `mesh0/metrics-agent`) listens on a Unix datagram
 * socket and aggregates counters, gauges, and timings before forwarding
 * them to mesh0 over HTTPS. This class only formats and emits one-shot
 * datagrams — there is no in-process aggregation, buffering, or retry.
 * That's intentional: the request-scoped PHP runtime stays free of
 * background work, and durability lives in the long-running agent.
 *
 * Wire format (one line per metric, `\n`-separated within a packet):
 *
 *     name:value|type[|@sample_rate][|#k1:v1,k2:v2]
 *
 * | type | meaning            |
 * |------|--------------------|
 * | `c`  | counter            |
 * | `g`  | gauge (last write) |
 * | `ms` | timing (ms)        |
 * | `h`  | histogram          |
 * | `d`  | distribution       |
 *
 * Validation: malformed metric names or tags throw `ConfigurationException`
 * — these are programmer errors that should fail loudly in development. The
 * underlying {@see MetricSink} is fire-and-forget for *transport* failures;
 * format errors surface as exceptions because silently dropping them would
 * make missing telemetry untraceable. `sampleRate` outside `(0, 1]` is
 * clamped (>=1 always emits, <=0 never emits) rather than throwing.
 *
 * @example
 *   $metrics = $client->metrics();
 *   $metrics->increment('checkout.charge', tags: ['tier' => 'pro']);
 *   $metrics->gauge('queue.depth', 42);
 *   $metrics->timing('db.query_ms', 12.4, tags: ['table' => 'orders']);
 */
final class Metrics
{
    /**
     * @param array<string, string|int|float> $defaultTags Tags merged into every metric (per-call tags win on conflict).
     */
    public function __construct(
        private readonly MetricSink $sink,
        private readonly array $defaultTags = [],
    ) {
        foreach ($defaultTags as $k => $v) {
            self::assertTagKey((string) $k);
            self::assertTagValue((string) $v);
        }
    }

    /**
     * Build a `Metrics` over an `AgentMetricSink` pointing at a local
     * metrics-agent's Unix datagram socket.
     *
     * @param array<string, string|int|float> $defaultTags
     * @throws ConfigurationException when `$socketPath` is not absolute or
     *         exceeds the 104-byte sun_path floor.
     */
    public static function agent(string $socketPath, array $defaultTags = []): self
    {
        return new self(new AgentMetricSink($socketPath), $defaultTags);
    }

    /**
     * Increment a counter by `$value` (default 1).
     *
     * @param array<string, string|int|float> $tags
     */
    public function increment(string $name, int|float $value = 1, array $tags = [], float $sampleRate = 1.0): void
    {
        $this->emit($name, $value, 'c', $tags, $sampleRate);
    }

    /**
     * Decrement a counter by `$value` (default 1). Convenience for negative `count()`.
     *
     * @param array<string, string|int|float> $tags
     */
    public function decrement(string $name, int|float $value = 1, array $tags = [], float $sampleRate = 1.0): void
    {
        $this->emit($name, -$value, 'c', $tags, $sampleRate);
    }

    /**
     * Record an absolute counter value.
     *
     * @param array<string, string|int|float> $tags
     */
    public function count(string $name, int|float $value, array $tags = [], float $sampleRate = 1.0): void
    {
        $this->emit($name, $value, 'c', $tags, $sampleRate);
    }

    /**
     * Set a gauge (last-write-wins per series per flush window).
     *
     * @param array<string, string|int|float> $tags
     */
    public function gauge(string $name, int|float $value, array $tags = []): void
    {
        $this->emit($name, $value, 'g', $tags, 1.0);
    }

    /**
     * Record a timing in milliseconds.
     *
     * @param array<string, string|int|float> $tags
     */
    public function timing(string $name, int|float $milliseconds, array $tags = [], float $sampleRate = 1.0): void
    {
        $this->emit($name, $milliseconds, 'ms', $tags, $sampleRate);
    }

    /**
     * Record a histogram sample (alias for `ms` server-side).
     *
     * @param array<string, string|int|float> $tags
     */
    public function histogram(string $name, int|float $value, array $tags = [], float $sampleRate = 1.0): void
    {
        $this->emit($name, $value, 'h', $tags, $sampleRate);
    }

    /**
     * Record a distribution sample (alias for `ms` server-side).
     *
     * @param array<string, string|int|float> $tags
     */
    public function distribution(string $name, int|float $value, array $tags = [], float $sampleRate = 1.0): void
    {
        $this->emit($name, $value, 'd', $tags, $sampleRate);
    }

    /**
     * Time `$fn` and emit a timing metric in milliseconds. Returns `$fn`'s return value.
     *
     * The timing is recorded whether `$fn` returns or throws.
     *
     * @template T
     * @param callable():T $fn
     * @param array<string, string|int|float> $tags
     * @return T
     */
    public function time(string $name, callable $fn, array $tags = [], float $sampleRate = 1.0): mixed
    {
        $start = hrtime(true);
        try {
            return $fn();
        } finally {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
            $this->timing($name, $elapsedMs, $tags, $sampleRate);
        }
    }

    /** Release the underlying sink. */
    public function close(): void
    {
        $this->sink->close();
    }

    /**
     * @param array<string, string|int|float> $tags
     */
    private function emit(string $name, int|float $value, string $type, array $tags, float $sampleRate): void
    {
        if ($sampleRate <= 0.0) {
            return;
        }
        if ($sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) > $sampleRate) {
            return;
        }
        $this->sink->send($this->format($name, $value, $type, $tags, $sampleRate));
    }

    /**
     * @param array<string, string|int|float> $tags
     */
    private function format(string $name, int|float $value, string $type, array $tags, float $sampleRate): string
    {
        self::assertName($name);

        $line = $name . ':' . self::formatValue($value) . '|' . $type;
        if ($sampleRate < 1.0) {
            $line .= '|@' . self::formatValue($sampleRate);
        }

        $merged = $this->defaultTags;
        foreach ($tags as $k => $v) {
            $merged[$k] = $v;
        }
        if ($merged !== []) {
            $rendered = [];
            foreach ($merged as $k => $v) {
                $key = (string) $k;
                $val = (string) $v;
                self::assertTagKey($key);
                self::assertTagValue($val);
                $rendered[] = $val === '' ? $key : ($key . ':' . $val);
            }
            $line .= '|#' . implode(',', $rendered);
        }
        return $line;
    }

    private static function formatValue(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_finite($value)) {
            throw new ConfigurationException('metric value must be finite');
        }
        // Locale-independent float rendering; trims trailing zeros.
        $s = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    private static function assertName(string $name): void
    {
        if ($name === '') {
            throw new ConfigurationException('metric name must not be empty');
        }
        if (preg_match('/[\s:|@#,]/', $name) === 1) {
            throw new ConfigurationException('metric name may not contain whitespace or any of : | @ # ,');
        }
    }

    private static function assertTagKey(string $key): void
    {
        if ($key === '') {
            throw new ConfigurationException('tag key must not be empty');
        }
        if (preg_match('/[\s:|#,]/', $key) === 1) {
            throw new ConfigurationException('tag key may not contain whitespace or any of : | # ,');
        }
    }

    private static function assertTagValue(string $value): void
    {
        if (preg_match('/[\s|#,]/', $value) === 1) {
            throw new ConfigurationException('tag value may not contain whitespace or any of | # ,');
        }
    }
}
