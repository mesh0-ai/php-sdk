<?php

declare(strict_types=1);

namespace Mesh0\Http;

use Mesh0\Config;
use Mesh0\Exception\ApiException;
use Mesh0\Exception\AuthenticationException;
use Mesh0\Exception\BadRequestException;
use Mesh0\Exception\NetworkException;
use Mesh0\Exception\NotFoundException;
use Mesh0\Exception\RateLimitException;
use Mesh0\Exception\ServerException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 HTTP transport with JSON encoding, auth, and retry-with-backoff.
 *
 * Retries are limited to idempotent failure modes — connect/transport errors,
 * `429`, and `5xx` — to keep the default behavior safe for both GET and POST
 * (mesh0's ingest path is idempotent on `event_id`, so duplicate-write risk
 * is bounded by the worker's dedup, not the client's retry policy).
 */
final class Transport
{
    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->http = $http ?? \Http\Discovery\Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query, ?array $body): array
    {
        $url = $this->buildUrl($path, $query);
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Bearer ' . $this->config->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->config->userAgent);

        foreach ($this->config->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        $attempt = 0;
        $maxAttempts = $this->config->maxRetries + 1;
        while (true) {
            $attempt++;
            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($attempt < $maxAttempts) {
                    $this->sleep($this->backoffMs($attempt));
                    continue;
                }
                throw new NetworkException('HTTP request failed: ' . $e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $this->decode($response);
            }

            // Retry on transient failures only; never retry 4xx other than 429.
            if (($status >= 500 || $status === 429) && $attempt < $maxAttempts) {
                $delayMs = $this->retryAfterMs($response) ?? $this->backoffMs($attempt);
                $this->sleep($delayMs);
                continue;
            }

            throw $this->errorFor($response);
        }
    }

    /** @param array<string, scalar|null> $query */
    private function buildUrl(string $path, array $query): string
    {
        $base = rtrim($this->config->baseUrl, '/');
        $path = '/' . ltrim($path, '/');
        $url = $base . $path;
        if ($query !== []) {
            $filtered = array_filter(
                $query,
                static fn (mixed $v): bool => $v !== null && $v !== '',
            );
            if ($filtered !== []) {
                $url .= '?' . http_build_query($filtered);
            }
        }
        return $url;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                'Failed to decode JSON response: ' . $e->getMessage(),
                $response->getStatusCode(),
                null,
                null,
                $e,
            );
        }
        if (!is_array($decoded)) {
            throw new ApiException(
                'Unexpected non-object JSON response',
                $response->getStatusCode(),
            );
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function errorFor(ResponseInterface $response): ApiException
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        /** @var array<string, mixed>|null $parsed */
        $parsed = null;
        if ($body !== '') {
            try {
                /** @var mixed $maybe */
                $maybe = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($maybe)) {
                    /** @var array<string, mixed> $maybe */
                    $parsed = $maybe;
                }
            } catch (\JsonException) {
                // leave $parsed null — non-JSON error body is fine
            }
        }

        $errorId = is_string($parsed['errorId'] ?? null) ? $parsed['errorId'] : null;
        $reason = is_string($parsed['reason'] ?? null) ? $parsed['reason'] : null;
        $error = is_string($parsed['error'] ?? null) ? $parsed['error'] : null;
        $message = sprintf(
            'mesh0 API error (status=%d%s%s)',
            $status,
            $error !== null ? ", error=$error" : '',
            $reason !== null ? ", reason=$reason" : '',
        );

        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException($message, $status, $parsed, $errorId),
            $status === 404 => new NotFoundException($message, $status, $parsed, $errorId),
            $status === 429 => new RateLimitException($message, $status, $parsed, $errorId, $this->retryAfterSeconds($response)),
            $status >= 400 && $status < 500 => new BadRequestException($message, $status, $parsed, $errorId),
            $status >= 500 => new ServerException($message, $status, $parsed, $errorId),
            default => new ApiException($message, $status, $parsed, $errorId),
        };
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $h = $response->getHeaderLine('Retry-After');
        if ($h === '') {
            return null;
        }
        if (ctype_digit($h)) {
            return (int) $h;
        }
        $ts = strtotime($h);
        if ($ts === false) {
            return null;
        }
        return max(0, $ts - time());
    }

    private function retryAfterMs(ResponseInterface $response): ?int
    {
        $s = $this->retryAfterSeconds($response);
        return $s === null ? null : $s * 1000;
    }

    /** Exponential backoff with jitter, capped at 5s. */
    private function backoffMs(int $attempt): int
    {
        $base = min(5000, 200 * (2 ** ($attempt - 1)));
        return $base + random_int(0, (int) ($base / 2));
    }

    private function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}
