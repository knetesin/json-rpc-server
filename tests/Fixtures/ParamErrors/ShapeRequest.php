<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\ParamErrors;

final readonly class ShapeRequest
{
    public function __construct(
        public string $kind,
    ) {
    }
}
