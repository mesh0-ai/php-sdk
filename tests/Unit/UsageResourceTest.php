<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use Mesh0\Config;
use Mesh0\Http\Transport;
use Mesh0\Resource\Usage;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class UsageResourceTest extends TestCase
{
    private MockHttpClient $mock;
    private Usage $usage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->usage = new Usage(new Transport(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        ));
    }

    public function testSummaryGetsRoot(): void
    {
        $this->mock->queueJson(200, [
            'period_start' => '2026-05-01',
            'period_end' => '2026-06-01',
            'event_count' => 1234,
            'bytes_in' => 5678,
            'rejected_count' => 0,
            'avg_events_per_day' => 41,
            'avg_bytes_per_day' => 189,
            'top_day' => ['day' => '2026-05-12', 'event_count' => 999],
        ]);

        $out = $this->usage->summary();

        $this->assertSame('GET', $this->mock->lastRequest()->getMethod());
        $this->assertSame('/v1/usage/summary', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame(1234, $out['event_count']);
        $this->assertIsArray($out['top_day']);
        $this->assertSame('2026-05-12', $out['top_day']['day']);
    }

    public function testSeriesDefaultsOmitFromTo(): void
    {
        $this->mock->queueJson(200, [
            'from' => '2026-04-24',
            'to' => '2026-05-24',
            'granularity' => 'day',
            'points' => [],
        ]);

        $this->usage->series();

        $uri = $this->mock->lastRequest()->getUri();
        $this->assertSame('/v1/usage', $uri->getPath());
        $this->assertSame('granularity=day', $uri->getQuery());
    }

    public function testSeriesSendsFromToAndGranularity(): void
    {
        $this->mock->queueJson(200, [
            'from' => '2026-01-01', 'to' => '2026-04-01',
            'granularity' => 'month', 'points' => [],
        ]);

        $this->usage->series('2026-01-01', '2026-04-01', 'month');

        parse_str($this->mock->lastRequest()->getUri()->getQuery(), $q);
        $this->assertSame([
            'granularity' => 'month',
            'from' => '2026-01-01',
            'to' => '2026-04-01',
        ], $q);
    }

    public function testSeriesRejectsHalfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->usage->series('2026-01-01', null);
    }
}
