<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use Mesh0\Config;
use Mesh0\Http\Transport;
use Mesh0\Resource\Schema;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class SchemaResourceTest extends TestCase
{
    private MockHttpClient $mock;
    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->schema = new Schema(new Transport(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        ));
    }

    public function testGetReturnsAliasesAndPromoted(): void
    {
        $this->mock->queueJson(200, [
            'aliases' => [['id' => 'a1', 'name' => 'order_id']],
            'promoted' => [['id' => 'p1', 'name' => 'order_id', 'status' => 'ready']],
        ]);

        $out = $this->schema->get();

        $this->assertSame('GET', $this->mock->lastRequest()->getMethod());
        $this->assertSame('/v1/schema', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame([['id' => 'a1', 'name' => 'order_id']], $out['aliases']);
        $this->assertSame('ready', $out['promoted'][0]['status']);
    }

    public function testGetHandlesEmptyEnvelope(): void
    {
        $this->mock->queueJson(200, []);
        $out = $this->schema->get();
        $this->assertSame([], $out['aliases']);
        $this->assertSame([], $out['promoted']);
    }

    public function testListAliasesDropsEnvelope(): void
    {
        $this->mock->queueJson(200, [
            'aliases' => [['id' => 'a1']],
            'promoted' => [],
        ]);
        $this->assertSame([['id' => 'a1']], $this->schema->listAliases());
    }

    public function testListPromotedDropsEnvelope(): void
    {
        $this->mock->queueJson(200, [
            'aliases' => [],
            'promoted' => [['id' => 'p1']],
        ]);
        $this->assertSame([['id' => 'p1']], $this->schema->listPromoted());
    }

    public function testCreateAliasSendsCamelCasePayload(): void
    {
        $this->mock->queueJson(201, ['alias' => ['id' => 'a1', 'name' => 'order_id']]);

        $alias = $this->schema->createAlias('order_id', 'order.id', 'string', 'order id');

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/aliases', $req->getUri()->getPath());
        $this->assertSame([
            'name' => 'order_id',
            'jsonPath' => 'order.id',
            'fieldType' => 'string',
            'description' => 'order id',
        ], $this->mock->lastJsonBody());
        $this->assertSame(['id' => 'a1', 'name' => 'order_id'], $alias);
    }

    public function testCreateAliasDefaultsDescriptionToEmptyString(): void
    {
        $this->mock->queueJson(201, ['alias' => []]);
        $this->schema->createAlias('foo', 'foo', 'number');
        $this->assertSame('', $this->mock->lastJsonBody()['description']);
    }

    public function testDeleteAliasUrlEncodesName(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->schema->deleteAlias('order_id');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/aliases/order_id', $req->getUri()->getPath());
    }

    public function testDeleteAliasRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->schema->deleteAlias('');
    }

    public function testPromoteAliasUnwrapsPromoted(): void
    {
        $this->mock->queueJson(202, [
            'promoted' => ['id' => 'p1', 'status' => 'materializing'],
        ]);

        $promoted = $this->schema->promoteAlias('order_id');

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/aliases/order_id/promote', $req->getUri()->getPath());
        $this->assertSame('', (string) $req->getBody());
        $this->assertSame(['id' => 'p1', 'status' => 'materializing'], $promoted);
    }

    public function testPromoteAliasEnvelopeSurfacesWarning(): void
    {
        $this->mock->queueJson(202, [
            'promoted' => ['id' => 'p1'],
            'warning' => 'cardinality_probe_degraded',
        ]);

        $env = $this->schema->promoteAliasEnvelope('order_id');
        $this->assertSame('cardinality_probe_degraded', $env['warning']);
    }

    public function testDemotePromotedIssuesDelete(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->schema->demotePromoted('order_id');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/promoted/order_id', $req->getUri()->getPath());
    }

    public function testCancelPromotionIssuesPostWithNoBody(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->schema->cancelPromotion('order_id');

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/promoted/order_id/cancel', $req->getUri()->getPath());
        $this->assertSame('', (string) $req->getBody());
    }
}
