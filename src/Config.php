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
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.mesh0.ai';
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_CONNECT_TIMEOUT = 5.0;
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_USER_AGENT = 'mesh0-php-sdk/0.4.0';
    public const DEFAULT_METRICS_AGENT_HOST = '127.0.0.1';
    public const DEFAULT_METRICS_AGENT_PORT = 8125;

    /** @var array<string, string> */
    public readonly array $defaultHeaders;

    /**
     * @param string                $apiKey         API key in the form `m0_<routing>_<secret>`.
     * @param string                $baseUrl        API base URL, no trailing slash.
     * @param float                 $timeout        Total request timeout, seconds.
     * @param float                 $connectTimeout Connect timeout, seconds.
     * @param int                   $maxRetries     Retries for idempotent failures (network / 5xx / 429).
     * @param string                $userAgent      User-Agent header value.
     * @param array<string, string> $defaultHeaders Extra headers added to every request.
     * @param string                $metricsAgentHost Host of the local metrics-agent (UDP target for metrics + events).
     * @param int                   $metricsAgentPort Port of the local metrics-agent (single port for both metrics and events).
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public readonly string $userAgent = self::DEFAULT_USER_AGENT,
        array $defaultHeaders = [],
        public readonly string $metricsAgentHost = self::DEFAULT_METRICS_AGENT_HOST,
        public readonly int $metricsAgentPort = self::DEFAULT_METRICS_AGENT_PORT,
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
        if ($metricsAgentHost === '') {
            throw new ConfigurationException('metricsAgentHost must not be empty');
        }
        if ($metricsAgentPort < 1 || $metricsAgentPort > 65535) {
            throw new ConfigurationException('metricsAgentPort must be in 1..65535');
        }

        $this->defaultHeaders = $defaultHeaders;
    }

    /**
     * Build a config from environment variables.
     *
     * Reads `MESH0_API_KEY` (required) and `MESH0_BASE_URL` (optional).
     */
    public static function fromEnv(): self
    {
        $key = getenv('MESH0_API_KEY');
        if ($key === false || $key === '') {
            throw new ConfigurationException('MESH0_API_KEY is not set');
        }
        $base = getenv('MESH0_BASE_URL');
        $agentHost = getenv('MESH0_AGENT_HOST');
        $agentPort = getenv('MESH0_AGENT_PORT');

        $port = self::DEFAULT_METRICS_AGENT_PORT;
        if ($agentPort !== false && $agentPort !== '') {
            if (!ctype_digit($agentPort)) {
                throw new ConfigurationException('MESH0_AGENT_PORT must be a positive integer');
            }
            $port = (int) $agentPort;
        }

        return new self(
            apiKey: $key,
            baseUrl: ($base === false || $base === '') ? self::DEFAULT_BASE_URL : $base,
            metricsAgentHost: ($agentHost === false || $agentHost === '')
                ? self::DEFAULT_METRICS_AGENT_HOST
                : $agentHost,
            metricsAgentPort: $port,
        );
    }
}
