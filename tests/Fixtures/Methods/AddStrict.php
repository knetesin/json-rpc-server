<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/**
 * Same shape as Add but without `allowPositionalDto: true` — used to verify
 * that positional params get rejected by default.
 */
#[Rpc\Method('math.add_strict')]
final class AddStrict
{
    /** @return array<string, mixed> */
    public function __invoke(AddRequest $req): array
    {
        return ['sum' => $req->a + $req->b];
    }
}
