<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Exception\ConfigurationException;
use Mesh0\Metrics\AgentMetricSink;
use Mesh0\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class AgentMetricSinkTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $sockPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        // /tmp avoids the macOS sun_path 104-byte cap that sys_get_temp_dir
        // can blow through.
        $this->sockPath = '/tmp/mesh0-agent-metric-' . bin2hex(random_bytes(4)) . '.sock';

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

    public function testSendDeliversPacket(): void
    {
        $sink = new AgentMetricSink($this->sockPath);

        $sink->send('hello.world:1|c');

        $this->assertSame('hello.world:1|c', $this->receiveOnePacket());
        $sink->close();
    }

    public function testEmptyPayloadIsDropped(): void
    {
        $sink = new AgentMetricSink($this->sockPath);

        $sink->send('');

        $this->assertNull($this->receiveOnePacket(50));
        $sink->close();
    }

    public function testOpenFailureForMissingSocketIsLatchedAndLogged(): void
    {
        $logger = new RecordingLogger();
        $sink = new AgentMetricSink(
            '/tmp/mesh0-agent-missing-' . bin2hex(random_bytes(4)) . '.sock',
            $logger,
        );

        $sink->send('x:1|c');
        $sink->send('y:1|c');

        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'open should be attempted exactly once');
        $this->assertStringContainsString('open failed', $warnings[0]['message']);
    }

    public function testOversizePacketIsDroppedWithSingleWarning(): void
    {
        $logger = new RecordingLogger();
        $sink = new AgentMetricSink($this->sockPath, $logger);

        $huge = str_repeat('x', AgentMetricSink::MAX_DATAGRAM_BYTES + 1);

        $sink->send($huge);
        $sink->send($huge);

        $this->assertNull($this->receiveOnePacket(50));
        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'oversize warning should latch after first drop');
        $this->assertStringContainsString('exceeds 32KB', $warnings[0]['message']);
    }

    public function testRejectsRelativeSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        new AgentMetricSink('relative/path.sock');
    }

    public function testRejectsTooLongSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/sun_path/');
        new AgentMetricSink('/' . str_repeat('a', 104));
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
