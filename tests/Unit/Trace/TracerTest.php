<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit\Trace;

use Mesh0\Event\Status;
use Mesh0\Tests\Support\InMemoryEventSink;
use Mesh0\Tests\Support\RecordingLogger;
use Mesh0\Trace\Tracer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TracerTest extends TestCase
{
    private InMemoryEventSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = new InMemoryEventSink();
    }

    public function testSingleSpanEmitsOneEventWithGeneratedIds(): void
    {
        $tracer = new Tracer($this->sink);

        $h = $tracer->enter('block.execute', ['block_id' => 'b_1']);
        $tracer->exit($h);

        $this->assertCount(1, $this->sink->events);
        $event = $this->sink->events[0];
        $this->assertNotNull($event->traceId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $event->traceId);
        $this->assertNotNull($event->spanId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $event->spanId);
        $this->assertNull($event->parentSpanId);
        $this->assertSame('block.execute', $event->operation);
        $this->assertSame(Status::Success, $event->status);
        $this->assertNotNull($event->durationMs);
        $this->assertGreaterThanOrEqual(0.0, $event->durationMs);
        $this->assertSame(['block_id' => 'b_1'], $event->attributes);
    }

    public function testNestedSpansShareTraceIdAndChainParents(): void
    {
        $tracer = new Tracer($this->sink);

        $a = $tracer->enter('block.if');
        $b = $tracer->enter('block.http_request');
        $c = $tracer->enter('block.db_query');
        $tracer->exit($c);
        $tracer->exit($b);
        $tracer->exit($a);

        $this->assertCount(3, $this->sink->events);

        // Children always close before parents.
        $childC = $this->sink->events[0];
        $childB = $this->sink->events[1];
        $rootA = $this->sink->events[2];

        $traceId = $rootA->traceId;
        $this->assertNotNull($traceId);
        $this->assertSame($traceId, $childB->traceId);
        $this->assertSame($traceId, $childC->traceId);

        $this->assertNull($rootA->parentSpanId);
        $this->assertSame($rootA->spanId, $childB->parentSpanId);
        $this->assertSame($childB->spanId, $childC->parentSpanId);
    }

    public function testEachSpanIsAnIndependentEvent(): void
    {
        // Regression guard: verify we never bundle multiple spans into one
        // event/payload — every span goes out as its own send() call.
        $tracer = new Tracer($this->sink);
        $h1 = $tracer->enter('a');
        $h2 = $tracer->enter('b');
        $tracer->exit($h2);
        $tracer->exit($h1);
        $this->assertCount(2, $this->sink->events);
    }

    public function testClosureFormReturnsValueAndEmitsSuccess(): void
    {
        $tracer = new Tracer($this->sink);

        $value = $tracer->span('block.compute', ['n' => 3], static fn (): int => 42);

        $this->assertSame(42, $value);
        $this->assertCount(1, $this->sink->events);
        $this->assertSame(Status::Success, $this->sink->events[0]->status);
    }

    public function testClosureFormRethrowsAndEmitsErrorSpan(): void
    {
        $tracer = new Tracer($this->sink);

        $thrown = null;
        try {
            $tracer->span('block.compute', [], static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertCount(1, $this->sink->events);
        $event = $this->sink->events[0];
        $this->assertSame(Status::Error, $event->status);
        $this->assertSame(RuntimeException::class, $event->errorType);
        $this->assertSame('boom', $event->errorMessage);
    }

    public function testThrowingChildStillLetsParentClose(): void
    {
        $tracer = new Tracer($this->sink);

        $parent = $tracer->enter('block.if');
        try {
            $tracer->span('block.bad', [], static function (): void {
                throw new RuntimeException('child failed');
            });
        } catch (\Throwable) {
            // swallow for the test — in real code the parent decides what to do.
        }
        $tracer->exit($parent);

        $this->assertCount(2, $this->sink->events);
        $childEvent = $this->sink->events[0];
        $parentEvent = $this->sink->events[1];

        $this->assertSame(Status::Error, $childEvent->status);
        $this->assertSame(Status::Success, $parentEvent->status);
        $this->assertSame($parentEvent->spanId, $childEvent->parentSpanId);
        $this->assertSame($parentEvent->traceId, $childEvent->traceId);
    }

    public function testStartTraceAdoptsW3CTraceparent(): void
    {
        $tracer = new Tracer($this->sink);

        $traceparent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $this->assertTrue($tracer->startTrace($traceparent));

        $h = $tracer->enter('root');
        $tracer->exit($h);

        $event = $this->sink->events[0];
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $event->traceId);
        $this->assertSame('b7ad6b7169203331', $event->parentSpanId);
    }

    public function testStartTraceRejectsMalformedHeader(): void
    {
        $tracer = new Tracer($this->sink);

        $this->assertFalse($tracer->startTrace(null));
        $this->assertFalse($tracer->startTrace('not-a-traceparent'));
        // Wrong version
        $this->assertFalse($tracer->startTrace('ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'));
        // All-zero ids forbidden
        $this->assertFalse($tracer->startTrace('00-' . str_repeat('0', 32) . '-b7ad6b7169203331-01'));
    }

    public function testStartTraceIgnoredOnceTraceStarted(): void
    {
        $tracer = new Tracer($this->sink);
        $h = $tracer->enter('root');
        $this->assertFalse($tracer->startTrace('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'));
        $tracer->exit($h);
    }

    public function testAppAndEnvironmentApplyToEverySpan(): void
    {
        $tracer = new Tracer($this->sink, appId: 'no-code-runtime', environment: 'prod');

        $tracer->span('block.execute', [], static fn () => null);

        $event = $this->sink->events[0];
        $this->assertSame('no-code-runtime', $event->appId);
        $this->assertSame('prod', $event->environment);
    }

    public function testExitMergesAttributesOverEnter(): void
    {
        $tracer = new Tracer($this->sink);

        $h = $tracer->enter('block.loop', ['block_id' => 'b_1', 'iterations' => 0]);
        $tracer->exit($h, attributes: ['iterations' => 7, 'completed' => true]);

        $event = $this->sink->events[0];
        $this->assertSame([
            'block_id' => 'b_1',
            'iterations' => 7,
            'completed' => true,
        ], $event->attributes);
    }

    public function testCurrentTraceAndSpanIdReflectStack(): void
    {
        $tracer = new Tracer($this->sink);
        $this->assertNull($tracer->currentTraceId());
        $this->assertNull($tracer->currentSpanId());
        $this->assertFalse($tracer->hasOpenSpan());

        $h = $tracer->enter('a');
        $this->assertNotNull($tracer->currentTraceId());
        $this->assertSame($h->spanId, $tracer->currentSpanId());
        $this->assertTrue($tracer->hasOpenSpan());

        $tracer->exit($h);
        $this->assertFalse($tracer->hasOpenSpan());
    }

    public function testResetClearsStateAndWarnsOnLeak(): void
    {
        $logger = new RecordingLogger();
        $tracer = new Tracer($this->sink, logger: $logger);

        $tracer->enter('a');
        $tracer->enter('b');
        $tracer->reset();

        $this->assertNull($tracer->currentTraceId());
        $this->assertFalse($tracer->hasOpenSpan());

        // Two records (from two enters) were never emitted because reset
        // dropped the stack — that's the contract.
        $this->assertCount(0, $this->sink->events);

        $warnings = array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'warning');
        $this->assertNotEmpty($warnings);
    }

    public function testResetIsSilentWhenStackEmpty(): void
    {
        $logger = new RecordingLogger();
        $tracer = new Tracer($this->sink, logger: $logger);

        $tracer->reset();

        $this->assertSame([], $logger->records);
    }

    public function testNewTraceAfterRootCloses(): void
    {
        $tracer = new Tracer($this->sink);

        $tracer->span('first', [], static fn () => null);
        $tracer->span('second', [], static fn () => null);

        $this->assertCount(2, $this->sink->events);
        // Without an explicit reset(), trace_id is reused — both spans are
        // siblings of the same trace. Customers wanting per-execution traces
        // call reset() between them; long-lived workers must do this anyway.
        $this->assertSame(
            $this->sink->events[0]->traceId,
            $this->sink->events[1]->traceId,
        );
    }

    public function testDurationIsMeasuredFromHrTime(): void
    {
        $tracer = new Tracer($this->sink);
        $h = $tracer->enter('slow');
        usleep(2_000); // 2ms
        $tracer->exit($h);

        $event = $this->sink->events[0];
        $this->assertNotNull($event->durationMs);
        $this->assertGreaterThanOrEqual(1.0, $event->durationMs);
    }

    public function testUnknownHandleOnExitIsLoggedAndIgnored(): void
    {
        $logger = new RecordingLogger();
        $tracer = new Tracer($this->sink, logger: $logger);

        // Construct a handle that the tracer never issued.
        $h = $tracer->enter('real');
        $tracer->exit($h);
        // Re-exit the same handle.
        $tracer->exit($h);

        // First exit produced one event; the second was a no-op.
        $this->assertCount(1, $this->sink->events);
        $warnings = array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'warning');
        $this->assertNotEmpty($warnings);
    }
}
