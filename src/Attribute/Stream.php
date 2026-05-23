<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Stream
{
    public function __construct(
        public readonly StreamFormat $format = StreamFormat::Ndjson,
    ) {
    }
}
