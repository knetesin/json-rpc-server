<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the unified "always flat" params model:
 *   - DTOs spread their ctor fields into the root params object.
 *   - Scalar #[Rpc\Param] (or auto-promoted scalar) sits next to them.
 *   - Schema and resolver agree on the same flat shape.
 *   - Extras still trigger -32602 (rejectUnknown default).
 *
 * Single-DTO and scalar-only forms are regression-tested elsewhere; this
 * file focuses on the new mixed case.
 */
final class FlatParamsTest extends KernelTestCase
{
    public function testDtoFieldsAndScalarSiblingResolveFromFlatParams(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.dtoPlusScalar","params":{"street":"Main","city":"NYC","autoId":7},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(['autoId' => 7, 'street' => 'Main', 'city' => 'NYC'], $payload['result']);
    }

    public function testUnknownTopLevelKeyRejected(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.dtoPlusScalar","params":{"street":"Main","city":"NYC","autoId":7,"wat":"???"},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertStringContainsString('wat', $payload['error']['message']);
        $this->assertSame('wat', $payload['error']['data'][0]['path']);
    }

    public function testScalarConstraintFiresOnMixedMethod(): void
    {
        // #[Assert\Positive] on the scalar sibling still applies.
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.dtoPlusScalar","params":{"street":"Main","city":"NYC","autoId":-1},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(-32602, $payload['error']['code']);
    }

    public function testNumericStringCoercedToIntScalar(): void
    {
        // Клиент шлёт число строкой (типичный route-параметр) — приводим к int, не TypeError.
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.dtoPlusScalar","params":{"street":"Main","city":"NYC","autoId":"7"},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertArrayNotHasKey('error', $payload);
        $this->assertSame(7, $payload['result']['autoId']);
    }

    public function testIncompatibleScalarRejectedAsInvalidParams(): void
    {
        // Несовместимое значение для int → чистый -32602, а не нативный TypeError.
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.dtoPlusScalar","params":{"street":"Main","city":"NYC","autoId":"abc"},"id":1}',
        );
        $payload = $this->decodeJsonResponse($kernel->handle($request));

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('autoId', $payload['error']['data'][0]['path']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function inRangeIntStrings(): iterable
    {
        yield 'leading zeros' => ['"007"', 7];
        yield 'negative zero' => ['"-0"', 0];
        yield 'int max' => ['"9223372036854775807"', \PHP_INT_MAX];
        yield 'int min' => ['"-9223372036854775808"', \PHP_INT_MIN];
        yield 'long zero padding' => ['"-00000000000000000000009"', -9];
    }

    #[DataProvider('inRangeIntStrings')]
    public function testIntStringInsidePhpRangeIsCoerced(string $json, int $expected): void
    {
        $payload = $this->callGroupEcho('{"group_id":'.$json.'}');

        $this->assertArrayNotHasKey('error', $payload);
        $this->assertSame($expected, $payload['result']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outOfRangeIntStrings(): iterable
    {
        yield 'above int max' => ['9223372036854775808'];
        yield 'below int min' => ['-9223372036854775809'];
        yield 'far above' => ['99999999999999999999'];
        yield 'far below' => ['-99999999999999999999'];
    }

    #[DataProvider('outOfRangeIntStrings')]
    public function testIntStringOutsidePhpRangeRejectedAsInvalidParams(string $digits): void
    {
        $payload = $this->callGroupEcho('{"group_id":"'.$digits.'"}');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('group_id', $payload['error']['data'][0]['path']);
        $this->assertSame('Expected type "int", got "string"', $payload['error']['data'][0]['message']);
    }

    public function testMissingRequiredParamNamesJsonKey(): void
    {
        $payload = $this->callGroupEcho('{}');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('Missing required parameter "group_id"', $payload['error']['message']);
    }

    public function testMcpInputSchemaIsFlat(): void
    {
        // properties at the root MUST include both DTO fields AND the scalar sibling.
        $kernel = $this->boot();
        $response = $kernel->handle(Request::create('/mcp/tools', 'GET'));
        $payload = $this->decodeJsonResponse($response);

        $tool = null;
        foreach ($payload['tools'] as $t) {
            if ('test.dtoPlusScalar' === $t['name']) {
                $tool = $t;
                break;
            }
        }
        $this->assertNotNull($tool, 'test.dtoPlusScalar not exposed via MCP');

        $this->assertSame('object', $tool['inputSchema']['type']);
        $props = (array) $tool['inputSchema']['properties'];

        // DTO fields spread into root.
        $this->assertArrayHasKey('street', $props);
        $this->assertSame('string', $props['street']['type']);
        $this->assertArrayHasKey('city', $props);

        // Scalar sibling next to them.
        $this->assertArrayHasKey('autoId', $props);
        $this->assertSame('integer', $props['autoId']['type']);
        $this->assertSame(0, $props['autoId']['exclusiveMinimum']);

        // DTO `address` ARG NAME must NOT appear — the DTO is spread, not nested.
        $this->assertArrayNotHasKey('address', $props);

        // Required is the union: DTO's required + autoId.
        $required = $tool['inputSchema']['required'];
        sort($required);
        $this->assertSame(['autoId', 'city', 'street'], $required);
    }

    /**
     * @return array<string, mixed>
     */
    private function callGroupEcho(string $params): array
    {
        $kernel = $this->boot([], ['Params']);

        return $this->decodeJsonResponse($kernel->handle(Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.params.groupEcho","params":'.$params.',"id":1}',
        )));
    }
}
