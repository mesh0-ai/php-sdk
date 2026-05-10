<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Mesh0\Config;
use Mesh0\Exception\AuthenticationException;
use Mesh0\Exception\BadRequestException;
use Mesh0\Exception\NetworkException;
use Mesh0\Exception\NotFoundException;
use Mesh0\Exception\RateLimitException;
use Mesh0\Exception\ServerException;
use Mesh0\Http\Transport;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class TransportTest extends TestCase
{
    private MockHttpClient $mock;
    private Transport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->transport = new Transport(
            new Config(
                apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
                maxRetries: 0,
            ),
            $this->mock,
            $factory,
            $factory,
        );
    }

    public function testGetSendsAuthHeaderAndReturnsBody(): void
    {
        $this->mock->queueJson(200, ['ok' => true, 'value' => 42]);

        $result = $this->transport->get('/v1/me');

        $this->assertSame(['ok' => true, 'value' => 42], $result);
        $req = $this->mock->lastRequest();
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('Bearer m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', $req->getHeaderLine('Authorization'));
        $this->assertStringContainsString('mesh0-php-sdk/', $req->getHeaderLine('User-Agent'));
        $this->assertSame('https://api.mesh0.ai/v1/me', (string) $req->getUri());
    }

    public function testQueryStringIsEncoded(): void
    {
        $this->mock->queueJson(200, []);
        $this->transport->get('/v1/events', ['limit' => 25, 'cursor' => null, 'q' => 'hello world']);

        $uri = (string) $this->mock->lastRequest()->getUri();
        $this->assertStringContainsString('limit=25', $uri);
        $this->assertStringContainsString('q=hello+world', $uri);
        $this->assertStringNotContainsString('cursor=', $uri);
    }

    public function testPostSerializesJson(): void
    {
        $this->mock->queueJson(200, ['accepted' => 2]);
        $this->transport->post('/v1/events', ['events' => [['ok' => true]]]);

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('application/json', $req->getHeaderLine('Content-Type'));
        $this->assertSame('{"events":[{"ok":true}]}', (string) $req->getBody());
    }

    public function test401MapsToAuthenticationException(): void
    {
        $this->mock->queueJson(401, ['error' => 'unauthorized', 'reason' => 'bad_key']);
        $this->expectException(AuthenticationException::class);
        $this->transport->get('/v1/me');
    }

    public function test404MapsToNotFoundException(): void
    {
        $this->mock->queueJson(404, ['error' => 'not_found']);
        $this->expectException(NotFoundException::class);
        $this->transport->get('/v1/traces/abc');
    }

    public function test400MapsToBadRequestException(): void
    {
        $this->mock->queueJson(400, ['error' => 'bad_request', 'reason' => 'invalid_payload']);
        try {
            $this->transport->post('/v1/events', []);
            $this->fail('expected BadRequestException');
        } catch (BadRequestException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame(['error' => 'bad_request', 'reason' => 'invalid_payload'], $e->body);
        }
    }

    public function test429MapsToRateLimitWithRetryAfter(): void
    {
        $this->mock->queueJson(429, ['error' => 'rate_limited'], ['Retry-After' => '7']);
        try {
            $this->transport->get('/v1/me');
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(7, $e->retryAfter);
        }
    }

    public function test5xxMapsToServerException(): void
    {
        $this->mock->queueJson(500, ['error' => 'internal_error', 'errorId' => 'err-1']);
        try {
            $this->transport->get('/v1/me');
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame('err-1', $e->errorId);
        }
    }

    public function testRetriesOn5xxThenSucceeds(): void
    {
        $factory = new HttpFactory();
        $transport = new Transport(
            new Config(apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 2),
            $this->mock,
            $factory,
            $factory,
        );
        $this->mock->queueJson(503, ['error' => 'unavailable']);
        $this->mock->queueJson(200, ['ok' => true]);

        $result = $transport->get('/v1/me');

        $this->assertSame(['ok' => true], $result);
        $this->assertCount(2, $this->mock->requests);
    }

    public function testNetworkErrorBecomesNetworkException(): void
    {
        $this->mock->queueException(new class () extends \RuntimeException implements ClientExceptionInterface {});
        $this->expectException(NetworkException::class);
        $this->transport->get('/v1/me');
    }
}
