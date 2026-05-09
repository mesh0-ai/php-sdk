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
- **Nested-span instrumentation** — `Mesh0\Trace\Tracer` for trees of operations (no-code blocks, request handlers, job pipelines)
- **Low-latency UDS-DGRAM path** — `~5µs/call` via the local mesh0 metrics-agent sidecar
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

// Send a single event. The wire shape is intentionally narrow — identity,
// time, status, plus two open bins (`attributes` queryable, `data` opaque).
// Anything domain-specific goes inside attributes / data.
$mesh0->events->send(
    Event::now()
        ->withAttributes([
            'app.id'          => 'checkout',
            'app.environment' => 'prod',
            'span.name'       => 'charge.captured',
            'user.id'         => 'user_42',
            'order_id'        => 'ord_123',
            'amount_usd'      => 19.99,
        ]),
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
$logger = $mesh0->logger(defaults: [
    'app.id'          => 'web',
    'app.environment' => 'prod',
]);

$logger->info('user {user} signed up', ['user' => 'alice', 'plan' => 'pro']);

try {
    chargeCard($order);
} catch (\Throwable $e) {
    $logger->error('charge failed', [
        'exception' => $e,
        'order_id'  => $order->id,
        'user.id'   => $order->userId,
    ]);
}
```

Context keys that map to wire-level event fields are lifted out; everything
else is merged into `attributes`:

| Context key       | Lifted to top-level wire field |
| ----------------- | ------------------------------ |
| `event_id`        | `event_id`                     |
| `trace_id`        | `trace_id`                     |
| `span_id`         | `span_id`                      |
| `parent_span_id`  | `parent_span_id`               |
| `duration_ms`     | `duration_ms`                  |

Plus: `exception` (Throwable) sets `status=error` and writes `error.type`
and `error.message` into `attributes`. Severity ≥ `error` also sets
`status=error`. The interpolated message and `log.level` always land in
`attributes`. Records are buffered in memory and flushed on `flush()`,
when the buffer fills, and on shutdown.

If you pass a [`Tracer`](#instrumenting-nested-operations-tracer) to
`logger(...)`, log records emitted inside an active span pick up
`trace_id` / `span_id` automatically when you don't supply them yourself.

### Laravel

```php
// config/logging.php
'channels' => [
    'mesh0' => [
        'driver' => 'custom',
        'via'    => fn () => Mesh0\Client::fromEnv()->logger(defaults: [
            'app.id'          => config('app.name'),
            'app.environment' => config('app.env'),
        ]),
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
        # Pass defaults via the constructor's $defaults argument
        arguments:
            $defaults:
                app.id: '%env(APP_NAME)%'
                app.environment: '%kernel.environment%'
```

---

## Sending events directly

The `Event` builder is fluent and immutable — every `with*` call returns a
new builder.

```php
$mesh0->events->send(
    Event::now()
        ->withDurationMs(820)
        ->withTraceId($traceId)
        ->withAttributes([
            'app.id'                       => 'agents',
            'app.environment'              => 'prod',
            'span.name'                    => 'agent.run',
            'gen_ai.system'                => 'anthropic',
            'gen_ai.request.model'         => 'claude-opus-4-7',
            'gen_ai.usage.input_tokens'    => 1_240,
            'gen_ai.usage.output_tokens'   => 380,
            'gen_ai.usage.cost_usd'        => 0.0184,
            'tools'                        => ['search', 'retrieve'],
            'workflow'                     => 'onboarding',
        ])
        // Big payloads (LLM message arrays, raw req/resp) go in `data` —
        // opaque, not TQL-queryable, only shown on single-event drilldown.
        ->withData(['messages' => $messages]),
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

## Metrics (statsd / DogStatsD over UDS-DGRAM)

For high-frequency counters, gauges, and timings — the kind of telemetry
that shouldn't go through the request-blocking HTTPS path — point at a
co-located [mesh0 metrics-agent](https://github.com/mesh0-ai/metrics-agent)
sidecar over its Unix datagram socket. UDP support was removed in 1.0;
the SDK speaks `udg://<path>` exclusively.

Set the agent's bind path once via env or `Config`:

```sh
export MESH0_AGENT_SOCKET=/run/mesh0/agent.sock
```

```php
$metrics = $mesh0->metrics(); // reads MESH0_AGENT_SOCKET / Config::$agentSocketPath

$metrics->increment('checkout.charge', tags: ['tier' => 'pro']);
$metrics->gauge('queue.depth', 42);
$metrics->timing('db.query_ms', 12.4, tags: ['table' => 'orders']);
$metrics->histogram('upload.bytes', 8192);

// Convenience: time a block; metric is emitted whether $fn returns or throws.
$rows = $metrics->time('db.select_ms', fn () => $pdo->query($sql)->fetchAll());
```

The socket is opened lazily on the first send, so `$mesh0->metrics()`
does no I/O. Per-call override:

```php
$metrics = $mesh0->metrics(socketPath: '/tmp/mesh0-test.sock', defaultTags: [
    'service' => 'checkout',
    'env'     => 'prod',
]);
```

The agent must be configured with a matching `MESH0_LISTEN_ADDR`
(`unix:///run/mesh0/agent.sock`). Calling `metrics()` (or
`events()->agent()`) without an `agentSocketPath` set throws
`ConfigurationException` — there is no UDP loopback fallback.

### Failure semantics

Datagram send failures (peer unreachable, agent not running) are
swallowed — the request path never throws on transport. Pass an optional
PSR-3 logger via `new AgentMetricSink($path, $log)` to surface a single
warning per state transition (open failure, write failure, oversize
drop). The open-failure latch is terminal for the lifetime of the sink —
long-lived workers that need to recover from a transient agent restart
should construct a fresh sink rather than rely on auto-reopen. Malformed
metric names or tags throw `ConfigurationException` so programmer errors
fail loudly in development rather than silently disappearing.
`sampleRate` outside `(0, 1]` is clamped (≤0 drops, ≥1 always emits)
rather than throwing.

---

## Sending events over UDS-DGRAM (low-latency)

For short-lived processes (PHP request handlers, CLI workers) that can't
afford an HTTPS roundtrip per event, fire events at the same
metrics-agent sidecar as JSON datagrams (~5µs per call):

```php
$agent = $mesh0->events->agent(); // reads MESH0_AGENT_SOCKET / Config::$agentSocketPath

$agent->send(
    Mesh0\Event\Event::now()
        ->withAttributes([
            'app.id'          => 'checkout',
            'app.environment' => 'prod',
            'span.name'       => 'charge.succeeded',
            'order_id'        => 'ord_123',
        ]),
);

// Bulk loop — the agent batches before forwarding to mesh0.
$agent->sendMany([$e1, $e2, $e3]);
```

The socket is opened lazily on the first send. Datagrams larger than
32KB are dropped with a single warning (pass a PSR-3 logger to observe),
and transport errors are swallowed — `send()` never throws.

This path is **at-most-once**: if the local agent is down or the kernel
drops the datagram, the event is gone. For at-least-once durability, use
`$mesh0->events->send(...)` which POSTs to `/v1/events` directly.

---

## Instrumenting nested operations (Tracer)

For trees of nested operations — no-code block executions, request → job
pipelines, anything where a parent's wall-clock includes its children —
use `Mesh0\Trace\Tracer`. It manages a per-execution `trace_id` and a stack
of `span_id`s, and emits exactly one event per closed span through any
`EventSink` (typically the same agent sink shown above):

```php
$tracer = $mesh0->tracer();

// Closure form — exception-safe, auto-pop, recommended:
$result = $tracer->span('block.if', ['block_id' => 'b_123'], function () use ($tracer) {
    return $tracer->span('block.http_request', ['url' => $url], fn () => $client->get($url));
});

// Manual form — when a closure doesn't fit (e.g. block dispatchers):
$h = $tracer->enter('block.loop', ['block_id' => 'b_456']);
try {
    // run block...
    $tracer->exit($h, attributes: ['iterations' => $n]);
} catch (\Throwable $e) {
    $tracer->exitWithException($h, $e);
    throw $e;
}
```

Each `enter`/`exit` pair becomes one independent datagram on the way
out; the metrics-agent forwards them verbatim and ClickHouse reassembles
the trace via `trace_id` at query time. There is no "session start" or
"session end" — children always close before parents because the parent's
frame is still on the stack while children run.

**Long-lived workers (FrankenPHP, RoadRunner, Swoole)** must call
`$tracer->reset()` between requests so trace state doesn't leak across
them. A non-empty stack at reset time logs a warning through the PSR-3
logger you pass to the constructor.

**Adopting an incoming trace** (W3C `traceparent` header):

```php
$tracer->startTrace($_SERVER['HTTP_TRACEPARENT'] ?? null);
// First enter() of the request now links to the upstream parent span.
```

**Logs that auto-correlate to the active span:** pass the tracer when
building the logger and any record emitted inside a `span()` will pick
up `trace_id` / `span_id` automatically when not supplied in the PSR-3
context:

```php
$logger = $mesh0->logger(
    defaults: ['app.id' => 'no-code-runtime'],
    tracer: $tracer,
);

$tracer->span('block.http_request', [], function () use ($logger) {
    $logger->info('calling upstream'); // trace_id / span_id stamped automatically
});
```

---

## Querying

```php
// Only `timestamp, duration_ms, project.id, status, trace.id, span.id,
// parent_span.id` are TQL builtins. Anything else (e.g. span.name) must
// be exposed via a per-project alias or promoted column — set those up
// in the dashboard, then reference them by their alias name here.
$rows = $mesh0->query->run([
    'from'    => 'events',
    'select'  => ['span_name', 'count()'],
    'where'   => ['status' => 'error'],
    'groupBy' => ['span_name'],
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

| Variable             | Description                                                       |
| -------------------- | ----------------------------------------------------------------- |
| `MESH0_API_KEY`      | API key (`m0_<routing>_<secret>`). **Required.**                  |
| `MESH0_BASE_URL`     | Override base URL (self-hosted deployments).                      |
| `MESH0_AGENT_SOCKET` | Absolute path to the metrics-agent's Unix datagram socket. Required for `metrics()` / `events->agent()`. |

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
