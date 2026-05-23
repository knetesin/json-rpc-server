<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Exception;

final class InvalidRequestException extends RpcException
{
    public function __construct(string $message = 'Invalid Request')
    {
        parent::__construct($message);
    }

    public function rpcCode(): int
    {
        return self::INVALID_REQUEST;
    }
}
