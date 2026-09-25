<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/** Bundle and Symfony DI attributes plus an unknown one must be left out; the rest is collected in order. */
#[Rpc\Method('attrs.collected', description: 'Share 10%-20%')]
#[Rpc\Cache(ttl: 5)]
#[Autoconfigure(public: true)]
#[AsTaggedItem(priority: 5)]
#[RequiresGroup(GroupPermission::Edit, on: 'teamId')]
#[Marker('first', tags: ['a%b'])]
// @phpstan-ignore attribute.notFound (deliberately unknown: PHP ignores it and so must the compiler pass)
#[DoesNotExist]
#[SubMarker('second')]
final class CollectedHandler
{
    public function __invoke(int $teamId): int
    {
        return $teamId;
    }
}
