<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\DependencyInjection;

use Knetesin\JsonRpcServerBundle\Attribute\Method as RpcMethod;
use Knetesin\JsonRpcServerBundle\Batch\ApcuBudgetTracker;
use Knetesin\JsonRpcServerBundle\Batch\NullBudgetTracker;
use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Knetesin\JsonRpcServerBundle\Cache\CacheChecker;
use Knetesin\JsonRpcServerBundle\Controller\McpController;
use Knetesin\JsonRpcServerBundle\Controller\RpcController;
use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass;
use Knetesin\JsonRpcServerBundle\Mcp\DefaultMcpResultFormatter;
use Knetesin\JsonRpcServerBundle\Mcp\McpResultFormatter;
use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Knetesin\JsonRpcServerBundle\Mcp\McpToolRegistry;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitBypassInterface;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitChecker;
use OpenTelemetry\API\Globals as OtelGlobals;
use Sentry\State\HubInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
// Sentry SDK is a soft dependency. The import resolves only when
// sentry/sentry-symfony is installed; the class_exists guard below decides
// whether the SentryRpcSubscriber gets registered.
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RpcExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('json_rpc_server.security.roles_match', $config['security']['roles_match']);
        $container->setParameter('json_rpc_server.security.expose_role_names', $config['security']['expose_role_names']);
        $container->setParameter('json_rpc_server.security.default_roles', array_values($config['security']['default_roles']));
        $container->setParameter('json_rpc_server.security.public_prefixes', array_values($config['security']['public_prefixes']));
        $container->setParameter('json_rpc_server.security.public_methods', array_values($config['security']['public_methods']));
        $container->setParameter('json_rpc_server.security.prefix_roles', $config['security']['prefix_roles']);
        $container->setParameter('json_rpc_server.context.request_id_header', $config['context']['request_id_header']);
        $container->setParameter('json_rpc_server.headers.deprecation', $config['headers']['deprecation']);
        $container->setParameter('json_rpc_server.stream.headers', array_filter(
            $config['stream']['headers'],
            // Null-valued entries explicitly opt OUT of a default header
            // (e.g. set X-Accel-Buffering: null to keep nginx buffering on).
            static fn ($v): bool => null !== $v,
        ));
        $container->setParameter('json_rpc_server.params.allow_positional_dto', $config['params']['allow_positional_dto']);
        $container->setParameter('json_rpc_server.params.reject_unknown', $config['params']['reject_unknown']);
        $container->setParameter('json_rpc_server.serializer.datetime_format', $config['serializer']['datetime_format']);
        $container->setParameter('json_rpc_server.serializer.date_format', $config['serializer']['date_format']);
        $container->setParameter('json_rpc_server.serializer.timezone', $config['serializer']['timezone']);
        $container->setParameter('json_rpc_server.max_request_size', $config['max_request_size']);
        $container->setParameter('json_rpc_server.max_json_depth', $config['max_json_depth']);
        $container->setParameter('json_rpc_server.http_status.enabled', $config['http_status']['enabled']);
        // Compiler pass may raise this to fit any per-method MaxRequestSize attribute.
        $container->setParameter('json_rpc_server.parser_cap', $config['max_request_size']);
        $container->setParameter('json_rpc_server.cache.default_pool', $config['cache']['default_pool']);
        $container->setParameter('json_rpc_server.cache.max_readable_key_length', $config['cache']['max_readable_key_length']);
        $container->setParameter('json_rpc_server.cache.key_prefix', $config['cache']['key_prefix']);
        $container->setParameter('json_rpc_server.cache.hash_prefix', $config['cache']['hash_prefix']);
        foreach ($config['routes'] as $name => $route) {
            $container->setParameter('json_rpc_server.routes.'.$name, $route['path']);
            $container->setParameter('json_rpc_server.routes.'.$name.'.enabled', $route['enabled']);
        }
        $container->setParameter('json_rpc_server.openrpc.title', $config['openrpc']['title']);
        $container->setParameter('json_rpc_server.openrpc.version', $config['openrpc']['version']);
        $container->setParameter('json_rpc_server.openrpc.description', $config['openrpc']['description']);
        $container->setParameter('json_rpc_server.handlers.public', $config['handlers']['public']);
        $container->setParameter('json_rpc_server.handlers.shared', $config['handlers']['shared']);
        // JSON_THROW_ON_ERROR is forced regardless of user config — encoding
        // failures must surface, never silently produce `false`.
        $container->setParameter('json_rpc_server.json.encode_flags', $config['json']['encode_flags'] | \JSON_THROW_ON_ERROR);
        $container->setParameter('json_rpc_server.rate_limiter.cache_pool', $config['rate_limiter']['cache_pool']);
        $container->setParameter('json_rpc_server.mcp.enabled', $config['mcp']['enabled']);
        $container->setParameter('json_rpc_server.mcp.format_header', $config['mcp']['format_header']);
        $container->setParameter('json_rpc_server.mcp.format_query', $config['mcp']['format_query']);
        $container->setParameter('json_rpc_server.mcp.default_format', $config['mcp']['default_format']);
        $container->setParameter('json_rpc_server.mcp.apply_rate_limit', $config['mcp']['apply_rate_limit']);
        $container->setParameter('json_rpc_server.mcp.expose_all', $config['mcp']['expose_all']);
        $container->setParameter('json_rpc_server.mcp.exclude_prefixes', array_values($config['mcp']['exclude_prefixes']));
        $container->setParameter('json_rpc_server.mcp.exclude_methods', array_values($config['mcp']['exclude_methods']));
        $container->setParameter('json_rpc_server.mcp.whitelist_methods', array_values($config['mcp']['whitelist_methods']));
        $container->setParameter('json_rpc_server.mcp.schema_max_depth', $config['mcp']['schema_max_depth']);
        $container->setParameter('json_rpc_server.mcp.markdown.max_table_rows', $config['mcp']['markdown']['max_table_rows']);
        $container->setParameter('json_rpc_server.mcp.markdown.max_table_cols', $config['mcp']['markdown']['max_table_cols']);

        $container->setParameter('json_rpc_server.parallel_batch.enabled', $config['parallel_batch']['enabled']);
        $container->setParameter('json_rpc_server.parallel_batch.min_batch_size', $config['parallel_batch']['min_batch_size']);
        $container->setParameter('json_rpc_server.parallel_batch.max_concurrency', $config['parallel_batch']['max_concurrency']);
        $container->setParameter('json_rpc_server.parallel_batch.budget', $config['parallel_batch']['budget']);
        $container->setParameter('json_rpc_server.parallel_batch.max_depth', $config['parallel_batch']['max_depth']);
        $container->setParameter('json_rpc_server.parallel_batch.connect_timeout', $config['parallel_batch']['connect_timeout']);
        $container->setParameter('json_rpc_server.parallel_batch.timeout', $config['parallel_batch']['timeout']);
        $container->setParameter('json_rpc_server.parallel_batch.forward_headers', array_values($config['parallel_batch']['forward_headers']));
        $container->setParameter('json_rpc_server.parallel_batch.self_url', $config['parallel_batch']['self_url']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');

        // Any service implementing RateLimitBypassInterface is collected by
        // RateLimitChecker's tagged_iterator — no manual tag needed in the app.
        $container->registerForAutoconfiguration(RateLimitBypassInterface::class)
            ->addTag('json_rpc_server.rate_limit_bypass');

        if ($config['profiler']['enabled'] && $container->getParameter('kernel.debug')) {
            $loader->load('services_profiler.php');
        }

        if ($config['parallel_batch']['enabled']) {
            // Loopback fan-out hard-depends on symfony/http-client. The bundle
            // doesn't pull it as a hard require (most installs don't enable
            // parallel batch), so we fail loudly here when the operator opted
            // in but the package is missing — better than a confusing runtime
            // error on the first batch.
            if (!class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
                throw new \LogicException('json_rpc_server.parallel_batch.enabled is true, but symfony/http-client is not installed. Run `composer require symfony/http-client` or set the flag back to false.');
            }
            $loader->load('services_parallel_batch.php');
            $this->wireBudgetTracker(
                $container,
                $config['parallel_batch']['budget_store'],
                (int) $config['parallel_batch']['budget'],
            );
            // RpcController is defined in services.php with default-null
            // parallel args; when fan-out is enabled we inject the executor
            // here, after both definitions exist.
            $container->getDefinition(RpcController::class)
                ->setArgument('$parallelExecutor', new Reference(ParallelBatchExecutor::class))
                ->setArgument('$events', new Reference('event_dispatcher', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE))
                ->setArgument('$parallelEnabled', true)
                ->setArgument('$parallelMinBatchSize', $config['parallel_batch']['min_batch_size'])
                ->setArgument('$parallelMaxDepth', $config['parallel_batch']['max_depth']);
        }

        if ($config['logging']['enabled']) {
            $loader->load('services_logging.php');
            $loggingDef = $container->getDefinition(\Knetesin\JsonRpcServerBundle\Logging\RpcLoggingSubscriber::class);
            if (null !== $config['logging']['channel']) {
                $loggingDef->setArgument('$logger', new Reference($config['logging']['channel']));
            }
            $loggingDef
                ->setArgument('$levelStarted', $config['logging']['level_started'])
                ->setArgument('$levelCompleted', $config['logging']['level_completed'])
                ->setArgument('$levelFailed', $config['logging']['level_failed'])
                ->setArgument('$logParams', $config['logging']['log_params'])
                ->setArgument('$logResult', $config['logging']['log_result'])
                ->setArgument('$slowThresholdMs', $config['logging']['slow_threshold_ms']);
        }

        // OpenTelemetry: register only if both opted in AND the SDK is present.
        // The OtelGlobals static class is the canonical entry point of the SDK;
        // its absence means the SDK isn't installed and the bridge no-ops.
        if ($config['opentelemetry']['enabled'] && class_exists(OtelGlobals::class)) {
            $loader->load('services_opentelemetry.php');
            $tracerName = $config['opentelemetry']['tracer_name'];
            // Inject the tracer name into the factory call we declared with
            // abstract_arg().
            $tracerDef = $container->getDefinition('json_rpc_server.opentelemetry.tracer');
            $methodCalls = $tracerDef->getMethodCalls();
            $methodCalls[0][1] = [$tracerName];
            $tracerDef->setMethodCalls($methodCalls);

            $meterDef = $container->getDefinition('json_rpc_server.opentelemetry.meter');
            $methodCalls = $meterDef->getMethodCalls();
            $methodCalls[0][1] = [$tracerName];
            $meterDef->setMethodCalls($methodCalls);

            $container->getDefinition(\Knetesin\JsonRpcServerBundle\OpenTelemetry\OpenTelemetrySubscriber::class)
                ->setArgument('$traces', $config['opentelemetry']['traces'])
                ->setArgument('$metrics', $config['opentelemetry']['metrics'])
                ->setArgument('$propagate', $config['opentelemetry']['propagate_traceparent'])
                ->setArgument('$recordParams', $config['opentelemetry']['record_params'])
                ->setArgument('$recordResult', $config['opentelemetry']['record_result'])
                ->setArgument('$recordMaxChars', $config['opentelemetry']['record_max_chars'])
                ->setArgument('$streamRecordRowCount', $config['opentelemetry']['stream']['record_row_count'])
                ->setArgument('$streamSpanPerRow', $config['opentelemetry']['stream']['span_per_row'])
                ->setArgument('$ignoreExceptions', array_values($config['opentelemetry']['ignore_exceptions']));
        }

        // Sentry: only register if both the user enabled it AND the SDK is present.
        // Silent skip (rather than failing the build) keeps `enabled: true` safe
        // to commit in shared config — dev environments without Sentry just no-op.
        if ($config['sentry']['enabled'] && interface_exists(HubInterface::class)) {
            $loader->load('services_sentry.php');
            $container->getDefinition(\Knetesin\JsonRpcServerBundle\Sentry\SentryRpcSubscriber::class)
                ->setArgument('$breadcrumbs', $config['sentry']['breadcrumbs'])
                ->setArgument('$tagMethod', $config['sentry']['tag_method'])
                ->setArgument('$transactions', $config['sentry']['transactions'])
                ->setArgument('$ignoreExceptions', array_values($config['sentry']['ignore_exceptions']));
        }

        $poolRefs = [];
        foreach ($config['cache']['pools'] as $name => $serviceId) {
            $poolRefs[$name] = new Reference($serviceId);
        }
        $container->getDefinition(CacheChecker::class)
            ->setArgument('$defaultPool', new Reference($config['cache']['default_pool']))
            ->setArgument('$namedPools', new ServiceLocatorArgument($poolRefs));

        if ($container->hasDefinition(RateLimitChecker::class)) {
            $container->getDefinition(RateLimitChecker::class)
                ->setArgument(0, new Reference($config['rate_limiter']['cache_pool']));
        }

        if (!$config['mcp']['enabled']) {
            $container->removeDefinition(McpController::class);
            $container->removeDefinition(McpToolRegistry::class);
            $container->removeDefinition(McpToolFilter::class);
            $container->removeDefinition(DefaultMcpResultFormatter::class);
            $container->removeAlias(McpResultFormatter::class);
            // JsonSchemaBuilder stays — OpenRpcDocumentBuilder and
            // debug:rpc still want to render input schemas. It's stateless
            // and dependency-free, so the cost of keeping it is nil.
        }

        // symfony/rate-limiter is a suggest, not a hard require. Drop the
        // service when the package isn't installed — Dispatcher already
        // accepts a null RateLimitChecker. If any method actually carries
        // #[Rpc\RateLimit], the compiler pass will fail loudly with a
        // "install symfony/rate-limiter" message; here we just clean up so
        // the no-rate-limit install works at all.
        if (!class_exists(RateLimiterFactory::class)) {
            $container->removeDefinition(RateLimitChecker::class);
        }

        // Only the tag is added here. Public/shared semantics are applied
        // by MethodCompilerPass according to json_rpc_server.handlers.* — keeping the
        // single source of truth for handler DI metadata in one place.
        $container->registerAttributeForAutoconfiguration(
            RpcMethod::class,
            static function (ChildDefinition $definition, RpcMethod $attribute): void {
                $definition->addTag(MethodCompilerPass::TAG);
            },
        );
    }

    /**
     * Resolves the configured `budget_store` setting to a concrete
     * BudgetTrackerInterface service and wires it into RpcController.
     *
     * Accepted shorthand values:
     *   - "apcu" → ApcuBudgetTracker, when APCu is available; falls back
     *              to Null otherwise with a build-time E_USER_WARNING so
     *              operators notice that the system-wide cap is off.
     *   - "null" → NullBudgetTracker (no system-wide cap).
     *   - any other string → service id implementing BudgetTrackerInterface.
     */
    private function wireBudgetTracker(ContainerBuilder $container, string $store, int $budget): void
    {
        $controllerDef = $container->getDefinition(RpcController::class);

        if ('null' === $store) {
            $container->setDefinition('json_rpc_server.parallel_batch.budget_tracker', new \Symfony\Component\DependencyInjection\Definition(NullBudgetTracker::class));
            $controllerDef->setArgument('$budget', new Reference('json_rpc_server.parallel_batch.budget_tracker'));

            return;
        }

        if ('apcu' === $store) {
            if (!ApcuBudgetTracker::isAvailable()) {
                // APCu was requested but isn't loaded / enabled in this SAPI.
                // We degrade to NullBudgetTracker so the boot doesn't fail —
                // but loudly, because the operator opted in to parallel batch
                // and now silently has NO system-wide concurrency cap. On
                // FPM that's a recipe for pool exhaustion under load.
                //
                // The warning surfaces in cache:warmup / cache:clear output
                // and ends up in error_log on prod. Operators who actually
                // want the no-budget mode should set `budget_store: null`
                // explicitly; that path is silent by design.
                // Not suppressed with @ on purpose — operators must see this.
                // If a test setup converts warnings to exceptions, that's the
                // correct outcome: CI catches a misconfigured parallel batch.
                // (ContainerBuilder::log() only accepts CompilerPassInterface,
                // so we can't route this through the container's audit log.)
                trigger_error(
                    'json_rpc_server: parallel_batch.budget_store="apcu" requested but APCu is not available (function missing or apc.enabled=0). Falling back to NullBudgetTracker — the system-wide fan-out budget is OFF. Install APCu, or set budget_store: null to silence this warning.',
                    \E_USER_WARNING,
                );

                $container->setDefinition('json_rpc_server.parallel_batch.budget_tracker', new \Symfony\Component\DependencyInjection\Definition(NullBudgetTracker::class));
                $controllerDef->setArgument('$budget', new Reference('json_rpc_server.parallel_batch.budget_tracker'));

                return;
            }
            $def = new \Symfony\Component\DependencyInjection\Definition(ApcuBudgetTracker::class);
            $def->setArgument(0, $budget);
            $container->setDefinition('json_rpc_server.parallel_batch.budget_tracker', $def);
            $controllerDef->setArgument('$budget', new Reference('json_rpc_server.parallel_batch.budget_tracker'));

            return;
        }

        // Custom service id.
        $controllerDef->setArgument('$budget', new Reference($store));
    }

    public function getAlias(): string
    {
        return 'json_rpc_server';
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration();
    }
}
