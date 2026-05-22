<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class EchoRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 32)]
        public string $message = 'hi',
    ) {
    }
}
