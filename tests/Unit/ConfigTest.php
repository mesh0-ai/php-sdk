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
}
