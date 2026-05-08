<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Metrics\UdpMetricSink;
use Mesh0\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class UdpMetricSinkTest extends TestCase
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
        $server = @\stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
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

    public function testSendDeliversPacketToBoundUdpServer(): void
    {
        $sink = new UdpMetricSink($this->host, $this->port);

        $sink->send('hello.world:1|c');

        $this->assertSame('hello.world:1|c', $this->receiveOnePacket());
        $sink->close();
    }

    public function testSendIsNoOpForEmptyPacket(): void
    {
        $sink = new UdpMetricSink($this->host, $this->port);

        $sink->send('');

        $this->assertNull($this->receiveOnePacket(50));
        $sink->close();
    }

    public function testCloseIsIdempotentAndSinkRemainsUsable(): void
    {
        $sink = new UdpMetricSink($this->host, $this->port);
        $sink->send('a:1|c');
        $this->assertSame('a:1|c', $this->receiveOnePacket());

        $sink->close();
        $sink->close();

        $sink->send('b:2|c');
        $this->assertSame('b:2|c', $this->receiveOnePacket());
        $sink->close();
    }

    public function testOpenFailureIsLatchedAndLogged(): void
    {
        $logger = new RecordingLogger();
        // RFC 2606 .invalid TLD: DNS resolution always fails.
        $sink = new UdpMetricSink('host.invalid', 9999, $logger);

        $sink->send('x:1|c');
        $sink->send('y:1|c');

        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'open should be attempted exactly once and warning fires once');
        $this->assertStringContainsString('open failed', $warnings[0]['message']);
    }

    public function testSendOnUnresolvableHostDoesNotThrow(): void
    {
        $sink = new UdpMetricSink('host.invalid', 9999);

        $sink->send('x:1|c');

        $this->expectNotToPerformAssertions();
    }

    public function testCloseAfterFailedOpenAllowsRetry(): void
    {
        $logger = new RecordingLogger();
        $sink = new UdpMetricSink('host.invalid', 9999, $logger);
        $sink->send('x:1|c');
        $this->assertCount(1, $logger->recordsAt('warning'));

        $sink->close();
        $sink->send('y:1|c');

        // After close(), the failure latch is cleared and open is retried,
        // producing a second warning.
        $this->assertCount(2, $logger->recordsAt('warning'));
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
