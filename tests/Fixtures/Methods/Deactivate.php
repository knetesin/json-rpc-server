<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Symfony\Component\Validator\Constraints as Assert;

#[Rpc\Method('user.deactivate')]
#[Rpc\Mcp(description: 'Deactivate a user without a DTO.')]
final class Deactivate
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Rpc\Param('user_id')]
        #[Assert\Positive]
        int $userId,
        #[Rpc\Param('reason', required: false)]
        #[Assert\Length(max: 64)]
        ?string $reason = null,
    ): array {
        return ['userId' => $userId, 'reason' => $reason];
    }
}
