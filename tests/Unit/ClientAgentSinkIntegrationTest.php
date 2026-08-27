<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use Mesh0\Client;
use Mesh0\Config;
use Mesh0\Event\Event;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end wiring check: `Config::$agentSocketPath` must reach the
 * lazy `AgentMetricSink` / `AgentEventSink` constructed by
 * `Client::metrics()` and `Events::agent()` so a regression in the
 * factory plumbing (dropped argument, wrong default) doesn't silently
 * misconfigure the sink.
 */
final class ClientAgentSinkIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $sockPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sockPath = '/tmp/mesh0-agent-client-' . bin2hex(random_bytes(4)) . '.sock';

        $errno = 0;
        $errstr = '';
        $server = @\stream_socket_server('udg://' . $this->sockPath, $errno, $errstr, \STREAM_SERVER_BIND);
        if ($server === false) {
            $this->markTestSkipped("could not bind UDS-DGRAM server: {$errstr}");
        }
        $this->server = $server;
        \stream_set_blocking($server, false);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \fclose($this->server);
        }
        $this->server = null;
        if ($this->sockPath !== '' && \file_exists($this->sockPath)) {
            @\unlink($this->sockPath);
        }
        parent::tearDown();
    }

    public function testClientMetricsSinkUsesConfigSocketPath(): void
    {
        $client = $this->client(socketPath: $this->sockPath);

        $client->metrics()->increment('client.agent.hit');

        $this->assertSame('client.agent.hit:1|c', $this->receiveOnePacket());
    }

    public function testEventsAgentSinkUsesConfigSocketPath(): void
    {
        $client = $this->client(socketPath: $this->sockPath);

        $client->events()->agent()->send(
            Event::now()->withAttribute('span.name', 'client.agent.event'),
        );

        $packet = $this->receiveOnePacket();
        $this->assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, flags: \JSON_THROW_ON_ERROR);
        $attributes = $decoded['attributes'] ?? null;
        $this->assertIsArray($attributes);
        $this->assertSame('client.agent.event', $attributes['span.name'] ?? null);
    }

    public function testPerCallSocketPathOverridesConfig(): void
    {
        // Config points at a bogus path; per-call socketPath should win.
        $client = $this->client(socketPath: '/tmp/mesh0-agent-not-bound-' . bin2hex(random_bytes(4)) . '.sock');

        $client->metrics(socketPath: $this->sockPath)->increment('override');

        $this->assertSame('override:1|c', $this->receiveOnePacket());
    }

    private function client(string $socketPath): Client
    {
        return new Client(
            new Config(
                apiKey: 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
                agentSocketPath: $socketPath,
            ),
            new MockHttpClient(),
        );
    }

    private function receiveOnePacket(int $timeoutMs = 500): ?string
    {
        \assert(\is_resource($this->server));
        $deadline = \microtime(true) + ($timeoutMs / 1000.0);
        while (\microtime(true) < $deadline) {
            $peer = '';
            $data = @\stream_socket_recvfrom($this->server, 65535, 0, $peer);
            if (\is_string($data) && $data !== '') {
                return $data;
            }
            \usleep(5_000);
        }
        return null;
    }

    public function testAgentSinkForwardsAnInstanceTokenAsTheDatagramCredential(): void
    {
        // In open mode the instance token is the ONLY credential a workspace
        // has — the same JWT authenticates ingest and the management API — so
        // a client already configured with one needs nothing further to emit.
        $jwt = 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ4In0.c2ln';
        $client = new Client(new Config(
            apiKey: $jwt,
            agentSocketPath: $this->sockPath,
            agentProject: 'workspace-42',
        ));

        $client->events()->agent()->send(
            Event::now()->withAttributes(['span.name' => 'charge']),
        );

        $packet = $this->receiveOnePacket();
        self::assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('workspace-42', $decoded['_project']);
        self::assertSame($jwt, $decoded['_token']);
    }

    public function testAgentSinkDoesNotForwardAProjectApiKey(): void
    {
        // An m0_ key is what an operator publishes in the agent's KEYS FILE.
        // Sending it inline would bypass the routing table they configured
        // and quietly change which pipeline the events land in.
        $client = new Client(new Config(
            apiKey: 'm0_routing_secret',
            agentSocketPath: $this->sockPath,
            agentProject: 'workspace-42',
        ));

        $client->events()->agent()->send(
            Event::now()->withAttributes(['span.name' => 'charge']),
        );

        $packet = $this->receiveOnePacket();
        self::assertNotNull($packet);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($packet, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('workspace-42', $decoded['_project']);
        self::assertArrayNotHasKey('_token', $decoded);
    }
}
