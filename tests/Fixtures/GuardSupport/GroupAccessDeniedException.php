<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport;

use Knetesin\JsonRpcServerBundle\Exception\RpcException;

final class GroupAccessDeniedException extends RpcException
{
    public const int CODE = -32002;

    public function __construct(
        private readonly int|string|null $groupId,
        private readonly GroupPermission $permission,
    ) {
        parent::__construct('Group access denied');
    }

    public function rpcCode(): int
    {
        return self::CODE;
    }

    /**
     * @return array{groupId: int|string|null, permission: string}
     */
    public function rpcData(): array
    {
        return ['groupId' => $this->groupId, 'permission' => $this->permission->name];
    }
}
