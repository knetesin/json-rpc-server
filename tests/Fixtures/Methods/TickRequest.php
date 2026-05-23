<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TickRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: 10)]
        public int $count = 3,
    ) {
    }
}
