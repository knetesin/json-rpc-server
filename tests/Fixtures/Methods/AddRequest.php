<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddRequest
{
    public function __construct(
        #[Assert\NotNull]
        public int $a,
        #[Assert\NotNull]
        public int $b,
    ) {
    }
}
