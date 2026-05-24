<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use InvalidArgumentException;
use Mesh0\Http\Transport;

/**
 * `/v1/schema`, `/v1/aliases*`, `/v1/promoted/*` — project-scoped schema
 * management. Aliases map a friendly name to a JSON path in the event
 * `attributes` blob; promoting an alias materializes a typed ClickHouse
 * column so TQL queries against it are cheap.
 *
 * Auth is the project API key (`m0_<routing>_<secret>`). The key's
 * `schema:read` scope is required to read; `schema:write` is required
 * for mutations.
 *
 * `$name` is the alias / promoted-field name (matches
 * `^[a-z_][a-z0-9_]{0,63}$` server-side). `$fieldType` is one of
 * `string`, `number`, `bool`. Wire shapes are camelCase here (unlike
 * the snake_case PII surface) because `public_api_schema.go` uses
 * camelCase struct tags — payloads are passed through unmodified.
 *
 * None of the POST endpoints are guarded by an Idempotency-Key
 * middleware on the server, so create / promote / cancel POSTs opt out
 * of automatic retry. DELETEs are naturally idempotent and use the
 * default policy.
 */
final class Schema
{
    public function __construct(private readonly Transport $http)
    {
    }

    /**
     * Raw `/v1/schema` envelope: `{ aliases: [...], promoted: [...] }`.
     * Use when both views are needed in one round trip.
     *
     * @return array{aliases: list<array<string, mixed>>, promoted: list<array<string, mixed>>}
     */
    public function get(): array
    {
        $resp = $this->http->get('/v1/schema');
        /** @var list<array<string, mixed>> $aliases */
        $aliases = is_array($resp['aliases'] ?? null) ? array_values($resp['aliases']) : [];
        /** @var list<array<string, mixed>> $promoted */
        $promoted = is_array($resp['promoted'] ?? null) ? array_values($resp['promoted']) : [];
        return ['aliases' => $aliases, 'promoted' => $promoted];
    }

    /**
     * Aliases for the project. Drops the envelope — call {@see get()}
     * if you also need `promoted`.
     *
     * @return list<array<string, mixed>>
     */
    public function listAliases(): array
    {
        return $this->get()['aliases'];
    }

    /**
     * Promoted (typed-column) fields for the project. Drops the envelope —
     * call {@see get()} if you also need `aliases`.
     *
     * @return list<array<string, mixed>>
     */
    public function listPromoted(): array
    {
        return $this->get()['promoted'];
    }

    /**
     * Create an alias. `name` matches `^[a-z_][a-z0-9_]{0,63}$` and
     * cannot collide with a builtin field or an existing promoted
     * column. `fieldType` is one of `string` / `number` / `bool`.
     * `description` is optional free-text, capped at 1024 chars.
     *
     * Not retried — a transient 5xx replay could mint a duplicate
     * alias row.
     *
     * @return array<string, mixed> The newly created alias.
     */
    public function createAlias(
        string $name,
        string $jsonPath,
        string $fieldType,
        ?string $description = null,
    ): array {
        $body = [
            'name' => $name,
            'jsonPath' => $jsonPath,
            'fieldType' => $fieldType,
            'description' => $description ?? '',
        ];
        $resp = $this->http->post('/v1/aliases', $body, [], idempotent: false);
        /** @var array<string, mixed> $alias */
        $alias = is_array($resp['alias'] ?? null) ? $resp['alias'] : [];
        return $alias;
    }

    /**
     * Delete an alias by name. Blocked server-side when a promotion
     * for the same name is in-flight (`status='materializing'`).
     *
     * @return array<string, mixed>
     */
    public function deleteAlias(string $name): array
    {
        $this->requireName($name);
        return $this->http->delete('/v1/aliases/' . rawurlencode($name));
    }

    /**
     * Promote an alias to a typed ClickHouse column. Returns 202 + the
     * `promoted` row with `status: "materializing"`; the SDK surfaces
     * the unwrapped row. Poll {@see get()} / {@see listPromoted()} for
     * `status` transitions (`materializing` → `ready` or `failed`).
     *
     * When the server falls back to a wide string column because the
     * cardinality probe failed, the response carries a
     * `warning: "cardinality_probe_degraded"` flag — use
     * {@see promoteAliasEnvelope()} to see it.
     *
     * Not retried.
     *
     * @return array<string, mixed> The unwrapped `promoted` row.
     */
    public function promoteAlias(string $name): array
    {
        $env = $this->promoteAliasEnvelope($name);
        /** @var array<string, mixed> $promoted */
        $promoted = is_array($env['promoted'] ?? null) ? $env['promoted'] : [];
        return $promoted;
    }

    /**
     * Raw `POST /v1/aliases/{name}/promote` envelope:
     * `{ promoted: {...}, warning?: "cardinality_probe_degraded" }`.
     *
     * @return array<string, mixed>
     */
    public function promoteAliasEnvelope(string $name): array
    {
        $this->requireName($name);
        return $this->http->post(
            '/v1/aliases/' . rawurlencode($name) . '/promote',
            null,
            [],
            idempotent: false,
        );
    }

    /**
     * Demote a promoted field. The PG row is removed; the underlying
     * CH column is intentionally retained for operator forensics (it
     * stops being populated but is not dropped). Blocked while the
     * promotion is still `materializing` — use {@see cancelPromotion()}
     * for that case.
     *
     * @return array<string, mixed>
     */
    public function demotePromoted(string $name): array
    {
        $this->requireName($name);
        return $this->http->delete('/v1/promoted/' . rawurlencode($name));
    }

    /**
     * Cancel an in-flight promotion (`status='materializing'`). KILLs
     * the CH mutation, DROPs the partially-materialized column, and
     * deletes the PG row. Returns 409 if the row is not in
     * `materializing` state.
     *
     * Not retried — the KILL+DROP sequence is destructive on CH and a
     * replay against a row already torn down would race with the next
     * promote attempt.
     *
     * @return array<string, mixed>
     */
    public function cancelPromotion(string $name): array
    {
        $this->requireName($name);
        return $this->http->post(
            '/v1/promoted/' . rawurlencode($name) . '/cancel',
            null,
            [],
            idempotent: false,
        );
    }

    private function requireName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('alias / promoted name cannot be empty');
        }
    }
}
