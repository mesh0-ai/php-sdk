<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Client;
use Mesh0\Config;
use Mesh0\Metrics\Metrics;
use Mesh0\Tests\Support\InMemoryMetricSink;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class ClientMetricsTest extends TestCase
{
    private function client(): Client
    {
        return new Client(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa'),
            new MockHttpClient(),
        );
    }

    public function testMetricsReturnsSameInstanceWhenCalledWithoutArgs(): void
    {
        $client = $this->client();

        $a = $client->metrics();
        $b = $client->metrics();

        $this->assertSame($a, $b, 'argument-free metrics() should memoize');
    }

    public function testMetricsReturnsFreshInstanceWhenCustomSinkProvided(): void
    {
        $client = $this->client();
        $sink = new InMemoryMetricSink();

        $cached = $client->metrics();
        $custom = $client->metrics(sink: $sink);

        $this->assertNotSame($cached, $custom);
        $custom->increment('hit');
        $this->assertSame(['hit:1|c'], $sink->packets);
    }

    public function testMetricsReturnsFreshInstanceWhenHostOrPortOverridden(): void
    {
        $client = $this->client();

        $cached = $client->metrics();
        $other = $client->metrics(host: '10.0.0.2');
        $third = $client->metrics(port: 9999);

        $this->assertNotSame($cached, $other);
        $this->assertNotSame($cached, $third);
        $this->assertNotSame($other, $third);
    }

    public function testMetricsReturnsFreshInstanceWhenDefaultTagsProvided(): void
    {
        $client = $this->client();
        $sink = new InMemoryMetricSink();

        $cached = $client->metrics();
        $tagged = $client->metrics(defaultTags: ['service' => 'api'], sink: $sink);

        $this->assertNotSame($cached, $tagged);
        $tagged->increment('hit');
        $this->assertSame(['hit:1|c|#service:api'], $sink->packets);
    }

    public function testMetricsIsAMetricsInstance(): void
    {
        $this->assertInstanceOf(Metrics::class, $this->client()->metrics());
    }
}
