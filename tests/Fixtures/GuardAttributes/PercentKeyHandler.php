<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** A nested array key with "%" would be resolved as a %parameter% placeholder. */
#[Rpc\Method('attrs.percentKey')]
#[Keyed(['ranges' => ['10%-20%' => 1]])]
final class PercentKeyHandler
{
    public function __invoke(): void
    {
    }
}
