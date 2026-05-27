<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

final class McpEndpointTest extends KernelTestCase
{
    public function testToolsContainsOnlyAttributeMarkedMethodsByDefault(): void
    {
        $tools = $this->getTools();
        $names = array_column($tools, 'name');

        $this->assertContains('test.echo', $names);
        $this->assertNotContains('math.add', $names);
        $this->assertNotContains('auth.getSession', $names);
    }

    public function testExposeAllListsEverything(): void
    {
        $tools = $this->getTools(['mcp' => ['expose_all' => true]]);
        $names = array_column($tools, 'name');

        $this->assertContains('math.add', $names);
        $this->assertContains('auth.getSession', $names);
        $this->assertContains('auth.logout', $names);
    }

    public function testExcludePrefixesHide(): void
    {
        $tools = $this->getTools([
            'mcp' => [
                'expose_all' => true,
                'exclude_prefixes' => ['auth.'],
            ],
        ]);
        $names = array_column($tools, 'name');

        $this->assertNotContains('auth.getSession', $names);
        $this->assertNotContains('auth.logout', $names);
        $this->assertContains('math.add', $names);
    }

    public function testWhitelistOverridesExclude(): void
    {
        $tools = $this->getTools([
            'mcp' => [
                'expose_all' => true,
                'exclude_prefixes' => ['auth.'],
                'whitelist_methods' => ['auth.getSession'],
            ],
        ]);
        $names = array_column($tools, 'name');

        $this->assertContains('auth.getSession', $names);
        $this->assertNotContains('auth.logout', $names);
    }

    public function testExcludeMethodsHidesByExactName(): void
    {
        $tools = $this->getTools([
            'mcp' => [
                'expose_all' => true,
                'exclude_methods' => ['auth.logout'],
            ],
        ]);
        $names = array_column($tools, 'name');

        $this->assertNotContains('auth.logout', $names);
        $this->assertContains('auth.getSession', $names);
    }

    public function testAttributeEnabledFalseHidesEvenInExposeAllMode(): void
    {
        $tools = $this->getTools(['mcp' => ['expose_all' => true]]);
        $names = array_column($tools, 'name');

        $this->assertNotContains('test.internalReport', $names);
    }

    public function testInputSchemaGeneratedFromParamAttributes(): void
    {
        // user.deactivate has no DTO — schema must come from #[Rpc\Param] params.
        $tools = $this->getTools(['mcp' => ['expose_all' => true]]);
        $tool = $this->findToolByName($tools, 'user.deactivate');

        $this->assertSame('object', $tool['inputSchema']['type']);
        $properties = (array) $tool['inputSchema']['properties'];
        $this->assertArrayHasKey('user_id', $properties);
        $this->assertSame('integer', $properties['user_id']['type']);
        $this->assertSame(0, $properties['user_id']['exclusiveMinimum']);

        $this->assertArrayHasKey('reason', $properties);
        $this->assertSame(64, $properties['reason']['maxLength']);

        $this->assertSame(['user_id'], $tool['inputSchema']['required']);
    }

    public function testInputSchemaGeneratedFromDto(): void
    {
        $tools = $this->getTools();
        $echo = $this->findToolByName($tools, 'test.echo');

        $this->assertSame('object', $echo['inputSchema']['type']);
        $this->assertArrayHasKey('properties', $echo['inputSchema']);
        $this->assertSame('string', $echo['inputSchema']['properties']['message']['type']);
        $this->assertSame(32, $echo['inputSchema']['properties']['message']['maxLength']);
    }

    public function testOutputSchemaOmittedWhenReturnTypeIsLoose(): void
    {
        // test.echo returns `array` — no informative schema can be derived
        // and no override is set, so MCP `tools/list` omits the field entirely.
        $tools = $this->getTools();
        $echo = $this->findToolByName($tools, 'test.echo');

        $this->assertArrayNotHasKey('outputSchema', $echo);
    }

    public function testOutputSchemaDerivedFromReturnDto(): void
    {
        $tools = $this->getTools();
        $tool = $this->findToolByName($tools, 'test.echoTyped');

        $this->assertArrayHasKey('outputSchema', $tool);
        $this->assertSame('object', $tool['outputSchema']['type']);
        $properties = (array) $tool['outputSchema']['properties'];
        $this->assertSame('string', $properties['pong']['type']);
        $this->assertSame('integer', $properties['length']['type']);
        $this->assertEqualsCanonicalizing(['pong', 'length'], $tool['outputSchema']['required']);
    }

