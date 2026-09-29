<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Knetesin\JsonRpcServerBundle\Http\ClientIpResolver;
use Knetesin\JsonRpcServerBundle\Http\FanoutClientIpSigner;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/*
 * Wires the loopback parallel-batch executor into the container.
 *
 * The executor uses a dedicated HttpClient — separate from any
 * application-level HttpClient — so its timeouts and headers don't leak
 * into the rest of the app. RpcController's parallel-related args are
 * connected from RpcExtension (post-load) so the wiring stays in one place
 * regardless of where the executor itself is declared.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Dedicated HttpClient instance for fan-out. Sharing the application's
    // shared HttpClient would mix concerns — we want strict timeouts and
    // no inherited headers.
    $services->set('json_rpc_server.parallel_batch.http_client', HttpClientInterface::class)
        ->factory([HttpClient::class, 'create'])
        ->args([[
            'timeout' => '%json_rpc_server.parallel_batch.timeout%',
            'max_redirects' => 0,
        ]]);

    // A missing kernel.secret fails the container build. An empty one fails on
    // first read (FrameworkBundle, else the signer's constructor), and since
    // ClientIpResolver depends on the signer, so does every rate-limited or
    // IP-scoped call, not only fan-out.
    $services->set(FanoutClientIpSigner::class)
        ->args([param('kernel.secret')]);

    $services->set(ParallelBatchExecutor::class)
        ->args([
            service('json_rpc_server.parallel_batch.http_client'),
            '%json_rpc_server.parallel_batch.max_concurrency%',
            '%json_rpc_server.parallel_batch.timeout%',
            '%json_rpc_server.parallel_batch.connect_timeout%',
            '%json_rpc_server.parallel_batch.forward_headers%',
            '%json_rpc_server.parallel_batch.self_url%',
            service('logger')->nullOnInvalid(),
            '%json_rpc_server.max_json_depth%',
            service(FanoutClientIpSigner::class),
            service(ClientIpResolver::class),
        ]);
};
