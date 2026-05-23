<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\KnetesinJsonRpcServerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** Unique per instance — spl_object_hash() is reused after the kernel is destroyed. */
    private readonly string $instanceId;

    /**
     * @param array<string, mixed> $rpcConfig
     */
    public function __construct(private readonly array $rpcConfig = [])
    {
        parent::__construct('test', true);
        $this->instanceId = bin2hex(random_bytes(8));
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SecurityBundle(),
            new KnetesinJsonRpcServerBundle(),
        ];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/jsonrpc-bundle/cache/'.$this->instanceId;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/jsonrpc-bundle/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => false],
            'validation' => ['enable_attributes' => true, 'email_validation_mode' => 'html5'],
            'serializer' => ['enable_attributes' => true],
            // Tag-aware pool that the bundle uses as its default — gives the
            // invalidation tests a tag-capable backend without overriding the
            // framework-reserved `cache.app` service.
            'cache' => [
                'pools' => [
                    'rpc.test.cache.app' => ['tags' => true],
                ],
            ],
        ]);

        $container->extension('security', [
            'providers' => [
                'in_memory' => ['memory' => null],
            ],
            'firewalls' => [
                // Must be enabled so TokenStorage drives AuthorizationChecker in role tests.
                'main' => ['security' => true],
            ],
        ]);

        // Tag-aware default pool so cache invalidation tests have a backend
        // that supports tag invalidation. MCP is opt-in at the bundle level
        // (default off), but every MCP test depends on the routes/services
        // being live — enable it across the test suite, individual tests can
        // still flip it back via rpcConfig (e.g. testMcpDisabledRemovesServices).
        // User-passed rpcConfig wins via array_replace_recursive.
        $rpcDefaults = [
            'cache' => ['default_pool' => 'rpc.test.cache.app'],
            'mcp' => ['enabled' => true],
            // Stream endpoint is opt-in at the bundle level (default off).
            // Fixture handlers include #[Stream] methods, so enable here;
            // individual tests can still flip it back via rpcConfig.
            'routes' => ['stream' => ['enabled' => true]],
        ];
        $container->extension('json_rpc_server', array_replace_recursive($rpcDefaults, $this->rpcConfig));

        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->set(\Knetesin\JsonRpcServerBundle\Tests\Fixtures\TestAuthenticationListener::class)
            ->load(
                'Knetesin\\JsonRpcServerBundle\\Tests\\Fixtures\\Methods\\',
                __DIR__.'/../Fixtures/Methods/',
            )
            ->load(
                'Knetesin\\JsonRpcServerBundle\\Tests\\Fixtures\\Cache\\',
                __DIR__.'/../Fixtures/Cache/',
            );
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/../../src/Resources/config/routes.php');
    }
}
