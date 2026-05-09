<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use DateTimeImmutable;
use Mesh0\Event\Event;
use Mesh0\Event\Status;
use PHPUnit\Framework\TestCase;

final class EventBuilderTest extends TestCase
{
    public function testBuildsCompleteWirePayload(): void
    {
        $event = Event::at(new DateTimeImmutable('2026-05-08T12:00:00.123Z'))
            ->withEventId('evt_1')
            ->withDurationMs(420.5)
            ->withTraceId('trace-1')
            ->withSpan('span-1', 'span-0')
            ->withStatus(Status::Success)
            ->withAttributes(['order_id' => 'ord_1', 'app.id' => 'checkout'])
            ->withAttribute('amount_usd', 19.99)
            ->withData(['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->build();

        $arr = $event->toArray();

        $this->assertSame('2026-05-08T12:00:00.123Z', $arr['timestamp']);
        $this->assertSame('evt_1', $arr['event_id']);
        $this->assertSame(420.5, $arr['duration_ms']);
        $this->assertSame('trace-1', $arr['trace_id']);
        $this->assertSame('span-1', $arr['span_id']);
        $this->assertSame('span-0', $arr['parent_span_id']);
        $this->assertSame('success', $arr['status']);
        $this->assertSame(
            ['order_id' => 'ord_1', 'app.id' => 'checkout', 'amount_usd' => 19.99],
            $arr['attributes'],
        );
        $this->assertSame(['messages' => [['role' => 'user', 'content' => 'hi']]], $arr['data']);
    }

    public function testWithStatusError(): void
    {
        $arr = Event::now()
            ->withStatus(Status::Error)
            ->withAttributes([
                'error.type' => 'TimeoutError',
                'error.message' => 'upstream took too long',
            ])
            ->build()
            ->toArray();

        $this->assertSame('error', $arr['status']);
        $attributes = $arr['attributes'];
        $this->assertIsArray($attributes);
        $this->assertSame('TimeoutError', $attributes['error.type']);
        $this->assertSame('upstream took too long', $attributes['error.message']);
    }

    public function testRejectsLegacyTopLevelFields(): void
    {
        // Wire decoder runs DisallowUnknownFields. Confirm the SDK no longer
        // emits the legacy top-level fields that were promoted in 1.x — they
        // must now ride inside `attributes` or `data`.
        $arr = Event::now()->withAttribute('app.id', 'checkout')->build()->toArray();

        foreach (['app_id', 'environment', 'operation', 'model', 'usage', 'user_id', 'session_id', 'tools', 'messages', 'finish_reason', 'error_type', 'error_message'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $arr, "legacy field '{$legacy}' must not appear top-level");
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
}
