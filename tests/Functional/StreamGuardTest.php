<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

/**
 * Compile-time guard: a #[Rpc\Stream] handler with routes.stream.enabled: false
 * must fail container build (not silently 404 at runtime).
 */
final class StreamGuardTest extends KernelTestCase
{
    public function testBuildFailsWhenStreamMethodPresentButRouteDisabled(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/routes\.stream\.enabled is false.*stream\.tick/');

        $this->boot(['routes' => ['stream' => ['enabled' => false]]]);
    }
}
