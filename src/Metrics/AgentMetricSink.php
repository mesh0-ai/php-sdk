<?php

declare(strict_types=1);

namespace Mesh0\Metrics;

use Mesh0\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * UDS-DGRAM `MetricSink` targeting a co-located mesh0 metrics-agent.
 *
 * Opens `udg://<socketPath>` against the agent's Unix-domain datagram
 * socket. Lossless on a healthy host, no IP-fragmentation ceiling, no
 * port to coordinate. The socket is opened lazily on the first `send()`
 * so constructing a sink (and therefore a `Mesh0\Client`) performs zero
 * I/O.
 *
 * Send errors are intentionally swallowed: the whole point of the agent
 * is that the request path never has to care whether telemetry made it.
 * An optional PSR-3 logger receives a single `warning` per state
 * transition (open failure / write failure) so missing telemetry is at
 * least observable. The open-failure latch is terminal for the lifetime
 * of the sink — long-lived workers that need to recover from a transient
 * agent restart should construct a fresh sink.
 */
final class AgentMetricSink implements MetricSink
{
    /** @var resource|null */
    private $socket = null;

    private bool $failedToOpen = false;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $socketPath,
        ?LoggerInterface $logger = null,
    ) {
        if ($socketPath === '' || $socketPath[0] !== '/') {
            throw new ConfigurationException('socketPath must be an absolute filesystem path');
        }
        // sun_path is 104 bytes on macOS/BSD and 108 on Linux. Reject at
        // the smaller bound so the same config works across platforms.
        if (\strlen($socketPath) > 104) {
            throw new ConfigurationException('socketPath exceeds 104 bytes (sun_path limit)');
        }
        $this->logger = $logger ?? new NullLogger();
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
        try {
            $written = @\fwrite($sock, $packet);
        } catch (\Throwable) {
            $written = false;
        }
        if ($written === false || $written === 0) {
            $this->logger->warning(
                'mesh0 metrics-agent write failed; dropping subsequent packets until reset',
                ['path' => $this->socketPath],
            );
            $this->resetSocket();
        }
    }

    public function close(): void
    {
        $this->resetSocket();
        $this->failedToOpen = false;
    }

    private function resetSocket(): void
    {
        if (\is_resource($this->socket)) {
            // fclose can warn on an invalid resource (programmer error); let
            // it surface in dev. We only suppress transport failures.
            fclose($this->socket);
        }
        $this->socket = null;
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
            "udg://{$this->socketPath}",
            $errno,
            $errstr,
            1.0,
            STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            $this->failedToOpen = true;
            $this->logger->warning(
                'mesh0 metrics-agent socket open failed; metrics disabled for this sink',
                ['path' => $this->socketPath, 'errno' => $errno, 'errstr' => $errstr],
            );
            return null;
        }
        // Non-blocking writes — datagram sends shouldn't ever block, but
        // defend against a misbehaving local agent socket buffer regardless.
        stream_set_blocking($sock, false);
        $this->socket = $sock;
        return $sock;
    }
}
