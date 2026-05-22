<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use JsonRpcServer\Profiler\JsonRpcDataCollector;
use JsonRpcServer\Profiler\RpcProfilerSubscriber;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('json_rpc.data_collector', JsonRpcDataCollector::class)
        ->tag('data_collector', [
            'template' => '@JsonRpcServerBundle/Resources/views/Collector/json_rpc.html.twig',
            'id' => 'json_rpc',
            'priority' => 265,
        ]);

    $services->set(RpcProfilerSubscriber::class)
        ->args([service('json_rpc.data_collector')])
        ->tag('kernel.event_subscriber');
};
