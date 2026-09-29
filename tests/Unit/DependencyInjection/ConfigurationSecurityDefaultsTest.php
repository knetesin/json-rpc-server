<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\DependencyInjection;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Configuration;
use Knetesin\JsonRpcServerBundle\DependencyInjection\RpcExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ValidateEnvPlaceholdersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationSecurityDefaultsTest extends TestCase
{
    public function testParallelBatchIsOffByDefaultAndNeedsNoSelfUrl(): void
    {
        $config = $this->process([]);

        $this->assertFalse($config['parallel_batch']['enabled']);
        $this->assertNull($config['parallel_batch']['self_url']);
    }

    public function testEnablingParallelBatchRequiresSelfUrl(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('parallel_batch.self_url is required when parallel_batch.enabled is true');

        $this->process(['parallel_batch' => ['enabled' => true]]);
    }

    public function testEmptySelfUrlIsRejectedWhenEnabled(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('parallel_batch.self_url is required');

        $this->process(['parallel_batch' => ['enabled' => true, 'self_url' => '']]);
    }

    #[DataProvider('invalidSelfUrls')]
    public function testSelfUrlMustBeAbsoluteHttpUrl(string $url): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be an absolute http:// or https:// URL');

        $this->process(['parallel_batch' => ['enabled' => true, 'self_url' => $url]]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSelfUrls(): iterable
    {
        yield 'relative path' => ['/rpc'];
        yield 'no scheme' => ['localhost/rpc'];
        yield 'other scheme' => ['ftp://localhost/rpc'];
        yield 'no host' => ['http:///rpc'];
    }

    public function testAbsoluteSelfUrlIsAccepted(): void
    {
        $config = $this->process(['parallel_batch' => ['enabled' => true, 'self_url' => 'https://127.0.0.1:8443/rpc']]);

        $this->assertSame('https://127.0.0.1:8443/rpc', $config['parallel_batch']['self_url']);
    }

    public function testSelfUrlFromEnvVarPassesBuildTimeValidation(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.bundles', []);
        $container->registerExtension(new RpcExtension());
        $container->loadFromExtension('json_rpc_server', [
            'parallel_batch' => ['enabled' => true, 'self_url' => '%env(RPC_SELF_URL)%', 'budget_store' => 'null'],
        ]);

        (new MergeExtensionConfigurationPass())->process($container);
        (new ValidateEnvPlaceholdersPass())->process($container);

        $this->assertTrue($container->getParameter('json_rpc_server.parallel_batch.enabled'));
    }

    public function testCookieIsNotForwardedToSubCallsByDefault(): void
    {
        $headers = $this->process([])['parallel_batch']['forward_headers'];

        $this->assertNotContains('Cookie', $headers);
        $this->assertContains('Authorization', $headers);
    }

    public function testMcpAppliesRateLimitsByDefault(): void
    {
        $this->assertTrue($this->process([])['mcp']['apply_rate_limit']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
