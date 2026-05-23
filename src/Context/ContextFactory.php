<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Context;

use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContextFactory
{
    /** Request attribute used to cache the resolved id across the request lifecycle. */
    private const string REQUEST_ID_ATTRIBUTE = '_rpc_request_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SecurityUserResolver $users,
        /** Header read for a client-supplied request id. Empty string disables header lookup. */
        private readonly string $requestIdHeader = 'X-Request-Id',
    ) {
    }

    public function create(string $methodName): Context
    {
        $request = $this->requestStack->getMainRequest();

        // Resolution order (first non-empty wins):
        //   1. cached value from a prior call in the same request — every item
        //      in a JSON-RPC batch must share one requestId, not five.
        //   2. client-supplied header (X-Request-Id by default). Lets a load
        //      balancer / gateway pin its own correlation id end-to-end.
        //   3. freshly generated cryptographic random id.
        $existing = $request?->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        if (\is_string($existing) && '' !== $existing) {
            $requestId = $existing;
        } else {
            $headerValue = '' !== $this->requestIdHeader
                ? $request?->headers->get($this->requestIdHeader)
                : null;
            $requestId = (\is_string($headerValue) && '' !== $headerValue)
                ? $headerValue
                : bin2hex(random_bytes(8));
            $request?->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);
        }

        $user = $this->users->getUser();
        $roles = $this->users->getRoles();

        return new Context(
            methodName: $methodName,
            requestId: $requestId,
            user: $user,
            roles: $roles,
        );
    }
}
