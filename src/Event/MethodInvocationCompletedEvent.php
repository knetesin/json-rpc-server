<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Event;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;

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
