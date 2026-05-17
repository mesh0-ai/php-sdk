<?php

declare(strict_types=1);

namespace Mesh0\Resource;

use Mesh0\Http\Transport;

/**
 * `/v1/alerts` and `/v1/alert-channels` — project-scoped CRUD for alert
 * definitions and notification channels, plus history and test-fire
 * helpers. Auth is the project API key (`m0_<routing>_<secret>`); the
 * key's `alerts:read` / `alerts:write` scopes are enforced server-side.
 *
 * Refs (`$ref` / `$alertId` / `$channelId`) may be either a UUID or the
 * resource's slug — the backend resolves both via `resolveAlertRef` /
 * `resolveChannelRef`.
 *
 * The `AlertInput` / `ChannelInput` payload shapes are defined by the
 * backend in `internal/alerts/service.go` and evolve with the product;
 * the SDK passes them through as opaque assoc arrays rather than
 * promoting them into PHP DTOs.
 */
final class Alerts
{
    public function __construct(private readonly Transport $http)
    {
    }

    // --- alerts -----------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listAlerts(): array
    {
        $resp = $this->http->get('/v1/alerts');
        /** @var list<array<string, mixed>> $alerts */
        $alerts = is_array($resp['alerts'] ?? null) ? array_values($resp['alerts']) : [];
        return $alerts;
    }

    /**
     * Get an alert by id-or-slug. Response includes the alert and (when
     * available) its evaluation state under `state` — both kept inside
     * the unwrapped `alert` object.
     *
     * @return array<string, mixed>
     */
    public function getAlert(string $ref): array
    {
        $resp = $this->http->get('/v1/alerts/' . rawurlencode($ref));
        /** @var array<string, mixed> $alert */
        $alert = is_array($resp['alert'] ?? null) ? $resp['alert'] : [];
        return $alert;
    }

    /**
     * Create an alert. Pass `$idempotencyKey` to make retries safe —
     * replays return the cached response verbatim, mismatched bodies
     * 409 with `idempotency_key_conflict`.
     *
     * @param array<string, mixed> $input AlertInput payload (see backend `alerts.AlertInput`).
     * @return array<string, mixed> The created alert.
     */
    public function createAlert(array $input, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];
        $resp = $this->http->post('/v1/alerts', $input, $headers);
        /** @var array<string, mixed> $alert */
        $alert = is_array($resp['alert'] ?? null) ? $resp['alert'] : [];
        return $alert;
    }

    /**
     * Update an alert. PATCH semantics: omitted fields keep their
     * existing values (see `AlertInput` in the backend).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> The updated alert.
     */
    public function updateAlert(string $ref, array $input): array
    {
        $resp = $this->http->patch('/v1/alerts/' . rawurlencode($ref), $input);
        /** @var array<string, mixed> $alert */
        $alert = is_array($resp['alert'] ?? null) ? $resp['alert'] : [];
        return $alert;
    }

    /** @return array<string, mixed> */
    public function deleteAlert(string $ref): array
    {
        return $this->http->delete('/v1/alerts/' . rawurlencode($ref));
    }

    /**
     * Fire a test notification for an alert through its configured
     * channels. Returns the raw response (HTTP 202 on success).
     *
     * @return array<string, mixed>
     */
    public function testFireAlert(string $ref): array
    {
        return $this->http->post('/v1/alerts/' . rawurlencode($ref) . '/test', []);
    }

    /**
     * Evaluation history for an alert. `limit` defaults to 50 server-side;
     * pass an integer to override.
     *
     * @return list<array<string, mixed>>
     */
    public function listAlertHistory(string $ref, ?int $limit = null): array
    {
        $path = '/v1/alerts/' . rawurlencode($ref) . '/history';
        if ($limit !== null) {
            $path .= '?limit=' . $limit;
        }
        $resp = $this->http->get($path);
        /** @var list<array<string, mixed>> $history */
        $history = is_array($resp['history'] ?? null) ? array_values($resp['history']) : [];
        return $history;
    }

    // --- channels ---------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listChannels(): array
    {
        $resp = $this->http->get('/v1/alert-channels');
        /** @var list<array<string, mixed>> $channels */
        $channels = is_array($resp['channels'] ?? null) ? array_values($resp['channels']) : [];
        return $channels;
    }

    /** @return array<string, mixed> */
    public function getChannel(string $ref): array
    {
        $resp = $this->http->get('/v1/alert-channels/' . rawurlencode($ref));
        /** @var array<string, mixed> $channel */
        $channel = is_array($resp['channel'] ?? null) ? $resp['channel'] : [];
        return $channel;
    }

    /**
     * Create a channel. Same `Idempotency-Key` contract as `createAlert()`.
     *
     * @param array<string, mixed> $input ChannelInput payload.
     * @return array<string, mixed>
     */
    public function createChannel(array $input, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];
        $resp = $this->http->post('/v1/alert-channels', $input, $headers);
        /** @var array<string, mixed> $channel */
        $channel = is_array($resp['channel'] ?? null) ? $resp['channel'] : [];
        return $channel;
    }

    /**
     * Update a channel. PATCH semantics — omitted fields are preserved.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateChannel(string $ref, array $input): array
    {
        $resp = $this->http->patch('/v1/alert-channels/' . rawurlencode($ref), $input);
        /** @var array<string, mixed> $channel */
        $channel = is_array($resp['channel'] ?? null) ? $resp['channel'] : [];
        return $channel;
    }

    /** @return array<string, mixed> */
    public function deleteChannel(string $ref): array
    {
        return $this->http->delete('/v1/alert-channels/' . rawurlencode($ref));
    }

    /**
     * Send a test notification through a channel without going through
     * an alert. Useful for verifying webhook URLs / Slack tokens at
     * setup time.
     *
     * @return array<string, mixed>
     */
    public function testFireChannel(string $ref): array
    {
        return $this->http->post('/v1/alert-channels/' . rawurlencode($ref) . '/test', []);
    }
}
