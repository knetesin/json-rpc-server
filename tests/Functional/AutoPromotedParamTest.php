<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

/**
 * A bare scalar __invoke parameter (no #[Rpc\Param], no Context/RpcRequest/etc)
 * is auto-promoted: it appears in inputSchema and resolves by its PHP name —
 * keeping schema and resolver in sync without ceremony.
 */
final class AutoPromotedParamTest extends KernelTestCase
{
    public function testBareScalarResolvesAtRuntime(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.autoPromoted","params":{"autoId":7,"note":"hello"},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(['autoId' => 7, 'note' => 'hello'], $payload['result']);
    }

    public function testBareScalarOptionalUsesDefault(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.autoPromoted","params":{"autoId":7},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(['autoId' => 7, 'note' => null], $payload['result']);
    }

    public function testBareScalarValidatorConstraintFires(): void
    {
        // #[Assert\Positive] on the bare int $autoId must still apply.
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.autoPromoted","params":{"autoId":-1},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(-32602, $payload['error']['code']);
    }

    public function testBareScalarsAppearInMcpInputSchema(): void
    {
        $kernel = $this->boot();
        $request = Request::create('/mcp/tools', 'GET');
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $tool = null;
        foreach ($payload['tools'] as $t) {
            if ('test.autoPromoted' === $t['name']) {
                $tool = $t;
                break;
            }
        }
        $this->assertNotNull($tool, 'test.autoPromoted not exposed via MCP');

        $this->assertSame('object', $tool['inputSchema']['type']);
        $props = (array) $tool['inputSchema']['properties'];
        $this->assertArrayHasKey('autoId', $props);
        $this->assertSame('integer', $props['autoId']['type']);
        $this->assertSame(0, $props['autoId']['exclusiveMinimum']);

        $this->assertArrayHasKey('note', $props);
        // Nullable: PHP type "?string" → schema type ["string","null"].
        $this->assertSame(['string', 'null'], $props['note']['type']);

        // autoId is required (no default, not nullable). note has a default → optional.
        $this->assertSame(['autoId'], $tool['inputSchema']['required']);
    }
}
