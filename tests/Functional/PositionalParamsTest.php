<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

/**
 * Positional values beyond the declared parameters are rejected under
 * rejectUnknown (the default) and ignored when it is off.
 */
final class PositionalParamsTest extends KernelTestCase
{
    public function testExtraPositionalScalarRejected(): void
    {
        $payload = $this->call('test.autoPromoted', '[7,"note","extra"]');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('Too many positional parameters: expected at most 2, got 3', $payload['error']['message']);
    }

    public function testDeclaredPositionalScalarsAccepted(): void
    {
        $payload = $this->call('test.autoPromoted', '[7,"note"]');

        $this->assertSame(['autoId' => 7, 'note' => 'note'], $payload['result']);
    }

    public function testExtraPositionalDtoFieldRejected(): void
    {
        $payload = $this->call('math.add', '[2,3,4]');

        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame('Too many positional parameters: expected at most 2, got 3', $payload['error']['message']);
    }

    public function testExtraPositionalIgnoredWhenRejectUnknownIsOff(): void
    {
        $config = ['params' => ['reject_unknown' => false]];

        $this->assertSame(['sum' => 5], $this->call('math.add', '[2,3,4]', $config)['result']);
        $this->assertSame(['autoId' => 7, 'note' => 'note'], $this->call('test.autoPromoted', '[7,"note","extra"]', $config)['result']);
    }

    public function testMethodWithoutBusinessParamsKeepsRawPositionalParams(): void
    {
        $payload = $this->call('test.params_echo', '[1,2,3]');

        $this->assertSame(3, $payload['result']['count']);
    }

    /**
     * @param array<string, mixed> $rpcConfig
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $params, array $rpcConfig = []): array
    {
        $kernel = $this->boot($rpcConfig);

        return $this->decodeJsonResponse($kernel->handle(Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"'.$method.'","params":'.$params.',"id":1}',
        )));
    }
}
