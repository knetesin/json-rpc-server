<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Request;

final readonly class RpcRequest
{
    public function __construct(
        public string|int|null $id,
        public string $method,
        public RpcParams $params,
        public bool $isNotification,
    ) {
    }
}
