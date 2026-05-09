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
        $this->assertNull($c->agentSocketPath);
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

    public function testRejectsEmptyAgentSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', agentSocketPath: '');
    }

    public function testRejectsRelativeAgentSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', agentSocketPath: 'relative/path.sock');
    }

    public function testRejectsTooLongAgentSocketPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/sun_path/');
        new Config(
            apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
            agentSocketPath: '/' . str_repeat('a', 104),
        );
    }

    public function testAcceptsAbsoluteAgentSocketPath(): void
    {
        $c = new Config(
            apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
            agentSocketPath: '/run/mesh0/agent.sock',
        );
        $this->assertSame('/run/mesh0/agent.sock', $c->agentSocketPath);
    }

    public function testFromEnvReadsAgentSocket(): void
    {
        \putenv('MESH0_API_KEY=m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa');
        \putenv('MESH0_AGENT_SOCKET=/run/mesh0/agent.sock');
        try {
            $c = Config::fromEnv();
            $this->assertSame('/run/mesh0/agent.sock', $c->agentSocketPath);
        } finally {
            \putenv('MESH0_API_KEY');
            \putenv('MESH0_AGENT_SOCKET');
        }
    }

    public function testFromEnvLeavesAgentSocketNullWhenUnset(): void
    {
        \putenv('MESH0_API_KEY=m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa');
        \putenv('MESH0_AGENT_SOCKET');
        try {
            $c = Config::fromEnv();
            $this->assertNull($c->agentSocketPath);
        } finally {
            \putenv('MESH0_API_KEY');
        }
    }
}
