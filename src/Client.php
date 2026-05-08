<?php

declare(strict_types=1);

namespace Mesh0;

use Mesh0\Http\Transport;
use Mesh0\Logger\Mesh0Logger;
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
