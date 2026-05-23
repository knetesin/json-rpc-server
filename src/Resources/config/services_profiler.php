<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Knetesin\JsonRpcServerBundle\Profiler\JsonRpcDataCollector;
use Knetesin\JsonRpcServerBundle\Profiler\RpcProfilerSubscriber;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('json_rpc.data_collector', JsonRpcDataCollector::class)
        ->args([
            service(MethodRegistry::class),
            service(McpToolFilter::class)->nullOnInvalid(),
        ])
        ->tag('data_collector', [
            'template' => '@KnetesinJsonRpcServer/Collector/json_rpc.html.twig',
            'id' => 'json_rpc',
            'priority' => 265,
        ]);

    $services->set(RpcProfilerSubscriber::class)
        ->args([service('json_rpc.data_collector')])
        ->tag('kernel.event_subscriber');
};
