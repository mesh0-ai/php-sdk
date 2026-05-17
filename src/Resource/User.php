<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Http\Transport;

/**
 * `/v1/user/*` — the user-scoped control plane. Backed by a personal user
 * API key (`m0u_…`) rather than a project key, and lets you manage the
 * orgs, projects, and project API keys the user has access to.
 *
 * Auth is the same Bearer mechanism as the project surfaces, so this
 * resource shares the same {@see Transport}. Returned payloads are kept
 * as associative arrays — the wire shape is documented in the backend
 * `routes/orgs.go` / `routes/organizations.go` handlers and we don't
 * promote those into PHP DTOs (yet) because the shape evolves
 * faster than the SDK release cadence.
 */
final class User
{
    public function __construct(private readonly Transport $http)
    {
    }

    // --- /v1/user/me ------------------------------------------------------

    /**
     * The authenticated user, plus a summary of the API key in use.
     *
     * Response shape: `{ user: {...}, apiKey?: { id, scope } }`.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->http->get('/v1/user/me');
    }

    // --- /v1/user/orgs ----------------------------------------------------

    /**
     * List every org the user is a member of (excluding orgs in
     * `deleting` status).
     *
     * @return list<array<string, mixed>>
     */
    public function listOrgs(): array
    {
        $resp = $this->http->get('/v1/user/orgs');
        /** @var list<array<string, mixed>> $orgs */
        $orgs = is_array($resp['organizations'] ?? null) ? array_values($resp['organizations']) : [];
        return $orgs;
    }

    /**
     * Create a new org. `slug` is optional — when omitted, the backend
     * derives one from `name` and appends a suffix if it collides.
     *
     * @return array<string, mixed> The newly created organization row.
     */
    public function createOrg(string $name, ?string $slug = null): array
    {
        $body = ['name' => $name];
        if ($slug !== null) {
            $body['slug'] = $slug;
        }
        $resp = $this->http->post('/v1/user/orgs', $body);
        /** @var array<string, mixed> $org */
        $org = is_array($resp['organization'] ?? null) ? $resp['organization'] : [];
        return $org;
    }

    /**
     * Fetch a single org by slug. Response also includes the caller's
     * role on the org under the `role` key.
     *
     * @return array<string, mixed>
     */
    public function getOrg(string $slug): array
    {
        return $this->http->get('/v1/user/orgs/' . rawurlencode($slug));
    }

    /**
     * Rename an org. Admin role or higher required server-side.
     *
     * @return array<string, mixed> The updated organization row.
     */
    public function updateOrg(string $slug, string $name): array
    {
        $resp = $this->http->patch('/v1/user/orgs/' . rawurlencode($slug), ['name' => $name]);
        /** @var array<string, mixed> $org */
        $org = is_array($resp['organization'] ?? null) ? $resp['organization'] : [];
        return $org;
    }

    /**
     * Soft-delete an org. Owner-only server-side. Returns the response
     * payload (`{ ok: true, deletionGraceDays: int }`).
     *
     * @return array<string, mixed>
     */
    public function deleteOrg(string $slug): array
    {
        return $this->http->delete('/v1/user/orgs/' . rawurlencode($slug));
    }

    // --- /v1/user/orgs/{slug}/projects -----------------------------------

    /**
     * List projects in an org.
     *
     * @return list<array<string, mixed>>
     */
    public function listProjects(string $slug): array
    {
        $resp = $this->http->get($this->projectsPath($slug));
        /** @var list<array<string, mixed>> $projects */
        $projects = is_array($resp['projects'] ?? null) ? array_values($resp['projects']) : [];
        return $projects;
    }

