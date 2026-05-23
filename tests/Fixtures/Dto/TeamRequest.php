<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MemberDto
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
    ) {
    }
}

final readonly class TeamRequest
{
    /**
     * @param list<MemberDto> $members
     */
    public function __construct(
        #[Assert\Valid]
        public array $members,
    ) {
    }
}
