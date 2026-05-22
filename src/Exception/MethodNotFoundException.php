<?php

declare(strict_types=1);

namespace JsonRpcServer\Exception;

final class MethodNotFoundException extends RpcException
{
    public function __construct(string $methodName)
    {
        parent::__construct(\sprintf('Method not found: %s', $methodName));
    }

    public function rpcCode(): int
    {
        return self::METHOD_NOT_FOUND;
    }
}
