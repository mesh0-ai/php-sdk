# mesh0 PHP SDK

[![CI](https://github.com/mesh0-ai/php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/mesh0-ai/php-sdk/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/mesh0/sdk/v/stable)](https://packagist.org/packages/mesh0/sdk)
[![License](https://poser.pugx.org/mesh0/sdk/license)](LICENSE)

Official PHP client for the [mesh0](https://mesh0.ai) AI telemetry platform.
Send logs, custom events, and OTLP traces; query them back with TQL.

- **PHP 8.2+** with strict types and readonly DTOs
- **PSR-3** logger you can drop into Laravel, Symfony, Slim, …
- **PSR-18** HTTP client — bring your own (Guzzle, Symfony HTTP, …) or rely on auto-discovery
- **Built-in retries** for transient failures with exponential backoff + jitter
- **Tested at PHPStan level 9**

---

## Installation

```sh
composer require mesh0/sdk
```

If you don't already have a PSR-18 client installed, add Guzzle:

```sh
composer require guzzlehttp/guzzle
```

---

## Quick start

```php
use Mesh0\Client;
use Mesh0\Event\Event;

$mesh0 = Client::create('m0_abcde_xxxxxxxxxxxxxxxxxxxxxxxx');

// Send a single event
$mesh0->events->send(
    Event::now()
        ->withApp('checkout', 'prod')
        ->withOperation('charge.captured')
        ->withUser('user_42')
        ->withAttributes(['order_id' => 'ord_123', 'amount_usd' => 19.99]),
);
```

Or load configuration from the environment (`MESH0_API_KEY`, `MESH0_BASE_URL`):

```php
$mesh0 = Client::fromEnv();
```

---

## Sending logs (PSR-3)

The fastest way to start streaming telemetry to mesh0 is the bundled PSR-3
logger. Plug it into any framework that takes a `Psr\Log\LoggerInterface`:

```php
$logger = $mesh0->logger(appId: 'web', environment: 'prod');

$logger->info('user {user} signed up', ['user' => 'alice', 'plan' => 'pro']);

try {
    chargeCard($order);
} catch (\Throwable $e) {
    $logger->error('charge failed', [
        'exception' => $e,
        'order_id'  => $order->id,
        'user_id'   => $order->userId,
    ]);
}
```

Special context keys are lifted onto first-class event fields:

| Context key       | Mapped to             |
| ----------------- | --------------------- |
| `trace_id`        | `trace_id`            |
| `span_id`         | `span_id`             |
| `parent_span_id`  | `parent_span_id`      |
| `user_id`         | `user_id`             |
| `session_id`      | `session_id`          |
| `operation`       | `operation`           |
| `duration_ms`     | `duration_ms`         |
| `exception`       | `error_type` + `error_message`, `status=error` |

Everything else is merged into `attributes`. Records are buffered in memory
and flushed on `flush()`, when the buffer fills, and on shutdown.

### Laravel

```php
// config/logging.php
'channels' => [
    'mesh0' => [
        'driver' => 'custom',
        'via'    => fn () => Mesh0\Client::fromEnv()->logger(
            appId: config('app.name'),
            environment: config('app.env'),
        ),
    ],
],
```

### Symfony / Monolog

Add a `psr` handler pointing at the mesh0 logger service:

```yaml
# config/services.yaml
services:
    Mesh0\Client:
        factory: ['Mesh0\Client', 'fromEnv']
    Psr\Log\LoggerInterface $mesh0Logger:
        factory: ['@Mesh0\Client', 'logger']
        arguments: ['%env(APP_NAME)%', '%kernel.environment%']
```

---

## Sending events directly

The `Event` builder is fluent and immutable — every `with*` call returns a
new builder.

```php
$mesh0->events->send(
    Event::now()
        ->withApp('agents', 'prod')
        ->withOperation('agent.run')
        ->withModel('anthropic', 'claude-opus-4-7')
        ->withUsage(promptTokens: 1_240, completionTokens: 380, costUsd: 0.0184)
        ->withDurationMs(820)
        ->withTraceId($traceId)
        ->withTools(['search', 'retrieve'])
        ->withAttributes(['workflow' => 'onboarding']),
);

// Bulk: send up to 5,000 events per HTTP call. Larger batches are split.
$mesh0->events->sendMany($events);
```

---

## OTLP traces

mesh0 accepts OTLP/HTTP JSON at `<baseUrl>/v1/traces`. Point any
OpenTelemetry exporter at it with the same Bearer token:

```ini
OTEL_EXPORTER_OTLP_ENDPOINT=https://api.mesh0.ai
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer m0_abcde_xxxxxxxxxxxxxxxxxxxxxxxx
```

The SDK exposes the read side:

```php
$spans = $mesh0->traces->get($traceId);
```

---

## Metrics (statsd / DogStatsD over UDP)

For high-frequency counters, gauges, and timings — the kind of telemetry that
shouldn't go through the request-blocking HTTPS path — point at a co-located
[mesh0 metrics-agent](https://github.com/mesh0-ai/metrics-agent) sidecar:

```php
$metrics = $mesh0->metrics(); // UDP 127.0.0.1:8125 by default

$metrics->increment('checkout.charge', tags: ['tier' => 'pro']);
$metrics->gauge('queue.depth', 42);
$metrics->timing('db.query_ms', 12.4, tags: ['table' => 'orders']);
$metrics->histogram('upload.bytes', 8192);

// Convenience: time a block; metric is emitted whether $fn returns or throws.
$rows = $metrics->time('db.select_ms', fn () => $pdo->query($sql)->fetchAll());
```

The UDP socket is opened lazily on the first send, so `$mesh0->metrics()` does
no I/O. Override the agent address via `Config` (or `MESH0_AGENT_HOST` /
`MESH0_AGENT_PORT`):

```php
$metrics = $mesh0->metrics(host: 'mesh0-agent', port: 9125, defaultTags: [
    'service' => 'checkout',
    'env'     => 'prod',
]);
```

---

## Querying

```php
$rows = $mesh0->query->run([
    'from'    => 'events',
    'select'  => ['operation', 'count()'],
    'where'   => ['status' => 'error'],
    'groupBy' => ['operation'],
    'orderBy' => [['count()', 'desc']],
    'limit'   => 25,
]);
```

Pagination is also available on the events resource:

```php
$page = $mesh0->events->list(limit: 100);
foreach ($page['events'] as $row) { /* … */ }

// Or stream every event, transparently following cursors:
foreach ($mesh0->events->iterate() as $row) { /* … */ }
```

---

## Configuration

```php
use Mesh0\Client;
use Mesh0\Config;

$mesh0 = new Client(new Config(
    apiKey: 'm0_abcde_xxxxxxxxxxxxxxxxxxxxxxxx',
    baseUrl: 'https://api.mesh0.ai',
    timeout: 10.0,
    connectTimeout: 5.0,
    maxRetries: 2,
    userAgent: 'my-app/1.0',
    defaultHeaders: ['X-Tenant' => 'acme'],
));
```

### Environment variables

| Variable          | Description                                         |
| ----------------- | --------------------------------------------------- |
| `MESH0_API_KEY`     | API key (`m0_<routing>_<secret>`). **Required.**  |
| `MESH0_BASE_URL`    | Override base URL (self-hosted deployments).      |
| `MESH0_AGENT_HOST`  | metrics-agent host (default `127.0.0.1`).         |
| `MESH0_AGENT_PORT`  | metrics-agent UDP port (default `8125`).          |

### Custom HTTP client

`Client` accepts any PSR-18 client. Bring your own to share connection
pooling, plug in middleware, or run against a fake in tests:

```php
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;

$guzzle = new Guzzle(['timeout' => 5]);
$factory = new HttpFactory();

$mesh0 = new Client(Config::fromEnv(), $guzzle, $factory, $factory);
```

---

## Errors

All exceptions extend `Mesh0\Exception\Mesh0Exception`. The most common
subclasses are:

| Exception                    | Status     | When                                    |
| ---------------------------- | ---------- | --------------------------------------- |
| `AuthenticationException`    | 401 / 403  | Missing, malformed, or revoked API key. |
| `BadRequestException`        | 4xx        | Payload rejected by validation.         |
| `NotFoundException`          | 404        | Resource doesn't exist.                 |
| `RateLimitException`         | 429        | Inspect `->retryAfter`.                 |
| `ServerException`            | 5xx        | mesh0 internal error; `->errorId` set.  |
| `NetworkException`           | —          | Transport-level failure (DNS, TLS, …).  |
| `ConfigurationException`     | —          | Invalid `Config`.                       |

The transport retries idempotent failures (`5xx`, `429`, transport errors)
up to `Config::maxRetries` with exponential backoff and jitter; the
`Retry-After` header is honored when present.

---

## Development

```sh
composer install
composer test     # PHPUnit
composer stan     # PHPStan level 9
composer cs       # PHP-CS-Fixer (PSR-12)
composer ci       # All of the above
```

---

## License

MIT — see [LICENSE](LICENSE).
