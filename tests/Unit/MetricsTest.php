<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Exception\ConfigurationException;
use Mesh0\Metrics\Metrics;
use Mesh0\Tests\Support\InMemoryMetricSink;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    private InMemoryMetricSink $sink;
    private Metrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = new InMemoryMetricSink();
        $this->metrics = new Metrics($this->sink);
    }

    public function testIncrementWritesCounterLine(): void
    {
        $this->metrics->increment('checkout.charge');

        $this->assertSame(['checkout.charge:1|c'], $this->sink->packets);
    }

    public function testIncrementWithExplicitValue(): void
    {
        $this->metrics->increment('checkout.charge', 5);

        $this->assertSame(['checkout.charge:5|c'], $this->sink->packets);
    }

    public function testDecrementEmitsNegativeCounter(): void
    {
        $this->metrics->decrement('inflight.requests');

        $this->assertSame(['inflight.requests:-1|c'], $this->sink->packets);
    }

    public function testGaugeUsesGaugeType(): void
    {
        $this->metrics->gauge('queue.depth', 42);

        $this->assertSame(['queue.depth:42|g'], $this->sink->packets);
    }

    public function testTimingFormatsFloatWithoutTrailingZeros(): void
    {
        $this->metrics->timing('db.query_ms', 12.5);

        $this->assertSame(['db.query_ms:12.5|ms'], $this->sink->packets);
    }

    public function testHistogramAndDistributionUseDistinctTypeTags(): void
    {
        $this->metrics->histogram('h', 1);
        $this->metrics->distribution('d', 1);

        $this->assertSame(['h:1|h', 'd:1|d'], $this->sink->packets);
    }

    public function testTagsAreAppendedAfterHash(): void
    {
        $this->metrics->increment('checkout.charge', 1, ['tier' => 'pro', 'region' => 'us-east-1']);

        $this->assertSame(['checkout.charge:1|c|#tier:pro,region:us-east-1'], $this->sink->packets);
    }

    public function testDefaultTagsAreEmittedAndOverridden(): void
    {
        $metrics = new Metrics($this->sink, ['service' => 'checkout', 'tier' => 'free']);

        $metrics->increment('hits', 1, ['tier' => 'pro']);

        $this->assertSame(['hits:1|c|#service:checkout,tier:pro'], $this->sink->packets);
    }

    public function testSampleRateIsAppendedWhenLessThanOne(): void
    {
        // Force the sample to fire deterministically by always passing through.
        // We can't seed mt_rand portably, so use sampleRate = 1.0 - epsilon and
        // check that when it does fire, the packet carries the @rate token.
        // Instead, exercise the formatter directly via a guaranteed path:
        $metrics = new Metrics($this->sink);
        // sampleRate = 1.0 always emits; bump down and loop to flush the random.
        // To keep the test deterministic, just verify the format when emit fires:
        // we run many trials; with rate 0.999 we'll near-certainly see one packet.
        for ($i = 0; $i < 200 && $this->sink->packets === []; $i++) {
            $metrics->timing('latency_ms', 10, [], 0.5);
        }
        $this->assertNotEmpty($this->sink->packets, 'expected at least one sampled packet');
        foreach ($this->sink->packets as $packet) {
            $this->assertStringContainsString('|@0.5', $packet);
            $this->assertStringStartsWith('latency_ms:10|ms|@0.5', $packet);
        }
    }

    public function testSampleRateOutOfRangeThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->metrics->increment('x', 1, [], 1.5);
    }

    public function testEmptyMetricNameRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->metrics->increment('');
    }

    public function testIllegalCharsInNameRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->metrics->increment('bad|name');
    }

    public function testIllegalCharsInTagKeyRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->metrics->increment('ok', 1, ['bad,key' => 'v']);
    }

    public function testTimeBlockEmitsTimingAndReturnsValue(): void
    {
        $result = $this->metrics->time('block_ms', static fn (): int => 7);

        $this->assertSame(7, $result);
        $this->assertCount(1, $this->sink->packets);
        $this->assertMatchesRegularExpression('/^block_ms:[0-9]+(\.[0-9]+)?\|ms$/', $this->sink->packets[0]);
    }

    public function testTimeBlockEmitsTimingEvenWhenCallableThrows(): void
    {
        try {
            $this->metrics->time('block_ms', static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('exception not propagated');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertCount(1, $this->sink->packets);
    }

    public function testCloseDelegatesToSink(): void
    {
        $this->metrics->close();
        $this->assertTrue($this->sink->closed);
    }

    public function testNonFiniteValueRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->metrics->gauge('x', NAN);
    }
}
