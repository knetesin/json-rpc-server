<?php

declare(strict_types=1);

namespace JsonRpcServer\Exception;

final class InternalErrorException extends RpcException
{
    public function __construct(string $message = 'Internal error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function rpcCode(): int
    {
        return self::INTERNAL_ERROR;
    }
}
