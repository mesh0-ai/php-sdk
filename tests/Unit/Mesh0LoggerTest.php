<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Mesh0\Client;
use Mesh0\Config;
use Mesh0\Logger\Mesh0Logger;
use Mesh0\Tests\Support\InMemoryEventSink;
use Mesh0\Tests\Support\MockHttpClient;
use Mesh0\Trace\Tracer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class Mesh0LoggerTest extends TestCase
{
    private MockHttpClient $mock;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->client = new Client(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        );
    }

    public function testInfoIsBufferedUntilFlush(): void
    {
        $logger = new Mesh0Logger(
            client: $this->client,
            bufferSize: 10,
            defaults: ['app.id' => 'web', 'app.environment' => 'prod'],
        );

        $logger->info('user {user} signed up', ['user' => 'alice', 'plan' => 'pro']);
        $this->assertCount(0, $this->mock->requests);

        $this->mock->queueJson(200, ['accepted' => 1]);
        $logger->flush();

        $this->assertCount(1, $this->mock->requests);
        $event = $this->mock->lastEvent();
        $this->assertSame('success', $event['status']);
        $attributes = $event['attributes'];
        $this->assertIsArray($attributes);
        $this->assertSame('user alice signed up', $attributes['message']);
        $this->assertSame('pro', $attributes['plan']);
        $this->assertSame('web', $attributes['app.id']);
        $this->assertSame('prod', $attributes['app.environment']);
        $this->assertSame(LogLevel::INFO, $attributes['log.level']);
    }

    public function testErrorWithExceptionMapsErrorAttributes(): void
    {
        $logger = new Mesh0Logger(client: $this->client, bufferSize: 1);

        $this->mock->queueJson(200, ['accepted' => 1]);
        $logger->error('charge failed', ['exception' => new \RuntimeException('boom'), 'order_id' => 'ord_1']);

        $event = $this->mock->lastEvent();
        $this->assertSame('error', $event['status']);
        $attributes = $event['attributes'];
        $this->assertIsArray($attributes);
        $this->assertSame('RuntimeException', $attributes['error.type']);
        $this->assertSame('boom', $attributes['error.message']);
        $this->assertSame('ord_1', $attributes['order_id']);
    }

    public function testTraceContextIsLifted(): void
    {
        $logger = new Mesh0Logger(client: $this->client, bufferSize: 1);
        $this->mock->queueJson(200, ['accepted' => 1]);

        $logger->info('ok', [
            'trace_id' => 'tr-1',
            'span_id' => 'sp-1',
            'parent_span_id' => 'sp-0',
            'duration_ms' => 12.5,
            'user_id' => 'u-1',
            'session_id' => 's-1',
        ]);

        $event = $this->mock->lastEvent();
        // Wire-shape fields are lifted to top-level.
        $this->assertSame('tr-1', $event['trace_id']);
        $this->assertSame('sp-1', $event['span_id']);
        $this->assertSame('sp-0', $event['parent_span_id']);
        $this->assertSame(12.5, $event['duration_ms']);

        $attributes = $event['attributes'];
        $this->assertIsArray($attributes);
        // user_id/session_id aren't wire fields anymore — they ride in attributes.
        $this->assertSame('u-1', $attributes['user_id']);
        $this->assertSame('s-1', $attributes['session_id']);
        // The lifted wire-shape keys must not also leak into attributes.
        $this->assertArrayNotHasKey('trace_id', $attributes);
        $this->assertArrayNotHasKey('span_id', $attributes);
        $this->assertArrayNotHasKey('parent_span_id', $attributes);
        $this->assertArrayNotHasKey('duration_ms', $attributes);
    }

    public function testTracerProvidesTraceContextWhenAbsentFromCallerContext(): void
    {
        $tracer = new Tracer(new InMemoryEventSink());
        $logger = new Mesh0Logger(client: $this->client, bufferSize: 1, tracer: $tracer);

        $h = $tracer->enter('block.execute');
        $this->mock->queueJson(200, ['accepted' => 1]);
        $logger->info('inside the span');

        $event = $this->mock->lastEvent();
        $this->assertSame($tracer->currentTraceId(), $event['trace_id']);
        $this->assertSame($h->spanId, $event['span_id']);

        $tracer->exit($h);
    }

    public function testCallerContextOverridesTracerWhenBothAreSet(): void
    {
        $tracer = new Tracer(new InMemoryEventSink());
        $logger = new Mesh0Logger(client: $this->client, bufferSize: 1, tracer: $tracer);

        $h = $tracer->enter('block.execute');
        $this->mock->queueJson(200, ['accepted' => 1]);
        $logger->info('explicit override', ['trace_id' => 'tr-explicit', 'span_id' => 'sp-explicit']);

        $event = $this->mock->lastEvent();
        $this->assertSame('tr-explicit', $event['trace_id']);
        $this->assertSame('sp-explicit', $event['span_id']);

        $tracer->exit($h);
    }

    public function testMinimumLevelFiltersLowerSeverities(): void
    {
        $logger = new Mesh0Logger(
            client: $this->client,
            bufferSize: 10,
            minimumLevel: LogLevel::WARNING,
        );

        $logger->debug('hidden');
        $logger->info('hidden');
        $logger->warning('shown');
        $logger->flush();

        // No flush ever happened above warning's buffer: only one record was buffered.
        $this->mock->queueJson(200, ['accepted' => 1]);
        $logger->warning('and another');
        $logger->flush();

        $this->assertGreaterThanOrEqual(1, count($this->mock->requests));
    }
}
