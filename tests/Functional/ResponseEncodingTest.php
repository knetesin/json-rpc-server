<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Responses are encoded once with `json_rpc_server.json.encode_flags`, and a
 * result json_encode() cannot represent becomes a JSON-RPC error envelope
 * instead of a framework error page.
 *
 * @see \Knetesin\JsonRpcServerBundle\Tests\Fixtures\Encoding\InvalidUtf8
 * @see \Knetesin\JsonRpcServerBundle\Tests\Fixtures\Encoding\WholeFloat
 */
final class ResponseEncodingTest extends KernelTestCase
{
    public function testUnencodableSingleResultBecomesInternalErrorEnvelope(): void
    {
        $response = $this->rpc([], '{"jsonrpc":"2.0","method":"test.encoding.invalidUtf8","id":7}');

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(-32603, $payload['error']['code']);
        $this->assertSame(7, $payload['id']);
        $this->assertArrayNotHasKey('result', $payload);
    }

    public function testUnencodableBatchItemDoesNotAffectSiblings(): void
    {
        $response = $this->rpc([], '['
            .'{"jsonrpc":"2.0","method":"test.encoding.invalidUtf8","id":"bad"},'
            .'{"jsonrpc":"2.0","method":"test.encoding.wholeFloat","id":"good"}'
            .']');

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $payload = $this->decodeJsonResponse($response);
        $this->assertCount(2, $payload);
        $byId = array_column($payload, null, 'id');
        $this->assertSame(-32603, $byId['bad']['error']['code']);
        $this->assertArrayNotHasKey('result', $byId['bad']);
        $this->assertEquals(1.0, $byId['good']['result']);
        $this->assertArrayNotHasKey('error', $byId['good']);
    }

    public function testConfiguredEncodeFlagsApplyToResponseBody(): void
    {
        $response = $this->rpc(
            ['json' => ['encode_flags' => \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION]],
            '{"jsonrpc":"2.0","method":"test.encoding.wholeFloat","id":1}',
        );

        $this->assertSame('{"jsonrpc":"2.0","result":1.0,"id":1}', $this->responseContent($response));
    }

    public function testUnencodableMcpResultBecomesMcpErrorEnvelope(): void
    {
        $kernel = $this->boot(['mcp' => ['expose_all' => true]], ['Encoding']);
        $response = $kernel->handle(Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.encoding.invalidUtf8","arguments":{}}',
        ));

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue($payload['isError']);
        $this->assertSame(-32603, $payload['error']['code']);
    }

    /**
     * @param array<string, mixed> $rpcConfig
     */
    private function rpc(array $rpcConfig, string $body): Response
    {
        return $this->boot($rpcConfig, ['Encoding'])->handle(Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        ));
    }
}
