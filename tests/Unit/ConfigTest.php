<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Config;
use Mesh0\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testValidConfig(): void
    {
        $c = new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertSame('https://api.mesh0.ai', $c->baseUrl);
        $this->assertSame(10.0, $c->timeout);
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: '');
    }

    public function testRejectsBadlyPrefixedKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'sk-1234');
    }

    public function testRejectsBadBaseUrl(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', baseUrl: 'not-a-url');
    }

    public function testRejectsNegativeRetries(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: -1);
    }

    public function testRejectsEmptyMetricsAgentHost(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', metricsAgentHost: '');
    }

    public function testRejectsMetricsAgentPortBelowOne(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', metricsAgentPort: 0);
    }

    public function testRejectsMetricsAgentPortAbove65535(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', metricsAgentPort: 65536);
    }

    public function testFromEnvReadsMetricsAgentVars(): void
    {
        \putenv('MESH0_API_KEY=m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa');
        \putenv('MESH0_AGENT_HOST=10.0.0.1');
        \putenv('MESH0_AGENT_PORT=9000');
        try {
            $c = Config::fromEnv();
            $this->assertSame('10.0.0.1', $c->metricsAgentHost);
            $this->assertSame(9000, $c->metricsAgentPort);
        } finally {
            \putenv('MESH0_API_KEY');
            \putenv('MESH0_AGENT_HOST');
            \putenv('MESH0_AGENT_PORT');
        }
    }

    public function testFromEnvRejectsMalformedAgentPort(): void
    {
        \putenv('MESH0_API_KEY=m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa');
        \putenv('MESH0_AGENT_PORT=not-a-port');
        try {
            $this->expectException(ConfigurationException::class);
            Config::fromEnv();
        } finally {
            \putenv('MESH0_API_KEY');
            \putenv('MESH0_AGENT_PORT');
        }
    }

    public function testFromEnvFallsBackToDefaultsWhenAgentVarsUnset(): void
    {
        \putenv('MESH0_API_KEY=m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa');
        \putenv('MESH0_AGENT_HOST');
        \putenv('MESH0_AGENT_PORT');
        try {
            $c = Config::fromEnv();
            $this->assertSame(Config::DEFAULT_METRICS_AGENT_HOST, $c->metricsAgentHost);
            $this->assertSame(Config::DEFAULT_METRICS_AGENT_PORT, $c->metricsAgentPort);
            $this->assertNull($c->metricsAgentSocketPath);
        } finally {
            \putenv('MESH0_API_KEY');
        }
    }

    public function testRejectsEmptyMetricsAgentSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', metricsAgentSocketPath: '');
    }

    public function testRejectsRelativeMetricsAgentSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', metricsAgentSocketPath: 'relative/path.sock');
    }

    public function testAcceptsAbsoluteMetricsAgentSocketPath(): void
    {
        $c = new Config(
            apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
            metricsAgentSocketPath: '/run/mesh0/agent.sock',
        );
        $this->assertSame('/run/mesh0/agent.sock', $c->metricsAgentSocketPath);
    }

    public function testRejectsTooLongMetricsAgentSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/sun_path/');
        new Config(
            apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
            metricsAgentSocketPath: '/' . str_repeat('a', 104),
        );
    }

    public function testFromEnvReadsAgentSocket(): void
    {
        \putenv('MESH0_API_KEY=m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa');
        \putenv('MESH0_AGENT_SOCKET=/run/mesh0/agent.sock');
        try {
            $c = Config::fromEnv();
            $this->assertSame('/run/mesh0/agent.sock', $c->metricsAgentSocketPath);
        } finally {
            \putenv('MESH0_API_KEY');
            \putenv('MESH0_AGENT_SOCKET');
        }
    }
}
