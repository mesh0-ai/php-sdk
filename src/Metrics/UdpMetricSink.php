<?php

declare(strict_types=1);

namespace Mesh0\Metrics;

/**
 * UDP `MetricSink` targeting a co-located mesh0 metrics-agent.
 *
 * The socket is opened lazily on the first `send()` so constructing a sink
 * (and therefore a `Mesh0\Client`) performs zero I/O. Send errors are
 * intentionally swallowed: the whole point of the agent is that the request
 * path never has to care whether telemetry made it.
 */
final class UdpMetricSink implements MetricSink
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 8125;

    /** @var resource|null */
    private $socket = null;

    private bool $failedToOpen = false;

    public function __construct(
        private readonly string $host = self::DEFAULT_HOST,
        private readonly int $port = self::DEFAULT_PORT,
    ) {
    }

    public function send(string $packet): void
    {
        if ($packet === '') {
            return;
        }
        $sock = $this->socket();
        if ($sock === null) {
            return;
        }
        // Suppress: a partial write or a closed agent must not bubble up.
        @fwrite($sock, $packet);
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->failedToOpen = false;
    }

    /** @return resource|null */
    private function socket()
    {
        if (\is_resource($this->socket)) {
            return $this->socket;
        }
        if ($this->failedToOpen) {
            return null;
        }
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "udp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            1.0,
            STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            $this->failedToOpen = true;
            return null;
        }
        // Non-blocking writes — UDP send shouldn't ever block, but defend
        // against a misbehaving local agent socket buffer regardless.
        @stream_set_blocking($sock, false);
        $this->socket = $sock;
        return $sock;
    }
}
