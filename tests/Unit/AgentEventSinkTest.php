<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Event\AgentEventSink;
use Mesh0\Event\Event;
use Mesh0\Exception\ConfigurationException;
use Mesh0\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class AgentEventSinkTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $sockPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sockPath = '/tmp/mesh0-agent-event-' . bin2hex(random_bytes(4)) . '.sock';

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

    public function testSendDeliversJson(): void
    {
        $sink = new AgentEventSink($this->sockPath);

        $event = Event::now()
            ->withApp('checkout', 'prod')
            ->withOperation('charge.succeeded')
            ->withAttribute('order_id', 'ord_123');

        $sink->send($event);

        $packet = $this->receiveOnePacket();
        $this->assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('checkout', $decoded['app_id'] ?? null);
        $this->assertSame('charge.succeeded', $decoded['operation'] ?? null);
        $sink->close();
    }

    public function testOpenFailureForMissingSocketIsLatchedAndLogged(): void
    {
        $logger = new RecordingLogger();
        $sink = new AgentEventSink(
            '/tmp/mesh0-agent-event-missing-' . bin2hex(random_bytes(4)) . '.sock',
            $logger,
        );

        $sink->send(Event::now()->withOperation('a'));
        $sink->send(Event::now()->withOperation('b'));

        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('open failed', $warnings[0]['message']);
    }

    public function testOversizePayloadIsDroppedWithSingleWarning(): void
    {
        $logger = new RecordingLogger();
        $sink = new AgentEventSink($this->sockPath, $logger);

        $huge = str_repeat('x', AgentEventSink::MAX_DATAGRAM_BYTES);
        $event = Event::now()
            ->withOperation('big')
            ->withAttribute('blob', $huge);

        $sink->send($event);
        $sink->send($event);

        $this->assertNull($this->receiveOnePacket(50));
        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'oversize warning should latch after first drop');
        $this->assertStringContainsString('exceeds 32KB', $warnings[0]['message']);
    }

    public function testJsonEncodeFailureIsDroppedWithSingleWarning(): void
    {
        $logger = new RecordingLogger();
        $sink = new AgentEventSink($this->sockPath, $logger);

        // Invalid UTF-8 byte — json_encode throws JsonException with
        // JSON_THROW_ON_ERROR rather than silently returning false.
        $bad = "\xB1\x31";
        $event = Event::now()
            ->withOperation('encode-fail')
            ->withAttribute('bad', $bad);

        $sink->send($event);
        $sink->send($event);

        $this->assertNull($this->receiveOnePacket(50));
        $warnings = $logger->recordsAt('warning');
        $this->assertCount(1, $warnings, 'encode warning should latch after first drop');
        $this->assertStringContainsString('json_encode', $warnings[0]['message']);
    }

    public function testRejectsRelativeSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        new AgentEventSink('relative/path.sock');
    }

    public function testRejectsTooLongSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/sun_path/');
        new AgentEventSink('/' . str_repeat('a', 104));
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
