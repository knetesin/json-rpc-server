<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\RateLimit;

use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitBypassInterface;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Test stand-in for a real verifier (e.g. FCrDNS crawler check): bypasses the
 * rate limit whenever the request carries `X-Bypass-RateLimit: 1`. Inert for
 * every other request, so it doesn't disturb the rest of the suite.
 */
final readonly class HeaderBypass implements RateLimitBypassInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function shouldBypass(MethodMetadata $method, RateLimit $rateLimit): bool
    {
        return '1' === $this->requestStack->getMainRequest()?->headers->get('X-Bypass-RateLimit');
    }
}
