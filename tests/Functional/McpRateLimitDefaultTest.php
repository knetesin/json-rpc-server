<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

final class McpRateLimitDefaultTest extends KernelTestCase
{
    public function testRateLimitIsEnforcedOnMcpCallByDefault(): void
    {
        $kernel = $this->boot();

        $first = $this->mcpCall($kernel);
        $second = $this->mcpCall($kernel);

        // test.mcp_throttled allows 1 call per 60 s globally.
        $this->assertArrayNotHasKey('isError', $first);
        $this->assertTrue($second['isError'] ?? false);
        $this->assertSame(-32003, $second['error']['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpCall(TestKernel $kernel): array
    {
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.mcp_throttled","arguments":{}}',
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->responseContent($kernel->handle($request)), true, 32, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
