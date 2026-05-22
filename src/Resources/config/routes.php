<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

use JsonRpcServer\Controller\McpController;
use JsonRpcServer\Controller\OpenRpcController;
use JsonRpcServer\Controller\RpcController;
use JsonRpcServer\Controller\StreamController;

return static function (RoutingConfigurator $routes): void {
    // %json_rpc_server.routes.{name}.enabled% and %json_rpc_server.mcp.enabled% are interpolated at
    // container compile time, then evaluated at match time. When a flag is
    // "0" (disabled), the condition short-circuits to false and the route
    // is never resolved — handy for projects that register their own routes
    // pointing at the bundle controllers.
    $enabled = static fn (string $param): string => \sprintf('"%%%s%%" === "1"', $param);

    $routes->add('rpc', '%json_rpc_server.routes.rpc%')
        ->controller(RpcController::class)
        ->methods(['POST'])
        ->condition($enabled('json_rpc_server.routes.rpc.enabled'));

    $routes->add('rpc_stream', '%json_rpc_server.routes.stream%')
        ->controller(StreamController::class)
        ->methods(['POST'])
        ->condition($enabled('json_rpc_server.routes.stream.enabled'));

    // MCP routes are gated by BOTH the global `json_rpc_server.mcp.enabled`
    // switch AND their per-route enabled flag — either being false skips the route.
    $mcpRouteCondition = static fn (string $param): string => \sprintf(
        '"%%json_rpc_server.mcp.enabled%%" === "1" and "%%%s%%" === "1"',
        $param,
    );

    $routes->add('rpc_mcp_tools', '%json_rpc_server.routes.mcp_tools%')
        ->controller([McpController::class, 'tools'])
        ->methods(['GET'])
        ->condition($mcpRouteCondition('json_rpc_server.routes.mcp_tools.enabled'));

    $routes->add('rpc_mcp_call', '%json_rpc_server.routes.mcp_call%')
        ->controller([McpController::class, 'call'])
        ->methods(['POST'])
        ->condition($mcpRouteCondition('json_rpc_server.routes.mcp_call.enabled'));

    $routes->add('rpc_openrpc', '%json_rpc_server.routes.openrpc%')
        ->controller(OpenRpcController::class)
        ->methods(['GET'])
        ->condition($enabled('json_rpc_server.routes.openrpc.enabled'));
};
