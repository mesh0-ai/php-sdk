<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Config;
use Mesh0\Event\Event;
use Mesh0\Event\EventBuilder;
use Mesh0\Event\UdpEventSink;
use Mesh0\Http\Transport;
use Psr\Log\LoggerInterface;

/**
 * `/v1/events` resource — ingest custom events and read them back.
 *
 * `send` and `sendMany` POST to the project bound to the API key — the
 * SDK never lets you target a different project.
 */
final class Events
{
    /** Server-side hard cap (mirrors backend validation). */
    private const MAX_BATCH = 5000;

    private ?UdpEventSink $udpSink = null;

    public function __construct(
        private readonly Transport $http,
        private readonly ?Config $config = null,
    ) {
    }

    /**
     * Return a datagram event sink targeting a co-located mesh0 metrics-agent.
     *
     * The agent listens on UDP (default `127.0.0.1:8125`) — or on a Unix
     * datagram socket when `socketPath` (or {@see Config::$metricsAgentSocketPath})
     * is set — and forwards events to mesh0's `/v1/events` endpoint over
     * HTTPS. Per-call cost is ~5µs, making this suitable for short-lived
     * processes (PHP request handlers, CLI workers) that can't afford an
     * HTTPS roundtrip per event.
     *
     * Subsequent calls without arguments return the same instance. The
     * datagram path is at-most-once; for at-least-once durability use
     * {@see send()} / {@see sendMany()} which POST to `/v1/events` directly.
     *
     * Defaults read from {@see Config::$metricsAgentSocketPath} when set
     * (UDS-DGRAM), otherwise {@see Config::$metricsAgentHost} /
     * {@see Config::$metricsAgentPort} (UDP, agent listens on the same port
     * for both metrics and events). When `socketPath` is set, `host` and
     * `port` are ignored.
     */
    public function udp(
        ?string $host = null,
        ?int $port = null,
        ?LoggerInterface $logger = null,
        ?string $socketPath = null,
    ): UdpEventSink {
        $defaultHost = $this->config?->metricsAgentHost ?? UdpEventSink::DEFAULT_HOST;
        $defaultPort = $this->config?->metricsAgentPort ?? UdpEventSink::DEFAULT_PORT;
        $defaultSocket = $this->config?->metricsAgentSocketPath;

        if ($host !== null || $port !== null || $logger !== null || $socketPath !== null) {
            return new UdpEventSink(
                $host ?? $defaultHost,
                $port ?? $defaultPort,
                $logger,
                $socketPath ?? $defaultSocket,
            );
        }

        return $this->udpSink ??= new UdpEventSink($defaultHost, $defaultPort, null, $defaultSocket);
    }

    /**
     * Send a single event.
     *
     * @return int Number of events accepted by the server (0 or 1).
     */
    public function send(Event|EventBuilder $event): int
    {
        return $this->sendMany([$event]);
    }

    /**
     * Send a batch of events. The batch is split client-side at 5,000 events
     * to match the server's per-request cap; this method returns the total
     * accepted across all sub-batches.
     *
     * @param iterable<Event|EventBuilder> $events
     * @return int Total events accepted across all chunks.
     */
    public function sendMany(iterable $events): int
    {
        $buffer = [];
        $accepted = 0;
        foreach ($events as $e) {
            $buffer[] = $e instanceof EventBuilder ? $e->build() : $e;
            if (count($buffer) >= self::MAX_BATCH) {
                $accepted += $this->flush($buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            $accepted += $this->flush($buffer);
        }
        return $accepted;
    }

    /** @param list<Event> $events */
    private function flush(array $events): int
    {
        $payload = ['events' => array_map(static fn (Event $e): array => $e->toArray(), $events)];
        $response = $this->http->post('/v1/events', $payload);
        $accepted = $response['accepted'] ?? 0;
        return is_int($accepted) ? $accepted : count($events);
    }

    /**
     * List events newest-first, with cursor pagination.
     *
     * @param int $limit Page size (1-500, server-enforced).
     * @return array{events: list<array<string, mixed>>, nextCursor: ?string, hasMore: bool}
     */
    public function list(int $limit = 50, ?string $cursor = null): array
    {
        /** @var array<string, mixed> $resp */
        $resp = $this->http->get('/v1/events', [
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
        /** @var list<array<string, mixed>> $events */
        $events = is_array($resp['events'] ?? null) ? $resp['events'] : [];
        $next = $resp['nextCursor'] ?? null;
        $hasMore = $resp['hasMore'] ?? false;
        return [
            'events' => $events,
            'nextCursor' => is_string($next) ? $next : null,
            'hasMore' => is_bool($hasMore) ? $hasMore : false,
        ];
    }

    /**
     * Iterate every event matching the parameters, paging transparently.
     *
     * @return iterable<array<string, mixed>>
     */
    public function iterate(int $pageSize = 200): iterable
    {
        $cursor = null;
        do {
            $page = $this->list($pageSize, $cursor);
            foreach ($page['events'] as $row) {
                yield $row;
            }
            $cursor = $page['nextCursor'];
        } while ($cursor !== null && $page['hasMore']);
    }
}
