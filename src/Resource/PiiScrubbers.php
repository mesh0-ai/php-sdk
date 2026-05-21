<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Http\Transport;

/**
 * `/v1/pii-scrubbers` and `/v1/pii-scrub-mode` — project-scoped CRUD for
 * PII redaction rules and the project-wide enforcement switch. Auth is
 * the project API key (`m0_<routing>_<secret>`); the key's `pii:read`
 * and `pii:write` scopes are enforced server-side.
 *
 * Refs (`$ref`) may be either a UUID or the rule's slug — the backend
 * resolves both via `Service.LookupID`.
 *
 * Wire shapes are snake_case, mirroring `internal/pii.Rule`. The
 * `RuleInput` PATCH semantics on the server are field-pointer based
 * (omitted = unchanged); the SDK passes the payload through as an
 * opaque assoc array rather than promoting it into a PHP DTO so new
 * fields land without an SDK release.
 *
 * Unlike `/v1/alerts`, the scrubber create endpoint has no server-side
 * `Idempotency-Key` middleware, so the SDK opts out of automatic retry
 * on POST (same policy as `/v1/user/*` creates) — a retried 5xx could
 * otherwise mint a second rule the caller never sees. PATCH / DELETE /
 * PUT remain on the default retry path: they're operation-idempotent at
 * the API contract level.
 */
final class PiiScrubbers
{
    public function __construct(private readonly Transport $http)
    {
    }

    /**
     * Raw list envelope: `{ scrubbers, mode, builtins, egress_sources,
     * enforcement_points }`. Use when you need the project mode or the
     * canonical enumeration of builtins / sources / points alongside
     * the rules (e.g. to render a picker without hard-coding the enum).
     *
     * @return array<string, mixed>
     */
    public function listEnvelope(): array
    {
        return $this->http->get('/v1/pii-scrubbers');
    }

    /**
     * Scrubber rules for the project. Drops the surrounding envelope —
     * call {@see listEnvelope()} if you also need `mode` / `builtins` /
     * `egress_sources` / `enforcement_points`.
     *
     * @return list<array<string, mixed>>
     */
    public function listScrubbers(): array
    {
        $resp = $this->http->get('/v1/pii-scrubbers');
        /** @var list<array<string, mixed>> $scrubbers */
        $scrubbers = is_array($resp['scrubbers'] ?? null) ? array_values($resp['scrubbers']) : [];
        return $scrubbers;
    }

    /**
     * Fetch a scrubber by id-or-slug.
     *
     * @return array<string, mixed>
     */
    public function getScrubber(string $ref): array
    {
        $resp = $this->http->get('/v1/pii-scrubbers/' . rawurlencode($ref));
        /** @var array<string, mixed> $scrubber */
        $scrubber = is_array($resp['scrubber'] ?? null) ? $resp['scrubber'] : [];
        return $scrubber;
    }

    /**
     * Create a scrubber rule. Disables retries — the endpoint has no
     * `Idempotency-Key` middleware, so a transient 5xx retry could
     * mint a duplicate rule.
     *
     * @param array<string, mixed> $input RuleInput payload (see backend `pii.RuleInput`).
     * @return array<string, mixed> The created scrubber.
     */
    public function createScrubber(array $input): array
    {
        $resp = $this->http->post('/v1/pii-scrubbers', $input, [], idempotent: false);
        /** @var array<string, mixed> $scrubber */
        $scrubber = is_array($resp['scrubber'] ?? null) ? $resp['scrubber'] : [];
        return $scrubber;
    }

    /**
     * Update a scrubber. PATCH semantics: omitted fields keep their
     * existing values (see `RuleInput` in the backend). Built-in
     * scrubbers can have `enabled` toggled but other fields are
     * server-rejected with `builtin_immutable`.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateScrubber(string $ref, array $input): array
    {
        $resp = $this->http->patch('/v1/pii-scrubbers/' . rawurlencode($ref), $input);
        /** @var array<string, mixed> $scrubber */
        $scrubber = is_array($resp['scrubber'] ?? null) ? $resp['scrubber'] : [];
        return $scrubber;
    }

    /**
     * Delete a custom scrubber. Built-in scrubbers cannot be deleted —
     * the server rejects with `builtin_immutable`.
     *
     * @return array<string, mixed>
     */
    public function deleteScrubber(string $ref): array
    {
        return $this->http->delete('/v1/pii-scrubbers/' . rawurlencode($ref));
    }

    /**
     * Read the project-wide enforcement mode. One of `enforce`,
     * `audit`, or `off`.
     */
    public function getMode(): string
    {
        $resp = $this->http->get('/v1/pii-scrub-mode');
        return is_string($resp['mode'] ?? null) ? $resp['mode'] : '';
    }

    /**
     * Set the project-wide enforcement mode. Server accepts `enforce`,
     * `audit`, or `off`; other values 400. Returns the canonicalized
     * mode the server stored.
     */
    public function setMode(string $mode): string
    {
        $resp = $this->http->put('/v1/pii-scrub-mode', ['mode' => $mode]);
        return is_string($resp['mode'] ?? null) ? $resp['mode'] : '';
    }
}
