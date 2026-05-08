<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use DateTimeImmutable;
use Mesh0\Config;
use Mesh0\Event\Event;
use Mesh0\Event\EventBuilder;
use Mesh0\Event\Status;
use Mesh0\Event\UdpEventSink;
use Mesh0\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class UdpEventSinkTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $host = '127.0.0.1';
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $errno = 0;
        $errstr = '';
        $server = @\stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND);
        if ($server === false) {
            $this->markTestSkipped("could not bind UDP loopback: {$errstr}");
        }
        $this->server = $server;
        $name = \stream_socket_get_name($server, false);
        if (!\is_string($name)) {
            $this->markTestSkipped('could not resolve bound UDP port');
        }
        $colon = \strrpos($name, ':');
        \assert($colon !== false);
        $this->port = (int) \substr($name, $colon + 1);
        \stream_set_blocking($server, false);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \fclose($this->server);
        }
        $this->server = null;
        parent::tearDown();
    }

    public function testDefaultsMatchConfigConstants(): void
    {
        $config = new Config(apiKey: 'm0_test_secret');
        $this->assertSame(UdpEventSink::DEFAULT_HOST, $config->metricsAgentHost);
        $this->assertSame(UdpEventSink::DEFAULT_PORT, $config->metricsAgentPort);
    }

    public function testConstructorPerformsNoIo(): void
    {
        // If construction did I/O against host.invalid, this would block on DNS.
        $start = \microtime(true);
        new UdpEventSink('host.invalid', 9999);
        $elapsed = \microtime(true) - $start;
        $this->assertLessThan(0.1, $elapsed, 'constructor must be lazy');
    }

    public function testSendAcceptsEventBuilder(): void
    {
        $sink = new UdpEventSink($this->host, $this->port);

        $builder = Event::now()
            ->withApp('checkout', 'prod')
            ->withOperation('charge.succeeded')
            ->withStatus(Status::Success)
            ->withAttribute('order_id', 'ord_123');

        $sink->send($builder);

        $packet = $this->receiveOnePacket();
        $this->assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('checkout', $decoded['app_id'] ?? null);
        $this->assertSame('prod', $decoded['environment'] ?? null);
        $this->assertSame('charge.succeeded', $decoded['operation'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertSame(['order_id' => 'ord_123'], $decoded['attributes'] ?? null);
        $sink->close();
    }

    public function testSendAcceptsBuiltEvent(): void
    {
        $sink = new UdpEventSink($this->host, $this->port);

        $event = new Event(
            timestamp: new DateTimeImmutable('2026-05-08T12:00:00Z'),
            traceId: 'trace-1',
            spanId: 'span-1',
            parentSpanId: 'span-0',
            appId: 'svc',
            operation: 'op.run',
            userId: 'u-1',
            sessionId: 's-1',
        );

        $sink->send($event);

        $packet = $this->receiveOnePacket();
        $this->assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('trace-1', $decoded['trace_id'] ?? null);
        $this->assertSame('span-1', $decoded['span_id'] ?? null);
        $this->assertSame('span-0', $decoded['parent_span_id'] ?? null);
        $this->assertSame('u-1', $decoded['user_id'] ?? null);
        $this->assertSame('s-1', $decoded['session_id'] ?? null);
        $sink->close();
    }

    public function testSendManyEmitsOneDatagramPerEvent(): void
    {
        $sink = new UdpEventSink($this->host, $this->port);

        $events = [
            Event::now()->withOperation('op.a'),
            Event::now()->withOperation('op.b'),
            Event::now()->withOperation('op.c'),
        ];
        $sink->sendMany($events);

        $ops = [];
        for ($i = 0; $i < 3; $i++) {
            $packet = $this->receiveOnePacket();
            $this->assertNotNull($packet, "expected datagram #{$i}");
            /** @var array<string, mixed> $decoded */
            $decoded = \json_decode($packet, true, flags: \JSON_THROW_ON_ERROR);
            $op = $decoded['operation'] ?? null;
            $this->assertIsString($op);
            $ops[] = $op;
        }
        \sort($ops);
        $this->assertSame(['op.a', 'op.b', 'op.c'], $ops);
        $this->assertNull($this->receiveOnePacket(50), 'no extra datagrams');
        $sink->close();
    }

    public function testOversizeEventIsDroppedWithSingleWarning(): void
    {
        $logger = new RecordingLogger();
        $sink = new UdpEventSink($this->host, $this->port, $logger);

        // ~40KB string → encoded JSON exceeds 32KB limit.
        $big = \str_repeat('x', 40_000);
        $event = Event::now()
            ->withOperation('huge')
            ->withAttribute('blob', $big);

        $sink->send($event);
        $sink->send($event); // second oversize should not log again

        $this->assertNull($this->receiveOnePacket(50), 'oversize datagram must not be sent');
        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'oversize warning fires exactly once');
        $this->assertStringContainsString('exceeds 32KB', $warnings[0]['message']);
        $sink->close();
    }

    public function testOversizeDoesNotThrow(): void
    {
        $sink = new UdpEventSink($this->host, $this->port);
        $event = Event::now()->withAttribute('blob', \str_repeat('x', 40_000));

        $sink->send($event);

        $this->expectNotToPerformAssertions();
    }

    public function testDnsFailureIsLatchedAndLoggedOnce(): void
    {
        $logger = new RecordingLogger();
        $sink = new UdpEventSink('host.invalid', 9999, $logger);

        $sink->send(Event::now()->withOperation('a'));
        $sink->send(Event::now()->withOperation('b'));

        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'open should be attempted exactly once');
        $this->assertStringContainsString('open failed', $warnings[0]['message']);
    }

    public function testDnsFailureDoesNotThrow(): void
    {
        $sink = new UdpEventSink('host.invalid', 9999);
        $sink->send(Event::now()->withOperation('x'));
        $this->expectNotToPerformAssertions();
    }

    public function testCloseThenSendReopensLazily(): void
    {
        $sink = new UdpEventSink($this->host, $this->port);

        $sink->send(Event::now()->withOperation('first'));
        $this->assertNotNull($this->receiveOnePacket());

        $sink->close();
        $sink->close(); // idempotent

        $sink->send(Event::now()->withOperation('second'));
        $packet = $this->receiveOnePacket();
        $this->assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('second', $decoded['operation'] ?? null);
        $sink->close();
    }

    public function testEventsResourceUdpReturnsSameInstance(): void
    {
        $client = \Mesh0\Client::create('m0_test_secret');
        $a = $client->events->udp();
        $b = $client->events->udp();
        $this->assertSame($a, $b);
    }

    public function testEventsResourceUdpWithOverridesReturnsFreshInstance(): void
    {
        $client = \Mesh0\Client::create('m0_test_secret');
        $a = $client->events->udp();
        $b = $client->events->udp(host: '127.0.0.1', port: 9000);
        $this->assertNotSame($a, $b);
    }

    /** Builder bypasses the EventBuilder branch for this test path. */
    public function testSendUsesBuilderBuild(): void
    {
        $sink = new UdpEventSink($this->host, $this->port);
        $builder = new EventBuilder(new DateTimeImmutable('2026-05-08T00:00:00Z'));
        $sink->send($builder);

        $packet = $this->receiveOnePacket();
        $this->assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('timestamp', $decoded);
        $sink->close();
    }

    private function receiveOnePacket(int $timeoutMs = 500): ?string
    {
        \assert(\is_resource($this->server));
        $deadline = \microtime(true) + ($timeoutMs / 1000.0);
        while (\microtime(true) < $deadline) {
            $peer = '';
            $data = @\stream_socket_recvfrom($this->server, 65535, 0, $peer);
            if (\is_string($data) && $data !== '') {
                return $data;
            }
            \usleep(5_000);
        }
        return null;
    }
}
