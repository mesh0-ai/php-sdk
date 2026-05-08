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
        $count = count($this->requests);
        if ($count === 0) {
            throw new \RuntimeException('No requests recorded');
        }
        return $this->requests[$count - 1];
    }
}
