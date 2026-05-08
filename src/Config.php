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
    public const DEFAULT_USER_AGENT = 'mesh0-php-sdk/0.1.0';

    /** @var array<string, string> */
    public readonly array $defaultHeaders;

    /**
     * @param non-empty-string                $apiKey         API key in the form `m0_<routing>_<secret>`.
     * @param non-empty-string                $baseUrl        API base URL, no trailing slash.
     * @param float                           $timeout        Total request timeout, seconds.
     * @param float                           $connectTimeout Connect timeout, seconds.
     * @param int<0, max>                     $maxRetries     Retries for idempotent failures (network / 5xx / 429).
     * @param non-empty-string                $userAgent      User-Agent header value.
     * @param array<string, string>           $defaultHeaders Extra headers added to every request.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public readonly string $userAgent = self::DEFAULT_USER_AGENT,
        array $defaultHeaders = [],
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

        return new self(
            apiKey: $key,
            baseUrl: ($base === false || $base === '') ? self::DEFAULT_BASE_URL : $base,
        );
    }
}
