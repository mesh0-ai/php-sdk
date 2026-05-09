<?php

declare(strict_types=1);

namespace Mesh0;

use Mesh0\Exception\ConfigurationException;

/**
 * Immutable client configuration.
 *
 * The `apiKey` is the only required field. Everything else has a sensible
 * default that matches mesh0's hosted endpoints. For self-hosted deployments
 * point `baseUrl` at your own API host.
 *
 * Metrics and event datagrams target a co-located mesh0 metrics-agent
 * over a Unix domain datagram socket. Set `agentSocketPath` (or
 * `MESH0_AGENT_SOCKET`) when you want to use the local datagram sinks;
 * leave it `null` if you only use HTTPS resources.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.mesh0.ai';
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_CONNECT_TIMEOUT = 5.0;
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_USER_AGENT = 'mesh0-php-sdk/1.0.0';

    /** @var array<string, string> */
    public readonly array $defaultHeaders;

    /**
     * @param string                $apiKey          API key in the form `m0_<routing>_<secret>`.
     * @param string                $baseUrl         API base URL, no trailing slash.
     * @param float                 $timeout         Total request timeout, seconds.
     * @param float                 $connectTimeout  Connect timeout, seconds.
     * @param int                   $maxRetries      Retries for idempotent failures (network / 5xx / 429).
     * @param string                $userAgent       User-Agent header value.
     * @param array<string, string> $defaultHeaders  Extra headers added to every request.
     * @param string|null           $agentSocketPath Absolute Unix-domain socket path of the local
     *                                               metrics-agent. Required when using `Client::metrics()`
     *                                               or `Events::agent()`; leave `null` otherwise.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public readonly string $userAgent = self::DEFAULT_USER_AGENT,
        array $defaultHeaders = [],
        public readonly ?string $agentSocketPath = null,
    ) {
        if ($apiKey === '') {
            throw new ConfigurationException('apiKey must not be empty');
        }
        if (!str_starts_with($apiKey, 'm0_')) {
            throw new ConfigurationException('apiKey must start with "m0_"');
        }
        if ($baseUrl === '' || !preg_match('#^https?://#', $baseUrl)) {
            throw new ConfigurationException('baseUrl must be an http(s) URL');
        }
        if ($timeout <= 0.0) {
            throw new ConfigurationException('timeout must be > 0');
        }
        if ($connectTimeout <= 0.0) {
            throw new ConfigurationException('connectTimeout must be > 0');
        }
        if ($maxRetries < 0) {
            throw new ConfigurationException('maxRetries must be >= 0');
        }
        if ($agentSocketPath !== null) {
            if ($agentSocketPath === '') {
                throw new ConfigurationException('agentSocketPath must not be empty when set');
            }
            if ($agentSocketPath[0] !== '/') {
                // UDS paths are interpreted relative to the agent's cwd if
                // not absolute, which is rarely what callers want and
                // fragile across deployments.
                throw new ConfigurationException('agentSocketPath must be an absolute filesystem path');
            }
            // sun_path is 104 bytes on macOS/BSD and 108 on Linux. Reject
            // at the smaller bound so the same config works across
            // platforms.
            if (\strlen($agentSocketPath) > 104) {
                throw new ConfigurationException('agentSocketPath exceeds 104 bytes (sun_path limit)');
            }
        }

        $this->defaultHeaders = $defaultHeaders;
    }

    /**
     * Build a config from environment variables.
     *
     * Reads `MESH0_API_KEY` (required), `MESH0_BASE_URL` (optional), and
     * `MESH0_AGENT_SOCKET` (optional) — the datagram sinks need the last
     * one set; leave it unset if you only use HTTPS resources.
     */
    public static function fromEnv(): self
    {
        $key = getenv('MESH0_API_KEY');
        if ($key === false || $key === '') {
            throw new ConfigurationException('MESH0_API_KEY is not set');
        }
        $base = getenv('MESH0_BASE_URL');
        $agentSocket = getenv('MESH0_AGENT_SOCKET');

        return new self(
            apiKey: $key,
            baseUrl: ($base === false || $base === '') ? self::DEFAULT_BASE_URL : $base,
            agentSocketPath: ($agentSocket === false || $agentSocket === '') ? null : $agentSocket,
        );
    }
}
