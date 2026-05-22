<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\McpFormat;

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
