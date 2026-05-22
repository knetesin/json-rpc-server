<?php

declare(strict_types=1);

namespace JsonRpcServer\Event;

use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Request\RpcParams;

final readonly class MethodInvocationCompletedEvent
{
    public function __construct(
        public MethodMetadata $method,
        public RpcParams $params,
        public mixed $result,
        public float $durationSec,
        public bool $cacheHit = false,
    ) {
    }
}
