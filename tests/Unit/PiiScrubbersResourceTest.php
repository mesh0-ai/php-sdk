<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Mesh0\Config;
use Mesh0\Http\Transport;
use Mesh0\Resource\PiiScrubbers;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class PiiScrubbersResourceTest extends TestCase
{
    private MockHttpClient $mock;
    private PiiScrubbers $scrubbers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->scrubbers = new PiiScrubbers(new Transport(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        ));
    }

    public function testListScrubbersUnwraps(): void
    {
        $this->mock->queueJson(200, [
            'scrubbers' => [['id' => 's_1'], ['id' => 's_2']],
            'mode' => 'enforce',
            'builtins' => ['email'],
            'egress_sources' => ['mcp', 'rest_events'],
            'enforcement_points' => ['egress', 'ingress', 'both'],
        ]);

        $rules = $this->scrubbers->listScrubbers();

        $this->assertSame('/v1/pii-scrubbers', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertCount(2, $rules);
        $this->assertSame(['id' => 's_1'], $rules[0]);
    }

    public function testListScrubbersHandlesMissingKey(): void
    {
        $this->mock->queueJson(200, []);
        $this->assertSame([], $this->scrubbers->listScrubbers());
    }

    public function testListEnvelopeReturnsFullResponse(): void
    {
        $this->mock->queueJson(200, [
            'scrubbers' => [],
            'mode' => 'audit',
            'builtins' => ['email', 'phone_us'],
            'egress_sources' => ['mcp'],
            'enforcement_points' => ['egress', 'ingress', 'both'],
        ]);

        $env = $this->scrubbers->listEnvelope();

        $this->assertSame('audit', $env['mode']);
        $this->assertSame(['email', 'phone_us'], $env['builtins']);
        $this->assertSame(['egress', 'ingress', 'both'], $env['enforcement_points']);
    }

    public function testGetScrubberUnwraps(): void
    {
        $this->mock->queueJson(200, ['scrubber' => ['id' => 's_1', 'slug' => 'card']]);

        $s = $this->scrubbers->getScrubber('card');

        $this->assertSame('/v1/pii-scrubbers/card', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame(['id' => 's_1', 'slug' => 'card'], $s);
    }

    public function testGetScrubberEscapesRef(): void
    {
        $this->mock->queueJson(200, []);
        $this->scrubbers->getScrubber('weird/ref');
        $this->assertSame('/v1/pii-scrubbers/weird%2Fref', $this->mock->lastRequest()->getUri()->getPath());
    }

    public function testCreateScrubberPostsBodyAndUnwraps(): void
    {
        $this->mock->queueJson(201, ['scrubber' => ['id' => 's_new', 'slug' => 'cc']]);

        $s = $this->scrubbers->createScrubber([
            'name' => 'Credit cards',
            'slug' => 'cc',
            'kind' => 'regex',
            'pattern' => '\\d{13,19}',
            'replacement' => '[CC]',
            'scope' => ['data'],
        ]);

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/pii-scrubbers', $req->getUri()->getPath());
        $this->assertSame([
            'name' => 'Credit cards',
            'slug' => 'cc',
            'kind' => 'regex',
            'pattern' => '\\d{13,19}',
            'replacement' => '[CC]',
            'scope' => ['data'],
        ], $this->mock->lastJsonBody());
        $this->assertSame(['id' => 's_new', 'slug' => 'cc'], $s);
    }

    public function testCreateScrubberDoesNotRetryOn5xx(): void
    {
        // Single queued 503 — if retry were enabled the transport would
        // dequeue twice and throw on the empty queue. With idempotent: false
        // the call should fail through immediately on the first response.
        $this->mock->queueJson(503, ['error' => 'internal_error']);

        $this->expectException(\Mesh0\Exception\ServerException::class);
        $this->scrubbers->createScrubber(['name' => 'x']);

        $this->assertCount(1, $this->mock->requests);
    }

    public function testUpdateScrubberPatchesPartialInput(): void
    {
        $this->mock->queueJson(200, ['scrubber' => ['id' => 's_1', 'enabled' => false]]);

        $s = $this->scrubbers->updateScrubber('s_1', ['enabled' => false]);

        $req = $this->mock->lastRequest();
        $this->assertSame('PATCH', $req->getMethod());
        $this->assertSame('/v1/pii-scrubbers/s_1', $req->getUri()->getPath());
        $this->assertSame(['enabled' => false], $this->mock->lastJsonBody());
        $this->assertSame(['id' => 's_1', 'enabled' => false], $s);
    }

    public function testDeleteScrubberIssuesDelete(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->scrubbers->deleteScrubber('s_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/pii-scrubbers/s_1', $req->getUri()->getPath());
    }

    public function testGetModeReturnsModeString(): void
    {
        $this->mock->queueJson(200, ['mode' => 'audit']);

        $mode = $this->scrubbers->getMode();

        $this->assertSame('/v1/pii-scrub-mode', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame('audit', $mode);
    }

    public function testSetModePutsBody(): void
    {
        $this->mock->queueJson(200, ['mode' => 'off']);

        $mode = $this->scrubbers->setMode('off');

        $req = $this->mock->lastRequest();
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/v1/pii-scrub-mode', $req->getUri()->getPath());
        $this->assertSame(['mode' => 'off'], $this->mock->lastJsonBody());
        $this->assertSame('off', $mode);
    }
}
