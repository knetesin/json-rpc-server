<?php

declare(strict_types=1);

namespace JsonRpcServer\Attribute;

/**
 * How multiple entries in #[Rpc\Method(roles: [...])] are evaluated.
 *
 * - Any: caller needs at least one role (Symfony access_control style).
 * - All: caller needs every listed role.
 */
enum RoleMatch: string
{
    case Any = 'any';
    case All = 'all';
}
