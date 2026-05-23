<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

final class TeamCreateTest extends KernelTestCase
{
    public function testResolvesArrayOfDtoFromFlatParams(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.teamCreate","params":{"members":[{"name":"alice"},{"name":"bob"}]},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(['count' => 2, 'names' => ['alice', 'bob']], $payload['result']);
    }

    public function testMcpSchemaAdvertisesMemberItems(): void
    {
        $kernel = $this->boot();
        $response = $kernel->handle(Request::create('/mcp/tools', 'GET'));
        $payload = $this->decodeJsonResponse($response);

        $tool = null;
        foreach ($payload['tools'] as $t) {
            if ('test.teamCreate' === $t['name']) {
                $tool = $t;
                break;
            }
        }
        $this->assertNotNull($tool, 'test.teamCreate not exposed via MCP');

        $members = $tool['inputSchema']['properties']['members'];
        $this->assertSame('array', $members['type']);
        $this->assertSame('object', $members['items']['type']);
        $this->assertArrayHasKey('name', (array) $members['items']['properties']);
    }

    public function testOpenRpcListsMembersWithNestedSchema(): void
    {
        $kernel = $this->boot();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('debug:rpc'));
        $tester->execute(['--openrpc' => true, '--title' => 't', '--api-version' => '1']);
        $tester->assertCommandIsSuccessful();

        $doc = json_decode($tester->getDisplay(), true, 32, \JSON_THROW_ON_ERROR);
        $methods = array_column($doc['methods'], null, 'name');
        $this->assertArrayHasKey('test.teamCreate', $methods);

        $params = array_column($methods['test.teamCreate']['params'], null, 'name');
        $this->assertArrayHasKey('members', $params);
        $this->assertSame('array', $params['members']['schema']['type']);
        $this->assertSame('object', $params['members']['schema']['items']['type']);
    }
}
