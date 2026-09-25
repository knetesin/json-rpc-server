<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\DependencyInjection\Compiler;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** Bare container with the parameters MethodCompilerPass reads. */
final class CompilerPassContainer
{
    /**
     * @param list<class-string> $handlers
     */
    public static function create(array $handlers): ContainerBuilder
    {
        $container = new ContainerBuilder();
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
        $container->setParameter('json_rpc_server.routes.stream.enabled', false);
        $container->setParameter('json_rpc_server.max_request_size', 0);
        $container->setDefinition(MethodRegistry::class, new Definition(MethodRegistry::class));

        foreach ($handlers as $class) {
            $container->setDefinition($class, (new Definition($class))->addTag(MethodCompilerPass::TAG));
        }

        return $container;
    }

    /**
     * @return array<mixed>
     */
    public static function rawMethods(ContainerBuilder $container): array
    {
        $raw = $container->getDefinition(MethodRegistry::class)->getArgument(0);
        if (!\is_array($raw)) {
            throw new \UnexpectedValueException('MethodRegistry argument 0 is not an array.');
        }

        return $raw;
    }
}
