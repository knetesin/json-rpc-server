<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\DependencyInjection\Compiler;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\PercentName\PercentInName;
use PHPUnit\Framework\TestCase;

final class MethodCompilerPassMethodNameTest extends TestCase
{
    public function testPercentInMethodNameFailsCompilation(): void
    {
        $container = CompilerPassContainer::create([PercentInName::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('RPC method name "test.%%kernel.environment%%" (%s) must not contain "%%"', PercentInName::class));

        (new MethodCompilerPass())->process($container);
    }
}
