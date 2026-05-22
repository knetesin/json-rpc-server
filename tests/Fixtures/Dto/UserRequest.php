<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Dto;

use JsonRpcServer\Type\Date;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UserRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(min: 1, max: 255)]
        public string $name,
        #[Assert\Range(min: 0, max: 150)]
        public int $age,
        #[Assert\Email]
        public ?string $email = null,
        public ?Date $birthday = null,
        public ?\DateTimeImmutable $createdAt = null,
        public Color $color = Color::Blue,
        #[Assert\Valid]
        public ?AddressDto $address = null,
    ) {
    }
}
