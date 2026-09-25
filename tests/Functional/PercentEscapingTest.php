<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Handler free text containing "%" must not be read as %parameter% placeholders
 * by the container, while roles and cache settings still resolve them.
 *
 * @see \Knetesin\JsonRpcServerBundle\Tests\Fixtures\Percent\PercentEcho
 */
final class PercentEscapingTest extends KernelTestCase
{
    public function testPercentStringsSurviveContainerBuildVerbatim(): void
    {
        $kernel = $this->boot([], ['Percent']);

        $meta = $this->registry($kernel)->get('test.percent');

        $this->assertSame('Share 10%-20% of %kernel.environment%', $meta->description);
        $this->assertSame('%Y-%m-%d', $meta->parameters[0]->default);
        $this->assertSame('Up to 100% of %kernel.environment%', $meta->mcpDescription);
        $this->assertSame('50% off', $meta->mcpAnnotations['title']);
    }

    public function testRolesAndCacheSettingsStillResolveParameters(): void
    {
        $kernel = $this->boot([], ['Percent']);

        $meta = $this->registry($kernel)->get('test.percentResolved');

        $this->assertSame(['test'], $meta->roles);
        $this->assertNotNull($meta->cache);
        $this->assertSame('test', $meta->cache->pool);
        $this->assertSame(['test'], $meta->cache->tags);
    }

    public function testDefaultWithPercentReachesHandler(): void
    {
        $kernel = $this->boot([], ['Percent']);

        $response = $kernel->handle(Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.percent","id":1}',
        ));

        $this->assertSame('%Y-%m-%d', $this->decodeJsonResponse($response)['result']);
    }

    private function registry(KernelInterface $kernel): MethodRegistry
    {
        $testContainer = $kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $testContainer);
        $registry = $testContainer->get(MethodRegistry::class);
        $this->assertInstanceOf(MethodRegistry::class, $registry);

        return $registry;
    }
}
