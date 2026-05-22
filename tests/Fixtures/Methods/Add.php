<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

#[Rpc\Method('math.add', allowPositionalDto: true)]
final class Add
{
    /** @return array<string, mixed> */
    public function __invoke(AddRequest $req): array
    {
        return ['sum' => $req->a + $req->b];
    }
}
