<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto;

final readonly class TagsMapRequest
{
    public function __construct(
        /** @var array<string, string> */
        public array $tags = [],
    ) {
    }
}
