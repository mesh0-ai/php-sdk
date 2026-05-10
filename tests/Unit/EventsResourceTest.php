<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Mesh0\Config;
use Mesh0\Event\Event;
use Mesh0\Http\Transport;
use Mesh0\Resource\Events;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class EventsResourceTest extends TestCase
{
    private MockHttpClient $mock;
    private Events $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->events = new Events(new Transport(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        ));
    }

    public function testSendOneReportsAcceptedCount(): void
    {
        $this->mock->queueJson(200, ['accepted' => 1]);

        $result = $this->events->send(Event::now()->withAttribute('span.name', 'test')->build());

        $this->assertSame(1, $result);
        $payload = $this->mock->lastJsonBody();
        $events = $payload['events'] ?? null;
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
    }

    public function testListReturnsCursorAndEvents(): void
    {
        $this->mock->queueJson(200, [
            'events' => [['event_id' => 'a'], ['event_id' => 'b']],
            'nextCursor' => 'cur-1',
            'hasMore' => true,
        ]);

        $page = $this->events->list(limit: 2);

        $this->assertCount(2, $page['events']);
        $this->assertSame('cur-1', $page['nextCursor']);
        $this->assertTrue($page['hasMore']);
    }

    public function testIterateFollowsCursorsUntilExhausted(): void
    {
        $this->mock->queueJson(200, [
            'events' => [['event_id' => 'a']],
            'nextCursor' => 'cur-1',
            'hasMore' => true,
        ]);
        $this->mock->queueJson(200, [
            'events' => [['event_id' => 'b']],
            'nextCursor' => null,
            'hasMore' => false,
        ]);

        $ids = [];
        foreach ($this->events->iterate(pageSize: 1) as $row) {
            $ids[] = $row['event_id'];
        }

        $this->assertSame(['a', 'b'], $ids);
    }
}
