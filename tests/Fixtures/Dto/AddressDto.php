<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddressDto
{
    public function __construct(
        #[Assert\NotBlank]
        public string $street,
        #[Assert\NotBlank]
        public string $city,
    ) {
    }
}
