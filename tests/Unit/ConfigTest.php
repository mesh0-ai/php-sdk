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

    public function testAcceptsAnOpenModeInstanceToken(): void
    {
        // An open-mode instance token has no prefix to check — it is a JWT
        // minted by the operator's control plane, and in open mode it is the
        // only credential a workspace has: it authenticates ingest AND the
        // whole management API this SDK wraps. Rejecting it here made every
        // management resource unreachable for an open-mode deployment.
        $jwt = 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9'
            . '.eyJpc3MiOiJ4YW5vIiwic3ViIjoiaW5zdC0xIn0'
            . '.c2lnbmF0dXJlLWJ5dGVz';

        $config = new Config(apiKey: $jwt);
        $this->assertSame($jwt, $config->apiKey);
    }

    public function testRejectsPaddedBase64InAnInstanceToken(): void
    {
        // mesh0 requires UNPADDED base64url on the wire, so a padded token is
        // malformed rather than merely unusual — it is rejected here, where
        // the message names the problem, instead of as an opaque 401.
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'eyJhbGciOiJFZERTQSJ9.eyJzdWIiOiJhIn0=.c2ln');
    }

    public function testRejectsAThingThatMerelyContainsDots(): void
    {
        // The JWT arm is a shape check, not "anything with dots" — a hostname
        // or a version string must still be caught at construction.
        $this->expectException(ConfigurationException::class);
        new Config(apiKey: 'api.mesh0.ai');
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

    public function testAgentProjectDefaultsToNull(): void
    {
        $cfg = new Config(apiKey: 'm0_abc_def');
        self::assertNull($cfg->agentProject);
    }

    public function testFromEnvReadsAgentProject(): void
    {
        putenv('MESH0_API_KEY=m0_abc_def');
        putenv('MESH0_AGENT_PROJECT=workspace-42');
        try {
            $cfg = Config::fromEnv();
            self::assertSame('workspace-42', $cfg->agentProject);
        } finally {
            putenv('MESH0_API_KEY');
            putenv('MESH0_AGENT_PROJECT');
        }
    }

    public function testIsInstanceTokenDistinguishesCredentialShapes(): void
    {
        $jwt = 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ4In0.c2ln';
        self::assertTrue(Config::isInstanceToken($jwt));
        self::assertFalse(Config::isInstanceToken('m0_routing_secret'));
        self::assertFalse(Config::isInstanceToken('m0u_secret'));
        // The case the shape check exists to catch: three dot-separated
        // alphanumeric runs that are not a token at all.
        self::assertFalse(Config::isInstanceToken('api.mesh0.ai'));
    }
}
