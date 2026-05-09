<?php

declare(strict_types=1);

namespace Mesh0\Metrics;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Datagram `MetricSink` targeting a co-located mesh0 metrics-agent.
 *
 * Speaks one of two transports, both fire-and-forget:
 *
 * - **UDP** (default): `host:port`, addresses the agent's UDP listener.
 * - **UDS-DGRAM**: pass `socketPath` to open `udg://<path>` against the
 *   agent's Unix-domain datagram socket. Lifts the ~64 KB UDP fragmentation
 *   ceiling and avoids the IP stack entirely on a single host.
 *
 * The socket is opened lazily on the first `send()` so constructing a sink
 * (and therefore a `Mesh0\Client`) performs zero I/O. Send errors are
 * intentionally swallowed: the whole point of the agent is that the request
 * path never has to care whether telemetry made it. An optional PSR-3 logger
 * receives a single `warning` per state transition (open failure / write
 * failure) so missing telemetry is at least observable.
 *
 * The class name is preserved for backward compatibility — UDS-DGRAM is just
 * a different concrete transport for the same "local agent sink" role.
 */
final class UdpMetricSink implements MetricSink
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 8125;

    /** @var resource|null */
    private $socket = null;

    private bool $failedToOpen = false;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $host = self::DEFAULT_HOST,
        private readonly int $port = self::DEFAULT_PORT,
        ?LoggerInterface $logger = null,
        private readonly ?string $socketPath = null,
    ) {
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
        // Network failures (peer unreachable, ICMP "port unreachable") must
        // never bubble up. We narrow suppression to fwrite, then invalidate
        // the cached socket on failure so the next call retries from scratch.
        try {
            $written = @\fwrite($sock, $packet);
        } catch (\Throwable) {
            $written = false;
        }
        if ($written === false || $written === 0) {
            $this->logger->warning('mesh0 metrics-agent write failed; dropping subsequent packets until reset', $this->endpointContext());
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
            // it surface in dev. We only suppress UDP transport failures.
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
            $this->endpointUri(),
            $errno,
            $errstr,
            1.0,
            STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            $this->failedToOpen = true;
            $this->logger->warning(
                'mesh0 metrics-agent socket open failed; metrics disabled for this sink',
                $this->endpointContext() + ['errno' => $errno, 'errstr' => $errstr],
            );
            return null;
        }
        // Non-blocking writes — datagram sends shouldn't ever block, but
        // defend against a misbehaving local agent socket buffer regardless.
        stream_set_blocking($sock, false);
        $this->socket = $sock;
        return $sock;
    }

    private function endpointUri(): string
    {
        if ($this->socketPath !== null) {
            return "udg://{$this->socketPath}";
        }
        return "udp://{$this->host}:{$this->port}";
    }

    /** @return array<string, string|int> */
    private function endpointContext(): array
    {
        if ($this->socketPath !== null) {
            return ['transport' => 'udg', 'path' => $this->socketPath];
        }
        return ['transport' => 'udp', 'host' => $this->host, 'port' => $this->port];
    }
}
