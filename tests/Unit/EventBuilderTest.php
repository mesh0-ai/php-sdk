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
            ->withApp('checkout', 'prod')
            ->withOperation('charge.captured')
            ->withModel('anthropic', 'claude-opus-4-7')
            ->withUsage(promptTokens: 100, completionTokens: 50, totalTokens: 150, costUsd: 0.012)
            ->withDurationMs(420.5)
            ->withUser('user_42')
            ->withSession('sess_99')
            ->withTraceId('trace-1')
            ->withSpan('span-1', 'span-0')
            ->withStatus(Status::Success)
            ->withTools(['search', 'retrieve'])
            ->withAttributes(['order_id' => 'ord_1'])
            ->withAttribute('amount_usd', 19.99)
            ->withMessages([['role' => 'user', 'content' => 'hi']])
            ->build();

        $arr = $event->toArray();

        $this->assertSame('2026-05-08T12:00:00.123Z', $arr['timestamp']);
        $this->assertSame('checkout', $arr['app_id']);
        $this->assertSame('prod', $arr['environment']);
        $this->assertSame('charge.captured', $arr['operation']);
        $this->assertSame(['provider' => 'anthropic', 'id' => 'claude-opus-4-7'], $arr['model']);
        $this->assertSame(
            ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150, 'cost_usd' => 0.012],
            $arr['usage'],
        );
        $this->assertSame(420.5, $arr['duration_ms']);
        $this->assertSame('user_42', $arr['user_id']);
        $this->assertSame('sess_99', $arr['session_id']);
        $this->assertSame('trace-1', $arr['trace_id']);
        $this->assertSame('span-1', $arr['span_id']);
        $this->assertSame('span-0', $arr['parent_span_id']);
        $this->assertSame('success', $arr['status']);
        $this->assertSame(['search', 'retrieve'], $arr['tools']);
        $this->assertSame(['order_id' => 'ord_1', 'amount_usd' => 19.99], $arr['attributes']);
    }

    public function testWithErrorMarksStatusAndFields(): void
    {
        $arr = Event::now()
            ->withError('TimeoutError', 'upstream took too long')
            ->build()
            ->toArray();

        $this->assertSame('error', $arr['status']);
        $this->assertSame('TimeoutError', $arr['error_type']);
        $this->assertSame('upstream took too long', $arr['error_message']);
    }

    public function testMinimalEventOnlySerializesTimestamp(): void
    {
        $arr = Event::at(new DateTimeImmutable('2026-01-01T00:00:00Z'))->build()->toArray();
        $this->assertSame(['timestamp'], array_keys($arr));
    }

    public function testBuilderIsImmutable(): void
    {
        $base = Event::now();
        $a = $base->withOperation('a');
        $b = $base->withOperation('b');
        $this->assertSame('a', $a->build()->toArray()['operation']);
        $this->assertSame('b', $b->build()->toArray()['operation']);
    }
}