    public function testOutputSchemaOverrideArrayWinsOverReturnType(): void
    {
        $tools = $this->getTools();
        $tool = $this->findToolByName($tools, 'test.echoOverride');

        $this->assertArrayHasKey('outputSchema', $tool);
        $this->assertSame('object', $tool['outputSchema']['type']);
        $properties = (array) $tool['outputSchema']['properties'];
        $this->assertSame('string', $properties['pong']['type']);
        $this->assertSame('integer', $properties['length']['type']);
        $this->assertSame(['pong', 'length'], $tool['outputSchema']['required']);
    }

    public function testOutputSchemaOverrideClassResolvesViaSchemaBuilder(): void
    {
        $tools = $this->getTools();
        $tool = $this->findToolByName($tools, 'test.echoOverrideClass');

        $this->assertArrayHasKey('outputSchema', $tool);
        $this->assertSame('object', $tool['outputSchema']['type']);
        $properties = (array) $tool['outputSchema']['properties'];
        $this->assertSame('string', $properties['pong']['type']);
        $this->assertSame('integer', $properties['length']['type']);
    }

    public function testAnnotationsOmittedWhenNothingDeclared(): void
    {
        // test.echo carries plain `#[Rpc\Mcp]` with no hints and no cache —
        // tool entry must not emit an `annotations` key at all.
        $tools = $this->getTools();
        $echo = $this->findToolByName($tools, 'test.echo');

        $this->assertArrayNotHasKey('annotations', $echo);
    }

    public function testAnnotationsPassthroughForExplicitHints(): void
    {
        $tools = $this->getTools();
        $tool = $this->findToolByName($tools, 'user.deleteAnnotated');

        $this->assertSame([
            'title' => 'Delete user',
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ], $tool['annotations']);
    }

    public function testAnnotationsAutoDerivedFromCacheAttribute(): void
    {
        $tools = $this->getTools();
        $tool = $this->findToolByName($tools, 'catalog.cachedListing');

        // Cache present, neither hint set explicitly → both must be true.
        $this->assertSame([
            'readOnlyHint' => true,
            'idempotentHint' => true,
        ], $tool['annotations']);
    }

    public function testExplicitHintBeatsCacheAutoDerive(): void
    {
        $tools = $this->getTools();
        $tool = $this->findToolByName($tools, 'catalog.cachedButDestructive');

        // Cache would default both to true, but explicit `false` on the
        // attribute must win.
        $this->assertFalse($tool['annotations']['readOnlyHint']);
        $this->assertFalse($tool['annotations']['idempotentHint']);
    }

    public function testAnnotationsAutoDerivedWithoutMcpAttribute(): void
    {
        // cache.counter_global has #[Rpc\Cache] but no #[Rpc\Mcp]. With
        // expose_all it surfaces in MCP, and Cache-derived hints must still
        // apply.
        $tools = $this->getTools(['mcp' => ['expose_all' => true]]);
        $tool = $this->findToolByName($tools, 'cache.counter_global');

        $this->assertSame([
            'readOnlyHint' => true,
            'idempotentHint' => true,
        ], $tool['annotations']);
    }

    public function testNoParamMethodEmitsEmptyPropertiesAsObject(): void
    {
        // Per JSON Schema spec `properties` is an object — empty must serialize as `{}`, not `[]`.
        // json_decode(assoc:true) loses the distinction, so assert against the raw response body.
        $kernel = $this->boot(['mcp' => ['expose_all' => true]]);
        $response = $kernel->handle(Request::create('/mcp/tools', 'GET'));
        $body = $this->responseContent($response);

        $bareToolJson = $this->extractToolJson($body, 'secured.bare');
        $this->assertStringContainsString('"properties":{}', $bareToolJson);
        $this->assertStringNotContainsString('"properties":[]', $bareToolJson);
    }

    private function extractToolJson(string $body, string $name): string
    {
        $needle = '"name":"'.$name.'"';
        $pos = strpos($body, $needle);
        $this->assertNotFalse($pos, "Tool $name not found in response body");
        // The tool object starts at the nearest `{` before $pos and ends at its matching `}`.
        $start = strrpos(substr($body, 0, $pos), '{');
        $this->assertNotFalse($start);
        $depth = 0;
        $len = \strlen($body);
        for ($i = $start; $i < $len; ++$i) {
            $depth += ('{' === $body[$i] ? 1 : 0) - ('}' === $body[$i] ? 1 : 0);
            if (0 === $depth) {
                return substr($body, $start, $i - $start + 1);
            }
        }
        $this->fail("Could not find matching brace for tool $name");
    }

