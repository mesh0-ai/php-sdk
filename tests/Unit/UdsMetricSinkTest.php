<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Metrics\UdpMetricSink;
use Mesh0\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip coverage for UdpMetricSink in UDS-DGRAM mode (`socketPath` set).
 * The class still carries the `Udp` name for backward compatibility — these
 * tests exercise the udg:// transport branch.
 */
final class UdsMetricSinkTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $sockPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        // macOS caps sun_path at 104 bytes; sys_get_temp_dir() can exceed
        // that. Use /tmp directly with a short unique suffix.
        $this->sockPath = '/tmp/mesh0-uds-metric-' . bin2hex(random_bytes(4)) . '.sock';

        $errno = 0;
        $errstr = '';
        $server = @\stream_socket_server('udg://' . $this->sockPath, $errno, $errstr, \STREAM_SERVER_BIND);
        if ($server === false) {
            $this->markTestSkipped("could not bind UDS-DGRAM server: {$errstr}");
        }
        $this->server = $server;
        \stream_set_blocking($server, false);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \fclose($this->server);
        }
        $this->server = null;
        if ($this->sockPath !== '' && \file_exists($this->sockPath)) {
            @\unlink($this->sockPath);
        }
        parent::tearDown();
    }

    public function testSendDeliversPacketOverUds(): void
    {
        $sink = new UdpMetricSink(socketPath: $this->sockPath);

        $sink->send('hello.world:1|c');

        $this->assertSame('hello.world:1|c', $this->receiveOnePacket());
        $sink->close();
    }

    public function testHostAndPortAreIgnoredWhenSocketPathSet(): void
    {
        // Bogus host/port — if the sink wired UDP, this would fail to open.
        $sink = new UdpMetricSink(host: 'host.invalid', port: 1, socketPath: $this->sockPath);

        $sink->send('a:1|c');

        $this->assertSame('a:1|c', $this->receiveOnePacket());
        $sink->close();
    }

    public function testOpenFailureForMissingSocketIsLatchedAndLogged(): void
    {
        $logger = new RecordingLogger();
        $sink = new UdpMetricSink(
            socketPath: '/tmp/mesh0-uds-does-not-exist-' . bin2hex(random_bytes(4)) . '.sock',
            logger: $logger,
        );

        $sink->send('x:1|c');
        $sink->send('y:1|c');

        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'open should be attempted exactly once');
        $this->assertStringContainsString('open failed', $warnings[0]['message']);
        $this->assertSame('udg', $warnings[0]['context']['transport'] ?? null);
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
