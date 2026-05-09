# Changelog

All notable changes to `mesh0/sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

### Notes
- Spans are independent on the wire — there is no "session start" or
  "session end" marker. The metrics-agent forwards each datagram verbatim;
  ClickHouse reassembles the trace via `trace_id` at query time.
- No changes to `Event`, `EventBuilder`, `UdpEventSink`'s wire format, or
  any HTTP resource. Adding `EventSink` to `UdpEventSink` is non-breaking.

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
