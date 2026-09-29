<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Http;

use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Exception\InvalidParamsException;
use Knetesin\JsonRpcServerBundle\Exception\MethodNotFoundException;
use Knetesin\JsonRpcServerBundle\Exception\RateLimitExceededException;
use Knetesin\JsonRpcServerBundle\Exception\RequestTooLargeException;
use Knetesin\JsonRpcServerBundle\Exception\RpcErrorEnvelope;
use Knetesin\JsonRpcServerBundle\Http\RpcHttpStatusResolver;
use PHPUnit\Framework\TestCase;

final class RpcHttpStatusResolverTest extends TestCase
{
    private RpcHttpStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RpcHttpStatusResolver();
    }

    public function testTooLargeAlways413(): void
    {
        $e = new RequestTooLargeException(100, 50);

        $this->assertSame(413, $this->resolver->statusForException($e, false));
        $this->assertSame(413, $this->resolver->statusForException($e, true));
    }

    public function testOptionalMappingOffReturns200ForOtherErrors(): void
    {
        $this->assertSame(200, $this->resolver->statusForException(new MethodNotFoundException('x'), false));
    }

    public function testOptionalMappingOnMapsCodes(): void
    {
        $this->assertSame(404, $this->resolver->statusForException(new MethodNotFoundException('x'), true));
        $this->assertSame(400, $this->resolver->statusForException(new InvalidParamsException(), true));
    }

    public function testBatchUsesHighestStatusWhenEnabled(): void
    {
        $responses = [
            ['jsonrpc' => '2.0', 'result' => 1, 'id' => 1],
            RpcErrorEnvelope::jsonRpc(2, new MethodNotFoundException('x')),
        ];

        $this->assertSame(200, $this->resolver->statusForResponses($responses, false));
        $this->assertSame(404, $this->resolver->statusForResponses($responses, true));
    }

    public function testTooLargeEnvelopeFollowsTheNormalCodeMapping(): void
    {
        // Envelopes are mapped by error.code only: an oversized batch item is a plain -32600.
        $envelope = RpcErrorEnvelope::jsonRpc(1, new RequestTooLargeException(10, 5));

        $this->assertSame(200, $this->resolver->statusForEnvelope($envelope, false));
        $this->assertSame(400, $this->resolver->statusForEnvelope($envelope, true));
        $this->assertSame(200, $this->resolver->statusForResponses([$envelope, ['jsonrpc' => '2.0', 'result' => 1, 'id' => 2]], false));
    }

    public function testAccessDeniedMapsTo403(): void
    {
        $e = new AccessDeniedException('Access denied');

        $this->assertSame(403, $this->resolver->statusForException($e, true));
        $this->assertSame(200, $this->resolver->statusForException($e, false));
        $this->assertSame(403, $this->resolver->statusForEnvelope(RpcErrorEnvelope::jsonRpc(1, $e), true));
    }

    public function testRateLimitMapsTo429(): void
    {
        $this->assertSame(429, $this->resolver->statusForException(new RateLimitExceededException('Too many', 5), true));
    }
}
