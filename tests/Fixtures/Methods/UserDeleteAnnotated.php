<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/**
 * Fully-annotated destructive method — every MCP hint is set explicitly so
 * the test can lock down passthrough.
 */
#[Rpc\Method('user.deleteAnnotated')]
#[Rpc\Mcp(
    description: 'Deletes a user by id.',
    title: 'Delete user',
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
)]
final class UserDeleteAnnotated
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['deleted' => true];
    }
}
