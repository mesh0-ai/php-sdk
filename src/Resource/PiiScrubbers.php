<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Http\Transport;

/**
 * `/v1/pii-scrubbers` and `/v1/pii-scrub-mode` — project-scoped CRUD for
 * PII redaction rules and the project-wide enforcement switch. Auth is
 * the project API key (`m0_<routing>_<secret>`), with `pii:read` /
 * `pii:write` scopes required on the key.
 *
 * `$ref` accepts either the rule's UUID or its slug.
 *
 * Wire shapes are snake_case. PATCH semantics are field-omission based
 * (omitted = unchanged); the SDK passes the payload through as an
 * opaque assoc array so new server fields land without an SDK release.
 *
 * POST is not retried; PATCH / DELETE / PUT are.
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
     * Create a scrubber rule. Not retried — a transient 5xx retry could
     * mint a duplicate rule.
     *
     * @param array<string, mixed> $input
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
     * existing values. Built-in scrubbers accept `enabled` toggles only;
     * other fields are server-rejected.
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
     * Delete a custom scrubber. Built-in scrubbers cannot be deleted.
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
