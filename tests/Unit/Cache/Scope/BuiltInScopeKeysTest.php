<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Cache\Scope;

use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Knetesin\JsonRpcServerBundle\Cache\Scope\IpScope;
use Knetesin\JsonRpcServerBundle\Cache\Scope\UserScope;
use Knetesin\JsonRpcServerBundle\Http\ClientIpResolver;
use Knetesin\JsonRpcServerBundle\Http\FanoutClientIpSigner;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class BuiltInScopeKeysTest extends TestCase
{
    public function testUserScopeKeysAuthenticatedUsersByIdentifier(): void
    {
        $this->assertSame('user:alice', $this->userScopeKey('alice'));
    }

    public function testUserScopeGuestSlotDoesNotCollideWithUserNamedAnon(): void
    {
        $this->assertSame('guest', $this->userScopeKey(null));
        $this->assertSame('user:anon', $this->userScopeKey('anon'));
    }

    public function testIpScopeUsesVerifiedFanoutClientIp(): void
    {
        $signer = new FanoutClientIpSigner('secret');
        $body = '{"jsonrpc":"2.0","method":"a","id":1}';
        $request = Request::create('/rpc', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1'], content: $body);
        $request->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '1');
        $request->headers->set(FanoutClientIpSigner::IP_HEADER, '203.0.113.7');
        $request->headers->set(FanoutClientIpSigner::SIGNATURE_HEADER, $signer->sign('203.0.113.7', 1, $body));
        $stack = new RequestStack();
        $stack->push($request);

        $scope = new IpScope($stack, new ClientIpResolver($stack, $signer));

        $this->assertSame('ip:203.0.113.7', $scope->key($this->method(), $this->rpcRequest()));
    }

    public function testIpScopeWithoutRequestIsUnknown(): void
    {
        $stack = new RequestStack();

        $this->assertSame('ip:unknown', (new IpScope($stack, new ClientIpResolver($stack)))->key($this->method(), $this->rpcRequest()));
    }

    private function userScopeKey(?string $identifier): string
    {
        $storage = new TokenStorage();
        if (null !== $identifier) {
            $storage->setToken(new TestBrowserToken(['ROLE_USER'], new InMemoryUser($identifier, null, ['ROLE_USER'])));
        }

        return (new UserScope(new SecurityUserResolver($storage)))->key($this->method(), $this->rpcRequest());
    }

    private function rpcRequest(): RpcRequest
    {
        return new RpcRequest(id: 1, method: 'test.method', params: new RpcParams([]), isNotification: false);
    }

    private function method(): MethodMetadata
    {
        return new MethodMetadata(
            name: 'test.method',
            serviceClass: 'object',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
        );
    }
}
