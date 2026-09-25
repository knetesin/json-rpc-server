<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\DependencyInjection\Compiler;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass;
use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodGuardPass;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Guard\GroupGuard;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\CollectedHandler;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\Marker;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\ObjectArgHandler;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\PercentKeyHandler;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\SubMarker;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\UninstantiableHandler;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Guard validation and handler attribute collection, run after
 * MethodCompilerPass has built the raw methods array.
 */
final class MethodGuardPassTest extends TestCase
{
    public function testCollectsOnlyForeignExistingAttributesWhenAGuardIsRegistered(): void
    {
        $container = $this->container([CollectedHandler::class], withGuard: true);

        $this->process($container);

        $this->assertSame([
            ['class' => RequiresGroup::class, 'args' => [GroupPermission::Edit, 'on' => 'teamId']],
            ['class' => Marker::class, 'args' => ['first', 'tags' => ['a%%b']]],
            ['class' => SubMarker::class, 'args' => ['second']],
        ], CompilerPassContainer::rawMethods($container)['attrs.collected']['attributes']);
    }

    public function testNoAttributesKeyWithoutGuards(): void
    {
        $container = $this->container([CollectedHandler::class], withGuard: false);

        $this->process($container);

        $this->assertArrayNotHasKey('attributes', CompilerPassContainer::rawMethods($container)['attrs.collected']);
    }

    public function testAttributeThatCannotBeInstantiatedFailsTheBuild(): void
    {
        $container = $this->container([UninstantiableHandler::class], withGuard: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/attrs\.uninstantiable.*Strict\] cannot be instantiated: level must not be negative/');

        $this->process($container);
    }

    public function testObjectAttributeArgumentFailsTheBuild(): void
    {
        $container = $this->container([ObjectArgHandler::class], withGuard: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/attrs\.objectArg.*WithObject\] must take only scalar, array, enum or null arguments/');

        $this->process($container);
    }

    public function testPercentInAttributeArrayKeyFailsTheBuild(): void
    {
        $container = $this->container([PercentKeyHandler::class], withGuard: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('RPC method attrs.percentKey (%s): attribute #[%s] uses an array key containing "%%"', PercentKeyHandler::class, \Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes\Keyed::class));

        $this->process($container);
    }

    public function testBrokenAttributesAreIgnoredWithoutGuards(): void
    {
        $container = $this->container([UninstantiableHandler::class, ObjectArgHandler::class, PercentKeyHandler::class], withGuard: false);

        $this->process($container);

        $raw = CompilerPassContainer::rawMethods($container);
        $this->assertArrayHasKey('attrs.uninstantiable', $raw);
        $this->assertArrayHasKey('attrs.objectArg', $raw);
        $this->assertArrayHasKey('attrs.percentKey', $raw);
    }

    public function testTaggedServiceNotImplementingTheInterfaceFailsTheBuild(): void
    {
        $container = $this->container([CollectedHandler::class], withGuard: false);
        $container->setDefinition('app.not_a_guard', (new Definition(\ArrayObject::class))->addTag(MethodGuardPass::TAG));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Service "app.not_a_guard" (ArrayObject) is tagged "json_rpc_server.method_guard" but does not implement Knetesin\JsonRpcServerBundle\Security\MethodGuardInterface.');

        $this->process($container);
    }

    public function testAbstractOrUnreflectableTaggedServicesAreNotChecked(): void
    {
        $container = $this->container([CollectedHandler::class], withGuard: true);
        $container->setDefinition('app.abstract', (new Definition(\ArrayObject::class))->setAbstract(true)->addTag(MethodGuardPass::TAG));
        $container->setDefinition('app.unknown', (new Definition('App\\DoesNotExist'))->addTag(MethodGuardPass::TAG));

        $this->process($container);

        $this->assertArrayHasKey('attributes', CompilerPassContainer::rawMethods($container)['attrs.collected']);
    }

    /**
     * @param list<class-string> $handlers
     */
    private function container(array $handlers, bool $withGuard): ContainerBuilder
    {
        $container = CompilerPassContainer::create($handlers);
        if ($withGuard) {
            $container->setDefinition(GroupGuard::class, (new Definition(GroupGuard::class))->addTag(MethodGuardPass::TAG));
        }

        return $container;
    }

    private function process(ContainerBuilder $container): void
    {
        (new MethodCompilerPass())->process($container);
        (new MethodGuardPass())->process($container);
    }
}
