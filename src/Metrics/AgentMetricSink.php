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
    /**
     * Per-datagram size ceiling. Larger packets are dropped before the
     * kernel rejects them with EMSGSIZE (which would otherwise look like
     * a generic write failure). Mirrors {@see \Mesh0\Event\AgentEventSink::MAX_DATAGRAM_BYTES}.
     */
    public const MAX_DATAGRAM_BYTES = 32_768;

    /** @var resource|null */
    private $socket = null;

    private bool $failedToOpen = false;

    private bool $oversizeWarned = false;

    private bool $writeFailureWarned = false;

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
        if (\strlen($packet) > self::MAX_DATAGRAM_BYTES) {
            if (!$this->oversizeWarned) {
                $this->oversizeWarned = true;
                $this->logger->warning('mesh0 metrics packet exceeds 32KB; dropping', [
                    'bytes' => \strlen($packet),
                    'limit' => self::MAX_DATAGRAM_BYTES,
                ]);
            }
            return;
        }
        $sock = $this->socket();
        if ($sock === null) {
            return;
        }
        $written = @\fwrite($sock, $packet);
        if ($written === false || $written === 0) {
            // Drop and warn once. We deliberately do NOT close/reopen the
            // socket — for UDS-DGRAM, EAGAIN (buffer full) and
            // ECONNREFUSED (peer gone) are recoverable without a
            // reconnect, and tearing down on every failure produced a
            // reconnect-per-packet storm under load.
            if (!$this->writeFailureWarned) {
                $this->writeFailureWarned = true;
                $this->logger->warning(
                    'mesh0 metrics-agent write failed; dropping packets',
                    ['path' => $this->socketPath, 'error' => (\error_get_last() ?? ['message' => 'unknown'])['message']],
                );
            }
            return;
        }
        $this->writeFailureWarned = false;
    }

    public function close(): void
    {
        $this->resetSocket();
        $this->failedToOpen = false;
        $this->oversizeWarned = false;
        $this->writeFailureWarned = false;
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
