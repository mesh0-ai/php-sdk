# Changelog

All notable changes to `mesh0/sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 4.0.0 - 2026-05-10

`status` and `duration_ms` are no longer top-level wire fields. The platform
no longer ships them as TQL builtins either — what counts as an "error" or
a meaningful "duration" is domain-specific, and reserving these as the
privileged metrics baked an opinion into the row format. They're now
ordinary `attributes` keys; alias or promote them on the project schema if
you want them queryable in TQL.

### Removed
- `Event::$durationMs` / `Event::$status` properties.
- `EventBuilder::withDurationMs()` / `EventBuilder::withStatus()`.
- `Mesh0\Event\Status` enum.
- `Mesh0Logger`: `duration_ms` is no longer a reserved context key (it
  flows into `attributes` like any other context entry); the logger no
  longer stamps `status=error` for `error`-and-above levels or for
  `exception` context — error semantics live in `error.type` /
  `error.message` attributes.
- `Tracer::exit()` no longer takes a `Status` argument; pass any status
  signal as an attribute (`['status' => 'error', ...]`) instead. The
  closure form of `Tracer::span()` no longer touches a status field on
  throw — it just lets the span event emit and re-throws.

### Migration
Move what you used to set top-level into `attributes`:

```php
// Before (3.x):
Event::now()
    ->withStatus(Status::Error)
    ->withDurationMs(142.5)
    ->withAttribute('order_id', 'ord_1');

// After (4.x):
Event::now()
    ->withAttributes([
        'status'      => 'error',
        'duration_ms' => 142.5,
        'order_id'    => 'ord_1',
    ]);
