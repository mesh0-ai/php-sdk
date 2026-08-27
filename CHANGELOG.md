# Changelog

All notable changes to `mesh0/sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- **`AgentEventSink` stamps `_project` and `_token` on outgoing datagrams.**
  The agent routes by a top-level `_project` and, in open mode, authenticates
  each datagram with a top-level `_token` (metrics-agent >= 0.4.0). The sink
  previously sent neither, so a multi-tenant agent had no way to route its
  events and an open-mode agent had no way to authenticate them.

  The project comes from `MESH0_AGENT_PROJECT` / `Config::$agentProject`. The
  credential is wired automatically by `Events::agent()` when the configured
  `apiKey` is an open-mode instance token — in open mode the same JWT
  authenticates ingest and the management API, so a configured client needs
  nothing further. An `m0_` project key is deliberately **not** forwarded: it
  is what an operator publishes in the agent's keys file, and sending it
  inline would bypass the routing table they configured.

  `$token` accepts a callable as well as a string, and for anything
  longer-lived than one request it must be one — an instance token is short
  lived and rotates, so a string captured at construction goes stale in a
  worker. A provider returning `null` emits without a credential (the
  documented degrade when minting fails); an empty token is never sent, since
  the agent reads a present-but-unusable credential as a hard drop.

  `_project` / `_token` are written last, so an event carrying a colliding
  attribute cannot silently re-route or re-authenticate itself.

- `Config::isInstanceToken()` exposes the existing credential-shape check so
  callers can tell an instance token from an `m0_` key without re-deriving it.

## 1.4.0 — 2026-08-03

### Added

- **`Config` accepts open-mode instance tokens.** A workspace-scoped instance
  token authenticates the whole project-management API this SDK wraps, but
  `Config` rejected any `apiKey` without an `m0_` / `m0u_` prefix — so a JWT
  threw `ConfigurationException` at construction and every management resource
  was unreachable for a cluster running open mode. Instance tokens are now a
  third accepted credential shape alongside the two prefixes.

  The check is deliberately a shape test, not a validation: three unpadded
  base64url segments whose first decodes to a JOSE header carrying `alg`.
  Signature, claims and expiry remain the server's to judge — it is the only
  party holding the key. Decoding the header is what keeps the arm narrow;
  counting segments alone would accept `api.mesh0.ai`. Padded base64 is
  rejected, since mesh0 requires unpadded base64url on the wire.

  Purely additive: every credential accepted before is still accepted.

## 1.3.0 — 2026-05-24

### Added

- **`Schema` resource** (`$client->schema`) — project-scoped wrapper over
  `/v1/schema`, `/v1/aliases*`, and `/v1/promoted/*` for managing aliases
  (friendly names mapped to JSON paths in the event `attributes` blob)
  and promoting them to typed ClickHouse columns. Includes
  `get()` / `listAliases()` / `listPromoted()` for reads,
  `createAlias()` / `deleteAlias()` for alias CRUD, and
  `promoteAlias()` / `promoteAliasEnvelope()` / `cancelPromotion()` /
  `demotePromoted()` for the promotion lifecycle. Requires `schema:read`
  / `schema:write` scopes on the project key.
- **`Usage` resource** (`$client->usage`) — project-scoped reads over
  `/v1/usage/summary` (current cap-period totals) and `/v1/usage`
  (time-bucketed series, `day` or `month` granularity). Requires
  `usage:read` on the project key.
- **README** — extended "Control-plane resources" section to cover
  `schema` and `usage`.

### Notes

- `createAlias`, `promoteAlias`, and `cancelPromotion` opt out of
  automatic retry — none of the corresponding routes are guarded by an
  `Idempotency-Key` middleware server-side, and `cancelPromotion`'s
  KILL+DROP sequence is destructive on a replay. `deleteAlias` /
  `demotePromoted` are naturally idempotent and stay on the default
  retry policy.
- The schema endpoints use camelCase on the wire (`jsonPath`,
  `fieldType`); usage endpoints use snake_case (`event_count`,
  `bytes_in`, `top_day`). Both are passed through unmodified.
