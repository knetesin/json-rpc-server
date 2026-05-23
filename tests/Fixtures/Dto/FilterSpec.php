<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto;

final readonly class FilterSpec
{
    public function __construct(
        public string $mode = 'include',
        /** @var list<int> */
        public array $value = [],
    ) {
    }
}