    /**
     * Create a project. `retentionDays` defaults to 90 server-side; pass
     * a value to override. Admin role or higher required server-side.
     *
     * The full response (including any inline-schema results) is
     * returned untouched, since callers seeding aliases will want
     * `aliases` / `promoted` / `schemaErrors`.
     *
     * @param array{aliases?: list<array<string, mixed>>, promote?: list<string>}|null $schema
     * @return array<string, mixed>
     */
    public function createProject(string $slug, string $name, ?int $retentionDays = null, ?array $schema = null): array
    {
        $body = ['name' => $name];
        if ($retentionDays !== null) {
            $body['retentionDays'] = $retentionDays;
        }
        if ($schema !== null) {
            $body['schema'] = $schema;
        }
        return $this->http->post($this->projectsPath($slug), $body);
    }

    /**
     * Fetch one project. Response includes `project` and the caller's `role`.
     *
     * @return array<string, mixed>
     */
    public function getProject(string $slug, string $projectId): array
    {
        return $this->http->get($this->projectPath($slug, $projectId));
    }

    /**
     * Update a project. At least one of `name` / `retentionDays` must
     * be supplied (the server rejects an empty payload).
     *
     * @return array<string, mixed> The updated project row.
     */
    public function updateProject(string $slug, string $projectId, ?string $name = null, ?int $retentionDays = null): array
    {
        $body = [];
        if ($name !== null) {
            $body['name'] = $name;
        }
        if ($retentionDays !== null) {
            $body['retentionDays'] = $retentionDays;
        }
        $resp = $this->http->patch($this->projectPath($slug, $projectId), $body);
        /** @var array<string, mixed> $project */
        $project = is_array($resp['project'] ?? null) ? $resp['project'] : [];
        return $project;
    }

    /**
     * Delete a project. Owner-only server-side.
     *
     * @return array<string, mixed>
     */
    public function deleteProject(string $slug, string $projectId): array
    {
        return $this->http->delete($this->projectPath($slug, $projectId));
    }

    // --- /v1/user/orgs/{slug}/projects/{id}/keys -------------------------

    /**
     * List the project API keys (`m0_<routing>_<secret>`) on a project.
     * The plaintext secret is never returned here — only metadata.
     *
     * @return list<array<string, mixed>>
     */
    public function listProjectKeys(string $slug, string $projectId): array
    {
        $resp = $this->http->get($this->projectPath($slug, $projectId) . '/keys');
        /** @var list<array<string, mixed>> $keys */
        $keys = is_array($resp['keys'] ?? null) ? array_values($resp['keys']) : [];
        return $keys;
    }

    /**
     * Mint a new project API key. The response includes the plaintext
     * token under `token` — it's only returned this once, so callers
     * must persist it immediately.
     *
     * `scope` is one of `read` or `read_write` (default server-side).
     * `expiresAt` is RFC3339.
     *
     * Response shape: `{ key: {...metadata...}, token: 'm0_...' }`.
     *
     * @return array<string, mixed>
     */
    public function createProjectKey(
        string $slug,
        string $projectId,
        ?string $name = null,
        ?string $expiresAt = null,
        ?string $scope = null,
    ): array {
        $body = [];
        if ($name !== null) {
            $body['name'] = $name;
        }
        if ($expiresAt !== null) {
            $body['expiresAt'] = $expiresAt;
        }
        if ($scope !== null) {
            $body['scope'] = $scope;
        }
        return $this->http->post($this->projectPath($slug, $projectId) . '/keys', $body);
    }

    /**
     * Revoke a key. Members can only revoke keys they minted; admin+
     * can revoke any. Re-revoking is a no-op server-side.
     *
     * @return array<string, mixed>
     */
    public function revokeProjectKey(string $slug, string $projectId, string $keyId): array
    {
        return $this->http->delete(
            $this->projectPath($slug, $projectId) . '/keys/' . rawurlencode($keyId),
        );
    }

    private function projectsPath(string $slug): string
    {
        return '/v1/user/orgs/' . rawurlencode($slug) . '/projects';
    }

    private function projectPath(string $slug, string $projectId): string
    {
        return $this->projectsPath($slug) . '/' . rawurlencode($projectId);
    }
}
