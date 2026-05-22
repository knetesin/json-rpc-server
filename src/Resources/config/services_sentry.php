<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use JsonRpcServer\Sentry\SentryRpcSubscriber;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Sentry's HubInterface is autowireable via sentry/sentry-symfony — when
    // that bundle is installed `Sentry\State\HubInterface` resolves to the
    // active hub. RpcExtension only loads this file when both the user opted
    // in (json_rpc_server.sentry.enabled) and the SDK class is present.
    $services->set(SentryRpcSubscriber::class)
        ->args([
            service('Sentry\\State\\HubInterface'),
            abstract_arg('Breadcrumbs flag; set by RpcExtension'),
            abstract_arg('Tag method flag; set by RpcExtension'),
            abstract_arg('Transactions flag; set by RpcExtension'),
            abstract_arg('Ignored exception classes; set by RpcExtension'),
        ])
        ->tag('kernel.event_subscriber');
};
