# Changelog

All notable changes to `mesh0/sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
