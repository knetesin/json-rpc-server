<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\DependencyInjection\Compiler;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Percent\PercentEcho;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Percent\PercentResolved;
use PHPUnit\Framework\TestCase;

/**
 * "%" is escaped in free-text fields of the raw methods array only; roles and
 * cache pool/tags keep resolving %parameter% placeholders.
 */
final class MethodCompilerPassEscapingTest extends TestCase
{
    public function testFreeTextFieldsAreEscaped(): void
    {
        $container = CompilerPassContainer::create([PercentEcho::class]);

        (new MethodCompilerPass())->process($container);

        $raw = CompilerPassContainer::rawMethods($container)['test.percent'];
        $this->assertSame('Share 10%%-20%% of %%kernel.environment%%', $raw['description']);
        $this->assertIsArray($raw['parameters']);
        $this->assertIsArray($raw['parameters'][0]);
        $this->assertSame('%%Y-%%m-%%d', $raw['parameters'][0]['default']);
        $this->assertSame('Up to 100%% of %%kernel.environment%%', $raw['mcpDescription']);
        $this->assertSame('50%% off', $raw['mcpAnnotations']['title']);
        $this->assertSame('{"type":"string","description":"strftime-like, e.g. %%Y"}', $raw['outputSchemaJson']);
        $this->assertSame(
            'Share 10%-20% of %kernel.environment%',
            $container->getParameterBag()->unescapeValue($raw['description']),
        );
    }

    public function testRolesAndCacheSettingsAreNotEscaped(): void
    {
        $container = CompilerPassContainer::create([PercentResolved::class]);

        (new MethodCompilerPass())->process($container);

        $raw = CompilerPassContainer::rawMethods($container)['test.percentResolved'];
        $this->assertSame(['%kernel.environment%'], $raw['roles']);
        $this->assertIsArray($raw['cache']);
        $this->assertSame('%kernel.environment%', $raw['cache']['pool']);
        $this->assertSame(['%kernel.environment%'], $raw['cache']['tags']);
    }
}
