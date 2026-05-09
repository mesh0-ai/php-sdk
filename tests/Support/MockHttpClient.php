<?php

declare(strict_types=1);

namespace Mesh0\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-memory PSR-18 client for tests. Records every outgoing request and
 * dequeues a queued response (or throws if the queue is empty).
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function queueJson(int $status, array $body, array $headers = []): void
    {
        $this->queue[] = new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    public function queueRaw(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function queueException(\Throwable $e): void
    {
        $this->queue[] = $e;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \RuntimeException('MockHttpClient queue is empty');
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $count = \count($this->requests);
        if ($count === 0) {
            throw new \RuntimeException('No requests recorded');
        }
        return $this->requests[$count - 1];
    }

    /**
     * Decode the last request's JSON body and assert the top level is an object.
     *
     * @return array<string, mixed>
     */
    public function lastJsonBody(): array
    {
        $raw = (string) $this->lastRequest()->getBody();
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Last request body was not a JSON object');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Pull a single event out of the last `/v1/events` request body.
     *
     * @return array<string, mixed>
     */
    public function lastEvent(int $index = 0): array
    {
        $body = $this->lastJsonBody();
        $events = $body['events'] ?? null;
        if (!\is_array($events) || !isset($events[$index]) || !\is_array($events[$index])) {
            throw new \RuntimeException("No event at index {$index}");
        }
        /** @var array<string, mixed> $event */
        $event = $events[$index];
        return $event;
    }

    /**
     * All events from the last `/v1/events` request body.
     *
     * @return list<array<string, mixed>>
     */
    public function lastEventsBatch(): array
    {
        $body = $this->lastJsonBody();
        $events = $body['events'] ?? null;
        if (!\is_array($events)) {
            throw new \RuntimeException('Last request body had no `events` array');
        }
        /** @var list<array<string, mixed>> $events */
        return \array_values($events);
    }
}
