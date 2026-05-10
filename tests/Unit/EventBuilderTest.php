<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Mesh0\Event\Event;
use PHPUnit\Framework\TestCase;

final class EventBuilderTest extends TestCase
{
    public function testBuildsCompleteWirePayload(): void
    {
        $event = Event::at(new DateTimeImmutable('2026-05-08T12:00:00.123Z'))
            ->withEventId('evt_1')
            ->withTraceId('trace-1')
            ->withSpan('span-1', 'span-0')
            ->withAttributes([
                'order_id'    => 'ord_1',
                'app.id'      => 'checkout',
                'status'      => 'success',
                'duration_ms' => 420.5,
            ])
            ->withAttribute('amount_usd', 19.99)
            ->withData(['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->build();

        $arr = $event->toArray();

        $this->assertSame('2026-05-08T12:00:00.123Z', $arr['timestamp']);
        $this->assertSame('evt_1', $arr['event_id']);
        $this->assertSame('trace-1', $arr['trace_id']);
        $this->assertSame('span-1', $arr['span_id']);
        $this->assertSame('span-0', $arr['parent_span_id']);
        $this->assertSame(
            [
                'order_id'    => 'ord_1',
                'app.id'      => 'checkout',
                'status'      => 'success',
                'duration_ms' => 420.5,
                'amount_usd'  => 19.99,
            ],
            $arr['attributes'],
        );
        $this->assertSame(['messages' => [['role' => 'user', 'content' => 'hi']]], $arr['data']);
    }

    public function testRejectsLegacyTopLevelFields(): void
    {
        // Wire decoder runs DisallowUnknownFields. Confirm the SDK no longer
        // emits the legacy top-level fields that earlier versions promoted —
        // they must now ride inside `attributes` or `data`. Includes the
        // 4.x-removed `status` and `duration_ms`.
        $arr = Event::now()->withAttribute('app.id', 'checkout')->build()->toArray();

        $legacy = [
            'app_id', 'environment', 'operation', 'model', 'usage', 'user_id',
            'session_id', 'tools', 'messages', 'finish_reason', 'error_type',
            'error_message', 'status', 'duration_ms',
        ];
        foreach ($legacy as $k) {
            $this->assertArrayNotHasKey($k, $arr, "legacy field '{$k}' must not appear top-level");
        }
    }

    public function testMinimalEventOnlySerializesTimestamp(): void
    {
        $arr = Event::at(new DateTimeImmutable('2026-01-01T00:00:00Z'))->build()->toArray();
        $this->assertSame(['timestamp'], array_keys($arr));
    }

    public function testBuilderIsImmutable(): void
    {
        $base = Event::now();
        $a = $base->withAttribute('span.name', 'a');
        $b = $base->withAttribute('span.name', 'b');
        $aAttrs = $a->build()->toArray()['attributes'];
        $bAttrs = $b->build()->toArray()['attributes'];
        $this->assertIsArray($aAttrs);
        $this->assertIsArray($bAttrs);
        $this->assertSame('a', $aAttrs['span.name']);
        $this->assertSame('b', $bAttrs['span.name']);
    }

    public function testWithAttributeMergesInsteadOfReplacing(): void
    {
        $arr = Event::now()
            ->withAttribute('a', 1)
            ->withAttribute('b', 2)
            ->withAttribute('a', 3) // override of `a`, but `b` survives
            ->build()
            ->toArray();
        $this->assertSame(['a' => 3, 'b' => 2], $arr['attributes']);
    }

    public function testTimestampIsRezonedToUtc(): void
    {
        // 2026-05-08 12:00:00 in America/New_York is 16:00:00Z (EDT, UTC-4).
        $local = new DateTimeImmutable('2026-05-08T12:00:00', new DateTimeZone('America/New_York'));
        $arr = Event::at($local)->build()->toArray();
        $this->assertSame('2026-05-08T16:00:00.000Z', $arr['timestamp']);
    }

    public function testEmptyIdentitiesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Event::now()->withTraceId('');
    }

    public function testEmptyEventIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Event::now()->withEventId('');
    }

    public function testEmptySpanIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Event::now()->withSpan('');
    }

    public function testWireKeysAreOnlyTheNarrowSet(): void
    {
        // Positive allowlist: every key the SDK is allowed to emit.
        $arr = Event::at(new DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->withEventId('evt_1')
            ->withTraceId('tr_1')
            ->withSpan('sp_1', 'sp_0')
            ->withAttributes(['a' => 1])
            ->withData(['b' => 2])
            ->build()
            ->toArray();

        $expected = ['timestamp', 'event_id', 'trace_id', 'span_id', 'parent_span_id', 'attributes', 'data'];
        sort($expected);
        $actual = array_keys($arr);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