```

For TQL, alias `status` and `duration_ms` on the project schema page
(`POST /aliases`) so they resolve in filters/groupBys/metrics.

## 3.0.0 - 2026-05-09

The Tracer no longer has any magical attribute injection. Span name and
error metadata are normal attributes on the same footing as everything
else in `attributes` — the SDK does not write keys on the caller's
behalf.

### Removed
- `Tracer::enter(string $operation, array $attributes)` →
  `Tracer::enter(array $attributes)`. Pass `['span.name' => '...']`
  yourself.
- `Tracer::span(string $operation, array $attributes, callable $fn)` →
  `Tracer::span(array $attributes, callable $fn)`.
- `Tracer::exitWithException()` — redundant once it stopped injecting
  `error.type` / `error.message`. On the manual error path, call
  `exit($h, Status::Error, ['error.type' => ..., 'error.message' => ...])`.
- `SpanHandle::$operation` field — no longer captured.
- Auto-injection of `attributes["span.name"]` from the dropped
  `operation` arg, and of `attributes["error.type"]` /
  `attributes["error.message"]` on the error path. The closure form of
  `span()` still flips status to `error` on throw, but adds no
  attributes.

### Changed
- The "exit closed a non-top span" warning context now reports
  `closed_span_id` and `dropped_span_ids` instead of operation names
  (since operation no longer exists as a separate concept).

## 2.0.0 - 2026-05-09

The `/v1/events` wire contract is now intentionally narrow: identity,
time, status, plus two open bins (`attributes` queryable, `data`
opaque). The backend runs `DisallowUnknownFields`, so the 1.x
top-level fields all had to fold into `attributes` / `data` or
disappear. This is a major break — no backwards-compat shims.

### Removed
- Top-level `Event` fields: `appId`, `environment`, `operation`,
  `model`, `usage`, `userId`, `sessionId`, `tools`, `messages`,
  `errorType`, `errorMessage`, `finishReason`.
- `Mesh0\Event\Model` and `Mesh0\Event\Usage` value objects (no
  longer first-class — fold their data into `attributes`).
- Corresponding `EventBuilder` methods: `withApp()`, `withModel()`,
  `withUsage()`, `withUser()`, `withSession()`, `withTools()`,
  `withMessages()`, `withFinishReason()`, `withError()`.
- `appId` and `environment` constructor parameters on
  `Mesh0\Logger\Mesh0Logger` and `Mesh0\Trace\Tracer`.

### Added
- `EventBuilder::withData(array)` — write to the opaque `data` bin
  (large payloads, LLM message arrays, raw req/resp).
- `EventBuilder::withAttributes(array)` and `withAttribute(key, value)`
  — merge semantics, repeated calls accumulate.
- `Mesh0\Event\Status` enum (`success` / `error`) replaces the
  freeform string status.
- `Mesh0Logger` constructor accepts an optional `?LoggerInterface
  $fallback` for diagnostics about swallowed delivery errors and
  malformed caller input. Defaults to `NullLogger` so misconfigured
  callers stay silent unless they opt in. `Client::logger()` exposes
  the same parameter.

### Changed
- `Mesh0Logger` now throws `Psr\Log\InvalidArgumentException` on
  unknown PSR-3 levels (per the spec), rather than silently
  coercing to `INFO`.
- `Mesh0Logger` no longer stamps `status=success` on info / debug /
  notice / warning records — only error records carry a status. Keeps
  `status=success` dashboards from being polluted by routine logs.
- `Mesh0Logger` flush / shutdown / destruct paths now route swallowed
  throwables to the fallback logger so delivery failures are visible.
  Malformed reserved context values (`trace_id` not a string,
  `duration_ms` not numeric, `exception` not a `Throwable`,
  `parent_span_id` without `span_id`) emit a fallback warning instead
  of being silently dropped.
- `Mesh0Logger::interpolate()` renders non-stringable placeholder
  values as `<non-stringable type>` instead of leaving the literal
  `{key}` text in the rendered message.
- `EventBuilder::withTraceId()`, `withSpan()`, `withEventId()` reject
  empty strings; `withDurationMs()` rejects negative or non-finite
  values. Throws `InvalidArgumentException` early instead of letting
  malformed identities reach the wire.
- `Event::toArray()` re-zones the timestamp to UTC before formatting.
  A non-UTC `DateTimeImmutable` no longer ships a wrong instant with
  a stray `Z` suffix.
- `Tracer::span()`, `Tracer::finish()` wrap sink writes and exit
  paths in try/catch so a sink failure cannot unwind out and corrupt
  the parent stack — the loss is logged through the configured PSR-3
  logger instead.
- `Mesh0\Trace\Tracer` reserved context-key partitioning in
  `Mesh0Logger`: `event_id`, `trace_id`, `span_id`, `parent_span_id`,
  `duration_ms`, and `exception` are lifted to wire fields and never
  leak back into `attributes`.

### Migration

```diff
- Event::now()
-     ->withApp('checkout', 'prod')
-     ->withUser('user_42')
-     ->withModel(new Model('anthropic', 'claude-opus-4-7'))
-     ->withUsage(new Usage(promptTokens: 1240, completionTokens: 380))
-     ->withMessages($messages)
+ Event::now()
+     ->withAttributes([
+         'app.id'                     => 'checkout',
+         'app.environment'            => 'prod',
+         'user.id'                    => 'user_42',
+         'gen_ai.system'              => 'anthropic',
+         'gen_ai.request.model'       => 'claude-opus-4-7',
+         'gen_ai.usage.input_tokens'  => 1240,
+         'gen_ai.usage.output_tokens' => 380,
+     ])
+     ->withData(['messages' => $messages]);
```

```diff
- $logger = $mesh0->logger(appId: 'web', environment: 'prod');
+ $logger = $mesh0->logger(defaults: [
+     'app.id'          => 'web',
+     'app.environment' => 'prod',
+ ]);
```

```diff
- $tracer = $mesh0->tracer(appId: 'agents', environment: 'prod');
+ $tracer = $mesh0->tracer();
+ // Stamp app.id / app.environment via attributes on each span:
+ $tracer->span('agent.run', [
+     'app.id'          => 'agents',
+     'app.environment' => 'prod',
+ ], $fn);
```

Pair with backend `>= 2.0.0` (the narrowed `EventRow` schema).

## 1.0.0 - 2026-05-08

UDS-DGRAM is now the only transport for the local metrics-agent. UDP
support has been removed; the SDK no longer speaks `udp://host:port`.
This is a breaking change — see the migration notes below.

### Removed
- `UdpMetricSink` and `UdpEventSink`. Replaced by `AgentMetricSink`
  (`Mesh0\Metrics\AgentMetricSink`) and `AgentEventSink`
  (`Mesh0\Event\AgentEventSink`), both of which require an absolute
  Unix-domain socket path.
- `Config::$metricsAgentHost`, `Config::$metricsAgentPort`, and the
  related `DEFAULT_METRICS_AGENT_HOST` / `DEFAULT_METRICS_AGENT_PORT`
  constants. Replaced by `Config::$agentSocketPath`.
- Environment variables `MESH0_AGENT_HOST` and `MESH0_AGENT_PORT`.
  Replaced by `MESH0_AGENT_SOCKET`.
- `Metrics::udp(host, port)` static factory. Replaced by
  `Metrics::agent(socketPath)`.
- `Events::udp(host, port, logger, socketPath)`. Replaced by
  `Events::agent(socketPath, logger)`.
- The `host` and `port` parameters on `Client::metrics()`. The new
  signature is `metrics(?string $socketPath, array $defaultTags, ?MetricSink $sink)`.

### Changed
- `Client::metrics()` and `Events::agent()` now throw
  `ConfigurationException` when no `agentSocketPath` is configured (no
  silent UDP loopback fallback).
- Sink constructors validate the socket path: must be absolute and
  ≤104 bytes (the macOS/BSD `sun_path` floor).

### Migration

```diff
- export MESH0_AGENT_HOST=127.0.0.1
- export MESH0_AGENT_PORT=8125
+ export MESH0_AGENT_SOCKET=/run/mesh0/agent.sock
```

