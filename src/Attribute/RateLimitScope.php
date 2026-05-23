<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

enum RateLimitScope: string
{
    /** Per-method counter shared across the deployment. */
    case GlobalScope = 'global';

    /** Per Symfony security user identifier (falls back to "anon" for guests). */
    case User = 'user';

    /** Per client IP (taken from RequestStack). */
    case Ip = 'ip';
}
