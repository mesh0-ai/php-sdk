<?php

declare(strict_types=1);

namespace Mesh0\Event;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * UDP event sink targeting a co-located mesh0 metrics-agent.
 *
 * Each event is encoded as a single JSON datagram and sent to the agent,
 * which batches and forwards them to `/v1/events` over HTTPS. This trades
 * at-least-once durability for ~5µs per-call cost — useful for short-lived
 * processes (PHP request handlers, CLI workers) where an HTTPS roundtrip
 * per event is unaffordable.
 *
 * The socket is opened lazily on the first `send()` so constructing a sink
 * performs zero I/O. Send errors are intentionally swallowed (UDP is at-most-
 * once); an optional PSR-3 logger receives a single `warning` per state
 * transition (open failure / write failure / oversize drop) so missing
 * telemetry is at least observable.
 */
final class UdpEventSink implements EventSink
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 8125;

    /**
     * Maximum size of a single UDP datagram. Anything larger risks IP
     * fragmentation or being silently dropped by the kernel; we drop with a
     * single warning rather than crash the request path.
     */
    public const MAX_DATAGRAM_BYTES = 32_768;

    /** @var resource|null */
    private $socket = null;

    private bool $failedToOpen = false;

    private bool $oversizeWarned = false;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $host = self::DEFAULT_HOST,
        private readonly int $port = self::DEFAULT_PORT,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        $this->resetSocket();
    }

    public function send(Event|EventBuilder $event): void
    {
        $e = $event instanceof EventBuilder ? $event->build() : $event;

        $payload = \json_encode($e->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (!\is_string($payload)) {
            return;
        }

        if (\strlen($payload) > self::MAX_DATAGRAM_BYTES) {
            if (!$this->oversizeWarned) {
                $this->oversizeWarned = true;
                $this->logger->warning('mesh0 event UDP datagram exceeds 32KB; dropping', [
                    'bytes' => \strlen($payload),
                    'limit' => self::MAX_DATAGRAM_BYTES,
                ]);
            }
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
            $written = @\fwrite($sock, $payload);
        } catch (\Throwable) {
            $written = false;
        }
        if ($written === false || $written === 0) {
            $this->logger->warning('mesh0 event UDP write failed; dropping subsequent packets until reset', [
                'host' => $this->host,
                'port' => $this->port,
            ]);
            $this->resetSocket();
        }
    }

    /**
     * Send a batch of events. There is no batching at the SDK level —
     * the agent batches before forwarding to mesh0.
     *
     * @param iterable<Event|EventBuilder> $events
     */
    public function sendMany(iterable $events): void
    {
        foreach ($events as $event) {
            $this->send($event);
        }
    }

    public function close(): void
    {
        $this->resetSocket();
        $this->failedToOpen = false;
        $this->oversizeWarned = false;
    }

    private function resetSocket(): void
    {
        if (\is_resource($this->socket)) {
            // fclose can warn on an invalid resource (programmer error); let
            // it surface in dev. We only suppress UDP transport failures.
            \fclose($this->socket);
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
        $sock = @\stream_socket_client(
            "udp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            1.0,
            \STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            $this->failedToOpen = true;
            $this->logger->warning('mesh0 event UDP socket open failed; events disabled for this sink', [
                'host' => $this->host,
                'port' => $this->port,
                'errno' => $errno,
                'errstr' => $errstr,
            ]);
            return null;
        }
        // Non-blocking writes — UDP send shouldn't ever block, but defend
        // against a misbehaving local agent socket buffer regardless.
        \stream_set_blocking($sock, false);
        $this->socket = $sock;
        return $sock;
    }
}
