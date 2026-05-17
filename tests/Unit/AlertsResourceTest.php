<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Mesh0\Config;
use Mesh0\Http\Transport;
use Mesh0\Resource\Alerts;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class AlertsResourceTest extends TestCase
{
    private MockHttpClient $mock;
    private Alerts $alerts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->alerts = new Alerts(new Transport(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        ));
    }

    public function testListAlertsUnwraps(): void
    {
        $this->mock->queueJson(200, [
            'alerts' => [['id' => 'a_1'], ['id' => 'a_2']],
        ]);

        $alerts = $this->alerts->listAlerts();

        $this->assertSame('/v1/alerts', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertCount(2, $alerts);
        $this->assertSame(['id' => 'a_1'], $alerts[0]);
    }

    public function testListAlertsHandlesMissingKey(): void
    {
        $this->mock->queueJson(200, []);
        $this->assertSame([], $this->alerts->listAlerts());
    }

    public function testGetAlertUnwrapsAlert(): void
    {
        $this->mock->queueJson(200, ['alert' => ['id' => 'a_1', 'slug' => 'errs']]);

        $a = $this->alerts->getAlert('errs');

        $this->assertSame('/v1/alerts/errs', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame(['id' => 'a_1', 'slug' => 'errs'], $a);
    }

    public function testGetAlertEscapesRef(): void
    {
        $this->mock->queueJson(200, []);
        $this->alerts->getAlert('weird/ref');
        $this->assertSame('/v1/alerts/weird%2Fref', $this->mock->lastRequest()->getUri()->getPath());
    }

    public function testCreateAlertPostsInputAndOmitsIdempotencyHeader(): void
    {
        $this->mock->queueJson(201, ['alert' => ['id' => 'a_new']]);

        $a = $this->alerts->createAlert(['name' => 'spike', 'threshold' => 10]);

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/alerts', $req->getUri()->getPath());
        $this->assertSame(['name' => 'spike', 'threshold' => 10], $this->mock->lastJsonBody());
        $this->assertFalse($req->hasHeader('Idempotency-Key'));
        $this->assertSame(['id' => 'a_new'], $a);
    }

    public function testCreateAlertForwardsIdempotencyKey(): void
    {
        $this->mock->queueJson(201, ['alert' => ['id' => 'a_new']]);

        $this->alerts->createAlert(['name' => 'spike'], idempotencyKey: 'req-123');

        $req = $this->mock->lastRequest();
        $this->assertSame('req-123', $req->getHeaderLine('Idempotency-Key'));
    }

    public function testUpdateAlertPatchesPartialInput(): void
    {
        $this->mock->queueJson(200, ['alert' => ['id' => 'a_1', 'name' => 'renamed']]);

        $a = $this->alerts->updateAlert('a_1', ['name' => 'renamed']);

        $req = $this->mock->lastRequest();
        $this->assertSame('PATCH', $req->getMethod());
        $this->assertSame('/v1/alerts/a_1', $req->getUri()->getPath());
        $this->assertSame(['name' => 'renamed'], $this->mock->lastJsonBody());
        $this->assertSame(['id' => 'a_1', 'name' => 'renamed'], $a);
    }

    public function testDeleteAlertIssuesDelete(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->alerts->deleteAlert('a_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/alerts/a_1', $req->getUri()->getPath());
    }

    public function testTestFireAlertPostsEmptyBody(): void
    {
        $this->mock->queueJson(202, ['ok' => true]);

        $resp = $this->alerts->testFireAlert('a_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/alerts/a_1/test', $req->getUri()->getPath());
        $this->assertSame('[]', (string) $req->getBody());
        $this->assertTrue($resp['ok']);
    }

    public function testListAlertHistoryDefaultsLimit(): void
    {
        $this->mock->queueJson(200, ['history' => [['id' => 'h_1']]]);

        $h = $this->alerts->listAlertHistory('a_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('/v1/alerts/a_1/history', $req->getUri()->getPath());
        $this->assertSame('', $req->getUri()->getQuery());
        $this->assertCount(1, $h);
    }

    public function testListAlertHistoryAppendsLimit(): void
    {
        $this->mock->queueJson(200, ['history' => []]);

        $this->alerts->listAlertHistory('a_1', 25);

        $this->assertSame('limit=25', $this->mock->lastRequest()->getUri()->getQuery());
    }

    public function testListChannelsUnwraps(): void
    {
        $this->mock->queueJson(200, ['channels' => [['id' => 'c_1', 'type' => 'slack']]]);

        $cs = $this->alerts->listChannels();

        $this->assertSame('/v1/alert-channels', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertCount(1, $cs);
        $this->assertSame(['id' => 'c_1', 'type' => 'slack'], $cs[0]);
    }

    public function testGetChannelUnwraps(): void
    {
        $this->mock->queueJson(200, ['channel' => ['id' => 'c_1']]);

        $c = $this->alerts->getChannel('c_1');

        $this->assertSame('/v1/alert-channels/c_1', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame(['id' => 'c_1'], $c);
    }

    public function testCreateChannelForwardsIdempotencyKey(): void
    {
        $this->mock->queueJson(201, ['channel' => ['id' => 'c_new']]);

        $this->alerts->createChannel(
            ['name' => 'ops', 'type' => 'slack', 'config' => ['webhookUrl' => 'https://x']],
            idempotencyKey: 'mkch-1',
        );

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/alert-channels', $req->getUri()->getPath());
        $this->assertSame('mkch-1', $req->getHeaderLine('Idempotency-Key'));
        $this->assertSame([
            'name' => 'ops',
            'type' => 'slack',
            'config' => ['webhookUrl' => 'https://x'],
        ], $this->mock->lastJsonBody());
    }

    public function testUpdateChannelPatchesInput(): void
    {
        $this->mock->queueJson(200, ['channel' => ['id' => 'c_1', 'name' => 'ops-2']]);

        $c = $this->alerts->updateChannel('c_1', ['name' => 'ops-2']);

        $req = $this->mock->lastRequest();
        $this->assertSame('PATCH', $req->getMethod());
        $this->assertSame('/v1/alert-channels/c_1', $req->getUri()->getPath());
        $this->assertSame(['name' => 'ops-2'], $this->mock->lastJsonBody());
        $this->assertSame(['id' => 'c_1', 'name' => 'ops-2'], $c);
    }

    public function testDeleteChannelIssuesDelete(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->alerts->deleteChannel('c_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/alert-channels/c_1', $req->getUri()->getPath());
    }

    public function testTestFireChannelPostsEmptyBody(): void
    {
        $this->mock->queueJson(202, ['ok' => true]);

        $this->alerts->testFireChannel('c_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/alert-channels/c_1/test', $req->getUri()->getPath());
    }
}
