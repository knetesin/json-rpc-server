<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bare scalar params without #[Rpc\Param] — should auto-promote so they appear
 * in inputSchema and resolve at runtime by their PHP name.
 */
#[Rpc\Method('test.autoPromoted')]
#[Rpc\Mcp(description: 'Bare scalars auto-promoted to params.')]
final class AutoPromotedScalar
{
    /** @return array<string, mixed> */
    public function __invoke(
        #[Assert\Positive]
        int $autoId,
        ?string $note = null,
    ): array {
        return ['autoId' => $autoId, 'note' => $note];
    }
}
