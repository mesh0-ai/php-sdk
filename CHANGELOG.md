# Changelog

All notable changes to `mesh0/sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
