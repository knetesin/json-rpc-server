<?php

declare(strict_types=1);

namespace JsonRpcServer\Exception;

final class RequestTooLargeException extends RpcException
{
    public function __construct(int $size, int $limit)
    {
        parent::__construct(\sprintf('Request body too large: %d bytes (limit: %d)', $size, $limit));
    }

    public function rpcCode(): int
    {
        return self::INVALID_REQUEST;
    }
}
