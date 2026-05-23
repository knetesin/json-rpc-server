<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Exception;

final class ParseException extends RpcException
{
    public function __construct(string $message = 'Parse error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function rpcCode(): int
    {
        return self::PARSE_ERROR;
    }
}
