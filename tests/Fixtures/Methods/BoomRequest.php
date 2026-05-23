<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BoomRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['access', 'access_custom', 'not_found', 'unhandled'])]
        public string $kind,
    ) {
    }
}
