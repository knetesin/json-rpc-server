<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto\TeamRequest;

#[Rpc\Method('test.teamCreate')]
#[Rpc\Mcp(description: 'Create a team from a list of members.')]
final class TeamCreate
{
    /** @return array{count: int, names: list<string>} */
    public function __invoke(TeamRequest $req): array
    {
        return [
            'count' => \count($req->members),
            'names' => array_map(static fn ($m) => $m->name, $req->members),
        ];
    }
}
