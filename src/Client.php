<?php

declare(strict_types=1);

namespace Mesh0;

use Mesh0\Http\Transport;
use Mesh0\Logger\Mesh0Logger;
use Mesh0\Metrics\Metrics;
use Mesh0\Metrics\MetricSink;
use Mesh0\Metrics\UdpMetricSink;
use Mesh0\Resource\Events;
use Mesh0\Resource\Meta;
use Mesh0\Resource\Query;
use Mesh0\Resource\Traces;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Top-level entry point for the mesh0 PHP SDK.
 *
 * Construct once and reuse — the client is stateless beyond its config and
 * the underlying PSR-18 client. Resource sub-clients are exposed as
 * properties; you can keep references to them or fetch them lazily through
 * the typed accessors.
 *
 * @example
 *   $mesh0 = Mesh0\Client::create('m0_abc12_…');
 *   $mesh0->events()->send(
 *       Mesh0\Event\Event::now()
 *           ->withApp('checkout', 'prod')
 *           ->withOperation('charge.succeeded')
 *           ->withAttributes(['order_id' => 'ord_123']),
 *   );
 */
final class Client
{
    public readonly Events $events;
    public readonly Traces $traces;
    public readonly Query $query;
    public readonly Meta $meta;

    private readonly Transport $transport;
    private ?Metrics $metrics = null;

    public function __construct(
        public readonly Config $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->transport = new Transport($config, $http, $requestFactory, $streamFactory);
        $this->events = new Events($this->transport);
        $this->traces = new Traces($this->transport);
        $this->query = new Query($this->transport);
        $this->meta = new Meta($this->transport);
    }

    /** Convenience: build a client from an API key string and (optional) base URL. */
    public static function create(string $apiKey, ?string $baseUrl = null): self
    {
        return new self(
            new Config(
                apiKey: $apiKey,
                baseUrl: $baseUrl ?? Config::DEFAULT_BASE_URL,
            ),
        );
    }

    /** Convenience: build a client from environment variables (`MESH0_API_KEY`, `MESH0_BASE_URL`). */
    public static function fromEnv(): self
    {
        return new self(Config::fromEnv());
    }

    public function events(): Events
    {
        return $this->events;
    }

    public function traces(): Traces
    {
        return $this->traces;
    }

    public function query(): Query
    {
        return $this->query;
    }

    public function meta(): Meta
    {
        return $this->meta;
    }

    /**
     * Return a metrics client targeting a co-located mesh0 metrics-agent.
     *
     * The agent listens on UDP (default `127.0.0.1:8125`) and forwards
     * counters, gauges, and timings to mesh0 over HTTPS. The UDP socket is
     * opened lazily on the first `send()` so calling this method does no I/O.
     *
     * Subsequent calls without arguments return the same instance. Pass `host`
     * or `port` to build a fresh `Metrics` against a different agent (or pass
     * a custom `MetricSink` to bypass UDP entirely, e.g. in tests).
     *
     * Defaults read from `Config::metricsAgentHost` / `metricsAgentPort` (which
     * in turn pick up `MESH0_AGENT_HOST` / `MESH0_AGENT_PORT` via
     * `Config::fromEnv()`).
     *
     * @param array<string, string|int|float> $defaultTags Tags merged into every metric.
     */
    public function metrics(
        ?string $host = null,
        ?int $port = null,
        array $defaultTags = [],
        ?MetricSink $sink = null,
    ): Metrics {
        if ($sink !== null || $host !== null || $port !== null || $defaultTags !== []) {
            $effectiveSink = $sink ?? new UdpMetricSink(
                $host ?? $this->config->metricsAgentHost,
                $port ?? $this->config->metricsAgentPort,
            );
            return new Metrics($effectiveSink, $defaultTags);
        }
        return $this->metrics ??= new Metrics(new UdpMetricSink(
            $this->config->metricsAgentHost,
            $this->config->metricsAgentPort,
        ));
    }

    /**
     * Build a PSR-3 logger backed by this client.
     *
     * Logs are buffered in memory and flushed on `flush()` / on shutdown / when
     * the buffer fills. See {@see Mesh0Logger} for the full set of options.
     *
     * @param array<string, mixed> $defaults Default attributes merged into every record.
     */
    public function logger(
        ?string $appId = null,
        ?string $environment = null,
        int $bufferSize = 50,
        array $defaults = [],
    ): LoggerInterface {
        return new Mesh0Logger(
            client: $this,
            appId: $appId,
            environment: $environment,
            bufferSize: $bufferSize,
            defaults: $defaults,
        );
    }
}
