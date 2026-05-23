<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Exception;

class NotFoundException extends RpcException
{
    public const int DEFAULT_CODE = -32002;

    public function __construct(
        string $message = 'Not found',
        private readonly int $rpcCode = self::DEFAULT_CODE,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function rpcCode(): int
    {
        return $this->rpcCode;
    }
}
