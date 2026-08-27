<?php

declare(strict_types=1);

namespace Mesh0\Event;

use Mesh0\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * UDS-DGRAM event sink targeting a co-located mesh0 metrics-agent.
 *
 * Each event is encoded as a single JSON datagram and sent to the agent
 * over `udg://<socketPath>`, which batches and forwards them to
 * `/v1/events` over HTTPS. This trades at-least-once durability for
 * ~5µs per-call cost — useful for short-lived processes (PHP request
 * handlers, CLI workers) where an HTTPS roundtrip per event is
 * unaffordable.
 *
 ## Routing and credentials
 *
 * The agent routes each datagram by a top-level `_project` and, in open
 * mode, authenticates it with a top-level `_token` (metrics-agent >= 0.4.0).
 * Both are stripped by the agent before it forwards the event, so neither
 * reaches stored telemetry.
 *
 * `$token` accepts a callable as well as a string, and for anything longer
 * than one request it must be one: an open-mode instance token is short
 * lived (~15 minutes) and rotates, so a string captured at construction
 * goes stale in any long-running worker. The callable is invoked per send —
 * it should be cheap and return the cached credential, not mint one.
 *
 * The socket is opened lazily on the first `send()` so constructing a
 * sink performs zero I/O. Send errors are intentionally swallowed
 * (datagrams are at-most-once); an optional PSR-3 logger receives a
 * single `warning` per state transition (open failure / write failure /
 * oversize drop) so missing telemetry is at least observable. The
 * open-failure latch is terminal for the lifetime of the sink.
 */
final class AgentEventSink implements EventSink
{
    /**
     * Maximum size of a single datagram. Larger payloads are silently
     * dropped by the kernel; we drop with a single warning rather than
     * crash the request path. Matches the agent's documented
     * per-datagram limit at the time of writing (metrics-agent >= 0.3.0).
     */
    public const MAX_DATAGRAM_BYTES = 32_768;

    /** @var resource|null */
    private $socket = null;

    private bool $failedToOpen = false;

    private bool $oversizeWarned = false;

    private bool $encodeWarned = false;

    private bool $writeFailureWarned = false;

    private readonly LoggerInterface $logger;

    /**
     * @param string|(callable():?string)|null $token Credential for the agent
     *        to authenticate this datagram with. Pass a callable for anything
     *        longer-lived than a single request — see the class docblock.
     */
    public function __construct(
        private readonly string $socketPath,
        ?LoggerInterface $logger = null,
        private readonly ?string $project = null,
        private $token = null,
    ) {
        if ($project !== null && $project === '') {
            throw new ConfigurationException('project must be a non-empty string or null');
        }
        if ($token !== null && !\is_string($token) && !\is_callable($token)) {
            throw new ConfigurationException('token must be a string, a callable returning ?string, or null');
        }
        if ($socketPath === '' || $socketPath[0] !== '/') {
            throw new ConfigurationException('socketPath must be an absolute filesystem path');
        }
        if (\strlen($socketPath) > 104) {
            throw new ConfigurationException('socketPath exceeds 104 bytes (sun_path limit)');
        }
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        $this->resetSocket();
    }

    public function send(Event|EventBuilder $event): void
    {
        $e = $event instanceof EventBuilder ? $event->build() : $event;

        try {
            $payload = \json_encode(
                $this->withRouting($e->toArray()),
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $ex) {
            if (!$this->encodeWarned) {
                $this->encodeWarned = true;
                $this->logger->warning('mesh0 event json_encode failed; dropping', [
                    'error' => $ex->getMessage(),
                    'event_id' => $e->eventId,
                ]);
            }
            return;
        }

        if (\strlen($payload) > self::MAX_DATAGRAM_BYTES) {
            if (!$this->oversizeWarned) {
                $this->oversizeWarned = true;
                $this->logger->warning('mesh0 event datagram exceeds 32KB; dropping', [
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

        $written = @\fwrite($sock, $payload);
        if ($written === false || $written === 0) {
            // Drop and warn once. We deliberately do NOT close/reopen the
            // socket here — for UDS-DGRAM, EAGAIN (buffer full) and
            // ECONNREFUSED (peer gone) are both recoverable without a
            // reconnect, and tearing down on every failure produced a
            // reconnect-per-packet storm under load.
            if (!$this->writeFailureWarned) {
                $this->writeFailureWarned = true;
                $this->logger->warning(
                    'mesh0 event write failed; dropping packets',
                    ['path' => $this->socketPath, 'error' => (\error_get_last() ?? ['message' => 'unknown'])['message']],
                );
            }
            return;
        }
        $this->writeFailureWarned = false;
    }

    /**
     * Merge the routing fields into the outgoing datagram.
     *
     * Written LAST so they win over an event that happens to carry a
     * colliding attribute — `_project` and `_token` are wire fields owned by
     * the sink/agent contract, not event data, and an event that set one
     * would otherwise silently re-route or re-authenticate itself.
     *
     * An empty or null token is omitted rather than sent as `""`: the agent
     * reads a present-but-unusable credential as a hard drop
     * (`drops.bad_token`), which is the right answer for a malformed token
     * and the wrong one for "this deployment does not use them".
     *
     * @param  array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function withRouting(array $event): array
    {
        if ($this->project !== null) {
            $event['_project'] = $this->project;
        }
        $token = \is_callable($this->token) ? ($this->token)() : $this->token;
        if (\is_string($token) && $token !== '') {
            $event['_token'] = $token;
        }
        return $event;
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
        $this->encodeWarned = false;
        $this->writeFailureWarned = false;
    }

    private function resetSocket(): void
    {
        if (\is_resource($this->socket)) {
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
            "udg://{$this->socketPath}",
            $errno,
            $errstr,
            1.0,
            \STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            $this->failedToOpen = true;
            $this->logger->warning(
                'mesh0 event socket open failed; events disabled for this sink',
                ['path' => $this->socketPath, 'errno' => $errno, 'errstr' => $errstr],
            );
            return null;
        }
        // Non-blocking writes — datagram sends shouldn't ever block, but
        // defend against a misbehaving local agent socket buffer regardless.
        \stream_set_blocking($sock, false);
        $this->socket = $sock;
        return $sock;
    }
}
