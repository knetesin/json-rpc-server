<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

/**
 * -32602 payloads describe what is wrong without echoing serializer messages,
 * which name DTO classes and other internals.
 */
final class InvalidParamsMessageTest extends KernelTestCase
{
    public function testDenormalizationErrorsUseGenericTexts(): void
    {
        [$body, $payload] = $this->call('test.paramErrors.user', '{"name":"a","age":"thirty","color":"purple","createdAt":"nope"}');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('Invalid params', $payload['error']['message']);
        $this->assertSame([
            ['path' => 'age', 'message' => 'This value should be of type int.', 'code' => null],
            ['path' => 'createdAt', 'message' => 'This value is not valid.', 'code' => null],
            ['path' => 'color', 'message' => 'This value is not valid.', 'code' => null],
        ], $payload['error']['data']);
        $this->assertStringNotContainsString('Knetesin', $body);
        $this->assertStringNotContainsString('UserRequest', $body);
    }

    public function testMissingPropertyIsReportedAsMissing(): void
    {
        [, $payload] = $this->call('test.paramErrors.user', '{"name":"a"}');

        $this->assertSame(
            [['path' => 'age', 'message' => 'This field is missing.', 'code' => null]],
            $payload['error']['data'],
        );
    }

    public function testExplicitNullReportsExpectedType(): void
    {
        [, $payload] = $this->call('test.paramErrors.user', '{"name":"a","age":null}');

        $this->assertSame(
            [['path' => 'age', 'message' => 'This value should be of type int.', 'code' => null]],
            $payload['error']['data'],
        );
    }

    public function testOtherSerializerErrorsCarryNoDetails(): void
    {
        [$body, $payload] = $this->call('test.paramErrors.shape', '{"kind":"square"}');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('Invalid params', $payload['error']['message']);
        $this->assertArrayNotHasKey('data', $payload['error']);
        $this->assertStringNotContainsString('ShapeRequest', $body);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function call(string $method, string $params): array
    {
        $kernel = $this->boot([], ['ParamErrors']);
        $response = $kernel->handle(Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"'.$method.'","params":'.$params.',"id":1}',
        ));

        return [$this->responseContent($response), $this->decodeJsonResponse($response)];
    }
}
