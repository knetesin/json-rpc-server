<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

enum RateLimitScope: string
{
    /** Per-method counter shared across the deployment. */
    case GlobalScope = 'global';

    /** Per Symfony security user identifier; guests are limited per client IP instead. */
    case User = 'user';

    /** Per client IP (taken from RequestStack). */
    case Ip = 'ip';
}
