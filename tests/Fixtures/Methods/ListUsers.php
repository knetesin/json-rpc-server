<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\McpFormat;

#[Rpc\Method('user.list')]
#[Rpc\Mcp(description: 'Returns a small list of users.', format: McpFormat::Markdown)]
final class ListUsers
{
    /** @return list<array<string, int|string>> */
    public function __invoke(): array
    {
        return [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
    }
}
