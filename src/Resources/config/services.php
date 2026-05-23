<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Knetesin\JsonRpcServerBundle\Cache\CacheChecker;
use Knetesin\JsonRpcServerBundle\Cache\RpcCacheInvalidator;
use Knetesin\JsonRpcServerBundle\Cache\Scope\IpScope;
use Knetesin\JsonRpcServerBundle\Cache\Scope\UserScope;
use Knetesin\JsonRpcServerBundle\Command\DebugRpcCommand;
use Knetesin\JsonRpcServerBundle\Command\RpcCacheClearCommand;
use Knetesin\JsonRpcServerBundle\Context\ContextFactory;
use Knetesin\JsonRpcServerBundle\Controller\McpController;
use Knetesin\JsonRpcServerBundle\Controller\OpenRpcController;
use Knetesin\JsonRpcServerBundle\Controller\RpcController;
use Knetesin\JsonRpcServerBundle\Controller\StreamController;
use Knetesin\JsonRpcServerBundle\Dispatcher\Dispatcher;
use Knetesin\JsonRpcServerBundle\Maker\MakeRpcMethod;
use Knetesin\JsonRpcServerBundle\Mcp\DefaultMcpResultFormatter;
use Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder;
use Knetesin\JsonRpcServerBundle\Mcp\McpResultFormatter;
use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Knetesin\JsonRpcServerBundle\Mcp\McpToolRegistry;
use Knetesin\JsonRpcServerBundle\OpenRpc\OpenRpcDocumentBuilder;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitChecker;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Request\RpcRequestParser;
use Knetesin\JsonRpcServerBundle\Resolver\ArgumentResolver;
use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;
use Knetesin\JsonRpcServerBundle\Serializer\DateNormalizer;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(DateNormalizer::class)
        ->args([
            '%json_rpc_server.serializer.datetime_format%',
            '%json_rpc_server.serializer.date_format%',
            '%json_rpc_server.serializer.timezone%',
        ])
        ->tag('serializer.normalizer', ['priority' => 1000]);

    $services->set(RpcRequestParser::class)
        ->args(['%json_rpc_server.parser_cap%', '%json_rpc_server.max_json_depth%']);
    $services->set(SecurityUserResolver::class)
        ->args([service('security.token_storage')->nullOnInvalid()]);

    $services->set(ContextFactory::class)
        ->arg('$requestIdHeader', '%json_rpc_server.context.request_id_header%');
    $services->set(ArgumentResolver::class);

    $services->set(RateLimitChecker::class)
        ->args([
            abstract_arg('Cache pool reference set by RpcExtension'),
            service('request_stack'),
            service(SecurityUserResolver::class),
        ]);

    $services->set(UserScope::class);

    $services->set(IpScope::class)
        ->args([service('request_stack')]);

    $services->set(CacheChecker::class)
        ->args([
            abstract_arg('Default pool reference set by RpcExtension'),
            abstract_arg('Named pool locator set by RpcExtension'),
            abstract_arg('Scope locator set by MethodCompilerPass'),
            '%json_rpc_server.cache.max_readable_key_length%',
            '%json_rpc_server.cache.key_prefix%',
            '%json_rpc_server.cache.hash_prefix%',
        ]);

    $services->set(RpcCacheInvalidator::class)
        ->args([
            service(MethodRegistry::class),
            service(CacheChecker::class),
            service('logger')->nullOnInvalid(),
        ])
        ->public();

    $services->set(RpcCacheClearCommand::class)
        ->tag('console.command');

    $services->set(DebugRpcCommand::class)
        ->args([
            service(MethodRegistry::class),
            service(McpToolFilter::class)->nullOnInvalid(),
            service(JsonSchemaBuilder::class)->nullOnInvalid(),
            service(OpenRpcDocumentBuilder::class)->nullOnInvalid(),
        ])
        ->tag('console.command');

    $services->set(OpenRpcDocumentBuilder::class);

    $services->set(OpenRpcController::class)
        ->args([
            service(OpenRpcDocumentBuilder::class),
            '%json_rpc_server.openrpc.title%',
            '%json_rpc_server.openrpc.version%',
            '%json_rpc_server.openrpc.description%',
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(Dispatcher::class)
        ->args([
            service(MethodRegistry::class),
            service(ArgumentResolver::class),
            service('serializer'),
            service('security.authorization_checker')->nullOnInvalid(),
            service(RateLimitChecker::class)->nullOnInvalid(),
            service(CacheChecker::class)->nullOnInvalid(),
            service('event_dispatcher')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            '%json_rpc_server.security.expose_role_names%',
        ]);

    $services->set(MethodRegistry::class)
        ->args([
            [],
            abstract_arg('Service locator filled in by compiler pass'),
            '%json_rpc_server.mcp.default_format%',
        ]);

    $services->set(RpcController::class)
        ->arg('$defaultMaxRequestSize', '%json_rpc_server.max_request_size%')
        ->arg('$jsonEncodeFlags', '%json_rpc_server.json.encode_flags%')
        ->arg('$deprecationHeader', '%json_rpc_server.headers.deprecation%')
        ->public()
        ->tag('controller.service_arguments');

    $services->set(StreamController::class)
        ->arg('$jsonEncodeFlags', '%json_rpc_server.json.encode_flags%')
        ->arg('$events', service('event_dispatcher')->nullOnInvalid())
        ->arg('$extraHeaders', '%json_rpc_server.stream.headers%')
        ->public()
        ->tag('controller.service_arguments');

    $services->set(JsonSchemaBuilder::class)
        ->args(['%json_rpc_server.serializer.datetime_format%', '%json_rpc_server.mcp.schema_max_depth%']);

    $services->set(McpToolFilter::class)
        ->args([
            '%json_rpc_server.mcp.expose_all%',
            '%json_rpc_server.mcp.exclude_prefixes%',
            '%json_rpc_server.mcp.exclude_methods%',
            '%json_rpc_server.mcp.whitelist_methods%',
        ]);

    $services->set(McpToolRegistry::class);

    $services->set(DefaultMcpResultFormatter::class)
        ->arg('$tableMaxRows', '%json_rpc_server.mcp.markdown.max_table_rows%')
        ->arg('$tableMaxCols', '%json_rpc_server.mcp.markdown.max_table_cols%')
        ->arg('$jsonEncodeFlags', '%json_rpc_server.json.encode_flags%');
    $services->alias(McpResultFormatter::class, DefaultMcpResultFormatter::class);

    $services->set(McpController::class)
        ->arg('$applyRateLimit', '%json_rpc_server.mcp.apply_rate_limit%')
        ->arg('$defaultMaxRequestSize', '%json_rpc_server.max_request_size%')
        ->arg('$maxJsonDepth', '%json_rpc_server.max_json_depth%')
        ->arg('$jsonEncodeFlags', '%json_rpc_server.json.encode_flags%')
        ->arg('$formatHeader', '%json_rpc_server.mcp.format_header%')
        ->arg('$formatQuery', '%json_rpc_server.mcp.format_query%')
        ->public()
        ->tag('controller.service_arguments');

    // make:rpc-method is gated on MakerBundle being installed. The maker
    // depends on AbstractMaker; without the class the service definition
    // would fail to load.
    if (class_exists(AbstractMaker::class)) {
        $services->set(MakeRpcMethod::class)
            ->tag('maker.command');
    }
};
