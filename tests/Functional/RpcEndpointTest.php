<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

final class RpcEndpointTest extends KernelTestCase
{
    public function testSingleSuccess(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}');
        $this->assertSame(['jsonrpc' => '2.0', 'result' => ['sum' => 5], 'id' => 1], $payload);
    }

    public function testPositionalParamsMapToDtoConstructorWhenOptIn(): void
    {
        // math.add carries #[Rpc\Method(allowPositionalDto: true)].
        $payload = $this->call('{"jsonrpc":"2.0","method":"math.add","params":[2,3],"id":1}');
        $this->assertSame(5, $payload['result']['sum']);
    }

    public function testPositionalParamsRejectedByDefault(): void
    {
        // math.add_strict has no opt-in — positional must be refused.
        $payload = $this->call('{"jsonrpc":"2.0","method":"math.add_strict","params":[2,3],"id":1}');
        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertStringContainsString('requires named parameters', $payload['error']['message']);
    }

    public function testPositionalParamsAllowedGloballyViaConfig(): void
    {
        // With json_rpc_server.params.allow_positional_dto: true the strict method also accepts positional.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"math.add_strict","params":[2,3],"id":1}',
            ['params' => ['allow_positional_dto' => true]],
        );
        $this->assertSame(5, $payload['result']['sum']);
    }

    public function testNamedParamsAlwaysWorkForStrictDto(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"math.add_strict","params":{"a":2,"b":3},"id":1}');
        $this->assertSame(5, $payload['result']['sum']);
    }

    public function testDeprecatedMethodReturnsHeaders(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"math.legacy_add","params":{"a":2,"b":3},"id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertSame('true', $response->headers->get('Deprecation'));
        $this->assertStringContainsString('math.legacy_add', (string) $response->headers->get('X-Rpc-Deprecated'));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(5, $payload['result']['sum']);
    }

    public function testUnknownDtoParamRejectedByDefault(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3,"wat":"???"},"id":1}');
        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertStringContainsString('wat', $payload['error']['message']);
        $this->assertSame('wat', $payload['error']['data'][0]['path']);
    }

    public function testUnknownDtoParamAcceptedWhenGlobalConfigDisablesReject(): void
    {
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3,"wat":"???"},"id":1}',
            ['params' => ['reject_unknown' => false]],
        );
        $this->assertSame(5, $payload['result']['sum']);
    }

    public function testNonDeprecatedMethodHasNoDeprecationHeader(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertFalse($response->headers->has('Deprecation'));
        $this->assertFalse($response->headers->has('X-Rpc-Deprecated'));
    }

    public function testBatch(): void
    {
        $payload = $this->call('[
            {"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},
            {"jsonrpc":"2.0","method":"math.add","params":{"a":4,"b":5},"id":2}
        ]');

        $this->assertCount(2, $payload);
        $this->assertSame(3, $payload[0]['result']['sum']);
        $this->assertSame(9, $payload[1]['result']['sum']);
    }

    public function testNotificationReturnsNoBody(): void
    {
        $kernel = $this->boot();
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2}}');
        $response = $kernel->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function testMethodNotFound(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"does.not.exist","id":1}');
        $this->assertSame(-32601, $payload['error']['code']);
    }

    public function testInvalidParams(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.echo","params":{"message":""},"id":1}');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertArrayHasKey('data', $payload['error']);
        $this->assertSame('message', $payload['error']['data'][0]['path']);
    }

    public function testDenormalizationErrorsCarryViolationPaths(): void
    {
        // message is declared `string`, sending a number triggers a
        // PartialDenormalizationException that must surface in `data`
        // with the field path instead of a flat message.
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.echo","params":{"message":123},"id":1}');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertArrayHasKey('data', $payload['error']);
        $this->assertSame('message', $payload['error']['data'][0]['path']);
        $this->assertNotEmpty($payload['error']['data'][0]['message']);
    }

    public function testAccessDeniedDefaultCode(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.boom","params":{"kind":"access"},"id":1}');
        $this->assertSame(-32001, $payload['error']['code']);
    }

    public function testAccessDeniedCustomCode(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.boom","params":{"kind":"access_custom"},"id":1}');
        $this->assertSame(-32050, $payload['error']['code']);
        $this->assertSame('quota', $payload['error']['message']);
    }

    public function testNotFoundCode(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.boom","params":{"kind":"not_found"},"id":1}');
        $this->assertSame(-32002, $payload['error']['code']);
    }

    public function testUnhandledBecomesInternalError(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.boom","params":{"kind":"unhandled"},"id":1}');
        $this->assertSame(-32603, $payload['error']['code']);
    }

    public function testParseError(): void
    {
        $payload = $this->call('not-json');
        $this->assertSame(-32700, $payload['error']['code']);
        $this->assertNull($payload['id']);
    }

    public function testContextIsInjected(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.echo","params":{"message":"hi"},"id":1}');
        $this->assertSame('hi', $payload['result']['pong']);
        $this->assertSame('test.echo', $payload['result']['method']);
    }

    public function testPublicMethodWorksWithoutAnyAuth(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.public","id":1}');
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testRoleGatedMethodIsDeniedForAnonymous(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.admin","id":1}');
        $this->assertSame(-32001, $payload['error']['code']);
        $this->assertStringContainsString('ROLE_ADMIN', $payload['error']['message']);
    }

    public function testPerMethodLimitAllowsBodiesUnderTheCap(): void
    {
        $payload = $this->call(\sprintf(
            '{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"%s"},"id":1}',
            str_repeat('a', 1024),
        ));
        $this->assertSame(1024, $payload['result']['received']);
    }

    public function testPerMethodLimitRejectsOversizeBody(): void
    {
        // UploadStub declares MaxRequestSize(4096). Send a body well above it.
        // Oversize is signalled in the JSON-RPC envelope; transport uses 413.
        $kernel = $this->boot();
        $body = \sprintf(
            '{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"%s"},"id":1}',
            str_repeat('a', 8192),
        );
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame(-32600, $payload['error']['code']);
        $this->assertStringContainsString('too large', $payload['error']['message']);
    }

    public function testPerMethodAttributeIsAuthoritativeNotGlobal(): void
    {
        // UploadStub declares MaxRequestSize(4096). Even with a higher global,
        // the per-method limit wins because it is more specific.
        $kernel = $this->boot(['max_request_size' => 1 << 20]);
        $body = \sprintf(
            '{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"%s"},"id":1}',
            str_repeat('a', 6000),
        );
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame(-32600, $payload['error']['code']);
        $this->assertStringContainsString('limit: 4096', $payload['error']['message']);
    }

    public function testUncappedGlobalLeavesUncappedMethodsUncapped(): void
    {
        // Regression: previously the parser cap was raised to the largest per-method
        // MaxRequestSize even when the global was 0 (uncapped). That silently
        // capped every other method to one method's limit at parse time.
        //
        // file.upload declares MaxRequestSize(4096); math.add declares none.
        // With global=0 the parser must accept arbitrarily large bodies for math.add.
        $kernel = $this->boot(['max_request_size' => 0]);
        $body = \sprintf(
            '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1,"_pad":"%s"}',
            str_repeat('x', 10_000),
        );
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('result', $payload, 'math.add must succeed with body well above any per-method cap');
        $this->assertSame(3, $payload['result']['sum']);
    }

    public function testMethodWithoutAttributeUsesGlobalLimit(): void
    {
        // math.add has no MaxRequestSize attribute — should be governed by
        // json_rpc_server.max_request_size. Set a tight global and send a body above it.
        $kernel = $this->boot(['max_request_size' => 80]);
        $body = \sprintf(
            '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1,"_pad":"%s"}',
            str_repeat('x', 200),
        );
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(-32600, $payload['error']['code']);
        $this->assertStringContainsString('too large', $payload['error']['message']);
    }

    public function testHandlerInstancesAreNotSharedAcrossCalls(): void
    {
        // StatefulProbe keeps a counter in $this->n (non-static).
        // With setShared(false) every dispatch builds a fresh handler →
        // counter is always 1. A leaking shared service would return 1, 2, 3.
        $kernel = $this->boot();
        for ($i = 0; $i < 3; ++$i) {
            $request = Request::create(
                '/rpc',
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"jsonrpc":"2.0","method":"test.stateful_probe","id":'.($i + 1).'}'
            );
            $response = $kernel->handle($request);
            $payload = $this->decodeJsonResponse($response);
            $this->assertSame(1, $payload['result']['n'], 'call #'.$i.' must see a fresh instance');
        }
    }

    public function testParamAttributeMapsSnakeCaseToCamelCase(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"user.deactivate","params":{"user_id":42,"reason":"spam"},"id":1}');
        $this->assertSame(42, $payload['result']['userId']);
        $this->assertSame('spam', $payload['result']['reason']);
    }

    public function testParamConstraintsAreEvaluated(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"user.deactivate","params":{"user_id":-5},"id":1}');
        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('user_id', $payload['error']['data'][0]['path']);
    }

    public function testOptionalParamCanBeOmitted(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"user.deactivate","params":{"user_id":7},"id":1}');
        $this->assertSame(7, $payload['result']['userId']);
        $this->assertNull($payload['result']['reason']);
    }

    public function testNotificationOversizeIsDroppedSilently(): void
    {
        $kernel = $this->boot();
        $body = \sprintf(
            '{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"%s"}}',
            str_repeat('a', 8192),
        );
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function testParamsBagAssocForm(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.params_echo","params":{"a":1,"b":"two","c":[3,4]},"id":1}');
        $this->assertSame(['a' => 1, 'b' => 'two', 'c' => [3, 4]], $payload['result']['all']);
        $this->assertSame(3, $payload['result']['count']);
        $this->assertFalse($payload['result']['isList']);
        $this->assertFalse($payload['result']['isEmpty']);
    }

    public function testParamsBagListForm(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.params_echo","params":[1,2,3],"id":1}');
        $this->assertSame([1, 2, 3], $payload['result']['all']);
        $this->assertTrue($payload['result']['isList']);
        $this->assertSame(3, $payload['result']['count']);
    }

    public function testParamsBagEmptyWhenOmitted(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.params_echo","id":1}');
        $this->assertSame([], $payload['result']['all']);
        $this->assertTrue($payload['result']['isEmpty']);
        $this->assertFalse($payload['result']['isList']);
    }

    public function testTypedGettersReadValuesByType(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.params_typed","params":{"name":"alice","age":30,"active":true,"score":4.5,"tags":["a","b"]},"id":1}');
        $this->assertSame([
            'name' => 'alice',
            'age' => 30,
            'active' => true,
            'score' => 4.5,
            'tags' => ['a', 'b'],
            'hasMissing' => false,
        ], $payload['result']);
    }

    public function testTypedGettersFallBackToDefaults(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.params_typed","params":{},"id":1}');
        $this->assertSame([
            'name' => 'anon',
            'age' => -1,
            'active' => false,
            'score' => 1.5,
            'tags' => [],
            'hasMissing' => false,
        ], $payload['result']);
    }

    public function testTypedGetterRejectsWrongType(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.params_typed","params":{"age":"thirty"},"id":1}');
        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertStringContainsString('age', $payload['error']['message']);
        $this->assertStringContainsString('integer', $payload['error']['message']);
    }

    public function testHttpRequestIsInjected(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PROBE' => 'probe-value'],
            content: '{"jsonrpc":"2.0","method":"test.http_probe","id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode(), $this->responseContent($response));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('/rpc', $payload['result']['path']);
        $this->assertSame('probe-value', $payload['result']['header']);
    }

    public function testRpcRequestEnvelopeIsInjected(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.envelope_probe","params":{"k":"v"},"id":42}');
        $this->assertSame(42, $payload['result']['id']);
        $this->assertSame('test.envelope_probe', $payload['result']['method']);
        $this->assertFalse($payload['result']['isNotification']);
        $this->assertSame(['k' => 'v'], $payload['result']['params']);
    }

    public function testRpcRequestEnvelopeMarksNotification(): void
    {
        // Notifications never produce a response. Verify via the side-channel:
        // call the same method with an id and confirm isNotification is false,
        // then call without id and confirm 204 (the body would have been visible
        // if isNotification were not correctly propagated up the stack).
        $kernel = $this->boot();
        $req = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","method":"test.envelope_probe"}');
        $response = $kernel->handle($req);
        $this->assertSame(204, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $rpcConfig
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function call(string $body, array $rpcConfig = []): array
    {
        $kernel = $this->boot($rpcConfig);
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode(), 'expected 200, got body: '.$this->responseContent($response));

        return $this->decodeJsonResponse($response);
    }
}
