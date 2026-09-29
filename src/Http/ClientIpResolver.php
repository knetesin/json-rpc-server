<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Http;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Client IP for rate limiting and cache scoping.
 *
 * A parallel-batch sub-call reaches the app from the server itself, so its
 * own {@see HttpRequest::getClientIp()} is the loopback address. When the
 * sub-call carries a client IP signed by {@see FanoutClientIpSigner}, that IP
 * is used instead; anything else falls back to getClientIp(). Without a
 * signer (parallel batch disabled) the forwarded header is never read.
 */
final class ClientIpResolver
{
    /** @var \WeakMap<HttpRequest, array{ip: ?string}> */
    private \WeakMap $resolved;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ?FanoutClientIpSigner $signer = null,
    ) {
        $this->resolved = new \WeakMap();
    }

    /** Client IP of the main request; null outside HTTP or when unknown. */
    public function clientIp(): ?string
    {
        $request = $this->requestStack->getMainRequest();

        return null === $request ? null : $this->clientIpOf($request);
    }

    public function clientIpOf(HttpRequest $request): ?string
    {
        if (null === $this->signer) {
            return $request->getClientIp();
        }

        // Verification hashes the body; memoized because every rate-limited
        // or IP-scoped call in the request asks again.
        $this->resolved[$request] ??= ['ip' => $this->signer->verifiedClientIp($request) ?? $request->getClientIp()];

        return $this->resolved[$request]['ip'];
    }
}
