<?php

declare(strict_types=1);

namespace Mesh0\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Mesh0\Config;
use Mesh0\Http\Transport;
use Mesh0\Resource\User;
use Mesh0\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class UserResourceTest extends TestCase
{
    private MockHttpClient $mock;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHttpClient();
        $factory = new HttpFactory();
        $this->user = new User(new Transport(
            new Config(apiKey: 'm0u_aaaaaaaaaaaaaaaaaaaaaaaa', maxRetries: 0),
            $this->mock,
            $factory,
            $factory,
        ));
    }

    public function testMeReturnsResponseUnwrapped(): void
    {
        $this->mock->queueJson(200, [
            'user' => ['id' => 'u_1', 'email' => 'a@b.c'],
            'apiKey' => ['id' => 'k_1', 'scope' => 'admin'],
        ]);

        $resp = $this->user->me();

        $req = $this->mock->lastRequest();
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/v1/user/me', $req->getUri()->getPath());
        $this->assertSame(['id' => 'u_1', 'email' => 'a@b.c'], $resp['user']);
        $this->assertSame(['id' => 'k_1', 'scope' => 'admin'], $resp['apiKey']);
    }

    public function testListOrgsUnwrapsOrganizations(): void
    {
        $this->mock->queueJson(200, [
            'organizations' => [
                ['id' => 'o_1', 'slug' => 'acme'],
                ['id' => 'o_2', 'slug' => 'globex'],
            ],
        ]);

        $orgs = $this->user->listOrgs();

        $this->assertCount(2, $orgs);
        $this->assertSame('acme', $orgs[0]['slug']);
        $this->assertSame('/v1/user/orgs', $this->mock->lastRequest()->getUri()->getPath());
    }

    public function testListOrgsDefaultsToEmptyArrayOnMissingKey(): void
    {
        $this->mock->queueJson(200, []);

        $this->assertSame([], $this->user->listOrgs());
    }

    public function testCreateOrgPostsNameAndSlug(): void
    {
        $this->mock->queueJson(201, [
            'organization' => ['id' => 'o_new', 'slug' => 'acme', 'name' => 'Acme'],
        ]);

        $org = $this->user->createOrg('Acme', 'acme');

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/user/orgs', $req->getUri()->getPath());
        $this->assertSame(['name' => 'Acme', 'slug' => 'acme'], $this->mock->lastJsonBody());
        $this->assertSame('o_new', $org['id']);
    }

    public function testCreateOrgOmitsSlugWhenNull(): void
    {
        $this->mock->queueJson(201, ['organization' => ['id' => 'o_x']]);

        $this->user->createOrg('Acme');

        $this->assertSame(['name' => 'Acme'], $this->mock->lastJsonBody());
    }

    public function testGetOrgReturnsFullPayloadIncludingRole(): void
    {
        $this->mock->queueJson(200, [
            'organization' => ['id' => 'o_1', 'slug' => 'acme'],
            'role' => 'owner',
        ]);

        $resp = $this->user->getOrg('acme');

        $this->assertSame('/v1/user/orgs/acme', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame('owner', $resp['role']);
        $this->assertSame(['id' => 'o_1', 'slug' => 'acme'], $resp['organization']);
    }

    public function testGetOrgEscapesSlug(): void
    {
        $this->mock->queueJson(200, []);
        $this->user->getOrg('weird slug');
        $this->assertSame('/v1/user/orgs/weird%20slug', $this->mock->lastRequest()->getUri()->getPath());
    }

    public function testUpdateOrgPatchesName(): void
    {
        $this->mock->queueJson(200, [
            'organization' => ['id' => 'o_1', 'slug' => 'acme', 'name' => 'Acme Inc.'],
        ]);

        $org = $this->user->updateOrg('acme', 'Acme Inc.');

        $req = $this->mock->lastRequest();
        $this->assertSame('PATCH', $req->getMethod());
        $this->assertSame('/v1/user/orgs/acme', $req->getUri()->getPath());
        $this->assertSame(['name' => 'Acme Inc.'], $this->mock->lastJsonBody());
        $this->assertSame('Acme Inc.', $org['name']);
    }

    public function testDeleteOrgIssuesDelete(): void
    {
        $this->mock->queueJson(200, ['ok' => true, 'deletionGraceDays' => 30]);

        $resp = $this->user->deleteOrg('acme');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/user/orgs/acme', $req->getUri()->getPath());
        $this->assertTrue($resp['ok']);
        $this->assertSame(30, $resp['deletionGraceDays']);
    }

    public function testListProjectsUnwrapsProjects(): void
    {
        $this->mock->queueJson(200, [
            'projects' => [
                ['id' => 'p_1', 'name' => 'main'],
            ],
        ]);

        $projects = $this->user->listProjects('acme');

        $this->assertSame('/v1/user/orgs/acme/projects', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertCount(1, $projects);
        $this->assertSame('main', $projects[0]['name']);
    }

    public function testCreateProjectSendsName(): void
    {
        $this->mock->queueJson(201, ['project' => ['id' => 'p_1', 'name' => 'main']]);

        $resp = $this->user->createProject('acme', 'main');

        $this->assertSame('POST', $this->mock->lastRequest()->getMethod());
        $this->assertSame(['name' => 'main'], $this->mock->lastJsonBody());
        $this->assertSame(['id' => 'p_1', 'name' => 'main'], $resp['project']);
    }

    public function testCreateProjectIncludesOptionalFields(): void
    {
        $this->mock->queueJson(201, ['project' => ['id' => 'p_2']]);

        $this->user->createProject('acme', 'analytics', 14, [
            'aliases' => [['name' => 'user_id', 'jsonPath' => '$.uid', 'fieldType' => 'string']],
            'promote' => ['user_id'],
        ]);

        $this->assertSame([
            'name' => 'analytics',
            'retentionDays' => 14,
            'schema' => [
                'aliases' => [['name' => 'user_id', 'jsonPath' => '$.uid', 'fieldType' => 'string']],
                'promote' => ['user_id'],
            ],
        ], $this->mock->lastJsonBody());
    }

    public function testGetProjectReturnsFullPayload(): void
    {
        $this->mock->queueJson(200, [
            'project' => ['id' => 'p_1', 'name' => 'main'],
            'role' => 'admin',
        ]);

        $resp = $this->user->getProject('acme', 'p_1');

        $this->assertSame('/v1/user/orgs/acme/projects/p_1', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertSame('admin', $resp['role']);
    }

    public function testUpdateProjectPatchesProvidedFields(): void
    {
        $this->mock->queueJson(200, ['project' => ['id' => 'p_1', 'name' => 'renamed', 'retentionDays' => 30]]);

        $project = $this->user->updateProject('acme', 'p_1', name: 'renamed', retentionDays: 30);

        $req = $this->mock->lastRequest();
        $this->assertSame('PATCH', $req->getMethod());
        $this->assertSame('/v1/user/orgs/acme/projects/p_1', $req->getUri()->getPath());
        $this->assertSame(['name' => 'renamed', 'retentionDays' => 30], $this->mock->lastJsonBody());
        $this->assertSame('renamed', $project['name']);
    }

    public function testUpdateProjectOmitsUnsetFields(): void
    {
        $this->mock->queueJson(200, ['project' => ['id' => 'p_1']]);

        $this->user->updateProject('acme', 'p_1', retentionDays: 7);

        $this->assertSame(['retentionDays' => 7], $this->mock->lastJsonBody());
    }

    public function testDeleteProjectIssuesDelete(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->user->deleteProject('acme', 'p_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/user/orgs/acme/projects/p_1', $req->getUri()->getPath());
    }

    public function testListProjectKeysUnwrapsKeys(): void
    {
        $this->mock->queueJson(200, [
            'keys' => [
                ['id' => 'k_1', 'keyPrefix' => 'm0_abcde', 'scope' => 'read_write'],
            ],
        ]);

        $keys = $this->user->listProjectKeys('acme', 'p_1');

        $this->assertSame('/v1/user/orgs/acme/projects/p_1/keys', $this->mock->lastRequest()->getUri()->getPath());
        $this->assertCount(1, $keys);
        $this->assertSame('read_write', $keys[0]['scope']);
    }

    public function testCreateProjectKeyReturnsKeyAndToken(): void
    {
        $this->mock->queueJson(201, [
            'key' => ['id' => 'k_new', 'keyPrefix' => 'm0_abcde', 'scope' => 'read'],
            'token' => 'm0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        $resp = $this->user->createProjectKey('acme', 'p_1', name: 'ci', expiresAt: '2027-01-01T00:00:00Z', scope: 'read');

        $req = $this->mock->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/user/orgs/acme/projects/p_1/keys', $req->getUri()->getPath());
        $this->assertSame([
            'name' => 'ci',
            'expiresAt' => '2027-01-01T00:00:00Z',
            'scope' => 'read',
        ], $this->mock->lastJsonBody());
        $this->assertSame('m0_abcde_aaaaaaaaaaaaaaaaaaaaaaaa', $resp['token']);
        $this->assertSame(
            ['id' => 'k_new', 'keyPrefix' => 'm0_abcde', 'scope' => 'read'],
            $resp['key'],
        );
    }

    public function testCreateProjectKeyAllowsEmptyBody(): void
    {
        $this->mock->queueJson(201, ['key' => ['id' => 'k_1'], 'token' => 'm0_x']);

        $this->user->createProjectKey('acme', 'p_1');

        $this->assertSame([], $this->mock->lastJsonBody());
    }

    public function testRevokeProjectKeyIssuesDelete(): void
    {
        $this->mock->queueJson(200, ['ok' => true]);

        $this->user->revokeProjectKey('acme', 'p_1', 'k_1');

        $req = $this->mock->lastRequest();
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/v1/user/orgs/acme/projects/p_1/keys/k_1', $req->getUri()->getPath());
    }

    public function testUserKeyPassesThroughConfigValidation(): void
    {
        // The user key shape `m0u_…` previously failed Config validation
        // (only `m0_` was allowed). Reaching this point at all means the
        // setUp Config(...) call accepted it; assert explicitly to keep
        // the contract pinned.
        $config = new Config(apiKey: 'm0u_aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertSame('m0u_aaaaaaaaaaaaaaaaaaaaaaaa', $config->apiKey);
    }
}
