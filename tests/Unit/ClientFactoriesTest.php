<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Mesh0\Client;
use Mesh0\Config;
use Mesh0\Logger\Mesh0Logger;
use Mesh0\Tests\Support\MockHttpClient;
use Mesh0\Trace\Tracer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the small `Client::logger()` / `Client::tracer()` factory surface.
 * Behavior of the underlying classes is exercised in their own test files.
 */
final class ClientFactoriesTest extends TestCase
{
    private MockHttpClient $mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
    }

    private function client(): Client
    {
        $factory = new HttpFactory();
        return new Client(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        );
    }

    public function testLoggerFactoryReturnsMesh0Logger(): void
    {
        $logger = $this->client()->logger(appId: 'web', environment: 'prod');
        $this->assertInstanceOf(Mesh0Logger::class, $logger);
    }

    public function testLoggerFactoryThreadsTracerThroughToLogger(): void
    {
        $client = $this->client();
        $tracer = $client->tracer(appId: 'web');
        $logger = $client->logger(bufferSize: 1, tracer: $tracer);

        $h = $tracer->enter('block.execute');
        $this->mock->queueJson(200, ['accepted' => 1]);
        $logger->info('inside the span');
        $tracer->exit($h);

        $event = $this->mock->lastEvent();
        $this->assertSame($h->traceId, $event['trace_id']);
        $this->assertSame($h->spanId, $event['span_id']);
    }

    public function testTracerFactoryReturnsTracerWithUdpSink(): void
    {
        $tracer = $this->client()->tracer(appId: 'web', environment: 'prod');

        $this->assertInstanceOf(Tracer::class, $tracer);
        $this->assertNull($tracer->currentTraceId());
        $this->assertFalse($tracer->hasOpenSpan());
    }
}
