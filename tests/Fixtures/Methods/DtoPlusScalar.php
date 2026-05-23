<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto\AddressDto;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demonstrates DTO + scalar coexisting in a single flat params object.
 * The DTO's ctor fields (street, city) and the scalar autoId all live
 * at the top level — JsonSchemaBuilder spreads, resolver filters.
 */
#[Rpc\Method('test.dtoPlusScalar')]
#[Rpc\Mcp(description: 'DTO + scalar param at the same flat level.')]
final class DtoPlusScalar
{
    /** @return array<string, mixed> */
    public function __invoke(
        AddressDto $address,
        #[Assert\Positive]
        int $autoId,
    ): array {
        return [
            'autoId' => $autoId,
            'street' => $address->street,
            'city' => $address->city,
        ];
    }
}
