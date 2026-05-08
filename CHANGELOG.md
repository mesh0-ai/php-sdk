# Changelog

All notable changes to `mesh0/sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
