<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Http\Transport;

/**
 * `/v1/traces/:traceId` — fetch every span in a trace.
 *
 * For OTLP ingestion, point any OpenTelemetry exporter (or the AI SDK
 * tracer) at `<baseUrl>/v1/traces` with the same Bearer key — the SDK does
 * not need to wrap that path itself.
 */
final class Traces
{
    public function __construct(private readonly Transport $http)
    {
    }

    /**
     * Returns the spans, ordered by timestamp ascending.
     *
     * @return list<array<string, mixed>>
     */
    public function get(string $traceId): array
    {
        /** @var array<string, mixed> $resp */
        $resp = $this->http->get('/v1/traces/' . rawurlencode($traceId));
        /** @var list<array<string, mixed>> $spans */
        $spans = is_array($resp['spans'] ?? null) ? $resp['spans'] : [];
        return $spans;
    }
}