    public function testCallReturnsMcpContentAndStructuredFormat(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.echo","arguments":{"message":"hi"}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        // text contains compact JSON (default format).
        $this->assertSame('text', $payload['content'][0]['type']);
        $inner = json_decode($payload['content'][0]['text'], true, 32, \JSON_THROW_ON_ERROR);
        $this->assertSame('hi', $inner['pong']);

        // structuredContent ships the raw object alongside.
        $this->assertSame('hi', $payload['structuredContent']['pong']);
    }

    public function testCallListResultRendersAsMarkdownTable(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"user.list","arguments":{}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertStringContainsString('| id | name |', $payload['content'][0]['text']);
        $this->assertStringContainsString('| 1 | Alice |', $payload['content'][0]['text']);
    }

    public function testFormatHeaderOverridesMethodDefault(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MCP_FORMAT' => 'pretty_json'],
            content: '{"name":"test.echo","arguments":{"message":"hi"}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        // pretty JSON has newlines, default Json wouldn't.
        $this->assertStringContainsString("\n", $payload['content'][0]['text']);
    }

    public function testFormatQueryParamOverridesMethodDefault(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call?format=markdown',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"user.list","arguments":{}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertStringContainsString('| id | name |', $payload['content'][0]['text']);
    }

    public function testFormatHeaderWinsOverQueryParam(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call?format=markdown',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MCP_FORMAT' => 'json'],
            content: '{"name":"user.list","arguments":{}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        // header chose json → no markdown table.
        $this->assertStringNotContainsString('| --- |', $payload['content'][0]['text']);
        $this->assertSame('[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]', $payload['content'][0]['text']);
    }

    public function testToonFormatViaQuery(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call?format=toon',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"user.list","arguments":{}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame("[2]{id,name}:\n  1,Alice\n  2,Bob", $payload['content'][0]['text']);
    }

    public function testToonFormatIsShorterThanPrettyJsonOnListPayload(): void
    {
        $kernel = $this->boot();
        $callWith = function (string $format) use ($kernel): string {
            $request = Request::create(
                '/mcp/call?format='.$format,
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"name":"user.list","arguments":{}}',
            );
            $payload = $this->decodeJsonResponse($kernel->handle($request));

            return $payload['content'][0]['text'];
        };

        $this->assertLessThan(\strlen($callWith('pretty_json')), \strlen($callWith('toon')));
    }

    public function testDefaultFormatConfigChangesFallback(): void
    {
        // test.echo carries #[Rpc\Mcp(...)] without an explicit format, so the
        // bundle-level default is used. Switch it to toon and verify.
        $kernel = $this->boot(['mcp' => ['default_format' => 'toon']]);
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.echo","arguments":{"message":"hi"}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        // TOON output for {"pong":"hi","method":"test.echo"}
        $this->assertSame("pong: hi\nmethod: test.echo", $payload['content'][0]['text']);
    }

    public function testPerMethodAttributeStillBeatsBundleDefault(): void
    {
        // user.list explicitly asks for Markdown; bundle default of toon must
        // not override the per-method attribute.
        $kernel = $this->boot(['mcp' => ['default_format' => 'toon']]);
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"user.list","arguments":{}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertStringContainsString('| id | name |', $payload['content'][0]['text']);
    }

    public function testInvalidDefaultFormatRejectedAtConfigTime(): void
    {
        $this->expectException(\Exception::class);
        $this->boot(['mcp' => ['default_format' => 'yaml']]);
    }

    public function testInvalidFormatReturns400(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call?format=yaml',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.echo","arguments":{"message":"hi"}}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertStringContainsString('Unknown MCP format', $payload['error']['message']);
    }

    public function testMcpResultTransformerStripsFields(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.secretBox","arguments":{}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        // text (LLM-visible) has no secret
        $this->assertStringNotContainsString('secret', $payload['content'][0]['text']);
        $this->assertStringContainsString('box', $payload['content'][0]['text']);

        // structuredContent also lacks it
        $this->assertArrayNotHasKey('secret', $payload['structuredContent']);
        $this->assertSame(42, $payload['structuredContent']['id']);
    }

    public function testRpcCallStillReturnsFullPayload(): void
    {
        // Sanity check: the transformer only runs in MCP path, not in plain JSON-RPC.
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.secretBox","id":1}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame('do not show this to the LLM', $payload['result']['secret']);
    }

    public function testCallErrorReturnsBothContentAndError(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.echo","arguments":{"message":""}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertTrue($payload['isError']);
        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertStringContainsString('Error -32602', $payload['content'][0]['text']);
    }

    public function testCallNonExposedMethodReturns404(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"math.add","arguments":{"a":1,"b":2}}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue($payload['isError']);
        $this->assertSame(-32601, $payload['error']['code']);
        $this->assertSame('text', $payload['content'][0]['type']);
    }

    public function testPerMethodMaxRequestSizeRejectsOversizedMcpCall(): void
    {
        $kernel = $this->boot();
        // file.upload caps the body at 4096 bytes — feed it ~5 KiB of payload.
        $payload = str_repeat('x', 5000);
        $body = json_encode([
            'name' => 'file.upload',
            'arguments' => ['payload' => $payload],
        ], \JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $response = $kernel->handle($request);

        // Transport-level oversize → 413, not the generic 200/isError MCP
        // tool-failure envelope (which is reserved for handler-level errors).
        $this->assertSame(413, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue($payload['isError']);
        $this->assertStringContainsString('too large', $payload['error']['message']);
    }

    public function testTransportErrorsUseUnifiedEnvelope(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":""}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue($payload['isError']);
        $this->assertSame(-32600, $payload['error']['code']);
        $this->assertStringContainsString('Field "name" must be a non-empty string', $payload['content'][0]['text']);
    }

    public function testBadJsonReturnsParseError(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'not-json',
        );
        $response = $kernel->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(-32700, $payload['error']['code']);
    }

    public function testValidationErrorCarriesDataAndRendersViolations(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/mcp/call',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"test.echo","arguments":{"message":""}}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertTrue($payload['isError']);
        $this->assertSame(-32602, $payload['error']['code']);
        // data with violation list — what /rpc has always carried but MCP used to lose
        $this->assertSame('message', $payload['error']['data'][0]['path']);
        // human-readable text in content includes the violation path
        $this->assertStringContainsString('message:', $payload['content'][0]['text']);
    }

    public function testRateLimitBypassedOnMcpByDefault(): void
    {
        $kernel = $this->boot();
        for ($i = 0; $i < 3; ++$i) {
            $request = Request::create(
                '/mcp/call',
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"name":"test.mcp_throttled","arguments":{}}',
            );
            $response = $kernel->handle($request);
            $payload = $this->decodeJsonResponse($response);
            $this->assertSame(200, $response->getStatusCode(), 'Call #'.$i.' body: '.$this->responseContent($response));
            $this->assertArrayNotHasKey('isError', $payload, 'Rate limit fired unexpectedly on call #'.$i);
        }
    }

    public function testRateLimitAppliedWhenConfigEnablesIt(): void
    {
        $kernel = $this->boot(['mcp' => ['apply_rate_limit' => true]]);
        $payloads = [];
        for ($i = 0; $i < 2; ++$i) {
            $request = Request::create(
                '/mcp/call',
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"name":"test.mcp_throttled","arguments":{}}',
            );
            $response = $kernel->handle($request);
            $payloads[] = $this->decodeJsonResponse($response);
        }

        // limit is 1/60s globally — second call must trip the limiter
        $this->assertArrayNotHasKey('isError', $payloads[0]);
        $this->assertTrue($payloads[1]['isError']);
        $this->assertSame(-32003, $payloads[1]['error']['code']);
    }

    public function testMcpDisabledRemovesServices(): void
    {
        $kernel = $this->boot(['mcp' => ['enabled' => false]]);

        $container = $kernel->getContainer();
        // McpController, McpToolRegistry, JsonSchemaBuilder, McpToolFilter are
        // removed when the bundle is disabled. Routes themselves still resolve
        // (they live in routes.php) but the controller is no longer wired,
        // which is acceptable since the user has explicitly disabled MCP.
        $this->assertFalse($container->has(\Knetesin\JsonRpcServerBundle\Controller\McpController::class));
        $this->assertFalse($container->has(\Knetesin\JsonRpcServerBundle\Mcp\McpToolRegistry::class));
        $this->assertFalse($container->has(\Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter::class));
        $this->assertFalse($container->has(\Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder::class));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    private function getTools(array $config = []): array
    {
        $kernel = $this->boot($config);
        $request = Request::create('/mcp/tools', 'GET');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode(), 'body: '.$this->responseContent($response));
        $payload = $this->decodeJsonResponse($response);

        return $payload['tools'];
    }

    /**
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private function findToolByName(array $tools, string $name): array
    {
        foreach ($tools as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }
        $this->fail("Tool $name not found");
    }
}
