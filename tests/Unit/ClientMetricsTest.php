<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Client;
use Mesh0\Config;
use Mesh0\Exception\ConfigurationException;
use Mesh0\Metrics\Metrics;
use Mesh0\Tests\Support\InMemoryMetricSink;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class ClientMetricsTest extends TestCase
{
    private function client(?string $agentSocketPath = '/run/mesh0/agent.sock'): Client
    {
        return new Client(
            new Config(
                apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
                agentSocketPath: $agentSocketPath,
            ),
            new MockHttpClient(),
        );
    }

    public function testMetricsReturnsSameInstanceWhenCalledWithoutArgs(): void
    {
        // Use a custom sink so the lazy-open never tries to actually
        // touch /run/mesh0/agent.sock during the test.
        $client = $this->client();
        $sink = new InMemoryMetricSink();

        $a = $client->metrics(sink: $sink);
        $cached = $client->metrics();
        $cached2 = $client->metrics();

        $this->assertSame($cached, $cached2, 'argument-free metrics() should memoize');
        $this->assertNotSame($a, $cached, 'sink override returns a fresh Metrics');
    }

    public function testMetricsReturnsFreshInstanceWhenCustomSinkProvided(): void
    {
        $client = $this->client();
        $sink = new InMemoryMetricSink();

        $custom = $client->metrics(sink: $sink);
        $custom->increment('hit');

        $this->assertSame(['hit:1|c'], $sink->packets);
    }

    public function testMetricsReturnsFreshInstanceWhenSocketPathOverridden(): void
    {
        $client = $this->client();
        $sink = new InMemoryMetricSink();

        $base = $client->metrics(sink: $sink);
        $other = $client->metrics(socketPath: '/tmp/other.sock');

        $this->assertNotSame($base, $other);
    }

    public function testMetricsReturnsFreshInstanceWhenDefaultTagsProvided(): void
    {
        $client = $this->client();
        $sink = new InMemoryMetricSink();

        $tagged = $client->metrics(defaultTags: ['service' => 'api'], sink: $sink);
        $tagged->increment('hit');

        $this->assertSame(['hit:1|c|#service:api'], $sink->packets);
    }

    public function testMetricsIsAMetricsInstance(): void
    {
        $sink = new InMemoryMetricSink();
        $this->assertInstanceOf(Metrics::class, $this->client()->metrics(sink: $sink));
    }

    public function testMetricsThrowsWhenAgentSocketPathMissing(): void
    {
        $client = $this->client(agentSocketPath: null);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/MESH0_AGENT_SOCKET/');
        $client->metrics();
    }

    public function testMetricsAllowsCustomSinkWithoutAgentSocketPath(): void
    {
        $client = $this->client(agentSocketPath: null);
        $sink = new InMemoryMetricSink();

        // Custom sink path bypasses the agent-socket-required check —
        // useful for tests and for callers using non-agent transports.
        $metrics = $client->metrics(sink: $sink);
        $metrics->increment('ok');

        $this->assertSame(['ok:1|c'], $sink->packets);
    }
}
