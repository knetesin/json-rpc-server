<?php

declare(strict_types=1);

namespace JsonRpcServer\Exception;

class AccessDeniedException extends RpcException
{
    public const int DEFAULT_CODE = -32001;

    public function __construct(
        string $message = 'Access denied',
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
