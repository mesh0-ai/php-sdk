# Claude / agent guide for the mesh0 PHP SDK

This file is the operating manual for AI assistants working in this repo.
It documents the architectural rules, the boundaries that should not be
crossed, and the workflows you should run before opening a PR.

## What this package is

A thin, strictly-typed PHP client for mesh0's public HTTP API
(`https://api.mesh0.ai`). It must remain:

- **Framework-agnostic.** No Laravel/Symfony hard dependencies. PSR-3 +
  PSR-18 + PSR-17 only. Anything framework-specific belongs in a separate
  bridge package.
- **Side-effect free at import time.** Constructing a `Client` does no
  network I/O; `register_shutdown_function` is registered lazily on the
  first log record, not at class load.
- **Production-grade.** Strict types, immutable readonly DTOs, no `mixed`
  in public signatures (except where the wire schema is genuinely
  unstructured, e.g. `attributes`), PHPStan level 9 must stay clean.

## Layout

```
src/
├── Client.php             # public entry point
├── Config.php             # immutable config + env loader
├── Exception/             # one class per failure mode, all extend Mesh0Exception
├── Http/Transport.php     # PSR-18 wrapper: auth, JSON, retries
├── Event/                 # Event + EventBuilder + value objects (Status, Usage, Model) + EventSink
├── Logger/Mesh0Logger.php # PSR-3 logger that ships records as events
├── Trace/                 # Tracer + SpanHandle (nested-span instrumentation, in-process state)
└── Resource/              # one class per API namespace (Events, Traces, Query, Meta)
tests/
├── Support/               # MockHttpClient
└── Unit/                  # PHPUnit tests, mirror src/ layout
```

Add new endpoints under `src/Resource/` and new domain types under their
respective subdirectory. Do not add endpoint logic into `Client.php`.

## API contract — where the truth lives

The wire format is defined by mesh0's backend in
`~/mesh0/core/backend/src/routes/` and the schemas in
`~/mesh0/core/backend/src/ingest/`. When changing event payloads:

- `/v1/events` — `routes/events.ts` (zod `EventSchema`)
- `/v1/traces` — OTLP/HTTP JSON, `ingest/normalize.ts`
- `/v1/query`  — `routes/public-api.ts` + `query/tql.ts`
- `/v1/me`, `/v1/org`, `/v1/project`, `/v1/traces/:id`,
  `/v1/events`, `/v1/events/stream` — `routes/public-api.ts`

If you add a field, mirror exactly the zod-validated key (e.g.
`prompt_tokens`, not `promptTokens`) when serializing — the SDK uses
camelCase in PHP and snake_case on the wire.

## Auth

Bearer token, format `m0_<routing_id>_<secret>`. The transport adds
`Authorization: Bearer …` to every request. Never log or echo the token.

## Retry policy

The transport retries only:

- transport errors (`ClientExceptionInterface`)
- HTTP 429
- HTTP 5xx

Backoff is exponential (200ms × 2^n) with jitter, capped at 5s; `Retry-After`
overrides the computed backoff when present. Max attempts is
`Config::maxRetries + 1`. **Do not** retry 4xx other than 429 — those are
client errors and retrying just hides the bug.

Server-side ingest is idempotent on `event_id`, so retrying POSTs is safe.
If you add a write endpoint that is *not* idempotent, gate it behind a
flag — don't change the default policy.

## Error mapping

`Transport::errorFor()` is the single mapping point from HTTP status →
typed exception. New status codes go there; never throw raw `ApiException`
from individual resources.

## Testing

- Unit tests are mandatory for every new public method.
- Use `Mesh0\Tests\Support\MockHttpClient` to assert outgoing requests and
  feed canned responses. **Do not** hit the real network in tests.
- PHPStan must pass at level 9. Run `composer stan` before committing.
- Lint with `composer cs` (PHP-CS-Fixer, PSR-12 + PHP 8.2 migration).
- Full local check: `composer ci`.

## Workflow

1. Read the relevant backend route to confirm the wire shape.
2. Add or update the DTO / resource method, with strict types and PHPDoc.
3. Add unit tests using `MockHttpClient`.
4. `composer ci` must pass.
5. Update README only if the public API surface changed.

## Things to keep out of the SDK

- Direct ClickHouse / Postgres clients — the SDK talks HTTP, period.
- Framework adapters (Laravel service provider, Symfony bundle). Those
  belong in `mesh0/sdk-laravel` etc.
- Background queue workers / async exporters. The PSR-3 logger buffers in
  memory; users who need durable buffering can plug in their own queue.
- Any persistent storage (no token caching, no schema caching to disk).

## Versioning

This SDK targets the v1 API. Breaking changes to the wire format from the
backend will land as a new major version of this SDK. PHP-side breaking
changes (renaming a public method, changing a constructor signature) also
require a major bump.
