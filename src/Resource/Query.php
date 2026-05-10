<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Http\Transport;

/**
 * `/v1/query` — TQL query against the project's events.
 *
 * mesh0's TQL DSL compiles to safe parameterized ClickHouse SQL on the
 * server. The SDK is intentionally a thin pass-through: pass a TQL request
 * dictionary and you'll get a list of result rows back.
 */
final class Query
{
    public function __construct(private readonly Transport $http)
    {
    }

    /**
     * Run a TQL query.
     *
     * @param array<string, mixed> $request TQL request body — see https://mesh0.ai/docs/tql.
     * @return list<array<string, mixed>>   Result rows.
     */
    public function run(array $request): array
    {
        /** @var array<string, mixed> $resp */
        $resp = $this->http->post('/v1/query', $request);
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($resp['rows'] ?? null) ? $resp['rows'] : [];
        return $rows;
    }

    /**
     * Run a TQL query and return the full response (rows + any metadata).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function runRaw(array $request): array
    {
        return $this->http->post('/v1/query', $request);
    }
}
