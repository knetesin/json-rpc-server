<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Guard;

use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Security\MethodGuardInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs before GroupGuard (higher priority). `X-Block: 1` denies with -32010,
 * `X-Guard-Crash: 1` throws a non-RPC exception.
 */
#[AsTaggedItem(priority: 10)]
final readonly class HeaderGuard implements MethodGuardInterface
{
    public const int BLOCK_CODE = -32010;

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function check(MethodMetadata $meta, array $args, RpcRequest $request): void
    {
        $headers = $this->requestStack->getMainRequest()?->headers;
        if ('1' === $headers?->get('X-Block')) {
            throw new AccessDeniedException('Blocked by header', self::BLOCK_CODE);
        }
        if ('1' === $headers?->get('X-Guard-Crash')) {
            throw new \RuntimeException('Guard crashed');
        }
    }
}
