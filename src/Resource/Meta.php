<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Http\Transport;

/**
 * `/v1/me`, `/v1/org`, `/v1/project` — identity and resource lookups for
 * the API key in use. Useful for surfacing "you're connected as <project>
 * in <org>" in CLIs and dashboards.
 */
final class Meta
{
    public function __construct(private readonly Transport $http)
    {
    }

    /** @return array<string, mixed>|null Null when the key has no associated user. */
    public function me(): ?array
    {
        /** @var array<string, mixed> $resp */
        $resp = $this->http->get('/v1/me');
        $user = $resp['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed> */
    public function organization(): array
    {
        /** @var array<string, mixed> $resp */
        $resp = $this->http->get('/v1/org');
        /** @var array<string, mixed> $org */
        $org = is_array($resp['organization'] ?? null) ? $resp['organization'] : [];
        return $org;
    }

    /** @return array<string, mixed> */
    public function project(): array
    {
        /** @var array<string, mixed> $resp */
        $resp = $this->http->get('/v1/project');
        /** @var array<string, mixed> $project */
        $project = is_array($resp['project'] ?? null) ? $resp['project'] : [];
        return $project;
    }
}
