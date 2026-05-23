<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\DependencyInjection\Compiler;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Conflict\ConflictingDtoAndScalar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * MethodCompilerPass must fail the container build when two __invoke params
 * claim the same top-level JSON key — silent double-resolution would be a
 * footgun.
 */
final class MethodCompilerPassConflictTest extends TestCase
{
    public function testKeyConflictBetweenDtoAndScalarFailsCompilation(): void
    {
        $container = new ContainerBuilder();
        // Parameters MethodCompilerPass reads from the container. Defaults so
        // the pass can run independently of RpcExtension's wiring.
        $container->setParameter('json_rpc_server.security.roles_match', 'any');
        $container->setParameter('json_rpc_server.security.default_roles', []);
        $container->setParameter('json_rpc_server.security.public_prefixes', []);
        $container->setParameter('json_rpc_server.security.public_methods', []);
        $container->setParameter('json_rpc_server.security.prefix_roles', []);
        $container->setParameter('json_rpc_server.params.allow_positional_dto', false);
        $container->setParameter('json_rpc_server.params.reject_unknown', true);
        $container->setParameter('json_rpc_server.handlers.public', false);
        $container->setParameter('json_rpc_server.handlers.shared', false);
        $container->setParameter('json_rpc_server.serializer.datetime_format', 'iso8601');
        $container->setParameter('json_rpc_server.mcp.schema_max_depth', 6);

        $def = new Definition(ConflictingDtoAndScalar::class);
        $def->addTag(MethodCompilerPass::TAG);
        $container->setDefinition(ConflictingDtoAndScalar::class, $def);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/JSON key "city" already owned/');

        (new MethodCompilerPass())->process($container);
    }
}
