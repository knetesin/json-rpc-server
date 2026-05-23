<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto;

final readonly class FiltersMapRequest
{
    public function __construct(
        /** @var array<string, FilterSpec> */
        public array $filters = [],
    ) {
    }
}
