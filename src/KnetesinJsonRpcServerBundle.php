<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass;
use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodGuardPass;
use Knetesin\JsonRpcServerBundle\DependencyInjection\RpcExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class KnetesinJsonRpcServerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MethodCompilerPass());
        // After tagged iterators are resolved, so guards tagged by late passes are covered.
        $container->addCompilerPass(new MethodGuardPass(), PassConfig::TYPE_OPTIMIZE, -1);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new RpcExtension();
        }

        return $this->extension ?: null;
    }
}
