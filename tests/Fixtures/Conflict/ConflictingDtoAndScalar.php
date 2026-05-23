<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Conflict;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto\AddressDto;

/**
 * AddressDto's ctor takes `street, city`. The scalar sibling `city` claims the
 * same JSON key as AddressDto's `city` — MethodCompilerPass must fail the
 * container build with a LogicException naming the colliding key.
 *
 * Lives outside tests/Fixtures/Methods/ so the default TestKernel doesn't
 * autoload it (that would crash every test). Loaded explicitly by the
 * unit test that exercises the conflict detector.
 */
#[Rpc\Method('test.conflictingDtoAndScalar')]
final class ConflictingDtoAndScalar
{
    /** @return array<string, mixed> */
    public function __invoke(
        AddressDto $address,
        #[Rpc\Param]
        string $city,
    ): array {
        return ['city' => $city, 'addressCity' => $address->city];
    }
}
