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
        // Seed mt_rand so the trial below always passes the gate.
        \mt_srand(1);
        $metrics = new Metrics($this->sink);

        // With sampleRate close to 1.0, mt_rand()/mt_getrandmax() < rate is
        // overwhelmingly likely; loop briefly to be robust against the seed.
        for ($i = 0; $i < 50 && $this->sink->packets === []; $i++) {
            $metrics->timing('latency_ms', 10, [], 0.999);
        }
        $this->assertNotEmpty($this->sink->packets);
        $this->assertStringStartsWith('latency_ms:10|ms|@0.999', $this->sink->packets[0]);
    }

    public function testSampleRateOfOneDoesNotAppendRateToken(): void
    {
        $this->metrics->increment('hit', 1, [], 1.0);

        $this->assertSame(['hit:1|c'], $this->sink->packets);
    }

    public function testSampleRateAboveOneClampsToAlwaysEmit(): void
    {
        // Out-of-range sampleRate must not throw on the hot path; >=1 always
        // emits and (per format()) does not append a rate token.
        $this->metrics->increment('hit', 1, [], 1.5);

        $this->assertSame(['hit:1|c'], $this->sink->packets);
    }

    public function testSampleRateOfZeroDropsEmission(): void
    {
        $this->metrics->increment('hit', 1, [], 0.0);
        $this->metrics->increment('hit', 1, [], -1.0);

        $this->assertSame([], $this->sink->packets);
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

    public function testIllegalCharsInTagValueRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->metrics->increment('ok', 1, ['k' => 'bad,val']);
    }

    public function testIllegalCharsInDefaultTagsRejectedAtConstruction(): void
    {
        $this->expectException(ConfigurationException::class);
        new Metrics($this->sink, ['bad key' => 'v']);
    }

    public function testCountEmitsCounterWithValue(): void
    {
        $this->metrics->count('orders.total', 42);

        $this->assertSame(['orders.total:42|c'], $this->sink->packets);
    }

    public function testEmptyTagValueRendersAsBareKey(): void
    {
        $this->metrics->increment('hit', 1, ['flag' => '']);

        $this->assertSame(['hit:1|c|#flag'], $this->sink->packets);
    }

    public function testNumericTagValuesAreCoercedToString(): void
    {
        $this->metrics->increment('hit', 1, ['n' => 7, 'f' => 1.5]);

        $this->assertSame(['hit:1|c|#n:7,f:1.5'], $this->sink->packets);
    }

    public function testNegativeFloatValueRetainsSign(): void
    {
        $this->metrics->gauge('temp', -2.5);

        $this->assertSame(['temp:-2.5|g'], $this->sink->packets);
    }

    public function testInfiniteValueRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->metrics->gauge('x', INF);
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
