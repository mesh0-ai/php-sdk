<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit\Trace;

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

        $h = $tracer->enter(['span.name' => 'block.execute', 'block_id' => 'b_1']);
        $tracer->exit($h);

        $this->assertCount(1, $this->sink->events);
        $event = $this->sink->events[0];
        $this->assertNotNull($event->traceId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $event->traceId);
        $this->assertNotNull($event->spanId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $event->spanId);
        $this->assertNull($event->parentSpanId);
        $this->assertNotNull($event->attributes);
        $this->assertSame('block.execute', $event->attributes['span.name']);
        $this->assertSame('b_1', $event->attributes['block_id']);
    }

    public function testTracerDoesNotAutoStampDurationMs(): void
    {
        // 4.0.0: Tracer no longer measures wall time and writes it as an
        // attribute. Callers who want a queryable duration put it in
        // attributes themselves. Regression guard: if any future refactor
        // re-introduces auto-stamping inside Tracer, this fails.
        $tracer = new Tracer($this->sink);

        $h = $tracer->enter(['span.name' => 'block.compute']);
        $tracer->exit($h);

        $event = $this->sink->events[0];
        $this->assertNotNull($event->attributes);
        $this->assertArrayNotHasKey('duration_ms', $event->attributes);
    }

    public function testNestedSpansShareTraceIdAndChainParents(): void
    {
        $tracer = new Tracer($this->sink);

        $a = $tracer->enter(['span.name' => 'block.if']);
        $b = $tracer->enter(['span.name' => 'block.http_request']);
        $c = $tracer->enter(['span.name' => 'block.db_query']);
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
        $h1 = $tracer->enter(['span.name' => 'a']);
        $h2 = $tracer->enter(['span.name' => 'b']);
        $tracer->exit($h2);
        $tracer->exit($h1);
        $this->assertCount(2, $this->sink->events);
    }

    public function testClosureFormReturnsValueAndEmitsSuccess(): void
    {
        $tracer = new Tracer($this->sink);

        $value = $tracer->span(['span.name' => 'block.compute', 'n' => 3], static fn (): int => 42);

        $this->assertSame(42, $value);
        $this->assertCount(1, $this->sink->events);
    }

    public function testClosureFormRethrowsAndEmitsErrorSpanWithoutInjectingAttrs(): void
    {
        $tracer = new Tracer($this->sink);

        $thrown = null;
        try {
            $tracer->span(['span.name' => 'block.compute'], static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertCount(1, $this->sink->events);
        $event = $this->sink->events[0];
        $this->assertNotNull($event->attributes);
        // error.* is the caller's responsibility — closure form does not inject.
        $this->assertArrayNotHasKey('error.type', $event->attributes);
        $this->assertArrayNotHasKey('error.message', $event->attributes);
        $this->assertSame('block.compute', $event->attributes['span.name']);
    }

    public function testManualErrorPathCanCarryErrorAttributes(): void
    {
        $tracer = new Tracer($this->sink);

        $h = $tracer->enter(['span.name' => 'block.compute']);
        $tracer->exit($h, [
            'status'        => 'error',
            'error.type'    => RuntimeException::class,
            'error.message' => 'boom',
        ]);

        $event = $this->sink->events[0];
        $this->assertNotNull($event->attributes);
        $this->assertSame('error', $event->attributes['status']);
        $this->assertSame(RuntimeException::class, $event->attributes['error.type']);
        $this->assertSame('boom', $event->attributes['error.message']);
    }

    public function testThrowingChildStillLetsParentClose(): void
    {
        $tracer = new Tracer($this->sink);

        $parent = $tracer->enter(['span.name' => 'block.if']);
        try {
            $tracer->span(['span.name' => 'block.bad'], static function (): void {
                throw new RuntimeException('child failed');
            });
        } catch (\Throwable) {
            // swallow for the test — in real code the parent decides what to do.
        }
        $tracer->exit($parent);

        $this->assertCount(2, $this->sink->events);
        $childEvent = $this->sink->events[0];
        $parentEvent = $this->sink->events[1];

        $this->assertSame($parentEvent->spanId, $childEvent->parentSpanId);
        $this->assertSame($parentEvent->traceId, $childEvent->traceId);
    }

    public function testStartTraceAdoptsW3CTraceparent(): void
    {
        $tracer = new Tracer($this->sink);

        $traceparent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $this->assertTrue($tracer->startTrace($traceparent));

        $h = $tracer->enter(['span.name' => 'root']);
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
        $h = $tracer->enter(['span.name' => 'root']);
        $this->assertFalse($tracer->startTrace('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'));
        $tracer->exit($h);
    }

    public function testExitMergesAttributesOverEnter(): void
    {
        $tracer = new Tracer($this->sink);

        $h = $tracer->enter(['span.name' => 'block.loop', 'block_id' => 'b_1', 'iterations' => 0]);
        $tracer->exit($h, attributes: ['iterations' => 7, 'completed' => true]);

        $event = $this->sink->events[0];
        $this->assertNotNull($event->attributes);
        $this->assertSame('b_1', $event->attributes['block_id']);
        $this->assertSame(7, $event->attributes['iterations']);
        $this->assertTrue($event->attributes['completed']);
        $this->assertSame('block.loop', $event->attributes['span.name']);
    }

    public function testEnterWithoutAttributesProducesNoAttributes(): void
    {
        // No magic injection — if the caller passes nothing, the event has
        // no attributes at all.
        $tracer = new Tracer($this->sink);
        $h = $tracer->enter();
        $tracer->exit($h);

        $event = $this->sink->events[0];
        $this->assertNull($event->attributes);
    }

    public function testCurrentTraceAndSpanIdReflectStack(): void
    {
        $tracer = new Tracer($this->sink);
        $this->assertNull($tracer->currentTraceId());
        $this->assertNull($tracer->currentSpanId());
        $this->assertFalse($tracer->hasOpenSpan());

        $h = $tracer->enter(['span.name' => 'a']);
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

        $tracer->enter(['span.name' => 'a']);
        $tracer->enter(['span.name' => 'b']);
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

        $tracer->span(['span.name' => 'first'], static fn () => null);
        $tracer->span(['span.name' => 'second'], static fn () => null);

        $this->assertCount(2, $this->sink->events);
        $this->assertSame(
            $this->sink->events[0]->traceId,
            $this->sink->events[1]->traceId,
        );
    }

    public function testUnknownHandleOnExitIsLoggedAndIgnored(): void
    {
        $logger = new RecordingLogger();
        $tracer = new Tracer($this->sink, logger: $logger);

        $h = $tracer->enter(['span.name' => 'real']);
        $tracer->exit($h);
        // Re-exit the same handle.
        $tracer->exit($h);

        // First exit produced one event; the second was a no-op.
        $this->assertCount(1, $this->sink->events);
        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => $r['level'] === 'warning',
        ));
        $this->assertNotEmpty($warnings);
        // Old "operation" key must not leak back in — the SDK does not promote
        // user-convention keys into log context.
        $this->assertArrayNotHasKey('operation', $warnings[0]['context']);
    }

    public function testExitingNonTopHandleEmitsThatSpanAndWarnsAboutDroppedFrames(): void
    {
        $logger = new RecordingLogger();
        $tracer = new Tracer($this->sink, logger: $logger);

        $a = $tracer->enter(['span.name' => 'outer']);
        $middle = $tracer->enter(['span.name' => 'middle']);
        $inner = $tracer->enter(['span.name' => 'inner']);

        // Skip middle/inner exits — close the outer handle directly.
        $tracer->exit($a);

        // Only `outer` is emitted; `middle` and `inner` are dropped, but a
        // warning records the loss so it isn't silent data loss.
        $this->assertCount(1, $this->sink->events);
        $event = $this->sink->events[0];
        $this->assertNotNull($event->attributes);
        $this->assertSame('outer', $event->attributes['span.name']);
        $this->assertFalse($tracer->hasOpenSpan());

        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => $r['level'] === 'warning',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame(2, $warnings[0]['context']['dropped_count']);
        $this->assertSame([$middle->spanId, $inner->spanId], $warnings[0]['context']['dropped_span_ids']);
        $this->assertSame($a->spanId, $warnings[0]['context']['closed_span_id']);
        // Old keys that promoted user convention into log context must stay gone.
        $this->assertArrayNotHasKey('closed_span', $warnings[0]['context']);
        $this->assertArrayNotHasKey('dropped_operations', $warnings[0]['context']);
    }

    public function testStartTraceAcceptsAnyFlagsByteAsW3CRequires(): void
    {
        // W3C says unknown flags MUST be ignored, not rejected. Pin the
        // contract so we don't accidentally tighten this in a refactor.
        foreach (['00', '01', 'ff'] as $flags) {
            $tracer = new Tracer($this->sink);
            $this->assertTrue(
                $tracer->startTrace('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-' . $flags),
                "flags byte {$flags} should be accepted",
            );
        }
    }

    public function testSpanWithoutSpanNameAttributeStillEmits(): void
    {
        // span.name is a user convention, not an SDK requirement. A caller
        // that never sets it must still get a valid span event.
        $tracer = new Tracer($this->sink);

        $tracer->span(['block_id' => 'b_1'], static fn () => null);

        $this->assertCount(1, $this->sink->events);
        $event = $this->sink->events[0];
        $this->assertNotNull($event->attributes);
        $this->assertSame('b_1', $event->attributes['block_id']);
        $this->assertArrayNotHasKey('span.name', $event->attributes);
    }

    public function testSinkFailureIsLoggedAndStackInvariantHolds(): void
    {
        // The stack must unwind cleanly even when the sink throws — otherwise
        // a single failed send() corrupts every outer span. See the
        // "load-bearing stack invariant" comment in Tracer::exit().
        $throwingSink = new class () implements \Mesh0\Event\EventSink {
            public int $sends = 0;

            public function send(\Mesh0\Event\Event|\Mesh0\Event\EventBuilder $event): void
            {
                ++$this->sends;
                throw new RuntimeException('sink down');
            }
        };
        $logger = new RecordingLogger();
        $tracer = new Tracer($throwingSink, logger: $logger);

        $outer = $tracer->enter(['span.name' => 'outer']);
        $inner = $tracer->enter(['span.name' => 'inner']);

        // Inner exit triggers a send that throws — the stack must still pop.
        $tracer->exit($inner);
        $this->assertTrue($tracer->hasOpenSpan());
        $this->assertSame($outer->spanId, $tracer->currentSpanId());

        // Outer exit does the same, leaving the tracer fully drained.
        $tracer->exit($outer);
        $this->assertFalse($tracer->hasOpenSpan());

        $this->assertSame(2, $throwingSink->sends);
        $errors = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => $r['level'] === 'error',
        ));
        $this->assertCount(2, $errors);
        $this->assertArrayNotHasKey('operation', $errors[0]['context']);
    }

    public function testClosureFormPropagatesNullReturn(): void
    {
        $tracer = new Tracer($this->sink);
        $value = $tracer->span(['span.name' => 'block.noop'], static fn () => null);

        $this->assertNull($value);
        $this->assertCount(1, $this->sink->events);
    }
}
