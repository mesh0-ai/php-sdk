<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use InvalidArgumentException;
use Mesh0\Http\Transport;

/**
 * `/v1/usage` and `/v1/usage/summary` — project-scoped usage read
 * endpoints. Auth is the project API key with the `usage:read` scope.
 *
 * The public surface deliberately omits cap percentages and org-wide
 * totals so a project-scoped key can't leak the org's billing
 * posture — those live on the org dashboard endpoints, which the SDK
 * does not (and cannot) call.
 *
 * Wire shapes are snake_case (`event_count`, `bytes_in`, …) — payloads
 * are passed through unmodified.
 */
final class Usage
{
    public function __construct(private readonly Transport $http)
    {
    }

    /**
     * Summary for the current cap period (defined by the project's
     * configured anchor day). Returns: `period_start`, `period_end`,
     * `event_count`, `bytes_in`, `rejected_count`,
     * `avg_events_per_day`, `avg_bytes_per_day`, and `top_day`
     * (`{day, event_count}` or null).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->http->get('/v1/usage/summary');
    }

    /**
     * Time-bucketed usage series. Defaults to the last 30 days at daily
     * granularity when both `$from` and `$to` are omitted.
     *
     * `$from` / `$to` are calendar dates as `YYYY-MM-DD` strings (UTC).
     * `$granularity` is `day` (default, max 366-day range) or `month`
     * (max 24-month range). Larger ranges are server-rejected with 400.
     *
     * Returns: `{ from, to, granularity, points: [{ bucket,
     * event_count, bytes_in, rejected_count }, …] }`.
     *
     * @throws InvalidArgumentException when only one of $from / $to is provided.
     * @return array<string, mixed>
     */
    public function series(
        ?string $from = null,
        ?string $to = null,
        string $granularity = 'day',
    ): array {
        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException('series() requires both $from and $to, or neither');
        }
        $query = ['granularity' => $granularity];
        if ($from !== null) {
            $query['from'] = $from;
            $query['to'] = $to;
        }
        return $this->http->get('/v1/usage', $query);
    }
}
