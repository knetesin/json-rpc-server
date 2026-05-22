<?php

declare(strict_types=1);

namespace JsonRpcServer;

use JsonRpcServer\DependencyInjection\Compiler\MethodCompilerPass;
use JsonRpcServer\DependencyInjection\RpcExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class JsonRpcServerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MethodCompilerPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new RpcExtension();
        }

        return $this->extension ?: null;
    }
}
