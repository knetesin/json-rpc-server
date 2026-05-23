<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Knetesin\JsonRpcServerBundle\Logging\RpcLoggingSubscriber;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RpcLoggingSubscriber::class)
        ->args([
            service('logger'),
            abstract_arg('Level for started events; set by RpcExtension'),
            abstract_arg('Level for completed events; set by RpcExtension'),
            abstract_arg('Level for failed events; set by RpcExtension'),
            abstract_arg('Whether to log params; set by RpcExtension'),
            abstract_arg('Whether to log result; set by RpcExtension'),
            abstract_arg('Slow threshold in ms; set by RpcExtension'),
        ])
        ->tag('kernel.event_subscriber');
};
