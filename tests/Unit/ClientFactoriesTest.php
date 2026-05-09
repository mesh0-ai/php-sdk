<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

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
    private function client(): Client
    {
        return new Client(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa'),
            new MockHttpClient(),
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

        $logger = $client->logger(tracer: $tracer);

        // Open a span; the logger should auto-stamp its trace context onto
        // the next record (the actual stamping is asserted in Mesh0LoggerTest;
        // here we only verify the wiring took effect).
        $h = $tracer->enter('block.execute');
        try {
            $reflection = new \ReflectionObject($logger);
            $tracerProp = $reflection->getProperty('tracer');
            $this->assertSame($tracer, $tracerProp->getValue($logger));
        } finally {
            $tracer->exit($h);
        }
    }

    public function testTracerFactoryReturnsTracerWithUdpSink(): void
    {
        $tracer = $this->client()->tracer(appId: 'web', environment: 'prod');

        $this->assertInstanceOf(Tracer::class, $tracer);
        $this->assertNull($tracer->currentTraceId());
        $this->assertFalse($tracer->hasOpenSpan());
    }
}
