<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Exception;

final class RateLimitExceededException extends RpcException
{
    public const int DEFAULT_CODE = -32003;

    public function __construct(
        string $message = 'Rate limit exceeded',
        public readonly ?int $retryAfter = null,
        private readonly int $rpcCode = self::DEFAULT_CODE,
    ) {
        parent::__construct($message);
    }

    public function rpcCode(): int
    {
        return $this->rpcCode;
    }

    public function rpcData(): mixed
    {
        return null !== $this->retryAfter ? ['retryAfter' => $this->retryAfter] : null;
    }
}
