<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use JsonRpcServer\OpenTelemetry\OpenTelemetrySubscriber;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\TracerInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Tracer / meter / counter / histogram are pulled from the OTel global
    // provider. The global provider is initialised by the host project
    // (typically via the OpenTelemetry auto-instrumentation Composer plugin
    // or the SDK's `Sdk::builder()->buildAndRegisterGlobal()`). We resolve
    // them lazily via factory services to avoid touching the SDK at boot.
    $services->set('json_rpc_server.opentelemetry.tracer', TracerInterface::class)
        ->factory([Globals::class, 'tracerProvider'])
        ->call('getTracer', [abstract_arg('Tracer name; set by RpcExtension')]);

    $services->set('json_rpc_server.opentelemetry.meter', \OpenTelemetry\API\Metrics\MeterInterface::class)
        ->factory([Globals::class, 'meterProvider'])
        ->call('getMeter', [abstract_arg('Meter name; set by RpcExtension')]);

    $services->set('json_rpc_server.opentelemetry.histogram', HistogramInterface::class)
        ->factory([service('json_rpc_server.opentelemetry.meter'), 'createHistogram'])
        ->args([
            'rpc.server.duration',
            'ms',
            'Duration of inbound RPC calls handled by the JSON-RPC bundle.',
        ]);

    $services->set('json_rpc_server.opentelemetry.counter', CounterInterface::class)
        ->factory([service('json_rpc_server.opentelemetry.meter'), 'createCounter'])
        ->args([
            'rpc.server.requests',
            '{call}',
            'Number of inbound RPC calls handled by the JSON-RPC bundle.',
        ]);

    $services->set(OpenTelemetrySubscriber::class)
        ->args([
            service('json_rpc_server.opentelemetry.tracer'),
            service('json_rpc_server.opentelemetry.histogram'),
            service('json_rpc_server.opentelemetry.counter'),
            service('request_stack')->nullOnInvalid(),
            abstract_arg('Traces flag; set by RpcExtension'),
            abstract_arg('Metrics flag; set by RpcExtension'),
            abstract_arg('Propagation flag; set by RpcExtension'),
            abstract_arg('Record params flag; set by RpcExtension'),
            abstract_arg('Record result flag; set by RpcExtension'),
            abstract_arg('Record max chars; set by RpcExtension'),
            abstract_arg('Stream record_row_count flag; set by RpcExtension'),
            abstract_arg('Stream span_per_row flag; set by RpcExtension'),
            abstract_arg('Ignored exception classes; set by RpcExtension'),
        ])
        ->tag('kernel.event_subscriber');
};