```diff
- $metrics = $mesh0->metrics(host: 'mesh0-agent', port: 9125);
+ $metrics = $mesh0->metrics(socketPath: '/run/mesh0/agent.sock');

- $sink = $mesh0->events->udp();
+ $sink = $mesh0->events->agent();

- new UdpMetricSink('127.0.0.1', 8125);
+ new AgentMetricSink('/run/mesh0/agent.sock');
```

Pair with metrics-agent `>= 0.3.0` and
`MESH0_LISTEN_ADDR=unix:///run/mesh0/agent.sock`.

## 0.5.0 - 2026-05-08

### Added
- `Config::$metricsAgentSocketPath` (and `MESH0_AGENT_SOCKET` env var):
  optional Unix-domain socket path. When set, both `UdpMetricSink` and
  `UdpEventSink` open `udg://<path>` (UDS-DGRAM) against the local
  metrics-agent instead of `udp://host:port`. Lifts the ~64 KB UDP
  fragmentation ceiling and avoids the IP stack on a single host.
- New constructor parameter `?string $socketPath` on `UdpMetricSink` and
  `UdpEventSink`. When set, takes precedence over `host` / `port`.
- `Client::metrics(... ?string $socketPath = null)` and
  `Events::udp(... ?string $socketPath = null)` accept per-call overrides.

### Notes
- Pair with metrics-agent `>= 0.3.0` and `MESH0_LISTEN_ADDR=unix:///path`.
- Class names (`UdpMetricSink`, `UdpEventSink`) are unchanged for backward
  compatibility — UDS-DGRAM is just a different concrete transport for
  the same "local agent sink" role.

## 0.4.0 - 2026-05-08

### Added
- `Mesh0\Trace\Tracer`: nested-span context for instrumenting trees of
  operations (no-code blocks, request handlers, job pipelines). Manages a
  per-execution `trace_id` and a stack of `span_id`s; each closed span emits
  exactly one event via the configured `EventSink` carrying `trace_id`,
  `span_id`, `parent_span_id`, and `duration_ms`.
- `Tracer::span($op, $attrs, $fn)` closure form (exception-safe), plus
  `enter()` / `exit()` / `exitWithException()` for cases where a closure
  doesn't fit. `reset()` clears state between requests in long-lived workers
  and warns through PSR-3 if the stack leaked.
- `Tracer::startTrace($traceparent)` adopts an incoming W3C `traceparent`
  header so the next root span links to an upstream trace.
- `Mesh0\Event\EventSink` interface — minimal `send(Event|EventBuilder)`
  contract that `UdpEventSink` now implements; alternative transports and
  test stubs plug in here.
- `Mesh0Logger` accepts an optional `Tracer`. Log records emitted inside an
  active span auto-stamp `trace_id` / `span_id` from the tracer when the
  caller did not supply them in the PSR-3 context.
- `Client::tracer(?appId, ?environment, ?logger)` factory builds a `Tracer`
  pre-wired to the local UDP event sink. `Client::logger(...)` gains a
  `?Tracer $tracer` parameter so PSR-3 logs auto-correlate without manually
  constructing `Mesh0Logger`.

### Notes
- Spans are independent on the wire — there is no "session start" or
  "session end" marker. The metrics-agent forwards each datagram verbatim;
  ClickHouse reassembles the trace via `trace_id` at query time.
- No changes to `Event`, `EventBuilder`, `UdpEventSink`'s wire format, or
  any HTTP resource. Adding `EventSink` to `UdpEventSink` is non-breaking.
- Closing a non-top span (the matching handle is below other open frames)
  emits the matched span and warns through PSR-3 with the count and
  operation names of the dropped inner frames, so a missed `exit()` upstream
  is observable rather than silent data loss.

## 0.3.0 - 2026-05-08

### Added
- `Mesh0\Event\UdpEventSink`: low-latency (~5µs/call) UDP event sink that
  fires JSON datagrams at a co-located mesh0 metrics-agent sidecar (default
  `127.0.0.1:8125`). The agent batches and forwards to `/v1/events` over
  HTTPS.
- `$client->events->udp(?host, ?port, ?logger)` accessor that returns a
  shared `UdpEventSink` configured from `Config::$metricsAgentHost` /
  `metricsAgentPort`. Call with overrides for a fresh instance.
- `UdpEventSink::send(Event|EventBuilder)`, `sendMany(iterable)`, `close()`,
  and a destructor that releases the socket. Lazy socket open, error
  swallowing, and one-warning-per-state-transition logging mirror
  `UdpMetricSink`.
- 32 KB per-datagram cap; oversize events are dropped with a single warning
  rather than thrown.

### Notes
- The UDP path is **at-most-once**. Use `$client->events->send(...)` for
  at-least-once durability via direct HTTPS POST.
- Existing `$client->events->send(...)` HTTPS behavior is unchanged.
