<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('math.legacy_add', deprecated: 'Use math.add instead — will be removed in v2.')]
final class LegacyAdd
{
    /** @return array<string, mixed> */
    public function __invoke(AddRequest $req): array
    {
        return ['sum' => $req->a + $req->b];
    }
}
